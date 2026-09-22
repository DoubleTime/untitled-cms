<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreMachineModelRequest;
use App\Http\Requests\Marketplace\UpdateMachineModelRequest;
use App\Models\MachineBrand;
use App\Models\MachineModel;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MachineModelController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', MachineModel::class);

        $machineModels = MachineModel::with('machineBrand:id,name')
            ->withCount(['scripts', 'aiModels'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Marketplace/MachineModels/Index', [
            'machineModels' => $machineModels,
            'machineBrands' => MachineBrand::orderBy('name')->get(['id', 'name']),
            'canCreate' => $request->user()->can('create', MachineModel::class),
            'canEdit' => $request->user()->can('update', new MachineModel),
            'canDelete' => $request->user()->can('delete', new MachineModel),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', MachineModel::class);

        return Inertia::render('Marketplace/MachineModels/Create', [
            'machineBrands' => MachineBrand::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreMachineModelRequest $request)
    {
        $validated = $request->validated();

        $machineModel = MachineModel::create([
            'machine_brand_id' => $validated['machine_brand_id'],
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        ActivityLogger::log('create', "Created Machine Model: {$machineModel->name}", $machineModel);

        return redirect()->route('admin.marketplace.machine-models.index')
            ->with('success', 'Machine Model created successfully.');
    }

    public function edit(MachineModel $machineModel)
    {
        Gate::authorize('update', $machineModel);

        return Inertia::render('Marketplace/MachineModels/Edit', [
            'machineModel' => $machineModel,
            'machineBrands' => MachineBrand::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateMachineModelRequest $request, MachineModel $machineModel)
    {
        $validated = $request->validated();

        $machineModel->update([
            'machine_brand_id' => $validated['machine_brand_id'],
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name'], $machineModel->id),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? $machineModel->is_active,
        ]);

        ActivityLogger::log('update', "Updated Machine Model: {$machineModel->name}", $machineModel);

        return redirect()->route('admin.marketplace.machine-models.index')
            ->with('success', 'Machine Model updated successfully.');
    }

    public function destroy(MachineModel $machineModel)
    {
        Gate::authorize('delete', $machineModel);

        // No cascade: catalogue entries targeting this Machine Model must be moved first.
        if ($machineModel->scripts()->exists() || $machineModel->aiModels()->exists()) {
            return redirect()->route('admin.marketplace.machine-models.index')
                ->with('error', 'This Machine Model still has Scripts or AI Models. Delete or reassign them first.');
        }

        $name = $machineModel->name;
        $machineModel->delete();

        ActivityLogger::log('delete', "Deleted Machine Model: {$name}");

        return redirect()->route('admin.marketplace.machine-models.index')
            ->with('success', 'Machine Model deleted successfully.');
    }

    /**
     * Slugs are derived from the name and kept unique with a numeric suffix.
     */
    private function uniqueSlug(string $name, ?string $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'machine-model';
        $slug = $base;
        $suffix = 2;

        while (MachineModel::where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
