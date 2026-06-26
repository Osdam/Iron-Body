<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Ciclo de vida de la membresía: renovación y cancelación (Bloque 3).
 *
 * Modelo productivo, sin mocks. La membresía vive en `users` (plan + fechas).
 * "Cancelar" = dejar de renovar: el miembro CONSERVA el acceso hasta el fin del
 * periodo vigente (`membership_end_date`); al expirar queda 'cancelled'. Nunca
 * borra datos ni elimina la cuenta. Reactivar deshace la cancelación.
 *
 * El COBRO recurrente automático real depende de un proveedor de suscripciones
 * (p. ej. tokenización recurrente de Wompi). Esa pieza queda como hook documentado
 * (`payment_provider_subscription_id` + applyProviderRenewal); ver
 * docs/MEMBRESIA_RENOVACION_CANCELACION.md. Sin proveedor conectado, la
 * renovación sigue ocurriendo por el flujo de pago existente (app/CRM).
 */
class MembershipService
{
    public const STATUS_NONE = 'none';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCEL_REQUESTED = 'cancel_requested';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    /** Fin de periodo (endOfDay) o null si no hay plan/fecha. */
    public function endsAt(User $user): ?Carbon
    {
        return $user->membership_end_date
            ? Carbon::parse($user->membership_end_date)->endOfDay()
            : null;
    }

    /** ¿La membresía vigente da acceso ahora? (plan + dentro de periodo). */
    public function isActive(User $user): bool
    {
        if (! $user->plan) {
            return false;
        }
        $ends = $this->endsAt($user);
        return $ends === null || $ends->isFuture();
    }

    /** Estado normalizado del ciclo de vida. */
    public function status(User $user): string
    {
        if (! $user->plan) {
            return self::STATUS_NONE;
        }
        $active = $this->isActive($user);
        $cancelRequested = $user->membership_cancellation_requested_at !== null;

        if ($active) {
            return $cancelRequested ? self::STATUS_CANCEL_REQUESTED : self::STATUS_ACTIVE;
        }
        // Fuera de periodo: distingue expirado natural de cancelado.
        return $cancelRequested ? self::STATUS_CANCELLED : self::STATUS_EXPIRED;
    }

    /** Días restantes de acceso (0 si ya expiró), o null si no hay fecha. */
    public function daysRemaining(User $user): ?int
    {
        $ends = $this->endsAt($user);
        if ($ends === null) {
            return null;
        }
        return max(0, (int) Carbon::now()->startOfDay()->diffInDays($ends->copy()->startOfDay(), false));
    }

    /** Snapshot para la app/CRM: una sola forma de exponer la membresía. */
    public function snapshot(User $user): array
    {
        $ends = $this->endsAt($user);
        return [
            'status' => $this->status($user),
            'plan_name' => $user->plan,
            'starts_at' => $user->membership_start_date,
            'ends_at' => $ends?->toDateString(),
            'days_remaining' => $this->daysRemaining($user),
            'is_active' => $this->isActive($user),
            'auto_renew' => (bool) $user->membership_auto_renew,
            'cancellation_requested_at' => $user->membership_cancellation_requested_at?->toIso8601String(),
            // Hasta cuándo conserva acceso aunque haya cancelado.
            'access_until' => ($user->membership_cancellation_effective_at
                ? Carbon::parse($user->membership_cancellation_effective_at)
                : $ends?->copy())?->toDateString(),
            'has_recurring_subscription' => ! empty($user->payment_provider_subscription_id),
        ];
    }

    /**
     * Vista previa de la cancelación SIN mutar (paso cancel-request). Devuelve
     * hasta cuándo conservaría el acceso para que la app confirme con datos
     * reales antes de ejecutar.
     */
    public function previewCancellation(User $user): array
    {
        $ends = $this->endsAt($user);
        return [
            'plan_name' => $user->plan,
            'access_until' => $ends?->toDateString(),
            'days_remaining' => $this->daysRemaining($user),
            'already_requested' => $user->membership_cancellation_requested_at !== null,
        ];
    }

