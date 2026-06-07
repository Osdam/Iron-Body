<?php

namespace App\Services\Payments;

use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\NotificationService;
use App\Services\RealtimeEvents;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proveedor DIRECTO de Nequi — "Pagos con notificación Push" (Nequi Negocios /
 * Nequi Conecta). INDEPENDIENTE de ePayco.
 *
 * Flujo: el comercio inicia el pago por API → el cliente recibe una notificación
 * en su app Nequi → aprueba o cancela → el backend confirma por webhook o por
 * consulta de estado → al aprobarse activa la membresía (una sola vez).
 *
 * SEGURIDAD:
 *  - Monto SIEMPRE autoritativo desde el plan en BD (se ignora el de la app).
 *  - Teléfono colombiano validado (10 dígitos, empieza por 3).
 *  - createPushPayment NUNCA activa membresía; solo `approved` (webhook/status)
 *    la activa, vía {@see PaymentMembershipActivator} (idempotente por referencia).
 *  - rejected/expired/failed/abandoned NO activan.
 *  - Reverso NO revierte la membresía automáticamente (requiere flujo admin).
 *  - Jamás se loguean llaves ni tokens. Logs: reference/provider/method/status.
 *
 * Si Nequi no entregó credenciales finales, `enabled=false`: este servicio NO se
 * invoca y el endpoint responde `unavailable` (cero cobros, cero aprobaciones
 * falsas). El adapter HTTP queda listo y documentado; las rutas/paths exactos se
 * confirman con la documentación final de Nequi.
 */
class NequiPushPaymentService
{
    public const PROVIDER = 'nequi';
    public const METHOD   = 'nequi_push';

    /** ¿Nequi directo habilitado y con configuración mínima? */
    public function isEnabled(): bool
    {
        $cfg = config('services.nequi');
        return (bool) ($cfg['enabled'] ?? false)
            && config('services.payments.nequi_provider') === 'direct';
    }

    /**
     * Crea (o reutiliza) la transacción Nequi PENDING e inicia el push. NUNCA
     * activa membresía. Lanza {@see NequiException} controlada ante errores.
     *
     * @throws NequiException
     */
    public function createPushPayment(
        Member $member,
        Plan $plan,
        string $phone,
        string $idempotencyKey
    ): PaymentTransaction {
        if (! $this->isEnabled()) {
            throw NequiException::unavailable(
                'Nequi directo está en proceso de activación. Usa PSE, tarjeta o DaviPlata.'
            );
        }

        $phone = $this->normalizePhone($phone);

        // Monto AUTORITATIVO del plan (anti-manipulación). Entero COP.
        $amount = (int) round((float) $plan->price);
        if ($amount < 1000) {
            throw new NequiException('El monto del plan no es válido para procesar el pago.');
        }

        $tx = $this->createOrReusePending($member, $plan, $phone, $amount, $idempotencyKey);

        // Si ya está aprobada (reintento idempotente), no se vuelve a empujar.
        if ($tx->status === PaymentTransaction::STATUS_APPROVED) {
            return $tx;
        }

        try {
            $token = $this->authenticate();
            $resp  = $this->requestPush($token, $tx, $phone);
        } catch (NequiException $e) {
            $this->transition($tx, PaymentTransaction::STATUS_FAILED, [
                'failure_reason' => $e->getMessage(),
                'raw_response'   => $this->mergeRaw($tx, ['push_error' => true]),
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Nequi push: error no controlado', [
                'reference' => $tx->reference,
                'provider'  => self::PROVIDER,
                'method'    => self::METHOD,
            ]);
            $this->transition($tx, PaymentTransaction::STATUS_FAILED, [
                'failure_reason' => 'No pudimos iniciar el pago con Nequi. Intenta nuevamente.',
                'raw_response'   => $this->mergeRaw($tx, ['push_error' => true]),
            ]);
            throw new NequiException('No pudimos iniciar el pago con Nequi. Intenta nuevamente.');
        }

        // Push aceptado → PENDING: el cliente aprueba en su app Nequi.
        $providerRef = $resp['transaction_id'] ?? $resp['transactionId'] ?? null;
        $expiresAt   = $resp['expires_at']
            ?? now()->addMinutes((int) (config('services.nequi.ttl_minutes') ?: 15))->toIso8601String();

        Log::info('nequi.push.created', [
            'reference' => $tx->reference,
            'provider'  => self::PROVIDER,
            'method'    => self::METHOD,
            'status'    => PaymentTransaction::STATUS_PENDING,
        ]);

        return $this->transition($tx, PaymentTransaction::STATUS_PENDING, [
            'provider_ref' => $providerRef,
            'raw_response' => $this->mergeRaw($tx, [
                'flow'       => 'nequi_push',
                'expires_at' => $expiresAt,
            ]),
        ]);
    }

