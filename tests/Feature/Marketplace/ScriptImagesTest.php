<?php

namespace Tests\Feature\Marketplace;

use App\Models\Role;
use App\Models\Script;
use App\Models\ScriptImage;
use App\Models\User;
use App\Models\VaultFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Preview Images are ordinary public Vault pictures attached to a Script
 * Script in display order; the first is the cover.
 */
class ScriptImagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Script $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithPermissions(['scripts.view', 'scripts.edit']);
        $this->script = Script::factory()->create();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function userWithPermissions(array $permissions): User
    {
        $role = Role::factory()->create([
            'permissions' => $permissions,
            'backend_access' => true,
            'is_active' => true,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    protected function image(): VaultFile
    {
        return VaultFile::factory()->create([
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
        ]);
    }

    public function test_images_are_synced_in_the_given_order(): void
    {
        $first = $this->image();
        $second = $this->image();

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/scripts/{$this->script->id}/images", [
                'vault_file_ids' => [$first->id, $second->id],
            ])->assertRedirect();

        $images = $this->script->images()->get();

        $this->assertCount(2, $images);
        $this->assertSame($first->id, $images[0]->vault_file_id);
        $this->assertSame(0, $images[0]->sort_order);
        $this->assertSame($second->id, $images[1]->vault_file_id);
        $this->assertSame(1, $images[1]->sort_order);
    }

    public function test_syncing_replaces_the_previous_list_and_can_reorder(): void
    {
        $first = $this->image();
        $second = $this->image();

        ScriptImage::create([
            'script_id' => $this->script->id,
            'vault_file_id' => $first->id,
            'sort_order' => 0,
        ]);
        ScriptImage::create([
            'script_id' => $this->script->id,
            'vault_file_id' => $second->id,
            'sort_order' => 1,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/scripts/{$this->script->id}/images", [
                'vault_file_ids' => [$second->id, $first->id],
            ])->assertRedirect();

        $images = $this->script->images()->get();

        $this->assertCount(2, $images);
        $this->assertSame($second->id, $images[0]->vault_file_id);
        $this->assertSame($first->id, $images[1]->vault_file_id);
    }

    public function test_an_empty_list_clears_the_preview_images(): void
    {
        ScriptImage::create([
            'script_id' => $this->script->id,
            'vault_file_id' => $this->image()->id,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/scripts/{$this->script->id}/images", [
                'vault_file_ids' => [],
            ])->assertRedirect();

        $this->assertSame(0, $this->script->images()->count());
    }

    public function test_a_non_image_vault_file_is_rejected(): void
    {
        $document = VaultFile::factory()->create([
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/scripts/{$this->script->id}/images", [
                'vault_file_ids' => [$document->id],
            ])->assertSessionHasErrors('vault_file_ids');

        $this->assertSame(0, $this->script->images()->count());
    }

    public function test_an_unknown_vault_file_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put("/admin/marketplace/scripts/{$this->script->id}/images", [
                'vault_file_ids' => ['01JQZZZZZZZZZZZZZZZZZZZZZZ'],
            ])->assertSessionHasErrors('vault_file_ids.0');
    }

    public function test_syncing_is_denied_without_the_edit_permission(): void
    {
        $image = $this->image();

        $this->actingAs($this->userWithPermissions(['scripts.view']))
            ->put("/admin/marketplace/scripts/{$this->script->id}/images", [
                'vault_file_ids' => [$image->id],
            ])->assertForbidden();

        $this->assertSame(0, $this->script->images()->count());
    }

    public function test_the_show_page_renders_with_preview_images(): void
    {
        ScriptImage::create([
            'script_id' => $this->script->id,
            'vault_file_id' => $this->image()->id,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->admin)
            ->get("/admin/marketplace/scripts/{$this->script->id}")
            ->assertOk();
    }
}
