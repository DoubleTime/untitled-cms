<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Services\ActivityLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MenuController extends Controller
{
    /**
     * Validates that a menu item URL does not use a dangerous URI scheme.
     */
    private function menuItemUrlRules(): array
    {
        return ['nullable', 'string', 'max:2048', function ($attribute, $value, $fail) {
            if ($value && preg_match('/^\s*(javascript|data|vbscript):/i', $value)) {
                $fail('The menu item URL must not use a dangerous URI scheme.');
            }
        }];
    }

    /**
     * Rules for the item tree built by Menus/Edit.tsx and MenuSeeder
     * ({id, title, url, target, order, subItems}).
     * Every persisted key must be listed: validated() strips unlisted nested array keys.
     */
    private function menuItemRules(): array
    {
        return [
            'items' => 'nullable|array',
            'items.*.id' => 'nullable|string|max:64',
            'items.*.title' => 'nullable|string|max:255',
            'items.*.url' => $this->menuItemUrlRules(),
            'items.*.target' => 'nullable|string|in:_self,_blank',
            'items.*.order' => 'nullable|integer',
            'items.*.subItems' => 'nullable|array',
            'items.*.subItems.*.id' => 'nullable|string|max:64',
            'items.*.subItems.*.title' => 'nullable|string|max:255',
            'items.*.subItems.*.url' => $this->menuItemUrlRules(),
            'items.*.subItems.*.target' => 'nullable|string|in:_self,_blank',
            'items.*.subItems.*.order' => 'nullable|integer',
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', Menu::class);

        $menus = Menu::orderBy('name', 'asc')->get();

        return Inertia::render('Menus/Index', [
            'menus' => $menus,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Menu::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique(Menu::class, 'slug')],
            'is_active' => 'boolean',
            ...$this->menuItemRules(),
        ]);

        try {
            $menu = Menu::create($validated);
        } catch (UniqueConstraintViolationException $e) {
            return back()->withErrors(['slug' => 'The slug has already been taken.'])->withInput();
        }

        ActivityLogger::log('create', "Created menu: {$menu->name}", $menu);
        Cache::forget('active_menus');

        return redirect()->route('admin.menus.edit', $menu->id)->with('success', 'Menu created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $menu = Menu::findOrFail($id);
        $this->authorize('update', $menu);

        return Inertia::render('Menus/Edit', [
            'menu' => $menu,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $menu = Menu::findOrFail($id);
        $this->authorize('update', $menu);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique(Menu::class, 'slug')->ignore($id)],
            ...$this->menuItemRules(),
            'is_active' => 'boolean',
        ]);

        try {
            $menu->update($validated);
        } catch (UniqueConstraintViolationException $e) {
            return back()->withErrors(['slug' => 'The slug has already been taken.'])->withInput();
        }

        ActivityLogger::log('update', "Updated menu: {$menu->name}", $menu);
        Cache::forget('active_menus');

        if ($request->has('stay')) {
            return redirect()->back()->with('success', 'Menu updated successfully.');
        }

        return redirect()->route('admin.menus.index')->with('success', 'Menu updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Menu $menu)
    {
        $this->authorize('delete', $menu);

        $name = $menu->name;
        $menu->delete();

        ActivityLogger::log('delete', "Deleted menu: {$name}", $menu);
        Cache::forget('active_menus');

        return redirect()->route('admin.menus.index')->with('success', 'Menu deleted successfully.');
    }
}
