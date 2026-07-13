<?php

namespace App\Services\Subscriptions;

use App\Models\Member;
use App\Models\MembershipSubscription;
use App\Models\PaymentTransaction;
use App\Models\SubscriptionEvent;
use App\Models\WompiPaymentSource;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiClient;
use App\Services\Wompi\WompiSignatureService;
use App\Services\Wompi\WompiTransactionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cobro recurrente AUTOMÁTICO de suscripciones vencidas. Lo dispara el comando
 * `subscriptions:charge-due` (scheduler). Reglas:
 *
 *   - Selecciona suscripciones `active`/`past_due` con `next_charge_at <= now`.
 *   - `Cache::lock` por suscripción + `lockForUpdate` → jamás doble cobro.
 *   - El cobro es una PaymentTransaction (subscription_id + billing_period único)
 *     que reutiliza TODO el flujo Wompi existente. La red se llama FUERA de la
 *     transacción DB (no bloquea la BD durante el HTTP).
 *   - Solo APPROVED extiende membresía + avanza el ciclo (vía el HOOK central
 *     `markChargeApproved` en transitionTo). PENDING no extiende (lo cerrará el
 *     webhook/reconciliación existentes). DECLINED/ERROR → reintento escalonado
 *     (WOMPI_RECURRING_RETRY_DAYS) y, al agotarlos, `past_due`.
 *   - NO corta acceso ni toca reglas de acceso al gimnasio.
 *   - INERTE con WOMPI_RECURRING_ENABLED=false (no toca Wompi).
 */
class RecurringBillingService
{
    public function __construct(
        private MembershipSubscriptionService $subs,
        private WompiTransactionService $tx,
        private WompiSignatureService $signature,
        private WompiClient $client,
        private array $cfg,
    ) {
    }

    public static function make(): self
    {
        return new self(
            MembershipSubscriptionService::make(),
            WompiTransactionService::make(),
            WompiSignatureService::fromConfig(),
            WompiClient::fromConfig(),
            (array) config('wompi'),
        );
    }

    /**
     * Procesa los cobros vencidos.
     *
     * @return array{selected:int,approved:int,declined:int,pending:int,past_due:int,skipped:int}
     */
    public function chargeDue(int $limit = 100): array
    {
        $stats = ['selected' => 0, 'approved' => 0, 'declined' => 0, 'pending' => 0, 'past_due' => 0, 'skipped' => 0];

        if (! $this->recurringEnabled()) {
            return $stats;
        }

        $subs = MembershipSubscription::query()
            ->whereIn('status', MembershipSubscription::CHARGEABLE_STATUSES)
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', now())
            ->orderBy('next_charge_at')
            ->limit($limit)
            ->get();

        foreach ($subs as $sub) {
            $stats['selected']++;
            $result = $this->chargeOne($sub);
            $stats[$result] = ($stats[$result] ?? 0) + 1;
        }

        if ($stats['selected'] > 0) {
            Log::info('subscriptions.charge_due.run', $stats);
        }

        return $stats;
    }

