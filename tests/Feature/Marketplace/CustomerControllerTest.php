<?php

namespace Tests\Feature\Marketplace;

use App\Models\Customer;
use App\Models\Role;
use App\Models\UnysisBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithPermissions([
            'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
        ]);
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

    public function test_index_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions([]))
            ->get('/admin/marketplace/customers')
            ->assertForbidden();
    }

    public function test_index_lists_customers(): void
    {
        Customer::factory()->create(['company' => 'Inari']);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/customers')
            ->assertOk();
    }

    public function test_a_customer_can_be_created(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/marketplace/customers', [
                'code' => 'inari-123',
                'company' => 'Inari',
                'contact_name' => 'Aina',
                'contact_email' => 'aina@example.com',
                'contact_phone' => '+60 12 345 6789',
                'notes' => 'Pilot site',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.marketplace.customers.index'));

        $this->assertDatabaseHas('customers', ['company' => 'Inari', 'code' => 'INARI-123']);
    }

    public function test_code_and_company_are_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/marketplace/customers', [])
            ->assertSessionHasErrors(['code', 'company']);
    }

    public function test_the_code_must_be_unique(): void
    {
        Customer::factory()->create(['code' => 'INARI-123']);

        $this->actingAs($this->admin)
            ->post('/admin/marketplace/customers', ['code' => 'inari-123', 'company' => 'Inari'])
            ->assertSessionHasErrors('code');
    }

    public function test_updating_keeps_its_own_code(): void
    {
        $customer = Customer::factory()->create(['code' => 'INARI-123']);

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/customers/{$customer->id}", [
                'code' => 'INARI-123',
                'company' => 'Inari',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_creating_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['customers.view']))
            ->post('/admin/marketplace/customers', ['code' => 'INARI-123', 'company' => 'Inari'])
            ->assertForbidden();

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_show_renders_the_customer_and_its_customer_users(): void
    {
        $customer = Customer::factory()->create();
        User::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($this->admin)
            ->get("/admin/marketplace/customers/{$customer->id}")
            ->assertOk();
    }

    public function test_a_customer_can_be_updated(): void
    {
        $customer = Customer::factory()->create(['company' => 'Inari']);

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/customers/{$customer->id}", [
                'code' => 'inari-456',
                'company' => 'Inari',
                'is_active' => false,
            ])
            ->assertRedirect(route('admin.marketplace.customers.index'));

        $customer->refresh();
        $this->assertSame('INARI-456', $customer->code);
        $this->assertFalse($customer->is_active);
    }

    public function test_a_customer_can_be_deleted(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/customers/{$customer->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_a_customer_with_customer_users_is_not_deleted(): void
    {
        $customer = Customer::factory()->create();
        User::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/customers/{$customer->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_a_customer_with_unysis_boxes_is_not_deleted(): void
    {
        $customer = Customer::factory()->create();
        UnysisBox::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/customers/{$customer->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_deleting_is_denied_without_the_permission(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($this->userWithPermissions(['customers.view']))
            ->delete("/admin/marketplace/customers/{$customer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }
}
