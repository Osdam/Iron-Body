<?php

namespace App\Services\Commercial;

use App\Models\CommercialOpportunity;
use App\Models\Plan;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Qué hacer con esta persona, cuándo, y por qué no otra cosa.
 *
 * Determinista y auditable a propósito. La IA redacta el mensaje; **esto**
 * decide si toca escribir, qué ofrecer y en qué momento. Una oferta la tiene
 * que poder explicar un humano mirando una fila, y eso no se consigue dejando
 * la decisión dentro de un modelo de lenguaje.
 *
 * Las reglas están ordenadas por prioridad y la primera que encaja gana. El
 * orden no es casual: primero lo que ya está en marcha (un pago a medias, una
 * membresía que vence), después lo que abre negocio nuevo. El dinero
 * comprometido vale más que el dinero hipotético.
 *
 * `wait` es una decisión de pleno derecho, y la más infravalorada: escribirle a
 * alguien que acaba de pagar para venderle otra cosa destruye más relación de
 * la que construye.
 */
class NextBestActionEngine
{
    /** Catálogo activo, leído una vez por instancia (ver planLadder). */
    private ?\Illuminate\Support\Collection $planCache = null;

    public function __construct(private readonly SegmentCalculator $segments) {}

    /**
     * Días antes del vencimiento en los que la renovación pasa a ser el objetivo.
     *
     * Vive aquí y no repetido en dos reglas porque son dos las que lo necesitan
     * —la de renovación para activarse y la de acompañamiento para apartarse— y
     * dos números que tienen que coincidir acaban no coincidiendo.
     */
    public const RENEWAL_WINDOW_DAYS = 10;

    /**
     * Evalúa un sujeto y devuelve la decisión, sin persistir nada.
     *
     * @return array{goal:string,action:string,offer:?string,reason:string,confidence:float,
     *               act_after:?Carbon,exclusions:array,offer_plan_id:?int,
     *               alternative_plan_id:?int,floor_plan_id:?int,estimated_value:?float,
     *               max_attempts:int}|null
     */
    public function decide(CommercialSubject $subject): ?array
    {
        // El opt-out está por encima de cualquier oportunidad comercial. No es
        // una regla más: es la primera y no admite excepciones.
        if (! $subject->isContactable()) {
            return null;
        }

        /*
         * Y el opt-out COMERCIAL, igual, pero sin silenciar a la persona.
         *
         * «No me ofrezcan más planes» y «no me contacten» no son lo mismo. El
         * segundo es `do_not_contact` y calla todo. El primero solo retira el
         * permiso de OFRECER: quien lo pide sigue teniendo derecho a que le
         * contesten cuando pregunta algo, y si además dejara de poder preguntar
         * el precio, ejercer su preferencia le habría empeorado el servicio.
         *
         * Aquí se corta lo que el sistema INICIA. Responder vive en
         * `canReplyReactively()` y no pasa por este motor.
         */
        if (! $subject->acceptsCommercialOffers()) {
            return null;
        }

        $rules = [
            'ruleNeedsHuman',
            'ruleRecoverPendingPayment',
            'ruleRetryDeclinedPayment',
            'ruleActivateAfterPayment',
            'ruleOnboardNewMember',
            'ruleRescueAtRisk',
            'ruleRenewExpiring',
            'ruleReactivateExpired',
            'ruleUpgrade',
            'ruleCloseProspect',
            'ruleQualifyProspect',
            'ruleRequestReferral',
        ];

        foreach ($rules as $rule) {
            $decision = $this->{$rule}($subject);
            if ($decision !== null) {
                return $this->normalize($decision, $subject);
            }
        }

        return null;
    }

