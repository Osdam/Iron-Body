<?php

namespace Tests\Feature\Cycle;

use App\Models\CommercialOpportunity;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Commercial\CommercialVocabulary as V;

/**
 * Cien clientes, ciento ochenta días.
 *
 * Los escenarios anteriores prueban caminos elegidos. Este no prueba ningún
 * camino: mezcla perfiles y deja correr el tiempo para ver qué se acumula. Es la
 * única forma de encontrar la clase de fallo que no aparece en un caso —una
 * oportunidad que se reabre sola cada semana, una alerta que se dispara cada
 * evaluación, una negativa que se olvida al tercer mes— porque en un solo
 * cliente y tres saltos de reloj no se nota.
 *
 * No se afirma nada sobre lo que el motor DEBE decidir para cada perfil: eso ya
 * está en los escenarios. Aquí se afirma lo que no puede pasar NUNCA, con
 * cualquier historia, y se busca activamente el estado imposible.
 */
class CycleVolumeTest extends CommercialCycleTestCase
{
    /** Los perfiles reales de un gimnasio, en la proporción aproximada. */
    private const PROFILES = [
        'alta_adherencia' => 20,
        'baja_adherencia' => 15,
        'sensible_precio' => 12,
        'rechaza_upsell' => 12,
        'opt_out' => 8,
        'abandona_pago' => 11,
        'renueva' => 12,
        'churn' => 6,
        'reactiva' => 4,
    ];

    private Plan $mensual;

    private Plan $trimestral;

