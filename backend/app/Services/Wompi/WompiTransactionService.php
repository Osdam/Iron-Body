<?php

namespace App\Services\Wompi;

use App\Models\Member;
use App\Models\PaymentConsent;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Billing\Money;
use App\Services\Billing\PriceQuote;
use App\Services\Billing\PricingException;
use App\Services\Billing\PricingService;
use App\Services\NotificationService;
use App\Services\Payments\PaymentMembershipActivator;
use App\Services\RealtimeEvents;
use App\Services\Subscriptions\MembershipSubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Núcleo transaccional de Wompi: creación/idempotencia, transición de estados
 * (con lockForUpdate y la máquina de estados PURA) y activación de membresía al
 * aprobarse (reutilizando el ACTIVADOR COMPARTIDO de pagos del CRM).
 *
 * Reglas no negociables:
 *   - Monto AUTORITATIVO del backend. Con Pricing V2 sale de PricingService
 *     (base + IVA según el pricing_mode del plan) y se congela en la propia
 *     transacción; sin V2 sale de Plan::price. Flutter nunca define el precio.
 *   - `approved` activa la membresía UNA sola vez (idempotencia por reference).
 *   - Un webhook/reconciliación duplicado no reactiva ni degrada un terminal.
 *   - Nada de PAN/CVC/OTP/secretos aquí.
 */
class WompiTransactionService
{
    public function __construct(
        private PaymentStateMachine $sm,
        private array $cfg,
    ) {}

    public static function make(): self
    {
        return new self(new PaymentStateMachine, (array) config('wompi'));
    }

    /**
     * Crea una transacción Wompi nueva o reutiliza una vigente (anti doble pago
     * + idempotencia). Misma estrategia atómica que el flujo legado.
     */
    public function createOrReuse(array $data): PaymentTransaction
    {
        $orderId = $data['order_id'] ?? null;
        $idem = $data['idempotency_key'] ?? null;

        return DB::transaction(function () use ($data, $orderId, $idem) {
            // 1) Orden ya aprobada → no crear otro pago.
            if ($orderId !== null) {
                $approved = PaymentTransaction::where('order_id', $orderId)
                    ->where('provider', 'wompi')
                    ->where('status', PaymentStateMachine::APPROVED)
                    ->lockForUpdate()->first();
                if ($approved) {
                    return $approved;
                }
                // 2) Intento en curso para la orden → reutilizar.
                $inFlight = PaymentTransaction::where('order_id', $orderId)
                    ->where('provider', 'wompi')
                    ->whereIn('status', PaymentStateMachine::IN_FLIGHT)
                    ->latest()->lockForUpdate()->first();
                if ($inFlight) {
                    return $inFlight;
                }
            }

            // 3) Idempotencia real por idempotency_key.
            if (! empty($idem)) {
                $existing = PaymentTransaction::where('idempotency_key', $idem)
                    ->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }
            }

            // 3b) Un intento anterior del que NO se sabe el desenlace.
            //
            // `idempotency_key` solo llega si la app manda `client_request_id`;
            // sin él, volver a pulsar «pagar» crea una referencia nueva. Da
            // igual casi siempre, salvo justo aquí: si el POST anterior se
            // perdió por un timeout, Wompi pudo haberlo cobrado, y mandar otro
            // es cobrar dos veces por lo mismo.
            //
            // Se reutiliza solo lo marcado como indeterminado, no cualquier
            // pendiente: un pendiente normal ya tiene id de Wompi y lo cubre el
            // guardia de AbstractWompiPaymentService.
            $unknown = $this->recentIndeterminateFor($data);
            if ($unknown !== null) {
                return $unknown;
            }

            $reference = $data['reference'] ?? $this->generateReference();
            while (PaymentTransaction::where('reference', $reference)->exists()) {
                $reference = $this->generateReference();
            }

            // COTIZACIÓN AUTORITATIVA. `amount` es el total BRUTO (base + IVA
            // cuando el plan es base_plus_tax): es lo que se firma, lo que se
            // cobra y lo que después se factura. El desglose se congela junto a
            // la transacción para que el webhook y la factura lo reutilicen.
            $quote = $this->authoritativeQuote($data);
            $amount = $quote?->grossAmount->toFloat() ?? $this->authoritativeAmount($data);
            $c = $this->sanitizeCustomer($data['customer'] ?? []);

            $attrs = [
                'uuid' => (string) Str::uuid(),
                'reference' => $reference,
                'idempotency_key' => $idem ?: (string) Str::uuid(),
                'order_id' => $orderId,
                'member_id' => $data['member_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'plan_id' => $data['plan_id'] ?? null,
                'amount' => $amount,
                'currency' => strtoupper($data['currency'] ?? ($this->cfg['currency'] ?? 'COP')),
                'status' => PaymentStateMachine::CREATED,
                'provider' => 'wompi',
                'environment' => $this->cfg['env'] ?? 'sandbox',
                'method' => $data['method'] ?? null,
                'description' => $data['description'] ?? 'Pago Iron Body',
                'customer' => $c,
                'customer_email' => $c['email'] ?? null,
                'customer_phone' => $c['phone'] ?? null,
                'customer_legal_id_type' => $c['doc_type'] ?? null,
                'customer_legal_id' => $c['doc_number'] ?? null,
                'retry_count' => 0,
                // Factura electrónica solicitada desde la app (opt-in). Se guarda
                // como metadato; al aprobarse, PaymentMembershipActivator decide si
                // FUERZA la emisión a Factus (sin depender de auto_emit global).
                'metadata' => $this->invoiceMetadata($data),
            ];

            if ($quote !== null) {
                $attrs = array_merge($attrs, [
                    'base_amount' => $quote->baseAmount->toDatabase(),
                    'tax_amount' => $quote->taxAmount->toDatabase(),
                    'gross_amount' => $quote->grossAmount->toDatabase(),
                    'discount_amount' => $quote->discountAmount->toDatabase(),
                    'tax_rate_id' => $quote->taxRateId,
                    'tax_rate' => $quote->taxRateString(),
                    'pricing_mode' => $quote->pricingMode->value,
                    'pricing_rules_version' => $quote->pricingRulesVersion,
                    'priced_at' => $quote->pricedAt,
                ]);
            }

            try {
                return PaymentTransaction::create($attrs);
            } catch (QueryException $e) {
                Log::warning('Wompi tx: choque de unicidad recuperado', ['sqlstate' => $e->getCode()]);
                $found = ! empty($idem)
                    ? PaymentTransaction::where('idempotency_key', $idem)->first()
                    : PaymentTransaction::where('reference', $reference)->first();
                if ($found) {
                    return $found;
                }
                throw $e;
            }
        });
    }

