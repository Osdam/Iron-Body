<?php

namespace App\Services\Wompi;

use App\Models\Member;
use App\Models\PaymentConsent;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Payments\PaymentMembershipActivator;
use App\Services\RealtimeEvents;
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
 *   - Monto AUTORITATIVO del backend (Plan::price → centavos). Flutter nunca
 *     define el precio final.
 *   - `approved` activa la membresía UNA sola vez (idempotencia por reference).
 *   - Un webhook/reconciliación duplicado no reactiva ni degrada un terminal.
 *   - Nada de PAN/CVC/OTP/secretos aquí.
 */
class WompiTransactionService
{
    public function __construct(
        private PaymentStateMachine $sm,
        private array $cfg,
    ) {
    }

    public static function make(): self
    {
        return new self(new PaymentStateMachine(), (array) config('wompi'));
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

            $reference = $data['reference'] ?? $this->generateReference();
            while (PaymentTransaction::where('reference', $reference)->exists()) {
                $reference = $this->generateReference();
            }

            $amount = $this->authoritativeAmount($data);
            $c = $this->sanitizeCustomer($data['customer'] ?? []);

            $attrs = [
                'uuid'             => (string) Str::uuid(),
                'reference'        => $reference,
                'idempotency_key'  => $idem ?: (string) Str::uuid(),
                'order_id'         => $orderId,
                'member_id'        => $data['member_id'] ?? null,
                'user_id'          => $data['user_id'] ?? null,
                'plan_id'          => $data['plan_id'] ?? null,
                'amount'           => $amount,
                'currency'         => strtoupper($data['currency'] ?? ($this->cfg['currency'] ?? 'COP')),
                'status'           => PaymentStateMachine::CREATED,
                'provider'         => 'wompi',
                'environment'      => $this->cfg['env'] ?? 'sandbox',
                'method'           => $data['method'] ?? null,
                'description'      => $data['description'] ?? 'Pago Iron Body',
                'customer'         => $c,
                'customer_email'   => $c['email'] ?? null,
                'customer_phone'   => $c['phone'] ?? null,
                'customer_legal_id_type' => $c['doc_type'] ?? null,
                'customer_legal_id'      => $c['doc_number'] ?? null,
                'retry_count'      => 0,
                // Factura electrónica solicitada desde la app (opt-in). Se guarda
                // como metadato; al aprobarse, PaymentMembershipActivator decide si
                // FUERZA la emisión a Factus (sin depender de auto_emit global).
                'metadata'         => $this->invoiceMetadata($data),
            ];

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
            'wompi_transaction_id'     => $wt['id'] ?? null,
            'provider_ref'             => $wt['id'] ?? null,
            'status_message'           => $this->safeMessage($wt['status_message'] ?? null),
            'processor_response_code'  => $this->extractProcessorCode($wt),
            'method'                   => $method,
            'external_auth_url'        => $externalAuthUrl,
            'card_brand'               => data_get($pm, 'extra.brand'),
            'card_last_four'           => data_get($pm, 'extra.last_four'),
            'installments'             => is_numeric($pm['installments'] ?? null) ? (int) $pm['installments'] : null,
            'raw_response'             => $this->safeRaw($wt),
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
            }

            // Notificación de rechazo (best-effort, idempotente por event_key).
            if ($changed && in_array($next, [
                PaymentStateMachine::DECLINED, PaymentStateMachine::ERROR, PaymentStateMachine::VOIDED,
            ], true)) {
                try {
                    $member = $fresh->member_id ? Member::find($fresh->member_id) : null;
                    app(\App\Services\NotificationService::class)->notifyPaymentRejected($member, $fresh);
                } catch (\Throwable $e) {
                    Log::warning('Wompi: notificación de rechazo falló', ['error' => $e->getMessage()]);
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
                'uuid'                       => (string) Str::uuid(),
                'reference'                  => $tx->reference,
                'payment_transaction_id'     => $tx->id,
                'member_id'                  => $tx->member_id,
                'user_id'                    => $tx->user_id,
                'acceptance_token'           => $tokens['acceptance_token'] ?? null,
                'accept_personal_auth_token' => $tokens['accept_personal_auth_token'] ?? null,
                'terms_link'                 => $tokens['terms_link'] ?? null,
                'privacy_link'               => $tokens['privacy_link'] ?? null,
                'accepted_at'                => now(),
                'ip'                         => $ip,
                'user_agent'                 => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'environment'                => $tx->environment,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Wompi: registro de consentimiento falló', ['error' => $e->getMessage()]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
                        'plan_id'  => $data['plan_id'],
                        'received' => $amount,
                        'plan'     => $planPrice,
                    ]);
                }
                $amount = $planPrice;
            }
        }
        return $amount;
    }

    public function amountInCents(PaymentTransaction $tx): int
    {
        return (int) round((float) $tx->amount * 100);
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
            'CARD'      => 'card',
            'PSE'       => 'pse',
            'NEQUI'     => 'nequi',
            'DAVIPLATA' => 'daviplata',
            default     => strtolower($type),
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
            'name'       => $c['name'] ?? null,
            'last_name'  => $c['last_name'] ?? null,
            'email'      => $c['email'] ?? null,
            'phone'      => $c['phone'] ?? null,
            'doc_type'   => $c['doc_type'] ?? null,
            'doc_number' => $c['doc_number'] ?? null,
            'city'       => $c['city'] ?? null,
            'address'    => $c['address'] ?? null,
            'country'    => $c['country'] ?? null,
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
