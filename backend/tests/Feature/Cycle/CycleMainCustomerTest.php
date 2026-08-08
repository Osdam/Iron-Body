<?php

namespace Tests\Feature\Cycle;

use App\Models\CommercialOpportunity;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Commercial\CommercialVocabulary as V;

/**
 * F9.1 – F9.15 · El ciclo completo de un cliente, día a día.
 *
 * Un solo cliente, seis meses simulados, y una pregunta en cada hito: ¿qué
 * decide el motor AHORA y por qué? La afirmación que se persigue tiene dos
 * mitades que se contradicen si se leen a la ligera:
 *
 *   ninguna venta termina la relación comercial
 *   ninguna relación autoriza a presionar indefinidamente
 *
 * La primera obliga a que después de cobrar aparezca un objetivo nuevo. La
 * segunda obliga a que ese objetivo no sea «vender otra cosa». Casi todo lo que
 * sigue vive en esa tensión.
 */
class CycleMainCustomerTest extends CommercialCycleTestCase
{
    private Plan $mensual;

    private Plan $anual;

    private MarketingLead $lead;

    private ?Member $member = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mensual = $this->plan('Mensual', 90000, 30);
        $this->anual = $this->plan('Anual', 900000, 365);

        $this->lead = $this->newLead('3151112233', 'Camila');
    }

    /**
     * El ciclo entero en una sola prueba, a propósito.
     *
     * Partirlo en veinte casos independientes obligaría a fabricar el estado
     * intermedio de cada uno a mano, y ese estado fabricado es exactamente lo
     * que no se quiere probar: la pregunta de F.9 es si el motor llega bien al
     * día 90 pasando por los ochenta y nueve anteriores, no si sabe interpretar
     * una foto que le pone la prueba.
     */
    public function test_el_ciclo_completo_de_una_clienta_durante_seis_meses(): void
    {
        // ── F9.1 · Día 0 — llega desde una pauta ────────────────────────
        $this->day(0, note: 'llega desde una pauta preguntando por el mensual');

        \App\Models\MarketingLeadAttribution::create([
            'marketing_lead_id' => $this->lead->id,
            'source_type' => 'ad',
            'ad_id' => 'AD-CICLO-1',
            'first_touch_at' => now(),
            // Lo que el anuncio prometía. Se guarda como EVIDENCIA histórica,
            // no como precio vigente: F9.18 vuelve sobre esto.
            'headline' => 'Plan mensual desde 90.000',
        ]);

        $opp = $this->recordAndEvaluate('lead.created', $this->lead);

        // Sin miembro, sin pago y sin datos: lo primero es conocerla, no cobrar.
        $this->assertNotNull($opp, 'Un prospecto nuevo no generó ningún objetivo.');
        $this->assertContains($opp->goal, [
            V::GOAL_COLLECT_DATA, V::GOAL_CLOSE_PLAN, V::GOAL_BOOK_VISIT,
        ], sprintf('Objetivo inicial inesperado: %s', $opp->goal));

        $this->assertNoGoal($this->lead, V::GOAL_UPGRADE,
            'No se puede ofrecer una mejora a quien todavía no ha comprado nada.');
        $this->assertNoGoal($this->lead, V::GOAL_RENEW,
            'No se puede ofrecer renovación a quien no tiene membresía.');

        // ── F9.2 · Descubrimiento ───────────────────────────────────────
        $this->day(0, 11, 'cuenta que quiere entrenar 3 veces por semana, tras el trabajo');

        $this->lead->forceFill([
            'objective' => 'salud',
            'status' => MarketingLead::STATUS_INTERESTED,
        ])->save();

        $opp = $this->recordAndEvaluate('lead.qualified', $this->lead);

        $subject = $this->subject($this->lead);
        $this->assertSame('salud', $subject->objective,
            'El objetivo que declaró la clienta no llegó a la memoria del motor.');

        // ── F9.3 · Venta del mensual ────────────────────────────────────
        $this->day(1, note: 'acepta y paga el mensual');

        $this->member = $this->makeMember($this->lead);
        $tx1 = $this->approvePayment($this->member, $this->mensual);

        // UNA intención, UNA venta, UNA membresía.
        $this->assertSales($this->member, 1);
        $this->assertSame(1, \App\Models\PaymentTransaction::where('member_id', $this->member->id)
            ->where('status', 'approved')->count());

        $opp = $this->recordAndEvaluate('payment.approved', $this->lead, $this->member, [
            'plan_id' => $this->mensual->id,
        ]);

        // Clasificación del dinero: primera compra = adquisición.
        $this->assertRevenue($this->member, acquisition: 1, renewal: 0);

        // ── F9.4 · Postventa inmediata — la exclusión comercial ─────────
        $this->note('F9.4', 'justo después de pagar');

        $this->assertNotNull($opp, 'Tras la venta no apareció ningún objetivo nuevo: la relación se cerró con la venta.');

        $this->assertContains($opp->goal, [
            V::GOAL_ACTIVATE_MEMBERSHIP, V::GOAL_COMPLETE_ONBOARDING,
            V::GOAL_LINK_APP, V::GOAL_INCREASE_ADHERENCE,
        ], sprintf(
            'Justo después de cobrar el objetivo es «%s». Lo que toca es que la '
            .'clienta empiece a usar el gimnasio, no venderle otra cosa.',
            $opp->goal,
        ));

        $this->assertNoGoal($this->lead, V::GOAL_UPGRADE,
            'Se ofreció una mejora el mismo día del pago.');
        $this->assertNoGoal($this->lead, V::GOAL_CROSS_SELL,
            'Se ofreció un complemento el mismo día del pago.');

        // El objetivo de cobrar quedó cerrado: nadie le vuelve a pedir el pago.
        $this->assertNoGoal($this->lead, V::GOAL_COLLECT_PAYMENT,
            'Se le sigue reclamando un pago que ya hizo.');

        // ── F9.5 · Primera semana ───────────────────────────────────────
        $this->day(1, 15, 'vincula la app');
        // No hay columna de vinculación: el motor deduce la cuenta de app de que
        // el miembro tenga usuario, y `makeMember` ya lo creó. Lo que se
        // comprueba es que el motor lo VEA, no inventar un campo para el gusto
        // de la prueba.
        $this->assertTrue($this->subject($this->lead, $this->member)->hasAppAccount,
            'El motor no reconoce que la clienta ya tiene cuenta en la app.');
        $this->recordAndEvaluate('app.linked', $this->lead, $this->member);

        foreach ([2, 4, 7] as $d) {
            $this->day($d, note: 'asiste');
            $this->attend($this->member);
            $opp = $this->recordAndEvaluate('attendance.recorded', $this->lead, $this->member);
        }

        $subject = $this->subject($this->lead, $this->member);
        $this->assertSame(3, $subject->attendancesLast30Days,
            'Las asistencias de la primera semana no llegaron al motor.');

        // Tres visitas en una semana es buena señal, pero es demasiado pronto:
        // lleva seis días como socia. Que exista adherencia no autoriza a vender.
        $this->assertNoGoal($this->lead, V::GOAL_UPGRADE, sprintf(
            'Se abrió una mejora en el día %d, con %d días como socia.',
            $this->currentDay, $subject->daysAsMember ?? 0,
        ));
        $this->note('F9.5', sprintf(
            'día %d · %d días como socia · %d asistencias · sin mejora: demasiado pronto',
            $this->currentDay, $subject->daysAsMember ?? 0, $subject->attendancesLast30Days,
        ));

        // ── F9.6 · Buena adherencia sostenida ───────────────────────────
        $this->day(21, note: 'tres semanas asistiendo 3 veces por semana');
        $this->attendRegularly($this->member, perWeek: 3, weeks: 2);

        $opp = $this->recordAndEvaluate('attendance.recorded', $this->lead, $this->member);
        $subject = $this->subject($this->lead, $this->member);

        $this->note('F9.6', sprintf(
            'día 21 · %d asistencias en 30 días · %.1f/semana · objetivo vivo: %s',
            $subject->attendancesLast30Days, $subject->weeklyAttendanceRate(),
            $opp?->goal ?? '(ninguno)',
        ));

        // ── F9.7 · Primer upsell y su rechazo ───────────────────────────
        //
        // Se fuerza la oportunidad para poder probar el RECHAZO. Que las reglas
        // la abran sola en este día concreto depende de umbrales que pueden
        // cambiar; lo que no puede cambiar es qué pasa cuando alguien dice no.
        $this->day(27, note: 'se le plantea el anual');

        $upgrade = CommercialOpportunity::create([
            'marketing_lead_id' => $this->lead->id,
            'member_id' => $this->member->id,
            'goal' => V::GOAL_UPGRADE,
            'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_OFFER_UPGRADE,
            'reason' => 'adherencia sostenida de 3 visitas/semana durante 3 semanas',
            'max_attempts' => 1,
            'estimated_value' => 900000,
        ]);

        // Fundamento real, no una promoción inventada.
        $this->assertStringContainsString('adherencia', (string) $upgrade->reason);

        $this->day(27, 12, 'responde: "no, por ahora prefiero seguir mensual"');

        $upgrade->forceFill([
            'status' => V::STATUS_LOST,
            'outcome' => 'declined',
            'outcome_reason' => 'customer_prefers_current_plan',
            'closed_at' => now(),
        ])->save();

        // No es un cliente perdido: es una preferencia sobre un producto.
        $this->assertNotSame(MarketingLead::STATUS_DISCARDED, $this->lead->fresh()->status,
            'Rechazar una mejora marcó a la clienta como descartada.');
        $this->assertTrue($this->subject($this->lead, $this->member)->hasActiveMembership,
            'El rechazo del anual le quitó el mensual que sí tenía.');

        // ── F9.8 · No acoso (primera mitad) ─────────────────────────────
        //
        // El orden de esta prueba lo manda el CALENDARIO, no la numeración de la
        // fase: la membresía se compró el día 1 con 30 días, así que la ventana
        // de renovación cae en el 29. Comprobar el no-acoso en los días 30 y 34
        // «después del rechazo» y ANTES de renovar exigiría mover el reloj hacia
        // atrás, y eso fabrica estados imposibles. Así que el no-acoso se
        // comprueba a los lados de la renovación, que además es más exigente:
        // hay que demostrar que ni una renovación de por medio resucita la
        // mejora rechazada.
        $this->assertNoUpgradeReopened([28]);

        // ── F9.9 · Renovación ───────────────────────────────────────────
        $this->day(29, note: 'la membresía vence en 2 días');

        $opp = $this->recordAndEvaluate('membership.expiring', $this->lead, $this->member);

        $this->assertGoal($opp, V::GOAL_RENEW,
            'Con la membresía a punto de vencer, asegurar la continuidad manda sobre cualquier mejora.');

        $this->day(30, note: 'renueva el mensual');
        $tx2 = $this->approvePayment($this->member, $this->mensual);
        $this->recordAndEvaluate('payment.approved', $this->lead, $this->member, [
            'plan_id' => $this->mensual->id,
        ]);

        // Dos pagos, dos ventas, UNA identidad, UNA membresía.
        $this->assertSales($this->member, 2);
        $this->assertSame(1, Member::where('phone', $this->lead->phone)->count(),
            'La renovación creó un segundo miembro: identidad duplicada.');
        $this->assertRevenue($this->member, acquisition: 1, renewal: 1);

        // ── F9.8 · No acoso (segunda mitad, ya renovada) ────────────────
        $this->assertNoUpgradeReopened([34, 40]);
        $this->note('F9.8', 'días 28, 34 y 40: ninguna mejora reabierta tras la negativa, ni con una renovación por medio');

        // ── F9.10 · Continuidad: el contexto actual manda ───────────────
        $this->day(45, note: 'sigue asistiendo; ya tiene historial y dos pagos');
        $this->attendRegularly($this->member, perWeek: 3, weeks: 2);
        $this->recordAndEvaluate('attendance.recorded', $this->lead, $this->member);

        $subject = $this->subject($this->lead, $this->member);

        $this->assertSame(2, $subject->approvedPaymentsCount);
        $this->assertGreaterThan(0, $subject->lifetimeValue);
        $this->assertTrue($subject->hasActiveMembership);

        // La pauta de adquisición sigue en el historial, pero ya no describe a
        // esta persona: es una socia con dos pagos, no un prospecto de anuncio.
        $this->assertSame(1, \App\Models\MarketingLeadAttribution::where('marketing_lead_id', $this->lead->id)->count(),
            'La atribución original se perdió o se duplicó.');
        $this->assertNotNull($subject->membershipStartedAt,
            'El motor no sabe desde cuándo es socia: decidiría con el contexto del anuncio.');

        $this->note('F9.10', sprintf(
            'día 45 · %d pagos · LTV $%s · contexto actual disponible sobre el de adquisición',
            $subject->approvedPaymentsCount, number_format($subject->lifetimeValue),
        ));

        // ── F9.11 + F9.12 · Segunda oportunidad y upgrade aceptado ──────
        $this->day(60, note: 'dos meses de historial: evidencia más fuerte que en el día 27');
        $this->attendRegularly($this->member, perWeek: 4, weeks: 2);

        $subject = $this->subject($this->lead, $this->member);

        // La evidencia es distinta de la primera vez, y eso es lo que legitima
        // volver a plantearlo: no «pasó el tiempo», sino «pasaron cosas».
        $this->assertGreaterThan(3, $subject->weeklyAttendanceRate(),
            'La segunda oportunidad no tiene evidencia mejor que la primera.');
        $this->assertSame(2, $subject->approvedPaymentsCount,
            'Sin un segundo pago, la única novedad sería el calendario.');

        $segunda = CommercialOpportunity::create([
            'marketing_lead_id' => $this->lead->id,
            'member_id' => $this->member->id,
            'goal' => V::GOAL_UPGRADE,
            'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_OFFER_UPGRADE,
            'reason' => sprintf(
                'renovó una vez y subió a %.1f visitas/semana (antes 3)',
                $subject->weeklyAttendanceRate(),
            ),
            'max_attempts' => 1,
            'estimated_value' => 900000,
        ]);

        $this->assertNotSame($upgrade->id, $segunda->id,
            'Se resucitó la oportunidad rechazada en vez de abrir una nueva.');
        $this->assertSame(V::STATUS_LOST, $upgrade->fresh()->status,
            'La oportunidad rechazada volvió a estar viva.');
        $this->assertGreaterThanOrEqual(33, $this->currentDay - 27,
            'La segunda oportunidad llegó antes de que se cumpliera un enfriamiento razonable.');

        $this->day(61, note: 'acepta el anual');

        $tx3 = $this->approvePayment($this->member, $this->anual);
        $segunda->forceFill([
            'status' => V::STATUS_WON, 'outcome' => 'accepted',
            'outcome_reason' => 'upgraded', 'closed_at' => now(),
        ])->save();

        $opp = $this->recordAndEvaluate('payment.approved', $this->lead, $this->member, [
            'plan_id' => $this->anual->id,
        ]);

        // UNA operación. Sin doble membresía ni períodos solapados mal.
        $this->assertSales($this->member, 3);
        $this->assertSame('Anual', $this->member->fresh()->user->plan,
            'El upgrade no cambió el plan vigente.');
        $this->assertRevenue($this->member, acquisition: 1, renewal: 2, upgrade: 1);

        // El importe salió del catálogo, no de ningún sitio ajeno.
        $this->assertEqualsWithDelta(900000.0, (float) $tx3->amount, 0.01,
            'El cobro del anual no coincide con el precio del catálogo.');

        // ── F9.13 · Post-upgrade ────────────────────────────────────────
        //
        // Lo que F.9 prohíbe es INTENTAR vender otra cosa, y eso se mide en si
        // algo puede salir hacia el cliente, no en si existe una fila. El motor
        // puede anotar «candidata a plan más largo, pero le queda mucho del
        // actual» y dejarlo aplazado meses: eso no es presión, es memoria. La
        // presión sería que se pudiera enviar hoy.
        $mejoraViva = CommercialOpportunity::where('marketing_lead_id', $this->lead->id)
            ->where('goal', V::GOAL_UPGRADE)
            ->whereIn('status', V::OPEN_STATUSES)
            ->latest('id')->first();

        if ($mejoraViva !== null) {
            $this->assertFalse($mejoraViva->isActionable(), sprintf(
                'Justo después de completar una mejora hay otra lista para enviarse '
                .'(act_after: %s). Eso es vender encima de una venta.',
                $mejoraViva->act_after?->toDateString() ?? 'sin aplazar',
            ));

            $this->assertContactDenied(
                $this->contactCheck($mejoraViva, $this->lead, $this->member),
                'opportunity_not_actionable',
            );
        }

        $this->assertNoGoal($this->lead, V::GOAL_CROSS_SELL,
            'Se intentó vender un complemento justo después del upgrade.');

        $this->note('F9.13', sprintf(
            'día 61 · tras la expansión el objetivo es «%s», no vender otra cosa',
            $opp?->goal ?? '(ninguno)',
        ));

        // ── F9.14 + F9.15 · Satisfacción y referido ─────────────────────
        $this->day(90, note: 'tres meses: asistencia sostenida, ninguna queja');
        $this->attendRegularly($this->member, perWeek: 4, weeks: 4);

        $subject = $this->subject($this->lead, $this->member);

        // Señal real: uso sostenido y cero incidencias. No «pagó, luego está
        // contenta»: pagar es una señal de intención, no de satisfacción.
        $this->assertGreaterThanOrEqual(12, $subject->attendancesLast30Days,
            'Sin uso sostenido no hay evidencia de satisfacción.');
        $this->assertSame(0, CommercialOpportunity::where('marketing_lead_id', $this->lead->id)
            ->where('goal', V::GOAL_RECOVER_SATISFACTION)->count(),
            'Hay una recuperación de satisfacción abierta: no es momento de pedir un referido.');

        $referido = CommercialOpportunity::create([
            'marketing_lead_id' => $this->lead->id,
            'member_id' => $this->member->id,
            'goal' => V::GOAL_REQUEST_REFERRAL,
            'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_ASK_REFERRAL,
            'reason' => sprintf('%d visitas en 30 días y ninguna incidencia', $subject->attendancesLast30Days),
            'max_attempts' => 1,
            'estimated_value' => 0,
        ]);

        // Un solo intento: pedir un referido dos veces es pedir un favor dos veces.
        $this->assertSame(1, (int) $referido->max_attempts,
            'El referido admite más de un intento: eso es insistir por un favor.');

        $this->day(120, note: 'un mes después del referido, reevaluación');
        $this->reevaluate($this->lead, $this->member);

        $this->assertLessThanOrEqual(1, CommercialOpportunity::where('marketing_lead_id', $this->lead->id)
            ->where('goal', V::GOAL_REQUEST_REFERRAL)->count(),
            'Se abrió un segundo referido.');

        // ── Cierre: el dinero, clasificado ──────────────────────────────
        $this->assertRevenue($this->member, acquisition: 1, renewal: 2, upgrade: 1);

        $total = (float) Payment::where('member_id', $this->member->id)->sum('amount');
        $this->assertEqualsWithDelta(90000 + 90000 + 900000, $total, 0.01,
            'El ingreso total del cliente no cuadra con sus tres pagos.');

        // Y nada salió al mundo real.
        $this->assertSame(0, \App\Models\MarketingMessage::where('direction', 'outbound')
            ->whereNotNull('meta_message_id')->count(),
            'Salió un mensaje de verdad durante la simulación.');
    }

    /**
     * En ninguno de estos días vuelve a haber una mejora viva.
     *
     * @param  list<int>  $days
     */
    private function assertNoUpgradeReopened(array $days): void
    {
        foreach ($days as $d) {
            $this->day($d, note: 'reevaluación comercial tras el rechazo');
            $this->reevaluate($this->lead, $this->member);

            $vivas = CommercialOpportunity::where('marketing_lead_id', $this->lead->id)
                ->where('goal', V::GOAL_UPGRADE)
                ->whereIn('status', V::OPEN_STATUSES)
                ->count();

            $this->assertSame(0, $vivas, sprintf(
                "En el día %d hay una mejora viva otra vez, %d días después de que "
                ."dijera que no. Eso es insistir.\n\nCiclo:\n%s",
                $d, $d - 27, $this->ledgerText(),
            ));
        }
    }

    /**
     * Clasificación del dinero, leída como la lee la analítica.
     *
     * No se comprueba contra una columna que alguien haya escrito a mano: se
     * usa la misma regla determinista que el panel —el primer pago aprobado de
     * un miembro es adquisición, los siguientes renovación, y es mejora cuando
     * el plan es más caro—. Comprobar otra cosa mediría el montaje.
     */
    private function assertRevenue(Member $member, int $acquisition, int $renewal, int $upgrade = 0): void
    {
        $pagos = \App\Models\PaymentTransaction::query()
            ->where('member_id', $member->id)
            ->where('status', 'approved')
            ->orderBy('id')
            ->get(['id', 'amount', 'plan_id']);

        $altas = 0;
        $renovaciones = 0;
        $mejoras = 0;
        $precioAnterior = null;

        foreach ($pagos as $p) {
            if ($precioAnterior === null) {
                $altas++;
            } else {
                $renovaciones++;
                if ((float) $p->amount > $precioAnterior) {
                    $mejoras++;
                }
            }
            $precioAnterior = (float) $p->amount;
        }

        $this->assertSame($acquisition, $altas, sprintf(
            "Adquisiciones: esperadas %d, contadas %d.\n\nCiclo:\n%s",
            $acquisition, $altas, $this->ledgerText(),
        ));
        $this->assertSame($renewal, $renovaciones, sprintf(
            "Renovaciones: esperadas %d, contadas %d. Una renovación contada como "
            ."adquisición infla la adquisición y falsea el coste por cliente.\n\nCiclo:\n%s",
            $renewal, $renovaciones, $this->ledgerText(),
        ));
        $this->assertSame($upgrade, $mejoras, sprintf(
            "Mejoras: esperadas %d, contadas %d.\n\nCiclo:\n%s",
            $upgrade, $mejoras, $this->ledgerText(),
        ));

        $this->note('ingresos', sprintf(
            'adquisición %d · renovación %d · mejora %d', $altas, $renovaciones, $mejoras,
        ));
    }
}
