<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 1.6 — readiness del webhook Meta (/api/webhooks/meta). El challenge usa
 * META_VERIFY_TOKEN y la firma usa META_WEBHOOK_SECRET; ambos funcionan aunque
 * META_ENABLED esté en false. POST idempotente por meta_message_id.
 */
class MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('meta.verify_token', 'verify-tok-123');
        config()->set('meta.webhook_secret', 'wsecret-xyz');
    }

    public function test_get_challenge_succeeds_with_correct_verify_token(): void
    {
        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-tok-123&hub_challenge=CH4LLENGE')
            ->assertOk()
            ->assertSee('CH4LLENGE');
    }

    public function test_get_challenge_rejects_wrong_verify_token(): void
    {
        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=WRONG&hub_challenge=CH4LLENGE')
            ->assertStatus(403);
    }

    public function test_post_rejects_invalid_signature(): void
    {
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);

        $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=deadbeef',
            'CONTENT_TYPE'             => 'application/json',
        ], $raw)->assertStatus(403);
    }

    public function test_post_valid_signature_processes_and_is_idempotent(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['profile' => ['name' => 'Tester'], 'wa_id' => '573150536026']],
                        'messages' => [[
                            'from' => '573150536026', 'id' => 'wamid.IDEMPO1',
                            'text' => ['body' => 'Hola, info de planes'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $raw = json_encode($payload);
        $sig = 'sha256='.hash_hmac('sha256', $raw, 'wsecret-xyz');

        $server = [
            'HTTP_X-Hub-Signature-256' => $sig,
            'CONTENT_TYPE'             => 'application/json',
        ];

        $this->call('POST', '/api/webhooks/meta', [], [], [], $server, $raw)->assertOk();

        // Reentrega del MISMO evento → no duplica (idempotencia por meta_message_id).
        $this->call('POST', '/api/webhooks/meta', [], [], [], $server, $raw)->assertOk();

        $this->assertSame(1, MarketingMessage::where('meta_message_id', 'wamid.IDEMPO1')->count());
    }
}
