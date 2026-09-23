<?php

namespace Tests\Feature\Marketplace;

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
use Tests\TestCase;

/**
 * The CSV export of the Download log. It shares DownloadQuery with the index, so
 * what matters here is that the permission holds, the header row is the agreed
 * one, a row carries the joined names, and a filter narrows the file.
 */
class DownloadExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MachineModel $machineModel;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithPermissions(['downloads.view']);

        $brand = MachineBrand::factory()->create(['name' => 'ASM']);
        $this->machineModel = MachineModel::factory()->create([
            'machine_brand_id' => $brand->id,
            'name' => 'Eagle 60',
        ]);

        $this->customer = Customer::factory()->create(['code' => 'INARI-1', 'company' => 'Inari']);
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

    private function scriptDownload(string $name, array $attributes = []): Download
    {
        $script = Script::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => $name,
        ]);

        $revision = Revision::factory()->create([
            'revisable_type' => 'script',
            'revisable_id' => $script->getKey(),
            'number' => 1,
            'status' => Revision::STATUS_RELEASED,
        ]);

        $box = UnysisBox::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Line 3 box',
        ]);

        return Download::factory()->create(array_merge([
            'revision_id' => $revision->id,
            'revisable_type' => 'script',
            'revisable_id' => $script->getKey(),
            'unysis_box_id' => $box->id,
            'source' => Download::SOURCE_API,
        ], $attributes));
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function csv(string $body): array
    {
        return array_map('str_getcsv', array_filter(explode("\n", str_replace("\r\n", "\n", trim($body)))));
    }

    public function test_the_export_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['scripts.view']))
            ->get('/admin/marketplace/downloads/export')
            ->assertForbidden();
    }

    public function test_the_export_writes_a_header_row_and_one_row_per_download(): void
    {
        $this->scriptDownload('Wire bond check');

        $response = $this->actingAs($this->admin)->get('/admin/marketplace/downloads/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'downloads-'.now()->format('Y-m-d').'.csv',
            $response->headers->get('content-disposition')
        );

        $rows = $this->csv($response->streamedContent());

        $this->assertSame([
            'downloaded_at', 'source', 'entry_type', 'entry_name',
            'machine_model', 'machine_brand', 'revision_number', 'revision_status',
            'customer_code', 'customer_company', 'unysis_box_uuid', 'unysis_box_name',
            'user_name', 'user_email', 'ip',
        ], $rows[0]);

        $this->assertCount(2, $rows);

        $row = array_combine($rows[0], $rows[1]);

        $this->assertSame('api', $row['source']);
        $this->assertSame('script', $row['entry_type']);
        $this->assertSame('Wire bond check', $row['entry_name']);
        $this->assertSame('Eagle 60', $row['machine_model']);
        $this->assertSame('ASM', $row['machine_brand']);
        $this->assertSame('1', $row['revision_number']);
        $this->assertSame('released', $row['revision_status']);
        $this->assertSame('INARI-1', $row['customer_code']);
        $this->assertSame('Line 3 box', $row['unysis_box_name']);
    }

    public function test_the_export_honours_the_current_filter(): void
    {
        $this->scriptDownload('Kept', ['source' => Download::SOURCE_API]);
        $this->scriptDownload('Dropped', ['source' => Download::SOURCE_WEB]);

        $response = $this->actingAs($this->admin)
            ->get('/admin/marketplace/downloads/export?source=api');

        $rows = $this->csv($response->streamedContent());

        // Header plus the one API row; the web row is filtered out.
        $this->assertCount(2, $rows);
        $this->assertSame('Kept', array_combine($rows[0], $rows[1])['entry_name']);
    }
}
