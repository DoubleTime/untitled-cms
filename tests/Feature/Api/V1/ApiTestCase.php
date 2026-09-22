<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiModel;
use App\Models\Customer;
use App\Models\FlowchartScript;
use App\Models\Revision;
use App\Models\Role;
use App\Models\User;
use App\Services\Marketplace\RevisionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared scaffolding for the RPA-TOOL API tests.
 *
 * Tokens are always obtained through the real POST /api/v1/login rather than
 * Sanctum::actingAs, because ResolveAiBox resolves the AI Box from the token's
 * name — a transient acting-as token carries none, so the real flow is the only
 * one that exercises the lookup.
 */
abstract class ApiTestCase extends TestCase
{
    protected const UUID = '4c4c4544-0037-5810-8043-b4c04f504433';

    protected function customerUser(?Customer $customer = null, array $attributes = []): User
    {
        $customer ??= Customer::factory()->create();

        return User::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'is_active' => true,
        ], $attributes));
    }

    /** A Team Member: no Customer, and a role that grants backend access. */
    protected function teamMember(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'customer_id' => null,
            'is_active' => true,
        ], $attributes));

        $role = Role::factory()->create([
            'backend_access' => true,
            'is_active' => true,
        ]);

        $user->syncRoles([$role->id]);

        return $user;
    }

    /** POST /api/v1/login and return the raw response. */
    protected function login(User $user, string $uuid = self::UUID, ?string $boxName = null, string $password = 'password'): TestResponse
    {
        return $this->postJson('/api/v1/login', array_filter([
            'email' => $user->email,
            'password' => $password,
            'motherboard_uuid' => $uuid,
            'box_name' => $boxName,
        ], fn ($value) => $value !== null));
    }

    /** POST /api/v1/login and return the plain-text token. */
    protected function tokenFor(User $user, string $uuid = self::UUID, ?string $boxName = null): string
    {
        $response = $this->login($user, $uuid, $boxName);
        $response->assertOk();

        return $response->json('token');
    }

    /**
     * Send the next request as the holder of this token.
     *
     * The auth guard caches the resolved user for the lifetime of the container,
     * and the container survives between requests inside one test, so the cache is
     * dropped first — otherwise a second request in the same test would never
     * re-read the token at all.
     */
    protected function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** A tiny but structurally valid zip, as RevisionService's magic-byte check wants. */
    protected function zipFile(string $name = 'bundle.zip'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "PK\x03\x04".str_repeat('a', 64));
    }

    protected function h5File(string $name = 'weights.h5'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\x89HDF\r\n\x1a\n".str_repeat('a', 64));
    }

    /**
     * Upload a real Revision through RevisionService (so the file exists on the
     * faked disk and the SHA-256 is genuine) and optionally move it along the
     * lifecycle. Requires Storage::fake('marketplace') in the calling test.
     */
    protected function makeRevision(
        FlowchartScript|AiModel $entry,
        string $status = Revision::STATUS_RELEASED,
        string $note = 'Change note',
    ): Revision {
        $uploader = User::factory()->create();
        $service = app(RevisionService::class);

        $file = $entry instanceof FlowchartScript ? $this->zipFile() : $this->h5File();

        $revision = $service->upload($entry, $file, $note, $uploader);

        if ($status === Revision::STATUS_RELEASED || $status === Revision::STATUS_DEPRECATED) {
            $service->release($revision, $uploader);
        }

        if ($status === Revision::STATUS_DEPRECATED) {
            $service->deprecate($revision, $uploader);
        }

        return $revision->refresh();
    }

    protected function fakeMarketplaceDisk(): void
    {
        Storage::fake('marketplace');
    }
}
