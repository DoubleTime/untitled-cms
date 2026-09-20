<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VaultFolder;
use App\Models\VaultFolderPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VaultFolderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = Role::updateOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super Admin', 'backend_access' => true, 'is_active' => true,
                'permissions' => ['media.view', 'media.create', 'media.edit', 'media.delete']]
        );
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_cannot_restore_folder_when_active_sibling_has_same_name(): void
    {
        $user = $this->createAdminUser();

        $trashed = VaultFolder::create(['uuid' => Str::uuid()->toString(), 'name' => 'Reports', 'owner_id' => $user->id]);
        $trashed->delete();
        VaultFolder::create(['uuid' => Str::uuid()->toString(), 'name' => 'Reports', 'owner_id' => $user->id]);

        $this->actingAs($user)
            ->postJson(route('admin.vault.folders.restore', $trashed->id))
            ->assertStatus(422);

        $this->assertTrue(VaultFolder::onlyTrashed()->whereKey($trashed->id)->exists());
    }

    public function test_folder_force_destroy_requires_global_media_delete(): void
    {
        $user = User::factory()->create();
        $role = Role::updateOrCreate(
            ['slug' => 'folder-deleter'],
            ['name' => 'Folder Deleter', 'backend_access' => true, 'is_active' => true,
                'permissions' => ['media.view', 'media.edit']]
        );
        $user->roles()->attach($role->id);

        $folder = VaultFolder::create(['uuid' => Str::uuid()->toString(), 'name' => 'Restricted', 'owner_id' => $user->id]);
        VaultFolderPermission::create(['folder_id' => $folder->id, 'user_id' => $user->id, 'permission' => 'delete']);

        $this->actingAs($user)
            ->deleteJson(route('admin.vault.folders.force_destroy', $folder->id))
            ->assertForbidden();

        $this->assertTrue(VaultFolder::withTrashed()->whereKey($folder->id)->exists());
    }

    public function test_folder_list_all_includes_nested_folders(): void
    {
        $user = $this->createAdminUser();

        $parent = VaultFolder::create(['uuid' => Str::uuid()->toString(), 'name' => 'Parent', 'owner_id' => $user->id]);
        VaultFolder::create(['uuid' => Str::uuid()->toString(), 'name' => 'Nested', 'parent_id' => $parent->id, 'owner_id' => $user->id]);

        $this->actingAs($user)
            ->getJson(route('admin.vault.folders.list'))
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($user)
            ->getJson(route('admin.vault.folders.list', ['all' => 1]))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['name' => 'Nested']);
    }

    public function test_can_create_folder(): void
    {
        $user = $this->createAdminUser();

        $response = $this->actingAs($user)->postJson(route('admin.vault.folders.store'), [
            'name' => 'Finance',
        ]);

        $response->assertStatus(200)
            ->assertJson(['name' => 'Finance']);

        $this->assertDatabaseHas('vault_folders', ['name' => 'Finance']);
    }

    public function test_can_create_nested_folder(): void
    {
        $user = $this->createAdminUser();

        $parent = VaultFolder::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Parent',
            'owner_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson(route('admin.vault.folders.store'), [
            'name' => 'Child',
            'parent_id' => $parent->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['name' => 'Child', 'parent_id' => $parent->id]);
    }

    public function test_can_rename_folder(): void
    {
        $user = $this->createAdminUser();

        $folder = VaultFolder::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Old Name',
            'owner_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->patchJson(route('admin.vault.folders.rename', $folder->id), [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200)
            ->assertJson(['name' => 'New Name']);

        $this->assertEquals('New Name', $folder->fresh()->name);
    }

    public function test_can_delete_empty_folder(): void
    {
        $user = $this->createAdminUser();

        $folder = VaultFolder::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'To Delete',
            'owner_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->deleteJson(route('admin.vault.folders.destroy', $folder->id));

        $response->assertStatus(200);
        $this->assertSoftDeleted($folder);
    }

    public function test_cannot_create_folder_with_duplicate_name_under_same_parent(): void
    {
        $user = $this->createAdminUser();

        VaultFolder::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Duplicate Name',
            'parent_id' => null,
            'owner_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson(route('admin.vault.folders.store'), [
            'name' => 'Duplicate Name',
            'parent_id' => null,
        ]);

        $response->assertStatus(422)
            ->assertJson(['error' => 'A folder with this name already exists in this directory.']);
    }

    public function test_cannot_rename_folder_to_duplicate_name_under_same_parent(): void
    {
        $user = $this->createAdminUser();

        VaultFolder::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Duplicate Name',
            'parent_id' => null,
            'owner_id' => $user->id,
        ]);

        $folder = VaultFolder::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Unique Name',
            'parent_id' => null,
            'owner_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->patchJson(route('admin.vault.folders.rename', $folder->id), [
            'name' => 'Duplicate Name',
        ]);

        $response->assertStatus(422)
            ->assertJson(['error' => 'A folder with this name already exists in this directory.']);
    }

    public function test_cannot_move_folder_to_directory_with_colliding_name(): void
    {
        $user = $this->createAdminUser();

        $destination = VaultFolder::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Destination',
            'owner_id' => $user->id,
        ]);

        VaultFolder::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Collide',
            'parent_id' => $destination->id,
            'owner_id' => $user->id,
        ]);

        $folderToMove = VaultFolder::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Collide',
            'owner_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->patchJson(route('admin.vault.folders.move', $folderToMove->id), [
            'parent_id' => $destination->id,
        ]);

        $response->assertStatus(422)
            ->assertJson(['error' => 'A folder with the same name already exists in the destination directory.']);
    }
}