    /** Consulta el estado real en Nequi y transiciona si cambió. */
    public function getPaymentStatus(PaymentTransaction $tx): PaymentTransaction
    {
        if (! $this->isEnabled() || ! $tx->provider_ref || ! $tx->isInFlight()) {
            return $tx;
        }
        try {
            $token  = $this->authenticate();
            $remote = $this->requestStatus($token, $tx);
        } catch (Throwable $e) {
            Log::info('nequi.status.query_failed', [
                'reference' => $tx->reference,
                'provider'  => self::PROVIDER,
            ]);
            return $tx; // sin datos confiables → no se cambia el estado
        }
        $status = $this->mapNequiStatus((string) ($remote['status'] ?? ''));
        if ($status === null) {
            return $tx;
        }
        return $this->transition($tx, $status, [
            'failure_reason' => $status === PaymentTransaction::STATUS_APPROVED
                ? null
                : ($remote['message'] ?? null),
            'raw_response' => $this->mergeRaw($tx, ['status_poll' => $this->sanitizePayload($remote)]),
        ]);
    }

    /**
     * Webhook de Nequi (idempotente). Verifica la firma HMAC con webhook_secret
     * si está configurada; localiza la tx por referencia/provider_ref; mapea el
     * estado; transiciona (approved activa membresía una sola vez).
     */
    public function handleWebhook(array $payload, array $headers = []): ?PaymentTransaction
    {
        $payload = $this->flatten($payload);

        if (! $this->verifyWebhookSignature($payload, $headers)) {
            Log::warning('nequi.webhook.signature_invalid', ['provider' => self::PROVIDER]);
            return null;
        }

        $reference   = $payload['reference'] ?? $payload['invoice'] ?? $payload['extra1'] ?? null;
        $providerRef = $payload['transaction_id'] ?? $payload['transactionId'] ?? null;

        $tx = null;
        if ($reference) {
            $tx = PaymentTransaction::where('reference', $reference)
                ->where('provider', self::PROVIDER)->first();
        }
        if (! $tx && $providerRef) {
            $tx = PaymentTransaction::where('provider_ref', $providerRef)
                ->where('provider', self::PROVIDER)->first();
        }
        if (! $tx) {
            Log::warning('nequi.webhook.tx_not_found', [
                'provider'  => self::PROVIDER,
                'reference' => $reference,
            ]);
            return null;
        }

        $status = $this->mapNequiStatus((string) ($payload['status'] ?? ''));
        if ($status === null) {
            Log::info('nequi.webhook.unmapped_status', [
                'reference' => $tx->reference,
                'provider'  => self::PROVIDER,
            ]);
            return $tx;
        }

        // Validar monto si viene (no aceptar si difiere del esperado).
        $paid = (float) ($payload['amount'] ?? 0);
        if ($paid > 0 && abs($paid - (float) $tx->amount) > 0.5) {
            Log::warning('nequi.webhook.amount_mismatch', [
                'reference' => $tx->reference,
                'provider'  => self::PROVIDER,
            ]);
            return $this->transition($tx, PaymentTransaction::STATUS_FAILED, [
                'failure_reason' => 'Monto no coincide con el esperado',
                'provider_ref'   => $providerRef,
                'raw_response'   => $this->mergeRaw($tx, ['webhook' => $this->sanitizePayload($payload)]),
            ]);
        }

        Log::info('nequi.webhook.processed', [
            'reference' => $tx->reference,
            'provider'  => self::PROVIDER,
            'method'    => self::METHOD,
            'status'    => $status,
        ]);

        return $this->transition($tx, $status, [
            'failure_reason' => $status === PaymentTransaction::STATUS_APPROVED ? null : ($payload['message'] ?? null),
            'provider_ref'   => $providerRef,
            'raw_response'   => $this->mergeRaw($tx, ['webhook' => $this->sanitizePayload($payload)]),
        ]);
    }

