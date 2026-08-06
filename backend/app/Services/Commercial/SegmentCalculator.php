<?php

namespace App\Services\Commercial;

use App\Models\CommercialSegment;
use Illuminate\Support\Carbon;

/**
 * En qué situación comercial está una persona, calculado con evidencia.
 *
 * Un segmento no es una etiqueta pegada a mano: es una conclusión con fecha,
 * confianza y los datos que la sostienen. Por eso cada uno caduca. Un «alta
 * intención» de hace tres semanas ya no lo es, y tratarlo como si lo fuera es
 * la forma más rápida de que el agente diga algo que no encaja con la realidad
 * del cliente.
 *
 * Una persona puede estar en varios segmentos a la vez —«próximo a vencer» y
 * «alta adherencia» es justo el mejor momento para hablar de un plan más
 * largo—, así que esto devuelve un conjunto, no una categoría única.
 */
class SegmentCalculator
{
    /** Cuánto vale cada conclusión antes de tener que recalcularla. */
    private const TTL_HOURS = [
        CommercialVocabulary::SEG_HIGH_INTENT => 48,      // la intención se enfría rápido
        CommercialVocabulary::SEG_PAYMENT_LINK_PENDING => 72,
        CommercialVocabulary::SEG_UNDECIDED => 168,
        CommercialVocabulary::SEG_PRICE_SENSITIVE => 720, // una objeción de precio pesa meses
        CommercialVocabulary::SEG_NEW_MEMBER => 168,
        CommercialVocabulary::SEG_EXPIRING_SOON => 24,    // depende de una fecha: se revisa a diario
        CommercialVocabulary::SEG_AT_RISK => 72,
    ];

    private const DEFAULT_TTL_HOURS = 168;

    /**
     * Calcula todos los segmentos de un sujeto.
     *
     * @return array<int, array{segment:string, confidence:float, evidence:array}>
     */
    public function calculate(CommercialSubject $subject): array
    {
        $segments = [];

        foreach ([
            'needsHuman', 'paymentLinkPending', 'paymentDeclined',
            'newMember', 'adherence', 'expiring', 'expired', 'inactive', 'atRisk',
            'upgradeOpportunity', 'referralCandidate',
            'newProspect', 'qualifiedProspect', 'highIntent', 'priceSensitive', 'undecided',
        ] as $rule) {
            $result = $this->{$rule}($subject);
            if ($result !== null) {
                $segments = array_merge($segments, is_array($result[0] ?? null) ? $result : [$result]);
            }
        }

        return $segments;
    }

    /**
     * Calcula y persiste, reemplazando lo anterior del mismo sujeto.
     *
     * @return array<int,string> Los segmentos vigentes tras el cálculo.
     */
    public function refresh(CommercialSubject $subject): array
    {
        $segments = $this->calculate($subject);
        $leadId = $subject->lead?->id;
        $memberId = $subject->member?->id;

        if ($leadId === null && $memberId === null) {
            return [];
        }

        $names = array_column($segments, 'segment');

        // Lo que ya no aplica se borra: un segmento obsoleto que sobrevive es
        // peor que ninguno, porque el motor decide sobre él sin saberlo.
        CommercialSegment::query()
            ->when($leadId !== null, fn ($q) => $q->where('marketing_lead_id', $leadId))
            ->when($leadId === null, fn ($q) => $q->where('member_id', $memberId))
            ->whereNotIn('segment', $names ?: ['__ninguno__'])
            ->delete();

        foreach ($segments as $segment) {
            CommercialSegment::updateOrCreate(
                array_filter([
                    'marketing_lead_id' => $leadId,
                    'member_id' => $leadId === null ? $memberId : null,
                    'segment' => $segment['segment'],
                ], fn ($v) => $v !== null),
                [
                    'member_id' => $memberId,
                    'confidence' => $segment['confidence'],
                    'evidence' => $segment['evidence'],
                    'computed_at' => now(),
                    'expires_at' => $this->expiryFor($segment['segment']),
                ],
            );
        }

        return $names;
    }

    private function expiryFor(string $segment): Carbon
    {
        return now()->addHours(self::TTL_HOURS[$segment] ?? self::DEFAULT_TTL_HOURS);
    }

    /** @return array{segment:string, confidence:float, evidence:array} */
    private function seg(string $segment, float $confidence, array $evidence): array
    {
        return ['segment' => $segment, 'confidence' => $confidence, 'evidence' => $evidence];
    }

