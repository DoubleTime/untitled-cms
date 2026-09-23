<?php

namespace Tests\Feature\Marketplace;

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
 * The Usage report — per-Customer and per-entry aggregates over a date range.
 *
 * Two Customers with a box each, so the grouped SQL has to actually attribute
 * rows rather than lump them together.
 */
class UsageReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MachineModel $machineModel;

    private Customer $inari;

    private Customer $carsem;

    private UnysisBox $inariBox;

    private UnysisBox $carsemBox;

    /** Revision numbers are unique per entry, so each new one gets the next number. */
    private int $revisionNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithPermissions(['downloads.view']);

        $brand = MachineBrand::factory()->create(['name' => 'ASM']);
        $this->machineModel = MachineModel::factory()->create([
            'machine_brand_id' => $brand->id,
            'name' => 'Eagle 60',
        ]);

        $this->inari = Customer::factory()->create(['code' => 'INARI-1', 'company' => 'Inari']);
        $this->carsem = Customer::factory()->create(['code' => 'CARSEM-1', 'company' => 'Carsem']);

        $this->inariBox = UnysisBox::factory()->create([
            'customer_id' => $this->inari->id,
            'status' => UnysisBox::STATUS_ACTIVE,
        ]);
        $this->carsemBox = UnysisBox::factory()->create([
            'customer_id' => $this->carsem->id,
            'status' => UnysisBox::STATUS_ACTIVE,
        ]);
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

    private function aiModel(string $name): AiModel
    {
        return AiModel::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => $name,
        ]);
    }

    private function releasedRevision(Script|AiModel $entry, int $number): Revision
    {
        return Revision::factory()->create([
            'revisable_type' => $entry->getMorphClass(),
            'revisable_id' => $entry->getKey(),
            'number' => $number,
            'status' => Revision::STATUS_RELEASED,
        ]);
    }

    private function download(Script|AiModel $entry, UnysisBox $box, array $attributes = []): Download
    {
        return Download::factory()->create(array_merge([
            'revision_id' => $this->releasedRevision($entry, ++$this->revisionNumber)->id,
            'revisable_type' => $entry->getMorphClass(),
            'revisable_id' => $entry->getKey(),
            'unysis_box_id' => $box->id,
            'source' => Download::SOURCE_API,
        ], $attributes));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function usageProps(string $key, string $query = ''): array
    {
        $data = [];

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/reports/usage'.$query)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$data, $key) {
                $page->component('Marketplace/Reports/Usage');
                $data = $page->toArray()['props'][$key];
            });

        return json_decode(json_encode($data), true);
    }

    public function test_the_report_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['scripts.view']))
            ->get('/admin/marketplace/reports/usage')
            ->assertForbidden();
    }

    public function test_per_customer_aggregates_attribute_downloads_to_the_right_customer(): void
    {
        $alpha = $this->script('Alpha');
        $beta = $this->script('Beta');

        // Inari: two downloads of two different entries, from one box.
        $this->download($alpha, $this->inariBox);
        $this->download($beta, $this->inariBox);

        // Carsem: one download.
        $this->download($alpha, $this->carsemBox);

        $rows = collect($this->usageProps('customers'))->keyBy('customer_code');

        $this->assertSame(2, $rows['INARI-1']['downloads']);
        $this->assertSame(2, $rows['INARI-1']['distinct_entries']);
        $this->assertSame(1, $rows['INARI-1']['boxes_downloaded']);
        $this->assertSame(1, $rows['INARI-1']['active_boxes']);
        $this->assertNotNull($rows['INARI-1']['last_download_at']);

        $this->assertSame(1, $rows['CARSEM-1']['downloads']);
        $this->assertSame(1, $rows['CARSEM-1']['distinct_entries']);
    }

    public function test_the_date_range_excludes_older_downloads(): void
    {
        $alpha = $this->script('Alpha');

        $this->download($alpha, $this->inariBox, ['created_at' => now()->subDays(2)]);
        $this->download($alpha, $this->inariBox, ['created_at' => now()->subDays(200)]);

        $lastSeven = collect($this->usageProps('customers', '?range=7'))->keyBy('customer_code');
        $this->assertSame(1, $lastSeven['INARI-1']['downloads']);

        $allTime = collect($this->usageProps('customers', '?range=all'))->keyBy('customer_code');
        $this->assertSame(2, $allTime['INARI-1']['downloads']);
    }

    public function test_the_entries_section_counts_customers_boxes_and_the_latest_release(): void
    {
        $alpha = $this->script('Alpha');

        $this->download($alpha, $this->inariBox);
        $this->download($alpha, $this->carsemBox);
        $this->releasedRevision($alpha, 7);
        $this->revisionNumber = 7;

        $rows = collect($this->usageProps('entries'))->keyBy('entry_name');

        $this->assertSame('script', $rows['Alpha']['entry_type']);
        $this->assertSame('Eagle 60', $rows['Alpha']['machine_model']);
        $this->assertSame('ASM', $rows['Alpha']['machine_brand']);
        $this->assertSame(2, $rows['Alpha']['downloads']);
        $this->assertSame(2, $rows['Alpha']['distinct_customers']);
        $this->assertSame(2, $rows['Alpha']['distinct_boxes']);
        $this->assertSame(7, $rows['Alpha']['latest_released_revision']);
    }

    public function test_the_entry_type_filter_narrows_the_entries_section(): void
    {
        $this->download($this->script('Alpha'), $this->inariBox);
        $this->download($this->aiModel('Defect net'), $this->inariBox);

        $all = $this->usageProps('entries');
        $this->assertCount(2, $all);

        $scriptsOnly = $this->usageProps('entries', '?entry_type=script');
        $this->assertCount(1, $scriptsOnly);
        $this->assertSame('Alpha', $scriptsOnly[0]['entry_name']);
    }

    public function test_both_sections_export_as_csv(): void
    {
        $this->download($this->script('Alpha'), $this->inariBox);

        $customers = $this->actingAs($this->admin)
            ->get('/admin/marketplace/reports/usage/export?section=customers&range=all');
        $customers->assertOk();

        $customerRows = $this->csv($customers->streamedContent());
        $this->assertSame([
            'customer_code', 'customer_company', 'active_boxes', 'boxes_downloaded',
            'downloads', 'distinct_entries', 'last_download_at',
        ], $customerRows[0]);
        $this->assertNotEmpty(array_filter($customerRows, fn ($row) => ($row[0] ?? null) === 'INARI-1'));

        $entries = $this->actingAs($this->admin)
            ->get('/admin/marketplace/reports/usage/export?section=entries&range=all');
        $entries->assertOk();

        $entryRows = $this->csv($entries->streamedContent());
        $this->assertSame([
            'entry_type', 'entry_name', 'machine_model', 'machine_brand', 'downloads',
            'distinct_customers', 'distinct_boxes', 'latest_released_revision', 'last_download_at',
        ], $entryRows[0]);
        $this->assertSame('Alpha', $entryRows[1][1]);
    }

    public function test_the_export_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['scripts.view']))
            ->get('/admin/marketplace/reports/usage/export?section=customers')
            ->assertForbidden();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function csv(string $body): array
    {
        return array_map('str_getcsv', array_filter(explode("\n", str_replace("\r\n", "\n", trim($body)))));
    }
}
