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

        // Editor: Manages the catalogue — Scripts, AI Models and their Revisions —
        // plus the Vault media they reference. No Customers, Users or Roles.
        $editorRole = Role::updateOrCreate(
            ['slug' => 'editor'],
            ['name' => 'editor', 'backend_access' => true, 'is_active' => true]
        );
        $editorRole->syncPermissions([
            'media.view', 'media.create', 'media.edit', 'media.delete',
            'machines.view',
            'customers.view',
            'scripts.view', 'scripts.create', 'scripts.edit', 'scripts.delete',
            'scripts.upload', 'scripts.release',
            'ai_models.view', 'ai_models.create', 'ai_models.edit', 'ai_models.delete',
            'ai_models.upload', 'ai_models.release',
            'unysis_boxes.view',
            'downloads.view',
        ]);

        // Author: Drafts catalogue entries and uploads Revisions, but cannot release
        // them or delete anything.
        $authorRole = Role::updateOrCreate(
            ['slug' => 'author'],
            ['name' => 'author', 'backend_access' => true, 'is_active' => true]
        );
        $authorRole->syncPermissions([
            'media.view', 'media.create',
            'machines.view',
            'customers.view',
            'scripts.view', 'scripts.create', 'scripts.edit', 'scripts.upload',
            'ai_models.view', 'ai_models.create', 'ai_models.edit', 'ai_models.upload',
        ]);

        // Viewer: Read-only on the catalogue, with backend access.
        $viewerRole = Role::updateOrCreate(
            ['slug' => 'viewer'],
            ['name' => 'viewer', 'backend_access' => true, 'is_active' => true]
        );
        $viewerRole->syncPermissions([
            'media.view',
            'machines.view',
            'customers.view',
            'scripts.view',
            'ai_models.view',
            'unysis_boxes.view',
            'downloads.view',
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
