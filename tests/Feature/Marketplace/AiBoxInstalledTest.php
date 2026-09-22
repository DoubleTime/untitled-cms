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
use App\Models\User;
use App\Services\Marketplace\AiBoxInstalledService;
use App\Services\Marketplace\RevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Installed" is derived, never stored: the latest Download of each catalogue
 * entry by one AI Box.
 */
class AiBoxInstalledTest extends TestCase
{
    use RefreshDatabase;

    protected AiBoxInstalledService $service;

    protected AiBox $box;

    protected MachineModel $machineModel;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('marketplace');

        $this->service = app(AiBoxInstalledService::class);
        $this->user = User::factory()->create();

        $brand = MachineBrand::factory()->create(['name' => 'Kulicke & Soffa']);
        $this->machineModel = MachineModel::factory()->create([
            'machine_brand_id' => $brand->id,
            'name' => 'iStack',
        ]);

        $this->box = AiBox::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'status' => AiBox::STATUS_ACTIVE,
        ]);
    }

    private function script(string $name = 'Wire bond check'): FlowchartScript
    {
        return FlowchartScript::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => $name,
        ]);
    }

    private function revision(FlowchartScript|AiModel $entry, string $status = Revision::STATUS_RELEASED): Revision
    {
        $service = app(RevisionService::class);

        $file = $entry instanceof FlowchartScript
            ? UploadedFile::fake()->createWithContent('bundle.zip', "PK\x03\x04".str_repeat('a', 64))
            : UploadedFile::fake()->createWithContent('weights.h5', "\x89HDF\r\n\x1a\n".str_repeat('a', 64));

        $revision = $service->upload($entry, $file, 'Change note', $this->user);

        if (in_array($status, [Revision::STATUS_RELEASED, Revision::STATUS_DEPRECATED], true)) {
            $service->release($revision, $this->user);
        }

        if ($status === Revision::STATUS_DEPRECATED) {
            $service->deprecate($revision, $this->user);
        }

        return $revision->refresh();
    }

    private function download(Revision $revision, FlowchartScript|AiModel $entry, string $at): Download
    {
        return Download::factory()->create([
            'revision_id' => $revision->id,
            'revisable_type' => $entry->getMorphClass(),
            'revisable_id' => $entry->getKey(),
            'user_id' => $this->user->id,
            'ai_box_id' => $this->box->id,
            'source' => Download::SOURCE_API,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function test_a_box_with_no_downloads_has_nothing_installed(): void
    {
        $this->assertSame([], $this->service->forBox($this->box));
    }

    public function test_the_latest_download_per_entry_wins(): void
    {
        $script = $this->script();
        $first = $this->revision($script);
        $second = $this->revision($script);

        // Deliberately inserted newest-first, so a naive "first row" would be wrong.
        $this->download($second, $script, '2026-09-10 10:00:00');
        $this->download($first, $script, '2026-09-01 10:00:00');

        $rows = $this->service->forBox($this->box);

        $this->assertCount(1, $rows);
        $this->assertSame($script->name, $rows[0]['entry_name']);
        $this->assertSame(2, $rows[0]['installed_number']);
        $this->assertSame('Kulicke & Soffa', $rows[0]['machine_brand']);
        $this->assertSame('iStack', $rows[0]['machine_model']);
        $this->assertFalse($rows[0]['outdated']);
    }

    public function test_an_older_installed_revision_is_flagged_outdated(): void
    {
        $script = $this->script();
        $first = $this->revision($script);
        $this->revision($script);

        $this->download($first, $script, '2026-09-01 10:00:00');

        $rows = $this->service->forBox($this->box);

        $this->assertSame(1, $rows[0]['installed_number']);
        $this->assertSame(2, $rows[0]['latest_released_number']);
        $this->assertTrue($rows[0]['outdated']);
    }

    public function test_a_draft_revision_does_not_make_an_install_outdated(): void
    {
        $script = $this->script();
        $released = $this->revision($script);
        $this->revision($script, Revision::STATUS_DRAFT);

        $this->download($released, $script, '2026-09-01 10:00:00');

        $rows = $this->service->forBox($this->box);

        $this->assertSame(1, $rows[0]['latest_released_number']);
        $this->assertFalse($rows[0]['outdated']);
    }

    public function test_an_entry_whose_only_download_was_deprecated_is_still_listed(): void
    {
        $script = $this->script('Deprecated only');
        $deprecated = $this->revision($script, Revision::STATUS_DEPRECATED);

        $this->download($deprecated, $script, '2026-09-01 10:00:00');

        $rows = $this->service->forBox($this->box);

        $this->assertCount(1, $rows);
        $this->assertSame('deprecated', $rows[0]['installed_status']);
        $this->assertSame(1, $rows[0]['installed_number']);
        // Nothing released, so there is nothing to be behind.
        $this->assertNull($rows[0]['latest_released_number']);
        $this->assertFalse($rows[0]['outdated']);
    }

    public function test_both_entry_types_appear_side_by_side(): void
    {
        $script = $this->script('Wire bond check');
        $aiModel = AiModel::factory()->create([
            'machine_model_id' => $this->machineModel->id,
            'name' => 'Solder void detector',
        ]);

        $this->download($this->revision($script), $script, '2026-09-01 10:00:00');
        $this->download($this->revision($aiModel), $aiModel, '2026-09-02 10:00:00');

        $rows = collect($this->service->forBox($this->box))->keyBy('entry_type');

        $this->assertCount(2, $rows);
        $this->assertSame('Wire bond check', $rows['flowchart_script']['entry_name']);
        $this->assertSame('Solder void detector', $rows['ai_model']['entry_name']);
    }

    public function test_another_boxs_downloads_are_not_counted(): void
    {
        $script = $this->script();
        $revision = $this->revision($script);

        $otherBox = AiBox::factory()->create(['customer_id' => $this->box->customer_id]);

        Download::factory()->create([
            'revision_id' => $revision->id,
            'revisable_type' => $script->getMorphClass(),
            'revisable_id' => $script->getKey(),
            'ai_box_id' => $otherBox->id,
        ]);

        $this->assertSame([], $this->service->forBox($this->box));
    }

    public function test_a_soft_deleted_entry_is_still_reported(): void
    {
        $script = $this->script('Retired script');
        $this->download($this->revision($script), $script, '2026-09-01 10:00:00');

        $script->delete();

        $rows = $this->service->forBox($this->box);

        $this->assertCount(1, $rows);
        $this->assertSame('Retired script', $rows[0]['entry_name']);
        $this->assertTrue($rows[0]['entry_deleted']);
    }
}
