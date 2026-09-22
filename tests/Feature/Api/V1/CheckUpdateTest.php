<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\Revision;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CheckUpdateTest extends ApiTestCase
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

    public function test_a_newer_released_revision_is_reported_as_an_update(): void
    {
        $script = Script::factory()->create();

        $this->makeRevision($script, Revision::STATUS_DEPRECATED, 'One');
        $latest = $this->makeRevision($script, Revision::STATUS_RELEASED, 'Two');

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/check-update?current=1')
            ->assertOk()
            ->assertJsonPath('update_available', true)
            ->assertJsonPath('latest.number', $latest->number)
            ->assertJsonPath('latest.sha256', $latest->sha256)
            ->assertJsonPath('latest.size_bytes', $latest->size_bytes)
            ->assertJsonPath('latest.change_note', 'Two')
            ->assertJsonPath('current_status', Revision::STATUS_DEPRECATED);
    }

    public function test_holding_the_latest_released_revision_is_not_an_update(): void
    {
        $script = Script::factory()->create();
        $latest = $this->makeRevision($script, Revision::STATUS_RELEASED);

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/check-update?current='.$latest->number)
            ->assertOk()
            ->assertJsonPath('update_available', false)
            ->assertJsonPath('latest.number', $latest->number)
            ->assertJsonPath('current_status', Revision::STATUS_RELEASED);
    }

    public function test_an_unknown_current_number_reports_unknown(): void
    {
        $script = Script::factory()->create();
        $this->makeRevision($script);

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/check-update?current=99')
            ->assertOk()
            ->assertJsonPath('update_available', true)
            ->assertJsonPath('current_status', 'unknown');
    }

    public function test_a_draft_current_number_reports_unknown(): void
    {
        $script = Script::factory()->create();
        $this->makeRevision($script, Revision::STATUS_RELEASED);
        $draft = $this->makeRevision($script, Revision::STATUS_DRAFT);

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/check-update?current='.$draft->number)
            ->assertOk()
            ->assertJsonPath('current_status', 'unknown');
    }

    public function test_without_current_the_latest_release_is_simply_offered(): void
    {
        $script = Script::factory()->create();
        $latest = $this->makeRevision($script);

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/check-update')
            ->assertOk()
            ->assertJsonPath('update_available', true)
            ->assertJsonPath('latest.number', $latest->number)
            ->assertJsonPath('current_status', null);
    }

    public function test_a_draft_only_entry_offers_nothing_even_when_current_is_unknown(): void
    {
        $script = Script::factory()->create();
        $this->makeRevision($script, Revision::STATUS_DRAFT);

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/check-update?current=42')
            ->assertOk()
            ->assertJsonPath('update_available', false)
            ->assertJsonPath('latest', null);
    }

    public function test_an_entry_with_no_released_revision_has_no_update(): void
    {
        $script = Script::factory()->create();
        $this->makeRevision($script, Revision::STATUS_DRAFT);

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/check-update?current=1')
            ->assertOk()
            ->assertJsonPath('update_available', false)
            ->assertJsonPath('latest', null)
            ->assertJsonPath('current_status', 'unknown');
    }

    public function test_a_non_numeric_current_is_a_validation_error(): void
    {
        $script = Script::factory()->create();
        $this->makeRevision($script);

        $this->api()->getJson('/api/v1/scripts/'.$script->id.'/check-update?current=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors('current');
    }

    public function test_ai_models_check_updates_the_same_way(): void
    {
        $aiModel = AiModel::factory()->create();
        $latest = $this->makeRevision($aiModel);

        $this->api()->getJson('/api/v1/ai-models/'.$aiModel->id.'/check-update?current=0')
            ->assertStatus(422);

        $this->api()->getJson('/api/v1/ai-models/'.$aiModel->id.'/check-update')
            ->assertOk()
            ->assertJsonPath('latest.number', $latest->number);
    }
}
