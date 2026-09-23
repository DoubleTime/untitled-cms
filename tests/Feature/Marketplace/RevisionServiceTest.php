<?php

namespace Tests\Feature\Marketplace;

use App\Exceptions\Marketplace\InvalidRevisionTransition;
use App\Models\AiModel;
use App\Models\Revision;
use App\Models\Script;
use App\Models\User;
use App\Services\ClamAvScanner;
use App\Services\Marketplace\RevisionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class RevisionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('marketplace');
        $this->uploader = User::factory()->create();
    }

    protected function service(): RevisionService
    {
        return app(RevisionService::class);
    }

    /** A real (if tiny) zip: local file header magic bytes plus padding. */
    protected function zipFile(string $name = 'bundle.zip'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "PK\x03\x04".str_repeat("\0", 64));
    }

    /** A file carrying the HDF5 superblock signature. */
    protected function h5File(string $name = 'weights.h5'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\x89HDF\r\n\x1a\n".str_repeat("\0", 64));
    }

    public function test_the_first_revision_is_number_one_and_is_stored_under_the_morph_alias(): void
    {
        $script = Script::factory()->create();

        $revision = $this->service()->upload($script, $this->zipFile(), 'First cut', $this->uploader);

        $this->assertSame(1, $revision->number);
        $this->assertSame('script', $revision->revisable_type);
        $this->assertSame(Revision::STATUS_DRAFT, $revision->status);
        $this->assertSame("script/{$script->id}/1.zip", $revision->disk_path);
        Storage::disk('marketplace')->assertExists($revision->disk_path);
    }

    public function test_numbering_increments_per_revisable(): void
    {
        $script = Script::factory()->create();

        $first = $this->service()->upload($script, $this->zipFile(), 'One', $this->uploader);
        $second = $this->service()->upload($script, $this->zipFile(), 'Two', $this->uploader);
        $third = $this->service()->upload($script, $this->zipFile(), 'Three', $this->uploader);

        $this->assertSame([1, 2, 3], [$first->number, $second->number, $third->number]);
    }

    public function test_numbering_is_independent_between_two_scripts(): void
    {
        $a = Script::factory()->create();
        $b = Script::factory()->create();

        $this->service()->upload($a, $this->zipFile(), 'A1', $this->uploader);
        $this->service()->upload($a, $this->zipFile(), 'A2', $this->uploader);
        $firstOfB = $this->service()->upload($b, $this->zipFile(), 'B1', $this->uploader);

        $this->assertSame(1, $firstOfB->number);
        $this->assertSame(2, $a->revisions()->count());
    }

    public function test_numbering_is_independent_between_an_ai_model_and_a_script(): void
    {
        $script = Script::factory()->create();
        $aiModel = AiModel::factory()->create();

        $this->service()->upload($script, $this->zipFile(), 'Script', $this->uploader);
        $revision = $this->service()->upload($aiModel, $this->h5File(), 'Model', $this->uploader);

        $this->assertSame(1, $revision->number);
        $this->assertSame('ai_model', $revision->revisable_type);
        $this->assertSame("ai_model/{$aiModel->id}/1.h5", $revision->disk_path);
    }

    public function test_the_sha256_matches_the_uploaded_bytes(): void
    {
        $script = Script::factory()->create();
        $content = "PK\x03\x04".str_repeat('u', 128);
        $file = UploadedFile::fake()->createWithContent('bundle.zip', $content);

        $revision = $this->service()->upload($script, $file, 'Checksum', $this->uploader);

        $this->assertSame(hash('sha256', $content), $revision->sha256);
        $this->assertSame(strlen($content), $revision->size_bytes);
        $this->assertSame('bundle.zip', $revision->original_filename);
    }

    public function test_a_wrong_extension_is_rejected(): void
    {
        $script = Script::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service()->upload($script, $this->h5File(), 'Wrong type', $this->uploader);
    }

    public function test_an_ai_model_rejects_a_zip(): void
    {
        $aiModel = AiModel::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service()->upload($aiModel, $this->zipFile(), 'Wrong type', $this->uploader);
    }

    public function test_a_double_extension_is_rejected(): void
    {
        $script = Script::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service()->upload($script, $this->zipFile('payload.exe.zip'), 'Sneaky', $this->uploader);
    }

    public function test_bad_magic_bytes_are_rejected(): void
    {
        $script = Script::factory()->create();
        $file = UploadedFile::fake()->createWithContent('bundle.zip', 'not a zip at all');

        $this->expectException(ValidationException::class);

        $this->service()->upload($script, $file, 'Not a zip', $this->uploader);
    }

    public function test_bad_hdf5_magic_bytes_are_rejected(): void
    {
        $aiModel = AiModel::factory()->create();
        $file = UploadedFile::fake()->createWithContent('weights.h5', 'definitely not hdf5');

        $this->expectException(ValidationException::class);

        $this->service()->upload($aiModel, $file, 'Not a model', $this->uploader);
    }

    public function test_an_oversize_file_is_rejected(): void
    {
        config(['marketplace.max_upload_kb' => 1]);

        $script = Script::factory()->create();
        $file = UploadedFile::fake()->createWithContent('bundle.zip', "PK\x03\x04".str_repeat('x', 4096));

        $this->expectException(ValidationException::class);

        $this->service()->upload($script, $file, 'Too big', $this->uploader);
    }

    public function test_the_clamav_scanner_is_invoked_when_enabled(): void
    {
        config(['vault.clamav_enabled' => true]);

        $scanner = Mockery::mock(ClamAvScanner::class);
        $scanner->shouldReceive('scan')->once()->andReturn(null);
        $this->app->instance(ClamAvScanner::class, $scanner);

        $script = Script::factory()->create();
        $revision = $this->service()->upload($script, $this->zipFile(), 'Scanned', $this->uploader);

        $this->assertSame(1, $revision->number);
    }

    public function test_the_clamav_scanner_is_not_invoked_when_disabled(): void
    {
        config(['vault.clamav_enabled' => false]);

        $scanner = Mockery::mock(ClamAvScanner::class);
        $scanner->shouldNotReceive('scan');
        $this->app->instance(ClamAvScanner::class, $scanner);

        $script = Script::factory()->create();

        $this->service()->upload($script, $this->zipFile(), 'Unscanned', $this->uploader);
    }

    public function test_an_infected_upload_is_rejected(): void
    {
        config(['vault.clamav_enabled' => true]);

        $scanner = Mockery::mock(ClamAvScanner::class);
        $scanner->shouldReceive('scan')->once()->andReturn('Eicar-Test-Signature');
        $this->app->instance(ClamAvScanner::class, $scanner);

        $script = Script::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service()->upload($script, $this->zipFile(), 'Infected', $this->uploader);
    }

    /**
     * A partial mock whose nextNumber() is scripted, so the retry loop around the
     * unique index on (revisable_type, revisable_id, number) can be driven without
     * a second concurrent process.
     */
    protected function serviceWithScriptedNumbering(): Mockery\MockInterface
    {
        return Mockery::mock(RevisionService::class, [app(ClamAvScanner::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    public function test_a_unique_number_collision_is_retried_once(): void
    {
        $script = Script::factory()->create();

        $this->service()->upload($script, $this->zipFile(), 'First', $this->uploader);

        // First attempt hands back a number that is already taken — exactly what a
        // concurrent upload does — so the insert violates the unique index; the
        // second attempt gets the real next number and wins.
        $service = $this->serviceWithScriptedNumbering();
        $service->shouldReceive('nextNumber')->twice()->andReturn(1, 2);

        $revision = $service->upload($script, $this->zipFile(), 'Second', $this->uploader);

        $this->assertSame(2, $revision->number);
        $this->assertSame(2, Revision::query()->where('revisable_id', $script->id)->count());
    }

    public function test_a_database_error_that_is_not_a_unique_violation_is_not_retried(): void
    {
        $script = Script::factory()->create();

        $previous = new \PDOException('SQLSTATE[42S22]: Column not found');
        $previous->errorInfo = ['42S22', 1054, 'Unknown column'];

        $service = $this->serviceWithScriptedNumbering();
        // once(): the loop must rethrow straight away rather than make a second pass.
        $service->shouldReceive('nextNumber')
            ->once()
            ->andThrow(new QueryException('sqlite', 'select 1', [], $previous));

        $this->expectException(QueryException::class);

        try {
            $service->upload($script, $this->zipFile(), 'Broken', $this->uploader);
        } finally {
            $this->assertSame(0, Revision::query()->where('revisable_id', $script->id)->count());
        }
    }

    public function test_a_draft_can_be_released_and_then_deprecated(): void
    {
        $script = Script::factory()->create();
        $revision = $this->service()->upload($script, $this->zipFile(), 'Ship it', $this->uploader);

        $this->service()->release($revision, $this->uploader);

        $this->assertSame(Revision::STATUS_RELEASED, $revision->fresh()->status);
        $this->assertSame($this->uploader->id, $revision->fresh()->released_by);
        $this->assertNotNull($revision->fresh()->released_at);

        $this->service()->deprecate($revision, $this->uploader);

        $this->assertSame(Revision::STATUS_DEPRECATED, $revision->fresh()->status);
        $this->assertNotNull($revision->fresh()->deprecated_at);
    }

    public function test_a_released_revision_cannot_be_released_again(): void
    {
        $script = Script::factory()->create();
        $revision = Revision::factory()->released()->create([
            'revisable_type' => $script->getMorphClass(),
            'revisable_id' => $script->id,
        ]);

        $this->expectException(InvalidRevisionTransition::class);

        $this->service()->release($revision, $this->uploader);
    }

    public function test_a_draft_cannot_be_deprecated(): void
    {
        $script = Script::factory()->create();
        $revision = Revision::factory()->create([
            'revisable_type' => $script->getMorphClass(),
            'revisable_id' => $script->id,
        ]);

        $this->expectException(InvalidRevisionTransition::class);

        $this->service()->deprecate($revision, $this->uploader);
    }

    public function test_a_deprecated_revision_has_no_path_back(): void
    {
        $script = Script::factory()->create();
        $revision = Revision::factory()->deprecated()->create([
            'revisable_type' => $script->getMorphClass(),
            'revisable_id' => $script->id,
        ]);

        $this->expectException(InvalidRevisionTransition::class);

        $this->service()->release($revision, $this->uploader);
    }

    public function test_delete_file_removes_the_stored_file(): void
    {
        $script = Script::factory()->create();
        $revision = $this->service()->upload($script, $this->zipFile(), 'Gone soon', $this->uploader);

        Storage::disk('marketplace')->assertExists($revision->disk_path);

        $this->service()->deleteFile($revision);

        Storage::disk('marketplace')->assertMissing($revision->disk_path);
    }
}