    /**
     * Intento reciente del mismo miembro y plan cuyo desenlace se desconoce.
     *
     * La ventana existe porque un intento indeterminado no se queda así para
     * siempre: el webhook o la reconciliación acaban cerrándolo. Pasado ese
     * tiempo, insistir es una compra nueva de verdad y bloquearla sería peor
     * que el problema que se quiere evitar.
     */
    private function recentIndeterminateFor(array $data): ?PaymentTransaction
    {
        $memberId = $data['member_id'] ?? null;
        if ($memberId === null) {
            return null;
        }

        $window = (int) data_get($this->cfg, 'indeterminate_reuse_minutes', 30);

        return PaymentTransaction::query()
            ->where('provider', 'wompi')
            ->where('member_id', $memberId)
            ->where('plan_id', $data['plan_id'] ?? null)
            ->whereIn('status', PaymentStateMachine::IN_FLIGHT)
            ->where('created_at', '>=', now()->subMinutes($window))
            ->whereNotNull('metadata')
            ->get()
            ->first(fn (PaymentTransaction $tx) => ! empty(data_get($tx->metadata, 'outcome_unknown')));
    }

    /**
     * Aplica a la transacción los datos de una `transaction` de Wompi (respuesta
     * de creación, consulta o evento). Mapea el estado, detecta requires_action
     * (autenticación externa pendiente) y persiste datos NO sensibles.
     *
     * @param  array  $wt  data.transaction de Wompi.
     */
    public function applyWompiTransaction(PaymentTransaction $tx, array $wt): PaymentTransaction
    {
        $wompiStatus = (string) ($wt['status'] ?? '');
        $state = $this->sm->mapWompiStatus($wompiStatus);

        $pm = $wt['payment_method'] ?? [];
        $method = $tx->method ?: $this->methodFromType($pm['type'] ?? null);

        // La autenticación externa SOLO existe en PSE (URL real del banco). Jamás
        // se deriva de `redirect_url` (que Wompi devuelve en TODA transacción) ni
        // se usa para CARD/NEQUI/DAVIPLATA → así CARD nunca cae en requires_action.
        $externalAuthUrl = $method === 'pse' ? $this->extractPseAuthUrl($wt) : null;

        // Solo PSE pendiente con URL del banco pasa a requires_action.
        if ($state === PaymentStateMachine::PENDING && $method === 'pse' && $externalAuthUrl) {
            $state = PaymentStateMachine::REQUIRES_ACTION;
        }

        $attrs = [
            'wompi_transaction_id' => $wt['id'] ?? null,
            'provider_ref' => $wt['id'] ?? null,
            'status_message' => $this->safeMessage($wt['status_message'] ?? null),
            'processor_response_code' => $this->extractProcessorCode($wt),
            'method' => $method,
            'external_auth_url' => $externalAuthUrl,
            'card_brand' => data_get($pm, 'extra.brand'),
            'card_last_four' => data_get($pm, 'extra.last_four'),
            'installments' => is_numeric($pm['installments'] ?? null) ? (int) $pm['installments'] : null,
            'raw_response' => $this->safeRaw($wt),
        ];

        return $this->transitionTo($tx, $state, $attrs);
    }