    /**
     * Cobra UNA suscripción vencida (idempotente, con lock distribuido). El
     * desenlace (APPROVED → renovar, fallo → reintento/past_due) lo cierra el HOOK
     * central en `WompiTransactionService::transitionTo`; aquí solo se prepara y
     * envía el cobro.
     *
     * @param  bool  $force  reintento manual (admin): omite el chequeo de vencimiento
     *                       (permite active/past_due; nunca cancelled/expired).
     * @return 'approved'|'declined'|'pending'|'past_due'|'skipped'
     */
    public function chargeOne(MembershipSubscription $sub, bool $force = false): string
    {
        $lock = Cache::lock('subscription:charge:'.$sub->id, 30);
        if (! $lock->get()) {
            return 'skipped'; // otra corrida la está procesando.
        }

        try {
            // 1) Crear el cobro del periodo (idempotente) en una transacción CORTA.
            $prepared = DB::transaction(function () use ($sub, $force) {
                /** @var MembershipSubscription|null $fresh */
                $fresh = MembershipSubscription::lockForUpdate()->find($sub->id);
                if (! $fresh) {
                    return null;
                }
                if ($force) {
                    // Reintento manual: no cobrar canceladas/expiradas.
                    if (in_array($fresh->status, [
                        MembershipSubscription::STATUS_CANCELLED, MembershipSubscription::STATUS_EXPIRED,
                    ], true)) {
                        return null;
                    }
                } elseif (! $fresh->isChargeable()
                    || ! $fresh->next_charge_at || $fresh->next_charge_at->gt(now())) {
                    return null;
                }
                return $this->prepareDueCharge($fresh);
            });

            if (! $prepared) {
                return 'skipped';
            }
            [$fresh, $charge, $created] = $prepared;

            if (! $created) {
                // Ya existía el cobro de este intento (doble ejecución) → no recobrar.
                return 'skipped';
            }

            // 2) Firmar + enviar a Wompi FUERA de la transacción DB.
            $cents     = (int) round((float) $fresh->price_snapshot * 100);
            $currency  = strtoupper((string) $fresh->currency);
            $signature = $this->signature->integritySignature($charge->reference, $cents, $currency);

            $this->tx->transitionTo($charge, PaymentStateMachine::PENDING);

            $res = $this->client->chargeWithPaymentSource([
                'payment_source_id' => $charge->wompi_payment_source_id,
                'amount_in_cents'   => $cents,
                'currency'          => $currency,
                'reference'         => $charge->reference,
                'customer_email'    => $charge->customer_email,
                'signature'         => $signature,
                'recurrent'         => true,
                'payment_method'    => ['installments' => 1],
            ], $charge->idempotency_key);

            // markError transiciona a ERROR → el HOOK central (markChargeFailed)
            // aplica reintento/past_due. No se maneja el fallo aquí (evita doble).
            if (! $res['ok']) {
                $this->tx->markError($charge, (string) ($res['error'] ?? 'Cobro recurrente rechazado.'),
                    ['processor_response_code' => $res['error_code'] ?? null]);
                return $this->outcome($fresh, $charge);
            }

            $wt = is_array($res['data']) ? $res['data'] : [];
            if (empty($wt['id'])) {
                $this->tx->markError($charge, 'Respuesta inválida de la pasarela en el cobro recurrente.');
                return $this->outcome($fresh, $charge);
            }

            // Aplica el estado real. APPROVED → activador extiende membresía + HOOK
            // promueve la suscripción; fallo → HOOK programa reintento/past_due.
            $charge = $this->tx->applyWompiTransaction($charge, $wt);

            return $this->outcome($fresh, $charge);
        } finally {
            $lock->release();
        }
    }

    /**
     * Reintento MANUAL (admin) de una suscripción, idempotente. No cobra dos veces
     * el mismo billing_period (mismo intento → mismo cargo). No re-cobra canceladas.
     *
     * @return 'approved'|'declined'|'pending'|'past_due'|'skipped'
     */
    public function retryNow(MembershipSubscription $sub): string
    {
        if (! $this->recurringEnabled()) {
            return 'skipped';
        }
        return $this->chargeOne($sub, force: true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Crea (idempotente) la PaymentTransaction del cobro vencido. billing_period
     * codifica el PERIODO + el intento (failed_attempts) → un reintento es un cobro
     * nuevo, pero dos corridas del MISMO intento no cobran dos veces.
     *
     * @return array{0:MembershipSubscription,1:PaymentTransaction,2:bool}|null
     */
    private function prepareDueCharge(MembershipSubscription $sub): ?array
    {
        $source = $sub->payment_source_id ? WompiPaymentSource::find($sub->payment_source_id) : null;
        if (! $source || ! $source->isChargeable()) {
            $this->subs->logEvent($sub, SubscriptionEvent::TYPE_CHARGE_ERROR, SubscriptionEvent::ACTOR_SYSTEM,
                message: 'no chargeable payment source');
            return null;
        }

        $periodKey = ($sub->current_period_end
            ? Carbon::parse($sub->current_period_end)
            : Carbon::parse($sub->next_charge_at))->toDateString();
        $attempt = (int) $sub->failed_attempts;
        $billingPeriod = $sub->uuid.':'.$periodKey.':a'.$attempt;

        $email = $source->customer_email ?: (string) optional(Member::find($sub->member_id))->email;

        [$charge, $created] = $this->subs->createChargeForPeriod($sub, $source, $billingPeriod, $email);

        return [$sub, $charge, $created];
    }

    /**
     * Traduce el desenlace del cobro a un código de resumen, leyendo el estado ya
     * sellado por el HOOK central (markChargeApproved / markChargeFailed).
     *
     * @return 'approved'|'declined'|'pending'|'past_due'
     */
    private function outcome(MembershipSubscription $sub, PaymentTransaction $charge): string
    {
        $status = $charge->fresh()->status;

        if ($status === PaymentStateMachine::APPROVED) {
            return 'approved';
        }
        if (in_array($status, [
            PaymentStateMachine::DECLINED, PaymentStateMachine::VOIDED,
            PaymentStateMachine::ERROR, PaymentStateMachine::EXPIRED,
        ], true)) {
            return $sub->fresh()->status === MembershipSubscription::STATUS_PAST_DUE ? 'past_due' : 'declined';
        }
        // PENDING: lo resolverá el webhook/reconciliación (y el HOOK cerrará ahí).
        return 'pending';
    }

    private function recurringEnabled(): bool
    {
        return (bool) ($this->cfg['recurring']['enabled'] ?? false);
    }
}