    // ── Reglas ────────────────────────────────────────────────────────────────

    private function needsHuman(CommercialSubject $s): ?array
    {
        return $s->needsHuman
            ? $this->seg(CommercialVocabulary::SEG_NEEDS_HUMAN, 1.0, ['reason' => 'staff_review_or_takeover'])
            : null;
    }

    private function paymentLinkPending(CommercialSubject $s): ?array
    {
        if (! $s->hasPendingPaymentLink) {
            return null;
        }

        return $this->seg(CommercialVocabulary::SEG_PAYMENT_LINK_PENDING, 1.0, [
            'created_at' => $s->pendingPaymentLinkAt?->toIso8601String(),
            'plan_id' => $s->pendingPaymentPlanId,
        ]);
    }

    private function paymentDeclined(CommercialSubject $s): ?array
    {
        return $s->hasDeclinedPayment
            ? $this->seg(CommercialVocabulary::SEG_PAYMENT_DECLINED, 1.0, ['has_declined' => true])
            : null;
    }

    /** Los primeros treinta días deciden si alguien se queda o no. */
    private function newMember(CommercialSubject $s): ?array
    {
        if (! $s->hasActiveMembership || $s->daysAsMember === null || $s->daysAsMember > 30) {
            return null;
        }

        return $this->seg(CommercialVocabulary::SEG_NEW_MEMBER, 1.0, [
            'days_as_member' => $s->daysAsMember,
            'attendances' => $s->attendancesLast30Days,
        ]);
    }

    /**
     * Adherencia. Tres o más visitas por semana es un cliente que está
     * obteniendo valor; menos de una, alguien que ya casi se ha ido.
     */
    private function adherence(CommercialSubject $s): ?array
    {
        if (! $s->hasActiveMembership) {
            return null;
        }

        $rate = $s->weeklyAttendanceRate();
        $evidence = ['weekly_rate' => $rate, 'last_30_days' => $s->attendancesLast30Days];

        if ($rate >= 3.0) {
            return $this->seg(CommercialVocabulary::SEG_HIGH_ADHERENCE, 0.9, $evidence);
        }

        // Un miembro recién llegado todavía no tiene historial suficiente para
        // llamarlo poco adherente: eso sería castigarle por ser nuevo.
        if ($rate < 1.0 && ($s->daysAsMember === null || $s->daysAsMember > 14)) {
            return $this->seg(CommercialVocabulary::SEG_LOW_ADHERENCE, 0.85, $evidence);
        }

        return null;
    }

    private function expiring(CommercialSubject $s): ?array
    {
        if (! $s->hasActiveMembership || $s->daysToExpiry === null || $s->daysToExpiry > 10 || $s->daysToExpiry < 0) {
            return null;
        }

        return $this->seg(CommercialVocabulary::SEG_EXPIRING_SOON, 1.0, [
            'days_to_expiry' => $s->daysToExpiry,
            'ends_at' => $s->membershipEndsAt?->toDateString(),
        ]);
    }

    private function expired(CommercialSubject $s): ?array
    {
        if ($s->hasActiveMembership || $s->daysSinceExpiry === null || $s->daysSinceExpiry <= 0) {
            return null;
        }

        return $this->seg(CommercialVocabulary::SEG_EXPIRED, 1.0, [
            'days_since_expiry' => $s->daysSinceExpiry,
        ]);
    }

    /** Nunca vino, o lleva un mes sin aparecer. */
    private function inactive(CommercialSubject $s): ?array
    {
        if ($s->daysSinceLastAttendance === null || $s->daysSinceLastAttendance < 30) {
            return null;
        }

        return $this->seg(CommercialVocabulary::SEG_INACTIVE, 0.95, [
            'days_since_last_attendance' => $s->daysSinceLastAttendance,
        ]);
    }

    /**
     * Riesgo de abandono: paga pero no viene. Es el que peor se detecta a ojo
     * porque desde caja parece un cliente sano hasta el día que no renueva.
     */
    private function atRisk(CommercialSubject $s): ?array
    {
        if (! $s->hasActiveMembership) {
            return null;
        }

        $noShow = $s->daysSinceLastAttendance !== null && $s->daysSinceLastAttendance >= 14;
        // Mismo criterio que el motor: sin fecha Y sin visitas recientes. Con
        // solo lo primero, un miembro activísimo entraría en riesgo de abandono.
        $neverCame = $s->daysSinceLastAttendance === null
            && $s->attendancesLast30Days === 0
            && $s->daysAsMember !== null && $s->daysAsMember >= 14;

        if (! $noShow && ! $neverCame) {
            return null;
        }

        return $this->seg(CommercialVocabulary::SEG_AT_RISK, $neverCame ? 0.95 : 0.85, [
            'days_since_last_attendance' => $s->daysSinceLastAttendance,
            'never_attended' => $neverCame,
            'days_as_member' => $s->daysAsMember,
        ]);
    }

