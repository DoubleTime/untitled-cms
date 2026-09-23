<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Requests\Marketplace\StoreRevisionRequest;
use App\Http\Requests\Marketplace\StoreScriptRequest;
use App\Http\Requests\Marketplace\SyncScriptImagesRequest;
use App\Http\Requests\Marketplace\UpdateScriptRequest;
use App\Models\AiModel;
use App\Models\Customer;
use App\Models\Revision;
use App\Models\Script;
use App\Models\ScriptImage;
use App\Services\ActivityLogger;
use App\Support\CatalogueEntryType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Scripts admin — CRUD and Preview Images.
 *
 * Everything the two catalogue entry types do identically — the Revision
 * lifecycle, hard delete, restore and the shared payload builders — lives in
 * CatalogueAdminController. What is left here is the Script-specific half, plus
 * the thin typed overrides that let implicit route model binding resolve
 * `{script}` and `{revision}`.
 */
class ScriptController extends CatalogueAdminController
{
    protected function modelClass(): string
    {
        return Script::class;
    }

    protected function entryLabel(): string
    {
        return 'Script';
    }

    protected function routePrefix(): string
    {
        return 'admin.marketplace.scripts.';
    }

    protected function pagePrefix(): string
    {
        return 'Marketplace/Scripts';
    }

    protected function slugFallback(): string
    {
        return CatalogueEntryType::SCRIPT;
    }

    /** Preview Image links go with the Script when it is hard deleted. */
    protected function beforeForceDelete(Script|AiModel $entry): void
    {
        $entry->images()->delete();
    }

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

        return Inertia::render($this->page('Index'), [
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

        return Inertia::render($this->page('Create'), [
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

        return redirect()->route($this->routeName('show'), $script->id)
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

        return Inertia::render($this->page('Show'), [
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

        return Inertia::render($this->page('Edit'), [
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

        return redirect()->route($this->routeName('show'), $script->id)
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

        return redirect()->route($this->routeName('index'))
            ->with('success', 'Script deleted. It can still be restored.');
    }

    /**
     * Replace the ordered Preview Image list. The first entry is the one the
     * Script is shown by.
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

        return redirect()->route($this->routeName('show'), $script->id)
            ->with('success', 'Preview Images updated.');
    }

    // ---------------------------------------------------------------------
    // Typed overrides. Implicit route model binding matches on the parameter
    // name, so `{script}` needs a `$script` parameter declared here; each one
    // delegates straight to CatalogueAdminController.
    // ---------------------------------------------------------------------

    public function restore(Script $script)
    {
        return $this->restoreEntry($script);
    }

    public function forceDestroy(Script $script)
    {
        return $this->forceDestroyEntry($script);
    }

    public function storeRevision(StoreRevisionRequest $request, Script $script)
    {
        return $this->storeRevisionFor($request, $script);
    }

    public function releaseRevision(Request $request, Script $script, Revision $revision)
    {
        return $this->releaseRevisionFor($request, $script, $revision);
    }

    public function deprecateRevision(Request $request, Script $script, Revision $revision)
    {
        return $this->deprecateRevisionFor($request, $script, $revision);
    }

    public function downloadRevision(Request $request, Script $script, Revision $revision)
    {
        return $this->downloadRevisionFor($request, $script, $revision);
    }

    public function destroyRevision(Script $script, Revision $revision)
    {
        return $this->destroyRevisionFor($script, $revision);
    }
}
