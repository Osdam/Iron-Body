<?php

namespace App\Services\Commercial;

use App\Models\CommercialOpportunity;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Services\Observability\ChannelLog;
use App\Services\Commercial\CommercialVocabulary as V;

/**
 * Cierra lo que ya no tiene sentido perseguir.
 *
 * Sin esta clase el motor acumula objetivos muertos: alguien paga y la
 * oportunidad «cobrar» sigue abierta, así que el sistema le insiste por un pago
 * que ya hizo. Es la forma más rápida de perder la confianza de un cliente y,
 * peor, de que el equipo deje de creerse el panel.
 *
 * La regla es una sola: una oportunidad se cierra cuando los HECHOS actuales ya
 * satisfacen su objetivo, cuando venció el plazo, o cuando se agotaron los
 * intentos. Se cierra mirando el mundo, no mirando si mandamos el mensaje.
 *
 * El cierre en `won` es además la bisagra del principio que sostiene todo el
 * módulo —ninguna venta termina la relación comercial—: cerrar aquí es lo que
 * permite que {@see NextBestActionEngine} calcule inmediatamente el objetivo
 * siguiente en lugar de dar el caso por terminado.
 */
class OpportunityReconciler
{
    /**
     * Revisa las oportunidades abiertas del sujeto y cierra las obsoletas.
     *
     * @return array<int,array{id:int,goal:string,outcome:string,reason:string}>
     */
    public function reconcile(CommercialSubject $subject): array
    {
        $closed = [];

        foreach ($this->openOpportunitiesFor($subject) as $opportunity) {
            $resolution = $this->resolve($opportunity, $subject);

            if ($resolution === null) {
                continue;
            }

            [$outcome, $reason, $realized] = $resolution;

            $opportunity->close($outcome, $reason, $realized);

            ChannelLog::info('commercial.opportunity.closed', [
                'opportunity_id' => $opportunity->id,
                'goal' => $opportunity->goal,
                'outcome' => $outcome,
                'reason' => $reason,
                'realized_value' => $realized,
            ]);

            $closed[] = [
                'id' => $opportunity->id,
                'goal' => $opportunity->goal,
                'outcome' => $outcome,
                'reason' => $reason,
            ];
        }

        return $closed;
    }

    /**
     * ¿Debe cerrarse esta oportunidad, y por qué?
     *
     * @return array{0:string,1:string,2:?float}|null
     */
    private function resolve(CommercialOpportunity $opportunity, CommercialSubject $subject): ?array
    {
        // 1. Objetivo cumplido por los hechos. Es el cierre que importa: el que
        //    reconoce una venta y libera el paso al objetivo siguiente.
        if ($achieved = $this->achievement($opportunity, $subject)) {
            return $achieved;
        }

        // 2. Plazo vencido. Una oferta de renovación para una membresía que
        //    caducó hace un mes ya no es una renovación.
        if ($opportunity->expires_at !== null && $opportunity->expires_at->isPast()) {
            return [V::STATUS_EXPIRED, 'deadline_passed', null];
        }

        // 3. Intentos agotados. Insistir más allá de esto es acoso, y el
        //    silencio sostenido es una respuesta.
        if ($opportunity->attempts >= $opportunity->max_attempts) {
            return [V::STATUS_LOST, 'max_attempts_reached', null];
        }

        // 4. Condiciones que bloquean cualquier objetivo. No se marcan perdidas
        //    —no fracasaron— sino bloqueadas: si la persona vuelve a hablar, el
        //    motor puede reabrir el caso sin haber registrado una derrota falsa.
        if (! $subject->isContactable()) {
            return [V::STATUS_BLOCKED, 'do_not_contact', null];
        }

        if ($subject->needsHuman && $opportunity->goal !== V::GOAL_ESCALATE) {
            return [V::STATUS_BLOCKED, 'human_in_control', null];
        }

        return null;
    }

