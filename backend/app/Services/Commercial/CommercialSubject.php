<?php

namespace App\Services\Commercial;

use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Todo lo que se sabe de una persona, reunido una sola vez.
 *
 * El motor comercial necesita cruzar datos que viven en media docena de sitios:
 * el lead de WhatsApp, el miembro, su membresía, sus pagos, sus asistencias y
 * sus citas. Hacer esas consultas dentro de cada regla acabaría en un N+1 y en
 * reglas que no se pueden probar sin media base de datos montada.
 *
 * Esta clase las hace una vez y expone hechos. Las reglas se escriben contra
 * hechos, se leen como frases y se prueban con un objeto construido a mano.
 *
 * Se consulta de forma DEFENSIVA: si una tabla no existe en este entorno, el
 * hecho correspondiente queda en null y las reglas que dependan de él
 * simplemente no disparan. Un motor que revienta porque falta una tabla opcional
 * es peor que uno que decide con menos información.
 */
class CommercialSubject
{
    public function __construct(
        public readonly ?MarketingLead $lead = null,
        public readonly ?Member $member = null,
        // ── Hechos de membresía ───────────────────────────────────────────
        public readonly bool $hasActiveMembership = false,
        public readonly ?Carbon $membershipEndsAt = null,
        public readonly ?int $daysToExpiry = null,
        public readonly ?int $daysSinceExpiry = null,
        public readonly ?int $currentPlanId = null,
        public readonly ?float $currentPlanPrice = null,
        public readonly ?int $currentPlanDurationDays = null,
        // ── Comportamiento ────────────────────────────────────────────────
        public readonly int $attendancesLast30Days = 0,
        public readonly ?Carbon $lastAttendanceAt = null,
        public readonly ?int $daysSinceLastAttendance = null,
        public readonly ?Carbon $membershipStartedAt = null,
        public readonly ?int $daysAsMember = null,
        // ── Dinero ────────────────────────────────────────────────────────
        public readonly bool $hasPendingPaymentLink = false,
        public readonly ?Carbon $pendingPaymentLinkAt = null,
        public readonly ?int $pendingPaymentPlanId = null,
        public readonly bool $hasDeclinedPayment = false,
        public readonly int $approvedPaymentsCount = 0,
        public readonly float $lifetimeValue = 0.0,
        // ── Conversación ──────────────────────────────────────────────────
        public readonly ?Carbon $lastInboundAt = null,
        public readonly ?int $daysSinceLastMessage = null,
        public readonly bool $doNotContact = false,
        public readonly bool $needsHuman = false,
        public readonly ?string $temperature = null,
        public readonly ?string $objective = null,
        public readonly int $priceObjections = 0,
        public readonly bool $hasAppAccount = false,
    ) {}

    /**
     * Construye la fotografía a partir de un lead y/o un miembro.
     *
     * Nunca lanza: si algo no se puede leer, ese hecho queda vacío.
     */
    public static function build(?MarketingLead $lead, ?Member $member = null): self
    {
        $member ??= self::resolveMember($lead);
        $user = self::resolveUser($member);

        $membership = self::membershipFacts($user);
        $attendance = self::attendanceFacts($member);
        $money = self::moneyFacts($lead, $member);
        $conversation = self::conversationFacts($lead);

        return new self(
            lead: $lead,
            member: $member,
            hasActiveMembership: $membership['active'],
            membershipEndsAt: $membership['ends_at'],
            daysToExpiry: $membership['days_to_expiry'],
            daysSinceExpiry: $membership['days_since_expiry'],
            currentPlanId: $membership['plan_id'],
            currentPlanPrice: $membership['plan_price'],
            currentPlanDurationDays: $membership['plan_duration'],
            attendancesLast30Days: $attendance['last_30'],
            lastAttendanceAt: $attendance['last_at'],
            daysSinceLastAttendance: $attendance['days_since'],
            membershipStartedAt: $membership['started_at'],
            daysAsMember: $membership['days_as_member'],
            hasPendingPaymentLink: $money['pending_link'],
            pendingPaymentLinkAt: $money['pending_at'],
            pendingPaymentPlanId: $money['pending_plan_id'],
            hasDeclinedPayment: $money['declined'],
            approvedPaymentsCount: $money['approved_count'],
            lifetimeValue: $money['lifetime_value'],
            lastInboundAt: $conversation['last_inbound_at'],
            daysSinceLastMessage: $conversation['days_since'],
            doNotContact: $conversation['do_not_contact'],
            needsHuman: $conversation['needs_human'],
            temperature: $conversation['temperature'],
            objective: $conversation['objective'],
            priceObjections: $conversation['price_objections'],
            hasAppAccount: $user !== null,
        );
    }