    /**
     * Reverso/anulación de un pago Nequi. NO revierte la membresía: eso requiere
     * un flujo administrativo explícito (evita cortar acceso por error).
     *
     * @throws NequiException
     */
    public function reversePayment(PaymentTransaction $tx, string $reason): PaymentTransaction
    {
        if (! $this->isEnabled()) {
            throw NequiException::unavailable('Nequi directo no está habilitado.');
        }
        try {
            $token = $this->authenticate();
            $this->requestReverse($token, $tx, $reason);
        } catch (NequiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new NequiException('No pudimos procesar el reverso con Nequi.');
        }

        Log::info('nequi.reverse.requested', [
            'reference' => $tx->reference,
            'provider'  => self::PROVIDER,
            'method'    => self::METHOD,
            'status'    => PaymentTransaction::STATUS_CANCELLED,
            'reason'    => mb_substr($reason, 0, 120),
        ]);

        // Marca la transacción como cancelada (NO toca la membresía vigente).
        return $this->transition($tx, PaymentTransaction::STATUS_CANCELLED, [
            'failure_reason' => 'Pago reversado: ' . mb_substr($reason, 0, 120),
            'raw_response'   => $this->mergeRaw($tx, ['reversed' => true]),
        ]);
    }

    // ── Helpers públicos (testeables) ────────────────────────────────────────

