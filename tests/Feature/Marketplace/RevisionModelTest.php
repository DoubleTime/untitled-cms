<?php

namespace Tests\Feature\Marketplace;

use App\Models\AiModel;
use App\Models\FlowchartScript;
use App\Models\Revision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Revisions are polymorphic so AI Models and FlowChart Scripts share one
 * implementation. Only released Revisions are offered to RPA-TOOL by default.
 */
class RevisionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_released_revision_ignores_draft_and_deprecated(): void
    {
        $model = AiModel::factory()->create();

        Revision::factory()->for($model, 'revisable')->deprecated()->create(['number' => 1]);
        Revision::factory()->for($model, 'revisable')->released()->create(['number' => 2]);
        $expected = Revision::factory()->for($model, 'revisable')->released()->create(['number' => 3]);
        Revision::factory()->for($model, 'revisable')->create(['number' => 4]); // draft

        $latest = $model->latestReleasedRevision();

        $this->assertNotNull($latest);
        $this->assertSame($expected->id, $latest->id);
        $this->assertSame(3, $latest->number);
    }

    public function test_latest_released_revision_is_null_when_nothing_is_released(): void
    {
        $script = FlowchartScript::factory()->create();

        Revision::factory()->for($script, 'revisable')->create(['number' => 1]);
        Revision::factory()->for($script, 'revisable')->deprecated()->create(['number' => 2]);

        $this->assertNull($script->latestReleasedRevision());
    }

    public function test_revisions_are_ordered_by_number_descending(): void
    {
        $script = FlowchartScript::factory()->create();

        Revision::factory()->for($script, 'revisable')->create(['number' => 1]);
        Revision::factory()->for($script, 'revisable')->create(['number' => 3]);
        Revision::factory()->for($script, 'revisable')->create(['number' => 2]);

        $this->assertSame([3, 2, 1], $script->revisions()->pluck('number')->all());
    }

    public function test_revisable_resolves_for_both_entry_types(): void
    {
        $model = AiModel::factory()->create();
        $script = FlowchartScript::factory()->create();

        $modelRevision = Revision::factory()->for($model, 'revisable')->create(['number' => 1]);
        $scriptRevision = Revision::factory()->for($script, 'revisable')->create(['number' => 1]);

        $this->assertInstanceOf(AiModel::class, $modelRevision->fresh()->revisable);
        $this->assertSame($model->id, $modelRevision->fresh()->revisable->id);

        $this->assertInstanceOf(FlowchartScript::class, $scriptRevision->fresh()->revisable);
        $this->assertSame($script->id, $scriptRevision->fresh()->revisable->id);

        // Each entry sees only its own revisions.
        $this->assertSame(1, $model->revisions()->count());
        $this->assertSame(1, $script->revisions()->count());
    }

    public function test_statuses_and_helpers(): void
    {
        $model = AiModel::factory()->create();

        $draft = Revision::factory()->for($model, 'revisable')->create(['number' => 1]);
        $released = Revision::factory()->for($model, 'revisable')->released()->create(['number' => 2]);
        $deprecated = Revision::factory()->for($model, 'revisable')->deprecated()->create(['number' => 3]);

        $this->assertSame(Revision::STATUS_DRAFT, $draft->status);
        $this->assertFalse($draft->isReleased());
        $this->assertTrue($released->isReleased());
        $this->assertTrue($deprecated->isDeprecated());
    }
}
