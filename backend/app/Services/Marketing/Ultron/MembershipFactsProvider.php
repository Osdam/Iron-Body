<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingLead;
use App\Models\MembershipSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commercial\CommercialSubject;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Lo que el asistente puede afirmar sobre la MEMBRESÍA de quien escribe,
 * como hecho del CRM (`context.membership`).
 *
 * Reglas:
 * - Solo hay hechos con IDENTIDAD (`lead.member_id`). Lo que coincide solo por
 *   teléfono no es un hecho de esta persona: sin socio ligado, `status` es
 *   `unknown` y no viaja nada más. Es la misma regla que rige el dinero en
 *   CustomerIntelligenceService.
 * - Nunca lleva nombre, documento, teléfono ni correo: fechas, días, plan y
 *   estado. El precio de renovar sale del catálogo con el marcador de siempre.
 * - Lo que el CRM no tiene va como SOURCE_NOT_AVAILABLE, para que se diga
 *   que el dato no está confirmado, en lugar de inventarse. Aplazarlo en una
 *   persona es una promesa aparte, y esa la gobierna la autorización de
 *   traspaso.
 * - Lo que el asistente NO resuelve solo (pausar, cancelar el cobro automático,
 *   devoluciones, factura, cambio de plan a mitad de periodo) se declara en
 *   `team_only`: la estrategia lo pide con `staff_review`, nunca lo promete.
 *
 * Nunca lanza: si algo no se puede leer, ese hecho queda vacío o marcado.
 */
final class MembershipFactsProvider
{
    public const SOURCE_NOT_AVAILABLE = 'SOURCE_NOT_AVAILABLE';

    /** No hay socio ligado al lead: no se afirma nada de membresía. */
    public const STATUS_UNKNOWN = 'unknown';

    /** Socio identificado sin membresía registrada. */
    public const STATUS_NONE = 'none';

    public const STATUS_ACTIVE = 'active';

    /** Activa y dentro de la ventana de renovación. */
    public const STATUS_EXPIRING = 'expiring';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [self::STATUS_UNKNOWN, self::STATUS_NONE, self::STATUS_ACTIVE, self::STATUS_EXPIRING, self::STATUS_EXPIRED];

    /** Peticiones que el asistente traslada al equipo con staff_review; jamás las ejecuta ni las promete. */
    public const TEAM_ONLY = ['pause_membership', 'cancel_autopay', 'refund', 'invoice', 'change_plan_mid_term', 'transfer_membership'];

    /** Estados de suscripción con cobro automático vivo. */
    private const AUTOPAY_ON = [
        MembershipSubscription::STATUS_ACTIVE,
        MembershipSubscription::STATUS_PAST_DUE,
        MembershipSubscription::STATUS_PENDING_FIRST_PAYMENT,
    ];

    /**
     * @return array{
     *   status:string, identity:bool, plan:?array{id:?int,name:?string,duration_days:?int,sellable:bool},
     *   ends_on:?string, days_to_expiry:?int, days_since_expiry:?int, started_on:?string, days_as_member:?int,
     *   attendance:array{last_30_days:int,days_since_last:?int}|string,
     *   autopay:array{enabled:bool,status:string,next_charge_on:?string}|string,
     *   renewal:array{window_open:bool,plan_id:?int,window_days:int},
     *   team_only:string[]
     * }
     */
    public function forPrompt(?MarketingLead $lead): array
    {
        if ($lead === null || empty($lead->member_id)) {
            return $this->unknown();
        }

        try {
            $subject = CommercialSubject::build($lead);
        } catch (Throwable $e) {
            $this->aviso('subject', $e);

            return $this->unknown();
        }

        if ($subject->member === null) {
            return $this->unknown();
        }

        $status = $this->status($subject);
        $plan = $this->plan($subject);
        $windowOpen = $status === self::STATUS_EXPIRING;

        return [
            'status' => $status,
            'identity' => true,
            'plan' => $plan,
            'ends_on' => $subject->membershipEndsAt?->toDateString(),
            'days_to_expiry' => $subject->daysToExpiry,
            'days_since_expiry' => $subject->daysSinceExpiry,
            'started_on' => $subject->membershipStartedAt?->toDateString(),
            'days_as_member' => $subject->daysAsMember,
            'attendance' => [
                'last_30_days' => $subject->attendancesLast30Days,
                'days_since_last' => $subject->daysSinceLastAttendance,
            ],
            'autopay' => $this->autopay($subject),
            // Renovar solo en ventana (o ya vencida, que es recuperación) y solo
            // con un plan que hoy se vende. Sin plan vendible, plan_id es null y
            // la estrategia recomienda desde active_plans / default_plan_id.
            'renewal' => [
                'window_open' => $windowOpen,
                'plan_id' => ($windowOpen || $status === self::STATUS_EXPIRED) && $plan !== null && $plan['sellable'] ? $plan['id'] : null,
                'window_days' => CustomerIntelligenceService::RENEWAL_WINDOW_DAYS,
            ],
            'team_only' => self::TEAM_ONLY,
        ];
    }

