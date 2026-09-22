<?php

namespace App\Http\Controllers\Marketplace;

use App\Exceptions\Marketplace\InvalidRevisionTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreAiModelRequest;
use App\Http\Requests\Marketplace\StoreRevisionRequest;
use App\Http\Requests\Marketplace\UpdateAiModelRequest;
use App\Models\AiModel;
use App\Models\Customer;
use App\Models\Download;
use App\Models\MachineModel;
use App\Models\Revision;
use App\Services\ActivityLogger;
use App\Services\Marketplace\DownloadService;
use App\Services\Marketplace\RevisionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * AI Models admin — CRUD and Revisions. Same shape as FlowchartScriptController
 * minus Preview Images, plus the model-specific fields (framework, input size,
 * labels, notes).
 */
class AiModelController extends Controller
{
    public function __construct(
        private RevisionService $revisions,
        private DownloadService $downloads,
    ) {}

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

        return Inertia::render('Marketplace/AiModels/Index', [
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

        return Inertia::render('Marketplace/AiModels/Create', [
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
            'framework' => $validated['framework'] ?? null,
            'input_size' => $validated['input_size'] ?? null,
            'labels' => $validated['labels'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()->getKey(),
        ]);

        ActivityLogger::log('create', "Created AI Model: {$aiModel->name}", $aiModel);

        return redirect()->route('admin.marketplace.ai-models.show', $aiModel->id)
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

        return Inertia::render('Marketplace/AiModels/Show', [
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

        return Inertia::render('Marketplace/AiModels/Edit', [
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
            'framework' => $validated['framework'] ?? null,
            'input_size' => $validated['input_size'] ?? null,
            'labels' => $validated['labels'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        ActivityLogger::log('update', "Updated AI Model: {$aiModel->name}", $aiModel);

        return redirect()->route('admin.marketplace.ai-models.show', $aiModel->id)
            ->with('success', 'AI Model updated successfully.');
    }

    public function destroy(AiModel $aiModel)
    {
        Gate::authorize('delete', $aiModel);

        $name = $aiModel->name;
        $aiModel->delete();

        ActivityLogger::log('delete', "Deleted AI Model: {$name}", $aiModel);

        return redirect()->route('admin.marketplace.ai-models.index')
            ->with('success', 'AI Model deleted. It can still be restored.');
    }

    public function restore(AiModel $aiModel)
    {
        Gate::authorize('restore', $aiModel);

        $aiModel->restore();

        ActivityLogger::log('restore', "Restored AI Model: {$aiModel->name}", $aiModel);

        return redirect()->route('admin.marketplace.ai-models.index', ['deleted' => 1])
            ->with('success', 'AI Model restored.');
    }

    public function forceDestroy(AiModel $aiModel)
    {
        Gate::authorize('hardDelete', $aiModel);

        $name = $aiModel->name;

        foreach ($aiModel->revisions()->get() as $revision) {
            $this->revisions->deleteFile($revision);
            $revision->delete();
        }

        $aiModel->forceDelete();

        ActivityLogger::log('hard_delete', "Permanently deleted AI Model: {$name}");

        return redirect()->route('admin.marketplace.ai-models.index')
            ->with('success', 'AI Model permanently deleted. Download history was kept.');
    }

    public function storeRevision(StoreRevisionRequest $request, AiModel $aiModel)
    {
        $revision = $this->revisions->upload(
            $aiModel,
            $request->file('file'),
            (string) $request->validated('change_note'),
            $request->user(),
        );

        ActivityLogger::log(
            'upload',
            "Uploaded Revision {$revision->number} of AI Model: {$aiModel->name}",
            $revision,
        );

        return redirect()->route('admin.marketplace.ai-models.show', $aiModel->id)
            ->with('success', "Revision {$revision->number} uploaded as a draft.");
    }

    public function releaseRevision(Request $request, AiModel $aiModel, Revision $revision)
    {
        Gate::authorize('release', $aiModel);
        $this->assertBelongsTo($aiModel, $revision);

        try {
            $this->revisions->release($revision, $request->user());
        } catch (InvalidRevisionTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLogger::log(
            'release',
            "Released Revision {$revision->number} of AI Model: {$aiModel->name}",
            $revision,
        );

        return back()->with('success', "Revision {$revision->number} released.");
    }

    public function deprecateRevision(Request $request, AiModel $aiModel, Revision $revision)
    {
        Gate::authorize('release', $aiModel);
        $this->assertBelongsTo($aiModel, $revision);

        try {
            $this->revisions->deprecate($revision, $request->user());
        } catch (InvalidRevisionTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLogger::log(
            'deprecate',
            "Deprecated Revision {$revision->number} of AI Model: {$aiModel->name}",
            $revision,
        );

        return back()->with('success', "Revision {$revision->number} deprecated.");
    }

    public function downloadRevision(Request $request, AiModel $aiModel, Revision $revision)
    {
        Gate::authorize('view', $aiModel);
        $this->assertBelongsTo($aiModel, $revision);

        $this->downloads->record($revision, $request->user(), null, Download::SOURCE_WEB, $request);

        return $this->downloads->stream($revision);
    }

    public function destroyRevision(AiModel $aiModel, Revision $revision)
    {
        Gate::authorize('hardDelete', $aiModel);
        $this->assertBelongsTo($aiModel, $revision);

        $number = $revision->number;
        $downloadCount = $revision->downloads()->count();

        $this->revisions->deleteFile($revision);
        $revision->delete();

        ActivityLogger::log(
            'hard_delete',
            "Permanently deleted Revision {$number} of AI Model: {$aiModel->name}",
        );

        $message = "Revision {$number} permanently deleted.";

        if ($downloadCount > 0) {
            return back()->with('error', $message." {$downloadCount} recorded Download(s) still reference it; the Download history was kept.");
        }

        return back()->with('success', $message);
    }

    private function assertBelongsTo(AiModel $aiModel, Revision $revision): void
    {
        abort_unless(
            $revision->revisable_type === $aiModel->getMorphClass() && $revision->revisable_id === $aiModel->getKey(),
            404,
        );
    }

    private function revisionPayload(AiModel $aiModel)
    {
        return $aiModel->revisions()
            ->with(['uploader:id,name', 'releaser:id,name'])
            ->withCount('downloads')
            // How many distinct AI Boxes pulled this Revision, alongside the raw
            // total: one box retrying is not the same as ten boxes installing.
            ->addSelect(['unique_boxes_count' => Download::query()
                ->selectRaw('count(distinct ai_box_id)')
                ->whereColumn('revision_id', 'revisions.id')])
            ->get();
    }

    /**
     * Totals for the header: every Download of this entry, and how many distinct
     * AI Boxes are behind them.
     *
     * @return array<string, int>
     */
    private function downloadStats(AiModel $aiModel): array
    {
        $base = Download::query()
            ->where('revisable_type', $aiModel->getMorphClass())
            ->where('revisable_id', $aiModel->getKey());

        return [
            'total' => (clone $base)->count(),
            'unique_boxes' => (clone $base)->whereNotNull('ai_box_id')->distinct()->count('ai_box_id'),
        ];
    }

    private function downloadPayload(AiModel $aiModel)
    {
        return Download::query()
            ->where('revisable_type', $aiModel->getMorphClass())
            ->where('revisable_id', $aiModel->getKey())
            ->where('source', Download::SOURCE_WEB)
            ->with(['user:id,name', 'revision:id,number'])
            ->latest()
            ->limit(50)
            ->get();
    }

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
        $base = Str::slug($name) ?: 'ai-model';
        $slug = $base;
        $suffix = 2;

        while (AiModel::withTrashed()
            ->where('machine_model_id', $machineModelId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
