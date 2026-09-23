<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Customer;
use App\Models\Download;
use App\Models\MachineBrand;
use App\Models\MachineModel;
use App\Models\Revision;
use App\Models\Role;
use App\Models\Script;
use App\Models\UnysisBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The Marketplace dashboard payload.
 *
 * Each panel is gated on the permission of the page it summarises, and a panel
 * the Team Member cannot see must be absent from the props, not merely hidden.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private MachineModel $machineModel;

    protected function setUp(): void
    {
        parent::setUp();

        $brand = MachineBrand::factory()->create();
        $this->machineModel = MachineModel::factory()->create(['machine_brand_id' => $brand->id]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
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

    private function script(string $name): Script
    {
        return Script::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => $name,
        ]);
    }

    private function release(Script|AiModel $entry, int $number = 1): Revision
    {
        return Revision::factory()->create([
            'revisable_type' => $entry->getMorphClass(),
            'revisable_id' => $entry->getKey(),
            'number' => $number,
            'status' => Revision::STATUS_RELEASED,
        ]);
    }

    public function test_the_cards_count_released_entries_customers_and_boxes(): void
    {
        $released = $this->script('Released');
        $this->release($released);
        $this->script('Draft only');

        $aiModel = AiModel::factory()->create(['machine_model_id' => $this->machineModel->id]);
        $this->release($aiModel);

        Customer::factory()->create(['is_active' => true]);
        Customer::factory()->create(['is_active' => false]);

        $customer = Customer::factory()->create(['is_active' => true]);
        UnysisBox::factory()->count(2)->create([
            'customer_id' => $customer->id,
            'status' => UnysisBox::STATUS_ACTIVE,
        ]);
        UnysisBox::factory()->create([
            'customer_id' => $customer->id,
            'status' => UnysisBox::STATUS_BLOCKED,
        ]);

        $user = $this->userWithPermissions([
            'scripts.view', 'ai_models.view', 'customers.view', 'unysis_boxes.view', 'downloads.view',
        ]);

        $this->actingAs($user)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('cards.scripts.released', 1)
                ->where('cards.scripts.total', 2)
                ->where('cards.aiModels.released', 1)
                ->where('cards.aiModels.total', 1)
                ->where('cards.customers.active', 2)
                ->where('cards.unysisBoxes.active', 2)
                ->where('cards.unysisBoxes.blocked', 1)
                ->where('cards.unysisBoxes.pending', 0)
            );
    }

    public function test_the_download_card_compares_the_last_seven_days_with_the_seven_before(): void
    {
        $script = $this->script('Counted');
        $revision = $this->release($script);

        $make = fn (string $when) => Download::factory()->create([
            'revision_id' => $revision->id,
            'revisable_type' => 'script',
            'revisable_id' => $script->getKey(),
            'created_at' => $when,
        ]);

        $make(now()->subDays(1));
        $make(now()->subDays(2));
        $make(now()->subDays(10));

        $this->actingAs($this->userWithPermissions(['downloads.view']))
            ->get('/admin/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.downloads.last_seven_days', 2)
                ->where('cards.downloads.previous_seven_days', 1)
                ->where('cards.downloads.delta', 1)
                // 30 days of buckets, gaps filled.
                ->has('downloadsPerDay', 30)
            );
    }

    public function test_panels_are_omitted_without_the_matching_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['users.view']))
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.scripts', null)
                ->where('cards.aiModels', null)
                ->where('cards.customers', null)
                ->where('cards.unysisBoxes', null)
                ->where('cards.downloads', null)
                ->where('downloadsPerDay', null)
                ->where('latestRevisions', null)
                ->where('recentBoxes', null)
            );
    }

    public function test_the_latest_revisions_and_recently_seen_boxes_lists_are_capped_at_ten(): void
    {
        $script = $this->script('Many revisions');

        foreach (range(1, 12) as $number) {
            $this->release($script, $number);
        }

        $customer = Customer::factory()->create();
        foreach (range(1, 12) as $index) {
            UnysisBox::factory()->create([
                'customer_id' => $customer->id,
                'last_seen_at' => now()->subMinutes($index),
            ]);
        }

        // A box that has never checked in is left out of the list entirely.
        UnysisBox::factory()->create([
            'customer_id' => $customer->id,
            'last_seen_at' => null,
        ]);

        $this->actingAs($this->userWithPermissions(['scripts.view', 'unysis_boxes.view']))
            ->get('/admin/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('latestRevisions', 10)
                ->where('latestRevisions.0.entry_name', 'Many revisions')
                ->has('recentBoxes', 10)
            );
    }
}
