<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveUnysisBox;
use App\Http\Resources\Api\V1\RevisionResource;
use App\Http\Resources\Api\V1\RevisionSummaryResource;
use App\Models\AiModel;
use App\Models\Download;
use App\Models\Revision;
use App\Models\Script;
use App\Models\UnysisBox;
use App\Services\Marketplace\DownloadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The read + download half of the RPA-TOOL API, shared by Scripts and
 * AI Models. The two entry types differ only in their model, their resources and
 * the relations worth eager-loading.
 *
 * Every Customer User sees the whole catalogue — the Customer label is a filter,
 * not an access wall (docs/adr/0001) — so nothing here scopes by Customer.
 */
abstract class CatalogueController extends Controller
{
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    public const NO_RELEASED_REVISION = 'No released revision available.';

    /** The Revision statuses the API exposes. Drafts are never among them. */
    public const VISIBLE_STATUSES = [Revision::STATUS_RELEASED, Revision::STATUS_DEPRECATED];

    public function __construct(protected DownloadService $downloads) {}

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function listResourceClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function detailResourceClass(): string;

    /**
     * Relations loaded for a list row. Detail loads these plus whatever else it
     * needs; both always get the narrowed `revisions` relation.
     *
     * @return array<int, string>
     */
    protected function listRelations(): array
    {
        return ['machineModel.machineBrand', 'customer'];
    }

    /** @return array<int, string> */
    protected function detailRelations(): array
    {
        return $this->listRelations();
    }

    /**
     * Paginated list of entries that have at least one released Revision. An entry
     * whose Revisions are all drafts is invisible to RPA-TOOL — there is nothing
     * it could download.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $modelClass = $this->modelClass();

        $query = $modelClass::query()
            ->with($this->listRelations())
            ->whereHas('revisions', fn (Builder $q) => $q->where('status', Revision::STATUS_RELEASED));

        $this->withVisibleRevisions($query);
        $this->applyFilters($query, $request);

        $query->orderBy('name');

        $resource = $this->listResourceClass();

        return $resource::collection($query->paginate($perPage)->withQueryString());
    }

    /**
     * Load the Revisions the API exposes (released + deprecated, newest first) and
     * the counts that go with them, in one go rather than per row.
     */
    protected function withVisibleRevisions(Builder $query): void
    {
        $query->with($this->visibleRevisionsRelation())
            ->withCount($this->visibleRevisionCounts());
    }

    /**
     * The eager-load and the counts are identical whether they are applied to a
     * Builder (the list) or to a loaded model (detail), so they are defined once.
     *
     * @return array<string, callable>
     */
    private function visibleRevisionsRelation(): array
    {
        return ['revisions' => fn ($q) => $q
            ->whereIn('status', self::VISIBLE_STATUSES)
            ->orderByDesc('number')];
    }

    /** @return array<int|string, mixed> */
    private function visibleRevisionCounts(): array
    {
        return [
            'revisions as revisions_count' => fn (Builder $q) => $q->whereIn('status', self::VISIBLE_STATUSES),
            'downloads as downloads_count',
        ];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        $query->when($request->filled('machine_model'), fn (Builder $q) => $q
            ->where('machine_model_id', $request->string('machine_model')->toString()));

        $query->when($request->filled('brand'), fn (Builder $q) => $q
            ->whereHas('machineModel', fn (Builder $m) => $m
                ->where('machine_brand_id', $request->string('brand')->toString())));

        $query->when($request->filled('customer'), fn (Builder $q) => $q
            ->where('customer_id', $request->string('customer')->toString()));

        // whereLike(..., caseSensitive: false) compiles to ILIKE on PostgreSQL and
        // to LIKE on SQLite, whose LIKE is already ASCII case-insensitive.
        $query->when($request->filled('q'), function (Builder $q) use ($request) {
            $term = '%'.$request->string('q')->toString().'%';

            $q->where(fn (Builder $inner) => $inner
                ->whereLike('name', $term, caseSensitive: false)
                ->orWhereLike('description', $term, caseSensitive: false));
        });
    }

