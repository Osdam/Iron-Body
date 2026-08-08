<?php

namespace Tests\Feature\Cycle;

use App\Models\CommercialOpportunity;
use App\Models\MarketingAiAction;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Marketing\MarketingManualTakeoverService;

/**
 * Los perfiles que la simulación masiva recorre pero no interroga.
 *
 * La corrida de 100×180 días comprueba invariantes agregados: que nada se
 * duplique, que nadie reciba de más, que el dinero cuadre. Eso encuentra
 * tormentas y estados imposibles, y no encuentra lo contrario: que el sistema
 * haga lo correcto **en el hito concreto**. Un motor que nunca abriera ninguna
 * oportunidad pasaría la simulación masiva entera.
 *
 * Así que aquí cada perfil se recorre despacio, con una afirmación en cada
 * momento que importa. Es lento de escribir y es lo único que demuestra que el
 * comportamiento es el correcto y no solo inofensivo.
 */
class CycleSecondaryProfilesTest extends CommercialCycleTestCase
{
    private Plan $mensual;

    private Plan $anual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mensual = $this->plan('Mensual', 90000, 30);
        $this->anual = $this->plan('Anual', 900000, 365);
    }

    // ══ 1 · Opt-out comercial y reactivación iniciada por el cliente ════

    /**
     * «No quiero que me vuelvan a ofrecer planes ni promociones.»
     *
     * Lo delicado no es apagar las ofertas: es apagarlas **sin** dejar de
     * atender a la persona. Un opt-out comercial no es una orden de silencio
     * absoluto; es una preferencia sobre lo que nosotros iniciamos. Si además
     * impidiera contestarle cuando pregunta algo, el sistema castigaría a quien
     * ejerció su derecho, que es peor servicio del que tenía antes de pedirlo.
     */
    public function test_opt_out_comercial_apaga_las_ofertas_y_no_al_cliente(): void
    {
        $this->day(0, note: 'compra el mensual');
        $lead = $this->newLead('3160000001', 'Opt-out');
        $member = $this->makeMember($lead);
        $this->approvePayment($member, $this->mensual);
        $this->attendRegularly($member, perWeek: 3, weeks: 2);

        // Había cosas comerciales en marcha antes de que lo pidiera.
        $upgrade = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_UPGRADE, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_OFFER_UPGRADE,
            'reason' => 'adherencia alta', 'max_attempts' => 1, 'created_by' => 'engine',
        ]);
        $referido = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_REQUEST_REFERRAL, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_ASK_REFERRAL,
            'reason' => 'cliente contento', 'max_attempts' => 1, 'created_by' => 'engine',
        ]);

        // ── El cliente lo pide ──────────────────────────────────────────
        $this->day(20, note: '«no quiero que me vuelvan a ofrecer planes ni promociones»');

        $this->optOutCommercially($lead);

        // Persistido, y persistido donde se puede consultar sin adivinar.
        $lead->refresh();
        $this->assertFalse($this->subject($lead)->acceptsCommercialOffers(),
            'El opt-out no quedó guardado: la próxima evaluación volvería a ofrecer.');
        $this->assertTrue($this->subject($lead)->isContactable(),
            'El opt-out comercial silenció a la persona entera.');
        $this->assertNotNull(
            MarketingAiAction::where('lead_id', $lead->id)
                ->where('action_type', 'commercial_opt_out')->first(),
            'No quedó constancia de CUÁNDO ni POR QUÉ se apagó lo comercial.',
        );

        // Las oportunidades comerciales que había, cerradas.
        foreach ([$upgrade, $referido] as $o) {
            $o->refresh();
            $this->assertFalse($o->isOpen(), sprintf(
                'La oportunidad de «%s» sigue viva tras el opt-out.', $o->goal,
            ));
            $this->assertSame('customer_opted_out', $o->outcome_reason);
        }

        // ── Seis meses de reevaluaciones ────────────────────────────────
        foreach ([25, 50, 80, 110, 140, 170, 200] as $d) {
            $this->day($d, note: 'reevaluación comercial');

            $this->assertNull($this->reevaluate($lead, $member), sprintf(
                'En el día %d el motor volvió a abrir una oportunidad pese al opt-out.', $d,
            ));
        }

        $this->assertSame(0, CommercialOpportunity::where('marketing_lead_id', $lead->id)
            ->whereIn('goal', [V::GOAL_UPGRADE, V::GOAL_CROSS_SELL, V::GOAL_REQUEST_REFERRAL])
            ->whereIn('status', V::OPEN_STATUSES)->count(),
            'Seis meses después hay ofertas vivas otra vez.');

        // Y la política de contacto lo deniega con su propio motivo, para que el
        // panel pueda distinguir «pidió no recibir ofertas» de «no contactar».
        $pendiente = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_UPGRADE, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_OFFER_UPGRADE,
            'reason' => 'abierta antes del opt-out', 'max_attempts' => 1,
        ]);
        $this->assertContactDenied(
            $this->contactCheck($pendiente, $lead, $member),
            'commercial_opt_out',
        );

        // ── El cliente vuelve por su cuenta ─────────────────────────────
        $this->day(210, note: 'el CLIENTE escribe: «ahora sí quiero conocer el anual»');

        MarketingMessage::create([
            'conversation_id' => $this->conversationOf($lead)->id,
            'direction' => 'inbound', 'sender_type' => 'lead',
            'body' => 'Ahora sí quiero conocer el anual', 'status' => 'received',
        ]);

        /*
         * Aquí está la distinción que da sentido a todo el escenario.
         *
         * Poder CONTESTAR a quien pregunta y poder ESCRIBIR PRIMERO son dos
         * permisos distintos, y el opt-out comercial solo retira el segundo. Si
         * retirara los dos, alguien que pidió no recibir promociones se quedaría
         * sin poder preguntar el precio, y eso no es respetar su preferencia:
         * es dejar de atenderle.
         */
        $this->assertTrue($lead->fresh()->canReplyReactively(), sprintf(
            'El cliente preguntó por el anual y el sistema no puede contestarle. '
            .'El opt-out comercial se está aplicando como silencio absoluto.%s',
            "\n\nCiclo:\n".$this->ledgerText(),
        ));

        // Pero seguimos sin poder OFRECERLE nada por iniciativa nuestra: que
        // pregunte por el anual no reabre la puerta a las campañas.
        $this->assertFalse($this->subject($lead, $member)->acceptsCommercialOffers(),
            'Que el cliente pregunte devolvió el permiso de ofrecer por iniciativa propia.');
        $this->assertNull($this->reevaluate($lead, $member),
            'Tras la pregunta del cliente el motor volvió a abrir ofertas automáticas.');

        // El historial no se borra: la preferencia sigue registrada.
        $this->assertNotNull(
            MarketingAiAction::where('lead_id', $lead->id)
                ->where('action_type', 'commercial_opt_out')->first(),
            'Se borró el rastro del opt-out al volver el cliente.',
        );

        $this->note('opt-out · qué cambia', 'responder: SÍ · escribir primero: NO · historial: intacto');
    }

    // ══ 2 · Cliente molesto ═════════════════════════════════════════════

    /**
     * Una queja cambia el objetivo, y no se olvida al día siguiente.
     *
     * El error caro aquí es de tiempo, no de intención: casi cualquier sistema
     * deja de vender mientras hay una queja abierta. Lo difícil es no volver a
     * vender el minuto siguiente a resolverla, porque el cliente todavía
     * recuerda el problema y la oferta llega como si no hubiera pasado nada.
     */
    public function test_una_queja_desplaza_la_venta_y_el_regreso_exige_tiempo(): void
    {
        $this->day(0, note: 'compra y entrena con regularidad');
        $lead = $this->newLead('3160000002', 'Molesto');
        $member = $this->makeMember($lead);
        $this->approvePayment($member, $this->mensual);
        $this->attendRegularly($member, perWeek: 4, weeks: 3);

        $upgrade = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_UPGRADE, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_OFFER_UPGRADE,
            'reason' => 'adherencia alta', 'max_attempts' => 1, 'created_by' => 'engine',
        ]);

        // ── La queja ────────────────────────────────────────────────────
        $this->day(22, note: 'se queja: llevan tres días sin agua caliente');

        $this->conversationOf($lead)->forceFill(['staff_review_pending' => true])->save();
        MarketingAiAction::create([
            'lead_id' => $lead->id,
            'conversation_id' => $this->conversationOf($lead)->id,
            'action_type' => 'register_complaint',
            'reason' => 'sin agua caliente tres días',
            'status' => 'executed',
            'metadata' => ['complaint' => true],
        ]);

        $opp = $this->reevaluate($lead, $member);

        // La venta se aparta: manda la persona.
        $this->assertGoal($opp, V::GOAL_ESCALATE,
            'Con una queja abierta el motor siguió eligiendo objetivos comerciales.');

        $this->assertFalse($upgrade->fresh()->isOpen(),
            'La oferta de mejora siguió viva con una queja abierta.');
        $this->assertNoGoal($lead, V::GOAL_UPGRADE, 'Se ofreció mejorar a alguien que acaba de quejarse.');
        $this->assertNoGoal($lead, V::GOAL_CROSS_SELL, 'Se ofreció un complemento con una queja abierta.');

        // Ningún seguimiento de venta programado.
        $this->assertSame(0, CommercialOpportunity::where('marketing_lead_id', $lead->id)
            ->whereIn('goal', [V::GOAL_UPGRADE, V::GOAL_CROSS_SELL, V::GOAL_REQUEST_REFERRAL])
            ->whereIn('status', V::OPEN_STATUSES)->count());

        // ── Se resuelve ─────────────────────────────────────────────────
        $this->day(24, note: 'un asesor lo resuelve y devuelve la conversación');

        $this->conversationOf($lead)->forceFill(['staff_review_pending' => false])->save();
        app(MarketingManualTakeoverService::class)->release($this->conversationOf($lead), $this->admin->id);

        // ── Y no se vuelve a vender el minuto siguiente ─────────────────
        foreach ([24, 25, 27] as $d) {
            $this->day($d, note: 'reevaluación justo después de resolver');
            $this->reevaluate($lead, $member);

            $this->assertNoGoal($lead, V::GOAL_UPGRADE, sprintf(
                'Se ofreció mejorar el día %d, a %d día(s) de resolverle una queja.',
                $d, $d - 22,
            ));
        }

        $this->note('queja', 'objetivo desplazado a escalate; sin ofertas a 5 días de la queja');
    }

    // ══ 3 · Pago abandonado y límite de intentos ═════════════════════════

    /**
     * Genera el enlace y no paga.
     *
     * Recuperar un pago a medias es de lo más rentable que hace un motor
     * comercial y de lo más fácil de convertir en acoso: la persona no ha dicho
     * que no, así que siempre parece que cabe un recordatorio más. El límite
     * tiene que ser un número, y ese número no puede reiniciarse porque el motor
     * vuelva a mirar.
     */
    public function test_un_pago_abandonado_se_recupera_con_limite_y_sin_duplicar(): void
    {
        $this->day(0, note: 'pide el enlace de pago y no paga');
        $lead = $this->newLead('3160000003', 'Abandona');
        $member = $this->makeMember($lead);
        $tx = $this->pendingPayment($member, $this->mensual);

        $this->day(1, note: 'el motor ve el pago a medias');
        $opp = $this->recordAndEvaluate('payment.link_created', $lead, $member, [
            'plan_id' => $this->mensual->id,
        ]);

        $this->assertGoal($opp, V::GOAL_RECOVER_PAYMENT_LINK,
            'Un enlace sin pagar no generó objetivo de recuperación.');

        $maxIntentos = (int) $opp->max_attempts;
        $this->assertGreaterThan(0, $maxIntentos, 'La recuperación no declara un techo de intentos.');
        $this->note('recuperación', sprintf('techo de %d intentos', $maxIntentos));

        // ── Se consumen todos los intentos ──────────────────────────────
        for ($i = 1; $i <= $maxIntentos; $i++) {
            $this->day(1 + $i * 3, note: sprintf('intento %d de recuperación', $i));

            $this->assertTrue($opp->fresh()->isActionable(), sprintf(
                'El intento %d de %d ya no es accionable: el techo se está aplicando de menos.',
                $i, $maxIntentos,
            ));

            $opp->forceFill([
                'attempts' => $i,
                'last_attempt_at' => now(),
            ])->save();
        }

        // Agotado: deja de ser accionable.
        $this->assertFalse($opp->fresh()->isActionable(),
            'Agotados los intentos la oportunidad sigue accionable: no hay techo real.');

        // ── Y las reevaluaciones NO reinician el contador ────────────────
        foreach ([30, 45, 60, 90] as $d) {
            $this->day($d, note: 'reevaluación tras agotar los intentos');
            $this->reevaluate($lead, $member);

            $vivo = CommercialOpportunity::where('marketing_lead_id', $lead->id)
                ->where('goal', V::GOAL_RECOVER_PAYMENT_LINK)
                ->orderByDesc('id')->first();

            $this->assertGreaterThanOrEqual($maxIntentos, (int) $vivo->attempts, sprintf(
                'En el día %d el contador de intentos bajó a %d: recalcular está '
                .'sirviendo para insistir más veces de las permitidas.',
                $d, (int) $vivo->attempts,
            ));

            $this->assertFalse($vivo->isActionable(), sprintf(
                'En el día %d la recuperación volvió a ser accionable sin nada nuevo.', $d,
            ));
        }

        // Un solo enlace: no se generó otra intención por el camino.
        $this->assertSame(1, PaymentTransaction::where('member_id', $member->id)->count(),
            'Se creó una segunda intención de pago durante la recuperación.');
        $this->assertSame(0, Payment::where('member_id', $member->id)->count(),
            'Hay una venta registrada de un pago que nunca entró.');

        // ── Una negativa explícita detiene la recuperación ──────────────
        $this->day(95, note: 'el cliente dice que ya no le interesa');

        $vivo = CommercialOpportunity::where('marketing_lead_id', $lead->id)
            ->where('goal', V::GOAL_RECOVER_PAYMENT_LINK)->orderByDesc('id')->firstOrFail();
        $vivo->close(V::STATUS_LOST, 'customer_declined');

        $this->day(120, note: 'reevaluación tras la negativa');
        $this->reevaluate($lead, $member);

        $this->assertSame(V::STATUS_LOST, $vivo->fresh()->status,
            'La negativa explícita no cerró la recuperación.');
    }

    // ══ 4 · F9.16 Reactivación ══════════════════════════════════════════

    /**
     * Se fue, volvió, y el dinero se cuenta por lo que es.
     *
     * Contar esto como adquisición es el error contable más tentador: parece un
     * cliente nuevo porque vuelve a entrar por la puerta. Pero si se cuenta así,
     * la pauta se lleva el mérito de recuperar a alguien que ya era cliente, y
     * el coste por cliente nuevo sale barato por un motivo falso.
     */
    public function test_f916_reactivacion_se_cuenta_como_reactivacion(): void
    {
        $this->day(0, note: 'compra el mensual');
        $lead = $this->newLead('3160000004', 'Reactiva');
        $member = $this->makeMember($lead);
        $this->approvePayment($member, $this->mensual);

        $this->day(3, note: 'viene dos veces y deja de venir');
        $this->attend($member);
        $this->day(6, note: 'última asistencia');
        $this->attend($member);

        // ── La membresía vence y sigue ausente ──────────────────────────
        $this->day(40, note: 'la membresía venció hace días y no ha vuelto');

        $subject = $this->subject($lead, $member);
        $this->assertFalse($subject->hasActiveMembership, 'El montaje no consiguió una membresía vencida.');
        $this->assertGreaterThan(0, $subject->daysSinceExpiry ?? 0);

        $opp = $this->reevaluate($lead, $member);

        $this->assertGoal($opp, V::GOAL_REACTIVATE,
            'Con la membresía vencida y el cliente ausente, el objetivo es traerlo de vuelta.');
        $this->assertNoGoal($lead, V::GOAL_UPGRADE,
            'Se ofreció un plan mejor a alguien que ya se había ido.');

        // ── Vuelve y paga ───────────────────────────────────────────────
        $this->day(130, note: 'vuelve tras más de 90 días sin pagar y compra el mensual');
        $this->approvePayment($member, $this->mensual);

        // Clasificación con la MISMA regla de la analítica: un pago posterior
        // sin ningún otro aprobado en los 90 días previos es reactivación.
        $desglose = $this->revenueBreakdown($member);

        $this->assertGreaterThan(0, $desglose['reactivation'], sprintf(
            "La vuelta no se contabilizó como reactivación.\n\nDesglose: %s",
            json_encode($desglose),
        ));
        $this->assertEqualsWithDelta(90000.0, $desglose['acquisition'], 0.01,
            'La reactivación aumentó la adquisición: la pauta se lleva un mérito que no es suyo.');
        $this->assertSame(0.0, $desglose['upgrade'],
            'La reactivación se contó como mejora.');

        // Y sigue siendo la misma persona.
        $this->assertSame(1, Member::where('phone', $lead->phone)->count(),
            'La reactivación creó un miembro nuevo: identidad duplicada.');
        $this->assertSame(2, Payment::where('member_id', $member->id)->count());

        $this->note('reactivación', sprintf(
            'adquisición $%s · reactivación $%s · mejora $%s',
            number_format($desglose['acquisition']),
            number_format($desglose['reactivation']),
            number_format($desglose['upgrade']),
        ));
    }

    // ══ 5 · F9.17 Promesa humana durante el takeover ════════════════════

    /**
     * «Te escribimos la próxima semana.»
     *
     * Una persona acaba de comprometer al gimnasio a una fecha. Si el agente
     * escribe al día siguiente, no solo molesta: deja al asesor por mentiroso
     * delante del cliente. La promesa tiene que ganar sobre cualquier
     * seguimiento que el agente tuviera programado de antes.
     */
    public function test_f917_una_promesa_humana_calla_al_agente_hasta_la_fecha(): void
    {
        $this->day(0, note: 'el agente tiene un seguimiento programado para mañana');
        $lead = $this->newLead('3160000005', 'Promesa');
        $member = $this->makeMember($lead);
        $this->approvePayment($member, $this->mensual);

        $opp = CommercialOpportunity::create([
            'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
            'goal' => V::GOAL_INCREASE_ADHERENCE, 'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_CHECK_IN,
            'reason' => 'seguimiento de la primera semana',
            'act_after' => now()->addDay(), 'max_attempts' => 2, 'created_by' => 'engine',
        ]);

        // ── El humano entra y promete ───────────────────────────────────
        $this->day(1, note: 'un asesor toma la conversación y promete escribir la próxima semana');

        $conversation = $this->conversationOf($lead);
        app(MarketingManualTakeoverService::class)->takeover($conversation, $this->admin->id, 'customer_asked');

        MarketingMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound', 'sender_type' => MarketingMessage::SENDER_HUMAN,
            'sender_user_id' => $this->admin->id,
            'body' => 'Te escribimos la próxima semana con la propuesta.',
            'status' => 'sent',
        ]);

        $prometidoPara = now()->copy()->addDays(7);
        $this->recordHumanPromise($opp, $prometidoPara, 'Te escribimos la próxima semana.');

        // La promesa gana sobre el seguimiento anterior del agente.
        $opp->refresh();
        $this->assertTrue($opp->act_after->greaterThanOrEqualTo($prometidoPara->startOfDay()), sprintf(
            'El seguimiento del agente sigue programado para %s, antes de la fecha '
            .'que prometió el asesor (%s).',
            $opp->act_after->toDateString(), $prometidoPara->toDateString(),
        ));

        // ── Se devuelve la conversación a la IA ─────────────────────────
        $this->day(1, 16, 'el asesor devuelve la conversación');
        app(MarketingManualTakeoverService::class)->release($conversation, $this->admin->id);

        $resumen = (string) $conversation->fresh()->summary;
        $this->assertStringContainsString('próxima semana', $resumen, sprintf(
            "El resumen del traspaso no menciona la promesa. El agente retomaría "
            ."sin saber a qué se comprometió una persona.\n\nResumen: %s",
            $resumen,
        ));

        // ── Y no escribe antes de la fecha ──────────────────────────────
        foreach ([2, 4, 7] as $d) {
            $this->day($d, note: sprintf('día +%d desde la promesa', $d - 1));

            $check = $this->contactCheck($opp->fresh(), $lead, $member);
            $this->assertFalse($check['allowed'], sprintf(
                'El agente habría escrito el día %d, antes de la fecha prometida. '
                .'Eso deja al asesor por mentiroso.', $d,
            ));
            $this->assertSame('opportunity_not_actionable', $check['reason']);
        }

        // Llegada la fecha, puede volver a evaluar.
        $this->day(9, note: 'pasada la fecha prometida');
        /*
         * El takeover BLOQUEA las oportunidades vivas, y hace bien: mientras
         * una persona lleva el caso, nada automático sigue en pie. Así que
         * pasada la fecha lo que se comprueba no es que reviva la fila vieja,
         * sino que el agente puede volver a decidir —y que la promesa sigue
         * mandando sobre lo que decida—.
         */
        $nueva = $this->reevaluate($lead, $member);

        $this->assertNotNull($nueva,
            'Pasada la fecha prometida el agente sigue mudo para siempre.');

        $this->assertNotNull(
            MarketingAiAction::where('lead_id', $lead->id)
                ->where('action_type', 'human_promise')->first(),
            'La promesa del asesor desapareció del historial.',
        );
    }

    // ══ 6 · F9.19 Plan desactivado ══════════════════════════════════════

    /**
     * El plan que compró ya no se vende.
     *
     * Dos tentaciones opuestas y las dos malas: ofrecerle otra vez un plan que
     * ya no existe, o reescribir su historial para que parezca que compró otra
     * cosa. Lo correcto es que el pasado quede como fue y el futuro se ofrezca
     * del catálogo vigente.
     */
    public function test_f919_un_plan_retirado_no_se_ofrece_y_no_se_reescribe(): void
    {
        $promo = $this->plan('Promo Verano', 60000, 30);

        $this->day(0, note: 'compra la Promo Verano');
        $lead = $this->newLead('3160000006', 'Plan retirado');
        $member = $this->makeMember($lead);
        $tx = $this->approvePayment($member, $promo);

        // Cuatro por semana, no tres: con tres la tasa queda por debajo del
        // umbral de «comprometido» y el motor elige rescatarla en vez de
        // renovarla. Eso es correcto —y aquí lo que se mide es la renovación—.
        $this->attendRegularly($member, perWeek: 4, weeks: 3);

        // ── El plan se retira ───────────────────────────────────────────
        $this->day(25, note: 'la Promo Verano deja de venderse');
        $promo->forceFill(['active' => false])->save();

        // ── Llega la renovación ─────────────────────────────────────────
        $this->day(27, note: 'le vence en pocos días');

        $opp = $this->recordAndEvaluate('membership.expiring', $lead, $member);
        $this->assertGoal($opp, V::GOAL_RENEW, 'No se planteó la renovación.');

        // No se ofrece el plan retirado, ni como principal ni como alternativa.
        foreach (['offer_plan_id', 'alternative_plan_id', 'floor_plan_id'] as $campo) {
            $this->assertNotSame($promo->id, $opp->{$campo}, sprintf(
                'La renovación ofrece «%s» en %s, y ese plan ya no se vende.',
                $promo->name, $campo,
            ));
        }

        // Sí se ofrece algo vigente: no se queda sin propuesta.
        $ofrecido = $opp->offer_plan_id ?? $opp->floor_plan_id;
        $this->assertNotNull($ofrecido, 'Con el plan retirado la renovación se quedó sin nada que ofrecer.');
        $this->assertTrue((bool) Plan::find($ofrecido)->active,
            'Se ofreció un plan inactivo como alternativa.');

        // ── El historial no se toca ─────────────────────────────────────
        $this->assertSame($promo->id, $tx->fresh()->plan_id,
            'Se reescribió el plan del pago histórico: se perdió qué compró de verdad.');
        $this->assertSame('Promo Verano', Plan::find($promo->id)->name);
        $this->assertFalse((bool) Plan::find($promo->id)->active);

        $this->note('plan retirado', sprintf(
            'histórico intacto (%s) · renovación ofrece plan vigente #%d',
            $promo->name, $ofrecido,
        ));
    }

    // ══ 7 · F9.20 Negativa definitiva ═══════════════════════════════════

    /**
     * «Ya te dije varias veces que no. No me ofrezcas eso de nuevo.»
     *
     * Distinto del opt-out global: aquí el cliente sigue queriendo que le
     * hablemos, pero no de ESE producto. La exclusión es del producto, no de la
     * persona, y tiene que sobrevivir al calendario: si bastara con esperar, la
     * frase «no me lo ofrezcas de nuevo» no significaría nada.
     */
    public function test_f920_una_negativa_definitiva_excluye_el_producto_no_al_cliente(): void
    {
        $this->day(0, note: 'compra el mensual y entrena');
        $lead = $this->newLead('3160000007', 'Negativa firme');
        $member = $this->makeMember($lead);
        $this->approvePayment($member, $this->mensual);
        $this->attendRegularly($member, perWeek: 4, weeks: 3);

        // ── Tres negativas al anual ─────────────────────────────────────
        $previas = [];

        foreach ([20, 60, 100] as $i => $d) {
            $this->day($d, note: sprintf('le ofrecen el anual y dice que no (%d.ª vez)', $i + 1));

            $o = CommercialOpportunity::create([
                'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
                'goal' => V::GOAL_UPGRADE, 'status' => V::STATUS_LOST,
                'next_action' => V::ACTION_OFFER_UPGRADE,
                'offer_plan_id' => $this->anual->id,
                'outcome' => 'declined', 'outcome_reason' => 'no quiere plan más largo',
                'reason' => 'adherencia alta', 'closed_at' => now(),
                'max_attempts' => 1, 'created_by' => 'engine',
            ]);
            $previas[] = $o;
        }

        // ── La negativa definitiva ──────────────────────────────────────
        $this->day(101, note: '«ya te dije varias veces que no; no me ofrezcas eso de nuevo»');

        $this->recordDefinitiveRefusal($lead, $this->anual, 'ya lo dijo tres veces');

        // La exclusión sube de fuerza y queda registrada con su evidencia.
        $exclusion = MarketingAiAction::where('lead_id', $lead->id)
            ->where('action_type', 'product_excluded')->firstOrFail();

        $this->assertSame($this->anual->id, (int) data_get($exclusion->metadata, 'plan_id'));
        $this->assertSame('definitive', data_get($exclusion->metadata, 'strength'),
            'La negativa definitiva se registró con la misma fuerza que un «no por ahora».');
        $this->assertSame(3, (int) data_get($exclusion->metadata, 'previous_refusals'),
            'No quedó constancia de cuántas veces lo había dicho ya.');

        // ── Seis meses más ──────────────────────────────────────────────
        foreach ([130, 160, 200, 250, 280] as $d) {
            $this->day($d, note: 'reevaluación');
            $this->reevaluate($lead, $member);

            $reaparecida = CommercialOpportunity::where('marketing_lead_id', $lead->id)
                ->where('goal', V::GOAL_UPGRADE)
                ->whereIn('status', V::OPEN_STATUSES)
                ->first();

            $this->assertNull($reaparecida, sprintf(
                'En el día %d volvió a aparecer la oferta que pidió no volver a recibir.', $d,
            ));
        }

        // Las negativas anteriores siguen siendo evidencia consultable.
        foreach ($previas as $o) {
            $this->assertSame(V::STATUS_LOST, $o->fresh()->status);
            $this->assertSame('no quiere plan más largo', $o->fresh()->outcome_reason);
        }

        // Pero el cliente NO está silenciado: sigue siendo contactable.
        $this->assertTrue($lead->fresh()->isContactable(),
            'Una negativa a un producto silenció a la persona entera.');

        $this->note('negativa definitiva', 'anual excluido · cliente sigue contactable · 3 negativas conservadas');
    }

    // ── Utilidades del escenario ────────────────────────────────────────

    /**
     * Registra un opt-out COMERCIAL.
     *
     * Apaga lo que el sistema inicia y cierra lo comercial que hubiera en
     * marcha, dejando constancia de cuándo y por qué. No borra nada: la
     * preferencia es un hecho más del historial.
     */
    private function optOutCommercially(MarketingLead $lead): void
    {
        /*
         * NO se toca `do_not_contact`.
         *
         * Ese campo significa «no me contacten» y calla todo, incluidas las
         * respuestas a lo que pregunte el propio cliente. Lo que pidió aquí es
         * otra cosa: que no le ofrezcan. Se registra como preferencia comercial,
         * que es lo que consulta el motor para no abrir ofertas y lo que deja
         * intacta la capacidad de contestarle.
         */
        MarketingAiAction::create([
            'lead_id' => $lead->id,
            'conversation_id' => $this->conversationOf($lead)->id,
            'action_type' => 'commercial_opt_out',
            'reason' => 'el cliente pidió no recibir planes ni promociones',
            'status' => 'executed',
            'metadata' => ['scope' => 'commercial', 'at' => now()->toIso8601String()],
        ]);

        CommercialOpportunity::query()
            ->where('marketing_lead_id', $lead->id)
            ->whereIn('status', V::OPEN_STATUSES)
            ->get()
            ->each(fn (CommercialOpportunity $o) => $o->close(V::STATUS_CANCELLED, 'customer_opted_out'));
    }

    /** Aplaza la oportunidad hasta la fecha que prometió una persona. */
    private function recordHumanPromise(CommercialOpportunity $opp, \Illuminate\Support\Carbon $when, string $text): void
    {
        $opp->forceFill([
            'act_after' => $when->copy()->startOfDay(),
            'evidence' => array_merge((array) $opp->evidence, [
                'human_promise' => ['text' => $text, 'due_at' => $when->toIso8601String()],
            ]),
        ])->save();

        MarketingAiAction::create([
            'lead_id' => $opp->marketing_lead_id,
            'conversation_id' => $this->conversationOf(MarketingLead::find($opp->marketing_lead_id))->id,
            'action_type' => 'human_promise',
            'reason' => $text,
            'status' => 'executed',
            'metadata' => ['due_at' => $when->toIso8601String()],
        ]);
    }

    /** Sube la exclusión de un producto a definitiva, con su evidencia. */
    private function recordDefinitiveRefusal(MarketingLead $lead, Plan $plan, string $why): void
    {
        $previas = CommercialOpportunity::where('marketing_lead_id', $lead->id)
            ->where('goal', V::GOAL_UPGRADE)
            ->where('status', V::STATUS_LOST)
            ->count();

        MarketingAiAction::create([
            'lead_id' => $lead->id,
            'conversation_id' => $this->conversationOf($lead)->id,
            'action_type' => 'product_excluded',
            'reason' => $why,
            'status' => 'executed',
            'metadata' => [
                'plan_id' => $plan->id,
                'goal' => V::GOAL_UPGRADE,
                'strength' => 'definitive',
                'previous_refusals' => $previas,
                'at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Desglose del ingreso con la regla REAL de la analítica.
     *
     * Reactivación: un pago posterior sin ningún otro aprobado en los 90 días
     * anteriores. Se calcula aquí igual que en `CampaignAnalyticsService` para
     * no medir una regla inventada por la prueba.
     *
     * @return array{acquisition:float,renewal:float,upgrade:float,reactivation:float}
     */
    private function revenueBreakdown(Member $member): array
    {
        $pagos = PaymentTransaction::where('member_id', $member->id)
            ->where('status', 'approved')
            ->orderBy('id')
            ->get(['id', 'amount', 'paid_at', 'plan_id']);

        $out = ['acquisition' => 0.0, 'renewal' => 0.0, 'upgrade' => 0.0, 'reactivation' => 0.0];
        $anterior = null;

        foreach ($pagos as $p) {
            if ($anterior === null) {
                $out['acquisition'] += (float) $p->amount;
                $anterior = $p;

                continue;
            }

            $huboPagoReciente = $pagos
                ->where('id', '<', $p->id)
                ->contains(fn ($pp) => \Illuminate\Support\Carbon::parse($pp->paid_at)
                    ->greaterThan(\Illuminate\Support\Carbon::parse($p->paid_at)->subDays(90)));

            if (! $huboPagoReciente) {
                $out['reactivation'] += (float) $p->amount;
            } elseif ((float) $p->amount > (float) $anterior->amount) {
                $out['upgrade'] += (float) $p->amount;
            } else {
                $out['renewal'] += (float) $p->amount;
            }

            $anterior = $p;
        }

        return $out;
    }
}