    /**
     * Evalúa y persiste como oportunidad. Idempotente por sujeto+objetivo: si
     * ya hay una abierta para el mismo objetivo, se actualiza en vez de abrir
     * una segunda, que acabaría en dos mensajes por lo mismo.
     */
    public function evaluate(CommercialSubject $subject, ?string $correlationId = null): ?CommercialOpportunity
    {
        $this->segments->refresh($subject);

        $decision = $this->decide($subject);

        if ($decision === null) {
            return null;
        }

        $leadId = $subject->lead?->id;
        $memberId = $subject->member?->id;

        return DB::transaction(function () use ($decision, $subject, $leadId, $memberId, $correlationId) {
            /*
             * Se busca la oportunidad de este objetivo abierta O DESPLAZADA.
             *
             * Incluir las desplazadas no es un detalle: sin eso, un objetivo que
             * va y viene —un socio mensual alterna «acompañar» a mitad de ciclo
             * con «renovar» los últimos diez días— crea una fila nueva en cada
             * vaivén. Una simulación de seis meses dejó 34 filas por persona.
             *
             * Y lo caro no es el ruido en el histórico, es que cada fila nueva
             * nace con `attempts` en cero. El techo de intentos —lo único que
             * impide insistirle a alguien más veces de las permitidas— se
             * reiniciaba solo, cada vez que el objetivo se alejaba y volvía.
             *
             * Nunca se revive una RECHAZADA ni una GANADA: un «no» del cliente y
             * una venta cerrada son hechos, no estados intermedios. Solo se
             * recupera lo que desplazó el propio motor.
             */
            $existing = CommercialOpportunity::query()
                ->where('goal', $decision['goal'])
                ->where(function ($q) {
                    $q->whereIn('status', CommercialVocabulary::OPEN_STATUSES)
                        ->orWhere(fn ($q2) => $q2
                            ->where('status', CommercialVocabulary::STATUS_CANCELLED)
                            ->where('outcome', 'superseded'));
                })
                ->when($leadId !== null, fn ($q) => $q->where('marketing_lead_id', $leadId))
                ->when($leadId === null && $memberId !== null, fn ($q) => $q->where('member_id', $memberId))
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $attributes = [
                'marketing_lead_id' => $leadId,
                'member_id' => $memberId,
                'goal' => $decision['goal'],
                'next_action' => $decision['action'],
                'next_offer' => $decision['offer'],
                'offer_plan_id' => $decision['offer_plan_id'],
                'alternative_plan_id' => $decision['alternative_plan_id'],
                'floor_plan_id' => $decision['floor_plan_id'],
                'priority' => CommercialVocabulary::priorityFor($decision['goal']),
                'confidence' => $decision['confidence'],
                'reason' => $decision['reason'],
                'exclusions' => $decision['exclusions'] ?: null,
                'evidence' => $subject->toEvidence(),
                'act_after' => $decision['act_after'],
                'estimated_value' => $decision['estimated_value'],
                'max_attempts' => $decision['max_attempts'],
                'correlation_id' => $correlationId,
            ];

            if ($existing !== null) {
                // Se refresca la decisión pero NO se reinician los intentos:
                // recalcular no puede convertirse en una vía para insistir más
                // veces de las permitidas.
                $existing->forceFill($attributes + [
                    // Vuelve a estar vigente si venía desplazada. Se limpia el
                    // desenlace para no dejar una fila abierta que dice a la vez
                    // que fue reemplazada.
                    'status' => CommercialVocabulary::STATUS_OPEN,
                    'outcome' => null,
                    'outcome_reason' => null,
                    'closed_at' => null,
                ])->save();

                $this->supersedeOthers($existing, $leadId, $memberId);

                return $existing;
            }

            $opportunity = CommercialOpportunity::create($attributes + [
                'status' => CommercialVocabulary::STATUS_OPEN,
                'created_by' => 'engine',
            ]);

            ChannelLog::info('commercial.opportunity.created', [
                'opportunity_id' => $opportunity->id,
                'goal' => $opportunity->goal,
                'action' => $opportunity->next_action,
                'priority' => $opportunity->priority,
                'estimated_value' => $opportunity->estimated_value,
            ]);

            $this->supersedeOthers($opportunity, $leadId, $memberId);

            return $opportunity;
        });
    }

