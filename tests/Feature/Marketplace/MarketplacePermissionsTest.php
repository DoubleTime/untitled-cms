<?php

namespace Tests\Feature\Marketplace;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplacePermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The canonical list is Role::availablePermissions(); nothing may hardcode a count.
     */
    public function test_marketplace_permissions_are_available(): void
    {
        $available = Role::availablePermissions();

        foreach ([
            'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
            'machines.view', 'machines.create', 'machines.edit', 'machines.delete',
            'scripts.view', 'scripts.create', 'scripts.edit', 'scripts.delete',
            'scripts.upload', 'scripts.release', 'scripts.hard_delete',
            'ai_models.view', 'ai_models.create', 'ai_models.edit', 'ai_models.delete',
            'ai_models.upload', 'ai_models.release', 'ai_models.hard_delete',
            'unysis_boxes.view', 'unysis_boxes.edit', 'unysis_boxes.block',
            'downloads.view',
        ] as $permission) {
            $this->assertContains($permission, $available);
        }

        $this->assertSame(
            array_values(array_unique($available)),
            $available,
            'Role::availablePermissions() contains duplicates'
        );
    }

    public function test_admin_role_is_seeded_with_every_permission(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = Role::where('slug', 'admin')->firstOrFail();

        // Derived from the source of truth — never a hardcoded count.
        $this->assertEqualsCanonicalizing(Role::availablePermissions(), $admin->permissions);
        $this->assertCount(count(Role::availablePermissions()), $admin->permissions);
    }

    public function test_customer_role_is_seeded_without_backend_access(): void
    {
        $this->seed(RoleSeeder::class);

        $customer = Role::where('slug', 'customer')->firstOrFail();

        $this->assertFalse($customer->backend_access);
        $this->assertTrue($customer->is_active);
        $this->assertSame([], $customer->permissions ?? []);
    }

    public function test_a_customer_user_cannot_access_the_backend(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::where('slug', 'customer')->firstOrFail();
        $user = User::factory()->create();
        $user->syncRoles([$role->id]);

        $this->assertFalse($user->canAccessBackend());
        $this->assertSame([], $user->getCachedPermissions());
    }

    public function test_is_customer_user_reflects_the_customer_link(): void
    {
        $teamMember = User::factory()->create();
        $this->assertFalse($teamMember->isCustomerUser());
        $this->assertNull($teamMember->customer);

        $customer = Customer::factory()->create();
        $customerUser = User::factory()->create(['customer_id' => $customer->id]);

        $this->assertTrue($customerUser->isCustomerUser());
        $this->assertTrue($customer->is($customerUser->customer));
        $this->assertTrue($customer->users()->whereKey($customerUser->id)->exists());
    }
}
