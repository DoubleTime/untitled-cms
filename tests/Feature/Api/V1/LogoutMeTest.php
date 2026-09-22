<?php

namespace Tests\Feature\Api\V1;

use App\Models\AiBox;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LogoutMeTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_get_a_json_401(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_an_unauthenticated_request_without_an_accept_header_is_still_json(): void
    {
        $response = $this->get('/api/v1/me');

        $response->assertStatus(401);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_me_returns_the_identity_payload_and_the_token_expiry(): void
    {
        $customer = Customer::factory()->create(['company' => 'Plexus']);
        $user = $this->customerUser($customer);
        $token = $this->tokenFor($user, self::UUID, 'Cell B');

        $this->asToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('customer.company', 'Plexus')
            ->assertJsonPath('ai_box.motherboard_uuid', self::UUID)
            ->assertJsonPath('ai_box.name', 'Cell B')
            ->assertJsonPath('ai_box.status', AiBox::STATUS_PENDING)
            ->assertJsonStructure(['user', 'customer', 'ai_box', 'token_expires_at']);
    }

    public function test_logout_deletes_only_the_current_token(): void
    {
        $user = $this->customerUser();

        $first = $this->tokenFor($user, self::UUID);
        $second = $this->tokenFor($user, '11111111-2222-3333-4444-555555555555');

        $this->assertSame(2, $user->tokens()->count());

        $this->asToken($first)->postJson('/api/v1/logout')->assertNoContent();

        $this->assertSame(1, $user->tokens()->count());

        $this->asToken($first)->getJson('/api/v1/me')->assertStatus(401);
        $this->asToken($second)->getJson('/api/v1/me')->assertOk();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $user = $this->customerUser();
        $token = $this->tokenFor($user);

        $user->tokens()->update(['expires_at' => now()->subMinute()]);

        $this->asToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }
}
