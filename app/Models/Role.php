<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;

class Role extends Model
{
    use HasFactory, HasUlidKey;

    protected $fillable = ['name', 'slug', 'description', 'permissions', 'is_active', 'backend_access'];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'backend_access' => 'boolean',
    ];

    // public function permissions()
    // {
    //    return $this->embedsMany(Permission::class);
    // }

    protected static function booted(): void
    {
        // Bust permission cache for all role members whenever permissions are saved,
        // regardless of which code path triggered the save (controller, seeder, etc.).
        static::saved(function (Role $role) {
            foreach ($role->users()->pluck('users.id') as $userId) {
                Cache::forget('user_permissions_'.$userId);
                Cache::forget('user_backend_access_'.$userId);
            }
        });
    }

    public function syncPermissions(array $permissions): void
    {
        $this->permissions = $permissions;
        $this->save(); // triggers the saved event above
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    // Check if role has specific permission
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? []);
    }

    // Get available permissions for the system.
    // Keep in sync with: all Policy classes, controller hasPermission() calls,
    // CheckMaintenanceMode, and the RoleSeeder.
    public static function availablePermissions(): array
    {
        return [
            // Pages
            'pages.view',
            'pages.create',
            'pages.edit',
            'pages.delete',
            'pages.publish',

            // Banners
            'banners.view',
            'banners.create',
            'banners.edit',
            'banners.delete',
            'banners.manage',

            // Media / Vault
            'media.view',
            'media.create',   // upload new files
            'media.edit',     // rename, move, update alt text
            'media.delete',

            // Users
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'users.manage',   // batch operations + role sync

            // Roles
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',
            'roles.manage',

            // Menus
            'menus.view',
            'menus.create',
            'menus.edit',
            'menus.delete',

            // AI Integrations
            'ai-integrations.view',
            'ai-integrations.edit',

            // Email Logs
            'email_logs.view',

            // System
            'manage-settings', // used in SettingController + CheckMaintenanceMode bypass
        ];
    }
}
