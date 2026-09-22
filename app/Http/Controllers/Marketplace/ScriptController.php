<?php

namespace App\Http\Controllers\Marketplace;

use App\Exceptions\Marketplace\InvalidRevisionTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreRevisionRequest;
use App\Http\Requests\Marketplace\StoreScriptRequest;
use App\Http\Requests\Marketplace\SyncScriptImagesRequest;
use App\Http\Requests\Marketplace\UpdateScriptRequest;
use App\Models\Customer;
use App\Models\Download;
use App\Models\MachineModel;
use App\Models\Revision;
use App\Models\Script;
use App\Models\ScriptImage;
use App\Services\ActivityLogger;
use App\Services\Marketplace\DownloadService;
use App\Services\Marketplace\RevisionService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Scripts admin — CRUD, Preview Images and Revisions.
 *
 * The AI Models admin (AiModelController) is deliberately the same shape; the
 * two entry types stay separate in the UI and the API even though they share
 * the Revision/Download implementation.
 */
class ScriptController extends Controller
{
    public function __construct(
        private RevisionService $revisions,
        private DownloadService $downloads,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Script::class);

        $showDeleted = $request->boolean('deleted');

        $scripts = Script::query()
            ->when($showDeleted, fn ($query) => $query->onlyTrashed())
            ->with([
                'machineModel:id,name,machine_brand_id',
                'machineModel.machineBrand:id,name',
                'customer:id,company',
                'revisions:id,revisable_type,revisable_id,number,status',
            ])
            ->withCount(['revisions', 'downloads'])
            ->orderBy('name')
            ->get();

        // The latest released Revision number, computed from the eager-loaded
        // Revisions (ordered by number desc) so there is no query per row.
        $scripts->each(function (Script $script) {
            $latest = $script->revisions->firstWhere('status', Revision::STATUS_RELEASED);
            $script->setAttribute('latest_released_number', $latest?->number);
            $script->setRelation('revisions', collect());
        });

        return Inertia::render('Marketplace/Scripts/Index', [
            'scripts' => $scripts,
            'machineModels' => $this->machineModelOptions(),
            'customers' => Customer::orderBy('company')->get(['id', 'company']),
            'showDeleted' => $showDeleted,
            'canCreate' => $request->user()->can('create', Script::class),
            'canEdit' => $request->user()->can('update', new Script),
            'canDelete' => $request->user()->can('delete', new Script),
            'canHardDelete' => $request->user()->can('hardDelete', new Script),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', Script::class);

        return Inertia::render('Marketplace/Scripts/Create', [
            'machineModels' => $this->machineModelOptions(),
            'customers' => Customer::orderBy('company')->get(['id', 'company']),
        ]);
    }

    public function store(StoreScriptRequest $request)
    {
        $validated = $request->validated();

        $script = Script::create([
            'machine_model_id' => $validated['machine_model_id'],
            'customer_id' => $validated['customer_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name'], $validated['machine_model_id']),
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->getKey(),
        ]);

        ActivityLogger::log('create', "Created Script: {$script->name}", $script);

        return redirect()->route('admin.marketplace.scripts.show', $script->id)
            ->with('success', 'Script created successfully.');
    }

    public function show(Request $request, Script $script)
    {
        Gate::authorize('view', $script);

        $script->load([
            'machineModel:id,name,machine_brand_id',
            'machineModel.machineBrand:id,name',
            'customer:id,company',
            'creator:id,name',
            'images.vaultFile',
        ]);

        return Inertia::render('Marketplace/Scripts/Show', [
            'script' => $script,
            'revisions' => $this->revisionPayload($script),
            'downloads' => $this->downloadPayload($script),
            'downloadStats' => $this->downloadStats($script),
            'canEdit' => $request->user()->can('update', $script),
            'canDelete' => $request->user()->can('delete', $script),
            'canUpload' => $request->user()->can('upload', $script),
            'canRelease' => $request->user()->can('release', $script),
            'canHardDelete' => $request->user()->can('hardDelete', $script),
            'allowedExtensions' => $this->revisions->allowedExtensions($script),
            'maxUploadKb' => (int) config('marketplace.max_upload_kb'),
        ]);
    }

    public function edit(Request $request, Script $script)
    {
        Gate::authorize('update', $script);

        return Inertia::render('Marketplace/Scripts/Edit', [
            'script' => $script,
            'machineModels' => $this->machineModelOptions(),
            'customers' => Customer::orderBy('company')->get(['id', 'company']),
        ]);
    }

    public function update(UpdateScriptRequest $request, Script $script)
    {
        $validated = $request->validated();

        $script->update([
            'machine_model_id' => $validated['machine_model_id'],
            'customer_id' => $validated['customer_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name'], $validated['machine_model_id'], $script->id),
            'description' => $validated['description'] ?? null,
        ]);

        ActivityLogger::log('update', "Updated Script: {$script->name}", $script);

