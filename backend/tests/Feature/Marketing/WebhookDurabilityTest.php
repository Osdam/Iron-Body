<?php

namespace Tests\Feature\Marketing;

use App\Jobs\ProcessMetaWebhookEvent;
use App\Models\MarketingMessage;
use App\Models\MetaWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El canal no puede perder el mensaje de un prospecto.
 *
 * Antes, el webhook validaba la firma y despachaba el payload a la cola: si el
 * worker estaba caído, ese mensaje no existía en ninguna parte. Ahora el hecho
 * original se guarda de forma síncrona y todo lo demás puede fallar y
 * reintentarse. Estas pruebas fijan esa garantía y sus bordes: reentregas,
 * replays, cuerpos gigantes y payloads con varios eventos dentro.
 */
class WebhookDurabilityTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = '123456';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('meta.webhook_secret', 'wsecret');
        config()->set('meta.whatsapp_phone_number_id', self::PHONE_ID);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', true);
        config()->set('marketing.inbound.auto_execute', false);
        config()->set('marketing.agent_enabled', false);
        Http::fake();
    }

    private function postRaw(string $raw, ?string $signature = null): TestResponse
    {
        $signature ??= 'sha256='.hash_hmac('sha256', $raw, 'wsecret');

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    private function postMeta(array $payload): TestResponse
    {
        return $this->postRaw(json_encode($payload) ?: '{}');
    }

    private function textPayload(string $id, string $body = 'precio', string $from = '573150536026'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID, 'display_phone_number' => '+573143455483'],
            'contacts' => [['profile' => ['name' => 'Prospecto'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => $body]]],
        ]]]]]];
    }

    public function test_the_raw_event_survives_even_if_no_worker_ever_runs(): void
    {
        // El worker no corre: se encolan los jobs y no se ejecutan.
        Queue::fake();

        $this->postMeta($this->textPayload('wamid.SURVIVE'))->assertOk();

        // Nada se procesó...
        $this->assertDatabaseCount('marketing_messages', 0);

        // ...pero lo que Meta dijo está guardado y es reprocesable.
        $event = MetaWebhookEvent::sole();
        $this->assertSame(MetaWebhookEvent::STATUS_PENDING, $event->status);
        $this->assertSame(1, $event->messages_count);
        $this->assertSame(self::PHONE_ID, $event->phone_number_id);
        $this->assertSame('precio', data_get($event->payload, 'entry.0.changes.0.value.messages.0.text.body'));

        Queue::assertPushed(ProcessMetaWebhookEvent::class);
    }

    public function test_meta_redelivering_the_same_body_does_not_duplicate_work(): void
    {
        $payload = $this->textPayload('wamid.DUP');

        $this->postMeta($payload)->assertOk();
        // Meta reentrega exactamente el mismo cuerpo (no recibió el 200 a tiempo).
        $this->postMeta($payload)->assertOk();

        $this->assertDatabaseCount('meta_webhook_events', 1);
        $this->assertDatabaseCount('marketing_messages', 1);
    }

    /**
     * Un atacante que capture un POST válido y lo reenvíe tal cual tiene una
     * firma perfectamente válida: es el mismo cuerpo. El hash lo detiene.
     */
    public function test_replaying_a_captured_signed_body_changes_nothing(): void
    {
        $raw = json_encode($this->textPayload('wamid.REPLAY')) ?: '{}';
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'wsecret');

        $this->postRaw($raw, $signature)->assertOk();
        $countAfterFirst = MarketingMessage::count();

        $this->postRaw($raw, $signature)->assertOk();
        $this->postRaw($raw, $signature)->assertOk();

        $this->assertDatabaseCount('meta_webhook_events', 1);
        $this->assertSame($countAfterFirst, MarketingMessage::count());
    }

    /**
     * Dos mensajes DISTINTOS del mismo prospecto son dos eventos: la barrera es
     * el cuerpo exacto, no el remitente.
     */
    public function test_two_different_messages_are_two_events(): void
    {
        $this->postMeta($this->textPayload('wamid.A', 'hola'))->assertOk();
        $this->postMeta($this->textPayload('wamid.B', 'precio'))->assertOk();

        $this->assertDatabaseCount('meta_webhook_events', 2);
        $this->assertSame(2, MarketingMessage::where('direction', 'inbound')->count());
    }

    public function test_an_absurdly_large_body_is_refused_before_anything_is_stored(): void
    {
        // 600 KB: por encima del techo de 512 KB del controlador.
        $raw = json_encode(['object' => 'whatsapp_business_account', 'filler' => str_repeat('x', 600 * 1024)]) ?: '';

        $this->postRaw($raw)->assertStatus(413);

        $this->assertDatabaseCount('meta_webhook_events', 0);
    }

    /**
     * Meta agrupa varios eventos en un POST. Quedarse con el primero —como hacía
     * el logging antiguo— pierde mensajes reales de personas reales.
     */
    public function test_every_entry_and_change_in_one_post_is_processed(): void
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [
            ['changes' => [
                ['field' => 'messages', 'value' => [
                    'metadata' => ['phone_number_id' => self::PHONE_ID],
                    'contacts' => [['profile' => ['name' => 'Uno'], 'wa_id' => '573001110001']],
                    'messages' => [['from' => '573001110001', 'id' => 'wamid.M1', 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => 'primero']]],
                ]],
                ['field' => 'messages', 'value' => [
                    'metadata' => ['phone_number_id' => self::PHONE_ID],
                    'contacts' => [['profile' => ['name' => 'Dos'], 'wa_id' => '573001110002']],
                    'messages' => [['from' => '573001110002', 'id' => 'wamid.M2', 'timestamp' => '1700000001', 'type' => 'text', 'text' => ['body' => 'segundo']]],
                ]],
            ]],
            ['changes' => [
                ['field' => 'messages', 'value' => [
                    'metadata' => ['phone_number_id' => self::PHONE_ID],
                    'contacts' => [['profile' => ['name' => 'Tres'], 'wa_id' => '573001110003']],
                    'messages' => [['from' => '573001110003', 'id' => 'wamid.M3', 'timestamp' => '1700000002', 'type' => 'text', 'text' => ['body' => 'tercero']]],
                ]],
            ]],
        ]];

        $this->postMeta($payload)->assertOk();

        $this->assertSame(3, MarketingMessage::where('direction', 'inbound')->count());
        $this->assertSame(3, MetaWebhookEvent::sole()->messages_count);

        foreach (['primero', 'segundo', 'tercero'] as $body) {
            $this->assertDatabaseHas('marketing_messages', ['body' => $body]);
        }
    }

    public function test_a_processed_event_is_marked_and_not_reprocessed(): void
    {
        $this->postMeta($this->textPayload('wamid.ONCE'))->assertOk();

        $event = MetaWebhookEvent::sole();
        $this->assertSame(MetaWebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertNotNull($event->processed_at);

        // Reprocesar el mismo evento (replay manual) no duplica nada.
        ProcessMetaWebhookEvent::dispatchSync($event->id);

        $this->assertSame(1, MarketingMessage::where('direction', 'inbound')->count());
    }

    /**
     * Un replay explícito de un evento que quedó a medias SÍ debe reprocesar,
     * y la idempotencia por meta_message_id impide que se duplique el mensaje.
     */
    public function test_replaying_a_failed_event_recovers_it_without_duplicating(): void
    {
        Queue::fake();
        $this->postMeta($this->textPayload('wamid.RECOVER'))->assertOk();
        Queue::assertPushed(ProcessMetaWebhookEvent::class);

        $event = MetaWebhookEvent::sole();
        // Simula el worker que murió a mitad del primer intento.
        $event->markFailed('RuntimeException', 'el worker se cayó');

        // Se invoca el job directamente: con la cola falseada, dispatchSync
        // también quedaría interceptado y no ejecutaría nada.
        app()->call([new ProcessMetaWebhookEvent($event->id), 'handle']);

        $this->assertSame(MetaWebhookEvent::STATUS_PROCESSED, $event->fresh()->status);
        $this->assertSame(1, MarketingMessage::where('meta_message_id', 'wamid.RECOVER')->count());
    }

    public function test_an_unparseable_or_empty_payload_is_acknowledged_without_storing(): void
    {
        $this->postRaw('{}')->assertOk();

        $this->assertDatabaseCount('meta_webhook_events', 0);
    }
}
