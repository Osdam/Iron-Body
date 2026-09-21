<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAgentAction;
use App\Models\MarketingAiAction;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EL DÍA DE CORTESÍA: REGISTRAR NO ES AGENDAR.
 *
 * Iron Body invita a conocer el gimnasio a quien todavía está decidiendo. Lo
 * que ULTRON puede hacer es recoger el día y la hora y dejarlo ANOTADO; quien
 * confirma es una persona. Si el agente dijera «quedaste agendado», alguien se
 * presentaría un día que nadie preparó, y eso es peor que no haber ofrecido
 * nada.
 *
 * Por eso lo que se mide aquí no es que el agente sepa hablar de la cortesía
 * —eso es del prompt— sino que el EFECTO sea verdad: que exista una fila, que
 * sea una sola, que diga «solicitada» y no «agendada», que respete el horario,
 * y que cambiar de día no deje tres visitas fantasma en la agenda del equipo.
 */
class CortesiaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        Http::fake();

        // Un lunes a las 9 de la mañana en Neiva, para que las fechas del test
        // no dependan del día en que se ejecute.
        Carbon::setTestNow(Carbon::parse('2026-09-21 14:00', 'UTC'));

        Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true]);

        // El horario aprobado, con su ventana legible por máquina en la MISMA
        // entrada: una sola fuente para la frase y para el dato.
        MarketingKnowledgeItem::create([
            'category' => 'schedule',
            'key' => 'schedule.opening_hours',
            'title' => 'Horario',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados y domingos de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true,
            'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
            'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
            'metadata' => ['windows' => [
                'lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'],
                'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'],
                'sabado' => ['08:00', '14:00'], 'domingo' => ['08:00', '14:00'],
            ]],
        ]);

        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::DISCOVERY]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function turno(string $texto, string $wamid, array $overrides = []): TestResponse
    {
        $m = MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $texto, 'meta_message_id' => $wamid, 'status' => 'received']);
        $token = (string) $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h())->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $wamid,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => array_merge([
                'strategy_goal' => 'book_visit', 'next_state' => P::DISCOVERY, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Listo, queda registrada tu solicitud de día de cortesía. El equipo la revisará.',
                'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'offer_appointment',
                'confidence' => 0.9, 'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST],
                'courtesy_action' => 'request',
            ], $overrides),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ], $this->h());
    }

    private function solicitud(): ?MarketingAgentAction
    {
        return MarketingAgentAction::query()->where('marketing_conversation_id', $this->conversation->id)->latest('id')->first();
    }

    private function ultimaAccion(): array
    {
        return MarketingAiAction::latest('id')->first()->metadata ?? [];
    }

    // ── El contexto que hace posible todo lo demás ───────────────────────────

    /** El modelo no puede saludar por la franja si no sabe qué hora es. */
    public function test_el_contexto_lleva_la_hora_del_gimnasio_ya_interpretada(): void
    {
        $m = MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => 'hola', 'meta_message_id' => 'w.ctx', 'status' => 'received']);
        $r = $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h())->assertOk();

        // 14:00 UTC son las 9 de la mañana en Neiva: la diferencia importa, y el
        // modelo no tiene que calcularla.
        $this->assertSame('America/Bogota', $r->json('context.business_time.timezone'));
        $this->assertSame('2026-09-21', $r->json('context.business_time.date'));
        $this->assertSame('09:00', $r->json('context.business_time.time'));
        $this->assertSame('lunes', $r->json('context.business_time.weekday'));
        $this->assertSame('manana', $r->json('context.business_time.daypart'));
        $this->assertSame('buenos dias', $r->json('context.business_time.greeting'));
        $this->assertSame('2026-09-22', $r->json('context.business_time.tomorrow'));

        // Y el horario viaja también como dato, no sólo como frase.
        $this->assertSame(['08:00', '14:00'], $r->json('context.gym.opening_windows.sabado'));
    }

    // ── F · fecha y hora completas: se registra UNA vez ──────────────────────

    public function test_f_una_solicitud_completa_queda_registrada_una_sola_vez(): void
    {
        $this->turno('quiero ir el sabado a las 10', 'w.f1', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
        ])->assertOk();

        $this->assertSame(1, MarketingAgentAction::count());
        $a = $this->solicitud();

        $this->assertSame(MarketingAgentAction::STATUS_SUGGESTED, $a->status, 'solicitada no es agendada');
        $this->assertSame(MarketingAgentAction::TYPE_CREATE_APPOINTMENT, $a->action_type);
        $this->assertSame($this->lead->id, $a->marketing_lead_id);
        $this->assertSame($this->conversation->id, $a->marketing_conversation_id);
        $this->assertSame('ultron', data_get($a->payload, 'source'));
        $this->assertSame('2026-09-26', data_get($a->payload, 'requested_date'));
        $this->assertSame('10:00', data_get($a->payload, 'requested_time'));
        $this->assertTrue((bool) $a->requires_approval);

        // Y NO nace ninguna cita: la cita la crea una persona al aprobar.
        $this->assertSame(0, MarketingAppointment::count(), 'se agendó sin que nadie confirmara');

        // La memoria lo recuerda, para no volver a preguntar el día.
        $this->assertSame('requested', data_get($this->conversation->fresh()->memory, 'courtesy_request.status'));
    }

    // ── G · repetición: no duplica ───────────────────────────────────────────

    public function test_g_repetir_la_misma_peticion_no_crea_dos_solicitudes(): void
    {
        $this->turno('quiero ir el sabado a las 10', 'w.g1', ['courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00'])->assertOk();
        $this->turno('si, el sabado a las 10 porfa', 'w.g2', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
            'reply_draft' => 'Sí, ya la tengo anotada para el sábado. El equipo la revisará.',
        ])->assertOk();

        $this->assertSame(1, MarketingAgentAction::count(), 'la misma visita quedó dos veces en la agenda del equipo');
    }

    // ── H · cambio de día: actualiza, no acumula ─────────────────────────────

    public function test_h_cambiar_de_dia_actualiza_la_misma_solicitud(): void
    {
        $this->turno('quiero ir el sabado a las 10', 'w.h1', ['courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00'])->assertOk();
        $this->turno('mejor el domingo a la misma hora', 'w.h2', [
            'courtesy_date' => '2026-09-27', 'courtesy_time' => '10:00',
            'reply_draft' => 'Listo, la cambié al domingo. El equipo la revisará.',
        ])->assertOk();

        $this->assertSame(1, MarketingAgentAction::count(), 'se quedó basura histórica en la agenda');
        $this->assertSame('2026-09-27', data_get($this->solicitud()->payload, 'requested_date'));
    }

    // ── I · cancelación: se refleja de verdad ────────────────────────────────

    public function test_i_cancelar_deja_la_solicitud_cancelada_sin_borrarla(): void
    {
        $this->turno('quiero ir el sabado a las 10', 'w.i1', ['courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00'])->assertOk();

        $this->turno('ya no voy a poder ir, cancela', 'w.i2', [
            'courtesy_action' => 'cancel', 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Sin problema, cancelé la solicitud. Cuando quieras retomarla me escribes.',
        ])->assertOk();

        $a = $this->solicitud();
        $this->assertSame(1, MarketingAgentAction::count(), 'cancelar no puede borrar el rastro');
        $this->assertSame(MarketingAgentAction::STATUS_CANCELLED, $a->status);
    }

    // ── E · fuera de horario: no se registra ─────────────────────────────────

    public function test_e_una_hora_fuera_del_horario_no_se_registra(): void
    {
        // El sábado se cierra a las 2 de la tarde.
        $this->turno('el sabado a las 6 de la tarde', 'w.e1', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '18:00',
            'reply_draft' => 'Ese día atendemos hasta las 2 de la tarde. ¿Te sirve antes de esa hora?',
        ])->assertOk();

        $this->assertSame(0, MarketingAgentAction::count(), 'se registró una visita a una hora con el gimnasio cerrado');

        $tools = $this->ultimaAccion()['tools_executed'] ?? [];
        $this->assertNotContains(SalesIntents::TOOL_COURTESY_REQUEST, $tools);
    }

    /** @return array<string,array{0:?string,1:?string}> */
    public static function fechasQueNoSeRegistran(): array
    {
        return [
            'ayer' => ['2026-09-20', '10:00'],
            'a más de tres meses' => ['2027-01-30', '10:00'],
            'sin fecha' => [null, '10:00'],
            'sin hora' => ['2026-09-26', null],
            /*
             * La hora imposible vive AQUÍ y no en un test de validación.
             *
             * Lo estuvo: el controlador la paraba con `date_format:H:i` y un
             * 422. Esa misma regla rechazaba «9:00» —PHP no da por iguales
             * '9:00' y '09:00'—, que es una hora legítima y que la autoridad
             * acepta a propósito. El controlador era más estricto que la
             * autoridad a la que sirve, y quien lo pagaba era la persona, con
             * la petición entera caída. Ahora decide la autoridad, y decide lo
             * mismo para las dos: no se registra, y el turno sigue vivo.
             */
            'hora imposible' => ['2026-09-26', '25:00'],
        ];
    }

    #[DataProvider('fechasQueNoSeRegistran')]
    public function test_una_fecha_imposible_no_se_registra(?string $fecha, ?string $hora): void
    {
        $this->turno('quiero ir', 'w.mal.'.md5((string) $fecha.$hora), [
            'courtesy_date' => $fecha, 'courtesy_time' => $hora,
            'reply_draft' => '¿Qué día te gustaría venir a conocer el gimnasio?',
        ])->assertOk();

        $this->assertSame(0, MarketingAgentAction::count());
    }

    // ── La honestidad, que es el punto de todo esto ──────────────────────────

    /**
     * «Lo dejé registrado» puede decirse: es verdad y hay fila que lo prueba.
     *
     * Y no es gratis que funcione: esa frase engancha la invariante de
     * promesas —«queda registrada» + «solicitud» la clasifican como efecto
     * durable—, que hasta ahora sólo autorizaba la marca para el equipo. Sin
     * abrirle la puerta a la cortesía, el turno moría en 422 justo después de
     * haber escrito la solicitud correctamente.
     */
    public function test_decir_que_quedo_registrada_es_verdad_y_sale(): void
    {
        $r = $this->turno('quiero conocer el gym el sabado a las 10', 'w.hon1', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
            // «queda registrada» es la forma que engancha la invariante de
            // promesas (medido: «dejé registrada», en pasado, no la dispara).
            // Se usa la que SÍ la dispara, o este test no probaría nada.
            'reply_draft' => 'Listo, queda registrada tu solicitud de día de cortesía para el sábado. El equipo la revisará.',
        ])->assertOk();

        $this->assertSame(1, MarketingAgentAction::count());
        $saliente = (string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body;

        $this->assertStringContainsString('queda registrada', mb_strtolower($saliente));
        $this->assertStringNotContainsString('agendad', mb_strtolower($saliente));
        $this->assertStringNotContainsString('confirmad', mb_strtolower($saliente));
    }

    /**
     * Y si la cortesía NO se registra, la misma frase vuelve a ser mentira.
     *
     * Es la otra mitad: la excepción a la invariante no es un permiso
     * permanente para hablar de solicitudes, es el reflejo de un hecho. Sin
     * hecho, no hay frase.
     */
    public function test_sin_registro_la_frase_no_sale(): void
    {
        $r = $this->turno('el sabado a las 6 de la tarde', 'w.hon2', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '18:00',
            'reply_draft' => 'Listo, queda registrada tu solicitud de día de cortesía. El equipo la revisará.',
        ]);

        $r->assertStatus(422)->assertJsonPath('code', 'promised_effect_without_authority');
        $this->assertSame(0, MarketingAgentAction::count());
    }

    /** Y la palabra prohibida sigue prohibida: «gratis» no sale, cortesía sí. */
    public function test_la_palabra_gratis_sigue_sin_poder_salir(): void
    {
        $this->turno('puedo probar un dia?', 'w.gratis', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
            'reply_draft' => 'Claro, te regalamos un dia gratis para que conozcas el gimnasio.',
        ])->assertStatus(422);
    }

    /**
     * «Quedaste agendado» no sale mientras nadie lo haya confirmado.
     *
     * Es el daño concreto del §25: alguien hace el viaje a un gimnasio que no
     * lo espera, y eso no lo arregla ninguna disculpa porque el viaje ya está
     * hecho. Se midió que ni esta frase, ni «tu cortesía quedó confirmada», ni
     * «ya te reservé el cupo» disparaban la invariante de promesas: pasaban
     * limpias. La honestidad aquí no puede vivir en el prompt.
     */
    public function test_no_se_puede_decir_que_la_cortesia_quedo_agendada(): void
    {
        $this->turno('quiero ir el sabado a las 10', 'w.conf1', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
            'reply_draft' => 'Listo, quedaste agendado para el sábado a las 10. Cualquier cosa me escribes.',
        ])->assertOk();

        $saliente = mb_strtolower((string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body);

        $this->assertStringNotContainsString('quedaste agendado', $saliente);
        $this->assertNotSame('', trim($saliente), 'el turno no puede quedarse mudo');
        // Y la solicitud sí se registró: lo que cae es la frase, no el efecto.
        $this->assertSame(1, MarketingAgentAction::count());
        $this->assertNotNull(data_get($this->ultimaAccion(), 'courtesy_confirmation_dropped.code'));
    }

    /**
     * Y cuando el equipo YA confirmó, la misma frase es verdad y sale.
     *
     * Sin esta mitad, la guarda sería una mordaza: dejaría de poder hablarse
     * de una cita real.
     */
    public function test_con_la_cita_ya_confirmada_la_frase_sale(): void
    {
        \App\Models\MarketingAppointment::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'marketing_lead_id' => $this->lead->id,
            'marketing_conversation_id' => $this->conversation->id,
            'type' => \App\Models\MarketingAppointment::TYPE_VISIT,
            'status' => \App\Models\MarketingAppointment::STATUS_SCHEDULED,
            'title' => 'Día de cortesía',
            'scheduled_at' => now()->addDays(5),
        ]);

        $this->turno('nos vemos el sabado entonces', 'w.conf2', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
            'reply_draft' => 'Listo, te esperamos el sábado a las 10. Trae ropa cómoda.',
        ])->assertOk();

        $saliente = mb_strtolower((string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body);

        $this->assertStringContainsString('te esperamos el sábado', $saliente);
    }

    // ── Lo que encontró la revisión, fijado para que no vuelva ───────────────

    /**
     * «9:00» es una hora, y se registra.
     *
     * El controlador la rechazaba con `date_format:H:i` y tumbaba la petición
     * entera con un 422, mientras {@see CourtesyAuthority} la aceptaba a
     * propósito porque es como la escribe la gente. El backend era más
     * estricto que su propia autoridad y el precio lo pagaba la persona.
     */
    public function test_la_hora_sin_cero_a_la_izquierda_se_registra(): void
    {
        $this->turno('el sabado a las 9', 'w.9am', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '9:00',
        ])->assertOk();

        $this->assertSame(1, MarketingAgentAction::count(), 'una hora que la gente escribe así se perdió por el camino');
        $this->assertSame('9:00', data_get($this->solicitud()->payload, 'requested_time'));

        // Y se entendió como las nueve de la mañana DE NEIVA, no como una hora
        // UTC: la fila se guarda en UTC, y ahí las nueve son las catorce.
        $this->assertSame('09:00', Carbon::parse(
            (string) data_get($this->solicitud()->payload, 'scheduled_at'), 'UTC',
        )->setTimezone(BusinessClock::TZ)->format('H:i'));
    }

    /**
     * NEGAR que quedó agendado es la frase más honesta que hay, y tiene que salir.
     *
     * La guarda miraba las palabras sin mirar la negación, así que «no
     * quedaste agendado todavía» —exactamente lo que queremos que diga—
     * desaparecía igual que la mentira que persigue. Retirarla no sólo
     * degradaba el turno: enseñaba a no decirla.
     */
    public function test_negar_que_quedo_agendado_no_se_retira(): void
    {
        $this->turno('quiero ir el sabado a las 10', 'w.neg1', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
            'reply_draft' => 'No quedaste agendado todavía: falta que el equipo revise la solicitud.',
        ])->assertOk();

        $saliente = mb_strtolower((string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body);

        $this->assertStringContainsString('no quedaste agendado', $saliente);
    }

    /**
     * Y una negación que pertenece a OTRA cosa no salva la mentira.
     *
     * «No hay problema, quedaste agendado» lleva un «no» a doce caracteres de
     * la afirmación y no la niega en absoluto. Por eso la ventana es corta y
     * corta también por la coma: una ventana ancha de «hay un no por aquí
     * cerca» convierte la guarda en un colador.
     */
    public function test_un_no_de_otra_frase_no_deja_pasar_la_mentira(): void
    {
        $this->turno('quiero ir el sabado a las 10', 'w.neg2', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
            'reply_draft' => 'No hay problema, quedaste agendado para el sábado a las 10.',
        ])->assertOk();

        $saliente = mb_strtolower((string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body);

        $this->assertStringNotContainsString('quedaste agendado', $saliente);
        $this->assertNotSame('', trim($saliente), 'el turno no puede quedarse mudo');
    }

    /**
     * Una despedida no es una cita: «te esperamos en la sede» tiene que salir.
     *
     * El patrón pedía sólo un artículo detrás de «te esperamos», así que
     * cualquier continuación caía. Lo que convierte una despedida en promesa
     * es el día o la hora, no el artículo.
     */
    public function test_esperar_a_alguien_sin_decir_cuando_no_es_una_cita(): void
    {
        $this->turno('donde quedan?', 'w.sede', [
            'tools_requested' => [], 'courtesy_action' => null,
            'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Claro que sí, te esperamos en la sede de la carrera 5 con gusto.',
        ])->assertOk();

        $saliente = mb_strtolower((string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body);

        $this->assertStringContainsString('te esperamos en la sede', $saliente);
    }

    /**
     * Si la guarda se lo lleva TODO, el respaldo tampoco puede inventar.
     *
     * Había un solo texto de respaldo y afirmaba «dejé registrada tu solicitud
     * de día de cortesía». En un turno sin cortesía ninguna —alguien que
     * avisa de que viene a pagar— la guarda contra afirmar una cortesía falsa
     * acababa afirmando una cortesía falsa.
     */
    public function test_sin_solicitud_el_respaldo_no_inventa_una(): void
    {
        $this->turno('voy el sabado a pagar', 'w.resp1', [
            'tools_requested' => [], 'courtesy_action' => null,
            'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Te esperamos el sábado a las 10.',
        ])->assertOk();

        $saliente = mb_strtolower((string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body);

        $this->assertNotSame('', trim($saliente), 'el turno no puede quedarse mudo');
        $this->assertStringNotContainsString('registrada', $saliente);
        $this->assertStringNotContainsString('solicitud', $saliente);
        $this->assertSame(0, MarketingAgentAction::count(), 'no había ninguna solicitud que registrar');
    }

    /**
     * Cancelar lo que no existe no abre la invariante de promesas.
     *
     * La excepción cortocircuitaba en «cancel» sin preguntar si había algo que
     * cancelar. Un turno que pide cancelar una solicitud inexistente no toca
     * una sola fila, y aun así desactivaba la comprobación entera: «lo dejo
     * marcado para que el equipo te contacte» salía sin que nadie marcara
     * nada. Es justo el fallo que la invariante existe para impedir, con la
     * puerta abierta desde dentro.
     */
    public function test_cancelar_lo_que_no_existe_no_abre_la_invariante(): void
    {
        $r = $this->turno('cancela lo mio', 'w.cancel0', [
            'courtesy_action' => 'cancel', 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Listo, lo dejo marcado para que el equipo te contacte.',
        ]);

        $r->assertStatus(422)->assertJsonPath('code', 'promised_effect_without_authority');
        $this->assertSame(0, MarketingAgentAction::count());
    }

    /**
     * Una cita de hace tres meses no autoriza a agendar hoy.
     *
     * La guarda preguntaba si existía CUALQUIER cita de la conversación, de
     * cualquier fecha y en cualquier estado. Y una conversación de WhatsApp no
     * se cierra: vive ligada al par lead+canal durante meses. Así que a quien
     * ya vino en junio se le podía decir en septiembre «quedaste agendado para
     * el sábado a las 10», intacto. Esa persona viaja un día que nadie
     * preparó, que es el daño exacto que esta guarda nació para impedir.
     */
    public function test_una_cita_vieja_no_autoriza_la_frase(): void
    {
        MarketingAppointment::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'marketing_lead_id' => $this->lead->id,
            'marketing_conversation_id' => $this->conversation->id,
            'type' => MarketingAppointment::TYPE_VISIT,
            'status' => MarketingAppointment::STATUS_COMPLETED,
            'title' => 'Día de cortesía',
            'scheduled_at' => now()->subMonths(3),
        ]);

        $this->turno('quiero ir el sabado a las 10', 'w.vieja', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
            'reply_draft' => 'Listo, quedaste agendado para el sábado a las 10.',
        ])->assertOk();

        $saliente = mb_strtolower((string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body);

        $this->assertStringNotContainsString('quedaste agendado', $saliente);
        $this->assertNotSame('', trim($saliente), 'el turno no puede quedarse mudo');
    }

    /**
     * Cambiar el día de una solicitud YA APROBADA reabre la aprobación.
     *
     * `openFor()` devuelve las filas abiertas, y «abierta» incluye las que un
     * humano aprobó. Sin esto, ULTRON reescribía el día y la dejaba aprobada:
     * el panel ejecuta desde `approved`, así que nacía una cita real un
     * domingo que nadie miró, y el acta seguía diciendo que la había aprobado
     * quien aprobó el sábado. Un registro que afirma algo que no ocurrió es el
     * mismo fallo que persigue la invariante de promesas, sólo que en el
     * expediente en vez de en el texto.
     */
    public function test_cambiar_el_dia_de_una_solicitud_aprobada_reabre_la_aprobacion(): void
    {
        $this->turno('quiero ir el sabado a las 10', 'w.ap1', [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
        ])->assertOk();

        $this->solicitud()->forceFill([
            'status' => MarketingAgentAction::STATUS_APPROVED,
            'approved_at' => now(),
        ])->save();

        $this->turno('mejor el domingo a la misma hora', 'w.ap2', [
            'courtesy_date' => '2026-09-27', 'courtesy_time' => '10:00',
            'reply_draft' => 'Listo, queda registrada tu solicitud para el domingo. El equipo la revisará.',
        ])->assertOk();

        $a = $this->solicitud();

        $this->assertSame(1, MarketingAgentAction::count(), 'cambiar de día no puede dejar dos filas');
        $this->assertSame('2026-09-27', data_get($a->payload, 'requested_date'));
        $this->assertSame(MarketingAgentAction::STATUS_SUGGESTED, $a->status, 'el día nuevo salió aprobado sin que nadie lo mirara');
        $this->assertNull($a->approved_at, 'quedó la firma de una aprobación que era de otro día');
    }

    /**
     * Una ventana mal formada no puede matar el turno.
     *
     * `count($ventana) >= 2` lo cumple igual `{"open":"05:00","close":"22:00"}`
     * —que es como cualquiera escribiría esto en el panel, donde la `metadata`
     * se valida como «array» y nada más— y leerla por índice literal era un
     * warning que Laravel convierte en excepción. Esto corre DENTRO de las
     * herramientas, después de persistir la acción: el turno moría mudo con la
     * fila ya escrita, el vigía no veía ningún turno sin desenlace, y como el
     * horario es global no moría una conversación sino todas las que pidieran
     * cortesía.
     *
     * @return array<string,array{0:mixed}>
     */
    public static function ventanasMalFormadas(): array
    {
        return [
            'objeto con claves' => [['open' => '05:00', 'close' => '14:00']],
            'al revés' => [['close' => '14:00', 'open' => '08:00']],
            'anidada' => [[['08:00'], ['14:00']]],
            'números sueltos' => [[8, 14]],
            'vacía dentro' => [['', '']],
        ];
    }

    #[DataProvider('ventanasMalFormadas')]
    public function test_una_ventana_mal_formada_no_mata_el_turno(mixed $ventana): void
    {
        MarketingKnowledgeItem::where('key', 'schedule.opening_hours')->first()
            ->forceFill(['metadata' => ['windows' => ['sabado' => $ventana]]])->save();

        $this->turno('quiero ir el sabado a las 10', 'w.vent.'.md5((string) json_encode($ventana)), [
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
            'reply_draft' => '¿Qué día y a qué hora te quedarían bien para venir?',
        ])->assertOk();

        $saliente = (string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body;

        $this->assertNotSame('', trim($saliente), 'el turno se quedó mudo con la fila ya escrita');
    }
}