    /**
     * Transición de estado SEGURA e idempotente con lockForUpdate. Aplica la
     * máquina de estados (no degrada terminales, approved absorbente), sella las
     * marcas *_at y, SOLO en approved, activa la membresía una vez.
     */
    public function transitionTo(PaymentTransaction $tx, string $target, array $attrs = []): PaymentTransaction
    {
        return DB::transaction(function () use ($tx, $target, $attrs) {
            /** @var PaymentTransaction $fresh */
            $fresh = PaymentTransaction::lockForUpdate()->find($tx->id);
            $current = (string) $fresh->status;

            $next = $this->sm->resolveNext($current, $target);

            // Persistir datos (NO nulos) aunque el estado no avance (p. ej.
            // guardar wompi_transaction_id en un refresco de pending).
            $clean = array_filter($attrs, fn ($v) => $v !== null);
            $fresh->fill($clean);

            $changed = $next !== $current;
            $fresh->status = $next;

            if ($changed) {
                $col = $this->sm->timestampColumnFor($next);
                if ($col && ! $fresh->{$col}) {
                    $fresh->{$col} = now();
                }
                if ($next === PaymentStateMachine::APPROVED && ! $fresh->paid_at) {
                    $fresh->paid_at = now();
                }
            }
            $fresh->save();

            // Activación + realtime SOLO al ENTRAR en approved (no en refrescos).
            if ($changed && $next === PaymentStateMachine::APPROVED) {
                app(PaymentMembershipActivator::class)->activate($fresh, 'wompi');
                RealtimeEvents::payment($fresh->member_id);
                RealtimeEvents::membership($fresh->member_id);
                RealtimeEvents::appState($fresh->member_id);

                // Cierre de suscripción recurrente (ADITIVO, idempotente, best-effort).
                // Solo aplica si la transacción pertenece a una suscripción; en el pago
                // único `subscription_id` es null y esto no hace nada. Corre UNA vez
                // (guardado por $changed), así que webhook/reconciliación/cobro directo
                // convergen aquí sin doble activación. Nunca rompe la confirmación.
                if ($fresh->subscription_id) {
                    try {
                        MembershipSubscriptionService::make()
                            ->markChargeApproved($fresh);
                    } catch (\Throwable $e) {
                        Log::warning('subscriptions.close_on_approved.failed', [
                            'reference' => $fresh->reference,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Notificación de rechazo (best-effort, idempotente por event_key).
            if ($changed && in_array($next, [
                PaymentStateMachine::DECLINED, PaymentStateMachine::ERROR, PaymentStateMachine::VOIDED,
            ], true)) {
                try {
                    $member = $fresh->member_id ? Member::find($fresh->member_id) : null;
                    app(NotificationService::class)->notifyPaymentRejected($member, $fresh);
                } catch (\Throwable $e) {
                    Log::warning('Wompi: notificación de rechazo falló', ['error' => $e->getMessage()]);
                }
            }

            // Cierre de suscripción recurrente FALLIDA (ADITIVO, idempotente,
            // best-effort). Cubre DECLINED/ERROR/VOIDED/EXPIRED — incluye EXPIRED
            // (que fija la reconciliación) además de los estados de rechazo. Solo
            // aplica si la transacción pertenece a una suscripción; en el pago único
            // `subscription_id` es null y no hace nada. Corre una vez ($changed).
            if ($changed && $fresh->subscription_id && in_array($next, [
                PaymentStateMachine::DECLINED, PaymentStateMachine::ERROR,
                PaymentStateMachine::VOIDED, PaymentStateMachine::EXPIRED,
            ], true)) {
                try {
                    MembershipSubscriptionService::make()
                        ->markChargeFailed($fresh);
                } catch (\Throwable $e) {
                    Log::warning('subscriptions.close_on_failed.failed', [
                        'reference' => $fresh->reference,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $fresh->fresh();
        });
    }

    /** Marca un intento como error controlado (sin tocar terminales). */
    public function markError(PaymentTransaction $tx, string $message, array $extra = []): PaymentTransaction
    {
        return $this->transitionTo($tx, PaymentStateMachine::ERROR, array_merge([
            'status_message' => $this->safeMessage($message),
        ], $extra));
    }

    /** Registra el consentimiento (auditoría) de los dos tokens de aceptación. */
    public function recordConsent(PaymentTransaction $tx, array $tokens, ?string $ip, ?string $userAgent): void
    {
        try {
            PaymentConsent::create([
                'uuid' => (string) Str::uuid(),
                'reference' => $tx->reference,
                'payment_transaction_id' => $tx->id,
                'member_id' => $tx->member_id,
                'user_id' => $tx->user_id,
                'acceptance_token' => $tokens['acceptance_token'] ?? null,
                'accept_personal_auth_token' => $tokens['accept_personal_auth_token'] ?? null,
                'terms_link' => $tokens['terms_link'] ?? null,
                'privacy_link' => $tokens['privacy_link'] ?? null,
                'accepted_at' => now(),
                'ip' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'environment' => $tx->environment,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Wompi: registro de consentimiento falló', ['error' => $e->getMessage()]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Cotización autoritativa del cobro, calculada SIEMPRE en el backend.
     *
     * Devuelve null cuando no hay plan asociado (pago libre) o cuando Pricing V2
     * está apagado: en esos casos se cae a authoritativeAmount() y el
     * comportamiento es idéntico al anterior.
     *
     * El cliente jamás fija el importe: si envía uno distinto al cotizado, se
     * ignora y se registra la discrepancia.
     */
    public function authoritativeQuote(array $data): ?PriceQuote
    {
        if (empty($data['plan_id']) || ! config('billing.pricing.v2_enabled', false)) {
            return null;
        }

        $plan = Plan::with('taxRate')->find($data['plan_id']);
        if (! $plan || (float) $plan->price <= 0) {
            return null;
        }

        try {
            $quote = app(PricingService::class)->quoteForPlan($plan);
        } catch (PricingException $e) {
            // Un plan gravable sin tarifa no se cobra a ciegas con Pricing V2:
            // se cae al comportamiento legacy y se deja constancia.
            Log::warning('Wompi: cotización V2 no disponible, se usa el precio legacy', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $received = round((float) ($data['amount'] ?? 0), 2);
        if ($received > 0 && abs($quote->grossAmount->toFloat() - $received) > 0.5) {
            Log::warning('Wompi: monto recibido != total cotizado; se usa el cotizado', [
                'plan_id' => $plan->id,
                'received' => $received,
                'quoted' => $quote->grossAmount->toFloat(),
            ]);
        }

        return $quote;
    }

    /** Monto en pesos (no centavos). Autoritativo desde el plan si aplica. */
    public function authoritativeAmount(array $data): float
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if (! empty($data['plan_id'])) {
            $plan = Plan::find($data['plan_id']);
            if ($plan && (float) $plan->price > 0) {
                $planPrice = round((float) $plan->price, 2);
                if (abs($planPrice - $amount) > 0.5) {
                    Log::warning('Wompi: monto recibido != precio del plan; se usa el del plan', [
                        'plan_id' => $data['plan_id'],
                        'received' => $amount,
                        'plan' => $planPrice,
                    ]);
                }
                $amount = $planPrice;
            }
        }

        return $amount;
    }

    /**
     * Centavos que se envían y se FIRMAN hacia Wompi.
     *
     * Se derivan del bruto congelado (`gross_amount`) cuando existe, y de
     * `amount` en las transacciones legacy. Ambos son el mismo valor por
     * construcción; usar el congelado deja explícito que el importe firmado es
     * el cotizado y no una reconstrucción posterior.
     */
    public function amountInCents(PaymentTransaction $tx): int
    {
        return Money::fromAmount($tx->gross_amount ?? $tx->amount)->toWompiCents();
    }

    /**
     * URL OFICIAL del banco para PSE (`async_payment_url`). NO se usa
     * `redirect_url` (es solo nuestra URL de retorno y Wompi la devuelve en TODA
     * transacción, lo que provocaba que CARD entrara en requires_action y abriera
     * un WebView). Esta URL se abre en el NAVEGADOR EXTERNO del sistema, nunca en
     * un WebView.
     */
    private function extractPseAuthUrl(array $wt): ?string
    {
        $url = data_get($wt, 'payment_method.extra.async_payment_url')
            ?? data_get($wt, 'payment_method.extra.external_identifier_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function extractProcessorCode(array $wt): ?string
    {
        $code = data_get($wt, 'status_message')
            ? data_get($wt, 'payment_method.extra.respuesta')
            : null;
        $code = data_get($wt, 'payment_method.extra.processor_response_code', $code);

        return is_scalar($code) ? (string) $code : null;
    }

    private function methodFromType(?string $type): ?string
    {
        if (! $type) {
            return null;
        }

        return match (strtoupper($type)) {
            'CARD' => 'card',
            'PSE' => 'pse',
            'NEQUI' => 'nequi',
            'DAVIPLATA' => 'daviplata',
            default => strtolower($type),
        };
    }

    private function safeMessage(?string $msg): ?string
    {
        return $msg ? mb_substr($msg, 0, 200) : null;
    }

    private function generateReference(): string
    {
        return 'IRON-'.now()->format('Ymd').'-'
            .strtoupper(Str::random(6)).'-'.substr((string) time(), -5);
    }

    /**
     * Metadatos de facturación electrónica solicitados desde la app. Solo se
     * persisten si el cliente marcó la opción (`request_invoice`). `wants_invoice`
     * dispara la emisión FORZADA al aprobarse el pago; `invoice_email` es el
     * correo de contacto opcional (el backend usa el del miembro si no llega).
     */
    private function invoiceMetadata(array $data): ?array
    {
        $wants = filter_var($data['request_invoice'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $wants) {
            return null;
        }
        $email = isset($data['invoice_email']) ? trim((string) $data['invoice_email']) : '';

        return array_filter([
            'wants_invoice' => true,
            'invoice_email' => $email !== '' ? $email : null,
        ], fn ($v) => $v !== null);
    }

    private function sanitizeCustomer(array $c): array
    {
        return array_filter([
            'name' => $c['name'] ?? null,
            'last_name' => $c['last_name'] ?? null,
            'email' => $c['email'] ?? null,
            'phone' => $c['phone'] ?? null,
            'doc_type' => $c['doc_type'] ?? null,
            'doc_number' => $c['doc_number'] ?? null,
            'city' => $c['city'] ?? null,
            'address' => $c['address'] ?? null,
            'country' => $c['country'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Quita cualquier campo sensible antes de persistir el payload crudo. */
    private function safeRaw(array $raw): array
    {
        foreach (['token', 'cvc', 'cvv', 'number', 'card_number', 'p_key'] as $k) {
            unset($raw[$k]);
        }
        unset($raw['payment_method']['token']);

        return $raw;
    }
}
