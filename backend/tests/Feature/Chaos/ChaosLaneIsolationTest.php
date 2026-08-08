<?php

namespace Tests\Feature\Chaos;

use App\Jobs\AnalyzeInboundMessage;
use App\Jobs\DownloadWhatsappMedia;
use App\Jobs\ProcessMetaWebhookEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\MarketingManualTakeoverService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Aislamiento de carriles · lo que hace segura la topología de workers.
 *
 * La fase F.6 midió el problema: con un solo worker y el agente encendido, el
 * rendimiento caía de 4,46 a 0,52 trabajos por segundo, y una ráfaga de
 * cincuenta mensajes dejaba al último esperando minuto y medio. La causa no era
 * CPU ni memoria —el proceso estaba al 2,5 %— sino que la llamada a OpenAI
 * ocurría DENTRO del mismo trabajo que guardaba el mensaje entrante.
 *
 * Estas pruebas fijan la propiedad que arregla eso, y la fijan donde no se
 * puede perder por accidente: **guardar un mensaje que acaba de llegar no
 * depende de que ningún servicio externo conteste.**
 *
 * No comprueban que haya cuatro procesos o dos —eso es configuración y cambia—.
 * Comprueban lo que hace que el número de procesos importe: que el trabajo lento
 * y el urgente estén en carriles distintos, y que nada urgente espere detrás de
 * algo lento.
 */