    /** @return array<string,mixed> */
    private function unknown(): array
    {
        return [
            'status' => self::STATUS_UNKNOWN,
            'identity' => false,
            'plan' => null,
            'ends_on' => null,
            'days_to_expiry' => null,
            'days_since_expiry' => null,
            'started_on' => null,
            'days_as_member' => null,
            'attendance' => self::SOURCE_NOT_AVAILABLE,
            'autopay' => self::SOURCE_NOT_AVAILABLE,
            'renewal' => ['window_open' => false, 'plan_id' => null, 'window_days' => CustomerIntelligenceService::RENEWAL_WINDOW_DAYS],
            'team_only' => self::TEAM_ONLY,
        ];
    }

    private function status(CommercialSubject $subject): string
    {
        if ($subject->hasActiveMembership) {
            return $subject->daysToExpiry !== null && $subject->daysToExpiry <= CustomerIntelligenceService::RENEWAL_WINDOW_DAYS
                ? self::STATUS_EXPIRING
                : self::STATUS_ACTIVE;
        }

        return $subject->daysSinceExpiry !== null ? self::STATUS_EXPIRED : self::STATUS_NONE;
    }

    /**
     * El plan del socio: por id cuando hay suscripción; por NOMBRE cuando es
     * pago único (users.plan). Un nombre que no casa con el catálogo viaja tal
     * cual (es texto del CRM, no del modelo) con id null y sellable false.
     *
     * @return ?array{id:?int,name:?string,duration_days:?int,sellable:bool}
     */
    private function plan(CommercialSubject $subject): ?array
    {
        try {
            $plan = $subject->currentPlanId !== null ? Plan::query()->find($subject->currentPlanId) : null;
            $rawName = null;

            if ($plan === null) {
                $user = $subject->member?->user_id ? User::query()->find($subject->member->user_id) : null;
                $rawName = trim((string) ($user?->plan ?? ''));
                if ($rawName !== '') {
                    $plan = Plan::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($rawName)])->first();
                }
            }

            if ($plan === null && ($rawName === null || $rawName === '')) {
                return null;
            }

            return [
                'id' => $plan?->id,
                'name' => $plan?->name ?? $rawName,
                'duration_days' => $plan !== null ? (int) $plan->duration_days : $subject->currentPlanDurationDays,
                'sellable' => $plan?->isSellable() ?? false,
            ];
        } catch (Throwable $e) {
            $this->aviso('plan', $e);

            return null;
        }
    }

    /** @return array{enabled:bool,status:string,next_charge_on:?string}|string */
    private function autopay(CommercialSubject $subject): array|string
    {
        try {
            if (! Schema::hasTable('membership_subscriptions')) {
                return self::SOURCE_NOT_AVAILABLE;
            }
            $userId = $subject->member?->user_id;
            $sub = $userId
                ? MembershipSubscription::query()->where('user_id', $userId)->orderByDesc('id')->first(['status', 'next_charge_at'])
                : null;

            if ($sub === null) {
                return ['enabled' => false, 'status' => 'none', 'next_charge_on' => null];
            }

            $status = (string) $sub->status;

            return [
                'enabled' => in_array($status, self::AUTOPAY_ON, true),
                'status' => $status,
                'next_charge_on' => in_array($status, self::AUTOPAY_ON, true) ? $sub->next_charge_at?->toDateString() : null,
            ];
        } catch (Throwable $e) {
            $this->aviso('autopay', $e);

            return self::SOURCE_NOT_AVAILABLE;
        }
    }

    private function aviso(string $fuente, Throwable $e): void
    {
        ChannelLog::warning('ultron.membership_facts.unavailable', ['source' => $fuente, 'exception' => class_basename($e)]);
    }
}
