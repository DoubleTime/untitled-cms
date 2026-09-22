<?php

namespace Tests\Feature\Api\V1;

use App\Http\Controllers\Api\V1\CatalogueController;
use App\Models\AiBox;
use App\Models\AiModel;
use App\Models\Download;
use App\Models\FlowchartScript;
use App\Models\Revision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DownloadTest extends ApiTestCase
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

    public function test_the_default_download_is_the_latest_released_revision(): void
    {
        $script = FlowchartScript::factory()->create();

        $this->makeRevision($script, Revision::STATUS_RELEASED, 'One');
        $latest = $this->makeRevision($script, Revision::STATUS_RELEASED, 'Two');
        $this->makeRevision($script, Revision::STATUS_DRAFT, 'Three');

        $response = $this->api()->get('/api/v1/scripts/'.$script->id.'/download');

        $response->assertOk();
        $this->assertSame($latest->sha256, $response->headers->get('X-Checksum-SHA256'));
        $this->assertSame((string) $latest->number, $response->headers->get('X-Revision-Number'));
        $this->assertStringContainsString(
            $latest->original_filename,
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_the_download_is_recorded_with_the_api_source_the_user_and_the_box(): void
    {
        $script = FlowchartScript::factory()->create();
        $revision = $this->makeRevision($script);

        $this->api()->get('/api/v1/scripts/'.$script->id.'/download')->assertOk();

        $box = AiBox::where('motherboard_uuid', self::UUID)->firstOrFail();
        $download = Download::firstOrFail();

        $this->assertSame($revision->id, $download->revision_id);
        $this->assertSame('flowchart_script', $download->revisable_type);
        $this->assertSame($script->id, $download->revisable_id);
        $this->assertSame($this->user->id, $download->user_id);
        $this->assertSame($box->id, $download->ai_box_id);
        $this->assertSame(Download::SOURCE_API, $download->source);
    }

    public function test_a_deprecated_revision_can_be_downloaded_by_explicit_number(): void
    {
        $script = FlowchartScript::factory()->create();

        $deprecated = $this->makeRevision($script, Revision::STATUS_DEPRECATED, 'Old');
        $this->makeRevision($script, Revision::STATUS_RELEASED, 'New');

        $response = $this->api()->get('/api/v1/scripts/'.$script->id.'/download?revision='.$deprecated->number);

        $response->assertOk();
        $this->assertSame($deprecated->sha256, $response->headers->get('X-Checksum-SHA256'));
    }

    public function test_a_draft_revision_is_never_downloadable(): void
    {
        $script = FlowchartScript::factory()->create();

        $this->makeRevision($script, Revision::STATUS_RELEASED);
        $draft = $this->makeRevision($script, Revision::STATUS_DRAFT);

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/download?revision='.$draft->number)
            ->assertStatus(404);

        $this->assertSame(0, Download::count());
    }

    public function test_an_unknown_revision_number_is_not_found(): void
    {
        $script = FlowchartScript::factory()->create();
        $this->makeRevision($script);

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/download?revision=99')
            ->assertStatus(404);
    }

    public function test_an_entry_with_no_released_revision_says_so(): void
    {
        $script = FlowchartScript::factory()->create();
        $this->makeRevision($script, Revision::STATUS_DRAFT);

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/download')
            ->assertStatus(404)
            ->assertJsonPath('message', CatalogueController::NO_RELEASED_REVISION);
    }

    public function test_a_revision_belonging_to_another_entry_is_not_reachable(): void
    {
        $mine = FlowchartScript::factory()->create();
        $other = FlowchartScript::factory()->create();

        $this->makeRevision($mine);
        $this->makeRevision($other);
        $otherSecond = $this->makeRevision($other);

        // Number 2 exists, but only on the other Script.
        $this->api()->getJson('/api/v1/scripts/'.$mine->id.'/download?revision='.$otherSecond->number)
            ->assertStatus(404);
    }

    public function test_ai_model_revisions_download_the_same_way(): void
    {
        $aiModel = AiModel::factory()->create();
        $revision = $this->makeRevision($aiModel);

        $response = $this->api()->get('/api/v1/ai-models/'.$aiModel->id.'/download');

        $response->assertOk();
        $this->assertSame($revision->sha256, $response->headers->get('X-Checksum-SHA256'));
        $this->assertSame('ai_model', Download::firstOrFail()->revisable_type);
    }

    public function test_downloads_are_throttled_at_twenty_per_minute(): void
    {
        $script = FlowchartScript::factory()->create();
        $this->makeRevision($script);

        for ($i = 0; $i < 20; $i++) {
            $this->api()->get('/api/v1/scripts/'.$script->id.'/download')->assertOk();
        }

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/download')->assertStatus(429);
    }

    public function test_downloading_without_a_token_is_unauthenticated(): void
    {
        $script = FlowchartScript::factory()->create();
        $this->makeRevision($script);

        $this->getJson('/api/v1/scripts/'.$script->id.'/download')->assertStatus(401);
        $this->assertSame(0, Download::count());
    }
}
