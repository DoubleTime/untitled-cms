<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VaultFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class VaultUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('vault');
        Storage::fake('sandbox');
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

    public function test_admin_can_upload_file(): void
    {
        $user = $this->createAdminUser();
        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->actingAs($user)->postJson(route('admin.vault.upload'), [
            'files' => [$file],
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['uploaded' => [['uuid', 'original_name']]]);

        $this->assertDatabaseHas('vault_files', [
            'original_name' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'uploaded_by' => $user->id,
        ]);

        $uploadedFile = VaultFile::first();
        $this->assertTrue(
            Storage::disk('public')->exists($uploadedFile->storage_path),
            "File missing at: {$uploadedFile->storage_path}"
        );
    }

    public function test_double_extension_is_rejected(): void
    {
        $user = $this->createAdminUser();
        $file = UploadedFile::fake()->create('exploit.php.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($user)->postJson(route('admin.vault.upload'), [
            'files' => [$file],
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['error' => 'Potential malicious double extension detected in filename: exploit.php.jpg']);

        $this->assertDatabaseMissing('vault_files', ['original_name' => 'exploit.php.jpg']);
    }

    public function test_validates_mime_type_mismatch(): void
    {
        $user = $this->createAdminUser();
        $file = UploadedFile::fake()->createWithContent('malicious.jpg', '<?php echo "evil"; ?>');

        $response = $this->actingAs($user)->postJson(route('admin.vault.upload'), [
            'files' => [$file],
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['error' => 'Security violation: Image sanitization failed. The file is corrupt or contains invalid data.']);

        $this->assertDatabaseMissing('vault_files', ['original_name' => 'malicious.jpg']);
    }

    public function test_unauthorized_user_cannot_upload(): void
    {
        $user = User::factory()->create(); // No role, no backend access

        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->actingAs($user)->postJson(route('admin.vault.upload'), [
            'files' => [$file],
        ]);

        $response->assertStatus(403);
    }

    public function test_empty_trash_requires_force_delete_permission(): void
    {
        $viewer = User::factory()->create();
        $viewerRole = Role::updateOrCreate(
            ['slug' => 'media-viewer'],
            [
                'name' => 'Media Viewer',
                'backend_access' => true,
                'is_active' => true,
                'permissions' => ['media.view'],
            ]
        );
        $viewer->roles()->attach($viewerRole->id);

        $file = VaultFile::factory()->create([
            'uploaded_by' => $viewer->id,
            'original_name' => 'trashed.jpg',
        ]);
        $file->delete();

        $response = $this->actingAs($viewer)->deleteJson(route('admin.vault.trash.empty'));

        $response->assertOk()
            ->assertJsonPath('deleted_count', 0);

        $this->assertTrue(VaultFile::onlyTrashed()->where('uuid', $file->uuid)->exists());
    }

    public function test_empty_trash_purges_when_user_has_media_delete(): void
    {
        $admin = $this->createAdminUser();

        $file = VaultFile::factory()->create([
            'uploaded_by' => $admin->id,
            'original_name' => 'gone.jpg',
        ]);
        $file->delete();

        $response = $this->actingAs($admin)->deleteJson(route('admin.vault.trash.empty'));

        $response->assertOk()
            ->assertJsonPath('deleted_count', 1);

        $this->assertFalse(VaultFile::withTrashed()->where('uuid', $file->uuid)->exists());
    }

    public function test_vault_page_reports_max_upload_size_in_megabytes(): void
    {
        $user = $this->createAdminUser();
        $vaultLimitMb = intdiv((int) config('vault.max_upload_kb', 51200), 1024);

        $this->actingAs($user)->get(route('admin.vault.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Vault/Index')
                ->where('maxUploadSize', fn ($size) => is_int($size) && $size >= 1 && $size <= $vaultLimitMb));
    }
}
