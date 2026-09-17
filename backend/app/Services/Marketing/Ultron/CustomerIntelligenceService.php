<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commercial\CommercialSubject;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesIntents;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Quién es la persona con la que habla ULTRON, dicho con honestidad.
 *
 * Tres cajones que no se mezclan: KNOWN (lo que el CRM sabe por hechos ligados
 * a una IDENTIDAD: membresía, pagos del socio, link pendiente, cuenta en la
 * app, lo que ya se le dijo), INFERRED (lo que el clasificador o la
 * conversación sugieren: objetivo, barrera, señales de compra; y también los
 * pagos que sólo coinciden por teléfono, porque ese teléfono lo escribe quien
 * paga) y UNKNOWN (lo que nadie sabe y por tanto puede preguntarse).
 *
 * La temperatura es una lectura de señales observables. El ciclo de vida es
 * un overlay derivado de hechos, y falla EN ABIERTO: cuando no se sabe qué
 * plan tiene la persona, no se afirma que sea el mismo. Lo único que se
 * bloquea es lo que se sabe seguro: cotizarle a un socio el plan que ya tiene.
 */
class CustomerIntelligenceService
{
    public const COLD = 'COLD';

    public const WARM = 'WARM';

    public const HOT = 'HOT';

    public const READY = 'READY';

    public const PROSPECT = 'PROSPECT';

    public const INTERESTED = 'INTERESTED';

    public const READY_TO_BUY = 'READY_TO_BUY';

    public const PAYMENT_PENDING = 'PAYMENT_PENDING';

    public const PAID = 'PAID';

    public const ONBOARDING = 'ONBOARDING';

    public const ACTIVE_MEMBER = 'ACTIVE_MEMBER';

    /** Fue socio y ya no lo es: la persona que más interesa recuperar. */
    public const LAPSED = 'LAPSED';

    /** Días de membresía tras los que alguien deja de estar «empezando». */
    public const ONBOARDING_DAYS = 7;

    /** Con menos de esto para vencer, volver a vender el mismo plan es renovar, no revender. */
    public const RENEWAL_WINDOW_DAYS = 7;

    /** Un pago aprobado más viejo que esto ya no es «acaba de pagar». */
    public const PAID_RECENT_DAYS = 30;

    private const GOALS = [SalesIntents::GOAL_FAT_LOSS, SalesIntents::GOAL_MUSCLE_GAIN, SalesIntents::GOAL_RECOMPOSITION];

    private const RISK = [SalesIntents::HUMAN_REQUEST, SalesIntents::COMPLAINT, SalesIntents::INVOICE_REQUEST, SalesIntents::MEDICAL_RISK_ESCALATION, SalesIntents::FRAUD_OR_PAYMENT_CLAIM];

    private const SOLO_MIRANDO = '/\b(solo (estaba|estoy) (mirando|viendo|curioseando|preguntando)|solo miro|solo por curiosidad|por ahora solo (info|informacion)|nada mas (queria|quiero) saber)\b/u';

    private const PRIMERA_VEZ = '/\b(primera vez|nunca he (entrenado|ido|hecho)|soy nuevo|soy nueva|empezando de cero|no he entrenado)\b/u';

    private const CON_EXPERIENCIA = '/\b(ya (he )?entren(e|ado)|entrenaba|antes iba|llevo (anos|meses) entrenando|tengo experiencia|vengo de otro gym)\b/u';

    /** Hechos del subsistema Commercial. Público para poder sustituirlo en pruebas. */
    public function subjectFor(MarketingLead $lead): CommercialSubject
    {
        return CommercialSubject::build($lead);
    }

