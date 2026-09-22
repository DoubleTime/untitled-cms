<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreMachineBrandRequest;
use App\Http\Requests\Marketplace\UpdateMachineBrandRequest;
use App\Models\MachineBrand;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MachineBrandController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', MachineBrand::class);

        $machineBrands = MachineBrand::withCount('machineModels')
            ->orderBy('name')
            ->get();

        return Inertia::render('Marketplace/MachineBrands/Index', [
            'machineBrands' => $machineBrands,
            'canCreate' => $request->user()->can('create', MachineBrand::class),
            'canEdit' => $request->user()->can('update', new MachineBrand),
            'canDelete' => $request->user()->can('delete', new MachineBrand),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', MachineBrand::class);

        return Inertia::render('Marketplace/MachineBrands/Create');
    }

    public function store(StoreMachineBrandRequest $request)
    {
        $validated = $request->validated();

        $machineBrand = MachineBrand::create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name']),
        ]);

        ActivityLogger::log('create', "Created Machine Brand: {$machineBrand->name}", $machineBrand);

        return redirect()->route('admin.marketplace.machine-brands.index')
            ->with('success', 'Machine Brand created successfully.');
    }

    public function edit(MachineBrand $machineBrand)
    {
        Gate::authorize('update', $machineBrand);

        return Inertia::render('Marketplace/MachineBrands/Edit', [
            'machineBrand' => $machineBrand,
        ]);
    }

    public function update(UpdateMachineBrandRequest $request, MachineBrand $machineBrand)
    {
        $validated = $request->validated();

        $machineBrand->update([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name'], $machineBrand->id),
        ]);

        ActivityLogger::log('update', "Updated Machine Brand: {$machineBrand->name}", $machineBrand);

        return redirect()->route('admin.marketplace.machine-brands.index')
            ->with('success', 'Machine Brand updated successfully.');
    }

    public function destroy(MachineBrand $machineBrand)
    {
        Gate::authorize('delete', $machineBrand);

        // No cascade: a Brand still naming Machine Models must be emptied first.
        if ($machineBrand->machineModels()->exists()) {
            return redirect()->route('admin.marketplace.machine-brands.index')
                ->with('error', 'This Machine Brand still has Machine Models. Delete or reassign them first.');
        }

        $name = $machineBrand->name;
        $machineBrand->delete();

        ActivityLogger::log('delete', "Deleted Machine Brand: {$name}");

        return redirect()->route('admin.marketplace.machine-brands.index')
            ->with('success', 'Machine Brand deleted successfully.');
    }

    /**
     * Slugs are derived from the name and kept unique with a numeric suffix.
     */
    private function uniqueSlug(string $name, ?string $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'machine-brand';
        $slug = $base;
        $suffix = 2;

        while (MachineBrand::where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