    /**
     * Objetivos que los hechos ya dan por conseguidos.
     *
     * @return array{0:string,1:string,2:?float}|null
     */
    private function achievement(CommercialOpportunity $opportunity, CommercialSubject $subject): ?array
    {
        $value = (float) ($opportunity->estimated_value ?? 0.0);

        return match ($opportunity->goal) {
            // Cobrar: se cumple cuando hay membresía vigente. Se usa el hecho
            // «está activo» y no «llegó un pago» porque un pago aprobado que no
            // llegó a activar nada no es un objetivo cumplido.
            V::GOAL_COLLECT_PAYMENT,
            V::GOAL_RECOVER_PAYMENT_LINK,
            V::GOAL_ACTIVATE_MEMBERSHIP => $subject->hasActiveMembership
                ? [V::STATUS_WON, 'membership_active', $value]
                : null,

            // Renovar y reactivar: ambos terminan en membresía vigente, pero
            // solo cuentan si el pago es posterior a la apertura del objetivo.
            V::GOAL_RENEW,
            V::GOAL_REACTIVATE => $subject->hasActiveMembership && $this->renewedAfter($opportunity, $subject)
                ? [V::STATUS_WON, 'membership_renewed', $value]
                : null,

            // Vender un plan: el prospecto se convirtió en miembro.
            V::GOAL_CLOSE_PLAN => $subject->member !== null && $subject->hasActiveMembership
                ? [V::STATUS_WON, 'became_member', $value]
                : null,

            // Mejora de plan: el plan actual ya no es el que se quería mejorar.
            V::GOAL_UPGRADE => $this->planChanged($opportunity, $subject)
                ? [V::STATUS_WON, 'plan_upgraded', $value]
                : null,

            V::GOAL_LINK_APP => $subject->hasAppAccount
                ? [V::STATUS_WON, 'app_linked', null]
                : null,

            // Adherencia: volvió a entrenar con regularidad.
            V::GOAL_INCREASE_ADHERENCE => $subject->weeklyAttendanceRate()
                >= (float) config('commercial.thresholds.engaged_weekly_rate', 2.5)
                    ? [V::STATUS_WON, 'adherence_recovered', null]
                    : null,

            // Escalado: se cierra solo cuando la persona ya no está al mando,
            // es decir, cuando el asesor terminó y devolvió la conversación.
            V::GOAL_ESCALATE => $subject->needsHuman
                ? null
                : [V::STATUS_WON, 'human_handled', null],

            default => null,
        };
    }

    /**
     * ¿La membresía se renovó DESPUÉS de abrirse la oportunidad?
     *
     * Sin esta comprobación, una oportunidad de renovación creada para alguien
     * cuya membresía todavía está vigente se cerraría como ganada en el mismo
     * instante en que se abre: la membresía activa que se quiere renovar es
     * justamente la que la vuelve verdadera.
     */
    private function renewedAfter(CommercialOpportunity $opportunity, CommercialSubject $subject): bool
    {
        if ($subject->membershipStartedAt === null) {
            return false;
        }

        return $subject->membershipStartedAt->greaterThan($opportunity->created_at);
    }

    /** El plan vigente ya no es el que había cuando se propuso la mejora. */
    private function planChanged(CommercialOpportunity $opportunity, CommercialSubject $subject): bool
    {
        if ($subject->currentPlanId === null) {
            return false;
        }

        // Cumplida si ahora tiene exactamente el plan que se le ofreció.
        return $opportunity->offer_plan_id !== null
            && $subject->currentPlanId === (int) $opportunity->offer_plan_id;
    }

    /** @return \Illuminate\Support\Collection<int,CommercialOpportunity> */
    private function openOpportunitiesFor(CommercialSubject $subject)
    {
        $leadId = $subject->lead?->id;
        $memberId = $subject->member?->id;

        return CommercialOpportunity::query()
            ->whereIn('status', V::OPEN_STATUSES)
            ->where(function ($q) use ($leadId, $memberId): void {
                if ($leadId !== null) {
                    $q->orWhere('marketing_lead_id', $leadId);
                }
                if ($memberId !== null) {
                    $q->orWhere('member_id', $memberId);
                }
            })
            ->when($leadId === null && $memberId === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->orderByDesc('priority')
            ->get();
    }
}
