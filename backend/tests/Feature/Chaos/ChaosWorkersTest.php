<?php

namespace Tests\Feature\Chaos;

use App\Jobs\ProcessMetaWebhookEvent;
use App\Models\Incident;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MetaWebhookEvent;
use App\Services\IronGuard\ChannelHealthDetector;
use App\Services\Marketing\MarketingInboundMessageRouter;
use App\Services\Meta\MetaConversationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;

/**
 * F6.08 – F6.13 · El worker se muere y la base se atraganta.
 *
 * La propiedad que sostiene toda esta familia se decidió mucho antes que estas
 * pruebas: el webhook guarda el cuerpo crudo y encola un ID, no el payload. Por
 * eso un worker que muere no se lleva nada con él —el mensaje del cliente ya
 * está en `meta_webhook_events`— y por eso un reintento lee exactamente lo
 * mismo que leyó el intento que se cortó.
 *
 * Lo que estas pruebas verifican es la otra mitad, la que no se sigue sola de
 * ese diseño: que al retomar no se dupliquen los efectos, que un fallo repetido
 * termine en algún sitio visible en vez de desaparecer, y que ningún reintento
 * corra para siempre.
 */
class ChaosWorkersTest extends ChaosTestCase
{
    /**
     * Entrega el webhook SIN ejecutar el job: el worker no está.
     *
     * La cola se devuelve a su sitio al salir. Dejarla falseada haría que los
     * escenarios de rescate —que encolan de verdad— parecieran no hacer nada,
     * y la prueba diría «no se procesó» culpando al sistema de lo que hizo el
     * andamio.
     */
    private function ingestWithoutWorker(string $text = 'Hola, quiero información'): MetaWebhookEvent
    {
        $realQueue = Queue::getFacadeRoot();
        Queue::fake();

        $this->metaWebhook($this->inboundMessage('573001112233', $text, waid: 'wamid.chaos-fijo'))->assertOk();

        Queue::assertPushed(ProcessMetaWebhookEvent::class);

        Queue::swap($realQueue);

        return MetaWebhookEvent::latest('id')->firstOrFail();
    }

    private function conversation(): ?MarketingConversation
    {
        $lead = MarketingLead::query()
            ->where('phone', '573001112233')
            ->orWhere('meta_user_id', '573001112233')
            ->first();

        return $lead === null ? null : MarketingConversation::where('lead_id', $lead->id)->first();
    }

    // ── F6.08 ───────────────────────────────────────────────────────────

