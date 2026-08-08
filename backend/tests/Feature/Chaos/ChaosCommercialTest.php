<?php

namespace Tests\Feature\Chaos;

use App\Models\CommercialApproval;
use App\Models\CommercialToolInvocation;
use App\Models\Incident;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Commercial\ApprovalQueueService;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolExecutor;
use App\Services\IronGuard\ChannelHealthDetector;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\Marketing\MarketingManualTakeoverService;
use App\Services\Marketing\WhatsappOutboxService;
use App\Services\Wompi\PaymentStateMachine;
use Illuminate\Support\Facades\Http;

/**
 * F6.38 – F6.45 · El cerebro comercial falla a medio camino.
 *
 * Aquí los fallos ya no vienen de fuera: vienen de que una operación comercial
 * tiene varios pasos y no todos pueden ser atómicos. Cobrar, activar, agendar y
 * vincular la app son cosas distintas, y una puede salir bien mientras la
 * siguiente se rompe.
 *
 * La regla que las ordena: **lo que ya es cierto no se deshace porque lo
 * siguiente falle.** Si alguien pagó, pagó, aunque después no se pueda vincular
 * su cuenta. Lo contrario —revertir hacia atrás para dejar todo «limpio»— es
 * cómo se le quita la membresía a alguien que la pagó.
 *
 * Y una que gana a todas: **si hay una persona al mando de la conversación, la
 * IA se calla.** No «se calla la próxima vez»: se calla ya, incluso para lo que
 * dejó a medias antes de que el humano entrara.
 */
class ChaosCommercialTest extends ChaosTestCase
{
    private function lead(): MarketingLead
    {
        return MarketingLead::create([
            'channel' => 'whatsapp', 'meta_user_id' => '573001112233',
            'phone' => '573001112233', 'name' => 'Prospecto Chaos',
        ]);
    }

