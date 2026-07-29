<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SubscriptionException;
use App\Models\Member;
use App\Models\MembershipSubscription;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Models\WompiPaymentSource;
use App\Services\Billing\Money;
use App\Services\Billing\PricingException;
use App\Services\Billing\PricingService;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiClient;
use App\Services\Wompi\WompiSignatureService;
use App\Services\Wompi\WompiTransactionService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Suscripciones de membresía con pago automático. Orquesta:
 *   1) Congelar el snapshot AUTORITATIVO del plan (precio/moneda/duración).
 *   2) Garantizar UNA sola suscripción viva por miembro (lockForUpdate + índice
 *      parcial Postgres del Bloque 1).
 *   3) PRIMER COBRO reutilizando el flujo Wompi existente: crea una
 *      PaymentTransaction (con subscription_id + billing_period) y la cobra con
 *      `payment_source_id`. Al aprobarse, la membresía la extiende el ACTIVADOR
 *      COMPARTIDO existente (PaymentMembershipActivator, vía applyWompiTransaction).
 *
 * Reglas duras:
 *   - Monto autoritativo del backend; Flutter jamás lo define.
 *   - Solo APPROVED activa la suscripción y extiende membresía. PENDING no extiende.
 *   - Idempotencia anti doble cobro por (subscription_id, billing_period) único.
 *   - NADA de retry aquí (eso es Bloque 4).
 *   - INERTE con WOMPI_RECURRING_ENABLED=false (guard lanza excepción antes de red).
 */
class MembershipSubscriptionService
{
    public function __construct(
        private WompiPaymentSourceService $sources,
        private WompiTransactionService $tx,
        private WompiSignatureService $signature,
        private WompiClient $client,
        private array $cfg,
    ) {}

    public static function make(): self
    {
        return new self(
            WompiPaymentSourceService::make(),
            WompiTransactionService::make(),
            WompiSignatureService::fromConfig(),
            WompiClient::fromConfig(),
            (array) config('wompi'),
        );
    }

    /**
     * Crea (o reutiliza) la suscripción del miembro y ejecuta el PRIMER COBRO.
     *
     * @param  array  $data  {
     *                       member_id, user_id, plan_id,
     *                       payment_source_id?  // fuente existente, o…
     *                       type?, token?, card_brand?, card_last_four?, exp_month?, exp_year?,
     *                       customer_email? | customer:{ email, ... }
     *                       }
     * @return array{subscription: MembershipSubscription, charge: ?PaymentTransaction, status: string}
     */
    public function subscribeWithFirstCharge(array $data, ?string $ip = null, ?string $ua = null): array
    {
        $this->assertRecurringEnabled();

        $memberId = $data['member_id'] ?? null;
        $userId = $data['user_id'] ?? null;

        $plan = ! empty($data['plan_id']) ? Plan::find($data['plan_id']) : null;
        if (! $plan || ! $plan->active || (float) $plan->price <= 0 || (int) $plan->duration_days <= 0) {
            throw SubscriptionException::invalidPlan();
        }

        // 1) Resolver la fuente de pago (existente o nueva por token). Valida método.
        $source = $this->resolveSource($data);
        $method = $source->type === WompiPaymentSource::TYPE_NEQUI ? 'nequi' : 'card';
        if (! $this->sources->isMethodAllowed($method)) {
            throw SubscriptionException::unsupportedMethod($method);
        }

        // 2) Suscripción viva única por miembro (idempotencia con lock).
        [$sub, $shouldCharge] = DB::transaction(function () use ($memberId, $userId, $plan, $source) {
            $existing = MembershipSubscription::query()
                ->where('member_id', $memberId)
                ->whereIn('status', MembershipSubscription::LIVE_STATUSES)
                ->lockForUpdate()
                ->first();

            // Ya hay una suscripción activa/past_due/pausada → idempotente, no cobrar.
            if ($existing && $existing->status !== MembershipSubscription::STATUS_PENDING_FIRST_PAYMENT) {
                return [$existing, false];
            }

            $sub = $existing ?: new MembershipSubscription([
                'uuid' => (string) Str::uuid(),
                'member_id' => $memberId,
                'user_id' => $userId,
                'status' => MembershipSubscription::STATUS_PENDING_FIRST_PAYMENT,
            ]);
            $wasNew = ! $sub->exists;

            // Snapshot AUTORITATIVO congelado desde backend. Con Pricing V2 se
            // congela también el tratamiento tributario (tarifa + modo), no solo
            // el precio: así una renovación futura cobra exactamente lo que se
            // autorizó aunque el catálogo cambie de tarifa entretanto.
            $sub->fill(array_merge([
                'plan_id' => $plan->id,
                'payment_source_id' => $source->id,
                'price_snapshot' => (float) $plan->price,
                'currency' => strtoupper((string) ($this->cfg['currency'] ?? 'COP')),
                'interval_days' => (int) $plan->duration_days,
                'method' => $source->type === WompiPaymentSource::TYPE_NEQUI ? 'nequi' : 'card',
            ], $this->fiscalSnapshot($plan)));
            $sub->save();

            if ($wasNew) {
                $this->logEvent($sub, SubscriptionEvent::TYPE_CREATED, SubscriptionEvent::ACTOR_MEMBER);
            }
            $this->logEvent($sub, SubscriptionEvent::TYPE_SOURCE_ATTACHED, SubscriptionEvent::ACTOR_SYSTEM, context: ['source_id' => $source->id]);

            return [$sub, true];
        });

        if (! $shouldCharge) {
            return ['subscription' => $sub->fresh(), 'charge' => null, 'status' => 'already_subscribed'];
        }

        // Fuente no disponible (pendiente 3DS / declinada / error) → NO cobrar.
        if (! $source->isChargeable()) {
            $this->logEvent($sub, SubscriptionEvent::TYPE_CHARGE_ERROR, SubscriptionEvent::ACTOR_SYSTEM,
                message: 'payment source not available: '.$source->status);

            return ['subscription' => $sub->fresh(), 'charge' => null, 'status' => 'payment_source_unavailable'];
        }

        // 3) Primer cobro (reutiliza el flujo Wompi existente).
        $charge = $this->chargeFirst($sub, $source, $data);

        return ['subscription' => $sub->fresh(), 'charge' => $charge, 'status' => $charge->status];
    }

