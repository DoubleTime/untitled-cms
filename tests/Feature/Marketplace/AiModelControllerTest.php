<?php

namespace Tests\Feature\Marketplace;

use App\Models\AiModel;
use App\Models\Download;
use App\Models\MachineModel;
use App\Models\Revision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiModelControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected MachineModel $machineModel;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('marketplace');

        $this->admin = $this->userWithPermissions([
            'ai_models.view', 'ai_models.create', 'ai_models.edit', 'ai_models.delete',
            'ai_models.upload', 'ai_models.release', 'ai_models.hard_delete',
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

    protected function h5File(string $name = 'weights.h5'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\x89HDF\r\n\x1a\n".str_repeat("\0", 64));
    }

    public function test_index_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions([]))
            ->get('/admin/marketplace/ai-models')
            ->assertForbidden();
    }

    public function test_index_lists_ai_models(): void
    {
        AiModel::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/ai-models')
            ->assertOk();
    }

    public function test_an_ai_model_can_be_created_with_its_optional_fields(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/marketplace/ai-models', [
                'machine_model_id' => $this->machineModel->id,
                'name' => 'Solder Defect',
                'description' => 'Detects solder defects.',
                'framework' => 'keras',
                'input_size' => '224x224',
                'labels' => "ok\nng",
                'notes' => 'Trained on 12k images.',
            ])->assertRedirect();

        $this->assertDatabaseHas('ai_models', [
            'name' => 'Solder Defect',
            'slug' => 'solder-defect',
            'framework' => 'keras',
            'input_size' => '224x224',
        ]);
    }

    public function test_creating_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['ai_models.view']))
            ->post('/admin/marketplace/ai-models', [
                'machine_model_id' => $this->machineModel->id,
                'name' => 'Solder Defect',
            ])->assertForbidden();

        $this->assertDatabaseCount('ai_models', 0);
    }

    public function test_the_name_must_be_unique_per_machine_model(): void
    {
        AiModel::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => 'Solder Defect',
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/marketplace/ai-models', [
                'machine_model_id' => $this->machineModel->id,
                'name' => 'Solder Defect',
            ])->assertSessionHasErrors('name');
    }

    public function test_show_renders_the_ai_model(): void
    {
        $aiModel = AiModel::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->get("/admin/marketplace/ai-models/{$aiModel->id}")
            ->assertOk();
    }

    public function test_an_ai_model_can_be_updated(): void
    {
        $aiModel = AiModel::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => 'Solder Defect',
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/ai-models/{$aiModel->id}", [
                'machine_model_id' => $this->machineModel->id,
                'name' => 'Solder Defect v2',
                'framework' => 'onnx',
            ])->assertRedirect();

        $aiModel->refresh();
        $this->assertSame('Solder Defect v2', $aiModel->name);
        $this->assertSame('onnx', $aiModel->framework);
    }

    public function test_soft_delete_restore_and_force_delete(): void
    {
        $aiModel = AiModel::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/ai-models/{$aiModel->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('ai_models', ['id' => $aiModel->id]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/ai-models/{$aiModel->id}/restore")
            ->assertRedirect();

        $this->assertNull($aiModel->fresh()->deleted_at);

        $this->actingAs($this->admin)->delete("/admin/marketplace/ai-models/{$aiModel->id}");
        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/ai-models/{$aiModel->id}/force")
            ->assertRedirect();

        $this->assertDatabaseCount('ai_models', 0);
    }

    public function test_force_delete_is_denied_without_the_hard_delete_permission(): void
    {
        $aiModel = AiModel::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $aiModel->delete();

        $this->actingAs($this->userWithPermissions(['ai_models.view', 'ai_models.delete']))
            ->delete("/admin/marketplace/ai-models/{$aiModel->id}/force")
            ->assertForbidden();
    }

    public function test_a_revision_can_be_uploaded(): void
    {
        $aiModel = AiModel::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/ai-models/{$aiModel->id}/revisions", [
                'file' => $this->h5File(),
                'change_note' => 'First weights',
            ])->assertRedirect();

        $this->assertDatabaseHas('revisions', [
            'revisable_type' => 'ai_model',
            'revisable_id' => $aiModel->id,
            'number' => 1,
            'status' => Revision::STATUS_DRAFT,
        ]);
    }

    public function test_a_zip_is_refused_for_an_ai_model(): void
    {
        $aiModel = AiModel::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $zip = UploadedFile::fake()->createWithContent('bundle.zip', "PK\x03\x04".str_repeat("\0", 64));

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/ai-models/{$aiModel->id}/revisions", [
                'file' => $zip,
                'change_note' => 'Wrong type',
            ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('revisions', 0);
    }

    public function test_uploading_is_denied_without_the_upload_permission(): void
    {
        $aiModel = AiModel::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->userWithPermissions(['ai_models.view']))
            ->post("/admin/marketplace/ai-models/{$aiModel->id}/revisions", [
                'file' => $this->h5File(),
                'change_note' => 'Nope',
            ])->assertForbidden();
    }

    public function test_a_revision_can_be_released_and_deprecated(): void
    {
        $aiModel = AiModel::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $revision = Revision::factory()->create([
            'revisable_type' => 'ai_model',
            'revisable_id' => $aiModel->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/ai-models/{$aiModel->id}/revisions/{$revision->id}/release")
            ->assertRedirect();

        $this->assertSame(Revision::STATUS_RELEASED, $revision->fresh()->status);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/ai-models/{$aiModel->id}/revisions/{$revision->id}/deprecate")
            ->assertRedirect();

        $this->assertSame(Revision::STATUS_DEPRECATED, $revision->fresh()->status);
    }

    public function test_downloading_records_a_web_download_and_returns_the_checksum_header(): void
    {
        $aiModel = AiModel::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)->post("/admin/marketplace/ai-models/{$aiModel->id}/revisions", [
            'file' => $this->h5File(),
            'change_note' => 'First',
        ]);

        $revision = Revision::first();

        $response = $this->actingAs($this->admin)
            ->get("/admin/marketplace/ai-models/{$aiModel->id}/revisions/{$revision->id}/download");

        $response->assertOk();
        $response->assertHeader('X-Checksum-SHA256', $revision->sha256);
        $response->assertHeader('X-Revision-Number', '1');

        $this->assertDatabaseHas('downloads', [
            'revision_id' => $revision->id,
            'revisable_type' => 'ai_model',
            'user_id' => $this->admin->id,
            'source' => Download::SOURCE_WEB,
        ]);
    }

    public function test_hard_deleting_a_revision_removes_the_file(): void
    {
        $aiModel = AiModel::factory()->create(['machine_model_id' => $this->machineModel->id]);

        $this->actingAs($this->admin)->post("/admin/marketplace/ai-models/{$aiModel->id}/revisions", [
            'file' => $this->h5File(),
            'change_note' => 'First',
        ]);

        $revision = Revision::first();

        $this->actingAs($this->admin)
            ->from("/admin/marketplace/ai-models/{$aiModel->id}")
            ->delete("/admin/marketplace/ai-models/{$aiModel->id}/revisions/{$revision->id}")
            ->assertSessionHas('success');

        Storage::disk('marketplace')->assertMissing($revision->disk_path);
        $this->assertDatabaseCount('revisions', 0);
    }
}
