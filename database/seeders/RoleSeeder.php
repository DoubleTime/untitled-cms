<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Admin: All permissions — always kept in sync with Role::availablePermissions()
        $adminRole = Role::updateOrCreate(
            ['slug' => 'admin'],
            ['name' => 'admin', 'backend_access' => true, 'is_active' => true]
        );
        $adminRole->syncPermissions(Role::availablePermissions());

        // Editor: Can manage content/banners, but not users/roles
        $editorRole = Role::updateOrCreate(
            ['slug' => 'editor'],
            ['name' => 'editor', 'backend_access' => true, 'is_active' => true]
        );
        $editorRole->syncPermissions([
            'pages.view', 'pages.create', 'pages.edit', 'pages.delete', 'pages.publish',
            'media.view', 'media.create', 'media.edit', 'media.delete',
            'banners.manage',
        ]);

        // Author: Can create/edit own content, no publishing or deleting others
        $authorRole = Role::updateOrCreate(
            ['slug' => 'author'],
            ['name' => 'author', 'backend_access' => true, 'is_active' => true]
        );
        $authorRole->syncPermissions([
            'pages.view', 'pages.create', 'pages.edit',
            'media.view', 'media.create',
        ]);

        // User: Self-registered users — read-only, no backend access
        $userRole = Role::updateOrCreate(
            ['slug' => 'user'],
            ['name' => 'user', 'backend_access' => false, 'is_active' => true]
        );
        $userRole->syncPermissions([
            'pages.view',
            'media.view',
        ]);

        // Customer: Customer Users signing in from RPA-TOOL — no permissions and no
        // backend access at all. They reach the catalogue only through the API.
        $customerRole = Role::updateOrCreate(
            ['slug' => 'customer'],
            ['name' => 'customer', 'backend_access' => false, 'is_active' => true]
        );
        $customerRole->syncPermissions([]);
    }
}
