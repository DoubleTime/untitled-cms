<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Requests\Marketplace\StoreAiModelRequest;
use App\Http\Requests\Marketplace\StoreRevisionRequest;
use App\Http\Requests\Marketplace\UpdateAiModelRequest;
use App\Models\AiModel;
use App\Models\Customer;
use App\Models\Revision;
use App\Services\ActivityLogger;
use App\Support\CatalogueEntryType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * AI Models admin — CRUD plus the inference metadata (framework, input size,
 * labels, notes). No Preview Images.
 *
 * The Revision lifecycle, hard delete, restore and the shared payload builders
 * are in CatalogueAdminController; the typed methods at the bottom exist so
 * implicit route model binding can resolve `{ai_model}`.
 */
class AiModelController extends CatalogueAdminController
{
    /** The AI Model fields that are neither shared nor derived. */
    private const METADATA_FIELDS = ['framework', 'input_size', 'labels', 'notes'];

    protected function modelClass(): string
    {
        return AiModel::class;
    }

    protected function entryLabel(): string
    {
        return 'AI Model';
    }

    protected function routePrefix(): string
    {
        return 'admin.marketplace.ai-models.';
    }

    protected function pagePrefix(): string
    {
        return 'Marketplace/AiModels';
    }

    protected function slugFallback(): string
    {
        return str_replace('_', '-', CatalogueEntryType::AI_MODEL);
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', AiModel::class);

        $showDeleted = $request->boolean('deleted');

        $aiModels = AiModel::query()
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

        $aiModels->each(function (AiModel $aiModel) {
            $latest = $aiModel->revisions->firstWhere('status', Revision::STATUS_RELEASED);
            $aiModel->setAttribute('latest_released_number', $latest?->number);
            $aiModel->setRelation('revisions', collect());
        });

        return Inertia::render($this->page('Index'), [
            'aiModels' => $aiModels,
            'machineModels' => $this->machineModelOptions(),
            'customers' => Customer::orderBy('company')->get(['id', 'company']),
            'showDeleted' => $showDeleted,
            'canCreate' => $request->user()->can('create', AiModel::class),
            'canEdit' => $request->user()->can('update', new AiModel),
            'canDelete' => $request->user()->can('delete', new AiModel),
            'canHardDelete' => $request->user()->can('hardDelete', new AiModel),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', AiModel::class);

        return Inertia::render($this->page('Create'), [
            'machineModels' => $this->machineModelOptions(),
            'customers' => Customer::orderBy('company')->get(['id', 'company']),
        ]);
    }

    public function store(StoreAiModelRequest $request)
    {
        $validated = $request->validated();

        $aiModel = AiModel::create([
            'machine_model_id' => $validated['machine_model_id'],
            'customer_id' => $validated['customer_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name'], $validated['machine_model_id']),
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->getKey(),
        ] + $this->metadata($validated));

        ActivityLogger::log('create', "Created AI Model: {$aiModel->name}", $aiModel);

        return redirect()->route($this->routeName('show'), $aiModel->id)
            ->with('success', 'AI Model created successfully.');
    }

    public function show(Request $request, AiModel $aiModel)
    {
        Gate::authorize('view', $aiModel);

        $aiModel->load([
            'machineModel:id,name,machine_brand_id',
            'machineModel.machineBrand:id,name',
            'customer:id,company',
            'creator:id,name',
        ]);

        return Inertia::render($this->page('Show'), [
            'aiModel' => $aiModel,
            'revisions' => $this->revisionPayload($aiModel),
            'downloads' => $this->downloadPayload($aiModel),
            'downloadStats' => $this->downloadStats($aiModel),
            'canEdit' => $request->user()->can('update', $aiModel),
            'canDelete' => $request->user()->can('delete', $aiModel),
            'canUpload' => $request->user()->can('upload', $aiModel),
            'canRelease' => $request->user()->can('release', $aiModel),
            'canHardDelete' => $request->user()->can('hardDelete', $aiModel),
            'allowedExtensions' => $this->revisions->allowedExtensions($aiModel),
            'maxUploadKb' => (int) config('marketplace.max_upload_kb'),
        ]);
    }

    public function edit(AiModel $aiModel)
    {
        Gate::authorize('update', $aiModel);

        return Inertia::render($this->page('Edit'), [
            'aiModel' => $aiModel,
            'machineModels' => $this->machineModelOptions(),
            'customers' => Customer::orderBy('company')->get(['id', 'company']),
        ]);
    }

    public function update(UpdateAiModelRequest $request, AiModel $aiModel)
    {
        $validated = $request->validated();

        $aiModel->update([
            'machine_model_id' => $validated['machine_model_id'],
            'customer_id' => $validated['customer_id'] ?? null,
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name'], $validated['machine_model_id'], $aiModel->id),
            'description' => $validated['description'] ?? null,
        ] + $this->metadata($validated));

        ActivityLogger::log('update', "Updated AI Model: {$aiModel->name}", $aiModel);

        return redirect()->route($this->routeName('show'), $aiModel->id)
            ->with('success', 'AI Model updated successfully.');
    }

    public function destroy(AiModel $aiModel)
    {
        Gate::authorize('delete', $aiModel);

        $name = $aiModel->name;
        $aiModel->delete();

        ActivityLogger::log('delete', "Deleted AI Model: {$name}", $aiModel);

        return redirect()->route($this->routeName('index'))
            ->with('success', 'AI Model deleted. It can still be restored.');
    }

    /**
     * The inference metadata, as a fillable array — the one part of the payload
     * Scripts have no equivalent of.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function metadata(array $validated): array
    {
        $out = [];

        foreach (self::METADATA_FIELDS as $field) {
            $out[$field] = $validated[$field] ?? null;
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Typed overrides. Implicit route model binding matches on the parameter
    // name, so `{ai_model}` needs an `$aiModel` parameter declared here; each
    // one delegates straight to CatalogueAdminController.
    // ---------------------------------------------------------------------

    public function restore(AiModel $aiModel)
    {
        return $this->restoreEntry($aiModel);
    }

    public function forceDestroy(AiModel $aiModel)
    {
        return $this->forceDestroyEntry($aiModel);
    }

    public function storeRevision(StoreRevisionRequest $request, AiModel $aiModel)
    {
        return $this->storeRevisionFor($request, $aiModel);
    }

    public function releaseRevision(Request $request, AiModel $aiModel, Revision $revision)
    {
        return $this->releaseRevisionFor($request, $aiModel, $revision);
    }

    public function deprecateRevision(Request $request, AiModel $aiModel, Revision $revision)
    {
        return $this->deprecateRevisionFor($request, $aiModel, $revision);
    }

    public function downloadRevision(Request $request, AiModel $aiModel, Revision $revision)
    {
        return $this->downloadRevisionFor($request, $aiModel, $revision);
    }

    public function destroyRevision(AiModel $aiModel, Revision $revision)
    {
        return $this->destroyRevisionFor($aiModel, $revision);
    }
}