    /**
     * F6.08 — El worker muere antes de tocar el evento.
     *
     * El webhook ya respondió 200 a Meta, así que Meta no va a reenviarlo: si
     * el evento no estuviera guardado, ese mensaje no existiría en ningún sitio
     * y el prospecto habría escrito al vacío.
     */
    public function test_f608_evento_persistido_sobrevive_al_worker_y_se_retoma(): void
    {
        $event = $this->ingestWithoutWorker();

        $this->assertSame(MetaWebhookEvent::STATUS_PENDING, $event->status);
        $this->assertNotEmpty($event->payload, 'El cuerpo original no se guardó: el evento no se puede retomar.');
        $this->assertNull($this->conversation(), 'Sin worker no debería haberse procesado nada todavía.');

        // Vuelve el worker.
        (new ProcessMetaWebhookEvent($event->id))->handle(
            app(\App\Services\Meta\MetaWebhookService::class),
            app(\App\Services\Meta\MetaLeadService::class),
            app(MetaConversationService::class),
            app(MarketingInboundMessageRouter::class),
            app(\App\Services\Marketing\MarketingMessageDispatcher::class),
        );

        $this->assertSame(MetaWebhookEvent::STATUS_PROCESSED, $event->fresh()->status);

        $conversation = $this->conversation();
        $this->assertNotNull($conversation, 'El evento retomado no llegó a crear la conversación.');
        $this->assertSame(1, MarketingMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')->count());
    }

    // ── F6.09 ───────────────────────────────────────────────────────────

    /**
     * F6.09 — El worker muere a MITAD del procesamiento.
     *
     * El caso interesante: el mensaje entrante ya se guardó y el corte llega
     * justo después, durante el enrutado. Al reintentar, el evento se procesa
     * entero otra vez. Si la idempotencia no estuviera aguas abajo, el cliente
     * tendría dos mensajes iguales en el inbox y el agente lo analizaría dos
     * veces.
     */
    public function test_f609_corte_a_media_ejecucion_no_duplica_efectos_al_reintentar(): void
    {
        $event = $this->ingestWithoutWorker();

        // El corte: el enrutado revienta después de que el mensaje se guardó.
        $this->app->bind(MarketingInboundMessageRouter::class, fn () => new class(app(\App\Services\Marketing\SalesAgentOrchestratorService::class)) extends MarketingInboundMessageRouter
        {
            public function route(MarketingLead $lead, MarketingConversation $conversation, MarketingMessage $message, array $parsed): array
            {
                throw new \RuntimeException('worker muerto a media ejecución');
            }
        });

        try {
            $this->runJob($event->id);
            $this->fail('El fallo a media ejecución debería propagarse para que la cola reintente.');
        } catch (\RuntimeException $e) {
            $this->assertSame('worker muerto a media ejecución', $e->getMessage());
        }

        $conversation = $this->conversation();
        $this->assertNotNull($conversation);
        $mensajesTrasElCorte = MarketingMessage::where('conversation_id', $conversation->id)->count();

        // Vuelve el worker sano y reintenta el MISMO evento.
        $this->app->forgetInstance(MarketingInboundMessageRouter::class);
        $this->app->bind(MarketingInboundMessageRouter::class, fn ($app) => new MarketingInboundMessageRouter(
            $app->make(\App\Services\Marketing\SalesAgentOrchestratorService::class),
        ));

        $this->runJob($event->id);

        $this->assertSame(MetaWebhookEvent::STATUS_PROCESSED, $event->fresh()->status);
        $this->assertSame($mensajesTrasElCorte, MarketingMessage::where('conversation_id', $conversation->id)->count(),
            'El reintento duplicó mensajes: la idempotencia por meta_message_id no está protegiendo el reproceso.');
        $this->assertSame(1, MarketingMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')->count());
    }

    // ── F6.10 ───────────────────────────────────────────────────────────

    /**
     * F6.10 — Se agotan los intentos.
     *
     * Un job que se rinde no puede limitarse a desaparecer en `failed_jobs`,
     * porque nadie lee `failed_jobs`. Tiene que quedar en la misma fila que
     * describe el evento, con el motivo, y en un estado del que se pueda salir.
     */
    public function test_f610_agotar_intentos_deja_el_evento_muerto_pero_visible_y_recuperable(): void
    {
        $event = $this->ingestWithoutWorker();

        /*
         * El fallo se inyecta en la resolución del lead, no en el enrutado.
         *
         * Es una distinción que costó una prueba mal escrita: un fallo POSTERIOR
         * a guardar el mensaje no se repite en el segundo intento, porque el
         * reproceso detecta el duplicado y sale antes de volver a pasar por el
         * punto roto. Eso es exactamente lo que debe hacer —y por eso no sirve
         * para probar el agotamiento—. Para eso hace falta una dependencia que
         * siga caída en cada intento, que es lo que pasa cuando el problema es
         * la base o un servicio y no un registro concreto.
         */
        $this->app->bind(\App\Services\Meta\MetaLeadService::class, fn () => new class extends \App\Services\Meta\MetaLeadService
        {
            public function resolveLead(string $channel, ?string $metaUserId, ?string $name = null): MarketingLead
            {
                throw new \RuntimeException('la dependencia sigue caída');
            }
        });

        // Tres intentos: el tercero es el último que le queda al job.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $this->runJob($event->id, attempts: $attempt);
            } catch (\RuntimeException) {
                // Esperado: así es como la cola sabe que debe reintentar.
            }
        }

        $event->refresh();

        $this->assertSame(MetaWebhookEvent::STATUS_DEAD, $event->status,
            'El evento agotado no quedó marcado como muerto: se pierde de vista.');
        $this->assertSame('RuntimeException', $event->last_error_class);
        $this->assertStringContainsString('dependencia', (string) $event->last_error);
        $this->assertSame(3, (int) $event->attempts, 'Los intentos no se están contando: no hay techo demostrable.');

        // Y es recuperable: el cuerpo original sigue ahí para el replay.
        $this->assertNotEmpty($event->payload);
    }