        return redirect()->route('admin.marketplace.scripts.show', $script->id)
            ->with('success', 'Script updated successfully.');
    }

    /**
     * Soft delete — the entry leaves the catalogue but its Revision files and
     * Download history stay until it is hard deleted.
     */
    public function destroy(Script $script)
    {
        Gate::authorize('delete', $script);

        $name = $script->name;
        $script->delete();

        ActivityLogger::log('delete', "Deleted Script: {$name}", $script);

        return redirect()->route('admin.marketplace.scripts.index')
            ->with('success', 'Script deleted. It can still be restored.');
    }

    public function restore(Script $script)
    {
        Gate::authorize('restore', $script);

        $script->restore();

        ActivityLogger::log('restore', "Restored Script: {$script->name}", $script);

        return redirect()->route('admin.marketplace.scripts.index', ['deleted' => 1])
            ->with('success', 'Script restored.');
    }

    /**
     * Hard delete — removes every Revision file, the Revision rows and the
     * Preview Image links, then the entry itself. Download rows are kept.
     */
    public function forceDestroy(Script $script)
    {
        Gate::authorize('hardDelete', $script);

        $name = $script->name;

        foreach ($script->revisions()->get() as $revision) {
            $this->revisions->deleteFile($revision);
            $revision->delete();
        }

        $script->images()->delete();
        $script->forceDelete();

        ActivityLogger::log('hard_delete', "Permanently deleted Script: {$name}");

        return redirect()->route('admin.marketplace.scripts.index')
            ->with('success', 'Script permanently deleted. Download history was kept.');
    }

    /**
     * Replace the ordered Preview Image list. The first entry is the cover.
     */
    public function syncImages(SyncScriptImagesRequest $request, Script $script)
    {
        $ids = array_values(array_filter((array) $request->validated('vault_file_ids')));

        $script->images()->delete();

        foreach ($ids as $index => $vaultFileId) {
            ScriptImage::create([
                'script_id' => $script->id,
                'vault_file_id' => $vaultFileId,
                'sort_order' => $index,
            ]);
        }

        ActivityLogger::log('update', "Updated Preview Images for Script: {$script->name}", $script);

        return redirect()->route('admin.marketplace.scripts.show', $script->id)
            ->with('success', 'Preview Images updated.');
    }

    public function storeRevision(StoreRevisionRequest $request, Script $script)
    {
        $revision = $this->revisions->upload(
            $script,
            $request->file('file'),
            (string) $request->validated('change_note'),
            $request->user(),
        );

        ActivityLogger::log(
            'upload',
            "Uploaded Revision {$revision->number} of Script: {$script->name}",
            $revision,
        );

        return redirect()->route('admin.marketplace.scripts.show', $script->id)
            ->with('success', "Revision {$revision->number} uploaded as a draft.");
    }

    public function releaseRevision(Request $request, Script $script, Revision $revision)
    {
        Gate::authorize('release', $script);
        $this->assertBelongsTo($script, $revision);

        try {
            $this->revisions->release($revision, $request->user());
        } catch (InvalidRevisionTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLogger::log(
            'release',
            "Released Revision {$revision->number} of Script: {$script->name}",
            $revision,
        );

        return back()->with('success', "Revision {$revision->number} released.");
    }

    public function deprecateRevision(Request $request, Script $script, Revision $revision)
    {
        Gate::authorize('release', $script);
        $this->assertBelongsTo($script, $revision);

        try {
            $this->revisions->deprecate($revision, $request->user());
        } catch (InvalidRevisionTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLogger::log(
            'deprecate',
            "Deprecated Revision {$revision->number} of Script: {$script->name}",
            $revision,
        );

        return back()->with('success', "Revision {$revision->number} deprecated.");
    }

    public function downloadRevision(Request $request, Script $script, Revision $revision)
    {
        Gate::authorize('view', $script);
        $this->assertBelongsTo($script, $revision);

        $this->downloads->record($revision, $request->user(), null, Download::SOURCE_WEB, $request);

        return $this->downloads->stream($revision);
    }

    /**
     * Hard delete one Revision: the file and the row go, the Download rows that
     * referenced it stay, and the flash says how many there were.
     */
    public function destroyRevision(Script $script, Revision $revision)
    {
        Gate::authorize('hardDelete', $script);
        $this->assertBelongsTo($script, $revision);

        $number = $revision->number;
        $downloadCount = $revision->downloads()->count();

        $this->revisions->deleteFile($revision);
        $revision->delete();

        ActivityLogger::log(
            'hard_delete',
            "Permanently deleted Revision {$number} of Script: {$script->name}",
        );

        $message = "Revision {$number} permanently deleted.";

        if ($downloadCount > 0) {
            return back()->with('error', $message." {$downloadCount} recorded Download(s) still reference it; the Download history was kept.");
        }

        return back()->with('success', $message);
    }

    private function assertBelongsTo(Script $script, Revision $revision): void
    {
        abort_unless(
            $revision->revisable_type === $script->getMorphClass() && $revision->revisable_id === $script->getKey(),
            404,
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function revisionPayload(Script $script)
    {
        return $script->revisions()
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
     * Totals for the header: every Download of this entry, and how many distinct
     * UNYSIS Boxes are behind them.
     *
     * @return array<string, int>
     */
    private function downloadStats(Script $script): array
    {
        $base = Download::query()
            ->where('revisable_type', $script->getMorphClass())
            ->where('revisable_id', $script->getKey());

        return [
            'total' => (clone $base)->count(),
            'unique_boxes' => (clone $base)->whereNotNull('unysis_box_id')->distinct()->count('unysis_box_id'),
        ];
    }

    /**
     * The latest 50 web Downloads for this entry.
     */
    private function downloadPayload(Script $script)
    {
        return Download::query()
            ->where('revisable_type', $script->getMorphClass())
            ->where('revisable_id', $script->getKey())
            ->where('source', Download::SOURCE_WEB)
            ->with(['user:id,name', 'revision:id,number'])
            ->latest()
            ->limit(50)
            ->get();
    }

    /**
     * Machine Models with their Machine Brand, for the grouped filter and the form select.
     */
    private function machineModelOptions()
    {
        return MachineModel::with('machineBrand:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'machine_brand_id']);
    }

    /**
     * Slugs are derived from the name and kept unique within the Machine Model.
     */
    private function uniqueSlug(string $name, string $machineModelId, ?string $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'script-script';
        $slug = $base;
        $suffix = 2;

        while (Script::withTrashed()
            ->where('machine_model_id', $machineModelId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
