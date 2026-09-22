<?php

namespace Tests\Feature\Marketplace;

use App\Models\Customer;
use App\Models\Download;
use App\Models\MachineModel;
use App\Models\Revision;
use App\Models\Role;
use App\Models\Script;
use App\Models\UnysisBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ScriptControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected MachineModel $machineModel;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('marketplace');

        $this->admin = $this->userWithPermissions([
            'scripts.view', 'scripts.create', 'scripts.edit', 'scripts.delete',
            'scripts.upload', 'scripts.release', 'scripts.hard_delete',
        ]);

        $this->machineModel = MachineModel::factory()->create();
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

    protected function zipFile(string $name = 'bundle.zip'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "PK\x03\x04".str_repeat("\0", 64));
    }

    public function test_index_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions([]))
            ->get('/admin/marketplace/scripts')
            ->assertForbidden();
    }

    public function test_index_lists_scripts(): void
    {
        Script::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/scripts')
            ->assertOk();
    }

    public function test_a_script_can_be_created(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin)
            ->post('/admin/marketplace/scripts', [
                'machine_model_id' => $this->machineModel->id,
                'customer_id' => $customer->id,
                'name' => 'Tray Loader',
                'description' => 'Loads trays.',
            ])->assertRedirect();

        $this->assertDatabaseHas('scripts', [
            'name' => 'Tray Loader',
            'slug' => 'tray-loader',
            'machine_model_id' => $this->machineModel->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_creating_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['scripts.view']))
            ->post('/admin/marketplace/scripts', [
                'machine_model_id' => $this->machineModel->id,
                'name' => 'Tray Loader',
            ])->assertForbidden();

        $this->assertDatabaseCount('scripts', 0);
    }

    public function test_the_name_must_be_unique_per_machine_model(): void
    {
        Script::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => 'Tray Loader',
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/marketplace/scripts', [
                'machine_model_id' => $this->machineModel->id,
                'name' => 'Tray Loader',
            ])->assertSessionHasErrors('name');
    }

    public function test_the_same_name_is_allowed_on_another_machine_model(): void
    {
        Script::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => 'Tray Loader',
        ]);

        $other = MachineModel::factory()->create();

        $this->actingAs($this->admin)
            ->post('/admin/marketplace/scripts', [
                'machine_model_id' => $other->id,
                'name' => 'Tray Loader',
            ])->assertRedirect();

        $this->assertDatabaseCount('scripts', 2);
    }

    public function test_show_renders_the_script(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->get("/admin/marketplace/scripts/{$script->id}")
            ->assertOk();
    }

    public function test_a_script_can_be_updated(): void
    {
        $script = Script::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => 'Tray Loader',
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/scripts/{$script->id}", [
                'machine_model_id' => $this->machineModel->id,
                'name' => 'Tray Unloader',
                'description' => 'Updated.',
            ])->assertRedirect();

        $this->assertSame('Tray Unloader', $script->fresh()->name);
    }

    public function test_soft_delete_restore_and_force_delete(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/scripts/{$script->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('scripts', ['id' => $script->id]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/scripts/{$script->id}/restore")
            ->assertRedirect();

        $this->assertNull($script->fresh()->deleted_at);

        $this->actingAs($this->admin)->delete("/admin/marketplace/scripts/{$script->id}");
        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/scripts/{$script->id}/force")
            ->assertRedirect();

        $this->assertDatabaseCount('scripts', 0);
    }

    public function test_force_delete_is_denied_without_the_hard_delete_permission(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $script->delete();

        $this->actingAs($this->userWithPermissions(['scripts.view', 'scripts.delete']))
            ->delete("/admin/marketplace/scripts/{$script->id}/force")
            ->assertForbidden();

        $this->assertSoftDeleted('scripts', ['id' => $script->id]);
    }

    public function test_force_delete_removes_the_revision_files(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)->post("/admin/marketplace/scripts/{$script->id}/revisions", [
            'file' => $this->zipFile(),
            'change_note' => 'First',
        ]);

        $revision = Revision::first();
        Storage::disk('marketplace')->assertExists($revision->disk_path);

        $script->delete();

        $this->actingAs($this->admin)->delete("/admin/marketplace/scripts/{$script->id}/force");

        Storage::disk('marketplace')->assertMissing($revision->disk_path);
        $this->assertDatabaseCount('revisions', 0);
    }

    public function test_a_revision_can_be_uploaded(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/scripts/{$script->id}/revisions", [
                'file' => $this->zipFile(),
                'change_note' => 'First cut',
            ])->assertRedirect();

        $this->assertDatabaseHas('revisions', [
            'revisable_type' => 'script',
            'revisable_id' => $script->id,
            'number' => 1,
            'status' => Revision::STATUS_DRAFT,
            'change_note' => 'First cut',
        ]);
    }

    public function test_uploading_is_denied_without_the_upload_permission(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->userWithPermissions(['scripts.view']))
            ->post("/admin/marketplace/scripts/{$script->id}/revisions", [
                'file' => $this->zipFile(),
                'change_note' => 'Nope',
            ])->assertForbidden();

        $this->assertDatabaseCount('revisions', 0);
    }

    public function test_a_change_note_is_required(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/scripts/{$script->id}/revisions", [
                'file' => $this->zipFile(),
            ])->assertSessionHasErrors('change_note');
    }

    public function test_a_revision_can_be_released_and_deprecated(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $revision = Revision::factory()->create([
            'revisable_type' => 'script',
            'revisable_id' => $script->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/scripts/{$script->id}/revisions/{$revision->id}/release")
            ->assertRedirect();

        $this->assertSame(Revision::STATUS_RELEASED, $revision->fresh()->status);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/scripts/{$script->id}/revisions/{$revision->id}/deprecate")
            ->assertRedirect();

        $this->assertSame(Revision::STATUS_DEPRECATED, $revision->fresh()->status);
    }

    public function test_releasing_a_released_revision_flashes_an_error(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $revision = Revision::factory()->released()->create([
            'revisable_type' => 'script',
            'revisable_id' => $script->id,
        ]);

        $this->actingAs($this->admin)
            ->from("/admin/marketplace/scripts/{$script->id}")
            ->post("/admin/marketplace/scripts/{$script->id}/revisions/{$revision->id}/release")
            ->assertSessionHas('error');
    }

    public function test_releasing_is_denied_without_the_release_permission(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $revision = Revision::factory()->create([
            'revisable_type' => 'script',
            'revisable_id' => $script->id,
        ]);

        $this->actingAs($this->userWithPermissions(['scripts.view']))
            ->post("/admin/marketplace/scripts/{$script->id}/revisions/{$revision->id}/release")
            ->assertForbidden();
    }

    public function test_downloading_records_a_web_download_and_returns_the_checksum_header(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)->post("/admin/marketplace/scripts/{$script->id}/revisions", [
            'file' => $this->zipFile(),
            'change_note' => 'First',
        ]);

        $revision = Revision::first();

        $response = $this->actingAs($this->admin)
            ->get("/admin/marketplace/scripts/{$script->id}/revisions/{$revision->id}/download");

        $response->assertOk();
        $response->assertHeader('X-Checksum-SHA256', $revision->sha256);
        $response->assertHeader('X-Revision-Number', '1');

        $this->assertDatabaseHas('downloads', [
            'revision_id' => $revision->id,
            'revisable_type' => 'script',
            'revisable_id' => $script->id,
            'user_id' => $this->admin->id,
            'source' => Download::SOURCE_WEB,
        ]);
    }

    public function test_a_revision_of_another_script_is_not_reachable(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $other = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $revision = Revision::factory()->create([
            'revisable_type' => 'script',
            'revisable_id' => $other->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/scripts/{$script->id}/revisions/{$revision->id}/release")
            ->assertNotFound();
    }

    public function test_hard_deleting_a_revision_removes_the_file_but_keeps_the_downloads(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)->post("/admin/marketplace/scripts/{$script->id}/revisions", [
            'file' => $this->zipFile(),
            'change_note' => 'First',
        ]);

        $revision = Revision::first();
        Download::factory()->create([
            'revision_id' => $revision->id,
            'revisable_type' => 'script',
            'revisable_id' => $script->id,
            'source' => Download::SOURCE_WEB,
        ]);

        $this->actingAs($this->admin)
            ->from("/admin/marketplace/scripts/{$script->id}")
            ->delete("/admin/marketplace/scripts/{$script->id}/revisions/{$revision->id}")
            ->assertSessionHas('error');

        Storage::disk('marketplace')->assertMissing($revision->disk_path);
        $this->assertDatabaseCount('revisions', 0);
        $this->assertDatabaseCount('downloads', 1);
    }

    public function test_the_show_page_carries_download_and_unique_box_counts(): void
    {
        $script = Script::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)->post("/admin/marketplace/scripts/{$script->id}/revisions", [
            'file' => $this->zipFile(),
            'change_note' => 'First',
        ]);

        $revision = Revision::first();
        $customer = Customer::factory()->create();
        $boxA = UnysisBox::factory()->create(['customer_id' => $customer->id]);
        $boxB = UnysisBox::factory()->create(['customer_id' => $customer->id]);

        // Three Downloads from two distinct UNYSIS Boxes: the totals must differ.
        foreach ([$boxA->id, $boxA->id, $boxB->id] as $boxId) {
            Download::factory()->create([
                'revision_id' => $revision->id,
                'revisable_type' => 'script',
                'revisable_id' => $script->id,
                'unysis_box_id' => $boxId,
            ]);
        }

        $this->actingAs($this->admin)
            ->get("/admin/marketplace/scripts/{$script->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('downloadStats.total', 3)
                ->where('downloadStats.unique_boxes', 2)
                ->where('revisions.0.downloads_count', 3)
                ->where('revisions.0.unique_boxes_count', 2)
                // The sub-select must not have replaced the Revision's own columns.
                ->where('revisions.0.number', 1)
                ->where('revisions.0.change_note', 'First')
            );
    }
}
