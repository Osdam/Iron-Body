<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fase 4-A — webhook Meta/WhatsApp vivo en modo seguro (dry_run). Enruta texto
 * entrante al cerebro con auto_execute=false; respeta do_not_contact y human
 * takeover; valida phone_number_id; nunca activa membresía ni envía real.
 */
class InboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = '123456';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('meta.verify_token', 'verify-tok');
        config()->set('meta.webhook_secret', 'wsecret');
        config()->set('meta.whatsapp_phone_number_id', self::PHONE_ID);
        config()->set('marketing.ai.driver', 'fake'); // sin OpenAI real
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.agent_enabled', false);
        config()->set('marketing.inbound.auto_analyze', true);
        config()->set('marketing.inbound.auto_execute', false);
        Http::fake(); // ningún envío real debe ocurrir
    }

    private function postMeta(array $payload): TestResponse
    {
        $raw = json_encode($payload);
        $sig = 'sha256='.hash_hmac('sha256', $raw, 'wsecret');

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $sig,
            'CONTENT_TYPE'             => 'application/json',
        ], $raw);
    }

    private function textPayload(string $id, string $from, string $body, string $phoneId = self::PHONE_ID, string $type = 'text'): array
    {
        $message = ['from' => $from, 'id' => $id, 'timestamp' => '1700000000', 'type' => $type];
        if ($type === 'text') {
            $message['text'] = ['body' => $body];
        }

        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['display_phone_number' => '+573000000000', 'phone_number_id' => $phoneId],
            'contacts' => [['profile' => ['name' => 'Tester'], 'wa_id' => $from]],
            'messages' => [$message],
        ]]]]]];
    }

    // ── Verificación GET ──────────────────────────────────────────────────────

    public function test_get_verification_success(): void
    {
        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-tok&hub_challenge=CH123')
            ->assertOk()->assertSee('CH123');
    }

    public function test_get_verification_forbidden_with_wrong_token(): void
    {
        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=WRONG&hub_challenge=CH123')
            ->assertStatus(403);
    }

    // ── POST mensajes ─────────────────────────────────────────────────────────

    public function test_text_message_creates_lead_conversation_message(): void
    {
        $this->postMeta($this->textPayload('wamid.1', '573150536026', 'hola, info'))->assertOk();

        $this->assertDatabaseHas('marketing_leads', ['channel' => 'whatsapp', 'meta_user_id' => '573150536026']);
        $this->assertDatabaseHas('marketing_messages', ['meta_message_id' => 'wamid.1', 'direction' => 'inbound']);
        Http::assertNothingSent();
    }

    public function test_text_message_is_analyzed_proposed_not_executed(): void
    {
        $this->postMeta($this->textPayload('wamid.2', '573150536026', 'mándame el link de pago'))->assertOk();

        $lead = MarketingLead::where('meta_user_id', '573150536026')->first();
        // Se analizó: hay decisión 'proposed' (auto_execute=false).
        $this->assertDatabaseHas('marketing_ai_actions', ['lead_id' => $lead->id, 'status' => 'proposed']);
        // Aunque pida link, NO se ejecuta ni se crea transacción (agente off).
        $this->assertDatabaseCount('payment_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_duplicate_message_does_not_duplicate_nor_reanalyze(): void
    {
        $payload = $this->textPayload('wamid.DUP', '573150536026', 'hola');
        $this->postMeta($payload)->assertOk();
        $actionsAfterFirst = MarketingAiAction::count();

        $this->postMeta($payload)->assertOk(); // reentrega idéntica

        $this->assertSame(1, MarketingMessage::where('meta_message_id', 'wamid.DUP')->count());
        $this->assertSame($actionsAfterFirst, MarketingAiAction::count()); // no re-analiza
    }

    public function test_status_update_does_not_create_lead_or_call_ai(): void
    {
        // Mensaje saliente previo.
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'meta_user_id' => '573150536026', 'phone' => '573150536026', 'status' => 'new']);
        $conv = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'ai_enabled' => true]);
        MarketingMessage::create(['conversation_id' => $conv->id, 'direction' => 'outbound', 'sender_type' => 'ai', 'body' => 'hola', 'meta_message_id' => 'wamid.OUT', 'status' => 'sent']);

        $leadsBefore = MarketingLead::count();

        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata'  => ['phone_number_id' => self::PHONE_ID],
            'statuses'  => [['id' => 'wamid.OUT', 'status' => 'delivered', 'recipient_id' => '573150536026', 'timestamp' => '1700000001']],
        ]]]]]];

        $this->postMeta($payload)->assertOk();

        $this->assertSame($leadsBefore, MarketingLead::count()); // no crea lead
        $this->assertSame('delivered', MarketingMessage::where('meta_message_id', 'wamid.OUT')->first()->status);
        $this->assertDatabaseMissing('marketing_ai_actions', ['action_type' => 'reply']);
    }

    public function test_unsupported_media_is_recorded_conservatively(): void
    {
        $this->postMeta($this->textPayload('wamid.IMG', '573150536026', '', self::PHONE_ID, 'image'))->assertOk();

        $lead = MarketingLead::where('meta_user_id', '573150536026')->first();
        $this->assertDatabaseHas('marketing_ai_actions', ['lead_id' => $lead->id, 'action_type' => 'unsupported_message']);
        // No se generó una decisión normal de venta.
        $this->assertDatabaseMissing('marketing_ai_actions', ['lead_id' => $lead->id, 'action_type' => 'reply']);
        Http::assertNothingSent();
    }

    public function test_phone_number_id_mismatch_is_ignored(): void
    {
        $this->postMeta($this->textPayload('wamid.X', '573150536026', 'hola', '999999'))->assertOk();

        $this->assertDatabaseCount('marketing_leads', 0); // ignorado, no procesa
    }

    public function test_do_not_contact_blocks_ai(): void
    {
        MarketingLead::create(['channel' => 'whatsapp', 'meta_user_id' => '573150536026', 'status' => 'new', 'do_not_contact' => true]);

        $this->postMeta($this->textPayload('wamid.DNC', '573150536026', 'cuánto vale?'))->assertOk();

        $lead = MarketingLead::where('meta_user_id', '573150536026')->first();
        $this->assertDatabaseHas('marketing_ai_actions', ['lead_id' => $lead->id, 'action_type' => 'inbound_skipped']);
        $this->assertDatabaseMissing('marketing_ai_actions', ['lead_id' => $lead->id, 'action_type' => 'reply']);
    }

    public function test_human_takeover_blocks_ai(): void
    {
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'meta_user_id' => '573150536026', 'status' => 'new']);
        MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'human_takeover' => true, 'ai_enabled' => false]);

        $this->postMeta($this->textPayload('wamid.HT', '573150536026', 'cuánto vale?'))->assertOk();

        $this->assertDatabaseHas('marketing_ai_actions', ['lead_id' => $lead->id, 'action_type' => 'inbound_skipped']);
    }

    public function test_pricing_deterministic_works_via_webhook(): void
    {
        Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);

        $this->postMeta($this->textPayload('wamid.P', '573150536026', 'cuánto vale el plan mensual?'))->assertOk();

        $lead = MarketingLead::where('meta_user_id', '573150536026')->first();
        $action = MarketingAiAction::where('lead_id', $lead->id)->where('action_type', 'reply')->first();
        $this->assertNotNull($action);
        // La decisión se guardó; el precio real va en la respuesta determinista.
        $this->assertSame(SalesIntents::PRICING_QUESTION, $action->metadata['intent'] ?? null);
    }
}