    // ── Snapshot fiscal de la suscripción ───────────────────────────────────

    /**
     * Congela el tratamiento tributario del plan en la suscripción.
     *
     * Devuelve [] si Pricing V2 está apagado o el plan no es cotizable: la
     * suscripción queda como legacy (cobra `price_snapshot`) y nada cambia.
     *
     * @return array<string,mixed>
     */
    private function fiscalSnapshot(Plan $plan): array
    {
        if (! config('billing.pricing.v2_enabled', false)) {
            return [];
        }

        try {
            $quote = app(PricingService::class)->quoteForPlan($plan);
        } catch (PricingException $e) {
            Log::warning('Suscripción: sin cotización V2, se congela solo el precio legacy', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return [
            'base_snapshot' => $quote->baseAmount->toDatabase(),
            'tax_amount_snapshot' => $quote->taxAmount->toDatabase(),
            'gross_snapshot' => $quote->grossAmount->toDatabase(),
            'tax_rate_id_snapshot' => $quote->taxRateId,
            'tax_rate_snapshot' => $quote->taxRateString(),
            'pricing_mode_snapshot' => $quote->pricingMode->value,
            'pricing_rules_version' => $quote->pricingRulesVersion,
            'priced_at' => $quote->pricedAt,
        ];
    }

    /**
     * Copia el snapshot de la suscripción a cada cobro (primer cobro y
     * renovaciones), para que el pago y la factura resultantes lo hereden.
     *
     * @return array<string,mixed>
     */
    public static function chargeSnapshotFrom(MembershipSubscription $sub): array
    {
        if (! $sub->hasFinancialSnapshot()) {
            return [];
        }

        return [
            'base_amount' => $sub->base_snapshot,
            'tax_amount' => $sub->tax_amount_snapshot,
            'gross_amount' => $sub->gross_snapshot,
            'discount_amount' => '0.00',
            'tax_rate_id' => $sub->tax_rate_id_snapshot,
            'tax_rate' => $sub->tax_rate_snapshot,
            'pricing_mode' => $sub->pricing_mode_snapshot,
            'pricing_rules_version' => $sub->pricing_rules_version,
            'priced_at' => $sub->priced_at,
        ];
    }

    // ── Primer cobro ────────────────────────────────────────────────────────

    private function chargeFirst(MembershipSubscription $sub, WompiPaymentSource $source, array $data): PaymentTransaction
    {
        // El primer cobro pertenece al periodo inicial (attempt 0).
        $billingPeriod = $sub->uuid.':'.Carbon::today()->toDateString().':a0';
        $email = $this->resolveEmail($data, $sub);

        // Idempotencia dura anti doble cobro: (subscription_id, billing_period) único.
        [$charge, $created] = $this->createChargeForPeriod($sub, $source, $billingPeriod, $email, 'Primer cobro membresía Iron Body');
        if (! $created) {
            // Ya hubo un intento para este periodo → NO se recobra.
            return $charge;
        }

        // Bruto congelado (base + IVA con Pricing V2; price_snapshot en legacy).
        $cents = Money::fromAmount($sub->chargeableGrossAmount())->toWompiCents();
        $currency = strtoupper((string) $sub->currency);
        $signature = $this->signature->integritySignature($charge->reference, $cents, $currency);

        // Refleja "enviado a la pasarela" antes de enviar.
        $this->tx->transitionTo($charge, PaymentStateMachine::PENDING);

        $res = $this->client->chargeWithPaymentSource([
            'payment_source_id' => $source->wompi_payment_source_id,
            'amount_in_cents' => $cents,
            'currency' => $currency,
            'reference' => $charge->reference,
            'customer_email' => $email,
            'signature' => $signature,
            'recurrent' => true,
            'payment_method' => ['installments' => 1],
        ], $charge->idempotency_key);

        // Fallo de transporte / respuesta inválida → markError transiciona a ERROR,
        // y el HOOK central (transitionTo → markChargeFailed) registra el evento y,
        // como la suscripción sigue pending_first_payment, NO programa reintento
        // (el primer cobro fallido lo reintenta el usuario; el scheduler no lo toca).
        if (! $res['ok']) {
            $this->tx->markError($charge, (string) ($res['error'] ?? 'No se pudo procesar el primer cobro.'),
                ['processor_response_code' => $res['error_code'] ?? null]);
            Log::info('subscriptions.first_charge.failed', [
                'subscription_id' => $sub->id,
                'error_code' => $res['error_code'] ?? null,
            ]);

            return $charge->fresh();
        }

        $wt = is_array($res['data']) ? $res['data'] : [];
        if (empty($wt['id'])) {
            $this->tx->markError($charge, 'Respuesta inválida de la pasarela. No se realizó ningún cobro.');

            return $charge->fresh();
        }

        // Aplica el estado REAL reutilizando el flujo existente. Si APPROVED, el
        // ACTIVADOR compartido extiende la membresía y el HOOK central promueve la
        // suscripción; si DECLINED/ERROR, el HOOK central registra el fallo. Todo
        // el desenlace se maneja centralizado en transitionTo (una sola vez).
        $charge = $this->tx->applyWompiTransaction($charge, $wt);

        return $charge->fresh();
    }

    /**
     * Crea la PaymentTransaction de un cobro recurrente (idempotente por
     * (subscription_id, billing_period)). Reutilizable por el primer cobro y por
     * el cobro recurrente del scheduler (RecurringBillingService).
     *
     * @return array{0: PaymentTransaction, 1: bool} [charge, created]
     */
    public function createChargeForPeriod(
        MembershipSubscription $sub,
        WompiPaymentSource $source,
        string $billingPeriod,
        string $email,
        string $description = 'Renovación automática Iron Body',
    ): array {
        return DB::transaction(function () use ($sub, $source, $billingPeriod, $email, $description) {
            $existing = PaymentTransaction::query()
                ->where('subscription_id', $sub->id)
                ->where('billing_period', $billingPeriod)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return [$existing, false];
            }

            $reference = $this->generateReference();
            while (PaymentTransaction::where('reference', $reference)->exists()) {
                $reference = $this->generateReference();
            }

            try {
                $charge = PaymentTransaction::create(array_merge([
                    'uuid' => (string) Str::uuid(),
                    'reference' => $reference,
                    'idempotency_key' => (string) Str::uuid(),
                    'member_id' => $sub->member_id,
                    'user_id' => $sub->user_id,
                    'plan_id' => $sub->plan_id,
                    // Bruto congelado de la suscripción, NUNCA el precio actual.
                    'amount' => $sub->chargeableGrossAmount(),
                    'currency' => strtoupper((string) $sub->currency),
                    'status' => PaymentStateMachine::CREATED,
                    'provider' => 'wompi',
                    'environment' => $this->cfg['env'] ?? 'sandbox',
                    'method' => 'card',
                    'description' => $description,
                    'customer_email' => $email,
                    'retry_count' => 0,
                    // Campos recurrentes (migración Bloque 1; modelo ampliado mínimamente).
                    'subscription_id' => $sub->id,
                    'billing_period' => $billingPeriod,
                    'is_recurring' => true,
                    'wompi_payment_source_id' => $source->wompi_payment_source_id,
                ], self::chargeSnapshotFrom($sub)));

                return [$charge, true];
            } catch (QueryException $e) {
                $found = PaymentTransaction::where('subscription_id', $sub->id)
                    ->where('billing_period', $billingPeriod)->first();
                if ($found) {
                    return [$found, false];
                }
                throw $e;
            }
        });
    }

    /**
     * PUNTO CENTRAL de cierre de un cobro recurrente APROBADO. Lo invoca
     * `WompiTransactionService::transitionTo` al ENTRAR en approved (una sola vez,
     * por webhook, reconciliación o cobro directo). Promueve la suscripción a
     * `active`, alinea el próximo cobro al vencimiento REAL de la membresía (que el
     * activador compartido acaba de extender) y resetea los reintentos.
     *
     * Idempotente: si este mismo cargo ya cerró la suscripción, no hace nada.
     * NUNCA extiende membresía (eso es del activador); solo sincroniza la suscripción.
     */
    public function markChargeApproved(PaymentTransaction $charge): void
    {
        if (! $charge->subscription_id) {
            return;
        }

        DB::transaction(function () use ($charge) {
            /** @var MembershipSubscription|null $sub */
            $sub = MembershipSubscription::lockForUpdate()->find($charge->subscription_id);
            if (! $sub) {
                return;
            }

            // Idempotencia: este cargo ya promovió la suscripción → salir.
            if ($sub->last_charge_reference === $charge->reference
                && $sub->status === MembershipSubscription::STATUS_ACTIVE) {
                return;
            }

            $wasFirst = $sub->status === MembershipSubscription::STATUS_PENDING_FIRST_PAYMENT;

            $user = $sub->user_id ? User::find($sub->user_id) : null;
            $end = $user && $user->membership_end_date
                ? Carbon::parse($user->membership_end_date)->startOfDay()
                : Carbon::today()->addDays((int) $sub->interval_days);

            $sub->forceFill([
                'status' => MembershipSubscription::STATUS_ACTIVE,
                'current_period_start' => Carbon::today()->toDateString(),
                'current_period_end' => $end->toDateString(),
                'next_charge_at' => $end,
                'last_charged_at' => now(),
                'last_charge_reference' => $charge->reference,
                'failed_attempts' => 0,
                'retry_stage' => 0,
            ])->save();

            if ($sub->payment_source_id) {
                WompiPaymentSource::whereKey($sub->payment_source_id)->update([
                    'status' => WompiPaymentSource::STATUS_AVAILABLE,
                    'last_used_at' => now(),
                ]);
            }

            $this->logEvent(
                $sub,
                $wasFirst ? SubscriptionEvent::TYPE_FIRST_CHARGE_APPROVED : SubscriptionEvent::TYPE_CHARGE_APPROVED,
                SubscriptionEvent::ACTOR_SYSTEM,
                $charge->reference,
                (float) $charge->amount,
            );
        });
    }

    /**
     * PUNTO CENTRAL de cierre de un cobro recurrente FALLIDO (DECLINED/ERROR/
     * VOIDED/EXPIRED). Lo invoca `WompiTransactionService::transitionTo` al ENTRAR
     * en un estado terminal de fallo (una vez), venga del cobro directo del
     * scheduler o del webhook/reconciliación asíncronos.
     *
     * - Suscripción ACTIVE/PAST_DUE (renovación): aplica la escalera de reintentos
     *   (WOMPI_RECURRING_RETRY_DAYS) y, al agotarla, marca past_due. NO corta acceso
     *   ni toca membership_end_date.
     * - Suscripción pending_first_payment (primer cobro): solo registra el fallo y
     *   la deja pending_first_payment (el usuario reintenta; el scheduler no la toca).
     *
     * Idempotente: si este mismo cargo ya fue procesado, no hace nada (evita doble
     * incremento ante webhook/reconciliación duplicados).
     */
    public function markChargeFailed(PaymentTransaction $charge): void
    {
        if (! $charge->subscription_id) {
            return;
        }

        DB::transaction(function () use ($charge) {
            /** @var MembershipSubscription|null $sub */
            $sub = MembershipSubscription::lockForUpdate()->find($charge->subscription_id);
            if (! $sub) {
                return;
            }

            // Idempotencia: este cargo ya cerró (aprobó o falló) la suscripción.
            if ($sub->last_charge_reference === $charge->reference) {
                return;
            }

            // Primer cobro fallido → se queda pending_first_payment (sin reintento auto).
            if (! in_array($sub->status, MembershipSubscription::CHARGEABLE_STATUSES, true)) {
                $sub->forceFill(['last_charge_reference' => $charge->reference])->save();
                $this->logEvent($sub, SubscriptionEvent::TYPE_CHARGE_DECLINED, SubscriptionEvent::ACTOR_SYSTEM,
                    $charge->reference, (float) $charge->amount);

                return;
            }

            // Renovación fallida → escalera de reintentos / past_due.
            $retryDays = array_values((array) ($this->cfg['recurring']['retry_days'] ?? [1, 3]));
            $maxRetries = (int) ($this->cfg['recurring']['max_retries'] ?? 3);

            $attempt = (int) $sub->failed_attempts + 1;
            $sub->failed_attempts = $attempt;
            $sub->last_charge_reference = $charge->reference;

            $idx = $attempt - 1;
            if ($idx < count($retryDays) && $attempt <= $maxRetries) {
                $inDays = (int) $retryDays[$idx];
                $sub->retry_stage = $attempt;
                $sub->next_charge_at = now()->addDays($inDays);
                $sub->save();
                $this->logEvent($sub, SubscriptionEvent::TYPE_RETRY_SCHEDULED, SubscriptionEvent::ACTOR_SYSTEM,
                    $charge->reference, (float) $charge->amount, context: ['in_days' => $inDays, 'attempt' => $attempt]);

                return;
            }

            // Agotados los reintentos → past_due. NO se corta acceso (regla de negocio).
            $sub->status = MembershipSubscription::STATUS_PAST_DUE;
            $sub->next_charge_at = null; // deja de intentar automáticamente.
            $sub->save();
            $this->logEvent($sub, SubscriptionEvent::TYPE_PAST_DUE, SubscriptionEvent::ACTOR_SYSTEM,
                $charge->reference, (float) $charge->amount, context: ['attempts' => $attempt]);
        });
    }

    /**
     * Cancela la renovación automática. NO borra histórico, NO corta la membresía
     * vigente (esta corre hasta `membership_end_date`), solo impide próximos cobros.
     *
     * @param  string  $actor  member|admin|system (auditoría).
     */
    public function cancel(MembershipSubscription $sub, string $actor = SubscriptionEvent::ACTOR_MEMBER, ?string $reason = null): MembershipSubscription
    {
        return DB::transaction(function () use ($sub, $actor, $reason) {
            /** @var MembershipSubscription $fresh */
            $fresh = MembershipSubscription::lockForUpdate()->find($sub->id);

            // Idempotente: ya cancelada → no re-registra.
            if ($fresh->status === MembershipSubscription::STATUS_CANCELLED) {
                return $fresh;
            }

            $fresh->forceFill([
                'status' => MembershipSubscription::STATUS_CANCELLED,
                'cancel_at_period_end' => true,
                'cancelled_at' => now(),
                'cancelled_by' => $actor,
                'cancel_reason' => $reason ? mb_substr($reason, 0, 200) : null,
                'next_charge_at' => null, // impide próximos cobros.
            ])->save();

            $this->logEvent($fresh, SubscriptionEvent::TYPE_CANCELLED, $actor, context: ['reason' => $reason]);

            return $fresh;
        });
    }

    /**
     * Reemplaza la fuente de pago de la suscripción por una NUEVA (solo tarjeta).
     * Crea la fuente segura, la vincula, REVOCA la anterior (conservando el
     * histórico: no se borra el registro) y registra el evento. NO cobra: el
     * cobro (retry controlado si estaba past_due) lo decide el llamador
     * (controlador) usando RecurringBillingService::retryNow.
     *
     * @param  array  $data  { type, token, card_brand?, card_last_four?, exp_month?, exp_year? }
     * @return WompiPaymentSource la nueva fuente (status available|declined|...).
     */
    public function replacePaymentSource(MembershipSubscription $sub, array $data): WompiPaymentSource
    {
        $this->assertRecurringEnabled();

        $email = $this->resolveEmail($data, $sub);

        // Crea la nueva fuente (valida método: solo tarjeta; rechaza PSE/etc).
        $source = $this->sources->createForMember([
            'member_id' => $sub->member_id,
            'user_id' => $sub->user_id,
            'type' => $data['type'] ?? 'CARD',
            'token' => $data['token'] ?? '',
            'customer_email' => $email,
            'card_brand' => $data['card_brand'] ?? null,
            'card_last_four' => $data['card_last_four'] ?? null,
            'exp_month' => $data['exp_month'] ?? null,
            'exp_year' => $data['exp_year'] ?? null,
        ]);

        // Solo se vincula si la fuente quedó disponible (no declinada/pendiente 3DS).
        if (! $source->isChargeable()) {
            $this->logEvent($sub, SubscriptionEvent::TYPE_CHARGE_ERROR, SubscriptionEvent::ACTOR_MEMBER,
                message: 'nueva fuente no disponible: '.$source->status);

            return $source;
        }

        DB::transaction(function () use ($sub, $source) {
            /** @var MembershipSubscription $fresh */
            $fresh = MembershipSubscription::lockForUpdate()->find($sub->id);
            $oldId = $fresh->payment_source_id;

            // Revoca la anterior (histórico conservado: solo cambia estado).
            if ($oldId && $oldId !== $source->id) {
                WompiPaymentSource::whereKey($oldId)->update([
                    'status' => WompiPaymentSource::STATUS_REVOKED,
                    'revoked_at' => now(),
                ]);
            }

            $fresh->forceFill(['payment_source_id' => $source->id])->save();
            $this->logEvent($fresh, SubscriptionEvent::TYPE_SOURCE_ATTACHED, SubscriptionEvent::ACTOR_MEMBER,
                context: ['source_id' => $source->id, 'replaced' => $oldId]);
        });

        return $source;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveSource(array $data): WompiPaymentSource
    {
        if (! empty($data['payment_source_id'])) {
            $src = WompiPaymentSource::find($data['payment_source_id']);
            if (! $src) {
                throw SubscriptionException::paymentSourceUnavailable('Fuente de pago no encontrada.');
            }

            return $src;
        }

        return $this->sources->createForMember([
            'member_id' => $data['member_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'type' => $data['type'] ?? 'CARD',
            'token' => $data['token'] ?? '',
            'customer_email' => $this->resolveEmail($data, null),
            'card_brand' => $data['card_brand'] ?? null,
            'card_last_four' => $data['card_last_four'] ?? null,
            'exp_month' => $data['exp_month'] ?? null,
            'exp_year' => $data['exp_year'] ?? null,
        ]);
    }

    private function resolveEmail(array $data, ?MembershipSubscription $sub): string
    {
        $email = $data['customer_email'] ?? ($data['customer']['email'] ?? null);
        if (! $email && $sub && $sub->member_id) {
            $email = optional(Member::find($sub->member_id))->email;
        }

        return trim((string) $email);
    }

    /** Registra un evento de auditoría de la suscripción (best-effort, sin secretos). */
    public function logEvent(
        MembershipSubscription $sub,
        string $type,
        string $actor = SubscriptionEvent::ACTOR_SYSTEM,
        ?string $reference = null,
        ?float $amount = null,
        ?string $message = null,
        array $context = [],
    ): void {
        try {
            SubscriptionEvent::create([
                'uuid' => (string) Str::uuid(),
                'subscription_id' => $sub->id,
                'member_id' => $sub->member_id,
                'type' => $type,
                'actor' => $actor,
                'reference' => $reference,
                'amount' => $amount,
                'message' => $message ? mb_substr($message, 0, 200) : null,
                'context' => $context !== [] ? $context : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('subscriptions.event.record_failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    private function assertRecurringEnabled(): void
    {
        if (! (bool) ($this->cfg['recurring']['enabled'] ?? false)) {
            throw SubscriptionException::recurringDisabled();
        }
    }

    private function generateReference(): string
    {
        return 'IRON-SUB-'.now()->format('Ymd').'-'
            .strtoupper(Str::random(6)).'-'.substr((string) time(), -5);
    }
}
