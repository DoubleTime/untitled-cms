<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveAiBox;
use App\Http\Resources\Api\V1\RevisionResource;
use App\Http\Resources\Api\V1\RevisionSummaryResource;
use App\Models\AiBox;
use App\Models\AiModel;
use App\Models\Download;
use App\Models\FlowchartScript;
use App\Models\Revision;
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
 * The read + download half of the RPA-TOOL API, shared by FlowChart Scripts and
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
        $visible = [Revision::STATUS_RELEASED, Revision::STATUS_DEPRECATED];

        $query->with(['revisions' => fn ($q) => $q->whereIn('status', $visible)->orderByDesc('number')])
            ->withCount([
                'revisions as revisions_count' => fn (Builder $q) => $q->whereIn('status', $visible),
                'downloads as downloads_count',
            ]);
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

    protected function showEntry(FlowchartScript|AiModel $entry): JsonResponse
    {
        $entry->load($this->detailRelations());
        $this->loadVisibleRevisions($entry);

        $resource = $this->detailResourceClass();

        return response()->json(['data' => (new $resource($entry))->resolve(request())]);
    }

    protected function revisionsFor(FlowchartScript|AiModel $entry): JsonResponse
    {
        return response()->json([
            'data' => RevisionResource::collection($this->visibleRevisions($entry))->resolve(request()),
        ]);
    }

    /**
     * Stream a Revision file and record the Download.
     *
     * The default is the latest released Revision; `?revision=N` pins one, which
     * may be deprecated (RPA-TOOL is allowed to re-fetch what it already runs) but
     * never a draft.
     */
    protected function downloadFrom(Request $request, FlowchartScript|AiModel $entry): StreamedResponse
    {
        if ($request->filled('revision')) {
            $number = (int) $request->query('revision');

            $revision = $entry->revisions()
                ->where('number', $number)
                ->whereIn('status', [Revision::STATUS_RELEASED, Revision::STATUS_DEPRECATED])
                ->first();

            abort_if($revision === null, 404, "Revision {$number} is not available for download.");
        } else {
            $revision = $entry->latestReleasedRevision();

            abort_if($revision === null, 404, self::NO_RELEASED_REVISION);
        }

        $box = $request->attributes->get(ResolveAiBox::ATTRIBUTE);

        $this->downloads->record(
            $revision,
            $request->user(),
            $box instanceof AiBox ? $box : null,
            Download::SOURCE_API,
            $request,
        );

        return $this->downloads->stream($revision);
    }

    /**
     * Tell RPA-TOOL whether the Revision it holds is still the newest released one.
     */
    protected function checkUpdateFor(Request $request, FlowchartScript|AiModel $entry): JsonResponse
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
    protected function visibleRevisions(FlowchartScript|AiModel $entry)
    {
        $this->loadVisibleRevisions($entry);

        return $entry->revisions;
    }

    protected function loadVisibleRevisions(FlowchartScript|AiModel $entry): void
    {
        $visible = [Revision::STATUS_RELEASED, Revision::STATUS_DEPRECATED];

        $entry->load(['revisions' => fn ($q) => $q->whereIn('status', $visible)->orderByDesc('number')])
            ->loadCount([
                'revisions as revisions_count' => fn (Builder $q) => $q->whereIn('status', $visible),
                'downloads as downloads_count',
            ]);
    }
}