    /**
     * @param  array<string,mixed>  $resolved  lo que dijo {@see ReferenceResolver} para este mensaje
     * @return array<string,mixed>
     */
    public function profile(
        MarketingConversation $conversation,
        MarketingMessage $message,
        ConversationMemory $memory,
        array $resolved,
        string $baseIntent,
    ): array {
        $lead = $conversation->lead;
        $subject = $lead !== null ? $this->subjectFor($lead) : new CommercialSubject;
        $identity = $subject->member !== null;
        $recent = $conversation->messages()->latest('id')->limit(10)->get()->reverse()->values();
        $inbound = $recent->where('direction', MarketingMessage::DIRECTION_INBOUND)->values();
        $textos = $inbound->map(fn ($m) => SalesAgentDecisionSchema::normalize((string) $m->body))->all();
        $texto = SalesAgentDecisionSchema::normalize((string) $message->body);

        // Dinero: sólo cuenta como hecho lo ligado al socio, nunca lo que sólo
        // coincide por teléfono (ese teléfono lo escribe quien paga en el checkout).
        $paidRecent = $identity ? $this->recentApprovedPayment($subject->member) : null;
        $currentPlanId = $subject->currentPlanId ?? ($identity ? $this->planIdFromUser($subject->member) : null);

        $known = [
            'has_name' => $lead !== null && trim((string) $lead->name) !== '',
            'objective' => $lead !== null && trim((string) $lead->objective) !== '' ? (string) $lead->objective : null,
            'is_member' => $identity,
            'has_active_membership' => $subject->hasActiveMembership,
            'membership_days_to_expiry' => $subject->daysToExpiry,
            'days_since_expiry' => $subject->daysSinceExpiry,
            'days_as_member' => $subject->daysAsMember,
            'current_plan_id' => $currentPlanId,
            'paid_plan_id' => $paidRecent['plan_id'] ?? null,
            'approved_payments_count' => $identity ? $subject->approvedPaymentsCount : 0,
            'has_pending_payment_link' => $identity && $subject->hasPendingPaymentLink,
            'pending_payment_plan_id' => $identity ? $subject->pendingPaymentPlanId : null,
            'has_declined_payment' => $identity && $subject->hasDeclinedPayment,
            'has_app_account' => $subject->hasAppAccount,
            'do_not_contact' => $subject->doNotContact,
            'commercial_opt_out' => $subject->commercialOptOut,
            'money_facts_source' => $identity ? 'identity' : 'none',
            'plans_discussed' => $memory->plansDiscussed(),
            'prices_delivered_for' => array_values(array_unique(array_map(fn ($p) => (int) $p['plan_id'], $memory->get('prices_delivered')))),
            'commercial_commitment_plan_id' => $memory->get('commercial_commitment')['plan_id'] ?? null,
            'objections_seen' => array_values(array_unique(array_map(fn ($o) => (string) $o['type'], $memory->get('objections_seen')))),
        ];

        $buying = $this->buyingSignals($baseIntent, $resolved, $memory);
        $inferred = [
            'objective' => $known['objective'] === null && trim((string) $conversation->detected_objective) !== '' ? (string) $conversation->detected_objective : null,
            'main_barrier' => $conversation->main_barrier,
            'experience_level' => $this->experience($textos),
            'plan_interest' => $resolved['plan_id'] ?? $memory->pendingPlanId(),
            'buying_signals' => $buying,
            'budget_signal' => in_array(SalesIntents::PRICE_OBJECTION, $known['objections_seen'], true) || $baseIntent === SalesIntents::PRICE_OBJECTION ? 'price_sensitive' : null,
            'just_browsing' => preg_match(self::SOLO_MIRANDO, $texto) === 1,
            // Pagos que sólo coinciden por teléfono: pista, no hecho.
            'payment_hint' => ! $identity && ($subject->approvedPaymentsCount > 0 || $subject->hasPendingPaymentLink)
                ? ['approved_payments_by_phone' => $subject->approvedPaymentsCount, 'pending_link_by_phone' => $subject->hasPendingPaymentLink]
                : null,
        ];

        $unknown = [];
        foreach (['objective' => $known['objective'] ?? $inferred['objective'], 'experience_level' => $inferred['experience_level'], 'main_barrier' => $inferred['main_barrier'], 'plan_interest' => $inferred['plan_interest'], 'time_constraints' => null, 'class_interest' => $memory->get('classes_discussed') === [] ? null : true, 'budget_signal' => $inferred['budget_signal']] as $k => $v) {
            if ($v === null) {
                $unknown[] = $k;
            }
        }

        [$temperature, $signals] = $this->temperature($baseIntent, $resolved, $memory, $known['has_pending_payment_link'], $inferred['just_browsing'], $buying);
        $lifecycle = $this->lifecycle($subject, $identity, $paidRecent !== null, $temperature, $known);
        $momentum = $this->momentum($inbound, $message, $baseIntent, $resolved);

        return [
            'known' => $known,
            'inferred' => $inferred,
            'unknown' => $unknown,
            'lead_temperature' => $temperature,
            'temperature_signals' => $signals,
            'conversation_momentum' => $momentum,
            'customer_lifecycle' => $lifecycle,
            'payment_state' => $this->paymentState($identity, $subject, $paidRecent !== null),
            'app_state' => $subject->hasAppAccount ? 'has_account' : 'unknown',
            'risk' => in_array($baseIntent, self::RISK, true),
            'renewal_window' => $subject->hasActiveMembership && $subject->daysToExpiry !== null && $subject->daysToExpiry <= self::RENEWAL_WINDOW_DAYS,
        ];
    }