    /**
     * Cierra los objetivos que este acaba de dejar obsoletos.
     *
     * `decide()` devuelve UN objetivo: el siguiente mejor. La deduplicación por
     * objetivo evitaba dos filas del mismo, pero nada cerraba las de los
     * objetivos anteriores, así que se acumulaban decisiones de momentos
     * distintos y contradictorias entre sí. Una simulación de tres semanas dejó
     * estas tres vivas a la vez sobre la misma persona:
     *
     *   collect_data         «todavía no sabemos qué busca»
     *   complete_onboarding  «es miembro nuevo y todavía no ha venido»
     *   increase_adherence   «miembro nuevo que ya está viniendo»
     *
     * Las dos últimas se contradicen literalmente. Con las tres abiertas, lo que
     * acabe recibiendo el cliente depende del orden en que alguien las lea, y en
     * el peor caso es una invitación a su valoración inicial a alguien que lleva
     * tres semanas entrenando tres veces por semana. Eso no es un objetivo mal
     * elegido: es haber perdido el contexto.
     *
     * Solo se tocan las que abrió el MOTOR. Una oportunidad creada por una
     * persona representa un compromiso humano —una llamada acordada, una
     * excepción autorizada— y no la puede cerrar un recálculo.
     */
    private function supersedeOthers(CommercialOpportunity $winner, ?int $leadId, ?int $memberId): void
    {
        $stale = CommercialOpportunity::query()
            ->whereIn('status', CommercialVocabulary::OPEN_STATUSES)
            ->where('id', '!=', $winner->id)
            ->where('created_by', 'engine')
            ->when($leadId !== null, fn ($q) => $q->where('marketing_lead_id', $leadId))
            ->when($leadId === null && $memberId !== null, fn ($q) => $q->where('member_id', $memberId))
            ->get();

        foreach ($stale as $opportunity) {
            $opportunity->forceFill([
                'status' => CommercialVocabulary::STATUS_CANCELLED,
                'outcome' => 'superseded',
                // Queda escrito QUÉ lo reemplazó: sin eso, el historial dice que
                // alguien canceló una oportunidad y no por qué.
                'outcome_reason' => 'reemplazada por el objetivo '.$winner->goal,
                'closed_at' => now(),
            ])->save();
        }

        if ($stale->isNotEmpty()) {
            ChannelLog::info('commercial.opportunity.superseded', [
                'winner_id' => $winner->id,
                'winner_goal' => $winner->goal,
                'superseded' => $stale->pluck('goal')->all(),
            ]);
        }
    }

    /** Completa la decisión con los valores por defecto que faltan. */
    private function normalize(array $decision, CommercialSubject $subject): array
    {
        return array_merge([
            'offer' => null,
            'confidence' => 0.7,
            'act_after' => null,
            'exclusions' => [],
            'offer_plan_id' => null,
            'alternative_plan_id' => null,
            'floor_plan_id' => null,
            'estimated_value' => null,
            'max_attempts' => 3,
        ], $decision);
    }

    // ── Reglas, en orden de prioridad ────────────────────────────────────────

    /** Si alguien pidió una persona, no hay nada comercial que decidir. */
    private function ruleNeedsHuman(CommercialSubject $s): ?array
    {
        if (! $s->needsHuman) {
            return null;
        }

        return [
            'goal' => CommercialVocabulary::GOAL_ESCALATE,
            'action' => CommercialVocabulary::ACTION_ESCALATE_HUMAN,
            'reason' => 'La conversación está marcada para revisión humana o la tomó una persona.',
            'confidence' => 1.0,
            'exclusions' => ['no_automated_offer' => 'Nada automático mientras un humano lleve el caso.'],
            'max_attempts' => 1,
        ];
    }

    /**
     * Un enlace de pago sin usar es la oportunidad más valiosa que existe: esa
     * persona ya dijo que sí y solo le faltó terminar. Pero hay que darle aire
     * unas horas antes de recordárselo.
     */
    private function ruleRecoverPendingPayment(CommercialSubject $s): ?array
    {
        if (! $s->hasPendingPaymentLink) {
            return null;
        }

        $createdAt = $s->pendingPaymentLinkAt;
        $hoursSince = $createdAt !== null ? $createdAt->diffInHours(now()) : 0;

        // Menos de seis horas: puede estar pagando ahora mismo. Recordárselo
        // sería impaciente y contraproducente.
        if ($hoursSince < 6) {
            return [
                'goal' => CommercialVocabulary::GOAL_RECOVER_PAYMENT_LINK,
                'action' => CommercialVocabulary::ACTION_WAIT,
                'reason' => 'Tiene un enlace de pago reciente; se le da tiempo antes de insistir.',
                'confidence' => 0.9,
                'act_after' => ($createdAt ?? now())->copy()->addHours(6),
                'exclusions' => ['too_soon' => 'Menos de 6 h desde que se generó el enlace.'],
            ];
        }

        $plan = $s->pendingPaymentPlanId !== null ? Plan::find($s->pendingPaymentPlanId) : null;

        return [
            'goal' => CommercialVocabulary::GOAL_RECOVER_PAYMENT_LINK,
            'action' => CommercialVocabulary::ACTION_RESEND_PAYMENT_LINK,
            'offer' => $plan?->name,
            'offer_plan_id' => $plan?->id,
            'reason' => sprintf(
                'Dejó un enlace de pago sin completar hace %d h. Ya mostró intención: solo falta cerrar.',
                $hoursSince,
            ),
            'confidence' => 0.9,
            'estimated_value' => $plan !== null ? (float) $plan->price : null,
            'max_attempts' => 2, // dos recordatorios; el tercero es acoso
        ];
    }

