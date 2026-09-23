<?php

namespace App\Http\Controllers\Marketplace;

use App\Exceptions\Marketplace\InvalidRevisionTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreRevisionRequest;
use App\Models\AiModel;
use App\Models\Download;
use App\Models\MachineModel;
use App\Models\Revision;
use App\Models\Script;
use App\Services\ActivityLogger;
use App\Services\Marketplace\DownloadService;
use App\Services\Marketplace\RevisionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Everything the Scripts admin and the AI Models admin do identically.
 *
 * The two entry types stay separate entities in the UI, the API and the
 * permission families, but they share one Revision/Download implementation, so
 * the Revision lifecycle actions, the soft/hard delete pair and the payload
 * builders live here once. What differs — the model, the route and page names,
 * the form requests, Preview Images on Scripts and the inference metadata on
 * AI Models — stays in the concrete controllers.
 *
 * **Route model binding.** Implicit binding matches a controller method's
 * parameter *name* against the route parameter (`{script}`, `{ai_model}`), so the
 * public actions have to be declared on the concrete controller with the
 * concrete type. They are thin overrides that delegate straight to the `…For`
 * methods here — the same arrangement Api\V1\CatalogueController uses for its
 * `{entry}` parameter.
 */
abstract class CatalogueAdminController extends Controller
{
    /** The latest web Downloads shown on an entry's Downloads tab. */
    protected const DOWNLOAD_LOG_LIMIT = 50;

    public function __construct(
        protected RevisionService $revisions,
        protected DownloadService $downloads,
    ) {}

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** How the entry type is named in flash messages and the activity log. */
    abstract protected function entryLabel(): string;

    /** Route name prefix, e.g. `admin.marketplace.scripts.`. */
    abstract protected function routePrefix(): string;

    /** Inertia page prefix, e.g. `Marketplace/Scripts`. */
    abstract protected function pagePrefix(): string;

    /** Slug used when the name slugifies to nothing at all, e.g. `script`. */
    abstract protected function slugFallback(): string;

    protected function routeName(string $action): string
    {
        return $this->routePrefix().$action;
    }

    protected function page(string $name): string
    {
        return $this->pagePrefix().'/'.$name;
    }

    /**
     * Hook for work that must happen before an entry is force-deleted, beyond its
     * Revisions. Scripts use it to drop their Preview Image links.
     */
    protected function beforeForceDelete(Script|AiModel $entry): void {}

    public function restoreEntry(Script|AiModel $entry)
    {
        Gate::authorize('restore', $entry);

        $entry->restore();

        ActivityLogger::log('restore', "Restored {$this->entryLabel()}: {$entry->name}", $entry);

        return redirect()->route($this->routeName('index'), ['deleted' => 1])
            ->with('success', "{$this->entryLabel()} restored.");
    }

    /**
     * Hard delete — removes every Revision file, the Revision rows and whatever
     * the entry type hangs off itself, then the entry. Download rows are kept.
     */
    public function forceDestroyEntry(Script|AiModel $entry)
    {
        Gate::authorize('hardDelete', $entry);

        $name = $entry->name;

        foreach ($entry->revisions()->get() as $revision) {
            $this->revisions->deleteFile($revision);
            $revision->delete();
        }

        $this->beforeForceDelete($entry);
        $entry->forceDelete();

        ActivityLogger::log('hard_delete', "Permanently deleted {$this->entryLabel()}: {$name}");

        return redirect()->route($this->routeName('index'))
            ->with('success', "{$this->entryLabel()} permanently deleted. Download history was kept.");
    }

    public function storeRevisionFor(StoreRevisionRequest $request, Script|AiModel $entry)
    {
        $revision = $this->revisions->upload(
            $entry,
            $request->file('file'),
            (string) $request->validated('change_note'),
            $request->user(),
        );

        ActivityLogger::log(
            'upload',
            "Uploaded Revision {$revision->number} of {$this->entryLabel()}: {$entry->name}",
            $revision,
        );

        return redirect()->route($this->routeName('show'), $entry->id)
            ->with('success', "Revision {$revision->number} uploaded as a draft.");
    }

    public function releaseRevisionFor(Request $request, Script|AiModel $entry, Revision $revision)
    {
        return $this->transitionRevision($request, $entry, $revision, 'release');
    }

    public function deprecateRevisionFor(Request $request, Script|AiModel $entry, Revision $revision)
    {
        return $this->transitionRevision($request, $entry, $revision, 'deprecate');
    }

