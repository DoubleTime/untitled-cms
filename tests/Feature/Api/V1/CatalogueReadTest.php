<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\Customer;
use App\Models\MachineBrand;
use App\Models\MachineModel;
use App\Models\Revision;
use App\Models\Script;
use App\Models\ScriptImage;
use App\Models\User;
use App\Models\VaultFile;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CatalogueReadTest extends ApiTestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeMarketplaceDisk();

        $this->user = $this->customerUser();
        $this->token = $this->tokenFor($this->user);
    }

    protected function api()
    {
        return $this->asToken($this->token);
    }

    public function test_machine_brands_and_models_are_listed(): void
    {
        $brand = MachineBrand::factory()->create(['name' => 'Fuji', 'slug' => 'fuji']);
        $model = MachineModel::factory()->create([
            'machine_brand_id' => $brand->id,
            'name' => 'NXT III',
            'is_active' => true,
        ]);
        // Inactive Machine Models are still listed: an entry may be labelled with
        // one, and this is a filter list, not an access decision.
        $inactiveModel = MachineModel::factory()->create([
            'machine_brand_id' => $brand->id,
            'name' => 'NXT II',
            'is_active' => false,
        ]);

        $brands = $this->api()->getJson('/api/v1/machine-brands')->assertOk()->json('data');

        $fuji = collect($brands)->firstWhere('id', $brand->id);

        $this->assertNotNull($fuji);
        $this->assertSame('Fuji', $fuji['name']);
        $this->assertSame('fuji', $fuji['slug']);
        $this->assertSame(['id', 'name', 'slug'], array_keys($fuji));

        $response = $this->api()->getJson('/api/v1/machine-models')->assertOk();

        $models = collect($response->json('data'));

        $this->assertCount(2, $models);

        $active = $models->firstWhere('id', $model->id);
        $inactive = $models->firstWhere('id', $inactiveModel->id);

        $this->assertNotNull($active);
        $this->assertTrue($active['is_active']);
        $this->assertSame($brand->id, $active['brand']['id']);
        $this->assertSame('Fuji', $active['brand']['name']);

        $this->assertNotNull($inactive);
        $this->assertFalse($inactive['is_active']);

        $this->api()->getJson('/api/v1/machine-models?brand='.$brand->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->api()->getJson('/api/v1/machine-models?brand=nope')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_customers_are_all_visible_because_the_label_is_not_an_access_wall(): void
    {
        $other = Customer::factory()->create(['company' => 'Somebody Else']);
        // Inactive Customers stay in the list — an entry may still carry the label —
        // and are marked with is_active so RPA-TOOL can grey the row out.
        $inactive = Customer::factory()->create(['company' => 'Wound Down', 'is_active' => false]);

        $response = $this->api()->getJson('/api/v1/customers')->assertOk();

        $rows = collect($response->json('data'));
        $companies = $rows->pluck('company')->all();

        $this->assertContains('Somebody Else', $companies);
        $this->assertContains('Wound Down', $companies);
        $this->assertContains($this->user->customer->company, $companies);
        $this->assertCount(3, $companies);
        $this->assertSame(
            ['code', 'company', 'id', 'is_active'],
            collect($response->json('data.0'))->keys()->sort()->values()->all()
        );
        $this->assertSame($other->id, $rows->firstWhere('company', 'Somebody Else')['id']);
        $this->assertTrue($rows->firstWhere('company', 'Somebody Else')['is_active']);
        $this->assertFalse($rows->firstWhere('id', $inactive->id)['is_active']);
    }

    public function test_an_entry_without_a_released_revision_is_hidden_from_the_list(): void
    {
        $withRelease = Script::factory()->create(['name' => 'Alpha']);
        $this->makeRevision($withRelease, Revision::STATUS_RELEASED);

        $draftOnly = Script::factory()->create(['name' => 'Beta']);
        $this->makeRevision($draftOnly, Revision::STATUS_DRAFT);

        Script::factory()->create(['name' => 'Gamma']);

        $response = $this->api()->getJson('/api/v1/scripts')->assertOk();

        $this->assertSame(['Alpha'], array_column($response->json('data'), 'name'));
    }

    public function test_a_deprecated_only_entry_is_hidden_from_the_list(): void
    {
        $script = Script::factory()->create(['name' => 'Retired']);
        $this->makeRevision($script, Revision::STATUS_DEPRECATED);

        $this->api()->getJson('/api/v1/scripts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_list_row_carries_the_latest_released_revision_counts_and_preview_image(): void
    {
        $brand = MachineBrand::factory()->create(['name' => 'Fuji']);
        $machineModel = MachineModel::factory()->create(['machine_brand_id' => $brand->id, 'name' => 'NXT III']);
        $customer = Customer::factory()->create(['company' => 'Inari']);

        $script = Script::factory()->create([
            'name' => 'Tray feeder',
            'machine_model_id' => $machineModel->id,
            'customer_id' => $customer->id,
        ]);

        $this->makeRevision($script, Revision::STATUS_DEPRECATED, 'Old');
        $latest = $this->makeRevision($script, Revision::STATUS_RELEASED, 'New');
        $this->makeRevision($script, Revision::STATUS_DRAFT, 'Not yet');

        $vaultFile = VaultFile::factory()->create(['is_public' => true, 'extension' => 'png']);
        ScriptImage::factory()->create([
            'script_id' => $script->id,
            'vault_file_id' => $vaultFile->id,
            'sort_order' => 0,
        ]);

        $response = $this->api()->getJson('/api/v1/scripts')->assertOk();

        $response->assertJsonPath('data.0.name', 'Tray feeder')
            ->assertJsonPath('data.0.machine_model.name', 'NXT III')
            ->assertJsonPath('data.0.machine_model.brand.name', 'Fuji')
            ->assertJsonPath('data.0.customer.company', 'Inari')
            ->assertJsonPath('data.0.latest_revision.number', $latest->number)
            ->assertJsonPath('data.0.latest_revision.sha256', $latest->sha256)
            ->assertJsonPath('data.0.latest_revision.change_note', 'New')
            // released + deprecated, never the draft
            ->assertJsonPath('data.0.revisions_count', 2)
            ->assertJsonPath('data.0.downloads_count', 0)
            ->assertJsonPath('data.0.preview_image_url', $vaultFile->url);
    }

    public function test_a_script_without_a_customer_reports_a_null_customer(): void
    {
        $script = Script::factory()->create(['customer_id' => null]);
        $this->makeRevision($script);

        $this->api()->getJson('/api/v1/scripts')
            ->assertOk()
            ->assertJsonPath('data.0.customer', null);
    }

    public function test_the_list_filters_by_machine_model_brand_and_customer(): void
    {
        $brandA = MachineBrand::factory()->create();
        $brandB = MachineBrand::factory()->create();
        $modelA = MachineModel::factory()->create(['machine_brand_id' => $brandA->id]);
        $modelB = MachineModel::factory()->create(['machine_brand_id' => $brandB->id]);
        $customer = Customer::factory()->create();

        $a = Script::factory()->create(['name' => 'A', 'machine_model_id' => $modelA->id, 'customer_id' => $customer->id]);
        $b = Script::factory()->create(['name' => 'B', 'machine_model_id' => $modelB->id, 'customer_id' => null]);

        $this->makeRevision($a);
        $this->makeRevision($b);

        $this->api()->getJson('/api/v1/scripts?machine_model='.$modelA->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'A');

        $this->api()->getJson('/api/v1/scripts?brand='.$brandB->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'B');

        $this->api()->getJson('/api/v1/scripts?customer='.$customer->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'A');
    }

    public function test_the_q_filter_matches_name_and_description_case_insensitively(): void
    {
        $byName = Script::factory()->create(['name' => 'Solder Paste Inspection', 'description' => 'nothing']);
        $byDescription = Script::factory()->create(['name' => 'Zed', 'description' => 'Handles SOLDER joints']);
        $neither = Script::factory()->create(['name' => 'Conveyor', 'description' => 'belt']);

        foreach ([$byName, $byDescription, $neither] as $script) {
            $this->makeRevision($script);
        }

        $response = $this->api()->getJson('/api/v1/scripts?q=solder')->assertOk();

        $names = array_column($response->json('data'), 'name');
        sort($names);

        $this->assertSame(['Solder Paste Inspection', 'Zed'], $names);
    }

    public function test_the_list_is_ordered_by_name_and_paginated_with_a_capped_per_page(): void
    {
        foreach (['Charlie', 'alpha', 'Bravo'] as $name) {
            $script = Script::factory()->create(['name' => $name]);
            $this->makeRevision($script);
        }

        $response = $this->api()->getJson('/api/v1/scripts?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame(3, $response->json('meta.total'));

        // per_page is capped at 200 and floored at 1.
        $this->assertSame(200, $this->api()->getJson('/api/v1/scripts?per_page=5000')->json('meta.per_page'));
        $this->assertSame(1, $this->api()->getJson('/api/v1/scripts?per_page=0')->json('meta.per_page'));
    }

    public function test_the_detail_payload_shows_images_and_every_non_draft_revision(): void
    {
        $script = Script::factory()->create();

        $deprecated = $this->makeRevision($script, Revision::STATUS_DEPRECATED, 'One');
        $released = $this->makeRevision($script, Revision::STATUS_RELEASED, 'Two');
        $draft = $this->makeRevision($script, Revision::STATUS_DRAFT, 'Three');

        $vaultFile = VaultFile::factory()->create(['is_public' => true, 'extension' => 'png']);
        ScriptImage::factory()->create([
            'script_id' => $script->id,
            'vault_file_id' => $vaultFile->id,
            'sort_order' => 0,
        ]);

        $response = $this->api()->getJson('/api/v1/scripts/'.$script->id)->assertOk();

        $numbers = array_column($response->json('data.revisions'), 'number');

        $this->assertSame([$released->number, $deprecated->number], $numbers);
        $this->assertNotContains($draft->number, $numbers);
        $response->assertJsonPath('data.images.0.url', $vaultFile->url)
            ->assertJsonPath('data.images.0.sort_order', 0)
            ->assertJsonPath('data.latest_revision.number', $released->number)
            ->assertJsonStructure(['data' => ['revisions' => [[
                'id', 'number', 'status', 'size_bytes', 'sha256', 'change_note',
                'original_filename', 'released_at', 'deprecated_at',
            ]]]]);
    }

    public function test_the_revisions_endpoint_returns_the_same_non_draft_list(): void
    {
        $script = Script::factory()->create();
        $released = $this->makeRevision($script, Revision::STATUS_RELEASED);
        $this->makeRevision($script, Revision::STATUS_DRAFT);

        $response = $this->api()->getJson('/api/v1/scripts/'.$script->id.'/revisions')->assertOk();

        $this->assertSame([$released->number], array_column($response->json('data'), 'number'));
    }

    /**
     * A draft-only entry is hidden from the list and has nothing to download, so
     * `show` and `revisions` refuse it too rather than answering with an empty
     * `revisions` array.
     */
    public function test_a_draft_only_entry_is_not_found_by_show_or_revisions(): void
    {
        $script = Script::factory()->create();
        $this->makeRevision($script, Revision::STATUS_DRAFT);

        $this->api()->getJson('/api/v1/scripts/'.$script->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'No released revision available.');

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/revisions')
            ->assertStatus(404)
            ->assertJsonPath('message', 'No released revision available.');
    }

    public function test_an_entry_with_no_revisions_at_all_is_not_found_by_show_or_revisions(): void
    {
        $aiModel = AiModel::factory()->create();

        $this->api()->getJson('/api/v1/ai-models/'.$aiModel->id)->assertStatus(404);
        $this->api()->getJson('/api/v1/ai-models/'.$aiModel->id.'/revisions')->assertStatus(404);
    }

    /** A deprecated-only entry still resolves: a box may re-fetch what it runs. */
    public function test_a_deprecated_only_entry_still_resolves(): void
    {
        $script = Script::factory()->create();
        $deprecated = $this->makeRevision($script, Revision::STATUS_DEPRECATED);

        $this->api()->getJson('/api/v1/scripts/'.$script->id)
            ->assertOk()
            ->assertJsonPath('data.revisions.0.number', $deprecated->number);

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/revisions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_soft_deleted_entry_is_not_found(): void
    {
        $script = Script::factory()->create();
        $this->makeRevision($script);
        $script->delete();

        $this->api()->getJson('/api/v1/scripts/'.$script->id)->assertStatus(404);
        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/revisions')->assertStatus(404);
        $this->api()->getJson('/api/v1/scripts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_ai_models_expose_the_inference_metadata_instead_of_images(): void
    {
        $aiModel = AiModel::factory()->create([
            'name' => 'Solder classifier',
            'framework' => 'keras',
            'input_size' => '224x224',
            'labels' => 'ok,ng',
            'notes' => 'Trained on 12k samples',
        ]);

        $revision = $this->makeRevision($aiModel);

        $this->api()->getJson('/api/v1/ai-models')
            ->assertOk()
            ->assertJsonPath('data.0.framework', 'keras')
            ->assertJsonPath('data.0.input_size', '224x224')
            ->assertJsonPath('data.0.labels', 'ok,ng')
            ->assertJsonPath('data.0.notes', 'Trained on 12k samples')
            ->assertJsonPath('data.0.latest_revision.number', $revision->number)
            ->assertJsonMissingPath('data.0.preview_image_url');

        $this->api()->getJson('/api/v1/ai-models/'.$aiModel->id)
            ->assertOk()
            ->assertJsonPath('data.revisions.0.number', $revision->number);
    }

    public function test_a_soft_deleted_ai_model_is_not_found(): void
    {
        $aiModel = AiModel::factory()->create();
        $this->makeRevision($aiModel);
        $aiModel->delete();

        $this->api()->getJson('/api/v1/ai-models/'.$aiModel->id)->assertStatus(404);
    }
}