    private function conversationFor(MarketingLead $lead): MarketingConversation
    {
        return MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    private function executor(): ToolExecutor
    {
        config()->set('commercial.tools.agenda', true);
        config()->set('commercial.tools.payments', true);
        config()->set('commercial.autonomy_enabled', true);

        return app(ToolExecutor::class);
    }

    // ── F6.38 ───────────────────────────────────────────────────────────

    /**
     * F6.38 — La agenda falla al crear la cita.
     *
     * Lo prohibido es decirle al cliente «listo, te esperamos el martes» cuando
     * no hay nada agendado. Una cita que el cliente cree tener y el gimnasio no
     * es peor que no haberla ofrecido: la persona se presenta y no la esperan.
     */
    public function test_f638_fallo_de_agenda_no_confirma_una_cita_que_no_existe(): void
    {
        $lead = $this->lead();

        $this->app->bind(\App\Services\Marketing\MarketingAppointmentService::class, fn () => new class(app(\App\Services\Marketing\MarketingAppointmentAuthorizationService::class)) extends \App\Services\Marketing\MarketingAppointmentService
        {
            public function create(array $data, ?int $createdBy): MarketingAppointment
            {
                throw new \RuntimeException('la agenda no responde');
            }
        });

        $result = $this->executor()->execute('book_appointment', [
            'type' => 'visit',
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i'),
        ], new ToolContext(lead: $lead, requestedBy: 'engine', correlationId: 'chaos-agenda'));

        $this->assertFalse($result->successful(), 'El fallo de la agenda se reportó como éxito.');
        $this->assertSame(0, MarketingAppointment::count(), 'Quedó una cita creada pese al fallo.');

        // Y queda auditado, en un estado del que se puede salir.
        $invocation = CommercialToolInvocation::where('tool', 'book_appointment')->latest('id')->first();
        $this->assertNotNull($invocation);
        $this->assertSame(CommercialToolInvocation::STATUS_FAILED, $invocation->status);
        $this->assertTrue((bool) $invocation->retryable,
            'Un fallo pasajero de la agenda quedó como definitivo: la venta muere por un error de red.');
    }

    // ── F6.39 ───────────────────────────────────────────────────────────

    /**
     * F6.39 — Dos reservas a la vez para la misma persona.
     *
     * El cliente dice «sí, el martes» y el mensaje llega dos veces, o dos
     * workers atienden a la vez. Solo una puede ocupar el hueco.
     */
    public function test_f639_dos_reservas_simultaneas_dejan_una_sola_cita(): void
    {
        $lead = $this->lead();
        $cuando = now()->addDays(2)->format('Y-m-d H:i');

        $primera = $this->executor()->execute('book_appointment', [
            'type' => 'visit', 'scheduled_at' => $cuando,
        ], new ToolContext(lead: $lead, requestedBy: 'engine', correlationId: 'chaos-a'));

        $segunda = $this->executor()->execute('book_appointment', [
            'type' => 'visit', 'scheduled_at' => $cuando,
        ], new ToolContext(lead: $lead, requestedBy: 'engine', correlationId: 'chaos-b'));

        $this->assertTrue($primera->successful());
        $this->assertSame(1, MarketingAppointment::count(),
            'La misma persona acabó con dos citas: alguien del equipo pierde una hora.');
        $this->assertSame('skipped', $segunda->status,
            'La segunda reserva no se reconoció como repetida.');
    }

    /**
     * F6.39b — Y la misma intención repetida se deduplica por clave.
     *
     * La clave de idempotencia se reclama en la base ANTES de ejecutar, así que
     * un reintento se encuentra el trabajo tomado aunque el primero siga vivo.
     */
    public function test_f639b_la_misma_intencion_no_se_ejecuta_dos_veces(): void
    {
        $lead = $this->lead();
        $context = (new ToolContext(lead: $lead, requestedBy: 'engine', correlationId: 'chaos-idem'))
            ->withIdempotencyKey('book:chaos:1');

        $cuando = now()->addDays(3)->format('Y-m-d H:i');

        $a = $this->executor()->execute('book_appointment', ['type' => 'call', 'scheduled_at' => $cuando], $context);
        $b = $this->executor()->execute('book_appointment', ['type' => 'call', 'scheduled_at' => $cuando], $context);

        $this->assertTrue($a->successful());
        $this->assertSame('skipped', $b->status);
        $this->assertSame(1, MarketingAppointment::count());
        $this->assertSame(1, CommercialToolInvocation::where('idempotency_key', 'book:chaos:1')->count());
    }

    // ── F6.40 ───────────────────────────────────────────────────────────

    /**
     * F6.40 — El pago entra y la vinculación de la app falla.
     *
     * El reflejo equivocado sería revertir para dejarlo «consistente». No: el
     * cliente pagó, y eso es un hecho que ningún fallo posterior deshace. Lo
     * que falta —la app— es una incidencia secundaria, recuperable, que no
     * puede tocar el dinero ni la membresía.
     */
    public function test_f640_fallo_al_vincular_la_app_no_revierte_el_pago_ni_la_membresia(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 90000, 'duration_days' => 30, 'benefits' => '']);
        $member = $this->member();