    /** Normaliza a teléfono colombiano de 10 dígitos (debe empezar por 3). */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '57') && strlen($digits) === 12) {
            $digits = substr($digits, 2); // quita indicativo país
        }
        if (strlen($digits) !== 10 || $digits[0] !== '3') {
            throw new NequiException('Ingresa un número de celular Nequi válido (10 dígitos).');
        }
        return $digits;
    }

    /** Mapea el estado de Nequi a nuestro estado interno (o null si desconocido). */
    public function mapNequiStatus(string $raw): ?string
    {
        return match (strtolower(trim($raw))) {
            'approved', 'success', 'successful', 'paid', 'completed', '00', '0', 'c' => PaymentTransaction::STATUS_APPROVED,
            'pending', 'in_progress', 'processing', 'created', '35' => PaymentTransaction::STATUS_PENDING,
            'rejected', 'declined', 'failed', 'error', 'r'          => PaymentTransaction::STATUS_FAILED,
            'expired', 'timeout'                                    => PaymentTransaction::STATUS_EXPIRED,
            'cancelled', 'canceled', 'abandoned', 'reversed', 'voided' => PaymentTransaction::STATUS_CANCELLED,
            default => null,
        };
    }

    /** Quita campos sensibles antes de persistir/loguear un payload. */
    public function sanitizePayload(array $p): array
    {
        unset(
            $p['access_token'], $p['token'], $p['client_secret'],
            $p['api_key'], $p['signature'], $p['authorization']
        );
        return $p;
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /** Autentica contra Nequi (OAuth client_credentials). Token cacheado corto. */
    protected function authenticate(): string
    {
        $cfg = config('services.nequi');
        foreach (['auth_url', 'client_id', 'client_secret'] as $k) {
            if (empty($cfg[$k])) {
                throw NequiException::unavailable('Nequi directo aún no tiene credenciales configuradas.');
            }
        }
        $resp = Http::asForm()
            ->withBasicAuth($cfg['client_id'], $cfg['client_secret'])
            ->post($cfg['auth_url'], ['grant_type' => 'client_credentials']);

        if (! $resp->successful()) {
            throw new NequiException('No pudimos autenticar con Nequi.');
        }
        $token = $resp->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new NequiException('Respuesta de autenticación de Nequi inválida.');
        }
        return $token;
    }

    /** Inicia el push (cobro con notificación) en la app Nequi del cliente. */
    protected function requestPush(string $token, PaymentTransaction $tx, string $phone): array
    {
        $cfg  = config('services.nequi');
        $base = rtrim((string) $cfg['base_url'], '/');
        // Ruta documentada de "pago no registrado con notificación push". El path
        // exacto se confirma con la doc final de Nequi (adapter listo).
        $resp = Http::withToken($token)
            ->withHeaders(array_filter(['x-api-key' => $cfg['api_key'] ?? null]))
            ->post($base . '/payments/unregistered/payment', [
                'phoneNumber' => $phone,
                'code'        => 'NIT_1',
                'value'       => (string) ((int) round((float) $tx->amount)),
                'reference'   => $tx->reference,
                'merchantId'  => $cfg['merchant_id'] ?? null,
            ]);

        if (! $resp->successful()) {
            throw new NequiException('Nequi no aceptó la solicitud de pago. Intenta nuevamente.');
        }
        return (array) $resp->json();
    }

    /** Consulta el estado del pago push en Nequi. */
    protected function requestStatus(string $token, PaymentTransaction $tx): array
    {
        $cfg  = config('services.nequi');
        $base = rtrim((string) $cfg['base_url'], '/');
        $resp = Http::withToken($token)
            ->withHeaders(array_filter(['x-api-key' => $cfg['api_key'] ?? null]))
            ->get($base . '/payments/unregistered/' . rawurlencode((string) $tx->provider_ref) . '/status');
        if (! $resp->successful()) {
            throw new NequiException('No pudimos consultar el estado en Nequi.');
        }
        return (array) $resp->json();
    }

    /** Solicita el reverso del pago en Nequi. */
    protected function requestReverse(string $token, PaymentTransaction $tx, string $reason): void
    {
        $cfg  = config('services.nequi');
        $base = rtrim((string) $cfg['base_url'], '/');
        $resp = Http::withToken($token)
            ->withHeaders(array_filter(['x-api-key' => $cfg['api_key'] ?? null]))
            ->post($base . '/payments/unregistered/reverse', [
                'transactionId' => $tx->provider_ref,
                'reference'     => $tx->reference,
                'reason'        => mb_substr($reason, 0, 120),
            ]);
        if (! $resp->successful()) {
            throw new NequiException('Nequi no aceptó el reverso.');
        }
    }

    /** Verifica la firma HMAC del webhook si webhook_secret está configurado. */
    protected function verifyWebhookSignature(array $payload, array $headers): bool
    {
        $secret = config('services.nequi.webhook_secret');
        if (empty($secret)) {
            // Sin secreto configurado: en sandbox se acepta para poder probar; en
            // producción (env != sandbox) se RECHAZA (no se confía en datos sin firmar).
            return config('services.nequi.env') === 'sandbox';
        }
        // Cabecera de firma (varias convenciones). HMAC-SHA256 del body crudo o
        // del payload serializado. Se acepta cualquiera de las cabeceras comunes.
        $sig = $headers['x-nequi-signature'][0]
            ?? $headers['x-signature'][0]
            ?? ($payload['signature'] ?? null);
        if (! $sig) {
            return false;
        }
        $calc = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), (string) $secret);
        return hash_equals($calc, (string) $sig);
    }

    /** Crea/reutiliza la transacción PENDING (anti doble pago por idempotency). */
    protected function createOrReusePending(
        Member $member,
        Plan $plan,
        string $phone,
        int $amount,
        string $idempotencyKey
    ): PaymentTransaction {
        return DB::transaction(function () use ($member, $plan, $phone, $amount, $idempotencyKey) {
            if ($idempotencyKey !== '') {
                $existing = PaymentTransaction::where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }
            }
            $reference = $this->generateReference();
            while (PaymentTransaction::where('reference', $reference)->exists()) {
                $reference = $this->generateReference();
            }
            return PaymentTransaction::create([
                'reference'       => $reference,
                'idempotency_key' => $idempotencyKey ?: (string) Str::uuid(),
                'member_id'       => $member->id,
                'user_id'         => $member->user_id,
                'plan_id'         => $plan->id,
                'amount'          => $amount,
                'currency'        => 'COP',
                'status'          => PaymentTransaction::STATUS_PENDING,
                'provider'        => self::PROVIDER,
                'method'          => self::METHOD,
                'description'     => 'Membresía ' . $plan->name . ' · Iron Body',
                'customer'        => ['phone' => $phone, 'name' => $member->full_name],
                'raw_response'    => ['flow' => 'nequi_push', 'requested_method' => self::METHOD],
            ]);
        });
    }

    /**
     * Transición idempotente. La aprobación es terminal y dispara la activación
     * de membresía COMPARTIDA + eventos realtime. No degrada estados finalizados.
     */
    protected function transition(PaymentTransaction $tx, string $status, array $attrs = []): PaymentTransaction
    {
        return DB::transaction(function () use ($tx, $status, $attrs) {
            /** @var PaymentTransaction $fresh */
            $fresh = PaymentTransaction::lockForUpdate()->find($tx->id);

            if ($fresh->status === PaymentTransaction::STATUS_APPROVED) {
                return $fresh; // terminal: ya aprobada (anti doble activación)
            }
            if ($fresh->isSettled()
                && in_array($status, [PaymentTransaction::STATUS_PENDING, PaymentTransaction::STATUS_PROCESSING], true)) {
                return $fresh;
            }

            $fresh->fill(array_filter($attrs, fn ($v) => $v !== null));
            if (array_key_exists('failure_reason', $attrs)) {
                $fresh->failure_reason = $attrs['failure_reason'];
            }
            $fresh->status = $status;
            if ($status === PaymentTransaction::STATUS_APPROVED && ! $fresh->paid_at) {
                $fresh->paid_at = now();
            }
            $fresh->save();

            if ($status === PaymentTransaction::STATUS_APPROVED) {
                app(PaymentMembershipActivator::class)->activate($fresh, self::PROVIDER);
                RealtimeEvents::payment($fresh->member_id);
                RealtimeEvents::membership($fresh->member_id);
                RealtimeEvents::appState($fresh->member_id);
            }

            if ($status === PaymentTransaction::STATUS_FAILED) {
                try {
                    $member = $fresh->member_id ? Member::find($fresh->member_id) : null;
                    app(NotificationService::class)->notifyPaymentRejected($member, $fresh);
                } catch (Throwable $e) {
                    Log::warning('Nequi: notificación de rechazo falló', ['error' => $e->getMessage()]);
                }
            }

            return $fresh;
        });
    }

    /** Mezcla datos nuevos en raw_response preservando lo existente. */
    protected function mergeRaw(PaymentTransaction $tx, array $extra): array
    {
        $prev = is_array($tx->raw_response) ? $tx->raw_response : [];
        return array_merge($prev, $extra);
    }

    /** Aplana payloads anidados comunes (data/transaction) a primer nivel. */
    protected function flatten(array $p): array
    {
        foreach (['data', 'transaction', 'payment'] as $k) {
            if (isset($p[$k]) && is_array($p[$k])) {
                $p = array_merge($p, $p[$k]);
            }
        }
        return $p;
    }

    protected function generateReference(): string
    {
        return 'NEQUI-' . now()->format('Ymd') . '-'
            . strtoupper(Str::random(6)) . '-' . substr((string) time(), -5);
    }

    /** Mensaje funcional mínimo por estado (la app puede usar el suyo). */
    public function statusMessage(string $status): string
    {
        return match ($status) {
            PaymentTransaction::STATUS_APPROVED  => 'Pago confirmado. Tu membresía fue activada.',
            PaymentTransaction::STATUS_FAILED    => 'El pago con Nequi no se realizó.',
            PaymentTransaction::STATUS_CANCELLED => 'El pago con Nequi fue cancelado.',
            PaymentTransaction::STATUS_EXPIRED   => 'El pago con Nequi expiró. Genera uno nuevo.',
            default                              => 'Revisa tu app Nequi y aprueba el pago.',
        };
    }

    /** Expira (a EXPIRED) la transacción si superó el TTL sin resolverse. */
    public function expiresAtFor(PaymentTransaction $tx): ?string
    {
        $raw = is_array($tx->raw_response) ? $tx->raw_response : [];
        if (! empty($raw['expires_at'])) {
            return (string) $raw['expires_at'];
        }
        return $tx->created_at
            ? Carbon::parse($tx->created_at)
                ->addMinutes((int) (config('services.nequi.ttl_minutes') ?: 15))
                ->toIso8601String()
            : null;
    }
}