    /**
     * Candidato a plan más largo: viene de verdad y su plan actual es corto.
     * Sin asistencia demostrada, un upgrade es solo una factura más grande.
     */
    private function upgradeOpportunity(CommercialSubject $s): ?array
    {
        if (! $s->hasActiveMembership || $s->weeklyAttendanceRate() < 2.5) {
            return null;
        }

        if ($s->currentPlanDurationDays === null || $s->currentPlanDurationDays > 45) {
            return null; // ya tiene un plan largo
        }

        if ($s->daysAsMember === null || $s->daysAsMember < 21) {
            return null; // demasiado pronto para saber si le va a servir
        }

        return $this->seg(CommercialVocabulary::SEG_UPGRADE_OPPORTUNITY, 0.8, [
            'weekly_rate' => $s->weeklyAttendanceRate(),
            'current_plan_days' => $s->currentPlanDurationDays,
            'days_as_member' => $s->daysAsMember,
        ]);
    }

    /** Pedir un referido a alguien que no está contento sale caro. */
    private function referralCandidate(CommercialSubject $s): ?array
    {
        if (! $s->hasActiveMembership || $s->weeklyAttendanceRate() < 3.0) {
            return null;
        }

        if ($s->daysAsMember === null || $s->daysAsMember < 45) {
            return null;
        }

        return $this->seg(CommercialVocabulary::SEG_REFERRAL_CANDIDATE, 0.75, [
            'weekly_rate' => $s->weeklyAttendanceRate(),
            'days_as_member' => $s->daysAsMember,
        ]);
    }

    // ── Prospectos ────────────────────────────────────────────────────────────

    private function newProspect(CommercialSubject $s): ?array
    {
        if ($s->lead === null || $s->hasActiveMembership || $s->approvedPaymentsCount > 0) {
            return null;
        }

        if ($s->objective !== null || $s->temperature !== null) {
            return null; // ya sabemos algo de él: deja de ser «nuevo»
        }

        return $this->seg(CommercialVocabulary::SEG_NEW_PROSPECT, 1.0, ['no_data_yet' => true]);
    }

    /** Calificado = sabemos qué quiere. Sin eso no se puede recomendar nada. */
    private function qualifiedProspect(CommercialSubject $s): ?array
    {
        if ($s->lead === null || $s->hasActiveMembership || empty($s->objective)) {
            return null;
        }

        return $this->seg(CommercialVocabulary::SEG_QUALIFIED_PROSPECT, 0.9, [
            'objective' => $s->objective,
        ]);
    }

    private function highIntent(CommercialSubject $s): ?array
    {
        if ($s->hasActiveMembership) {
            return null;
        }

        $hot = in_array($s->temperature, ['hot', 'very_hot'], true);

        if (! $hot && ! $s->hasPendingPaymentLink) {
            return null;
        }

        return $this->seg(CommercialVocabulary::SEG_HIGH_INTENT, $hot ? 0.9 : 0.8, [
            'temperature' => $s->temperature,
            'has_pending_link' => $s->hasPendingPaymentLink,
        ]);
    }

    private function priceSensitive(CommercialSubject $s): ?array
    {
        return $s->priceObjections > 0
            ? $this->seg(CommercialVocabulary::SEG_PRICE_SENSITIVE, min(1.0, 0.6 + 0.2 * $s->priceObjections), [
                'objections' => $s->priceObjections,
            ])
            : null;
    }

    /** Habla, pero no avanza. */
    private function undecided(CommercialSubject $s): ?array
    {
        if ($s->hasActiveMembership || $s->lead === null) {
            return null;
        }

        if ($s->daysSinceLastMessage === null || $s->daysSinceLastMessage < 3 || $s->daysSinceLastMessage > 30) {
            return null;
        }

        if ($s->temperature === 'very_hot') {
            return null;
        }

        return $this->seg(CommercialVocabulary::SEG_UNDECIDED, 0.7, [
            'days_since_last_message' => $s->daysSinceLastMessage,
        ]);
    }
}
