<?php

namespace Tests\Feature\Marketplace;

use App\Models\AiModel;
use App\Models\MachineBrand;
use App\Models\MachineModel;
use App\Models\Role;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineModelControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected MachineBrand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithPermissions([
            'machines.view', 'machines.create', 'machines.edit', 'machines.delete',
        ]);
        $this->brand = MachineBrand::factory()->create(['name' => 'Fanuc', 'slug' => 'fanuc']);
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
            ->get('/admin/marketplace/machine-models')
            ->assertForbidden();
    }

    public function test_index_lists_machine_models(): void
    {
        MachineModel::factory()->create(['machine_brand_id' => $this->brand->id]);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/machine-models')
            ->assertOk();
    }

    public function test_a_machine_model_can_be_created(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/marketplace/machine-models', [
                'machine_brand_id' => $this->brand->id,
                'name' => 'R-2000iC',
                'description' => 'Six-axis arm',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.marketplace.machine-models.index'));

        $model = MachineModel::firstOrFail();
        $this->assertSame('R-2000iC', $model->name);
        $this->assertSame('r-2000ic', $model->slug);
        $this->assertSame($this->brand->id, $model->machine_brand_id);
    }

    public function test_creating_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['machines.view']))
            ->post('/admin/marketplace/machine-models', [
                'machine_brand_id' => $this->brand->id,
                'name' => 'R-2000iC',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('machine_models', 0);
    }

    public function test_the_name_must_be_unique_within_the_machine_brand(): void
    {
        MachineModel::factory()->create([
            'machine_brand_id' => $this->brand->id,
            'name' => 'R-2000iC',
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/marketplace/machine-models', [
                'machine_brand_id' => $this->brand->id,
                'name' => 'R-2000iC',
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_the_same_name_is_allowed_under_a_different_machine_brand(): void
    {
        MachineModel::factory()->create([
            'machine_brand_id' => $this->brand->id,
            'name' => 'R-2000iC',
        ]);

        $other = MachineBrand::factory()->create(['name' => 'Kawasaki', 'slug' => 'kawasaki']);

        $this->actingAs($this->admin)
            ->post('/admin/marketplace/machine-models', [
                'machine_brand_id' => $other->id,
                'name' => 'R-2000iC',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, MachineModel::count());
    }

    public function test_a_machine_model_can_be_updated(): void
    {
        $model = MachineModel::factory()->create([
            'machine_brand_id' => $this->brand->id,
            'name' => 'R-2000iC',
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/machine-models/{$model->id}", [
                'machine_brand_id' => $this->brand->id,
                'name' => 'R-2000iD',
                'is_active' => false,
            ])
            ->assertRedirect(route('admin.marketplace.machine-models.index'));

        $model->refresh();
        $this->assertSame('R-2000iD', $model->name);
        $this->assertSame('r-2000id', $model->slug);
        $this->assertFalse($model->is_active);
    }

    public function test_a_machine_model_can_be_deleted(): void
    {
        $model = MachineModel::factory()->create(['machine_brand_id' => $this->brand->id]);

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/machine-models/{$model->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseCount('machine_models', 0);
    }

    public function test_a_machine_model_with_scripts_is_not_deleted(): void
    {
        $model = MachineModel::factory()->create(['machine_brand_id' => $this->brand->id]);
        Script::factory()->create(['machine_model_id' => $model->id]);

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/machine-models/{$model->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('machine_models', ['id' => $model->id]);
    }

    public function test_a_machine_model_with_ai_models_is_not_deleted(): void
    {
        $model = MachineModel::factory()->create(['machine_brand_id' => $this->brand->id]);
        AiModel::factory()->create(['machine_model_id' => $model->id]);

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/machine-models/{$model->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('machine_models', ['id' => $model->id]);
    }
}
