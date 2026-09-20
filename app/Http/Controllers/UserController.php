<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Ensure user has permission
        Gate::authorize('viewAny', User::class);

        $users = User::with('roles')->paginate(10);
        $roles = Role::all();

        $stats = Cache::remember('user_stats', 60, fn () => [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'deleted' => User::onlyTrashed()->count(),
        ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles,
            'stats' => $stats,
            'canCreate' => $request->user()->can('create', User::class),
            // We check permissions directly for UI flags using a generic model instance
            'canEdit' => $request->user()->can('update', new User),
            'canDelete' => $request->user()->can('delete', new User),
        ]);
    }

    /**
     * Get soft-deleted users via AJAX.
     */
    public function trashed(Request $request)
    {
        Gate::authorize('viewAny', User::class);
        $trashedUsers = User::onlyTrashed()->with('roles')->paginate(50);

        return response()->json($trashedUsers);
    }

    /**
     * Invite a new user.
     */
    public function invite(Request $request)
    {
        Gate::authorize('create', User::class);

        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'role' => 'required|exists:roles,id',
        ]);

        $user = User::create([
            'email' => $validated['email'],
            'name' => explode('@', $validated['email'])[0], // Temporary name
            'password' => Hash::make(Str::random(16)),
            'is_active' => true,  // Admin-invited users are immediately active
            'email_verified_at' => now(), // Admin-invited users are pre-verified (admin vouches for them)
        ]);

        $user->roles()->attach($validated['role']);

        // In a real app, you would send an email here
        // \Mail::to($user->email)->send(new \App\Mail\UserInvitation($user));

        return redirect()->back()->with('success', 'Invitation sent to '.$validated['email']);
    }

    /**
     * Batch activate users.
     */
    public function batchActivate(Request $request)
    {
        // Treat as update
        // We use a generic class policy check to avoid instantiating dummy models
        Gate::authorize('batchUpdate', User::class);

        $request->validate(['user_ids' => 'required|array']);

        $count = User::whereKey($request->user_ids)->update(['is_active' => true]);

        $this->bustStatsCache();

        return redirect()->back()->with('success', "Activated {$count} users.");
    }

    /**
     * Batch deactivate users.
     */
    public function batchDeactivate(Request $request)
    {
        Gate::authorize('batchUpdate', User::class);

        $request->validate(['user_ids' => 'required|array']);

        // Prevent deactivating self
        $ids = array_filter($request->user_ids, fn ($id) => $id !== auth()->id());
        $count = User::whereKey($ids)->update(['is_active' => false]);

        $this->bustStatsCache();

        return redirect()->back()->with('success', "Deactivated {$count} users.");
    }

    /**
     * Batch delete users.
     */
    public function batchDelete(Request $request)
    {
        Gate::authorize('batchDelete', User::class);

        $request->validate(['user_ids' => 'required|array']);

        // Prevent deleting self
        $ids = array_filter($request->user_ids, fn ($id) => $id !== auth()->id());
        $count = User::whereKey($ids)->delete();

        $this->bustStatsCache();

        return redirect()->back()->with('success', "Deleted {$count} users.");
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Users/Create', [
            'roles' => Role::all(['id', 'name', 'slug']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
        // Validation and Authorization are handled in StoreUserRequest
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(), // Auto-verify for admin-created users
            'is_active' => true, // New users are active by default
        ]);

        $user->roles()->attach($validated['roles']);

        $this->bustStatsCache();

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $user = User::with('roles')->findOrFail($id);
        Gate::authorize('update', $user);

        return Inertia::render('Users/Edit', [
            'user' => $user,
            'roles' => Role::all(['id', 'name', 'slug']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        // Validation and Authorization are handled in UpdateUserRequest
        $validated = $request->validated();

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'is_active' => $validated['is_active'] ?? $user->is_active,
        ]);

        if (! empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        // Assuming syncRoles is a method provided by a package like Spatie's laravel-permission
        // If not, use $user->roles()->sync($validated['roles']);
        $user->syncRoles($validated['roles']);

        ActivityLogger::log('update', "Updated user: {$user->name}", $user);

        $this->bustStatsCache();

        if ($request->has('stay')) {
            return redirect()->back()->with('success', 'User status updated successfully.');
        }

        return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user) // Changed to use Route Model Binding
    {
        Gate::authorize('delete', $user);

        // Prevent deleting self (optional but good practice)
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')->with('error', 'You cannot delete yourself.');
        }

        $name = $user->name; // Store name before deletion for logging

        // If using Spatie's laravel-permission, roles are often detached automatically on user delete.
        // If not, ensure roles are detached: $user->roles()->detach();
        $user->delete();

        $this->bustStatsCache();

        return redirect()->route('admin.users.index')->with('success', 'User deleted successfully.');
    }

    /**
     * Restore a soft-deleted user.
     */
    public function restore(string $id)
    {
        $user = User::onlyTrashed()->findOrFail($id);
        Gate::authorize('restore', $user);

        $user->restore();

        ActivityLogger::log('restore', "Restored user: {$user->name}", $user);

        $this->bustStatsCache();

        return redirect()->route('admin.users.index')->with('success', 'User restored successfully.');
    }

    /**
     * Permanently delete a user.
     */
    public function forceDelete(string $id)
    {
        $user = User::onlyTrashed()->findOrFail($id);
        Gate::authorize('forceDelete', $user);

        $name = $user->name;
        $user->roles()->detach();
        $user->forceDelete();

        ActivityLogger::log('force_delete', "Permanently deleted user: {$name}");

        return redirect()->route('admin.users.index')->with('success', 'User permanently deleted.');
    }

    /**
     * Clear the user stats cache.
     */
    private function bustStatsCache()
    {
        Cache::forget('user_stats');
    }

    /**
     * Logout user from all devices by incrementing session_version.
     * The VerifySessionVersion middleware checks this value on each request
     * and logs out any session whose version no longer matches the DB value.
     * Works with any session driver (file, database, Redis, etc.).
     */
    public function logoutAllDevices(string $id)
    {
        $user = User::findOrFail($id);
        Gate::authorize('update', $user);

        $user->increment('session_version');

        // If the admin is invalidating their own sessions, update the current
        // session so they are not immediately logged out themselves.
        if ((string) $user->id === (string) auth()->id()) {
            session()->put('session_version', $user->session_version);
        }

        ActivityLogger::log('logout_all_devices', "Logged out user from all devices: {$user->name}", $user);

        return redirect()->back()->with('success', 'User logged out from all devices.');
    }
}
