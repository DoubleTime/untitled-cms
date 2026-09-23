<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeders run under Model::unguarded(), so $fillable does not filter their
 * payloads — every key they write reaches the INSERT directly. That is how
 * `settings.description` shipped missing from the schema: it is absent from
 * Setting::$fillable, so deriving columns from $fillable could not see it,
 * and no test exercised the seeders.
 */
class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_seeders_run_against_the_schema(): void
    {
        $this->seed();

        $this->assertTrue(Role::query()->exists(), 'RoleSeeder produced no roles');
        $this->assertTrue(User::query()->exists(), 'AdminUserSeeder produced no users');
        $this->assertTrue(Setting::query()->exists(), 'SettingSeeder produced no settings');
    }

    public function test_seeded_setting_descriptions_persist(): void
    {
        $this->seed();

        $described = Setting::query()->whereNotNull('description')->first();

        $this->assertNotNull(
            $described,
            'No seeded setting kept its description — the column is missing or unwritable.'
        );
        $this->assertNotSame('', $described->description);
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        $this->seed();
        $before = Setting::query()->count();

        $this->seed();

        $this->assertSame(
            $before,
            Setting::query()->count(),
            'Re-seeding duplicated settings; seeders should use updateOrCreate keyed on `key`.'
        );
    }
}
