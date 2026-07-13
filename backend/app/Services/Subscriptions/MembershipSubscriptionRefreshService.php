<?php

namespace App\Services\Subscriptions;

use App\Models\MembershipSubscription;
use App\Models\PaymentTransaction;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiReconciliationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Refresh SEGURO del estado de una suscripción para `GET /current?refresh=1`.
 *
 * Reconcilia SOLO el último cobro EN VUELO de la suscripción reutilizando el flujo
 * existente (WompiReconciliationService → WompiTransactionService → hooks centrales
 * markChargeApproved/markChargeFailed). NO crea transacciones nuevas, NO duplica
 * activaciones ni retries (todo pasa por los puntos idempotentes ya existentes).
 *
 * Anti-concurrencia + anti-spam a Wompi:
 *   - Cache::lock por suscripción (un refresh a la vez).
 *   - Throttle corto (cache) para no consultar Wompi en cada request.
 *   - Un fallo temporal de Wompi NUNCA rompe la respuesta: se registra y se
 *     devuelve el estado actual.
 */
class MembershipSubscriptionRefreshService
{
    public function __construct(private WompiReconciliationService $reconciler)
    {
    }

    public static function make(): self
    {
        return new self(WompiReconciliationService::make());
    }

    private const THROTTLE_TTL = 15; // segundos entre reconciliaciones por suscripción
    private const LOCK_TTL = 10;

    /** Devuelve la suscripción fresca, reconciliando el cobro en vuelo si aplica. */
    public function refresh(MembershipSubscription $sub): MembershipSubscription
    {
        $tx = $this->latestInFlightCharge($sub);

        // Nada que reconciliar (estado ya asentado o sin cobro en vuelo).
        if ($tx === null) {
            return $sub->fresh();
        }

        // Throttle: si se reconcilió hace poco, devolver estado actual sin llamar Wompi.
        $throttleKey = "subscription-refresh-throttle:{$sub->id}";
        if (Cache::has($throttleKey)) {
            return $sub->fresh();
        }

        // Lock: un solo refresh simultáneo por suscripción. Si no se obtiene, no error.
        $lock = Cache::lock("subscription-refresh:{$sub->id}", self::LOCK_TTL);
        if (! $lock->get()) {
            return $sub->fresh();
        }

        try {
            Cache::put($throttleKey, 1, now()->addSeconds(self::THROTTLE_TTL));
            // Reutiliza la reconciliación existente (idempotente; los hooks centrales
            // promueven/degradan la suscripción una sola vez). Nunca lanza al caller.
            $this->reconciler->reconcileOne($tx);
        } catch (\Throwable $e) {
            Log::warning('subscriptions.refresh.reconcile_failed', [
                'subscription_id' => $sub->id,
                'error'           => mb_substr($e->getMessage(), 0, 200),
            ]);
        } finally {
            $lock->release();
        }

        return $sub->fresh();
    }

    /** Último cobro EN VUELO (pending/processing/requires_action) con id de Wompi. */
    private function latestInFlightCharge(MembershipSubscription $sub): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('subscription_id', $sub->id)
            ->where('provider', 'wompi')
            ->whereIn('status', PaymentStateMachine::IN_FLIGHT)
            ->whereNotNull('wompi_transaction_id')
            ->latest('id')
            ->first();
    }
}
