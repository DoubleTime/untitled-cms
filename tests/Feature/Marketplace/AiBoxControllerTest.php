<?php

namespace Tests\Feature\Marketplace;

use App\Models\AiBox;
use App\Models\AiModel;
use App\Models\Customer;
use App\Models\Download;
use App\Models\MachineBrand;
use App\Models\MachineModel;
use App\Models\Revision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AiBoxControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithPermissions([
            'ai_boxes.view', 'ai_boxes.edit', 'ai_boxes.block',
        ]);

        $this->customer = Customer::factory()->create(['company' => 'Inari', 'is_active' => true]);
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

    protected function box(array $attributes = []): AiBox
    {
        return AiBox::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
        ], $attributes));
    }

    public function test_index_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions([]))
            ->get('/admin/marketplace/ai-boxes')
            ->assertForbidden();
    }

    public function test_index_lists_boxes_with_their_filter_options(): void
    {
        $brand = MachineBrand::factory()->create();
        $model = MachineModel::factory()->create(['machine_brand_id' => $brand->id]);
        $this->box(['name' => 'Line 3', 'machine_model_id' => $model->id]);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/ai-boxes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Marketplace/AiBoxes/Index')
                ->has('aiBoxes', 1)
                ->where('aiBoxes.0.name', 'Line 3')
                ->where('aiBoxes.0.customer.company', 'Inari')
                ->has('customers', 1)
                ->has('machineModels', 1)
                ->where('statuses', AiBox::STATUSES)
            );
    }

    public function test_show_is_denied_without_the_permission(): void
    {
        $box = $this->box();

        $this->actingAs($this->userWithPermissions([]))
            ->get("/admin/marketplace/ai-boxes/{$box->id}")
            ->assertForbidden();
    }

    public function test_show_renders_the_installed_and_download_tabs(): void
    {
        $box = $this->box();

        $this->actingAs($this->admin)
            ->get("/admin/marketplace/ai-boxes/{$box->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Marketplace/AiBoxes/Show')
                ->where('aiBox.id', $box->id)
                ->has('installed')
                ->has('downloads.data')
            );
    }

    public function test_a_box_label_can_be_updated(): void
    {
        $brand = MachineBrand::factory()->create();
        $model = MachineModel::factory()->create(['machine_brand_id' => $brand->id]);
        $box = $this->box(['name' => null, 'location' => null, 'status' => AiBox::STATUS_PENDING]);

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/ai-boxes/{$box->id}", [
                'name' => 'Line 3 inspection box',
                'location' => 'Penang — Plant 2',
                'machine_model_id' => $model->id,
            ])
            ->assertRedirect(route('admin.marketplace.ai-boxes.show', $box->id));

        $box->refresh();

        $this->assertSame('Line 3 inspection box', $box->name);
        $this->assertSame('Penang — Plant 2', $box->location);
        $this->assertSame($model->id, $box->machine_model_id);
        // Never changed through update — that is what block/unblock/activate are for.
        $this->assertSame(AiBox::STATUS_PENDING, $box->status);
    }

    public function test_update_cannot_change_the_status_or_the_uuid(): void
    {
        $box = $this->box(['status' => AiBox::STATUS_PENDING]);
        $uuid = $box->motherboard_uuid;

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/ai-boxes/{$box->id}", [
                'name' => 'Relabelled',
                'status' => AiBox::STATUS_ACTIVE,
                'motherboard_uuid' => 'something-else',
            ])
            ->assertRedirect();

        $box->refresh();

        $this->assertSame(AiBox::STATUS_PENDING, $box->status);
        $this->assertSame($uuid, $box->motherboard_uuid);
    }

    public function test_update_is_denied_without_the_edit_permission(): void
    {
        $box = $this->box(['name' => 'Untouched']);

        $this->actingAs($this->userWithPermissions(['ai_boxes.view']))
            ->put("/admin/marketplace/ai-boxes/{$box->id}", ['name' => 'Changed'])
            ->assertForbidden();

        $this->assertSame('Untouched', $box->fresh()->name);
    }

    public function test_an_unknown_machine_model_is_refused(): void
    {
        $box = $this->box();

        $this->actingAs($this->admin)
            ->put("/admin/marketplace/ai-boxes/{$box->id}", ['machine_model_id' => 'nope'])
            ->assertSessionHasErrors('machine_model_id');
    }

    public function test_blocking_deletes_every_token_for_that_box_and_cuts_the_api_off(): void
    {
        $customerUser = User::factory()->create([
            'customer_id' => $this->customer->id,
            'is_active' => true,
        ]);

        $token = $this->apiToken($customerUser, '4C4C4544-0037-5810-8043-B4C04F504433');

        $box = AiBox::query()->where('customer_id', $this->customer->id)->firstOrFail();
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // The live token works before the block.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->actingAs($this->admin)
            ->from("/admin/marketplace/ai-boxes/{$box->id}")
            ->post("/admin/marketplace/ai-boxes/{$box->id}/block")
            ->assertRedirect("/admin/marketplace/ai-boxes/{$box->id}")
            ->assertSessionHas('success');

        $this->assertSame(AiBox::STATUS_BLOCKED, $box->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The same token is now worthless: no token row, so no authentication.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_blocking_leaves_other_boxes_tokens_alone(): void
    {
        $customerUser = User::factory()->create([
            'customer_id' => $this->customer->id,
            'is_active' => true,
        ]);

        $this->apiToken($customerUser, '11111111-1111-1111-1111-111111111111');
        $this->apiToken($customerUser, '22222222-2222-2222-2222-222222222222');

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $first = AiBox::query()->where('motherboard_uuid', '11111111-1111-1111-1111-111111111111')->firstOrFail();

        $this->actingAs($this->admin)->post("/admin/marketplace/ai-boxes/{$first->id}/block");

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => '22222222-2222-2222-2222-222222222222']);
    }

    public function test_blocking_is_denied_without_the_block_permission(): void
    {
        $box = $this->box(['status' => AiBox::STATUS_ACTIVE]);

        $this->actingAs($this->userWithPermissions(['ai_boxes.view', 'ai_boxes.edit']))
            ->post("/admin/marketplace/ai-boxes/{$box->id}/block")
            ->assertForbidden();

        $this->assertSame(AiBox::STATUS_ACTIVE, $box->fresh()->status);
    }

    public function test_a_blocked_box_can_be_unblocked(): void
    {
        $box = $this->box(['status' => AiBox::STATUS_BLOCKED]);

        $this->actingAs($this->admin)
            ->from("/admin/marketplace/ai-boxes/{$box->id}")
            ->post("/admin/marketplace/ai-boxes/{$box->id}/unblock")
            ->assertSessionHas('success');

        $this->assertSame(AiBox::STATUS_ACTIVE, $box->fresh()->status);
    }

    public function test_a_pending_box_can_be_acknowledged(): void
    {
        $box = $this->box(['status' => AiBox::STATUS_PENDING]);

        $this->actingAs($this->admin)
            ->from("/admin/marketplace/ai-boxes/{$box->id}")
            ->post("/admin/marketplace/ai-boxes/{$box->id}/activate")
            ->assertSessionHas('success');

        $this->assertSame(AiBox::STATUS_ACTIVE, $box->fresh()->status);
    }

    public function test_activate_refuses_a_blocked_box(): void
    {
        $box = $this->box(['status' => AiBox::STATUS_BLOCKED]);

        $this->actingAs($this->admin)
            ->from("/admin/marketplace/ai-boxes/{$box->id}")
            ->post("/admin/marketplace/ai-boxes/{$box->id}/activate")
            ->assertSessionHas('error');

        $this->assertSame(AiBox::STATUS_BLOCKED, $box->fresh()->status);
    }

    public function test_activate_needs_the_edit_permission_not_the_block_one(): void
    {
        $box = $this->box(['status' => AiBox::STATUS_PENDING]);

        $this->actingAs($this->userWithPermissions(['ai_boxes.view', 'ai_boxes.block']))
            ->post("/admin/marketplace/ai-boxes/{$box->id}/activate")
            ->assertForbidden();
    }

    public function test_a_box_with_downloads_cannot_be_deleted(): void
    {
        $box = $this->box();

        Download::factory()->create([
            'ai_box_id' => $box->id,
            'revision_id' => Revision::factory()->create([
                'revisable_type' => 'ai_model',
                'revisable_id' => AiModel::factory(),
            ])->id,
        ]);

        $this->actingAs($this->admin)
            ->from("/admin/marketplace/ai-boxes/{$box->id}")
            ->delete("/admin/marketplace/ai-boxes/{$box->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('ai_boxes', ['id' => $box->id]);
    }

    public function test_a_box_without_downloads_can_be_deleted(): void
    {
        $box = $this->box();

        $this->actingAs($this->admin)
            ->delete("/admin/marketplace/ai-boxes/{$box->id}")
            ->assertRedirect(route('admin.marketplace.ai-boxes.index'));

        $this->assertDatabaseMissing('ai_boxes', ['id' => $box->id]);
    }

    public function test_deleting_is_denied_without_the_edit_permission(): void
    {
        $box = $this->box();

        $this->actingAs($this->userWithPermissions(['ai_boxes.view', 'ai_boxes.block']))
            ->delete("/admin/marketplace/ai-boxes/{$box->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('ai_boxes', ['id' => $box->id]);
    }

    /**
     * A real RPA-TOOL token, obtained the way RPA-TOOL obtains one — the token's
     * name is the motherboard UUID, which is exactly what block() looks up.
     */
    private function apiToken(User $user, string $uuid): string
    {
        $this->app['auth']->forgetGuards();

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
            'motherboard_uuid' => $uuid,
        ]);

        $response->assertOk();

        return $response->json('token');
    }
}