    private Plan $anual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mensual = $this->plan('Mensual', 90000, 30);
        $this->trimestral = $this->plan('Trimestral', 240000, 90);
        $this->anual = $this->plan('Anual', 900000, 365);
    }

    public function test_cien_clientes_durante_seis_meses_no_producen_estados_imposibles(): void
    {
        $this->assertSame(100, array_sum(self::PROFILES), 'Los perfiles no suman cien clientes.');

        // ── Día 0: llegan los cien ──────────────────────────────────────
        $this->day(0, note: 'llegan 100 prospectos');

        $clientes = [];
        $n = 0;

        foreach (self::PROFILES as $perfil => $cuantos) {
            for ($i = 0; $i < $cuantos; $i++) {
                $n++;
                $lead = $this->newLead(sprintf('31%08d', 5000000 + $n), sprintf('%s-%02d', $perfil, $i));
                $clientes[] = ['perfil' => $perfil, 'lead' => $lead, 'member' => null];
            }
        }

        // La bitácora de cien clientes sería ilegible: se resume.
        $this->ledger = ['DÍA 0 · 100 prospectos creados en 9 perfiles'];

        // ── El recorrido de seis meses ──────────────────────────────────
        //
        // Se avanza en saltos de una semana. Cada salto: los hechos de cada
        // perfil y una reevaluación comercial. Veintiséis semanas.
        foreach (range(0, 180, 7) as $dia) {
            $this->day($dia);

            foreach ($clientes as $idx => &$c) {
                $this->advanceProfile($c, $dia);
            }
            unset($c);

            // Y al final de cada semana, la comprobación de invariantes sobre
            // TODOS. Comprobar solo al final esconde el momento en que se rompe.
            $this->assertNoImpossibleState($clientes, $dia);
        }

        // ── Lo que se buscaba ───────────────────────────────────────────

        $this->assertNoDuplicateOpportunities($clientes);
        $this->assertNoFollowupStorms($clientes);
        $this->assertOptOutRespected($clientes);
        $this->assertMoneyIsCoherent();

        // Resumen de la corrida, para que el resultado sea legible.
        $this->note('RESUMEN', sprintf(
            '%d leads · %d miembros · %d pagos aprobados · %d oportunidades (%d vivas) · %d ventas',
            MarketingLead::count(),
            Member::count(),
            PaymentTransaction::where('status', 'approved')->count(),
            CommercialOpportunity::count(),
            CommercialOpportunity::whereIn('status', V::OPEN_STATUSES)->count(),
            Payment::count(),
        ));

        // Y nada de esto salió al mundo.
        $this->assertSame(0, \App\Models\MarketingMessage::where('direction', 'outbound')
            ->whereNotNull('meta_message_id')->count(),
            'Salió un mensaje real durante la simulación de volumen.');
    }

    /** Lo que le pasa a este cliente en esta semana, según su perfil. */
    private function advanceProfile(array &$c, int $dia): void
    {
        $lead = $c['lead'];
        $perfil = $c['perfil'];

        // Semana 1: casi todos compran el mensual. Los que abandonan el pago, no.
        if ($dia === 7 && $perfil !== 'abandona_pago') {
            $c['member'] = $this->makeMember($lead);
            $this->approvePayment($c['member'], $this->mensual);
        }

        if ($dia === 7 && $perfil === 'abandona_pago') {
            $c['member'] = $this->makeMember($lead);
            $this->pendingPayment($c['member'], $this->mensual);
        }

        $member = $c['member'];

        if ($member === null) {
            $this->reevaluate($lead);

            return;
        }

        switch ($perfil) {
            case 'alta_adherencia':
                $this->attend($member);
                $this->attend($member);
                $this->attend($member);
                if ($dia % 28 === 0 && $dia > 7) {
                    $this->approvePayment($member, $this->mensual);
                }
                break;

            case 'baja_adherencia':
                if ($dia % 21 === 0) {
                    $this->attend($member);
                }
                if ($dia % 28 === 0 && $dia > 7) {
                    $this->approvePayment($member, $this->mensual);
                }
                break;

            case 'sensible_precio':
                $this->attend($member);
                // Registra una objeción de precio cada mes.
                if ($dia % 28 === 0 && $dia > 7) {
                    \App\Models\MarketingAiAction::create([
                        'lead_id' => $lead->id,
                        'action_type' => 'register_objection',
                        'reason' => 'precio',
                        'status' => 'executed',
                        'metadata' => ['objection' => 'price'],
                    ]);
                    $this->approvePayment($member, $this->mensual);
                }
                break;

            case 'rechaza_upsell':
                $this->attend($member);
                $this->attend($member);
                // Al mes le ofrecen mejorar y dice que no. Una sola vez.
                if ($dia === 35) {
                    CommercialOpportunity::create([
                        'marketing_lead_id' => $lead->id, 'member_id' => $member->id,
                        'goal' => V::GOAL_UPGRADE, 'status' => V::STATUS_LOST,
                        'next_action' => V::ACTION_OFFER_UPGRADE,
                        'outcome' => 'declined',
                        'outcome_reason' => 'no le interesa un plan más largo',
                        'reason' => 'adherencia alta', 'closed_at' => now(),
                        'max_attempts' => 1, 'created_by' => 'engine',
                    ]);
                }
                if ($dia % 28 === 0 && $dia > 7) {
                    $this->approvePayment($member, $this->mensual);
                }
                break;

            case 'opt_out':
                // Al mes pide que no le ofrezcan nada más.
                if ($dia === 35) {
                    $lead->forceFill(['do_not_contact' => true])->save();
                }
                $this->attend($member);
                break;

            case 'abandona_pago':
                // Nunca paga. El link sigue pendiente.
                break;

            case 'renueva':
                $this->attend($member);
                $this->attend($member);
                if ($dia % 28 === 0 && $dia > 7) {
                    $this->approvePayment($member, $this->mensual);
                }
                break;

            case 'churn':
                // Vino el primer mes y desapareció; no renueva.
                if ($dia <= 28) {
                    $this->attend($member);
                }
                break;

            case 'reactiva':
                // Se va y vuelve al cuarto mes.
                if ($dia <= 28) {
                    $this->attend($member);
                }
                if ($dia === 126) {
                    $this->approvePayment($member, $this->mensual);
                    $this->attend($member);
                }
                break;
        }

        $this->reevaluate($lead, $member);
    }

    // ── Invariantes a escala ────────────────────────────────────────────

    /** Ningún cliente puede estar en un estado que no existe. */
    private function assertNoImpossibleState(array $clientes, int $dia): void
    {
        // Un pago aprobado, una venta. Nunca más.
        $duplicadas = \Illuminate\Support\Facades\DB::table('payments')
            ->select('reference')
            ->groupBy('reference')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(0, $duplicadas, sprintf(
            'Día %d: hay referencias de venta duplicadas.', $dia,
        ));

        // Nadie tiene dos miembros con el mismo teléfono.
        $identidades = \Illuminate\Support\Facades\DB::table('members')
            ->select('phone')
            ->whereNotNull('phone')
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(0, $identidades, sprintf(
            'Día %d: hay teléfonos con más de un miembro: identidad duplicada.', $dia,
        ));
    }

    /**
     * Nunca dos oportunidades vivas del mismo objetivo, ni objetivos vivos
     * contradictorios.
     */
    private function assertNoDuplicateOpportunities(array $clientes): void
    {
        $problemas = [];

        foreach ($clientes as $c) {
            $vivas = CommercialOpportunity::query()
                ->where('marketing_lead_id', $c['lead']->id)
                ->whereIn('status', V::OPEN_STATUSES)
                ->get();

            $porObjetivo = $vivas->groupBy('goal');

            foreach ($porObjetivo as $goal => $filas) {
                if ($filas->count() > 1) {
                    $problemas[] = sprintf('%s: %d vivas de «%s»', $c['lead']->name, $filas->count(), $goal);
                }
            }

            // Más de un objetivo vivo del motor es haber perdido el contexto:
            // `decide()` devuelve uno.
            $delMotor = $vivas->where('created_by', 'engine');
            if ($delMotor->count() > 1) {
                $problemas[] = sprintf(
                    '%s: %d objetivos del motor vivos a la vez (%s)',
                    $c['lead']->name, $delMotor->count(), $delMotor->pluck('goal')->implode(', '),
                );
            }
        }

        $this->assertSame([], $problemas, sprintf(
            "Oportunidades acumuladas:\n  %s", implode("\n  ", array_slice($problemas, 0, 15)),
        ));
    }

    /**
     * Ninguna tormenta de seguimientos.
     *
     * Veintiséis semanas de reevaluaciones sobre cien personas son 2.600
     * oportunidades de abrir algo. Si cada una abriera una fila, el resultado
     * sería un histórico inservible; el techo real tiene que estar mucho más
     * abajo, y sobre todo tiene que estar acotado por persona.
     */
    private function assertNoFollowupStorms(array $clientes): void
    {
        $ruidosos = [];

        /*
         * Se cuenta la INSISTENCIA, no las filas.
         *
         * Contar oportunidades totales medía la contabilidad y no el daño: un
         * socio que renueva seis veces tiene, legítimamente, seis oportunidades
         * de renovación cumplidas, y sumarlas a las de acompañamiento daba
         * veintisiete filas que no molestan a nadie. Lo que molesta es cuántas
         * veces se le escribe, y eso vive en `attempts`.
         *
         * El techo sale de la propia política —dos proactivos por semana— sobre
         * las 26 semanas de la simulación. Si el total de intentos lo supera, es
         * que alguna vía está saltándose el límite.
         */
        $maxSemanal = (int) config('commercial.contact_limits.max_proactive_per_week', 2);
        $techoIntentos = $maxSemanal * 26;

        foreach ($clientes as $c) {
            $intentos = (int) CommercialOpportunity::where('marketing_lead_id', $c['lead']->id)->sum('attempts');

            if ($intentos > $techoIntentos) {
                $ruidosos[] = sprintf(
                    '%s: %d intentos de contacto en 26 semanas (techo %d)',
                    $c['lead']->name, $intentos, $techoIntentos,
                );
            }

            // Y ningún objetivo concreto puede repetirse más veces que ciclos
            // ha habido: seis meses de plan mensual son seis renovaciones, no
            // veinte.
            $porObjetivo = CommercialOpportunity::where('marketing_lead_id', $c['lead']->id)
                ->get()->groupBy('goal');

            foreach ($porObjetivo as $goal => $filas) {
                if ($filas->count() > 8) {
                    $ruidosos[] = sprintf(
                        '%s: %d oportunidades de «%s» en seis meses',
                        $c['lead']->name, $filas->count(), $goal,
                    );
                }
            }
        }

        $this->assertSame([], $ruidosos, sprintf(
            "Insistencia por encima de la política:\n  %s",
            implode("\n  ", array_slice($ruidosos, 0, 15)),
        ));

        // Y las alertas comerciales tampoco se multiplican.
        app(\App\Services\Commercial\CommercialAlertService::class)->evaluate();
        app(\App\Services\Commercial\CommercialAlertService::class)->evaluate();
        app(\App\Services\Commercial\CommercialAlertService::class)->evaluate();

        $porHuella = \Illuminate\Support\Facades\DB::table('commercial_alerts')
            ->select('fingerprint')
            ->groupBy('fingerprint')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(0, $porHuella,
            'Tres evaluaciones seguidas duplicaron alertas: la bandeja se vuelve ruido.');
    }

    /** Quien pidió que no le ofrezcan nada, no recibe nada comercial. */
    private function assertOptOutRespected(array $clientes): void
    {
        $violaciones = [];

        foreach ($clientes as $c) {
            if ($c['perfil'] !== 'opt_out') {
                continue;
            }

            $lead = $c['lead']->fresh();

            $this->assertTrue((bool) $lead->do_not_contact,
                'El montaje no llegó a marcar el opt-out: la comprobación no probaría nada.');

            // Cualquier oportunidad comercial abierta DESPUÉS del opt-out.
            $comerciales = CommercialOpportunity::query()
                ->where('marketing_lead_id', $lead->id)
                ->whereIn('goal', [
                    V::GOAL_UPGRADE, V::GOAL_CROSS_SELL, V::GOAL_REQUEST_REFERRAL,
                ])
                ->whereIn('status', V::OPEN_STATUSES)
                ->get();

            foreach ($comerciales as $o) {
                $violaciones[] = sprintf('%s: «%s» viva tras el opt-out', $lead->name, $o->goal);
            }
        }

        $this->assertSame([], $violaciones, sprintf(
            "Se abrieron ofertas a quien pidió no recibirlas:\n  %s",
            implode("\n  ", $violaciones),
        ));
    }

    /** El dinero cuadra: ni pagos huérfanos ni ventas sin pago. */
    private function assertMoneyIsCoherent(): void
    {
        // Toda venta viene de una transacción aprobada.
        $ventasSinPago = \Illuminate\Support\Facades\DB::table('payments as pay')
            ->leftJoin('payment_transactions as tx', 'tx.reference', '=', 'pay.reference')
            ->whereNull('tx.id')
            ->count();

        $this->assertSame(0, $ventasSinPago,
            'Hay ventas sin transacción que las respalde: dinero inventado.');

        // Toda transacción aprobada produjo exactamente una venta.
        $aprobadas = PaymentTransaction::where('status', 'approved')->count();
        $ventas = Payment::count();

        $this->assertSame($aprobadas, $ventas, sprintf(
            'Hay %d pagos aprobados y %d ventas: %s.',
            $aprobadas, $ventas,
            $ventas > $aprobadas ? 'se contó dinero dos veces' : 'se perdió una venta',
        ));

        // Ninguna transacción aprobada con importe distinto del plan cobrado.
        $desajustadas = PaymentTransaction::query()
            ->join('plans', 'plans.id', '=', 'payment_transactions.plan_id')
            ->where('payment_transactions.status', 'approved')
            ->whereRaw('ABS(payment_transactions.amount - plans.price) > 0.5')
            ->count();

        $this->assertSame(0, $desajustadas,
            'Hay cobros por un importe que no es el del catálogo.');
    }
}
