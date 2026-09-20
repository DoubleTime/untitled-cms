<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Services\ActivityLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BannerController extends Controller
{
    /**
     * Validates that a slide URL does not use a dangerous URI scheme.
     */
    private function slideUrlRules(): array
    {
        return ['nullable', 'string', 'max:2048', function ($attribute, $value, $fail) {
            if ($value && preg_match('/^\s*(javascript|data|vbscript):/i', $value)) {
                $fail('The slide URL must not use a dangerous URI scheme.');
            }
        }];
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', Banner::class);

        $banners = Banner::orderBy('order', 'asc')->get();

        return Inertia::render('Banners/Index', [
            'banners' => $banners,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $this->authorize('create', Banner::class);

        return Inertia::render('Banners/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Banner::class);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique(Banner::class, 'slug')],
            'slides' => 'nullable|array',
            'slides.*.image' => 'required_with:slides|string',
            'slides.*.url' => $this->slideUrlRules(),
            'slides.*.target' => 'nullable|string|in:_self,_blank',
            'slides.*.sequence' => 'nullable|numeric',
            'slides.*.title' => 'nullable|string',
            'slides.*.subtitle' => 'nullable|string',
            'slides.*.caption' => 'nullable|string',
            'is_active' => 'boolean',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
        ]);

        try {
            $banner = Banner::create($validated);
        } catch (UniqueConstraintViolationException $e) {
            return back()->withErrors(['slug' => 'The slug has already been taken.'])->withInput();
        }

        ActivityLogger::log('create', "Created banner: {$banner->title}", $banner);

        return redirect()->route('admin.banners.index')->with('success', 'Banner created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $banner = Banner::findOrFail($id);
        $this->authorize('update', $banner);

        return Inertia::render('Banners/Edit', [
            'banner' => $banner,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $banner = Banner::findOrFail($id);
        $this->authorize('update', $banner);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:255', Rule::unique(Banner::class, 'slug')->ignore($id)],
            'slides' => 'nullable|array',
            'slides.*.image' => 'required_with:slides|string',
            'slides.*.url' => $this->slideUrlRules(),
            'slides.*.target' => 'nullable|string|in:_self,_blank',
            'slides.*.sequence' => 'nullable|numeric',
            'slides.*.title' => 'nullable|string',
            'slides.*.subtitle' => 'nullable|string',
            'slides.*.caption' => 'nullable|string',
            'is_active' => 'boolean',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
        ]);

        try {
            $banner->update($validated);
        } catch (UniqueConstraintViolationException $e) {
            return back()->withErrors(['slug' => 'The slug has already been taken.'])->withInput();
        }

        ActivityLogger::log('update', "Updated banner: {$banner->title}", $banner);

        if ($request->has('stay')) {
            return redirect()->back()->with('success', 'Banner status updated successfully.');
        }

        return redirect()->route('admin.banners.index')->with('success', 'Banner updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Banner $banner)
    {
        $this->authorize('delete', $banner);

        $title = $banner->title;
        $banner->delete();

        ActivityLogger::log('delete', "Deleted banner: {$title}", $banner);

        return redirect()->route('admin.banners.index')->with('success', 'Banner deleted successfully.');
    }
}
