<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Marketing\WhatsappOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lo que sale hacia Meta y qué pasa cuando no sale.
 *
 * Dos garantías se prueban aquí y son opuestas entre sí, que es justo lo
 * difícil: un fallo pasajero (un 429 por límite de tasa, algo que Meta provoca
 * a propósito cuando envías rápido) tiene que reintentarse, porque si no el
 * prospecto se queda sin respuesta para siempre; y a la vez NINGÚN reintento
 * puede escribirle dos veces a la misma persona. Cloud API no ofrece claves de
 * idempotencia, así que la defensa es no reenviar nunca un mensaje que ya tiene
 * `meta_message_id`.
 */
class OutboxDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', true);
        config()->set('meta.access_token', 'token-de-prueba');
        config()->set('meta.app_secret', 'secreto-de-prueba');
        config()->set('meta.whatsapp_phone_number_id', '123456');
        config()->set('marketing.outbox.max_attempts', 3);
        config()->set('marketing.outbox.retry_base_seconds', 30);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead', 'status' => MarketingLead::STATUS_NEW,
        ]);
        MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    private function dispatcher(): MarketingMessageDispatcher
    {
        return app(MarketingMessageDispatcher::class);
    }

    private function outbox(): WhatsappOutboxService
    {
        return app(WhatsappOutboxService::class);
    }

    private function send(string $body = 'Hola, con gusto te cuento.'): array
    {
        return $this->dispatcher()->dispatchWhatsapp($this->lead, 'whatsapp', $body);
    }

    private function fakeAccepted(string $id = 'wamid.ACCEPTED'): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => $id]]], 200)]);
    }

    /** Meta responde con el error tal como lo documenta Cloud API. */
    private function fakeError(int $httpStatus, int $code, string $message = 'error'): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => $message, 'type' => 'OAuthException', 'code' => $code],
        ], $httpStatus)]);
    }

    public function test_a_successful_send_records_metas_id(): void
    {
        $this->fakeAccepted();

        $result = $this->send();

        $this->assertTrue($result['sent']);
        $this->assertSame('wamid.ACCEPTED', $result['provider_message_id']);

        $message = MarketingMessage::find($result['message_id']);
        $this->assertSame(WhatsappOutboxService::STATUS_SENT, $message->status);
        $this->assertSame(1, $message->send_attempts);
        $this->assertNull($message->next_attempt_at);
    }

    /**
     * El mensaje existe ANTES de salir a la red. Si el proceso muriera en mitad
     * del POST, quien atiende lo vería en el inbox en vez de no ver nada.
     */
    public function test_the_message_exists_even_when_meta_refuses_it(): void
    {
        $this->fakeError(400, 131026, 'Receiver is incapable of receiving this message');

        $result = $this->send();

        $this->assertFalse($result['sent']);
        $this->assertNotNull($result['message_id']);
        $this->assertDatabaseHas('marketing_messages', [
            'id' => $result['message_id'],
            'direction' => 'outbound',
        ]);
    }

    /** Un límite de tasa es pasajero: se programa otro intento. */
    public function test_a_rate_limit_schedules_another_attempt(): void
    {
        $this->fakeError(429, 130429, 'Rate limit hit');

        $result = $this->send();

        $this->assertFalse($result['sent']);
        $this->assertTrue($result['will_retry']);

        $message = MarketingMessage::find($result['message_id']);
        $this->assertSame(WhatsappOutboxService::STATUS_FAILED, $message->status);
        $this->assertSame(130429, $message->last_error_code);
        $this->assertNotNull($message->next_attempt_at);
        $this->assertTrue($message->next_attempt_at->isFuture());
    }

    public function test_a_server_error_from_meta_is_also_retried(): void
    {
        $this->fakeError(500, 131000, 'Something went wrong');

        $this->assertTrue($this->send()['will_retry']);
    }

    /**
     * Un número sin WhatsApp no mejora por insistir. Reintentarlo solo gasta
     * cuota y ensucia el inbox, así que muere en el primer intento.
     */
    public function test_a_permanent_failure_never_gets_a_second_attempt(): void
    {
        $this->fakeError(400, 131026, 'Receiver is incapable of receiving this message');

        $result = $this->send();

        $this->assertFalse($result['will_retry']);

        $message = MarketingMessage::find($result['message_id']);
        $this->assertSame(WhatsappOutboxService::STATUS_DEAD, $message->status);
        $this->assertNull($message->next_attempt_at);
    }

    /** La ventana de 24 h cerrada exige plantilla, no insistencia. */
    public function test_the_closed_24h_window_is_a_dead_end_not_a_retry(): void
    {
        $this->fakeError(400, 131047, 'Re-engagement message');

        $result = $this->send();

        $this->assertFalse($result['will_retry']);
        $this->assertSame(
            WhatsappOutboxService::STATUS_DEAD,
            MarketingMessage::find($result['message_id'])->status,
        );
    }

    /**
     * El corazón del asunto: si Meta ya aceptó el mensaje, ningún reintento
     * puede volver a enviarlo. Escribirle dos veces a un prospecto es peor que
     * no escribirle.
     */
    public function test_an_already_delivered_message_is_never_sent_twice(): void
    {
        $this->fakeAccepted();
        $result = $this->send();
        $message = MarketingMessage::find($result['message_id']);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SEGUNDO']]], 200)]);

        $again = $this->outbox()->deliver($message->fresh(), '573150536026');

        $this->assertTrue($again['sent']);
        $this->assertSame('already_delivered', $again['reason']);
        // Ni una sola petición nueva a Meta.
        Http::assertNothingSent();
        $this->assertSame('wamid.ACCEPTED', $message->fresh()->meta_message_id);
    }

    /** Tras agotar los intentos deja de reintentarse y queda a la vista. */
    public function test_after_the_last_attempt_the_message_is_marked_dead(): void
    {
        $this->fakeError(429, 130429);

        $result = $this->send();                       // intento 1
        $message = MarketingMessage::find($result['message_id']);

        $this->outbox()->deliver($message->fresh(), '573150536026');  // 2
        $this->outbox()->deliver($message->fresh(), '573150536026');  // 3 (máximo)

        $message->refresh();
        $this->assertSame(3, $message->send_attempts);
        $this->assertSame(WhatsappOutboxService::STATUS_DEAD, $message->status);
        $this->assertNull($message->next_attempt_at);
    }

    /** La espera crece entre intentos para no martillear a Meta. */
    public function test_the_wait_grows_between_attempts(): void
    {
        Carbon::setTestNow('2026-08-05 10:00:00');
        $this->fakeError(429, 130429);

        $message = MarketingMessage::find($this->send()['message_id']);
        $firstWait = now()->diffInSeconds($message->fresh()->next_attempt_at);

        $this->outbox()->deliver($message->fresh(), '573150536026');
        $secondWait = now()->diffInSeconds($message->fresh()->next_attempt_at);

        $this->assertGreaterThan($firstWait, $secondWait);

        Carbon::setTestNow();
    }

    public function test_the_retry_command_picks_up_only_what_is_due(): void
    {
        // Meta rechaza el primer envío por límite de tasa y acepta el segundo:
        // exactamente el pico pasajero que justifica que exista el reintento.
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Rate limit hit', 'type' => 'OAuthException', 'code' => 130429]], 429)
            ->push(['messages' => [['id' => 'wamid.RESCATADO']]], 200),
        ]);

        $message = MarketingMessage::find($this->send()['message_id']);

        // Todavía no vence: la corrida no debe tocarlo.
        $this->artisan('marketing:retry-outbox')->assertSuccessful();
        $this->assertSame(1, $message->fresh()->send_attempts);

        // Vencido: ahora sí se reintenta y Meta lo acepta.
        $message->forceFill(['next_attempt_at' => now()->subMinute()])->save();

        $this->artisan('marketing:retry-outbox')->assertSuccessful();

        $message->refresh();
        $this->assertSame(WhatsappOutboxService::STATUS_SENT, $message->status);
        $this->assertSame('wamid.RESCATADO', $message->meta_message_id);
    }

    /** Lo ya entregado nunca entra en la cola de reintentos. */
    public function test_delivered_messages_are_never_queued_for_retry(): void
    {
        $this->fakeAccepted();
        $this->send();

        $this->assertCount(0, $this->outbox()->due());
    }

    /** Un mensaje muerto tampoco vuelve solo: hace falta una decisión humana. */
    public function test_a_dead_message_is_not_retried_automatically(): void
    {
        $this->fakeError(400, 131026);
        $message = MarketingMessage::find($this->send()['message_id']);

        $this->assertCount(0, $this->outbox()->due());

        $again = $this->outbox()->deliver($message->fresh(), '573150536026');
        $this->assertSame('dead', $again['reason']);
    }

    /** Con Meta apagado nada sale, y eso no es un fallo sino el modo seguro. */
    public function test_with_meta_off_nothing_leaves_and_it_is_not_a_failure(): void
    {
        config()->set('meta.enabled', false);
        Http::fake();

        $result = $this->send();

        $this->assertTrue($result['dry_run']);
        $this->assertTrue($result['safe_to_send']);
        $this->assertFalse($result['sent']);
        Http::assertNothingSent();
    }

    /** Una respuesta se manda citando el mensaje del cliente al que contesta. */
    public function test_a_reply_quotes_the_customer_message_it_answers(): void
    {
        $this->fakeAccepted();

        $this->dispatcher()->dispatchWhatsapp(
            $this->lead, 'whatsapp', 'Claro que sí',
            ['reply_to_meta_message_id' => 'wamid.DEL_CLIENTE'],
        );

        Http::assertSent(fn (Request $r) => data_get($r->data(), 'context.message_id') === 'wamid.DEL_CLIENTE');
    }

    /** El texto del prospecto nunca viaja en el log de un envío. */
    public function test_the_message_body_is_not_written_to_the_log(): void
    {
        $this->fakeError(400, 131026, 'Receiver is incapable');

        $result = $this->send('mi cédula es 1075123456 y mi dirección es calle 5');

        // El cuerpo se guarda en la BD (hace falta para el inbox) pero el
        // resumen del error que se persiste es el de Meta, no el nuestro.
        $message = MarketingMessage::find($result['message_id']);
        $this->assertStringNotContainsString('1075123456', (string) $message->last_error_message);
    }
}
