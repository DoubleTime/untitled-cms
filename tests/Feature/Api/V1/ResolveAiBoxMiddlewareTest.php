<?php

namespace Tests\Feature\Api\V1;

use App\Http\Middleware\ResolveAiBox;
use App\Models\AiBox;
use App\Models\Customer;
use App\Services\Marketplace\AiBoxService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The middleware is what makes a block take effect at once instead of when the
 * 30-day token expires, so every case here goes through a real token issued by
 * POST /api/v1/login.
 */
class ResolveAiBoxMiddlewareTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_blocking_the_box_after_login_refuses_the_next_request(): void
    {
        $user = $this->customerUser();
        $token = $this->tokenFor($user);

        $this->asToken($token)->getJson('/api/v1/me')->assertOk();

        AiBox::where('motherboard_uuid', self::UUID)->update(['status' => AiBox::STATUS_BLOCKED]);

        $response = $this->asToken($token)->getJson('/api/v1/machine-brands')->assertStatus(403);

        $this->assertStringContainsString('blocked', $response->json('message'));
    }

    public function test_deleting_the_box_after_login_refuses_the_next_request(): void
    {
        $user = $this->customerUser();
        $token = $this->tokenFor($user);

        AiBox::where('motherboard_uuid', self::UUID)->delete();

        $this->asToken($token)->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('message', ResolveAiBox::BOX_UNKNOWN);
    }

    public function test_deactivating_the_user_after_login_refuses_the_next_request(): void
    {
        $user = $this->customerUser();
        $token = $this->tokenFor($user);

        $user->forceFill(['is_active' => false])->save();

        $this->asToken($token)->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('message', ResolveAiBox::ACCOUNT_REFUSED);
    }

    public function test_deactivating_the_customer_after_login_refuses_the_next_request(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->customerUser($customer);
        $token = $this->tokenFor($user);

        $customer->forceFill(['is_active' => false])->save();

        $this->asToken($token)->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('message', ResolveAiBox::ACCOUNT_REFUSED);
    }

    public function test_unlinking_the_user_from_its_customer_refuses_the_next_request(): void
    {
        $user = $this->customerUser();
        $token = $this->tokenFor($user);

        $user->forceFill(['customer_id' => null])->save();

        $this->asToken($token)->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('message', ResolveAiBox::ACCOUNT_REFUSED);
    }

    public function test_moving_the_box_to_another_customer_refuses_the_next_request(): void
    {
        $user = $this->customerUser();
        $token = $this->tokenFor($user);

        $other = Customer::factory()->create();
        AiBox::where('motherboard_uuid', self::UUID)->update(['customer_id' => $other->id]);

        $response = $this->asToken($token)->getJson('/api/v1/me')->assertStatus(403);

        $this->assertStringContainsString('different Customer', $response->json('message'));
    }

    public function test_the_box_is_touched_but_not_on_every_single_request(): void
    {
        $user = $this->customerUser();
        $token = $this->tokenFor($user);

        $box = AiBox::where('motherboard_uuid', self::UUID)->firstOrFail();
        $box->forceFill(['last_seen_at' => now()->subHour(), 'last_ip' => '127.0.0.1'])->save();

        $this->asToken($token)->getJson('/api/v1/me')->assertOk();

        $box->refresh();
        $this->assertTrue($box->last_seen_at->greaterThan(now()->subMinute()));

        // A second request within the throttle window must not write again.
        $pinned = now()->subSeconds(AiBoxService::TOUCH_INTERVAL_SECONDS - 10);
        $box->forceFill(['last_seen_at' => $pinned, 'last_ip' => '127.0.0.1'])->save();

        $this->asToken($token)->getJson('/api/v1/me')->assertOk();

        $this->assertSame(
            $pinned->toDateTimeString(),
            $box->fresh()->last_seen_at->toDateTimeString(),
        );
    }
}
