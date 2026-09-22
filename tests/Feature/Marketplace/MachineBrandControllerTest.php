<?php

namespace Tests\Feature\Marketplace;

use App\Models\MachineBrand;
use App\Models\MachineModel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineBrandControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithPermissions([
            'machines.view', 'machines.create', 'machines.edit', 'machines.delete',
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
        $user = $this->userWithPermissions([]);

        $this->actingAs($user)->get('/admin/marketplace/machine-brands')->assertForbidden();
    }

    public function test_index_lists_machine_brands(): void
    {
        MachineBrand::factory()->create(['name' => 'Fanuc']);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/machine-brands')
            ->assertOk();
    }

    public function test_a_machine_brand_can_be_created_with_a_generated_slug(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/admin/marketplace/machine-brands', ['name' => 'Kawasaki Robotics']);

        $response->assertRedirect(route('admin.marketplace.machine-brands.index'));

        $brand = MachineBrand::firstOrFail();
        $this->assertSame('Kawasaki Robotics', $brand->name);
        $this->assertSame('kawasaki-robotics', $brand->slug);
    }

    public function test_creating_a_machine_brand_is_denied_without_the_permission(): void
    {
        $user = $this->userWithPermissions(['machines.view']);

        $this->actingAs($user)
            ->post('/admin/marketplace/machine-brands', ['name' => 'Fanuc'])
            ->assertForbidden();

        $this->assertDatabaseCount('machine_brands', 0);
    }

    public function test_the_name_must_be_unique(): void
    {
        MachineBrand::factory()->create(['name' => 'Fanuc']);

        $this->actingAs($this->admin)
            ->post('/admin/marketplace/machine-brands', ['name' => 'Fanuc'])
            ->assertSessionHasErrors('name');
    }

    public function test_a_machine_brand_can_be_updated(): void
    {
        $brand = MachineBrand::factory()->create(['name' => 'Fanuc', 'slug' => 'fanuc']);

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/machine-brands/{$brand->id}", ['name' => 'Fanuc America'])
            ->assertRedirect(route('admin.marketplace.machine-brands.index'));

        $brand->refresh();
        $this->assertSame('Fanuc America', $brand->name);
        $this->assertSame('fanuc-america', $brand->slug);
    }

    public function test_updating_allows_keeping_its_own_name(): void
    {
        $brand = MachineBrand::factory()->create(['name' => 'Fanuc', 'slug' => 'fanuc']);

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/machine-brands/{$brand->id}", ['name' => 'Fanuc'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_machine_brand_can_be_deleted(): void
    {
        $brand = MachineBrand::factory()->create();

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/machine-brands/{$brand->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseCount('machine_brands', 0);
    }

    public function test_a_machine_brand_with_machine_models_is_not_deleted(): void
    {
        $brand = MachineBrand::factory()->create();
        MachineModel::factory()->create(['machine_brand_id' => $brand->id]);

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/machine-brands/{$brand->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('machine_brands', ['id' => $brand->id]);
    }

    public function test_deleting_is_denied_without_the_permission(): void
    {
        $brand = MachineBrand::factory()->create();
        $user = $this->userWithPermissions(['machines.view']);

        $this->actingAs($user)
            ->delete("/admin/marketplace/machine-brands/{$brand->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('machine_brands', ['id' => $brand->id]);
    }
}