    /**
     * F6.10b — Un evento muerto levanta un incidente, y cien no levantan cien.
     *
     * La deduplicación no es cosmética: sin ella, la pantalla que debería
     * avisar del problema es la primera víctima del problema.
     */
    public function test_f610b_eventos_muertos_generan_un_incidente_agrupado(): void
    {
        for ($i = 0; $i < 100; $i++) {
            MetaWebhookEvent::create([
                'correlation_id' => 'chaos-'.$i,
                'payload_hash' => hash('sha256', 'chaos-'.$i),
                'object' => 'whatsapp_business_account',
                'payload' => ['entry' => []],
                'payload_bytes' => 10,
                'messages_count' => 1,
                'statuses_count' => 0,
                'status' => MetaWebhookEvent::STATUS_DEAD,
                'attempts' => 3,
                'last_error_class' => 'RuntimeException',
                'last_error' => 'la dependencia sigue caída',
            ]);
        }

        app(ChannelHealthDetector::class)->scan();

        $incident = $this->assertSingleIncident('events_dead', 1);
        $this->assertSame('meta_webhook', $incident->source);
        $this->assertNotNull($incident->first_seen_at);
        $this->assertNotNull($incident->last_seen_at);
        $this->assertNotEmpty($incident->evidence);
        $this->assertNoSecretsLeaked($incident);
    }

    // ── F6.11 ───────────────────────────────────────────────────────────

