<?php

namespace Tests\Unit;

use App\Models\Page;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public static function tableProvider(): array
    {
        return array_map(fn ($t) => [$t], [
            'users', 'roles', 'role_user', 'settings', 'ai_hubs', 'pages',
            'banners', 'menus', 'redirects', 'chat_sessions', 'vault_files',
            'vault_folders', 'vault_folder_permissions', 'activity_logs',
            'vault_audit_logs', 'email_logs', 'suppressed_emails',
        ]);
    }

    #[DataProvider('tableProvider')]
    public function test_table_exists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
    }

    public function test_primary_keys_are_ulids(): void
    {
        $page = Page::factory()->create();

        $this->assertIsString($page->getKey());
        $this->assertSame(26, strlen($page->getKey()));
        $this->assertFalse($page->incrementing);
    }

    public function test_role_permissions_round_trip_as_an_array(): void
    {
        $role = Role::factory()->create([
            'permissions' => ['pages.edit', 'media.upload'],
        ]);

        $fresh = Role::find($role->getKey());

        $this->assertIsArray($fresh->permissions);
        $this->assertTrue($fresh->hasPermission('pages.edit'));
        $this->assertFalse($fresh->hasPermission('pages.delete'));
    }
}
