<?php

namespace Tests\Feature\Marketplace;

use App\Models\AiBox;
use App\Models\AiModel;
use App\Models\Customer;
use App\Models\FlowchartScript;
use App\Models\MachineBrand;
use App\Models\MachineModel;
use App\Models\Revision;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The marketplace migration has to produce the same schema on SQLite (tests) and
 * PostgreSQL (production), including the unique constraints the services will rely
 * on instead of application-level locking.
 */
class MarketplaceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_marketplace_tables_exist(): void
    {
        foreach ([
            'customers',
            'machine_brands',
            'machine_models',
            'flowchart_scripts',
            'flowchart_script_images',
            'ai_models',
            'revisions',
            'ai_boxes',
            'downloads',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue(Schema::hasColumn('users', 'customer_id'));
        $this->assertTrue(Schema::hasColumn('flowchart_scripts', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('ai_models', 'deleted_at'));
    }

    public function test_machine_brand_name_is_unique(): void
    {
        MachineBrand::factory()->create(['name' => 'Fanuc']);

        $this->expectException(QueryException::class);
        MachineBrand::factory()->create(['name' => 'Fanuc']);
    }

    public function test_machine_model_name_is_unique_per_brand(): void
    {
        $brand = MachineBrand::factory()->create();
        $other = MachineBrand::factory()->create();

        MachineModel::factory()->create(['machine_brand_id' => $brand->id, 'name' => 'R-2000']);

        // Same name under a different brand is fine.
        MachineModel::factory()->create(['machine_brand_id' => $other->id, 'name' => 'R-2000']);

        $this->expectException(QueryException::class);
        MachineModel::factory()->create(['machine_brand_id' => $brand->id, 'name' => 'R-2000']);
    }

    public function test_ai_box_motherboard_uuid_is_unique(): void
    {
        $uuid = '0f9c1d8e-3a55-4b21-9a0f-1c2d3e4f5a6b';
        AiBox::factory()->create(['motherboard_uuid' => $uuid]);

        $this->expectException(QueryException::class);
        AiBox::factory()->create(['motherboard_uuid' => $uuid]);
    }

    public function test_revision_number_is_unique_per_revisable(): void
    {
        $model = AiModel::factory()->create();
        $script = FlowchartScript::factory()->create();

        Revision::factory()->for($model, 'revisable')->create(['number' => 1]);

        // The same number under a different revisable is fine — numbering is per entry.
        Revision::factory()->for($script, 'revisable')->create(['number' => 1]);

        $this->expectException(QueryException::class);
        Revision::factory()->for($model, 'revisable')->create(['number' => 1]);
    }

    public function test_entries_may_carry_a_customer_label(): void
    {
        $customer = Customer::factory()->create();

        $script = FlowchartScript::factory()->create(['customer_id' => $customer->id]);
        $model = AiModel::factory()->create(['customer_id' => $customer->id]);

        $this->assertTrue($customer->is($script->customer));
        $this->assertTrue($customer->is($model->customer));

        // The label is optional — an unlabelled entry is the normal case.
        $this->assertNull(FlowchartScript::factory()->create()->customer_id);
    }
}