    private function ruleRetryDeclinedPayment(CommercialSubject $s): ?array
    {
        if (! $s->hasDeclinedPayment || $s->hasActiveMembership || $s->hasPendingPaymentLink) {
            return null;
        }

        return [
            'goal' => CommercialVocabulary::GOAL_COLLECT_PAYMENT,
            'action' => CommercialVocabulary::ACTION_RESEND_PAYMENT_LINK,
            'reason' => 'Un pago suyo fue rechazado. Suele ser el banco o el medio, no la decisión de compra.',
            'confidence' => 0.8,
            'exclusions' => ['no_discount' => 'Un rechazo del banco no justifica cambiar el precio.'],
            'max_attempts' => 2,
        ];
    }

    /** Pagó y todavía no tiene acceso: esto es urgente y no es una venta. */
    private function ruleActivateAfterPayment(CommercialSubject $s): ?array
    {
        if ($s->approvedPaymentsCount === 0 || $s->hasActiveMembership) {
            return null;
        }

        /*
         * Que la membresía haya CADUCADO no es una activación fallida.
         *
         * Esta regla existe para un caso concreto: el pago entró y la membresía
         * no se activó, así que hay que resolverlo antes de nada. Pero la
         * condición «tiene pago aprobado y no tiene membresía activa» también la
         * cumple, para siempre, cualquiera cuya membresía venció de forma normal.
         *
         * El resultado era que a un exsocio se le decía «tienes un pago
         * aprobado pero no figuras con membresía activa, vamos a resolverlo»
         * —falso: se resolvió hace meses y luego se le acabó— y, peor, que la
         * regla de reactivación, que va después, no llegaba a mirarlo nunca.
         *
         * Si sabemos CUÁNDO caducó, es que estuvo activa: el pago sí activó.
         */
        if ($s->daysSinceExpiry !== null && $s->daysSinceExpiry > 0) {
            return null; // caducó: es reactivación, no una activación pendiente
        }

        return [
            'goal' => CommercialVocabulary::GOAL_ACTIVATE_MEMBERSHIP,
            'action' => CommercialVocabulary::ACTION_CONFIRM_ACTIVATION,
            'reason' => 'Tiene un pago aprobado pero no figura con membresía activa. Hay que resolverlo antes de nada.',
            'confidence' => 0.95,
            'exclusions' => ['no_selling' => 'No se le ofrece nada hasta que tenga lo que ya pagó.'],
            'max_attempts' => 1,
        ];
    }