    /**
     * Solicita la cancelación de la renovación (paso cancel-confirm). Idempotente:
     * conserva el acceso hasta el fin del periodo vigente. No toca los datos del
     * miembro ni elimina nada.
     */
    public function requestCancellation(User $user, string $by = 'member'): array
    {
        if ($user->membership_cancellation_requested_at === null) {
            $user->membership_auto_renew = false;
            $user->membership_cancellation_requested_at = Carbon::now();
            $user->membership_cancellation_effective_at =
                $user->membership_end_date ?: Carbon::today()->toDateString();
            $user->save();

            $this->audit('cancel_requested', $user, $by);
        }

        return $this->snapshot($user);
    }

    /**
     * Reactiva la renovación: deshace la cancelación (mientras el periodo siga
     * vigente). No extiende la fecha — renovar el periodo expirado se hace con un
     * pago/renovación normal.
     */
    public function reactivate(User $user, string $by = 'member'): array
    {
        if ($user->membership_cancellation_requested_at !== null || ! $user->membership_auto_renew) {
            $user->membership_auto_renew = true;
            $user->membership_cancellation_requested_at = null;
            $user->membership_cancellation_effective_at = null;
            $user->save();

            $this->audit('reactivated', $user, $by);
        }

        return $this->snapshot($user);
    }

    /**
     * Cancelación administrativa (CRM). `immediate=false` (default) programa el
     * fin al término del periodo (igual que el miembro). `immediate=true` corta
     * el acceso hoy (fija membership_end_date a ayer) — usar con criterio.
     */
    public function adminCancel(User $user, bool $immediate = false): array
    {
        $user->membership_auto_renew = false;
        if ($user->membership_cancellation_requested_at === null) {
            $user->membership_cancellation_requested_at = Carbon::now();
        }

        if ($immediate) {
            $effective = Carbon::yesterday()->toDateString();
            $user->membership_end_date = $effective;
            $user->membership_cancellation_effective_at = $effective;
        } else {
            $user->membership_cancellation_effective_at =
                $user->membership_end_date ?: Carbon::today()->toDateString();
        }
        $user->save();

        $this->audit($immediate ? 'admin_cancel_immediate' : 'admin_cancel_scheduled', $user, 'admin');

        return $this->snapshot($user);
    }

    /** Reactivación administrativa (deshace la cancelación). */
    public function adminReactivate(User $user): array
    {
        return $this->reactivate($user, 'admin');
    }

    /**
     * Hook de COBRO RECURRENTE (reservado). Lo invocaría el webhook del proveedor
     * de suscripciones al cobrar un periodo: extiende la membresía. Hoy NO hay
     * proveedor conectado; ver docs/MEMBRESIA_RENOVACION_CANCELACION.md. Se deja
     * la firma estable para enchufar el proveedor sin reescribir el modelo.
     */
    public function applyProviderRenewal(User $user, int $durationDays, ?string $subscriptionId = null): array
    {
        $base = $this->endsAt($user);
        $start = $base && $base->isFuture() ? $base->copy()->startOfDay() : Carbon::today();
        $user->membership_end_date = $start->copy()->addDays(max(1, $durationDays))->toDateString();
        if ($subscriptionId) {
            $user->payment_provider_subscription_id = $subscriptionId;
        }
        // Una renovación efectiva limpia cualquier cancelación pendiente.
        $user->membership_cancellation_requested_at = null;
        $user->membership_cancellation_effective_at = null;
        $user->save();

        $this->audit('provider_renewal', $user, 'provider');

        return $this->snapshot($user);
    }

    private function audit(string $action, User $user, string $by): void
    {
        Log::info('membership.'.$action, [
            'user_id' => $user->id,
            'by' => $by,
            'plan' => $user->plan,
            'ends_at' => $user->membership_end_date,
            'auto_renew' => (bool) $user->membership_auto_renew,
        ]);
    }
}
