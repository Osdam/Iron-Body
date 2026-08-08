<?php

namespace Tests\Feature\Chaos;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageStatus;
use App\Models\MetaWebhookEvent;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Marketing\WhatsappOutboxService;
use Illuminate\Support\Facades\Http;

/**
 * F6.30 – F6.37 · El canal de WhatsApp se porta mal.
 *
 * Ninguno de estos escenarios llama a Meta: se simula el saliente contra un
 * doble, porque el número no está registrado y no puede salir un solo mensaje
 * real de aquí.
 *
 * Cloud API no ofrece claves de idempotencia. Esa ausencia es la que obliga a
 * que la defensa contra el doble envío sea nuestra y sea local: **un mensaje
 * con `meta_message_id` ya salió y no se reintenta jamás.** Casi todo lo que
 * sigue comprueba esa regla desde un ángulo distinto, porque el precio de que
 * falle no es un log feo —es que a una persona le llegue dos veces lo mismo, o
 * que reciba a las once de la noche una respuesta que ya no venía a cuento.
 */
class ChaosMetaChannelTest extends ChaosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Canal configurado para poder EJERCITAR el envío contra el doble. Ni
        // una petición sale de aquí: `preventStrayRequests` lo garantiza.
        config()->set('meta.enabled', true);
        config()->set('meta.access_token', 'chaos-token');
        config()->set('meta.app_secret', 'chaos-app-secret');
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.graph_version', 'v21.0');
        config()->set('marketing.outbox.max_attempts', 4);
    }

    private function lead(): MarketingLead
    {
        return MarketingLead::create([
            'channel' => 'whatsapp', 'meta_user_id' => '573001112233',
            'phone' => '573001112233', 'name' => 'Prospecto Chaos',
        ]);
    }

    private function send(MarketingLead $lead, string $body = 'Hola, te cuento los planes'): array
    {
        return app(MarketingMessageDispatcher::class)->dispatchWhatsapp($lead, 'whatsapp', $body);
    }

    // ── F6.30 ───────────────────────────────────────────────────────────

    /**
     * F6.30 — Meta responde 429 al enviar.
     *
     * El mensaje ya está en la bandeja antes de salir a la red, así que un
     * rechazo por límite de tasa no lo hace desaparecer: queda con su próximo
     * intento programado y con el motivo escrito.
     */
    public function test_f630_429_deja_el_saliente_reintentable_y_sin_duplicar(): void
    {
        $lead = $this->lead();

        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Rate limit hit', 'code' => 80007, 'type' => 'OAuthException'],
        ], 429, ['Retry-After' => '30'])]);

        $result = $this->send($lead);

        $this->assertFalse($result['sent']);
        $this->assertTrue($result['will_retry'], 'Un 429 es pasajero: tenía que quedar reintentable.');

        $message = MarketingMessage::findOrFail($result['message_id']);
        $this->assertSame(WhatsappOutboxService::STATUS_FAILED, $message->status);
        $this->assertNull($message->meta_message_id, 'Se dio por entregado un mensaje que Meta rechazó.');
        $this->assertNotNull($message->next_attempt_at, 'No se programó el reintento: el prospecto se quedaría sin respuesta.');
        $this->assertGreaterThan(now(), $message->next_attempt_at, 'El reintento es inmediato: eso es martillear a Meta.');
        $this->assertSame(1, (int) $message->send_attempts);

        // Y sigue habiendo UN solo mensaje.
        $this->assertSame(1, MarketingMessage::where('direction', 'outbound')->count());
    }

    /**
     * F6.30b — La espera crece entre intentos.
     *
     * Sin crecimiento, veinte mensajes fallidos vuelven todos a la vez y
     * provocan exactamente el límite del que se estaba saliendo.
     */
    public function test_f630b_la_espera_entre_reintentos_crece(): void
    {
        $lead = $this->lead();

        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Rate limit hit', 'code' => 80007],
        ], 429)]);

        $result = $this->send($lead);
        $message = MarketingMessage::findOrFail($result['message_id']);
        $primeraEspera = now()->diffInSeconds($message->next_attempt_at);

        $outbox = app(WhatsappOutboxService::class);
        $outbox->deliver($message->fresh(), '573001112233');
        $segundaEspera = now()->diffInSeconds($message->fresh()->next_attempt_at);

        $this->assertGreaterThan($primeraEspera, $segundaEspera,
            'La espera no crece: dos intentos seguidos golpean igual de rápido.');
    }

    /**
     * F6.30c — Y los reintentos tienen techo.
     *
     * Un número que no tiene WhatsApp no mejora por insistir. Agotados los
     * intentos, el mensaje queda muerto y visible en vez de dando vueltas.
     */
    public function test_f630c_los_reintentos_tienen_techo(): void
    {
        $lead = $this->lead();

        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Rate limit hit', 'code' => 80007],
        ], 429)]);

        $result = $this->send($lead);
        $message = MarketingMessage::findOrFail($result['message_id']);

        $outbox = app(WhatsappOutboxService::class);
        for ($i = 0; $i < 10; $i++) {
            $outbox->deliver($message->fresh(), '573001112233');
        }

        $message->refresh();
        $this->assertSame(WhatsappOutboxService::STATUS_DEAD, $message->status,
            'El mensaje se reintentaría para siempre.');
        $this->assertNull($message->next_attempt_at);
        $this->assertLessThanOrEqual(4, (int) $message->send_attempts,
            'Se superó el máximo de intentos configurado.');
    }

    // ── F6.31 ───────────────────────────────────────────────────────────

    /** F6.31 — 500 de Meta: el mensaje permanece reintentable, no muerto. */
    public function test_f631_500_de_meta_mantiene_el_mensaje_reintentable(): void
    {
        $lead = $this->lead();

        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Internal error', 'code' => 1, 'type' => 'OAuthException'],
        ], 500)]);

        $result = $this->send($lead);

        $message = MarketingMessage::findOrFail($result['message_id']);
        $this->assertSame(WhatsappOutboxService::STATUS_FAILED, $message->status);
        $this->assertNotNull($message->next_attempt_at);
        $this->assertNull($message->meta_message_id);
    }

    /**
     * F6.31b — Y cuando Meta vuelve, sale UNA vez.
     *
     * La recuperación es la mitad que importa: comprobar que el fallo se
     * registra bien y no comprobar que después se entrega deja el escenario a
     * medio probar.
     */
    public function test_f631b_recuperacion_tras_500_entrega_una_sola_vez(): void
    {
        $lead = $this->lead();

        $caido = true;
        Http::fake(['graph.facebook.com/*' => function () use (&$caido) {
            return $caido
                ? Http::response(['error' => ['message' => 'Internal error', 'code' => 1]], 500)
                : Http::response(['messages' => [['id' => 'wamid.entregado-1']]], 200);
        }]);

        $result = $this->send($lead);
        $message = MarketingMessage::findOrFail($result['message_id']);
        $this->assertSame(WhatsappOutboxService::STATUS_FAILED, $message->status);

        $caido = false;
        $outbox = app(WhatsappOutboxService::class);
        $outbox->deliver($message->fresh(), '573001112233');

        $message->refresh();
        $this->assertSame(WhatsappOutboxService::STATUS_SENT, $message->status);
        $this->assertSame('wamid.entregado-1', $message->meta_message_id);

        // Y volver a intentarlo ya no manda nada: el id de Meta es el candado.
        $llamadasAntes = 0;
        Http::recorded(function () use (&$llamadasAntes) {
            $llamadasAntes++;

            return true;
        });

        $outbox->deliver($message->fresh(), '573001112233');
        $outbox->deliver($message->fresh(), '573001112233');

        $llamadasDespues = 0;
        Http::recorded(function () use (&$llamadasDespues) {
            $llamadasDespues++;

            return true;
        });

        $this->assertSame($llamadasAntes, $llamadasDespues,
            'Un mensaje ya entregado se volvió a mandar: el cliente lo recibe dos veces.');
        $this->assertSame(1, MarketingMessage::where('direction', 'outbound')->count());
    }

    // ── F6.32 ───────────────────────────────────────────────────────────

    /**
     * F6.32 — Meta aceptó el mensaje pero el callback de entrega tarda.
     *
     * Es normal: `sent` y `delivered` pueden separarse minutos, u horas si el
     * teléfono está apagado. Reenviar por impaciencia es el error clásico, y
     * aquí se comprueba que no ocurre.
     */
    public function test_f632_ausencia_de_delivered_no_provoca_reenvio(): void
    {
        $lead = $this->lead();

        Http::fake(['graph.facebook.com/*' => Http::response([
            'messages' => [['id' => 'wamid.aceptado-1']],
        ], 200)]);

        $result = $this->send($lead);
        $message = MarketingMessage::findOrFail($result['message_id']);
        $this->assertSame(WhatsappOutboxService::STATUS_SENT, $message->status);

        // Pasa el tiempo y `delivered` no llega.
        $this->travel(6)->hours();

        // El barrido de reintentos no debe considerarlo siquiera.
        $due = app(WhatsappOutboxService::class)->due();
        $this->assertTrue($due->doesntContain('id', $message->id),
            'Un mensaje entregado a Meta entró en la cola de reintentos por no tener «delivered».');

        $this->artisan('marketing:retry-outbox')->assertSuccessful();

        $this->assertSame('wamid.aceptado-1', $message->fresh()->meta_message_id);
        $this->assertSame(1, MarketingMessage::where('direction', 'outbound')->count());
    }

    // ── F6.33 ───────────────────────────────────────────────────────────

    /**
     * F6.33 — El mismo estado repetido.
     *
     * Meta reentrega callbacks. El estado no puede moverse dos veces por el
     * mismo hecho, pero cada llegada sí queda en el historial: son evidencia de
     * lo que pasó, no ruido que convenga esconder.
     */
    public function test_f633_estados_repetidos_no_duplican_el_efecto(): void
    {
        $message = $this->sentMessage('wamid.estados-1');

        for ($i = 0; $i < 5; $i++) {
            $this->metaWebhook($this->statusCallback('wamid.estados-1', 'sent'))->assertOk();
            $this->metaWebhook($this->statusCallback('wamid.estados-1', 'delivered'))->assertOk();
        }

        $message->refresh();
        $this->assertSame('delivered', $message->status);

        $aplicados = MarketingMessageStatus::where('message_id', $message->id)
            ->where('applied', true)->count();

        $this->assertSame(1, $aplicados, sprintf(
            'Se aplicaron %d cambios de estado para un mensaje que solo avanzó una vez.', $aplicados,
        ));
    }

    // ── F6.34 ───────────────────────────────────────────────────────────

    /**
     * F6.34 — Los estados llegan al revés.
     *
     * `read` primero, después `delivered`, después `sent`. Ocurre de verdad
     * porque cada callback viaja por su cuenta. Si el estado retrocediera, el
     * inbox diría que un mensaje leído está «enviado» y quien atiende volvería
     * a escribir creyendo que no llegó.
     */
    public function test_f634_estados_fuera_de_orden_no_degradan_el_estado(): void
    {
        $message = $this->sentMessage('wamid.desorden-1');

        $this->metaWebhook($this->statusCallback('wamid.desorden-1', 'read'))->assertOk();
        $this->assertSame('read', $message->fresh()->status);

        $this->metaWebhook($this->statusCallback('wamid.desorden-1', 'delivered'))->assertOk();
        $this->assertSame('read', $message->fresh()->status, 'Un «delivered» tardío degradó un mensaje ya leído.');

        $this->metaWebhook($this->statusCallback('wamid.desorden-1', 'sent'))->assertOk();
        $this->assertSame('read', $message->fresh()->status, 'Un «sent» tardío degradó un mensaje ya leído.');

        // Los tres quedan en el historial; solo uno movió el estado.
        $this->assertSame(3, MarketingMessageStatus::where('message_id', $message->id)->count());
        $this->assertSame(1, MarketingMessageStatus::where('message_id', $message->id)
            ->where('applied', true)->count());
    }

    // ── F6.35 ───────────────────────────────────────────────────────────

    /**
     * F6.35 — El mismo webhook entrante, muchas veces.
     *
     * Meta reentrega cuando duda de haber recibido el 200. Diez entregas del
     * mismo mensaje son un mensaje.
     */
    public function test_f635_webhook_entrante_duplicado_produce_un_solo_mensaje(): void
    {
        $payload = $this->inboundMessage('573001112233', 'Hola, quiero información', waid: 'wamid.repetido-1');

        for ($i = 0; $i < 10; $i++) {
            $this->metaWebhook($payload)->assertOk();
        }

        $lead = MarketingLead::where('meta_user_id', '573001112233')->firstOrFail();
        $conversation = MarketingConversation::where('lead_id', $lead->id)->firstOrFail();

        $this->assertSame(1, MarketingMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')->count(),
            'Diez reentregas del mismo mensaje crearon más de un mensaje.');
        $this->assertSame(1, MarketingLead::count());
        $this->assertSame(1, MarketingConversation::count());

        // El cuerpo idéntico se registra una vez: el resto se descarta por hash.
        $this->assertSame(1, MetaWebhookEvent::count());
    }

    /**
     * F6.35b — Y dos mensajes distintos del mismo cuerpo SÍ son dos.
     *
     * La deduplicación tiene que distinguir «lo mismo otra vez» de «otra vez lo
     * mismo»: si alguien escribe «hola» dos veces, son dos mensajes.
     */
    public function test_f635b_dos_mensajes_distintos_no_se_confunden_con_un_duplicado(): void
    {
        $this->metaWebhook($this->inboundMessage('573001112233', 'hola', waid: 'wamid.uno'))->assertOk();
        $this->metaWebhook($this->inboundMessage('573001112233', 'hola', waid: 'wamid.dos'))->assertOk();

        $lead = MarketingLead::where('meta_user_id', '573001112233')->firstOrFail();
        $conversation = MarketingConversation::where('lead_id', $lead->id)->firstOrFail();

        $this->assertSame(2, MarketingMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')->count(),
            'Dos mensajes distintos se colapsaron en uno: se perdió lo que dijo el cliente.');
    }

    // ── F6.36 ───────────────────────────────────────────────────────────

    /**
     * F6.36 — Firma inválida: se rechaza ANTES de encolar.
     *
     * Lo que importa no es solo el código de respuesta: es que no quede fila,
     * no se encole job y no se cree lead. Un atacante no puede llenar la cola
     * ni inventarse prospectos.
     */
    public function test_f636_firma_invalida_se_rechaza_antes_de_persistir_o_encolar(): void
    {
        $payload = $this->inboundMessage('573009998877', 'soy un atacante');
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'secreto-que-no-es'),
            'HTTP_ACCEPT' => 'application/json',
        ], $raw);

        $this->assertContains($response->status(), [401, 403], 'Un webhook sin firma válida no fue rechazado.');
        $this->assertSame(0, MetaWebhookEvent::count(), 'Se guardó el evento de un webhook no autenticado.');
        $this->assertSame(0, MarketingLead::count(), 'Un webhook falso llegó a crear un lead.');
        $this->assertSame(0, MarketingMessage::count());
    }

    /** F6.36b — Y sin firma en absoluto, lo mismo. */
    public function test_f636b_sin_firma_tampoco_pasa(): void
    {
        $payload = $this->inboundMessage('573009998877', 'sin firma');
        $raw = json_encode($payload);

        $response = $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $raw);

        $this->assertContains($response->status(), [401, 403]);
        $this->assertSame(0, MetaWebhookEvent::count());
    }

    // ── F6.37 ───────────────────────────────────────────────────────────

    /**
     * F6.37 — Un cuerpo desproporcionado.
     *
     * El límite se aplica sobre el cuerpo crudo, antes de decodificarlo: si se
     * midiera después, el trabajo de agotar la memoria ya estaría hecho.
     */
    public function test_f637_payload_enorme_se_rechaza_sin_agotar_memoria(): void
    {
        $memoriaAntes = memory_get_usage(true);

        $payload = $this->inboundMessage('573001112233', str_repeat('A', 3 * 1024 * 1024));
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, self::META_SECRET),
            'HTTP_ACCEPT' => 'application/json',
        ], $raw);

        // Se reconoce (200) o se rechaza, pero NO se almacena ni se procesa.
        $this->assertSame(0, MetaWebhookEvent::count(),
            'Un cuerpo de 3 MB llegó a almacenarse: el disco y la memoria quedan a merced de quien mande el POST.');
        $this->assertSame(0, MarketingMessage::count());
        $this->assertLessThan(200 * 1024 * 1024, memory_get_usage(true) - $memoriaAntes,
            'El rechazo consumió una cantidad de memoria desproporcionada.');
        $this->assertContains($response->status(), [200, 202, 413, 422]);
    }

    // ── Utilidad ────────────────────────────────────────────────────────

    /** Un saliente que Meta ya aceptó, con su id. */
    private function sentMessage(string $metaMessageId): MarketingMessage
    {
        $lead = $this->lead();

        $conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
        ]);

        return MarketingMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'sender_type' => MarketingMessage::SENDER_AI,
            'body' => 'Te cuento los planes',
            'status' => WhatsappOutboxService::STATUS_SENT,
            'meta_message_id' => $metaMessageId,
        ]);
    }
}