    /**
     * Primeros treinta días. Aquí se decide la permanencia, y por eso NO se
     * vende: se acompaña. Un upgrade a alguien que aún no ha venido nunca es la
     * forma más rápida de que pida la baja.
     */
    private function ruleOnboardNewMember(CommercialSubject $s): ?array
    {
        if (! $s->hasActiveMembership || $s->daysAsMember === null || $s->daysAsMember > 30) {
            return null;
        }

        /*
         * Si se le está venciendo, la continuidad manda sobre el acompañamiento.
         *
         * Las dos ventanas se solapan SIEMPRE en el plan mensual: con 30 días de
         * vigencia, a partir del día 20 la persona es a la vez «miembro nuevo»
         * (≤30 días) y «se le vence» (≤10 días). Como esta regla va antes que la
         * de renovación, un socio mensual no llegaba nunca a tener el objetivo de
         * renovar en su primer ciclo: justo en la ventana que decide si sigue o
         * no, el motor estaba ocupado consolidándole el hábito.
         *
         * Y no es intercambiable: el acompañamiento se puede dar mañana, el
         * vencimiento no. Sin renovación no hay hábito que consolidar.
         */
        if ($s->daysToExpiry !== null
            && $s->daysToExpiry >= 0
            && $s->daysToExpiry <= self::RENEWAL_WINDOW_DAYS) {
            return null;
        }

        /*
         * «No ha venido nunca» y «no ha venido últimamente» no son lo mismo.
         *
         * `attendancesLast30Days` mide una ventana, no una historia. Para un
         * socio que renueva, la fecha de inicio se mueve con la renovación, así
         * que alguien que vino dos veces hace dos meses y desapareció vuelve a
         * contar como «miembro nuevo con cero asistencias» y recibe la
         * invitación a su valoración inicial. Es absurdo de leer —lleva dos
         * meses apuntado— y además tapa el problema real: esa persona no es
         * nueva, está a punto de irse, y la regla de rescate que existe para
         * exactamente eso va después de esta y nunca llega a mirarla.
         */
        if ($s->attendancesLast30Days === 0 && $s->lastAttendanceAt !== null) {
            return null; // que lo mire ruleRescueAtRisk: es retención, no alta
        }

        if ($s->attendancesLast30Days === 0) {
            return [
                'goal' => CommercialVocabulary::GOAL_COMPLETE_ONBOARDING,
                'action' => CommercialVocabulary::ACTION_OFFER_APPOINTMENT,
                'reason' => 'Es miembro nuevo y todavía no ha venido. Una valoración inicial es lo que más sube la permanencia.',
                'confidence' => 0.85,
                'exclusions' => [
                    'no_upsell' => 'No se ofrece plan más largo: aún no ha usado el que tiene.',
                ],
                'max_attempts' => 2,
            ];
        }

        if (! $s->hasAppAccount) {
            return [
                'goal' => CommercialVocabulary::GOAL_LINK_APP,
                'action' => CommercialVocabulary::ACTION_GUIDE_APP_LINK,
                'reason' => 'Ya viene al gimnasio pero no tiene la app vinculada; con ella se sigue mejor el progreso.',
                'confidence' => 0.7,
                'max_attempts' => 2,
            ];
        }

        return [
            'goal' => CommercialVocabulary::GOAL_INCREASE_ADHERENCE,
            'action' => CommercialVocabulary::ACTION_CHECK_IN,
            'reason' => 'Miembro nuevo que ya está viniendo. Un acompañamiento breve consolida el hábito.',
            'confidence' => 0.6,
            'act_after' => now()->addDays(3),
            'exclusions' => ['no_upsell_yet' => 'Antes de ofrecer nada, confirmar que el plan actual le funciona.'],
            'max_attempts' => 2,
        ];
    }

    /** Paga y no viene. Desde caja parece un cliente sano hasta que no renueva. */
    private function ruleRescueAtRisk(CommercialSubject $s): ?array
    {
        if (! $s->hasActiveMembership) {
            return null;
        }

        /*
         * Dentro de la ventana de renovación manda la continuidad.
         *
         * Es el mismo límite que aparta al acompañamiento, y por el mismo
         * motivo: a dos días del vencimiento, rescatar la adherencia de alguien
         * que está a punto de quedarse sin membresía es empezar por el final. Si
         * no renueva, no hay adherencia que rescatar.
         *
         * Y no se pierde nada por el camino: `ruleRenewExpiring` ya distingue al
         * socio comprometido del que no lo está —al segundo le ofrece el plan
         * mínimo y sin discurso de mejora—, así que la persona en riesgo sigue
         * recibiendo el trato que le corresponde, dentro del mensaje que de
         * verdad importa esta semana.
         */
        if ($s->daysToExpiry !== null
            && $s->daysToExpiry >= 0
            && $s->daysToExpiry <= self::RENEWAL_WINDOW_DAYS) {
            return null;
        }

        $noShow = $s->daysSinceLastAttendance !== null && $s->daysSinceLastAttendance >= 14;
        // «Nunca vino» exige AMBAS cosas: que no haya fecha de última visita y
        // que no haya visitas recientes. Deducirlo solo de la fecha ausente
        // marcaba como desaparecido a quien vino diecisiete veces este mes.
        $neverCame = $s->daysSinceLastAttendance === null
            && $s->attendancesLast30Days === 0
            && $s->daysAsMember !== null && $s->daysAsMember >= 14;

        if (! $noShow && ! $neverCame) {
            return null;
        }

        return [
            'goal' => CommercialVocabulary::GOAL_INCREASE_ADHERENCE,
            'action' => CommercialVocabulary::ACTION_CHECK_IN,
            'reason' => $neverCame
                ? 'Lleva más de dos semanas de membresía sin haber venido ni una vez. Hay una fricción que resolver.'
                : sprintf('Paga pero lleva %d días sin venir. Es el patrón previo a no renovar.', (int) $s->daysSinceLastAttendance),
            'confidence' => 0.85,
            'exclusions' => [
                'no_upsell' => 'No se ofrece nada más: primero hay que entender por qué no viene.',
                'no_renewal_push' => 'Empujar la renovación de alguien que no usa el servicio genera reclamos.',
            ],
            'max_attempts' => 2,
        ];
    }

