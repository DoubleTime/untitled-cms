<?php

namespace Tests\Feature\Marketplace;

use App\Models\AiBox;
use App\Models\AiModel;
use App\Models\Customer;
use App\Models\Download;
use App\Models\FlowchartScript;
use App\Models\MachineBrand;
use App\Models\MachineModel;
use App\Models\Revision;
use App\Models\Role;
use App\Models\User;
use App\Services\Marketplace\RevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected MachineModel $machineModel;

    protected Customer $inari;

    protected Customer $carsem;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('marketplace');

        $this->admin = $this->userWithPermissions(['downloads.view', 'scripts.view', 'ai_models.view']);

        $brand = MachineBrand::factory()->create();
        $this->machineModel = MachineModel::factory()->create(['machine_brand_id' => $brand->id]);

        $this->inari = Customer::factory()->create(['company' => 'Inari']);
        $this->carsem = Customer::factory()->create(['company' => 'Carsem']);
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

    private function script(string $name): FlowchartScript
    {
        return FlowchartScript::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => $name,
        ]);
    }

    private function revision(FlowchartScript|AiModel $entry): Revision
    {
        $file = $entry instanceof FlowchartScript
            ? UploadedFile::fake()->createWithContent('bundle.zip', "PK\x03\x04".str_repeat('a', 64))
            : UploadedFile::fake()->createWithContent('weights.h5', "\x89HDF\r\n\x1a\n".str_repeat('a', 64));

        return app(RevisionService::class)->upload($entry, $file, 'Note', $this->admin);
    }

    private function download(
        FlowchartScript|AiModel $entry,
        array $attributes = [],
    ): Download {
        return Download::factory()->create(array_merge([
            'revision_id' => $this->revision($entry)->id,
            'revisable_type' => $entry->getMorphClass(),
            'revisable_id' => $entry->getKey(),
        ], $attributes));
    }

    public function test_the_log_is_denied_without_the_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['scripts.view']))
            ->get('/admin/marketplace/downloads')
            ->assertForbidden();
    }

    public function test_the_log_lists_downloads_newest_first(): void
    {
        $script = $this->script('Wire bond check');

        $this->download($script, ['created_at' => '2026-09-01 10:00:00']);
        $this->download($script, ['created_at' => '2026-09-05 10:00:00']);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/downloads')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Marketplace/Downloads/Index')
                ->has('downloads.data', 2)
                ->where('downloads.data.0.entry_name', 'Wire bond check')
                ->where('downloads.data.0.created_at', fn ($value) => str_starts_with($value, '2026-09-05'))
                ->where('summary.total', 2)
            );
    }

    public function test_the_log_is_paginated_at_fifty_a_page(): void
    {
        $script = $this->script('Paged');
        $revision = $this->revision($script);

        Download::factory()->count(55)->create([
            'revision_id' => $revision->id,
            'revisable_type' => $script->getMorphClass(),
            'revisable_id' => $script->getKey(),
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/downloads')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('downloads.data', 50)
                ->where('downloads.total', 55)
                ->where('downloads.last_page', 2)
            );

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/downloads?page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('downloads.data', 5));
    }

    public function test_the_source_filter(): void
    {
        $script = $this->script('Sourced');

        $this->download($script, ['source' => Download::SOURCE_API]);
        $this->download($script, ['source' => Download::SOURCE_WEB]);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/downloads?source=web')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('downloads.data', 1)
                ->where('downloads.data.0.source', 'web')
                ->where('summary.total', 1)
            );
    }

    public function test_the_entry_type_filter(): void
    {
        $script = $this->script('A script');
        $aiModel = AiModel::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => 'An AI Model',
        ]);

        $this->download($script);
        $this->download($aiModel);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/downloads?entry_type=ai_model')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('downloads.data', 1)
                ->where('downloads.data.0.entry_name', 'An AI Model')
            );
    }

    public function test_the_ai_box_filter(): void
    {
        $script = $this->script('Boxed');
        $box = AiBox::factory()->create(['customer_id' => $this->inari->id]);
        $other = AiBox::factory()->create(['customer_id' => $this->carsem->id]);

        $this->download($script, ['ai_box_id' => $box->id]);
        $this->download($script, ['ai_box_id' => $other->id]);

        $this->actingAs($this->admin)
            ->get("/admin/marketplace/downloads?ai_box_id={$box->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('downloads.data', 1)
                ->where('downloads.data.0.ai_box.id', $box->id)
            );
    }

    public function test_the_customer_filter_matches_through_the_box_and_through_the_user(): void
    {
        $script = $this->script('Customer scoped');

        $box = AiBox::factory()->create(['customer_id' => $this->inari->id]);
        $inariUser = User::factory()->create(['customer_id' => $this->inari->id]);
        $carsemBox = AiBox::factory()->create(['customer_id' => $this->carsem->id]);

        // Reached through the AI Box.
        $this->download($script, ['ai_box_id' => $box->id, 'user_id' => User::factory()]);
        // Reached through the Customer User, with no box (a web fetch).
        $this->download($script, ['ai_box_id' => null, 'user_id' => $inariUser->id, 'source' => Download::SOURCE_WEB]);
        // Neither.
        $this->download($script, ['ai_box_id' => $carsemBox->id]);

        $this->actingAs($this->admin)
            ->get("/admin/marketplace/downloads?customer_id={$this->inari->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('downloads.data', 2)
                ->where('summary.total', 2)
            );
    }

    public function test_the_user_filter(): void
    {
        $script = $this->script('By user');
        $alice = User::factory()->create(['name' => 'Alice']);

        $this->download($script, ['user_id' => $alice->id]);
        $this->download($script);

        $this->actingAs($this->admin)
            ->get("/admin/marketplace/downloads?user_id={$alice->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('downloads.data', 1)
                ->where('downloads.data.0.user.name', 'Alice')
            );
    }

    public function test_the_entry_name_search_spans_both_entry_types(): void
    {
        $script = $this->script('Bond inspection');
        $aiModel = AiModel::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => 'Bond classifier',
        ]);
        $other = $this->script('Solder paste');

        $this->download($script);
        $this->download($aiModel);
        $this->download($other);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/downloads?q=Bond')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('downloads.data', 2)
                ->where('summary.total', 2)
            );
    }

    public function test_the_entry_name_search_matching_nothing_returns_nothing(): void
    {
        $this->download($this->script('Bond inspection'));

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/downloads?q=nothing-matches-this')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('downloads.data', 0)
                ->where('summary.total', 0)
            );
    }

    public function test_the_date_range_filter(): void
    {
        $script = $this->script('Dated');

        $this->download($script, ['created_at' => '2026-08-01 10:00:00']);
        $this->download($script, ['created_at' => '2026-09-10 10:00:00']);
        $this->download($script, ['created_at' => '2026-09-20 10:00:00']);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/downloads?from=2026-09-01&to=2026-09-15')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('downloads.data', 1)
                ->where('summary.total', 1)
            );
    }

    public function test_the_summary_strip(): void
    {
        $popular = $this->script('Popular');
        $quiet = $this->script('Quiet');

        $boxA = AiBox::factory()->create(['customer_id' => $this->inari->id]);
        $boxB = AiBox::factory()->create(['customer_id' => $this->inari->id]);

        $revision = $this->revision($popular);

        // Three recent downloads of the popular entry from two distinct boxes.
        foreach ([$boxA->id, $boxB->id, $boxA->id] as $boxId) {
            Download::factory()->create([
                'revision_id' => $revision->id,
                'revisable_type' => $popular->getMorphClass(),
                'revisable_id' => $popular->getKey(),
                'ai_box_id' => $boxId,
                'created_at' => now()->subDay(),
            ]);
        }

        // One old download of the quiet entry, with no box at all.
        $this->download($quiet, ['ai_box_id' => null, 'created_at' => now()->subDays(30)]);

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/downloads')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total', 4)
                ->where('summary.last_seven_days', 3)
                ->where('summary.unique_boxes', 2)
                ->has('summary.top_entries', 2)
                ->where('summary.top_entries.0.entry_name', 'Popular')
                ->where('summary.top_entries.0.downloads', 3)
            );
    }

    public function test_a_download_whose_entry_was_hard_deleted_still_appears(): void
    {
        $script = $this->script('Gone');
        $this->download($script);

        $script->forceDelete();

        $this->actingAs($this->admin)
            ->get('/admin/marketplace/downloads')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('downloads.data', 1)
                ->where('downloads.data.0.entry_name', null)
                ->where('downloads.data.0.entry_deleted', true)
            );
    }
}
