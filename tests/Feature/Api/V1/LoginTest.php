<?php

namespace Tests\Feature\Api\V1;

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Middleware\ResolveAiBox;
use App\Models\AiBox;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LoginTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_a_customer_user_gets_a_token_and_the_full_identity_payload(): void
    {
        $customer = Customer::factory()->create(['company' => 'Inari Amertron']);
        $user = $this->customerUser($customer, ['name' => 'Aina', 'email' => 'aina@inari.test']);

        $response = $this->login($user, self::UUID, 'Line 3 box');

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Aina')
            ->assertJsonPath('user.email', 'aina@inari.test')
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('customer.company', 'Inari Amertron')
            ->assertJsonPath('ai_box.motherboard_uuid', self::UUID)
            ->assertJsonPath('ai_box.name', 'Line 3 box')
            ->assertJsonPath('ai_box.status', AiBox::STATUS_PENDING)
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user', 'customer', 'ai_box']);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_the_token_expires_after_the_configured_ttl_and_is_named_after_the_box(): void
    {
        $user = $this->customerUser();

        $this->login($user)->assertOk();

        $token = $user->tokens()->firstOrFail();

        $this->assertSame(self::UUID, $token->name);
        $this->assertNotNull($token->expires_at);
        $this->assertEqualsWithDelta(
            now()->addDays((int) config('marketplace.token_ttl_days'))->timestamp,
            $token->expires_at->timestamp,
            5,
        );
    }

    public function test_the_motherboard_uuid_is_normalised(): void
    {
        $user = $this->customerUser();

        $this->login($user, '  '.strtoupper(self::UUID).' ')
            ->assertOk()
            ->assertJsonPath('ai_box.motherboard_uuid', self::UUID);

        $this->assertDatabaseHas('ai_boxes', ['motherboard_uuid' => self::UUID]);
    }

    public function test_a_wrong_password_fails_with_the_generic_message(): void
    {
        $user = $this->customerUser();

        $this->login($user, self::UUID, null, 'not-the-password')
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', AuthController::CREDENTIALS_MESSAGE);
    }

    public function test_an_unknown_email_fails_with_the_same_generic_message(): void
    {
        $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.test',
            'password' => 'password',
            'motherboard_uuid' => self::UUID,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', AuthController::CREDENTIALS_MESSAGE);
    }

    public function test_the_motherboard_uuid_is_required(): void
    {
        $user = $this->customerUser();

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motherboard_uuid');
    }

    public function test_an_inactive_user_is_refused(): void
    {
        $user = $this->customerUser(null, ['is_active' => false]);

        $this->login($user)
            ->assertStatus(403)
            ->assertJsonPath('message', AuthController::INACTIVE_MESSAGE);

        $this->assertDatabaseCount('ai_boxes', 0);
    }

    public function test_a_team_member_is_refused(): void
    {
        $user = $this->teamMember();

        $this->login($user)
            ->assertStatus(403)
            ->assertJsonPath('message', ResolveAiBox::ACCOUNT_REFUSED);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_customer_user_of_an_inactive_customer_is_refused(): void
    {
        $customer = Customer::factory()->create(['is_active' => false]);
        $user = $this->customerUser($customer);

        $this->login($user)
            ->assertStatus(403)
            ->assertJsonPath('message', ResolveAiBox::ACCOUNT_REFUSED);
    }

    public function test_a_blocked_box_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->customerUser($customer);

        AiBox::factory()->blocked()->create([
            'customer_id' => $customer->id,
            'motherboard_uuid' => self::UUID,
        ]);

        $response = $this->login($user)->assertStatus(403);

        $this->assertStringContainsString('blocked', $response->json('message'));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_box_registered_to_another_customer_is_refused_and_never_reassigned(): void
    {
        $other = Customer::factory()->create();
        $box = AiBox::factory()->create([
            'customer_id' => $other->id,
            'motherboard_uuid' => self::UUID,
        ]);

        $user = $this->customerUser();

        $response = $this->login($user)->assertStatus(403);

        $this->assertStringContainsString('different Customer', $response->json('message'));
        $this->assertSame($other->id, $box->fresh()->customer_id);
    }

    public function test_an_unknown_box_is_created_as_pending_with_the_first_user_recorded(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->customerUser($customer);

        $this->login($user, self::UUID, 'Cell A')->assertOk();

        $box = AiBox::where('motherboard_uuid', self::UUID)->firstOrFail();

        $this->assertSame($customer->id, $box->customer_id);
        $this->assertSame(AiBox::STATUS_PENDING, $box->status);
        $this->assertSame($user->id, $box->first_user_id);
        $this->assertSame('Cell A', $box->name);
        $this->assertNotNull($box->last_seen_at);
        $this->assertNotNull($box->last_ip);
    }

    public function test_an_existing_box_is_reused_and_its_last_seen_is_bumped(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->customerUser($customer);

        $box = AiBox::factory()->active()->create([
            'customer_id' => $customer->id,
            'motherboard_uuid' => self::UUID,
            'name' => 'Labelled by a Team Member',
            'last_seen_at' => now()->subWeek(),
            'last_ip' => '10.0.0.1',
        ]);

        $this->login($user, self::UUID, 'Whatever the client calls itself')
            ->assertOk()
            ->assertJsonPath('ai_box.id', $box->id)
            ->assertJsonPath('ai_box.status', AiBox::STATUS_ACTIVE);

        $box->refresh();

        $this->assertSame(1, AiBox::count());
        $this->assertSame('Labelled by a Team Member', $box->name);
        $this->assertTrue($box->last_seen_at->greaterThan(now()->subMinute()));
    }

    public function test_login_is_throttled_at_five_per_minute(): void
    {
        $user = $this->customerUser();

        for ($i = 0; $i < 5; $i++) {
            $this->login($user, self::UUID, null, 'wrong')->assertStatus(422);
        }

        $this->login($user, self::UUID, null, 'wrong')->assertStatus(429);
    }
}