    /**
     * Renovación. Aquí sí entra el upgrade, pero **solo si viene de verdad**, y
     * siempre con alternativa y mínimo aceptable: ir a por el anual y volver
     * sin nada es el error clásico.
     */
    private function ruleRenewExpiring(CommercialSubject $s): ?array
    {
        if (! $s->hasActiveMembership || $s->daysToExpiry === null) {
            return null;
        }

        if ($s->daysToExpiry > self::RENEWAL_WINDOW_DAYS || $s->daysToExpiry < 0) {
            return null;
        }

        $rate = $s->weeklyAttendanceRate();
        $engaged = $rate >= 2.5;

        $ladder = $this->planLadder($s->currentPlanDurationDays);

        return [
            'goal' => CommercialVocabulary::GOAL_RENEW,
            'action' => $engaged
                ? CommercialVocabulary::ACTION_OFFER_UPGRADE
                : CommercialVocabulary::ACTION_OFFER_RENEWAL,
            'offer' => $engaged ? ($ladder['primary']?->name) : ($ladder['floor']?->name),
            'offer_plan_id' => $engaged ? $ladder['primary']?->id : $ladder['floor']?->id,
            'alternative_plan_id' => $engaged ? $ladder['alternative']?->id : null,
            'floor_plan_id' => $ladder['floor']?->id,
            'reason' => $engaged
                ? sprintf(
                    'Le vence en %d días y viene %.1f veces por semana. Con ese uso, un plan más largo le sale mejor por mes.',
                    $s->daysToExpiry, $rate,
                )
                : sprintf(
                    'Le vence en %d días. Su uso es bajo (%.1f/semana), así que lo sensato es asegurar la renovación, no alargar el compromiso.',
                    $s->daysToExpiry, $rate,
                ),
            'confidence' => $engaged ? 0.85 : 0.7,
            'estimated_value' => $engaged
                ? (float) ($ladder['primary']?->price ?? 0)
                : (float) ($ladder['floor']?->price ?? 0),
            'exclusions' => $engaged ? [] : [
                'no_upgrade' => sprintf('Uso semanal %.1f: por debajo del umbral de 2,5 para proponer un plan más largo.', $rate),
            ],
            'max_attempts' => 3,
        ];
    }

    private function ruleReactivateExpired(CommercialSubject $s): ?array
    {
        if ($s->hasActiveMembership || $s->daysSinceExpiry === null || $s->daysSinceExpiry <= 0) {
            return null;
        }

        // Pasado el año, un mensaje de reactivación es spam, no comercial.
        if ($s->daysSinceExpiry > 365) {
            return null;
        }

        // Los tres primeros días puede estar renovando por su cuenta.
        if ($s->daysSinceExpiry < 3) {
            return [
                'goal' => CommercialVocabulary::GOAL_REACTIVATE,
                'action' => CommercialVocabulary::ACTION_WAIT,
                'reason' => 'Acaba de vencer; se le da margen por si renueva por su cuenta.',
                'confidence' => 0.8,
                'act_after' => now()->addDays(3),
                'exclusions' => ['too_soon' => 'Menos de 3 días desde el vencimiento.'],
            ];
        }

        return [
            'goal' => CommercialVocabulary::GOAL_REACTIVATE,
            'action' => CommercialVocabulary::ACTION_CHECK_IN,
            'reason' => sprintf(
                'Su membresía venció hace %d días. Antes de ofrecer nada, entender si dejó de venir por algo concreto.',
                $s->daysSinceExpiry,
            ),
            'confidence' => 0.7,
            'exclusions' => [
                'no_discount' => 'No se ofrecen descuentos de reactivación sin autorización.',
            ],
            // Uno cada vez, y como mucho dos. Insistir a quien ya se fue quema
            // la posibilidad de que vuelva por su cuenta.
            'max_attempts' => 2,
        ];
    }