    /**
     * ¿Es cotizarle el plan que ya tiene?
     *
     * Anclado al EFECTO (el plan que el turno cotiza o compromete), no a la
     * etiqueta de fase que elige el modelo. Y falla en abierto: si no se sabe
     * qué plan tiene la persona, o el turno no cotiza ninguno, no hay evidencia
     * de reventa y no se bloquea. Renovar cerca del vencimiento y otro plan
     * siguen permitidos; un exsocio (LAPSED) es prospecto de recuperación.
     *
     * @param  array<string,mixed>  $profile
     */
    public function isResell(array $profile, ?int $quotedPlanId): bool
    {
        if ($quotedPlanId === null || ($profile['renewal_window'] ?? false)) {
            return false;
        }
        if (! in_array($profile['customer_lifecycle'] ?? null, [self::PAID, self::ONBOARDING, self::ACTIVE_MEMBER], true)) {
            return false;
        }
        $current = $profile['known']['current_plan_id'] ?? $profile['known']['paid_plan_id'] ?? null;

        return $current !== null && (int) $current === (int) $quotedPlanId;
    }

    /** Fases que cuentan como cerrar una venta. Solo informativo para prompts y métricas. */
    public static function closingPhases(): array
    {
        return [CommercialPhaseMachine::CLOSING, CommercialPhaseMachine::PAYMENT_HANDOFF, CommercialPhaseMachine::WON];
    }

    /** @return array{plan_id:?int, at:string}|null */
    private function recentApprovedPayment(Member $member): ?array
    {
        if (! Schema::hasTable('payment_transactions')) {
            return null;
        }
        try {
            $since = now()->subDays(self::PAID_RECENT_DAYS);
            $t = PaymentTransaction::query()
                ->where('member_id', $member->id)
                ->whereIn('status', ['APPROVED', 'approved'])
                // La fecha contable es approved_at; created_at sólo si no la hay.
                ->where(fn ($q) => $q->where('approved_at', '>=', $since)
                    ->orWhere(fn ($q2) => $q2->whereNull('approved_at')->where('created_at', '>=', $since)))
                ->orderByDesc('id')
                ->first(['plan_id', 'approved_at', 'created_at']);
        } catch (Throwable) {
            return null;
        }

        return $t === null ? null : ['plan_id' => $t->plan_id !== null ? (int) $t->plan_id : null, 'at' => optional($t->approved_at ?? $t->created_at)->toIso8601String()];
    }

