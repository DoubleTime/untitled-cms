<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\EmailLog;
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
            'users', 'roles', 'role_user', 'settings', 'vault_files',
            'vault_folders', 'vault_folder_permissions', 'activity_logs',
            'vault_audit_logs', 'email_logs', 'suppressed_emails',
            'customers', 'machine_brands', 'machine_models', 'scripts',
            'script_images', 'ai_models', 'revisions', 'unysis_boxes', 'downloads',
        ]);
    }

    #[DataProvider('tableProvider')]
    public function test_table_exists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
    }

    public function test_primary_keys_are_ulids(): void
    {
        $customer = Customer::factory()->create();

        $this->assertIsString($customer->getKey());
        $this->assertSame(26, strlen($customer->getKey()));
        $this->assertFalse($customer->getIncrementing());
    }

    public function test_role_permissions_round_trip_as_an_array(): void
    {
        $role = Role::factory()->create([
            'permissions' => ['scripts.edit', 'media.upload'],
        ]);

        $fresh = Role::find($role->getKey());

        $this->assertIsArray($fresh->permissions);
        $this->assertTrue($fresh->hasPermission('scripts.edit'));
        $this->assertFalse($fresh->hasPermission('scripts.delete'));
    }

    public function test_email_log_factory_persists_with_real_columns(): void
    {
        $log = EmailLog::factory()->create();

        $this->assertDatabaseHas('email_logs', [
            'id' => $log->getKey(),
            'recipient' => $log->recipient,
        ]);
    }
}