    /** Upgrade fuera de la ventana de renovación: solo con uso demostrado. */
    private function ruleUpgrade(CommercialSubject $s): ?array
    {
        if (! $s->hasActiveMembership) {
            return null;
        }

        if ($s->weeklyAttendanceRate() < 2.5 || $s->daysAsMember === null || $s->daysAsMember < 21) {
            return null;
        }

        // Quién puede subir lo decide el CATÁLOGO, no un número mágico: si no
        // existe ningún plan más largo que el suyo, no hay nada que ofrecer.
        // Con un umbral fijo de días, quien tuviera trimestral nunca habría
        // recibido la propuesta de anual aunque entrenara a diario.
        if ($this->planLadder($s->currentPlanDurationDays)['primary'] === null) {
            return null;
        }

        // Si le queda más de un mes, no es el momento: se guarda para la
        // ventana de renovación, donde la conversación es natural.
        if ($s->daysToExpiry !== null && $s->daysToExpiry > 30) {
            return [
                'goal' => CommercialVocabulary::GOAL_UPGRADE,
                'action' => CommercialVocabulary::ACTION_WAIT,
                'reason' => 'Buen candidato a plan más largo, pero le queda mucho del actual.',
                'confidence' => 0.7,
                'act_after' => now()->addDays(max(1, $s->daysToExpiry - 20)),
                'exclusions' => ['bad_timing' => 'Faltan más de 30 días para que venza.'],
            ];
        }

        $ladder = $this->planLadder($s->currentPlanDurationDays);

        return [
            'goal' => CommercialVocabulary::GOAL_UPGRADE,
            'action' => CommercialVocabulary::ACTION_OFFER_UPGRADE,
            'offer' => $ladder['primary']?->name,
            'offer_plan_id' => $ladder['primary']?->id,
            'alternative_plan_id' => $ladder['alternative']?->id,
            'floor_plan_id' => $ladder['floor']?->id,
            'reason' => sprintf(
                'Entrena %.1f veces por semana desde hace %d días. Un plan más largo le baja el costo mensual.',
                $s->weeklyAttendanceRate(), $s->daysAsMember,
            ),
            'confidence' => 0.8,
            'estimated_value' => (float) ($ladder['primary']?->price ?? 0),
            'max_attempts' => 2,
        ];
    }

    /** Prospecto que ya dijo qué quiere: toca recomendar y cerrar. */
    private function ruleCloseProspect(CommercialSubject $s): ?array
    {
        if ($s->hasActiveMembership || $s->lead === null || empty($s->objective)) {
            return null;
        }

        $ladder = $this->planLadder(null);

        /*
         * Por dónde llegó la persona es una SEÑAL, no la regla.
         *
         * Si vino de una pauta que promocionaba un plan concreto y ese plan
         * sigue vigente, se arranca por ahí: es lo que vino a mirar y empezar
         * por otra cosa obliga a explicar de más. Pero es solo el punto de
         * partida —el escalón alternativo y el suelo no cambian—, y si el plan
         * anunciado ya no existe o cambió de precio, la señal se descarta
         * entera en vez de arrastrar una oferta que no se puede sostener.
         *
         * Que esto solo ocurra aquí no es casualidad: las reglas de renovación,
         * rescate y mejora se evalúan ANTES, así que quien ya es cliente nunca
         * llega a esta línea. El anuncio de hace ocho meses no puede pesar en
         * lo que se le ofrece hoy a alguien que ya entrena.
         */
        $signals = $s->acquisitionSignals();
        $advertised = $this->advertisedPlanFor($signals);

        $offer = $advertised ?? $ladder['floor'];

        return [
            'goal' => CommercialVocabulary::GOAL_CLOSE_PLAN,
            'action' => CommercialVocabulary::ACTION_RECOMMEND_PLAN,
            'offer' => $offer?->name,
            'offer_plan_id' => $offer?->id,
            'alternative_plan_id' => $ladder['alternative']?->id,
            'floor_plan_id' => $ladder['floor']?->id,
            'reason' => $advertised !== null
                ? sprintf(
                    'Ya sabemos qué busca (%s) y llegó por una pauta del plan %s, que sigue vigente. Se arranca por ahí.',
                    $s->objective,
                    $advertised->name,
                )
                : sprintf('Ya sabemos qué busca (%s). Toca recomendar el plan que encaje y proponer un siguiente paso.', $s->objective),
            'confidence' => 0.75,
            'estimated_value' => (float) ($offer?->price ?? 0),
            'exclusions' => $s->priceObjections > 0
                ? ['price_sensitive' => 'Ya objetó el precio: empezar por la opción de entrada, no por la más cara.']
                : [],
            'max_attempts' => 3,
        ];
    }

