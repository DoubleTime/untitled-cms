<?php

namespace Tests\Feature\Marketplace;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\Marketplace\CustomerUserGuard;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer Users belong to RPA-TOOL (docs/adr/0002) and must never hold a web session.
 */
class CustomerUserWebLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    protected function makeCustomerUser(): User
    {
        $customer = Customer::factory()->create();
        $role = Role::where('slug', 'customer')->firstOrFail();

        $user = User::factory()->create([
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
        $user->syncRoles([$role->id]);

        return $user;
    }

    public function test_a_customer_user_is_refused_the_web_login(): void
    {
        $user = $this->makeCustomerUser();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors([
            'email' => CustomerUserGuard::RPA_TOOL_ONLY_MESSAGE,
        ]);
    }

    public function test_a_customer_user_with_a_wrong_password_is_still_refused(): void
    {
        $user = $this->makeCustomerUser();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        $this->assertGuest();
    }

    public function test_a_user_holding_only_the_customer_role_is_refused(): void
    {
        // No customer_id, but the only role is `customer` and it has no backend access.
        $role = Role::where('slug', 'customer')->firstOrFail();
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role->id]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors([
            'email' => CustomerUserGuard::RPA_TOOL_ONLY_MESSAGE,
        ]);
    }

    public function test_a_customer_user_cannot_reach_the_admin_area(): void
    {
        $user = $this->makeCustomerUser();

        $this->actingAs($user)
            ->get('/admin/marketplace/customers')
            ->assertRedirect('/');
    }

    public function test_a_team_member_can_still_log_in(): void
    {
        $role = Role::factory()->create([
            'permissions' => ['customers.view'],
            'backend_access' => true,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_an_ordinary_non_customer_user_can_still_log_in(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }
}