    /**
     * The two lifecycle moves differ only in the service call and the words, and
     * both are authorised by `release` — deprecating is the same authority as
     * releasing, not a delete.
     */
    private function transitionRevision(Request $request, Script|AiModel $entry, Revision $revision, string $action)
    {
        Gate::authorize('release', $entry);
        $this->assertBelongsTo($entry, $revision);

        try {
            $this->revisions->{$action}($revision, $request->user());
        } catch (InvalidRevisionTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        $past = $action === 'release' ? 'Released' : 'Deprecated';
        $flash = $action === 'release' ? 'released' : 'deprecated';

        ActivityLogger::log(
            $action === 'release' ? 'release' : 'deprecate',
            "{$past} Revision {$revision->number} of {$this->entryLabel()}: {$entry->name}",
            $revision,
        );

        return back()->with('success', "Revision {$revision->number} {$flash}.");
    }

    public function downloadRevisionFor(Request $request, Script|AiModel $entry, Revision $revision)
    {
        Gate::authorize('view', $entry);
        $this->assertBelongsTo($entry, $revision);

        $this->downloads->record($revision, $request->user(), null, Download::SOURCE_WEB, $request);

        return $this->downloads->stream($revision);
    }

    /**
     * Hard delete one Revision: the file and the row go, the Download rows that
     * referenced it stay, and the flash says how many there were.
     */
    public function destroyRevisionFor(Script|AiModel $entry, Revision $revision)
    {
        Gate::authorize('hardDelete', $entry);
        $this->assertBelongsTo($entry, $revision);

        $number = $revision->number;
        $downloadCount = $revision->downloads()->count();

        $this->revisions->deleteFile($revision);
        $revision->delete();

        ActivityLogger::log(
            'hard_delete',
            "Permanently deleted Revision {$number} of {$this->entryLabel()}: {$entry->name}",
        );

        $message = "Revision {$number} permanently deleted.";

        if ($downloadCount > 0) {
            return back()->with('error', $message." {$downloadCount} recorded Download(s) still reference it; the Download history was kept.");
        }

        return back()->with('success', $message);
    }

    /** A Revision that does not belong to the entry in the URL is a 404, not a 403. */
    protected function assertBelongsTo(Script|AiModel $entry, Revision $revision): void
    {
        abort_unless(
            $revision->revisable_type === $entry->getMorphClass() && $revision->revisable_id === $entry->getKey(),
            404,
        );
    }

    /**
     * @return Collection<int, Revision>
     */
    protected function revisionPayload(Script|AiModel $entry)
    {
        return $entry->revisions()
            ->with(['uploader:id,name', 'releaser:id,name'])
            ->withCount('downloads')
            // How many distinct UNYSIS Boxes pulled this Revision, alongside the raw
            // total: one box retrying is not the same as ten boxes installing.
            ->addSelect(['unique_boxes_count' => Download::query()
                ->selectRaw('count(distinct unysis_box_id)')
                ->whereColumn('revision_id', 'revisions.id')])
            ->get();
    }

    /**
     * Totals for the header. The query lives on DownloadService, which owns
     * everything else about Downloads.
     *
     * @return array<string, int>
     */
    protected function downloadStats(Script|AiModel $entry): array
    {
        return $this->downloads->statsFor($entry);
    }

    /** The latest web Downloads for this entry. */
    protected function downloadPayload(Script|AiModel $entry)
    {
        return Download::query()
            ->where('revisable_type', $entry->getMorphClass())
            ->where('revisable_id', $entry->getKey())
            ->where('source', Download::SOURCE_WEB)
            ->with(['user:id,name', 'revision:id,number'])
            ->latest()
            ->limit(self::DOWNLOAD_LOG_LIMIT)
            ->get();
    }

    /**
     * Machine Models with their Machine Brand, for the grouped filter and the form select.
     */
    protected function machineModelOptions()
    {
        return MachineModel::with('machineBrand:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'machine_brand_id']);
    }

    /**
     * Slugs are never user input: they are derived from the name and kept unique
     * within the Machine Model, including against soft-deleted entries.
     */
    protected function uniqueSlug(string $name, string $machineModelId, ?string $ignoreId = null): string
    {
        $model = $this->modelClass();

        $base = Str::slug($name) ?: $this->slugFallback();
        $slug = $base;
        $suffix = 2;

        while ($model::withTrashed()
            ->where('machine_model_id', $machineModelId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
