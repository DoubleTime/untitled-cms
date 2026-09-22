<?php

namespace Tests\Feature\Marketplace;

use App\Models\Customer;
use App\Models\Download;
use App\Models\FlowchartScript;
use App\Models\MachineModel;
use App\Models\Revision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FlowchartScriptControllerTest extends TestCase
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

    public function test_index_lists_flowchart_scripts(): void
    {
        FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/scripts')
            ->assertOk();
    }

    public function test_a_flowchart_script_can_be_created(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin)
            ->post('/admin/marketplace/scripts', [
                'machine_model_id' => $this->machineModel->id,
                'customer_id' => $customer->id,
                'name' => 'Tray Loader',
                'description' => 'Loads trays.',
            ])->assertRedirect();

        $this->assertDatabaseHas('flowchart_scripts', [
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

        $this->assertDatabaseCount('flowchart_scripts', 0);
    }

    public function test_the_name_must_be_unique_per_machine_model(): void
    {
        FlowchartScript::factory()->create([
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
        FlowchartScript::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => 'Tray Loader',
        ]);

        $other = MachineModel::factory()->create();

        $this->actingAs($this->admin)
            ->post('/admin/marketplace/scripts', [
                'machine_model_id' => $other->id,
                'name' => 'Tray Loader',
            ])->assertRedirect();

        $this->assertDatabaseCount('flowchart_scripts', 2);
    }

    public function test_show_renders_the_flowchart_script(): void
    {
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->get("/admin/marketplace/scripts/{$script->id}")
            ->assertOk();
    }

    public function test_a_flowchart_script_can_be_updated(): void
    {
        $script = FlowchartScript::factory()->create([
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
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/scripts/{$script->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('flowchart_scripts', ['id' => $script->id]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/scripts/{$script->id}/restore")
            ->assertRedirect();

        $this->assertNull($script->fresh()->deleted_at);

        $this->actingAs($this->admin)->delete("/admin/marketplace/scripts/{$script->id}");
        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/scripts/{$script->id}/force")
            ->assertRedirect();

        $this->assertDatabaseCount('flowchart_scripts', 0);
    }

    public function test_force_delete_is_denied_without_the_hard_delete_permission(): void
    {
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $script->delete();

        $this->actingAs($this->userWithPermissions(['scripts.view', 'scripts.delete']))
            ->delete("/admin/marketplace/scripts/{$script->id}/force")
            ->assertForbidden();

        $this->assertSoftDeleted('flowchart_scripts', ['id' => $script->id]);
    }

    public function test_force_delete_removes_the_revision_files(): void
    {
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);

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
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/scripts/{$script->id}/revisions", [
                'file' => $this->zipFile(),
                'change_note' => 'First cut',
            ])->assertRedirect();

        $this->assertDatabaseHas('revisions', [
            'revisable_type' => 'flowchart_script',
            'revisable_id' => $script->id,
            'number' => 1,
            'status' => Revision::STATUS_DRAFT,
            'change_note' => 'First cut',
        ]);
    }

    public function test_uploading_is_denied_without_the_upload_permission(): void
    {
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->userWithPermissions(['scripts.view']))
            ->post("/admin/marketplace/scripts/{$script->id}/revisions", [
                'file' => $this->zipFile(),
                'change_note' => 'Nope',
            ])->assertForbidden();

        $this->assertDatabaseCount('revisions', 0);
    }

    public function test_a_change_note_is_required(): void
    {
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/scripts/{$script->id}/revisions", [
                'file' => $this->zipFile(),
            ])->assertSessionHasErrors('change_note');
    }

    public function test_a_revision_can_be_released_and_deprecated(): void
    {
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $revision = Revision::factory()->create([
            'revisable_type' => 'flowchart_script',
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
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $revision = Revision::factory()->released()->create([
            'revisable_type' => 'flowchart_script',
            'revisable_id' => $script->id,
        ]);

        $this->actingAs($this->admin)
            ->from("/admin/marketplace/scripts/{$script->id}")
            ->post("/admin/marketplace/scripts/{$script->id}/revisions/{$revision->id}/release")
            ->assertSessionHas('error');
    }

    public function test_releasing_is_denied_without_the_release_permission(): void
    {
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $revision = Revision::factory()->create([
            'revisable_type' => 'flowchart_script',
            'revisable_id' => $script->id,
        ]);

        $this->actingAs($this->userWithPermissions(['scripts.view']))
            ->post("/admin/marketplace/scripts/{$script->id}/revisions/{$revision->id}/release")
            ->assertForbidden();
    }

    public function test_downloading_records_a_web_download_and_returns_the_checksum_header(): void
    {
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);

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
            'revisable_type' => 'flowchart_script',
            'revisable_id' => $script->id,
            'user_id' => $this->admin->id,
            'source' => Download::SOURCE_WEB,
        ]);
    }

    public function test_a_revision_of_another_script_is_not_reachable(): void
    {
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $other = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $revision = Revision::factory()->create([
            'revisable_type' => 'flowchart_script',
            'revisable_id' => $other->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/scripts/{$script->id}/revisions/{$revision->id}/release")
            ->assertNotFound();
    }

    public function test_hard_deleting_a_revision_removes_the_file_but_keeps_the_downloads(): void
    {
        $script = FlowchartScript::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)->post("/admin/marketplace/scripts/{$script->id}/revisions", [
            'file' => $this->zipFile(),
            'change_note' => 'First',
        ]);

        $revision = Revision::first();
        Download::factory()->create([
            'revision_id' => $revision->id,
            'revisable_type' => 'flowchart_script',
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
}