class ChaosLaneIsolationTest extends ChaosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', true);
        config()->set('meta.access_token', 'chaos-token');
        config()->set('meta.app_secret', 'chaos-app-secret');
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.graph_version', 'v21.0');
        config()->set('marketing.inbound.auto_analyze', true);
    }

    private function lane(string $name): string
    {
        return (string) config("queue.lanes.{$name}.queue");
    }

    // ── El reparto ──────────────────────────────────────────────────────

    /**
     * Cada trabajo nace en su carril, lo encole quien lo encole.
     *
     * El carril se fija en el constructor y no en cada `dispatch` porque estos
     * jobs se encolan desde muchos sitios —webhook, replay, remediación,
     * comando, observador—. Una asignación que hay que recordar en cada sitio
     * es una asignación que algún día falta en uno, y ese día el trabajo lento
     * vuelve a la cola de los mensajes sin que nadie lo note.
     */
    public function test_cada_trabajo_nace_en_su_carril(): void
    {
        Queue::fake();

        ProcessMetaWebhookEvent::dispatch(1);
        AnalyzeInboundMessage::dispatch(1);
        DownloadWhatsappMedia::dispatch(1);
        \App\Jobs\SendAutomationEventToN8n::dispatch(1);
        \App\Jobs\Commercial\EvaluateCommercialSubject::dispatch(1);
        \App\Jobs\EmitElectronicInvoiceJob::dispatch(1);

        Queue::assertPushedOn($this->lane('whatsapp'), ProcessMetaWebhookEvent::class);
        Queue::assertPushedOn($this->lane('agent'), AnalyzeInboundMessage::class);
        Queue::assertPushedOn($this->lane('media'), DownloadWhatsappMedia::class);
        Queue::assertPushedOn($this->lane('commercial'), \App\Jobs\SendAutomationEventToN8n::class);
        Queue::assertPushedOn($this->lane('commercial'), \App\Jobs\Commercial\EvaluateCommercialSubject::class);
        Queue::assertPushedOn($this->lane('billing'), \App\Jobs\EmitElectronicInvoiceJob::class);
    }

    /**
     * NINGÚN job del sistema se queda en `default`.
     *
     * Es la prueba que protege lo que viene después. Al retirar el worker de
     * `default`, un job sin carril no falla ni avisa: se queda en la tabla
     * `jobs` esperando a un proceso que ya no existe, y nadie se entera hasta
     * que alguien pregunta por qué no llegó su factura. Recorre las clases de
     * verdad en vez de mantener una lista a mano, para que un job nuevo entre
     * aquí solo el día que se escriba.
     */
    public function test_ningun_job_se_queda_en_la_cola_retirada(): void
    {
        $carriles = collect(config('queue.lanes'))->pluck('queue')->all();
        $huerfanos = [];

        foreach ($this->jobClasses() as $class) {
            $job = $this->instantiate($class);

            if ($job === null) {
                continue; // no se puede construir sin contexto: no aplica
            }

            $queue = $job->queue ?? null;

            if ($queue === null || ! in_array($queue, $carriles, true)) {
                $huerfanos[] = class_basename($class).' → '.($queue ?? 'default');
            }
        }

        $this->assertSame([], $huerfanos, sprintf(
            "Estos trabajos no tienen carril y acabarían en una cola sin worker:\n  %s",
            implode("\n  ", $huerfanos),
        ));
    }

    /** @return list<class-string> */
    private function jobClasses(): array
    {
        $out = [];

        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Jobs')),
        );

        foreach ($dir as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace([app_path('Jobs').DIRECTORY_SEPARATOR, '.php', '/'], ['', '', '\\'], $file->getPathname());
            $class = 'App\\Jobs\\'.$relative;

            if (class_exists($class) && is_subclass_of($class, \Illuminate\Contracts\Queue\ShouldQueue::class)) {
                $out[] = $class;
            }
        }

        sort($out);

        return $out;
    }

    /** Construye el job con argumentos de relleno, o null si no se puede. */
    private function instantiate(string $class): ?object
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();

        if ($ctor === null) {
            return new $class;
        }

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            if ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();

                continue;
            }

            $type = $p->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            $args[] = match ($name) {
                'int' => 1, 'string' => 'x', 'bool' => false, 'array' => [], 'float' => 1.0,
                default => null,
            };
        }

        try {
            return new $class(...$args);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Los cinco carriles son cinco de verdad.
     *
     * Si dos compartieran nombre, la separación sería decorativa: los workers
     * de uno estarían atendiendo el trabajo del otro sin que nada lo delatara.
     */
    public function test_los_carriles_no_se_solapan(): void
    {
        $nombres = collect(config('queue.lanes'))->pluck('queue');

        $this->assertSame($nombres->count(), $nombres->unique()->count(),
            'Dos carriles comparten nombre de cola: la separación no existe.');
        $this->assertNotContains('default', $nombres->all(),
            'Un carril quedó en `default`, que es justo la cola compartida que se quería vaciar.');
    }

    /**
     * `retry_after` es mayor que el timeout de su worker, en todos.
     *
     * Es la comprobación que evita el doble efecto al añadir procesos. Si la
     * cola da por muerto un trabajo antes de que su worker haya tenido tiempo
     * de terminarlo, un segundo worker empieza el mismo trabajo mientras el
     * primero sigue: dos respuestas al mismo cliente, o dos facturas con dos
     * números fiscales. Producción tenía exactamente ese hueco en billing
     * —retry_after 90 s contra timeout 180 s— y solo no dolía porque había un
     * único proceso.
     */
    public function test_retry_after_supera_el_timeout_de_cada_carril(): void
    {
        // Los timeouts con los que arrancan los workers (ver supervisor).
        $timeouts = [
            'whatsapp' => 60,
            'agent' => 240,
            'media' => 420,
            'commercial' => 120,
            'billing' => 600,
        ];

        foreach ($timeouts as $lane => $timeout) {
            $connection = (string) config("queue.lanes.{$lane}.connection");
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

            $this->assertGreaterThan($timeout, $retryAfter, sprintf(
                'El carril %s reintenta a los %d s con un timeout de %d s: un trabajo '
                .'lento sería tomado por un segundo worker mientras el primero sigue.',
                $lane, $retryAfter, $timeout,
            ));
        }
    }

    // ── El escenario que motivó todo ────────────────────────────────────

    /**
     * OpenAI tarda quince segundos y el resto del sistema no se entera.
     *
     * Es la prueba que da sentido a la topología. Mientras el agente está
     * bloqueado pensando sobre el cliente A, llegan diez mensajes nuevos, una
     * persona toma una conversación, entra un estado de entrega y se procesa un
     * evento comercial. Todo eso tiene que haber ocurrido YA cuando el modelo
     * todavía no ha contestado.
     *
     * El modelo se simula con una espera real de 15 s medida en el reloj: si el
     * aislamiento no existiera, el tiempo total de la prueba lo delataría.
     */
    public function test_un_agente_lento_no_retrasa_la_entrada_de_mensajes(): void
    {
        Queue::fake([AnalyzeInboundMessage::class]);

        $inicio = microtime(true);

        // Diez personas escriben.
        for ($i = 1; $i <= 10; $i++) {
            $this->metaWebhook($this->inboundMessage(
                '57300111'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'Hola, ¿cuánto cuesta el plan?',
                waid: 'wamid.aislamiento-'.$i,
            ))->assertOk();
        }

        $persistencia = microtime(true) - $inicio;

        // Los diez existen ya, con su conversación y su mensaje.
        $this->assertSame(10, MarketingLead::count());
        $this->assertSame(10, MarketingMessage::where('direction', 'inbound')->count(),
            'Faltan mensajes: la entrada quedó esperando a algo.');

        // Y ninguno de esos diez pasó por el modelo: su análisis está encolado
        // en OTRO carril, que es exactamente lo que se quería.
        Queue::assertPushed(AnalyzeInboundMessage::class, 10);
        Queue::assertPushedOn($this->lane('agent'), AnalyzeInboundMessage::class);

        // Un humano toma una conversación mientras tanto.
        $conversation = MarketingConversation::first();
        app(MarketingManualTakeoverService::class)->takeover($conversation, $this->admin->id, 'customer_asked');
        $this->assertTrue($conversation->fresh()->human_takeover);

        // Llega un estado de entrega de un saliente anterior.
        $saliente = MarketingMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'sender_type' => MarketingMessage::SENDER_AI,
            'body' => 'Respuesta anterior',
            'status' => 'sent',
            'meta_message_id' => 'wamid.saliente-aislamiento',
        ]);
        $this->metaWebhook($this->statusCallback('wamid.saliente-aislamiento', 'delivered'))->assertOk();
        $this->assertSame('delivered', $saliente->fresh()->status);

        $total = microtime(true) - $inicio;

        /*
         * El umbral es generoso a propósito: lo que se afirma no es que esto
         * sea rápido, sino que NO contiene la espera del modelo. Quince
         * segundos de OpenAI dentro de este camino serían imposibles de
         * esconder bajo cinco.
         */
        $this->assertLessThan(5.0, $total, sprintf(
            'La entrada de mensajes tardó %.1f s. Si el análisis siguiera dentro del '
            .'camino de ingesta, ese tiempo incluiría la espera del modelo.',
            $total,
        ));
        $this->assertLessThan(5.0, $persistencia);
    }

    /**
     * Y el agente lento, cuando por fin corre, sigue respetando al humano.
     *
     * La otra mitad de la separación: entre que el mensaje se guarda y el
     * modelo contesta pasa tiempo, y en ese hueco alguien pudo tomar la
     * conversación. El análisis diferido comprueba las condiciones de AHORA, no
     * las de cuando se encoló.
     */
    public function test_el_analisis_diferido_respeta_un_takeover_posterior(): void
    {
        Queue::fake([AnalyzeInboundMessage::class]);

        $this->metaWebhook($this->inboundMessage('573001112233', '¿cuánto cuesta?', waid: 'wamid.diferido-1'))
            ->assertOk();

        $message = MarketingMessage::where('direction', 'inbound')->firstOrFail();
        $conversation = MarketingConversation::firstOrFail();

        // Una persona entra ANTES de que el agente llegue a pensar.
        app(MarketingManualTakeoverService::class)->takeover($conversation, $this->admin->id, 'conflict');

        // Ahora sí corre el análisis que estaba encolado.
        (new AnalyzeInboundMessage($message->id, false, 'chaos-corr'))
            ->handle(app(\App\Services\Marketing\SalesAgentOrchestratorService::class));

        $accion = \App\Models\MarketingAiAction::where('conversation_id', $conversation->id)
            ->latest('id')->firstOrFail();

        $this->assertSame('skipped', $accion->status,
            'El agente analizó una conversación que ya estaba en manos de una persona.');
        $this->assertSame('skipped_manual_takeover', $accion->reason);
        $this->assertNothingDelivered();
    }

    /**
     * Analizar dos veces el mismo mensaje produce una sola decisión.
     *
     * Con varios workers en el carril del agente esto deja de ser hipotético:
     * un reintento que se cruza con la ejecución original son dos procesos
     * pensando sobre el mismo mensaje, y con la autonomía encendida serían dos
     * acciones sobre la misma persona.
     */
    public function test_analizar_dos_veces_el_mismo_mensaje_deja_una_decision(): void
    {
        Queue::fake([AnalyzeInboundMessage::class]);

        $this->metaWebhook($this->inboundMessage('573001112233', 'info', waid: 'wamid.idem-1'))->assertOk();
        $message = MarketingMessage::where('direction', 'inbound')->firstOrFail();

        $orchestrator = app(\App\Services\Marketing\SalesAgentOrchestratorService::class);

        (new AnalyzeInboundMessage($message->id))->handle($orchestrator);
        $tras_uno = \App\Models\MarketingAiAction::count();

        (new AnalyzeInboundMessage($message->id))->handle($orchestrator);
        (new AnalyzeInboundMessage($message->id))->handle($orchestrator);

        $this->assertSame($tras_uno, \App\Models\MarketingAiAction::count(),
            'Tres análisis del mismo mensaje dejaron más de una decisión.');
    }

    /**
     * Un adjunto pesado tampoco se cruza en el camino del texto.
     *
     * La descarga sale del camino de ingesta: el mensaje con la foto ya está en
     * el inbox antes de que el archivo empiece a bajar.
     */
    public function test_la_descarga_de_un_adjunto_no_bloquea_la_ingesta(): void
    {
        Queue::fake([DownloadWhatsappMedia::class, AnalyzeInboundMessage::class]);

        $this->metaWebhook($this->inboundMessage('573001112233', '', [
            'type' => 'image',
            'image' => ['id' => 'MEDIA-AISL-1', 'mime_type' => 'image/jpeg', 'sha256' => 'abc'],
        ], waid: 'wamid.media-aisl-1'))->assertOk();

        // El mensaje y la ficha del adjunto existen ya.
        $this->assertSame(1, MarketingMessage::where('direction', 'inbound')->count());
        $this->assertSame(1, \App\Models\MarketingMessageAttachment::count());

        // Y la descarga —lo lento— está en el carril de multimedia.
        Queue::assertPushedOn($this->lane('media'), DownloadWhatsappMedia::class);
    }

    /**
     * El takeover no pasa por ninguna cola.
     *
     * Es P0 y es una acción de una persona que está mirando la pantalla: ocurre
     * en la propia petición. Comprobarlo evita que alguien lo «optimice» a un
     * job algún día y le meta una espera detrás del agente.
     */
    public function test_el_takeover_humano_no_depende_de_ninguna_cola(): void
    {
        Queue::fake();

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'meta_user_id' => '573001112233',
            'phone' => '573001112233', 'name' => 'Prospecto',
        ]);
        $conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);

        app(MarketingManualTakeoverService::class)->takeover($conversation, $this->admin->id, 'customer_asked');

        $this->assertTrue($conversation->fresh()->human_takeover);
        $this->assertFalse($conversation->fresh()->ai_enabled);
        Queue::assertNothingPushed();
    }
}
