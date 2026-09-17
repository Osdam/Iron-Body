<?php

namespace Tests\Feature\Marketing;

use App\Jobs\SendUltronEventToN8n;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El puente CRM → ULTRON, entrando por el webhook REAL de Meta.
 *
 * Se prueba desde `POST /api/webhooks/meta` y no llamando al emisor a mano,
 * porque lo que importa no es que el método funcione: es que se dispare en el
 * punto correcto del recorrido, después de persistir y de las barreras, y que
 * NO se dispare cuando alguna de esas barreras dice que no.
 */
class UltronEventBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = '123456';

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        config()->set('meta.webhook_secret', 'wsecret');
        config()->set('meta.whatsapp_phone_number_id', self::PHONE_ID);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ai.enabled', true);
        // Los dos cerebros nunca a la vez: ULTRON encendido, cerebro local off.
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.enabled', true);
        config()->set('marketing.ultron.webhook_url', 'https://n8n.example.test/webhook/iron-body-ultron');
        config()->set('automation.webhook_secret', 'n8n-shared-secret');

        // El fake NO va aquí: `Http::fake()` FUSIONA stubs y gana el primero
        // que encaje, así que uno declarado en setUp se comería el que un test
        // concreto necesite para simular a n8n caído. Cada prueba declara el suyo.
        Http::preventStrayRequests();

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto',
            'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    /** n8n responde esto. Se declara en cada prueba, nunca en setUp. */
    private function fakeN8n(int $status = 200): void
    {
        Http::fake(['n8n.example.test/*' => Http::response($status === 200 ? ['ok' => true] : 'boom', $status)]);
    }

    /** Un entrante real, firmado como lo firma Meta. */
    private function incoming(array $message): TestResponse
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'contacts' => [['profile' => ['name' => 'Prospecto'], 'wa_id' => '573150536026']],
            'messages' => [array_merge(['from' => '573150536026', 'timestamp' => (string) now()->timestamp], $message)],
        ]]]]]];

        $raw = json_encode($payload) ?: '{}';

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'wsecret'),
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    private function text(string $body, string $wamid): array
    {
        return ['id' => $wamid, 'type' => 'text', 'text' => ['body' => $body]];
    }

    // ── El camino que sí emite ────────────────────────────────────────────────

    public function test_an_eligible_inbound_emits_exactly_one_event(): void
    {
        $this->fakeN8n();
        $this->incoming($this->text('hola, cuánto vale?', 'wamid.E1'))->assertOk();

        $evento = MarketingAutomationEvent::sole();
        $this->assertSame(MarketingAutomationEvent::TYPE_MESSAGE_RECEIVED, $evento->event_type);
        $this->assertSame($this->lead->id, (int) $evento->lead_id);
        $this->assertSame($this->conversation->id, (int) $evento->conversation_id);
        $this->assertSame('wamid.E1', $evento->idempotency_key);
    }

    public function test_the_payload_carries_no_pii(): void
    {
        $this->fakeN8n();
        $this->incoming($this->text('hola', 'wamid.E2'))->assertOk();

        $payload = MarketingAutomationEvent::sole()->payload_json;
        $json = json_encode($payload);

        $this->assertSame('INBOUND_USER', $payload['direction']);
        $this->assertSame('whatsapp', $payload['channel']);
        $this->assertSame($this->conversation->id, $payload['conversation_id']);

        foreach (['3150536026', '573150536026', 'Prospecto'] as $pii) {
            $this->assertStringNotContainsString($pii, $json, "«{$pii}» no debe salir del CRM.");
        }
        foreach (['phone', 'wa_id', 'meta_user_id', 'phone_number_id'] as $campo) {
            $this->assertArrayNotHasKey($campo, $payload);
        }
    }

    public function test_the_request_to_n8n_is_signed_and_carries_the_idempotency_key(): void
    {
        $this->fakeN8n();
        $this->incoming($this->text('hola', 'wamid.E3'))->assertOk();

        Http::assertSent(function ($request) {
            $cuerpo = $request->body();
            $firma = hash_hmac('sha256', $cuerpo, 'n8n-shared-secret');

            return $request->hasHeader('Authorization', 'Bearer n8n-shared-secret')
                && $request->hasHeader('X-IronBody-Signature', $firma)
                && $request->hasHeader('X-Idempotency-Key', 'wamid.E3')
                && $request->hasHeader('X-IronBody-Event', 'marketing.message.received');
        });

        $this->assertSame(MarketingAutomationEvent::STATUS_SENT, MarketingAutomationEvent::sole()->status);
    }

    // ── Todo lo que NO debe emitir ────────────────────────────────────────────

    public function test_the_flag_off_emits_nothing(): void
    {
        config()->set('marketing.ultron.enabled', false);

        $this->incoming($this->text('hola', 'wamid.OFF'))->assertOk();

        $this->assertSame(0, MarketingAutomationEvent::count());
        // Y el mensaje del prospecto SÍ se guardó: el puente es aditivo.
        $this->assertSame(1, MarketingMessage::where('direction', 'inbound')->count());
    }

    public function test_a_duplicate_delivery_emits_only_one_event(): void
    {
        $this->fakeN8n();
        $mensaje = $this->text('hola', 'wamid.DUP');

        $this->incoming($mensaje)->assertOk();
        $this->incoming($mensaje)->assertOk();

        $this->assertSame(1, MarketingAutomationEvent::count());
    }

    public function test_human_takeover_emits_nothing(): void
    {
        $this->conversation->update(['human_takeover' => true, 'human_takeover_source' => 'manual']);

        $this->incoming($this->text('hola', 'wamid.HT'))->assertOk();

        $this->assertSame(0, MarketingAutomationEvent::count());
    }

    public function test_do_not_contact_emits_nothing(): void
    {
        $this->lead->update(['do_not_contact' => true]);

        $this->incoming($this->text('hola', 'wamid.DNC'))->assertOk();

        $this->assertSame(0, MarketingAutomationEvent::count());
    }

    public function test_ai_disabled_emits_nothing(): void
    {
        $this->conversation->update(['ai_enabled' => false, 'human_takeover' => true, 'human_takeover_source' => 'manual']);

        $this->incoming($this->text('hola', 'wamid.AID'))->assertOk();

        $this->assertSame(0, MarketingAutomationEvent::count());
    }

    /** Un audio sin texto se escala a un humano; ULTRON no lo ve. */
    public function test_a_non_analysable_message_emits_nothing(): void
    {
        $this->incoming([
            'id' => 'wamid.AUD', 'type' => 'audio',
            'audio' => ['id' => 'media-1', 'mime_type' => 'audio/ogg', 'voice' => true],
        ])->assertOk();

        $this->assertSame(0, MarketingAutomationEvent::count());
    }

    /** Un callback de estado no es una consulta: no crea mensaje ni evento. */
    public function test_a_status_callback_emits_nothing(): void
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'statuses' => [['id' => 'wamid.ST', 'status' => 'delivered', 'recipient_id' => '573150536026',
                'timestamp' => (string) now()->timestamp]],
        ]]]]]];
        $raw = json_encode($payload) ?: '{}';

        $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'wsecret'),
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertOk();

        $this->assertSame(0, MarketingAutomationEvent::count());
    }

    /** Nuestros propios salientes jamás despiertan a ULTRON. */
    public function test_an_outbound_message_emits_nothing(): void
    {
        MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => MarketingMessage::SENDER_AI,
            'body' => 'respuesta', 'status' => 'dry_run',
        ]);

        $this->assertSame(0, MarketingAutomationEvent::count());
    }

    // ── Entrega fiable ────────────────────────────────────────────────────────

    public function test_the_event_survives_n8n_being_down(): void
    {
        $this->fakeN8n(500);

        $this->incoming($this->text('hola', 'wamid.DOWN'))->assertOk();

        $evento = MarketingAutomationEvent::sole();
        $this->assertSame(MarketingAutomationEvent::STATUS_FAILED, $evento->status);
        $this->assertSame('http_500', $evento->last_error);
        $this->assertGreaterThanOrEqual(1, $evento->attempts);

        // Lo importante: el mensaje del prospecto está guardado igualmente.
        $this->assertSame(1, MarketingMessage::where('direction', 'inbound')->count());
    }

    public function test_without_a_configured_url_the_event_is_skipped_not_failed(): void
    {
        config()->set('marketing.ultron.webhook_url', '');

        $this->incoming($this->text('hola', 'wamid.NOURL'))->assertOk();

        $this->assertSame(MarketingAutomationEvent::STATUS_SKIPPED, MarketingAutomationEvent::sole()->status);
    }

    public function test_delivery_is_dispatched_to_the_commercial_lane(): void
    {
        // Solo ESTE job. Un `Queue::fake()` a secas falsearía también
        // ProcessMetaWebhookEvent y el entrante no llegaría a procesarse.
        Queue::fake([SendUltronEventToN8n::class]);

        $this->incoming($this->text('hola', 'wamid.LANE'))->assertOk();

        Queue::assertPushed(SendUltronEventToN8n::class, function ($job) {
            return $job->queue === (config('queue.lanes.commercial.queue') ?? 'commercial');
        });
    }

    // ── El secreto nunca se escribe ───────────────────────────────────────────

    public function test_the_shared_secret_never_reaches_the_stored_event(): void
    {
        $this->fakeN8n();
        $this->incoming($this->text('hola', 'wamid.SEC'))->assertOk();

        $fila = json_encode(MarketingAutomationEvent::sole()->toArray());
        $this->assertStringNotContainsString('n8n-shared-secret', (string) $fila);
    }

    // ── Cerrojo del canario: una conversación y nada más ──────────────────────

    /**
     * Sin id de canario (lo normal), ULTRON atiende como siempre.
     */
    public function test_without_a_canary_id_nothing_changes(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', null);

        $this->incoming($this->text('hola', 'wamid.SINCANARIO'))->assertOk();

        $this->assertSame(1, MarketingAutomationEvent::count());
    }

    /** La conversación señalada sí llega a ULTRON. */
    public function test_the_canary_conversation_is_the_one_that_gets_through(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $this->incoming($this->text('hola', 'wamid.CANARIO'))->assertOk();

        $this->assertSame(1, MarketingAutomationEvent::count());
        $this->assertSame($this->conversation->id, MarketingAutomationEvent::sole()->conversation_id);
    }

    /**
     * Y cualquier otra NO, que es lo único que hace útil al cerrojo.
     *
     * El mensaje se guarda igual y sigue visible en el Inbox: lo que no ocurre
     * es que ULTRON se entere. Perder el mensaje sería perder al cliente.
     */
    public function test_any_other_conversation_is_left_out(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id + 999);

        $this->incoming($this->text('hola', 'wamid.AJENA'))->assertOk();

        $this->assertSame(0, MarketingAutomationEvent::count());
        $this->assertSame(1, MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_INBOUND)->count());
    }

    /** El interruptor general sigue mandando sobre el cerrojo. */
    public function test_the_master_switch_still_wins_over_the_canary(): void
    {
        config()->set('marketing.ultron.enabled', false);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $this->incoming($this->text('hola', 'wamid.APAGADO'))->assertOk();

        $this->assertSame(0, MarketingAutomationEvent::count());
    }

    /**
     * Ser la conversación del canario no compra ningún privilegio.
     *
     * Estas tres son las barreras que protegen a la persona, y el cerrojo se
     * añadió por encima de ellas, no en su lugar.
     */
    public function test_being_the_canary_does_not_override_the_opt_out(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $this->lead->update(['do_not_contact' => true]);

        $this->incoming($this->text('hola', 'wamid.DNC'))->assertOk();

        $this->assertSame(0, MarketingAutomationEvent::count());
    }

    public function test_being_the_canary_does_not_override_a_manual_takeover(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $this->conversation->update(['human_takeover' => true, 'human_takeover_source' => 'manual']);

        $this->incoming($this->text('hola', 'wamid.TAKEOVER'))->assertOk();

        $this->assertSame(0, MarketingAutomationEvent::count());
    }

    public function test_being_the_canary_does_not_override_ai_disabled(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $this->conversation->update(['ai_enabled' => false]);

        $this->incoming($this->text('hola', 'wamid.IAOFF'))->assertOk();

        $this->assertSame(0, MarketingAutomationEvent::count());
    }

    /** Y el duplicado se sigue deduplicando dentro del canario. */
    public function test_a_duplicate_inside_the_canary_is_still_one_event(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $this->incoming($this->text('hola', 'wamid.DUPCANARIO'))->assertOk();
        $this->incoming($this->text('hola', 'wamid.DUPCANARIO'))->assertOk();

        $this->assertSame(1, MarketingAutomationEvent::count());
    }
}