    /**
     * An entry with nothing but draft Revisions is not part of the catalogue as far
     * as RPA-TOOL is concerned: the list hides it and `download` has nothing to
     * give, so `show` and `revisions` refuse it with the same message rather than
     * answering with an empty `revisions` array. A deprecated-only entry still
     * resolves — a box may re-fetch what it already runs.
     */
    protected function assertVisibleRevisions(Script|AiModel $entry): void
    {
        abort_if($entry->revisions->isEmpty(), 404, self::NO_RELEASED_REVISION);
    }

    protected function showEntry(Script|AiModel $entry): JsonResponse
    {
        $entry->load($this->detailRelations());
        $this->loadVisibleRevisions($entry);
        $this->assertVisibleRevisions($entry);

        $resource = $this->detailResourceClass();

        return response()->json(['data' => (new $resource($entry))->resolve(request())]);
    }

    protected function revisionsFor(Script|AiModel $entry): JsonResponse
    {
        $revisions = $this->visibleRevisions($entry);

        $this->assertVisibleRevisions($entry);

        return response()->json([
            'data' => RevisionResource::collection($revisions)->resolve(request()),
        ]);
    }

    /**
     * Stream a Revision file and record the Download.
     *
     * The default is the latest released Revision; `?revision=N` pins one, which
     * may be deprecated (RPA-TOOL is allowed to re-fetch what it already runs) but
     * never a draft.
     */
    protected function downloadFrom(Request $request, Script|AiModel $entry): StreamedResponse
    {
        if ($request->filled('revision')) {
            $number = (int) $request->query('revision');

            $revision = $entry->revisions()
                ->where('number', $number)
                ->whereIn('status', self::VISIBLE_STATUSES)
                ->first();

            abort_if($revision === null, 404, "Revision {$number} is not available for download.");
        } else {
            $revision = $entry->latestReleasedRevision();

            abort_if($revision === null, 404, self::NO_RELEASED_REVISION);
        }

        $box = $request->attributes->get(ResolveUnysisBox::ATTRIBUTE);

        $this->downloads->record(
            $revision,
            $request->user(),
            $box instanceof UnysisBox ? $box : null,
            Download::SOURCE_API,
            $request,
        );

        return $this->downloads->stream($revision);
    }

    /**
     * Tell RPA-TOOL whether the Revision it holds is still the newest released one.
     */
    protected function checkUpdateFor(Request $request, Script|AiModel $entry): JsonResponse
    {
        $request->validate([
            'current' => ['nullable', 'integer', 'min:1'],
        ]);

        $current = $request->filled('current') ? (int) $request->query('current') : null;
        $latest = $entry->latestReleasedRevision();

        $currentStatus = null;

        if ($current !== null) {
            $held = $entry->revisions()->where('number', $current)->first();

            $currentStatus = ($held === null || $held->status === Revision::STATUS_DRAFT)
                ? 'unknown'
                : $held->status;
        }

        // A box that reports a number the catalogue does not know (or that is still
        // a draft) is holding something it should replace, so the latest release is
        // offered to it.
        $updateAvailable = $latest !== null
            && ($current === null || $currentStatus === 'unknown' || $latest->number > $current);

        return response()->json([
            'update_available' => $updateAvailable,
            'latest' => $latest === null ? null : (new RevisionSummaryResource($latest))->resolve(request()),
            'current_status' => $currentStatus,
        ]);
    }

    /** @return Collection<int, Revision> */
    protected function visibleRevisions(Script|AiModel $entry)
    {
        $this->loadVisibleRevisions($entry);

        return $entry->revisions;
    }

    protected function loadVisibleRevisions(Script|AiModel $entry): void
    {
        $entry->load($this->visibleRevisionsRelation())
            ->loadCount($this->visibleRevisionCounts());
    }
}