    private static function resolveMember(?MarketingLead $lead): ?Member
    {
        if ($lead === null || empty($lead->member_id)) {
            return null;
        }

        try {
            return Member::find($lead->member_id);
        } catch (Throwable) {
            return null;
        }
    }

    private static function resolveUser(?Member $member): ?User
    {
        if ($member === null || empty($member->user_id)) {
            return null;
        }

        try {
            return User::find($member->user_id);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Estado de la membresía a través de MembershipService, que ya es la
     * autoridad sobre qué significa «activa» en este negocio. Reimplementarlo
     * aquí acabaría produciendo dos verdades distintas.
     *
     * @return array<string,mixed>
     */
    /**
     * Lleva cualquier fecha al Carbon de Laravel.
     *
     * El sistema mezcla las dos clases: los servicios antiguos usan
     * `Carbon\Carbon` y Eloquent devuelve `Illuminate\Support\Carbon`. Como la
     * segunda hereda de la primera, un valor de la clase base NO satisface un
     * parámetro tipado con la derivada, y el fallo aparece lejos del origen.
     */
    private static function asCarbon(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private static function membershipFacts(?User $user): array
    {
        $empty = [
            'active' => false, 'ends_at' => null, 'days_to_expiry' => null,
            'days_since_expiry' => null, 'plan_id' => null, 'plan_price' => null,
            'plan_duration' => null, 'started_at' => null, 'days_as_member' => null,
        ];

        if ($user === null) {
            return $empty;
        }

        try {
            $service = app(MembershipService::class);
            $endsAt = $service->endsAt($user);
            $active = $service->isActive($user);

            $daysToExpiry = $endsAt !== null && $endsAt->isFuture()
                ? (int) now()->diffInDays($endsAt, false)
                : null;
            $daysSinceExpiry = $endsAt !== null && $endsAt->isPast()
                ? (int) $endsAt->diffInDays(now(), false)
                : null;

            $subscription = Schema::hasTable('membership_subscriptions')
                ? DB::table('membership_subscriptions')
                    ->where('user_id', $user->id)
                    ->orderByDesc('id')
                    ->first(['plan_id', 'price_snapshot', 'interval_days', 'current_period_start'])
                : null;

            // La suscripción manda cuando existe, pero la mayoría de la gente
            // paga de una vez y nunca tiene fila en membership_subscriptions.
            // Sin este respaldo, `started_at` era null para casi todos los
            // socios, y con él `days_as_member`, que es la condición que exige
            // llevar un tiempo mínimo antes de proponer una mejora de plan: el
            // resultado era que a los clientes de pago único no se les ofrecía
            // nunca nada.
            $startedAt = self::asCarbon(
                $subscription?->current_period_start ?? $user->membership_start_date,
            );

            return [
                'active' => $active,
                // Se normaliza el tipo a propósito. MembershipService devuelve
                // Carbon\Carbon y este objeto declara Illuminate\Support\Carbon,
                // que es una subclase: la conversión NO es automática en esa
                // dirección. Sin este ajuste, construir el sujeto de cualquier
                // socio con membresía vigente lanzaba un TypeError, y como
                // ocurre fuera del try de este método, se llevaba por delante
                // la evaluación entera.
                'ends_at' => self::asCarbon($endsAt),
                'days_to_expiry' => $daysToExpiry,
                'days_since_expiry' => $daysSinceExpiry,
                'plan_id' => $subscription->plan_id ?? null,
                'plan_price' => isset($subscription->price_snapshot) ? (float) $subscription->price_snapshot : null,
                'plan_duration' => isset($subscription->interval_days) ? (int) $subscription->interval_days : null,
                'started_at' => $startedAt,
                'days_as_member' => $startedAt !== null ? (int) $startedAt->diffInDays(now()) : null,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /** @return array<string,mixed> */
    private static function attendanceFacts(?Member $member): array
    {
        $empty = ['last_30' => 0, 'last_at' => null, 'days_since' => null];

        if ($member === null || ! Schema::hasTable('attendances')) {
            return $empty;
        }

        try {
            // Solo entradas: una salida no es una visita adicional.
            //
            // El valor es 'entry'. La columna es un enum('entry','exit') y así
            // lo escriben el torniquete y la app. Antes se filtraba por 'in',
            // que no coincide con ninguna fila: la consulta devolvía siempre
            // cero y TODO socio parecía no haber pisado nunca el gimnasio, lo
            // que a su vez hacía que nadie llegara nunca al umbral de uso para
            // una mejora de plan.
            $base = DB::table('attendances')
                ->where('member_id', $member->id)
                ->where('action', 'entry');

            $last30 = (clone $base)->where('captured_at', '>=', now()->subDays(30))->count();
            $lastRow = (clone $base)->orderByDesc('captured_at')->first(['captured_at']);
            $lastAt = $lastRow?->captured_at ? Carbon::parse($lastRow->captured_at) : null;

            return [
                'last_30' => $last30,
                'last_at' => $lastAt,
                'days_since' => $lastAt !== null ? (int) $lastAt->diffInDays(now()) : null,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /** @return array<string,mixed> */
    private static function moneyFacts(?MarketingLead $lead, ?Member $member): array
    {
        $empty = [
            'pending_link' => false, 'pending_at' => null, 'pending_plan_id' => null,
            'declined' => false, 'approved_count' => 0, 'lifetime_value' => 0.0,
        ];

        if (! Schema::hasTable('payment_transactions')) {
            return $empty;
        }

        try {
            $query = PaymentTransaction::query();

            // El lead se identifica por su teléfono en la transacción; el
            // miembro, por su id. Se usan los dos porque una misma persona
            // puede haber pagado antes y después de convertirse en miembro.
            $matched = false;
            $query->where(function ($q) use ($lead, $member, &$matched): void {
                if ($member !== null) {
                    $q->orWhere('member_id', $member->id);
                    $matched = true;
                }
                if ($lead !== null && ! empty($lead->phone)) {
                    $digits = preg_replace('/[^0-9]/', '', (string) $lead->phone) ?? '';
                    if ($digits !== '') {
                        $q->orWhere('customer_phone', 'like', '%'.substr($digits, -10));
                        $matched = true;
                    }
                }
            });

            if (! $matched) {
                return $empty;
            }

            $transactions = $query->orderByDesc('id')->limit(50)->get([
                'status', 'plan_id', 'amount', 'created_at', 'expires_at',
            ]);

            // Un enlace pendiente sigue vivo si no ha caducado. Perseguir un
            // enlace caducado es peor que no perseguirlo: el cliente hace clic
            // y se encuentra un error.
            $pending = $transactions->first(fn ($t) => in_array($t->status, ['PENDING', 'pending', 'created'], true)
                && ($t->expires_at === null || Carbon::parse($t->expires_at)->isFuture()));

            $declined = $transactions->contains(fn ($t) => in_array($t->status, ['DECLINED', 'declined', 'ERROR', 'error'], true));
            $approved = $transactions->filter(fn ($t) => in_array($t->status, ['APPROVED', 'approved'], true));

            return [
                'pending_link' => $pending !== null,
                'pending_at' => $pending?->created_at ? Carbon::parse($pending->created_at) : null,
                'pending_plan_id' => $pending->plan_id ?? null,
                'declined' => $declined,
                'approved_count' => $approved->count(),
                'lifetime_value' => (float) $approved->sum('amount'),
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /** @return array<string,mixed> */
    private static function conversationFacts(?MarketingLead $lead): array
    {
        if ($lead === null) {
            return [
                'last_inbound_at' => null, 'days_since' => null, 'do_not_contact' => false,
                'needs_human' => false, 'temperature' => null, 'objective' => null,
                'price_objections' => 0,
            ];
        }

        $lastInbound = $lead->last_message_at;

        $priceObjections = 0;
        $needsHuman = false;

        try {
            if (Schema::hasTable('marketing_conversations')) {
                $conversation = DB::table('marketing_conversations')
                    ->where('lead_id', $lead->id)
                    ->orderByDesc('id')
                    ->first(['staff_review_pending', 'human_takeover', 'last_inbound_at']);

                $needsHuman = (bool) ($conversation->staff_review_pending ?? false)
                    || (bool) ($conversation->human_takeover ?? false);

                if (! empty($conversation->last_inbound_at)) {
                    $lastInbound = Carbon::parse($conversation->last_inbound_at);
                }
            }

            if (Schema::hasTable('marketing_ai_actions')) {
                $priceObjections = DB::table('marketing_ai_actions')
                    ->where('lead_id', $lead->id)
                    ->where('action_type', 'register_objection')
                    ->count();
            }
        } catch (Throwable) {
            // Los hechos de conversación son un extra: su ausencia no bloquea.
        }

        return [
            'last_inbound_at' => $lastInbound,
            'days_since' => $lastInbound !== null ? (int) Carbon::parse($lastInbound)->diffInDays(now()) : null,
            'do_not_contact' => (bool) $lead->do_not_contact,
            'needs_human' => $needsHuman,
            'temperature' => $lead->temperature,
            'objective' => $lead->objective,
            'price_objections' => $priceObjections,
        ];
    }

    /** Frecuencia semanal de entrenamiento, base de la adherencia. */
    public function weeklyAttendanceRate(): float
    {
        return round($this->attendancesLast30Days / 4.3, 2);
    }

    /** ¿Se le puede escribir? El opt-out manda por encima de todo lo demás. */
    public function isContactable(): bool
    {
        return ! $this->doNotContact;
    }

    /** Resumen sin datos personales, para dejarlo como evidencia auditable. */
    public function toEvidence(): array
    {
        return array_filter([
            'has_active_membership' => $this->hasActiveMembership,
            'days_to_expiry' => $this->daysToExpiry,
            'days_since_expiry' => $this->daysSinceExpiry,
            'attendances_last_30_days' => $this->attendancesLast30Days,
            'weekly_attendance_rate' => $this->weeklyAttendanceRate(),
            'days_since_last_attendance' => $this->daysSinceLastAttendance,
            'days_as_member' => $this->daysAsMember,
            'has_pending_payment_link' => $this->hasPendingPaymentLink,
            'has_declined_payment' => $this->hasDeclinedPayment,
            'approved_payments' => $this->approvedPaymentsCount,
            'lifetime_value' => $this->lifetimeValue,
            'days_since_last_message' => $this->daysSinceLastMessage,
            'temperature' => $this->temperature,
            'objective' => $this->objective,
            'price_objections' => $this->priceObjections,
            'has_app_account' => $this->hasAppAccount,
        ], fn ($v) => $v !== null && $v !== false && $v !== 0 && $v !== 0.0);
    }
}