        $tx = PaymentTransaction::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => 'IRON-CHAOS-APP-1',
            'idempotency_key' => 'chaos-app-1',
            'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => 90000, 'currency' => 'COP',
            'status' => PaymentStateMachine::PENDING,
            'member_id' => $member->id, 'user_id' => $member->user_id, 'plan_id' => $plan->id,
        ]);

        // La vinculación con la app revienta justo después de aprobar el pago.
        $this->app->bind(\App\Services\AppNotificationService::class, fn () => new class extends \App\Services\AppNotificationService
        {
            public function __construct() {}

            public function __call($name, $arguments)
            {
                throw new \RuntimeException('el servicio de la app no responde');
            }
        });

        \App\Services\Wompi\WompiTransactionService::make()
            ->transitionTo($tx, PaymentStateMachine::APPROVED);

        $tx->refresh();

        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status,
            'Un fallo de la app revirtió un pago que ya estaba aprobado.');
        $this->assertNotNull($tx->paid_at);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count(),
            'La venta desapareció porque falló algo posterior a ella.');

        // Y la membresía del miembro sigue en pie.
        $this->assertSame(\App\Models\Member::STATUS_ACTIVE, $member->fresh()->status);
    }

    // ── F6.41 ───────────────────────────────────────────────────────────

    /**
     * F6.41 — El ejecutor falla DESPUÉS de registrar la decisión.
     *
     * La decisión es lo que hace auditable al agente: qué se propuso, por qué y
     * cuándo. Si desapareciera al fallar la ejecución, no quedaría forma de
     * saber qué intentó hacer el sistema.
     */
    public function test_f641_la_decision_sobrevive_al_fallo_del_ejecutor_y_se_puede_reintentar(): void
    {
        $lead = $this->lead();

        $falla = true;
        $this->app->bind(\App\Services\Marketing\MarketingAppointmentService::class, function () use (&$falla) {
            return new class($falla, app(\App\Services\Marketing\MarketingAppointmentAuthorizationService::class)) extends \App\Services\Marketing\MarketingAppointmentService
            {
                public function __construct(private bool &$falla, $authz)
                {
                    parent::__construct($authz);
                }

                public function create(array $data, ?int $createdBy): MarketingAppointment
                {
                    if ($this->falla) {
                        throw new \RuntimeException('el ejecutor se cayó');
                    }

                    return parent::create($data, $createdBy);
                }
            };
        });

        $cuando = now()->addDays(4)->format('Y-m-d H:i');
        $result = $this->executor()->execute('book_appointment', [
            'type' => 'assessment', 'scheduled_at' => $cuando,
        ], new ToolContext(lead: $lead, requestedBy: 'engine', correlationId: 'chaos-exec'));

        $this->assertFalse($result->successful());

        $invocation = CommercialToolInvocation::where('tool', 'book_appointment')->latest('id')->first();
        $this->assertNotNull($invocation, 'La decisión desapareció al fallar la ejecución.');
        $this->assertSame('chaos-exec', $invocation->correlation_id);
        $this->assertNotEmpty($invocation->arguments, 'No quedó registrado con qué se intentó ejecutar.');
        $this->assertSame(CommercialToolInvocation::STATUS_FAILED, $invocation->status);

        // Se reintenta con una intención nueva y ahora sí funciona: una cita.
        $falla = false;
        $retry = $this->executor()->execute('book_appointment', [
            'type' => 'assessment', 'scheduled_at' => $cuando,
        ], new ToolContext(lead: $lead, requestedBy: 'engine', correlationId: 'chaos-exec'));

        $this->assertTrue($retry->successful());
        $this->assertSame(1, MarketingAppointment::count(), 'El reintento duplicó la cita.');
    }

    // ── F6.42 ───────────────────────────────────────────────────────────

    /**
     * F6.42 — Un humano toma el control con un reintento de la IA en vuelo.
     *
     * El escenario crítico de esta familia, y el más fácil de no ver: no hay
     * fallo de infraestructura, no hay excepción, no hay nada roto. Solo hay
     * una respuesta que la IA generó hace media hora, que no salió por un 429,
     * y que sigue esperando su turno en la cola de reintentos.
     *
     * Mientras tanto una persona entró en la conversación —porque el cliente se
     * quejó, o porque el agente metió la pata— y está hablando ella. Que la
     * respuesta vieja del bot salga ahora es exactamente lo que el takeover
     * existe para impedir: el cliente recibe al bot justo después de que un
     * humano le dijera que a partir de ahora le atiende él.
     */
    public function test_f642_el_takeover_humano_cancela_el_reintento_pendiente_de_la_ia(): void
    {
        config()->set('meta.enabled', true);
        config()->set('meta.access_token', 'chaos-token');
        config()->set('meta.app_secret', 'chaos-app-secret');
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.graph_version', 'v21.0');

        $lead = $this->lead();

        /*
         * Un único stub con interruptor. Encadenar dos `Http::fake()` no
         * serviría: el primero que empareja gana, así que Meta seguiría dando
         * 429 y el mensaje no saldría —y la prueba pasaría en verde atribuyendo
         * al takeover un silencio que en realidad causó el andamio—. Aquí Meta
         * SÍ acepta al final; si algo no sale, es porque alguien lo impidió.
         */
        $metaRechaza = true;

        Http::fake(['graph.facebook.com/*' => function () use (&$metaRechaza) {
            return $metaRechaza
                ? Http::response(['error' => ['message' => 'Rate limit hit', 'code' => 80007]], 429)
                : Http::response(['messages' => [['id' => 'wamid.no-deberia-salir']]], 200);
        }]);

        $envio = app(\App\Services\Marketing\MarketingMessageDispatcher::class)
            ->dispatchWhatsapp($lead, 'whatsapp', 'Te paso el link de pago', [],
                MarketingMessage::SENDER_AI);

        $mensaje = MarketingMessage::findOrFail($envio['message_id']);
        $this->assertSame(WhatsappOutboxService::STATUS_FAILED, $mensaje->status);
        $this->assertNotNull($mensaje->next_attempt_at);

        // Entra una persona.
        $conversation = MarketingConversation::findOrFail($envio['conversation_id']);
        app(MarketingManualTakeoverService::class)->takeover($conversation, $this->admin->id, 'agent_error');

        // Vence la espera y Meta ya acepta mensajes: el único motivo posible
        // para que esto no salga es que el takeover lo impida.
        $this->travel(2)->hours();
        $metaRechaza = false;

        $this->assertTrue(
            app(WhatsappOutboxService::class)->due()->contains('id', $mensaje->id),
            'El mensaje ni siquiera estaba pendiente de reintento: la prueba no probaría nada.',
        );

        $this->artisan('marketing:retry-outbox')->assertSuccessful();

        $mensaje->refresh();

        $this->assertNull($mensaje->meta_message_id, sprintf(
            'La respuesta de la IA salió DESPUÉS de que una persona tomara el control '
            .'(estado «%s»). El cliente recibe al bot justo después de que un humano '
            .'le dijera que le atiende él.',
            $mensaje->status,
        ));
        $this->assertNotSame(WhatsappOutboxService::STATUS_SENT, $mensaje->status);
    }

    /**
     * F6.42b — Pero el mensaje que escribió el HUMANO sí se reintenta.
     *
     * La distinción importa tanto como el bloqueo. Si el takeover cancelara
     * todo lo pendiente, el asesor que acaba de tomar el control perdería su
     * propio mensaje por un 429, que es justo lo contrario de lo que quería.
     */
    public function test_f642b_el_takeover_no_cancela_el_mensaje_del_propio_asesor(): void
    {
        config()->set('meta.enabled', true);
        config()->set('meta.access_token', 'chaos-token');
        config()->set('meta.app_secret', 'chaos-app-secret');
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.graph_version', 'v21.0');

        $lead = $this->lead();
        $conversation = $this->conversationFor($lead);
        app(MarketingManualTakeoverService::class)->takeover($conversation, $this->admin->id, 'customer_asked');

        $metaRechaza = true;

        Http::fake(['graph.facebook.com/*' => function () use (&$metaRechaza) {
            return $metaRechaza
                ? Http::response(['error' => ['message' => 'Rate limit hit', 'code' => 80007]], 429)
                : Http::response(['messages' => [['id' => 'wamid.del-asesor']]], 200);
        }]);

        $envio = app(\App\Services\Marketing\MarketingMessageDispatcher::class)
            ->dispatchWhatsapp($lead, 'whatsapp', 'Hola, soy Ana del gimnasio', [],
                MarketingMessage::SENDER_HUMAN, $this->admin->id);

        $mensaje = MarketingMessage::findOrFail($envio['message_id']);
        $this->assertSame(WhatsappOutboxService::STATUS_FAILED, $mensaje->status);

        $this->travel(2)->hours();
        $metaRechaza = false;

        $this->artisan('marketing:retry-outbox')->assertSuccessful();

        $this->assertSame('wamid.del-asesor', $mensaje->fresh()->meta_message_id,
            'El mensaje que escribió la persona no se entregó: el takeover se llevó por delante su propio mensaje.');
    }

    // ── F6.43 / F6.44 ───────────────────────────────────────────────────

    private function approval(string $key = 'chaos-approval-1'): CommercialApproval
    {
        return app(ApprovalQueueService::class)->request(
            type: CommercialApproval::TYPE_DISCOUNT,
            justification: 'Descuento excepcional para cerrar la venta.',
            idempotencyKey: $key,
            context: ['ttl_hours' => 1, 'requested_by' => 'agent'],
        );
    }

    /**
     * F6.43 — La aprobación caduca mientras el ejecutor esperaba.
     *
     * Una autorización con fecha es una autorización con fecha. Ejecutarla
     * tarde es actuar sin permiso, aunque el permiso existiera hace un rato.
     */
    public function test_f643_una_aprobacion_vencida_no_se_ejecuta(): void
    {
        $approval = $this->approval();

        $resultado = app(ApprovalQueueService::class)->approve($approval, $this->admin);
        $this->assertTrue($resultado['ok']);

        // Pasa el tiempo mientras el ejecutor estaba ocupado.
        $this->travel(3)->hours();

        $ejecucion = app(ApprovalQueueService::class)->markExecuted($approval->fresh(), 'hecho');

        $this->assertFalse($ejecucion['ok'], 'Se ejecutó una autorización vencida.');
        $this->assertSame('expired', $ejecucion['code']);
    }

    /**
     * F6.44 — La misma aprobación, aprobada dos veces.
     *
     * Dos administradores pulsan «aprobar» casi a la vez, o alguien recarga la
     * pantalla. Es un permiso, no dos, y sobre todo: una sola ejecución.
     */
    public function test_f644_aprobar_dos_veces_produce_una_sola_ejecucion(): void
    {
        $approval = $this->approval();
        $servicio = app(ApprovalQueueService::class);

        $primera = $servicio->approve($approval, $this->admin);
        $segunda = $servicio->approve($approval->fresh(), $this->admin);

        $this->assertTrue($primera['ok']);
        $this->assertFalse($segunda['ok'], 'La segunda aprobación se aceptó como si fuera nueva.');

        $ejecucionA = $servicio->markExecuted($approval->fresh(), 'hecho');
        $ejecucionB = $servicio->markExecuted($approval->fresh(), 'hecho otra vez');

        $this->assertTrue($ejecucionA['ok']);
        $this->assertFalse($ejecucionB['ok'], 'La acción autorizada se ejecutó dos veces.');
    }

    // ── F6.45 ───────────────────────────────────────────────────────────

    /**
     * F6.45 — IRON GUARD se cae.
     *
     * El detector es una lupa, no un órgano vital. Si se rompe se pierde
     * visibilidad, y eso es malo; lo que sería inaceptable es que además
     * parara el canal comercial. El negocio no puede depender de que su
     * sistema de vigilancia esté sano.
     */
    public function test_f645_con_iron_guard_caido_el_canal_comercial_sigue_operando(): void
    {
        $this->app->bind(IncidentRecorder::class, fn () => new class extends IncidentRecorder
        {
            public function record(array $data): Incident
            {
                throw new \RuntimeException('el detector está caído');
            }
        });

        // Un mensaje entrante real, con el detector roto de fondo.
        $this->metaWebhook($this->inboundMessage('573001112233', 'Hola, ¿cuánto cuesta?'))->assertOk();

        $lead = MarketingLead::where('meta_user_id', '573001112233')->first();
        $this->assertNotNull($lead, 'Con IRON GUARD caído se dejó de atender al prospecto.');

        $conversation = MarketingConversation::where('lead_id', $lead->id)->first();
        $this->assertNotNull($conversation);
        $this->assertSame(1, MarketingMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')->count());

        // Y una herramienta comercial sigue funcionando.
        $result = $this->executor()->execute('book_appointment', [
            'type' => 'visit', 'scheduled_at' => now()->addDays(5)->format('Y-m-d H:i'),
        ], new ToolContext(lead: $lead, requestedBy: 'engine', correlationId: 'chaos-guard'));

        $this->assertTrue($result->successful(),
            'El canal comercial dejó de funcionar porque el sistema de vigilancia estaba roto.');
    }

    /**
     * F6.45b — Y cuando IRON GUARD vuelve, ve lo que pasó mientras no estaba.
     *
     * La observabilidad degradada tiene que ser recuperable: los hechos siguen
     * en la base y el detector los encuentra al volver.
     */
    public function test_f645b_al_recuperarse_iron_guard_detecta_lo_ocurrido(): void
    {
        for ($i = 0; $i < 4; $i++) {
            \App\Models\MetaWebhookEvent::create([
                'correlation_id' => 'chaos-recover-'.$i,
                'payload_hash' => hash('sha256', 'chaos-recover-'.$i),
                'object' => 'whatsapp_business_account',
                'payload' => ['entry' => []],
                'payload_bytes' => 10, 'messages_count' => 1, 'statuses_count' => 0,
                'status' => \App\Models\MetaWebhookEvent::STATUS_DEAD,
                'attempts' => 3,
                'last_error_class' => 'RuntimeException',
                'last_error' => 'la dependencia estaba caída',
            ]);
        }

        app(ChannelHealthDetector::class)->scan();

        $incident = Incident::where('kind', 'events_dead')->first();
        $this->assertNotNull($incident, 'Al recuperarse, el detector no vio lo que había pasado.');
        $this->assertNoSecretsLeaked($incident);
    }
}
