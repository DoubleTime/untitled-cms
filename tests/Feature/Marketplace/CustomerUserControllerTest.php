<?php

namespace Tests\Feature\Marketplace;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerUserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithPermissions([
            'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
        ]);
        $this->customer = Customer::factory()->create(['company' => 'Inari']);
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

    public function test_creating_a_customer_user_is_denied_without_customers_edit(): void
    {
        $this->actingAs($this->userWithPermissions(['customers.view']))
            ->post("/admin/marketplace/customers/{$this->customer->id}/users", [
                'name' => 'Aina',
                'email' => 'aina@example.com',
                'password' => 'Str0ng-Password!',
                'password_confirmation' => 'Str0ng-Password!',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'aina@example.com']);
    }

    public function test_a_customer_user_gets_only_the_customer_role_and_the_customer_id(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/marketplace/customers/{$this->customer->id}/users", [
                'name' => 'Aina',
                'email' => 'aina@example.com',
                'password' => 'Str0ng-Password!',
                'password_confirmation' => 'Str0ng-Password!',
            ])
            ->assertRedirect(route('admin.marketplace.customers.show', $this->customer->id));

        $user = User::where('email', 'aina@example.com')->firstOrFail();

        $this->assertSame($this->customer->id, $user->customer_id);
        $this->assertTrue($user->isCustomerUser());
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(['customer'], $user->roles()->pluck('slug')->all());
        $this->assertFalse($user->canAccessBackend());
    }

    public function test_omitting_the_password_sends_an_invite(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/customers/{$this->customer->id}/users", [
                'name' => 'Aina',
                'email' => 'aina@example.com',
            ])
            ->assertSessionHas('success');

        $user = User::where('email', 'aina@example.com')->firstOrFail();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_supplying_a_password_does_not_send_an_invite(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/customers/{$this->customer->id}/users", [
                'name' => 'Aina',
                'email' => 'aina@example.com',
                'password' => 'Str0ng-Password!',
                'password_confirmation' => 'Str0ng-Password!',
            ])
            ->assertSessionHas('success');

        Notification::assertNothingSent();
    }

    public function test_the_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'aina@example.com']);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/customers/{$this->customer->id}/users", [
                'name' => 'Aina',
                'email' => 'aina@example.com',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_deactivating_a_customer_user_deletes_their_tokens(): void
    {
        $user = User::factory()->create([
            'customer_id' => $this->customer->id,
            'is_active' => true,
        ]);
        $user->createToken('box-uuid-1');
        $user->createToken('box-uuid-2');

        $this->assertSame(2, $user->tokens()->count());

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/customers/{$this->customer->id}/users/{$user->id}/toggle-active")
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reactivating_a_customer_user_restores_the_flag(): void
    {
        $user = User::factory()->create([
            'customer_id' => $this->customer->id,
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/customers/{$this->customer->id}/users/{$user->id}/toggle-active")
            ->assertSessionHas('success');

        $this->assertTrue($user->refresh()->is_active);
    }

    public function test_revoking_all_sessions_deletes_every_token(): void
    {
        $user = User::factory()->create(['customer_id' => $this->customer->id]);
        $user->createToken('box-uuid-1');
        $user->createToken('box-uuid-2');
        $user->createToken('box-uuid-3');

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/customers/{$this->customer->id}/users/{$user->id}/revoke-tokens")
            ->assertSessionHas('success');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue($user->refresh()->is_active);
    }

    public function test_a_password_reset_can_be_sent(): void
    {
        Notification::fake();

        $user = User::factory()->create(['customer_id' => $this->customer->id]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/customers/{$this->customer->id}/users/{$user->id}/send-password-reset")
            ->assertSessionHas('success');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_a_user_belonging_to_another_customer_is_not_found(): void
    {
        $other = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $other->id]);

        $this->actingAs($this->admin)
            ->post("/admin/marketplace/customers/{$this->customer->id}/users/{$user->id}/revoke-tokens")
            ->assertNotFound();
    }

    public function test_customer_user_actions_are_denied_without_customers_edit(): void
    {
        $user = User::factory()->create(['customer_id' => $this->customer->id]);

        $this->actingAs($this->userWithPermissions(['customers.view']))
            ->post("/admin/marketplace/customers/{$this->customer->id}/users/{$user->id}/revoke-tokens")
            ->assertForbidden();
    }
}