    /** El plan del socio de pago único vive como NOMBRE en users.plan. */
    private function planIdFromUser(Member $member): ?int
    {
        try {
            $user = $member->user_id ? User::find($member->user_id) : null;
            $name = trim((string) ($user?->plan ?? ''));
            if ($name === '') {
                return null;
            }
            $plan = Plan::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first(['id']);

            return $plan?->id;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return string[] */
    private function buyingSignals(string $intent, array $resolved, ConversationMemory $memory): array
    {
        $s = [];
        if (in_array($intent, [SalesIntents::PAYMENT_LINK_REQUEST, SalesIntents::HIGH_INTENT_CLOSE], true)) {
            $s[] = 'intent:'.$intent;
        }
        if (in_array($resolved['type'] ?? null, [ReferenceResolver::SEND_IT, ReferenceResolver::CHOOSE_PLAN, ReferenceResolver::HOW_TO_START], true)) {
            $s[] = 'reference:'.$resolved['type'];
        }
        if (($resolved['type'] ?? null) === ReferenceResolver::ACCEPT_OFFER && in_array($resolved['offer_kind'] ?? null, ['explain_how_to_start', 'send_payment_link', 'book_visit'], true)) {
            $s[] = 'accepted:'.$resolved['offer_kind'];
        }
        if (($memory->get('commercial_commitment')['plan_id'] ?? null) !== null) {
            $s[] = 'commitment';
        }
        if (in_array($intent, [SalesIntents::PRICING_QUESTION, SalesIntents::PRICE_OBJECTION], true) || ($resolved['type'] ?? null) === ReferenceResolver::PRICE_OF_PENDING) {
            $s[] = 'price_engagement';
        }

        return $s;
    }

    /** @return array{0:string, 1:string[]} */
    private function temperature(string $intent, array $resolved, ConversationMemory $memory, bool $pendingLink, bool $browsing, array $buying): array
    {
        $signals = [];
        $level = self::COLD;
        $order = [self::COLD => 0, self::WARM => 1, self::HOT => 2, self::READY => 3];
        $bump = function (string $to, string $why) use (&$level, &$signals, $order): void {
            if ($order[$to] > $order[$level]) {
                $level = $to;
            }
            $signals[] = $why;
        };

        if ($pendingLink || in_array($intent, [SalesIntents::PAYMENT_LINK_REQUEST, SalesIntents::HIGH_INTENT_CLOSE], true)) {
            $bump(self::READY, $pendingLink ? 'pending_payment_link' : 'intent:'.$intent);
        }
        if (in_array($resolved['type'] ?? null, [ReferenceResolver::SEND_IT, ReferenceResolver::CHOOSE_PLAN], true)) {
            $bump(self::READY, 'reference:'.$resolved['type']);
        }
        if (in_array('commitment', $buying, true)) {
            $bump(self::READY, 'commercial_commitment');
        }
        if (($resolved['type'] ?? null) === ReferenceResolver::HOW_TO_START || in_array('accepted:explain_how_to_start', $buying, true)) {
            $bump(self::HOT, 'wants_to_start');
        }
        if ($intent === SalesIntents::PRICE_OBJECTION || ($resolved['type'] ?? null) === ReferenceResolver::PRICE_OF_PENDING) {
            $bump(self::HOT, 'price_engagement');
        }
        if ($intent === SalesIntents::PRICING_QUESTION) {
            $bump($memory->plansDiscussed() === [] ? self::WARM : self::HOT, 'pricing_question');
        }
        if (($resolved['type'] ?? null) === ReferenceResolver::MORE_INFO && $memory->get('prices_delivered') !== []) {
            $bump(self::HOT, 'more_info_after_price');
        }
        if (in_array($intent, self::GOALS, true) || in_array($intent, [SalesIntents::LOCATION_QUESTION, SalesIntents::SCHEDULE_QUESTION, SalesIntents::TIME_OBJECTION, SalesIntents::DELAY_OBJECTION, SalesIntents::BEGINNER_FEAR, SalesIntents::INSECURITY_BODY], true) || ($resolved['type'] ?? null) === ReferenceResolver::MORE_INFO) {
            $bump(self::WARM, 'engaged:'.$intent);
        }
        if ($memory->plansDiscussed() !== []) {
            $bump(self::WARM, 'plans_discussed');
        }
        if ($browsing) {
            if ($level !== self::READY) {
                $level = $level === self::HOT ? self::WARM : self::COLD;
            }
            $signals[] = 'just_browsing';
        }
        if (in_array($intent, [SalesIntents::NOT_INTERESTED, SalesIntents::GOODBYE, SalesIntents::DO_NOT_CONTACT_REQUEST], true) || ($resolved['type'] ?? null) === ReferenceResolver::DECLINE_OFFER) {
            $level = self::COLD;
            $signals[] = 'declined:'.$intent;
        }

        return [$level, array_values(array_unique($signals))];
    }

    private function lifecycle(CommercialSubject $subject, bool $identity, bool $paidRecently, string $temperature, array $known): string
    {
        if ($subject->hasActiveMembership) {
            // «Empezando» es la primera membresía, no los primeros días de cada
            // cobro recurrente: el periodo en curso se reinicia con cada cargo.
            $primera = $subject->approvedPaymentsCount <= 1;

            return $primera && $subject->daysAsMember !== null && $subject->daysAsMember <= self::ONBOARDING_DAYS ? self::ONBOARDING : self::ACTIVE_MEMBER;
        }
        if ($identity && $paidRecently) {
            return self::PAID;
        }
        if ($subject->daysSinceExpiry !== null) {
            return self::LAPSED;
        }
        if ($known['has_pending_payment_link']) {
            return self::PAYMENT_PENDING;
        }
        if ($temperature === self::READY) {
            return self::READY_TO_BUY;
        }
        if ($known['plans_discussed'] !== [] || $temperature !== self::COLD) {
            return self::INTERESTED;
        }

        return self::PROSPECT;
    }

    private function momentum($inbound, MarketingMessage $message, string $intent, array $resolved): string
    {
        if (in_array($intent, [SalesIntents::NOT_INTERESTED, SalesIntents::GOODBYE], true) || ($resolved['type'] ?? null) === ReferenceResolver::DECLINE_OFFER) {
            return 'stalling';
        }
        $previous = $inbound->filter(fn ($m) => $m->id < $message->id)->last();
        if ($previous === null || $previous->created_at === null || $message->created_at === null) {
            return 'steady';
        }
        $gapMinutes = $previous->created_at->diffInMinutes($message->created_at);
        $recentCount = $inbound->filter(fn ($m) => $m->created_at !== null && $message->created_at !== null && $m->created_at->diffInMinutes($message->created_at) <= 15)->count();
        if ($gapMinutes >= 24 * 60) {
            return 'cold_restart';
        }
        if ($recentCount >= 3 || $gapMinutes <= 3) {
            return 'accelerating';
        }

        return $gapMinutes > 120 ? 'stalling' : 'steady';
    }

    private function paymentState(bool $identity, CommercialSubject $subject, bool $paidRecently): string
    {
        if (! $identity) {
            return 'unknown';
        }
        if ($subject->hasPendingPaymentLink) {
            return 'pending';
        }
        if ($paidRecently) {
            return 'approved';
        }
        if ($subject->hasDeclinedPayment) {
            return 'declined';
        }

        return $subject->approvedPaymentsCount > 0 ? 'approved_past' : 'none';
    }

    /** @param string[] $textos */
    private function experience(array $textos): ?string
    {
        foreach (array_reverse($textos) as $t) {
            if (preg_match(self::PRIMERA_VEZ, $t) === 1) {
                return 'beginner';
            }
            if (preg_match(self::CON_EXPERIENCIA, $t) === 1) {
                return 'experienced';
            }
        }

        return null;
    }
}
