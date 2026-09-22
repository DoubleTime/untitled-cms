<?php

namespace Tests\Unit;

use App\Exceptions\Marketplace\AiBoxBelongsToAnotherCustomer;
use App\Exceptions\Marketplace\AiBoxBlocked;
use App\Models\AiBox;
use App\Models\Customer;
use App\Models\User;
use App\Services\Marketplace\AiBoxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiBoxServiceTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '4c4c4544-0037-5810-8043-b4c04f504433';

    private function service(): AiBoxService
    {
        return app(AiBoxService::class);
    }

    public function test_it_normalises_the_motherboard_uuid(): void
    {
        $this->assertSame(self::UUID, AiBoxService::normaliseUuid('  '.strtoupper(self::UUID)."\n"));
    }

    public function test_it_creates_a_pending_box_on_first_sight(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);

        $box = $this->service()->resolve($customer, strtoupper(self::UUID), 'Cell A', '10.0.0.9', $user);

        $this->assertSame(self::UUID, $box->motherboard_uuid);
        $this->assertSame($customer->id, $box->customer_id);
        $this->assertSame(AiBox::STATUS_PENDING, $box->status);
        $this->assertSame($user->id, $box->first_user_id);
        $this->assertSame('Cell A', $box->name);
        $this->assertSame('10.0.0.9', $box->last_ip);
        $this->assertNotNull($box->last_seen_at);
    }

    public function test_a_box_created_without_a_name_keeps_a_null_name(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);

        $box = $this->service()->resolve($customer, self::UUID, '  ', '10.0.0.9', $user);

        $this->assertNull($box->name);
    }

    public function test_it_reuses_an_existing_box_and_never_overwrites_a_team_member_label(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);

        $existing = AiBox::factory()->active()->create([
            'customer_id' => $customer->id,
            'motherboard_uuid' => self::UUID,
            'name' => 'Labelled',
            'last_seen_at' => now()->subWeek(),
            'last_ip' => '10.0.0.1',
        ]);

        $box = $this->service()->resolve($customer, self::UUID, 'Client name', '10.0.0.2', $user);

        $this->assertSame($existing->id, $box->id);
        $this->assertSame('Labelled', $box->name);
        $this->assertSame('10.0.0.2', $box->last_ip);
        $this->assertTrue($box->last_seen_at->greaterThan(now()->subMinute()));
        $this->assertSame(1, AiBox::count());
    }

    public function test_it_fills_a_blank_name_from_the_client(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);

        AiBox::factory()->create([
            'customer_id' => $customer->id,
            'motherboard_uuid' => self::UUID,
            'name' => null,
        ]);

        $box = $this->service()->resolve($customer, self::UUID, 'Cell B', '10.0.0.2', $user);

        $this->assertSame('Cell B', $box->name);
    }

    public function test_it_refuses_a_box_registered_to_another_customer(): void
    {
        $owner = Customer::factory()->create();
        $other = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $other->id]);

        AiBox::factory()->create([
            'customer_id' => $owner->id,
            'motherboard_uuid' => self::UUID,
        ]);

        $this->expectException(AiBoxBelongsToAnotherCustomer::class);

        $this->service()->resolve($other, self::UUID, null, '10.0.0.2', $user);
    }

    public function test_it_refuses_a_blocked_box(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);

        AiBox::factory()->blocked()->create([
            'customer_id' => $customer->id,
            'motherboard_uuid' => self::UUID,
        ]);

        $this->expectException(AiBoxBlocked::class);

        $this->service()->resolve($customer, self::UUID, null, '10.0.0.2', $user);
    }

    public function test_touch_writes_when_the_box_is_stale(): void
    {
        $box = AiBox::factory()->create([
            'last_seen_at' => now()->subHour(),
            'last_ip' => '10.0.0.1',
        ]);

        $this->service()->touch($box, '10.0.0.2');

        $box->refresh();

        $this->assertSame('10.0.0.2', $box->last_ip);
        $this->assertTrue($box->last_seen_at->greaterThan(now()->subMinute()));
    }

    public function test_touch_skips_the_write_inside_the_throttle_window(): void
    {
        $seen = now()->subSeconds(AiBoxService::TOUCH_INTERVAL_SECONDS - 10);

        $box = AiBox::factory()->create([
            'last_seen_at' => $seen,
            'last_ip' => '10.0.0.1',
        ]);

        $this->service()->touch($box, '10.0.0.1');

        $this->assertSame(
            $seen->toDateTimeString(),
            $box->fresh()->last_seen_at->toDateTimeString(),
        );
    }

    public function test_touch_writes_when_the_ip_changed_even_inside_the_window(): void
    {
        $box = AiBox::factory()->create([
            'last_seen_at' => now()->subSeconds(5),
            'last_ip' => '10.0.0.1',
        ]);

        $this->service()->touch($box, '10.0.0.7');

        $this->assertSame('10.0.0.7', $box->fresh()->last_ip);
    }
}
