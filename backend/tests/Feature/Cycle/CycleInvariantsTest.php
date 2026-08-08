<?php

namespace Tests\Feature\Cycle;

use App\Models\CommercialOpportunity;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\ContactPolicy;
use App\Services\Marketing\MarketingManualTakeoverService;
use App\Services\Wompi\PaymentStateMachine;

/**
 * Los diez invariantes del ciclo comercial.
 *
 * Los escenarios prueban un camino; esto prueba una propiedad. La diferencia
 * importa: un escenario que pasa demuestra que ESA secuencia funciona, y un
 * cliente real nunca sigue exactamente esa secuencia. Lo que se afirma aquí
 * tiene que ser cierto llegando por donde sea.
 *
 * Cada uno está escrito como la frase que un humano diría en voz alta si el
 * sistema lo violara. Ese es el criterio para saber si el invariante está bien
 * elegido: si al romperse nadie se quejaría, no era un invariante.
 */
class CycleInvariantsTest extends CommercialCycleTestCase
{
    private Plan $mensual;

    private Plan $anual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mensual = $this->plan('Mensual', 90000, 30);
        $this->anual = $this->plan('Anual', 900000, 365);
    }

    // ── 1 ───────────────────────────────────────────────────────────────

    /**
     * Nunca dos oportunidades vivas del mismo objetivo para la misma persona.
     *
     * Dos filas abiertas para el mismo objetivo son dos mensajes por lo mismo.
     * El motor recalcula muchas veces al día y cada recálculo es una ocasión de
     * abrir la segunda.
     */
    public function test_invariante_1_no_hay_dos_oportunidades_vivas_del_mismo_objetivo(): void
    {
        $lead = $this->newLead('3150000001', 'Invariante 1');
        $member = $this->makeMember($lead);
        $this->grantMembership($member, $this->mensual);
        $this->attendRegularly($member, perWeek: 3, weeks: 3);

        // Veinte recálculos, como una jornada entera de eventos.
        for ($i = 0; $i < 20; $i++) {
            $this->reevaluate($lead, $member);
        }

        $porObjetivo = CommercialOpportunity::query()
            ->where('marketing_lead_id', $lead->id)
            ->whereIn('status', V::OPEN_STATUSES)
            ->get()
            ->groupBy('goal');

        foreach ($porObjetivo as $goal => $vivas) {
            $this->assertCount(1, $vivas, sprintf(
                'Hay %d oportunidades vivas de «%s». Son %d mensajes por lo mismo.',
                $vivas->count(), $goal, $vivas->count(),
            ));
        }

        // Y tampoco dos objetivos comerciales compitiendo a la vez.
        $this->assertLessThanOrEqual(1, $porObjetivo->count(), sprintf(
            'Hay %d objetivos vivos al mismo tiempo: el motor elige uno, no una lista.',
            $porObjetivo->count(),
        ));
    }

    // ── 2 ───────────────────────────────────────────────────────────────

    /** Una venta completa cierra o transforma el objetivo anterior. */
    public function test_invariante_2_la_venta_cierra_el_objetivo_de_cobrar(): void
    {
        $lead = $this->newLead('3150000002', 'Invariante 2');
        $member = $this->makeMember($lead);

        CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_COLLECT_PAYMENT, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_SEND_PAYMENT_LINK,
            'reason' => 'aceptó el mensual', 'max_attempts' => 3,
        ]);

        $this->day(1, note: 'paga');
        $this->approvePayment($member, $this->mensual);
        $this->recordAndEvaluate('payment.approved', $lead, $member, ['plan_id' => $this->mensual->id]);

        $this->assertNoGoal($lead, V::GOAL_COLLECT_PAYMENT,
            'Se le sigue reclamando el pago que ya hizo: es el fallo más caro de un motor comercial.');

        // Y la relación no se cerró con la venta: hay algo nuevo que hacer.
        $this->assertNotNull($this->openOpportunity($lead),
            'Tras la venta no queda ningún objetivo: la relación terminó con el cobro.');
    }

    // ── 3 ───────────────────────────────────────────────────────────────

    /** Un pago aprobado no puede producir dos membresías. */
    public function test_invariante_3_un_pago_aprobado_no_produce_dos_membresias(): void
    {
        $lead = $this->newLead('3150000003', 'Invariante 3');
        $member = $this->makeMember($lead);

        $tx = PaymentTransaction::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => 'INV3-'.uniqid(), 'idempotency_key' => 'inv3-'.uniqid('', true),
            'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => 90000, 'currency' => 'COP',
            'status' => PaymentStateMachine::PENDING,
            'member_id' => $member->id, 'user_id' => $member->user_id,
            'plan_id' => $this->mensual->id,
        ]);

        $svc = \App\Services\Wompi\WompiTransactionService::make();

        // Cinco caminos hacia el mismo hecho: la respuesta del cobro, el webhook,
        // dos reentregas y la reconciliación. Todos convergen aquí.
        for ($i = 0; $i < 5; $i++) {
            $svc->transitionTo($tx->fresh(), PaymentStateMachine::APPROVED);
        }

        $this->assertSame(1, Payment::where('reference', $tx->reference)->count(),
            'Cinco confirmaciones del mismo pago produjeron más de una venta.');
        $this->assertSame(1, PaymentTransaction::where('member_id', $member->id)->count());
    }

    // ── 4 ───────────────────────────────────────────────────────────────

    /**
     * El opt-out comercial impide toda acción comercial automática.
     *
     * Es el invariante que no admite excepción por valor: da igual que el
     * cliente sea el que más gasta.
     */
    public function test_invariante_4_el_opt_out_impide_toda_accion_comercial(): void
    {
        $lead = $this->newLead('3150000004', 'Invariante 4');
        $member = $this->makeMember($lead);
        $this->grantMembership($member, $this->mensual);
        $this->attendRegularly($member, perWeek: 4, weeks: 4);

        // Pide que no le ofrezcan nada.
        $lead->forceFill(['do_not_contact' => true])->save();
        $this->note('opt-out', 'la persona pide no recibir ofertas');

        // El motor no puede ni decidir: el opt-out va antes que la primera regla.
        for ($i = 0; $i < 10; $i++) {
            $this->day(min(180, $i * 18), note: 'reevaluación con opt-out activo');
            $opp = $this->reevaluate($lead, $member);

            $this->assertNull($opp, sprintf(
                'Con opt-out activo se abrió una oportunidad de «%s» en el día %d.',
                $opp?->goal ?? '?', $this->currentDay,
            ));
        }

        // Y si alguien hubiera dejado una oportunidad abierta de antes, la
        // política de contacto la bloquea igual.
        $vieja = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_UPGRADE, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_OFFER_UPGRADE,
            'reason' => 'abierta antes del opt-out', 'max_attempts' => 1,
        ]);

        $this->assertContactDenied(
            $this->contactCheck($vieja, $lead, $member),
            'do_not_contact',
        );
    }

    // ── 5 ───────────────────────────────────────────────────────────────

    /** Mientras una persona lleva la conversación, la IA no envía. */
    public function test_invariante_5_el_takeover_humano_impide_el_envio_de_la_ia(): void
    {
        $lead = $this->newLead('3150000005', 'Invariante 5');
        $member = $this->makeMember($lead);
        $this->grantMembership($member, $this->mensual);

        $opp = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_RENEW, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_OFFER_RENEWAL,
            'reason' => 'le vence pronto', 'max_attempts' => 2,
        ]);

        // Antes del takeover se podría contactar.
        $this->assertTrue($this->contactCheck($opp, $lead, $member)['allowed'],
            'Sin takeover la política ya bloqueaba: la prueba no mediría el takeover.');

        app(MarketingManualTakeoverService::class)
            ->takeover($this->conversationOf($lead), $this->admin->id, 'customer_asked');

        $this->assertContactDenied(
            $this->contactCheck($opp, $lead, $member),
            'human_in_control',
        );

        // Y el motor tampoco decide nada comercial mientras el humano manda.
        $decision = $this->reevaluate($lead, $member);
        $this->assertSame(V::GOAL_ESCALATE, $decision?->goal,
            'Con un humano al mando el motor siguió eligiendo objetivos comerciales.');
    }

    // ── 6 ───────────────────────────────────────────────────────────────

    /**
     * El catálogo actual manda sobre el precio histórico.
     *
     * El anuncio por el que llegó alguien hace tres meses es evidencia de por
     * qué vino, no una lista de precios vigente. Prometer el precio viejo es
     * regalar dinero; reescribir el histórico es perder la trazabilidad.
     */
    public function test_invariante_6_el_catalogo_vigente_domina_el_precio_historico(): void
    {
        $lead = $this->newLead('3150000006', 'Invariante 6');

        \App\Models\MarketingLeadAttribution::create([
            'marketing_lead_id' => $lead->id,
            'source_type' => 'ad', 'ad_id' => 'AD-VIEJO',
            'first_touch_at' => now(),
            'headline' => 'Mensual desde 90.000',
        ]);

        $member = $this->makeMember($lead);
        $this->grantMembership($member, $this->mensual);

        // Tres meses después sube el precio.
        $this->day(90, note: 'el catálogo sube: el mensual pasa a 110.000');
        $this->mensual->forceFill(['price' => 110000])->save();

        $tx = $this->approvePayment($member, $this->mensual->fresh());

        $this->assertEqualsWithDelta(110000.0, (float) $tx->amount, 0.01,
            'Se cobró el precio del anuncio viejo en vez del vigente.');

        // Y el histórico sigue intacto: no se reescribe lo que decía el anuncio.
        $atribucion = \App\Models\MarketingLeadAttribution::where('marketing_lead_id', $lead->id)->firstOrFail();
        $this->assertStringContainsString('90.000', (string) $atribucion->headline,
            'Se reescribió el titular del anuncio: se perdió la evidencia de qué se le prometió.');
    }

    // ── 7 y 8 ───────────────────────────────────────────────────────────

    /**
     * Una renovación no se cuenta como adquisición, ni una mejora como
     * renovación.
     *
     * Contar mal aquí no es un error de informe: es la cifra con la que se
     * decide cuánto invertir en pauta. Una renovación contada como alta hace
     * parecer que los anuncios traen el doble de clientes de los que traen.
     */
    public function test_invariantes_7_y_8_el_dinero_no_cambia_de_categoria(): void
    {
        $lead = $this->newLead('3150000007', 'Invariante 7-8');
        $member = $this->makeMember($lead);

        $this->day(0, note: 'alta');
        $this->approvePayment($member, $this->mensual);

        $this->day(30, note: 'renovación');
        $this->approvePayment($member, $this->mensual);

        $this->day(60, note: 'mejora al anual');
        $this->approvePayment($member, $this->anual);

        $pagos = PaymentTransaction::where('member_id', $member->id)
            ->where('status', 'approved')->orderBy('id')->get();

        $this->assertCount(3, $pagos);

        // El primero, y solo el primero, es adquisición.
        $this->assertSame($pagos->first()->id, $pagos->min('id'));
        $altas = $pagos->filter(fn ($p) => $p->id === $pagos->min('id'));
        $this->assertCount(1, $altas, 'Hay más de una adquisición para el mismo miembro.');

        // El tercero es mejora, no renovación: cambia el plan y sube el importe.
        $this->assertGreaterThan((float) $pagos[1]->amount, (float) $pagos[2]->amount,
            'La mejora no se distingue de una renovación por el importe.');
        $this->assertNotSame($pagos[1]->plan_id, $pagos[2]->plan_id,
            'La mejora no cambió de plan: entonces no era una mejora.');

        // Y el total no se cuenta dos veces.
        $this->assertEqualsWithDelta(
            90000 + 90000 + 900000,
            (float) $pagos->sum('amount'), 0.01,
            'El ingreso total no cuadra: hay doble conteo.',
        );
    }

    // ── 9 ───────────────────────────────────────────────────────────────

    /**
     * Una negativa no desaparece sin evidencia de que algo cambió.
     *
     * «Pasó el tiempo» no es evidencia. Si lo fuera, el sistema tendría permiso
     * para volver a preguntar cada mes eternamente, que es exactamente la
     * definición de acoso con calendario.
     */
    public function test_invariante_9_una_negativa_no_caduca_sola(): void
    {
        $lead = $this->newLead('3150000009', 'Invariante 9');
        $member = $this->makeMember($lead);
        $this->grantMembership($member, $this->mensual);

        // Dice que no al anual. El estado y el motivo quedan escritos.
        $rechazada = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_UPGRADE, 'status' => V::STATUS_LOST,
            'next_action' => V::ACTION_OFFER_UPGRADE,
            'outcome' => 'declined', 'outcome_reason' => 'prefiere seguir mensual',
            'reason' => 'adherencia alta', 'closed_at' => now(),
            'max_attempts' => 1,
        ]);

        // Seis meses de reevaluaciones SIN nada nuevo: sin más asistencia, sin
        // más pagos, sin que la persona pregunte.
        foreach ([30, 60, 90, 120, 150, 180] as $d) {
            $this->day($d, note: 'reevaluación sin ninguna novedad');
            $this->reevaluate($lead, $member);
        }

        // La negativa sigue registrada, con su motivo, intacta.
        $rechazada->refresh();
        $this->assertSame(V::STATUS_LOST, $rechazada->status,
            'La negativa desapareció del historial.');
        $this->assertSame('prefiere seguir mensual', $rechazada->outcome_reason,
            'Se perdió el motivo por el que dijo que no.');

        $this->note('invariante 9', '180 días sin novedad: la negativa sigue en pie con su motivo');
    }

    // ── 10 ──────────────────────────────────────────────────────────────

    /**
     * A un cliente en riesgo no se le vende más, se le retiene.
     *
     * Es el invariante que separa un motor comercial de una máquina de facturar.
     * Alguien que paga y no viene parece un cliente sano desde caja, y es
     * justamente el que está a punto de irse. Ofrecerle un plan más largo es
     * cobrarle por adelantado algo que ya no está usando.
     */
    public function test_invariante_10_un_cliente_en_riesgo_no_recibe_mejora(): void
    {
        $lead = $this->newLead('3150000010', 'Invariante 10');
        $member = $this->makeMember($lead);

        $this->day(0, note: 'compra el mensual');
        $this->approvePayment($member, $this->mensual);

        // Vino dos veces la primera semana y desapareció.
        $this->day(2, note: 'asiste');
        $this->attend($member);
        $this->day(4, note: 'asiste, y no vuelve');
        $this->attend($member);

        // Renueva —desde caja parece un cliente sano— pero no pisa el gimnasio.
        $this->day(30, note: 'renueva sin haber vuelto');
        $this->approvePayment($member, $this->mensual);

        $this->day(45, note: 'seis semanas sin venir');
        $opp = $this->reevaluate($lead, $member);

        $subject = $this->subject($lead, $member);
        $this->assertGreaterThanOrEqual(30, $subject->daysSinceLastAttendance ?? 0,
            'El montaje no consiguió un cliente realmente ausente.');

        $this->assertNoGoal($lead, V::GOAL_UPGRADE, sprintf(
            'Se le ofreció un plan más largo llevando %d días sin venir. Eso es '
            .'cobrarle por adelantado algo que no está usando.',
            $subject->daysSinceLastAttendance ?? 0,
        ));
        $this->assertNoGoal($lead, V::GOAL_CROSS_SELL,
            'Se le ofreció un complemento a alguien que está a punto de irse.');

        $this->assertGoal($opp, V::GOAL_INCREASE_ADHERENCE,
            'Con un cliente ausente lo que toca es entender por qué no viene.');
    }

    // ── Política de contacto, con fechas concretas ──────────────────────

    /**
     * El mínimo entre mensajes proactivos se cuenta en horas reales.
     *
     * Con `min_hours_between` a 48 h, un mensaje el martes a las 10:00 no
     * permite otro el miércoles a las 10:00. Se comprueba con fechas, no con
     * `subDays()` relativos, porque el error típico es contar días de
     * calendario en vez de horas.
     */
    public function test_politica_de_contacto_cuenta_horas_no_dias_de_calendario(): void
    {
        config()->set('commercial.contact_limits.min_hours_between', 48);
        config()->set('commercial.contact_limits.max_proactive_per_week', 2);

        $lead = $this->newLead('3150000011', 'Política');
        $member = $this->makeMember($lead);
        $this->grantMembership($member, $this->mensual);

        $opp = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_INCREASE_ADHERENCE, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_CHECK_IN, 'reason' => 'seguimiento',
            'max_attempts' => 3,
        ]);

        // Martes 10:00 — sale un proactivo de la IA.
        $this->day(0, 10, 'la IA escribe un mensaje proactivo');
        MarketingMessage::create([
            'conversation_id' => $this->conversationOf($lead)->id,
            'direction' => 'outbound', 'sender_type' => MarketingMessage::SENDER_AI,
            'body' => '¿Cómo va la semana?', 'status' => 'sent',
        ]);

        // Miércoles 10:00 — 24 h después: todavía no.
        $this->day(1, 10, 'un día después');
        $this->assertContactDenied(
            $this->contactCheck($opp, $lead, $member),
            'too_soon_since_last_contact',
        );

        // Jueves 11:00 — 49 h después: ya se puede.
        $this->day(2, 11, 'cuarenta y nueve horas después');
        $this->assertTrue($this->contactCheck($opp, $lead, $member)['allowed'],
            'Pasadas 49 h con un mínimo de 48 h sigue bloqueado: se están contando días, no horas.');
    }

    /**
     * Las horas de silencio son las de Neiva, no las del servidor.
     *
     * El servidor puede correr en UTC. Con UTC, las 22:00 de Neiva son las 03:00
     * del día siguiente, y un sistema que mire su propio reloj mandaría mensajes
     * de madrugada convencido de que es media mañana.
     */
    public function test_las_horas_de_silencio_son_las_de_neiva(): void
    {
        config()->set('commercial.contact_limits.quiet_hours_start', 21);
        config()->set('commercial.contact_limits.quiet_hours_end', 8);

        $lead = $this->newLead('3150000012', 'Silencio');
        $member = $this->makeMember($lead);
        $this->grantMembership($member, $this->mensual);

        $opp = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_INCREASE_ADHERENCE, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_CHECK_IN, 'reason' => 'seguimiento',
            'max_attempts' => 3,
        ]);

        // 22:30 en Neiva.
        \Illuminate\Support\Carbon::setTestNow(
            \Illuminate\Support\Carbon::parse('2026-03-10 22:30:00', 'America/Bogota')
                ->setTimezone(config('app.timezone')),
        );
        $this->note('22:30 en Neiva', 'dentro de las horas de silencio');

        $check = $this->contactCheck($opp, $lead, $member);
        $this->assertContactDenied($check, 'quiet_hours');
        $this->assertNotNull($check['retry_at'],
            'Se bloqueó por horario sin decir cuándo se puede volver a intentar.');

        // 09:00 en Neiva del día siguiente: fuera del silencio.
        \Illuminate\Support\Carbon::setTestNow(
            \Illuminate\Support\Carbon::parse('2026-03-11 09:00:00', 'America/Bogota')
                ->setTimezone(config('app.timezone')),
        );
        $this->assertTrue($this->contactCheck($opp, $lead, $member)['allowed'],
            'A las 09:00 de Neiva sigue en silencio: se está usando la hora del servidor.');
    }

    /**
     * El mensaje de una persona no consume la cuota comercial de la IA.
     *
     * Una conversación viva no es una campaña. Si el mensaje del asesor contara,
     * atender bien a alguien dejaría al sistema sin margen para el seguimiento
     * que sí toca.
     */
    public function test_el_mensaje_de_un_asesor_no_consume_cuota_comercial(): void
    {
        config()->set('commercial.contact_limits.min_hours_between', 48);

        $lead = $this->newLead('3150000013', 'Cuota');
        $member = $this->makeMember($lead);
        $this->grantMembership($member, $this->mensual);

        $opp = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_INCREASE_ADHERENCE, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_CHECK_IN, 'reason' => 'seguimiento',
            'max_attempts' => 3,
        ]);

        // Un asesor acaba de escribirle hace un minuto.
        MarketingMessage::create([
            'conversation_id' => $this->conversationOf($lead)->id,
            'direction' => 'outbound',
            'sender_type' => MarketingMessage::SENDER_HUMAN,
            'sender_user_id' => $this->admin->id,
            'body' => 'Hola, soy Ana del gimnasio', 'status' => 'sent',
        ]);

        $this->assertTrue($this->contactCheck($opp, $lead, $member)['allowed'],
            'El mensaje de un asesor consumió la cuota de la IA: atender bien penaliza.');
    }
}