    /**
     * F6.11 — Se reinicia la cola con trabajo dentro.
     *
     * Reiniciar la cola tira los jobs encolados, no los eventos. El comando de
     * rescate es lo que convierte eso en un incidente sin consecuencias.
     */
    public function test_f611_reinicio_de_cola_no_pierde_eventos_ya_persistidos(): void
    {
        $event = $this->ingestWithoutWorker();

        // El reinicio: lo encolado se evapora, la fila permanece.
        $this->assertSame(MetaWebhookEvent::STATUS_PENDING, $event->fresh()->status);

        // El evento tiene que envejecer para que el rescate lo considere atascado.
        $event->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $this->artisan('marketing:replay-webhooks', ['--minutes' => 10])->assertSuccessful();

        $this->assertSame(MetaWebhookEvent::STATUS_PROCESSED, $event->fresh()->status,
            'El evento rescatado tras el reinicio no llegó a procesarse.');

        $conversation = $this->conversation();
        $this->assertNotNull($conversation);
        $this->assertSame(1, MarketingMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')->count());
    }

    /** F6.11b — Y ejecutar el rescate dos veces no duplica nada. */
    public function test_f611b_el_rescate_es_seguro_de_ejecutar_de_mas(): void
    {
        $event = $this->ingestWithoutWorker();
        $event->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $this->artisan('marketing:replay-webhooks', ['--minutes' => 10])->assertSuccessful();
        $this->artisan('marketing:replay-webhooks', ['--id' => $event->id])->assertSuccessful();
        $this->artisan('marketing:replay-webhooks', ['--id' => $event->id])->assertSuccessful();

        $conversation = $this->conversation();
        $this->assertSame(1, MarketingMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')->count(),
            'Reprocesar el mismo evento creó mensajes de más.');
    }

    // ── F6.12 ───────────────────────────────────────────────────────────

    /**
     * F6.12 — PostgreSQL deja de contestar a mitad de la escritura.
     *
     * Lo que se comprueba es que no quede un estado a medias: el mensaje y su
     * historial se mueven juntos o no se mueven, y el evento queda reintentable
     * en lugar de dado por bueno.
     */
    public function test_f612_timeout_de_base_de_datos_no_deja_estado_parcial(): void
    {
        $event = $this->ingestWithoutWorker();

        // Primero, un procesado sano para tener con qué comparar.
        $this->runJob($event->id);
        $conversation = $this->conversation();
        $this->assertNotNull($conversation);

        $mensajesAntes = MarketingMessage::where('conversation_id', $conversation->id)->count();
        $historialAntes = \App\Models\MarketingMessageStatus::count();

        // Ahora la base se cae en mitad de la transacción del cambio de estado.
        $mensaje = MarketingMessage::where('conversation_id', $conversation->id)->first();
        $mensaje->forceFill(['meta_message_id' => 'wamid.saliente-1', 'direction' => 'outbound', 'status' => 'sent'])->save();

        $conversations = app(MetaConversationService::class);

        /*
         * El corte se pone entre las DOS escrituras de la misma transacción: el
         * historial ya se insertó y el UPDATE del mensaje es el que se queda sin
         * base. Es el momento exacto en el que un rollback que no funcionara
         * dejaría un mensaje que dice «entregado» con un historial que no lo
         * respalda, o al revés —y en un inbox, un estado que nadie puede
         * justificar es peor que un estado viejo—.
         */
        MarketingMessage::updating(function () {
            throw new QueryException('pgsql', 'update marketing_messages ...', [],
                new \PDOException('SQLSTATE[57014]: canceling statement due to statement timeout'));
        });

        try {
            $conversations->recordStatus('wamid.saliente-1', 'delivered');
            $this->fail('El timeout de la base debería propagarse, no tragarse.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('57014', $e->getMessage());
        }

        MarketingMessage::flushEventListeners();

        // Ni el historial ni el estado del mensaje se movieron a medias.
        $this->assertSame($historialAntes, \App\Models\MarketingMessageStatus::count(),
            'Quedó un registro de historial de una transacción que no llegó a confirmarse.');
        $this->assertSame('sent', $mensaje->fresh()->status,
            'El estado del mensaje avanzó sin que su historial se confirmara.');
        $this->assertSame($mensajesAntes, MarketingMessage::where('conversation_id', $conversation->id)->count());
    }

    // ── F6.13 ───────────────────────────────────────────────────────────

    /**
     * F6.13 — Deadlock de la base.
     *
     * Dos entregas del mismo hilo chocando. El job no puede tragarse el
     * deadlock —tiene que dejarlo subir para que la cola reintente— y el
     * reintento no puede duplicar el efecto de lo que sí se había escrito.
     */
    public function test_f613_deadlock_se_reintenta_de_forma_acotada_y_sin_duplicar(): void
    {
        $event = $this->ingestWithoutWorker();

        /*
         * El choque se pone al resolver el lead, no al enrutar: un deadlock
         * ocurre antes de que nada se haya escrito, y ese es justo el caso en
         * el que el reintento tiene que volver a recorrer todo el camino sin
         * duplicar lo que sí llegó a escribirse.
         */
        $intentos = new \stdClass;
        $intentos->n = 0;

        $this->app->bind(\App\Services\Meta\MetaLeadService::class, fn () => new class($intentos) extends \App\Services\Meta\MetaLeadService
        {
            public function __construct(private \stdClass $intentos) {}

            public function resolveLead(string $channel, ?string $metaUserId, ?string $name = null): MarketingLead
            {
                $this->intentos->n++;

                // Solo el primer intento choca; el segundo pasa, que es lo que
                // hace un deadlock de verdad: es transitorio por definición.
                if ($this->intentos->n === 1) {
                    throw new QueryException('pgsql', 'update ...', [],
                        new \PDOException('SQLSTATE[40P01]: deadlock detected'));
                }

                return parent::resolveLead($channel, $metaUserId, $name);
            }
        });

        try {
            $this->runJob($event->id, attempts: 1);
            $this->fail('El deadlock debería propagarse para que la cola reintente.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('40P01', $e->getMessage());
        }

        $this->assertSame(MetaWebhookEvent::STATUS_FAILED, $event->fresh()->status,
            'Tras un deadlock con reintentos por delante el evento debe quedar failed, no dead.');

        $this->runJob($event->id, attempts: 2);

        $this->assertSame(MetaWebhookEvent::STATUS_PROCESSED, $event->fresh()->status);
        $this->assertSame(2, $intentos->n, 'El reintento tras el deadlock no está acotado a lo esperado.');

        $conversation = $this->conversation();
        $this->assertSame(1, MarketingMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')->count(),
            'El reintento tras el deadlock duplicó el mensaje entrante.');
    }

    // ── Utilidad ────────────────────────────────────────────────────────

    /**
     * Ejecuta el job de verdad, controlando en qué número de intento va.
     *
     * `attempts()` viene normalmente del driver de cola; sin él, el job no
     * sabría cuándo se le acaban las oportunidades y nunca marcaría `dead`.
     */
    private function runJob(int $eventId, int $attempts = 1): void
    {
        $job = new class($eventId, $attempts) extends ProcessMetaWebhookEvent
        {
            public function __construct(int $eventId, private int $forcedAttempts)
            {
                parent::__construct($eventId);
            }

            public function attempts(): int
            {
                return $this->forcedAttempts;
            }
        };

        $job->handle(
            app(\App\Services\Meta\MetaWebhookService::class),
            app(\App\Services\Meta\MetaLeadService::class),
            app(MetaConversationService::class),
            app(MarketingInboundMessageRouter::class),
            app(\App\Services\Marketing\MarketingMessageDispatcher::class),
        );
    }
}