    /**
     * El plan que anunciaba la pauta, si sigue siendo ofrecible.
     *
     * `advertised_offer_usable` es la condición que importa: lo pone el
     * contraste con el catálogo vigente, y en false significa que el plan
     * desapareció o cambió de precio. En ese caso NO se devuelve nada, porque
     * abrir por una oferta que no se puede cumplir es peor que abrir genérico.
     *
     * @param  array<string,mixed>  $signals
     */
    private function advertisedPlanFor(array $signals): ?Plan
    {
        $planId = $signals['advertised_plan_id'] ?? null;

        if ($planId === null || ($signals['advertised_offer_usable'] ?? false) !== true) {
            return null;
        }

        // Se busca en el catalogo ACTIVO. Si el plan se desactivo entre que se
        // calculo el contraste y esto, aqui no aparece y no se ofrece.
        $this->planLadder(null);

        return $this->planCache?->firstWhere('id', (int) $planId);
    }

    /** No sabemos qué quiere: preguntar antes de ofrecer nada. */
    private function ruleQualifyProspect(CommercialSubject $s): ?array
    {
        if ($s->hasActiveMembership || $s->lead === null) {
            return null;
        }

        return [
            'goal' => CommercialVocabulary::GOAL_COLLECT_DATA,
            'action' => CommercialVocabulary::ACTION_ASK_DISCOVERY,
            'reason' => 'Todavía no sabemos qué busca. Recomendar sin eso es adivinar.',
            'confidence' => 0.7,
            'exclusions' => ['no_offer' => 'No se ofrece plan sin conocer objetivo y disponibilidad.'],
            'max_attempts' => 2,
        ];
    }

    /** Referidos: solo a quien está contento y lleva tiempo. */
    private function ruleRequestReferral(CommercialSubject $s): ?array
    {
        if (! $s->hasActiveMembership || $s->weeklyAttendanceRate() < 3.0) {
            return null;
        }

        if ($s->daysAsMember === null || $s->daysAsMember < 45) {
            return null;
        }

        return [
            'goal' => CommercialVocabulary::GOAL_REQUEST_REFERRAL,
            'action' => CommercialVocabulary::ACTION_ASK_REFERRAL,
            'reason' => sprintf(
                'Lleva %d días entrenando %.1f veces por semana. Es quien mejor puede recomendar el gimnasio.',
                $s->daysAsMember, $s->weeklyAttendanceRate(),
            ),
            'confidence' => 0.6,
            'max_attempts' => 1,
        ];
    }

    /**
     * Escalera de planes: principal, alternativa y mínimo aceptable.
     *
     * Se lee del catálogo REAL. Si no hay planes cargados devuelve nulos y las
     * reglas siguen funcionando sin oferta concreta: es preferible una
     * recomendación sin plan que un plan inventado.
     *
     * @return array{primary:?Plan, alternative:?Plan, floor:?Plan}
     */
    private function planLadder(?int $currentDurationDays): array
    {
        // Caché de INSTANCIA, no estática. Una estática sobrevive entre
        // ejecuciones dentro del mismo worker de cola: si alguien desactiva un
        // plan desde el CRM, el agente seguiría ofreciéndolo hasta que el
        // proceso se reiniciara. Ofrecer un plan que ya no existe es
        // exactamente lo que el agente tiene prohibido.
        $cache = $this->planCache ??= Plan::query()
            ->where('active', true)
            ->orderBy('duration_days')
            ->get(['id', 'name', 'price', 'duration_days']);

        if ($cache->isEmpty()) {
            return ['primary' => null, 'alternative' => null, 'floor' => null];
        }

        // El mínimo es el plan activo más corto: la renovación que siempre se
        // debe poder cerrar.
        $floor = $currentDurationDays !== null
            ? ($cache->firstWhere('duration_days', $currentDurationDays) ?? $cache->first())
            : $cache->first();

        // Los candidatos a subir son los más largos que el actual.
        $longer = $cache->filter(fn ($p) => $p->duration_days > ($floor->duration_days ?? 0))->values();

        return [
            'primary' => $longer->last() ?: null,      // el más largo disponible
            'alternative' => $longer->first() ?: null, // el escalón inmediato
            'floor' => $floor,
        ];
    }
}
