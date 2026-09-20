<?php

namespace Tests\Feature;

use App\Jobs\ProcessEmailWebhook;
use App\Models\EmailLog;
use App\Models\SuppressedEmail;
use App\Models\User;
use App\Services\EmailWebhooks\Contracts\WebhookProvider;
use App\Services\EmailWebhooks\MailgunWebhookProvider;
use App\Services\EmailWebhooks\SendGridWebhookProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    // ─── Signature verification ───────────────────────────────────────────────

    public function test_resend_valid_signature_dispatches_job(): void
    {
        Queue::fake();

        $secret = base64_encode('testsecretbytes_16b');
        config(['services.email_webhook.provider' => 'resend']);
        config(['services.email_webhook.resend.webhook_secret' => 'whsec_'.$secret]);

        $payload = ['type' => 'email.delivered', 'data' => ['email_id' => 're_abc']];
        $body = json_encode($payload);
        $msgId = 'msg_001';
        $ts = time();
        $toSign = "{$msgId}.{$ts}.{$body}";
        $sig = 'v1,'.base64_encode(hash_hmac('sha256', $toSign, base64_decode($secret), true));

        $this->withHeaders([
            'svix-id' => $msgId,
            'svix-timestamp' => $ts,
            'svix-signature' => $sig,
        ])->postJson('/webhooks/email', $payload)->assertStatus(202);

        Queue::assertPushed(ProcessEmailWebhook::class);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        Queue::fake();

        config(['services.email_webhook.provider' => 'resend']);
        config(['services.email_webhook.resend.webhook_secret' => 'whsec_'.base64_encode('testsecretbytes_16b')]);

        $this->withHeaders([
            'svix-id' => 'msg_001',
            'svix-timestamp' => time(),
            'svix-signature' => 'v1,badsignature',
        ])->postJson('/webhooks/email', ['type' => 'email.delivered'])
            ->assertStatus(400);

        Queue::assertNothingPushed();
    }

    public function test_mailgun_valid_signature_dispatches_job(): void
    {
        Queue::fake();

        config(['services.email_webhook.provider' => 'mailgun']);
        config(['services.email_webhook.mailgun.webhook_signing_key' => 'testkey']);

        $timestamp = time();
        $token = 'testtoken';
        $signature = hash_hmac('sha256', $timestamp.$token, 'testkey');

        $payload = [
            'signature' => compact('timestamp', 'token', 'signature'),
            'event-data' => [
                'event' => 'delivered',
                'message' => ['headers' => ['message-id' => '<msg@example.com>']],
                'recipient' => 'user@example.com',
            ],
        ];

        $this->postJson('/webhooks/email', $payload)->assertStatus(202);

        Queue::assertPushed(ProcessEmailWebhook::class);
    }

    // ─── Event normalisation & processing (via mocked provider) ──────────────

    public function test_delivered_event_updates_email_log(): void
    {
        $this->mockProvider(fn ($mock) => $mock
            ->shouldReceive('verifySignature')->andReturn(true)
            ->shouldReceive('normalizeEvent')->andReturn([
                'status' => 'delivered',
                'message_id' => 'msg_deliver',
                'recipient' => 'user@example.com',
                'reason' => null,
                'raw_type' => 'email.delivered',
                'data' => [],
            ])
        );

        $job = new ProcessEmailWebhook([]);
        app()->call([$job, 'handle'], ['provider' => app(WebhookProvider::class)]);

        $log = EmailLog::where('provider_message_id', 'msg_deliver')->first();
        $this->assertNotNull($log);
        $this->assertEquals('delivered', $log->status);
        $this->assertEquals('user@example.com', $log->recipient);
    }

    public function test_bounce_suppresses_email_and_flags_user(): void
    {
        $user = User::factory()->create(['email' => 'bounced@example.com']);

        $this->mockProvider(fn ($mock) => $mock
            ->shouldReceive('verifySignature')->andReturn(true)
            ->shouldReceive('normalizeEvent')->andReturn([
                'status' => 'bounced',
                'message_id' => 'msg_bounce',
                'recipient' => 'bounced@example.com',
                'reason' => 'bounced_hard',
                'raw_type' => 'email.bounced',
                'data' => [],
            ])
        );

        $job = new ProcessEmailWebhook([]);
        app()->call([$job, 'handle'], ['provider' => app(WebhookProvider::class)]);

        $suppressed = SuppressedEmail::where('email', 'bounced@example.com')->first();
        $this->assertNotNull($suppressed);
        $this->assertEquals('bounced_hard', $suppressed->reason);
        $this->assertTrue($user->fresh()->bounce_hard);
    }

    public function test_soft_bounce_does_not_flag_user(): void
    {
        $user = User::factory()->create(['email' => 'soft@example.com']);

        $this->mockProvider(fn ($mock) => $mock
            ->shouldReceive('verifySignature')->andReturn(true)
            ->shouldReceive('normalizeEvent')->andReturn([
                'status' => 'bounced',
                'message_id' => 'msg_soft',
                'recipient' => 'soft@example.com',
                'reason' => 'bounced_soft',
                'raw_type' => 'failed',
                'data' => [],
            ])
        );

        $job = new ProcessEmailWebhook([]);
        app()->call([$job, 'handle'], ['provider' => app(WebhookProvider::class)]);

        $this->assertFalse($user->fresh()->bounce_hard);
    }

    public function test_sendgrid_batch_payload_processes_all_events(): void
    {
        // Two events in a single SendGrid batch — both must produce a log record.
        $this->mockProvider(fn ($mock) => $mock
            ->shouldReceive('verifySignature')->andReturn(true)
            ->shouldReceive('normalizeEvent')
            ->with(\Mockery::on(fn ($p) => ($p['sg_message_id'] ?? null) === 'sg_001'))
            ->andReturn(['status' => 'delivered', 'message_id' => 'sg_001', 'recipient' => 'a@example.com', 'reason' => null, 'raw_type' => 'delivered', 'data' => []])
            ->shouldReceive('normalizeEvent')
            ->with(\Mockery::on(fn ($p) => ($p['sg_message_id'] ?? null) === 'sg_002'))
            ->andReturn(['status' => 'opened', 'message_id' => 'sg_002', 'recipient' => 'b@example.com', 'reason' => null, 'raw_type' => 'open', 'data' => []])
        );

        $batch = [
            ['event' => 'delivered', 'sg_message_id' => 'sg_001', 'email' => 'a@example.com'],
            ['event' => 'open',      'sg_message_id' => 'sg_002', 'email' => 'b@example.com'],
        ];

        $job = new ProcessEmailWebhook($batch);
        app()->call([$job, 'handle'], ['provider' => app(WebhookProvider::class)]);

        // Both IDs should appear in email_logs
        $this->assertEquals(2, EmailLog::whereIn('provider_message_id', ['sg_001', 'sg_002'])->count());
    }

    public function test_unknown_event_type_is_ignored(): void
    {
        $this->mockProvider(fn ($mock) => $mock
            ->shouldReceive('verifySignature')->andReturn(true)
            ->shouldReceive('normalizeEvent')->andReturn(null)
        );

        $job = new ProcessEmailWebhook([]);
        app()->call([$job, 'handle'], ['provider' => app(WebhookProvider::class)]);

        $this->assertEquals(0, EmailLog::count());
    }

    // ─── Unit: SendGrid sg_message_id suffix stripping ────────────────────────

    public function test_sendgrid_strips_filter_suffix_from_message_id(): void
    {
        $provider = new SendGridWebhookProvider;

        $result = $provider->normalizeEvent([
            'event' => 'delivered',
            'sg_message_id' => 'abc123.filter-sendgrid.net',
            'email' => 'user@example.com',
        ]);

        $this->assertEquals('abc123', $result['message_id']);
    }

    // ─── Unit: Mailgun uses RFC Message-ID for correlation ────────────────────

    public function test_mailgun_normalizes_event_using_message_header_id(): void
    {
        $provider = new MailgunWebhookProvider;

        $result = $provider->normalizeEvent([
            'event-data' => [
                'event' => 'delivered',
                'message' => ['headers' => ['message-id' => '<abc@mg.example.com>']],
                'recipient' => 'user@example.com',
            ],
        ]);

        $this->assertEquals('<abc@mg.example.com>', $result['message_id']);
    }

    public function test_mailgun_soft_bounce_sets_correct_reason(): void
    {
        $provider = new MailgunWebhookProvider;

        $result = $provider->normalizeEvent([
            'event-data' => [
                'event' => 'failed',
                'severity' => 'temporary',
                'message' => ['headers' => ['message-id' => '<soft@mg.example.com>']],
                'recipient' => 'user@example.com',
            ],
        ]);

        $this->assertEquals('bounced_soft', $result['reason']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function mockProvider(\Closure $configure): void
    {
        $this->mock(WebhookProvider::class, $configure);
    }
}
