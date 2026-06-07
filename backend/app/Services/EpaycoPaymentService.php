<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Models\Member;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Integración ePayco (modo pruebas) — fuente de verdad del estado del pago.
 *
 * Estrategia: el backend genera una referencia + idempotency_key, crea/recupera
 * la transacción y entrega a la app un `checkout_url` interno
 * (`/pay/epayco/{reference}`) que renderiza el Checkout Onpage de ePayco con la
 * LLAVE PÚBLICA del lado servidor. La llave privada NUNCA sale del backend.
 *
 * La confirmación (webhook) y la consulta de estado se validan:
 *  1. Por firma SHA256 cuando hay `p_cust_id_cliente` + `p_key` (panel ePayco).
 *  2. Si no, consultando la API pública de validación de ePayco por `ref_payco`
 *     (fuente de verdad alterna que no requiere llave privada).
 *
 * Anti doble pago e idempotencia están en `createOrReuse()` y `transitionTo()`.
 *
 * NOTA: nunca se registra en log ningún dato sensible (ePayco no envía PAN/CVV;
 * tampoco se loguean llaves).
 */
class EpaycoPaymentService
{
    /**
     * Crea una transacción nueva o reutiliza una existente (anti doble pago).
     *
     * @param  array  $data  amount, currency, description, reference?,
     *                        idempotency_key?, order_id?, user_id?, plan_id?,
     *                        customer[]
     */
    public function createOrReuse(array $data): PaymentTransaction
    {
        $orderId = $data['order_id'] ?? null;
        $idem = $data['idempotency_key'] ?? null;

        // Búsqueda + creación atómica (lockForUpdate) para que un doble tap o un
        // reintento NUNCA inserten dos filas con la misma idempotency_key.
        return DB::transaction(function () use ($data, $orderId, $idem) {
            // 1) Orden ya aprobada → NO crear otro pago.
            if ($orderId !== null) {
                $approved = PaymentTransaction::where('order_id', $orderId)
                    ->where('status', PaymentTransaction::STATUS_APPROVED)
                    ->lockForUpdate()
                    ->first();
                if ($approved) {
                    return $approved;
                }
                // 2) Intento en curso para la orden → reutilizar ese.
                $inFlight = PaymentTransaction::where('order_id', $orderId)
                    ->whereIn('status', [
                        PaymentTransaction::STATUS_PENDING,
                        PaymentTransaction::STATUS_PROCESSING,
                    ])
                    ->latest()
                    ->lockForUpdate()
                    ->first();
                if ($inFlight) {
                    return $inFlight;
                }
            }

            // 3) Idempotencia real: si ya existe esa idempotency_key se REUTILIZA
            //    la transacción, sin importar su estado. (Un reintento limpio usa
            //    una idempotency_key nueva desde la app; misma clave = mismo
            //    intento lógico → nunca se inserta una fila duplicada.)
            if (!empty($idem)) {
                $existing = PaymentTransaction::where('idempotency_key', $idem)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $reference = $data['reference'] ?? $this->generateReference();
            while (PaymentTransaction::where('reference', $reference)->exists()) {
                $reference = $this->generateReference();
            }

            // Monto AUTORITATIVO del backend: si hay plan, el precio real lo
            // decide el plan en BD, NUNCA el monto que mande Flutter (anti
            // manipulación: no se puede pagar menos para desbloquear, ni cobrar
            // más). Si no hay plan (otros flujos), se usa el monto recibido.
            $amount = round((float) ($data['amount'] ?? 0), 2);
            if (!empty($data['plan_id'])) {
                $plan = Plan::find($data['plan_id']);
                if ($plan && (float) $plan->price > 0) {
                    $planPrice = round((float) $plan->price, 2);
                    if (abs($planPrice - $amount) > 0.5) {
                        Log::warning('ePayco: monto recibido != precio del plan; se usa el del plan', [
                            'plan_id'  => $data['plan_id'],
                            'received' => $amount,
                            'plan'     => $planPrice,
                        ]);
                    }
                    $amount = $planPrice;
                }
            }

            $attrs = [
                'reference'       => $reference,
                'idempotency_key' => $idem ?: (string) Str::uuid(),
                'order_id'        => $orderId,
                'member_id'       => $data['member_id'] ?? null,
                'user_id'         => $data['user_id'] ?? null,
                'plan_id'         => $data['plan_id'] ?? null,
                'amount'          => $amount,
                'currency'        => strtoupper($data['currency'] ?? 'COP'),
                'status'          => PaymentTransaction::STATUS_PENDING,
                'provider'        => 'epayco',
                'description'     => $data['description'] ?? 'Pago Iron Body',
                'customer'        => $this->sanitizeCustomer($data['customer'] ?? []),
            ];

            try {
                // Pago 100% in-app: sin checkout web/navegador, sin checkout_url.
                return PaymentTransaction::create($attrs);
            } catch (QueryException $e) {
                // Carrera: otra petición insertó la misma idempotency_key/
                // reference primero. NO es un error para el usuario: se
                // recupera la transacción existente. (Se loguea sin SQL crudo
                // ni datos sensibles.)
                Log::warning('PaymentTransaction: choque de unicidad recuperado', [
                    'sqlstate' => $e->getCode(),
                ]);
                $found = !empty($idem)
                    ? PaymentTransaction::where('idempotency_key', $idem)->first()
                    : PaymentTransaction::where('reference', $reference)->first();
                if ($found) {
                    return $found;
                }
                throw $e; // inesperado: lo maneja la capa superior (controller)
            }
        });
    }

    /**
     * Pago IN-APP por API ePayco (sin navegador). Crea/reutiliza la transacción
     * (idempotencia + anti doble pago), cobra por el método indicado y deja la
     * transacción en el estado real (approved/failed/pending/...).
     *
     * @param  string  $method  card|pse|nequi|daviplata
     */
    public function payInApp(string $method, array $data, EpaycoApiClient $api): PaymentTransaction
    {
        $tx = $this->createOrReuse($data);

        // Anti doble pago: ya aprobada → no se cobra de nuevo.
        if ($tx->status === PaymentTransaction::STATUS_APPROVED) {
            return $tx;
        }
        // Si ya hubo un intento de cobro (tiene provider_ref) y sigue en curso,
        // no se vuelve a cobrar: la app debe consultar /status.
        if ($tx->provider_ref && $tx->isInFlight()) {
            return $tx;
        }

        // Reintento sobre una transacción previa: vuelve a "processing" y limpia
        // el motivo de fallo anterior.
        $this->transitionTo($tx, PaymentTransaction::STATUS_PROCESSING, [
            'failure_reason' => null,
            'method'         => $method,
        ]);

        $cfg = config('services.epayco');
        $payload = [
            'reference'        => $tx->reference,
            'description'      => $tx->description ?: 'Pago Iron Body',
            'value'            => number_format((float) $tx->amount, 2, '.', ''),
            'currency'         => strtoupper($tx->currency),
            // ePayco rechaza IPs loopback/privadas en charge ("Error validando
            // datos"). En dev local o cuando viene detrás de un túnel/proxy sin
            // X-Forwarded-For real, sustituimos por una IP pública neutral.
            'ip'               => $this->sanitizeClientIp($data['ip'] ?? null),
            'test'             => (bool) $cfg['test'],
            'url_confirmation' => $this->confirmationUrl(),
            'url_response'     => $this->responseUrl(),
            'dues'             => (string) (int) ($data['dues'] ?? 1),
            'customer'         => $tx->customer ?? [],
            'card'             => $this->normalizeCard($data['card'] ?? []),
            'pse'              => $data['pse'] ?? [],
            'phone'            => $data['phone'] ?? ($tx->customer['phone'] ?? ''),
        ];

        $r = match ($method) {
            'card'      => $api->payCard($payload),
            'nequi'     => $api->payNequi($payload),
            'pse'       => $api->payPse($payload),
            'daviplata' => $api->payDaviplata($payload),
            default     => null,
        };
        if ($r === null) {
            return $this->transitionTo($tx, PaymentTransaction::STATUS_FAILED, [
                'failure_reason' => 'Método de pago no soportado.',
            ]);
        }

        // Estado real según la respuesta de ePayco (sin aprobaciones falsas).
        $status = $r['state'] !== null
            ? $this->mapEpaycoState((int) $r['state'])
            : ($r['ok']
                ? PaymentTransaction::STATUS_PROCESSING
                : PaymentTransaction::STATUS_FAILED);

        // El mensaje controlado (PSE "autoriza en tu banco", Daviplata
        // "confírmalo en tu app", Nequi "no habilitado") se conserva como
        // `failure_reason` también en pending: la app lo muestra. NO se fuerza
        // FAILED por `requires_external` (PSE queda PENDIENTE legítimamente).
        $reason = $status === PaymentTransaction::STATUS_APPROVED
            ? null
            : ($r['message'] ?? null);

        $tx = $this->transitionTo($tx, $status, [
            'failure_reason' => $reason,
            'provider_ref'   => $r['ref_payco'] ?? $r['transaction_id'],
            'raw_response'   => $this->safeRaw((array) ($r['raw'] ?? [])),
        ]);

        // Log SANITIZADO (sin llaves/tokens/datos sensibles).
        Log::info('ePayco pago in-app procesado', [
            'method'       => $method,
            'reference'    => $tx->reference,
            'provider_ref' => $tx->provider_ref,
            'status'       => $tx->status,
            'http_status'  => $r['raw']['status'] ?? null,
            'epayco_msg'   => $r['message'] ? mb_substr($r['message'], 0, 160) : null,
        ]);

        return $tx;
    }

    /**
     * Smart Checkout v2 (Nequi/DaviPlata). En vez de forzar un endpoint directo
     * de billetera (que la cuenta puede no tener habilitado), crea una SESIÓN de
     * checkout en ePayco y devuelve la transacción PENDIENTE con la URL del
     * bridge (WebView). El pago lo completa el usuario en el checkout OFICIAL de
     * ePayco; la confirmación REAL llega por webhook. Nunca activa membresía aquí.
     *
     * @param  string  $method  nequi|daviplata (método SOLICITADO; ePayco mostrará
     *                          los que tenga disponibles).
     */
    public function startCheckoutSession(string $method, array $data, EpaycoApiClient $api): PaymentTransaction
    {
        $tx = $this->createOrReuse($data);

        // Anti doble pago / idempotencia: si ya está aprobada o ya hay una sesión
        // viva creada para esta transacción, se REUTILIZA (no se crea otra).
        if ($tx->status === PaymentTransaction::STATUS_APPROVED) {
            return $tx;
        }
        // Idempotencia: si ya hay un bridge vivo para esta transacción (con o sin
        // sessionId, p. ej. fallback con llave pública), se REUTILIZA.
        $existingFlow = is_array($tx->raw_response) ? ($tx->raw_response['flow'] ?? null) : null;
        if ($tx->isInFlight() && $tx->checkout_url && $existingFlow === 'smart_checkout') {
            return $tx;
        }

        $this->transitionTo($tx, PaymentTransaction::STATUS_PENDING, [
            'failure_reason' => null,
            'method'         => $method,
        ]);

        $member = $tx->member_id ? Member::find($tx->member_id) : null;
        $plan   = $tx->plan_id ? Plan::find($tx->plan_id) : null;
        $c      = is_array($tx->customer) ? $tx->customer : [];

        $payload = [
            'checkout_version'         => '2',
            'name'                     => 'Iron Body',
            'currency'                 => strtoupper($tx->currency),
            // Monto AUTORITATIVO (precio del plan en BD; ver createOrReuse).
            'amount'                   => number_format((float) $tx->amount, 2, '.', ''),
            'description'              => $tx->description
                ?: ('Membresía Iron Body' . ($plan ? ' - ' . $plan->name : '')),
            'lang'                     => 'ES',
            'invoice'                  => $tx->reference,
            'country'                  => 'CO',
            'response'                 => $this->responseUrl(),
            'confirmation'             => $this->confirmationUrl(),
            'methodsDisable'           => [],
            'method'                   => 'POST',
            'dues'                     => 1,
            'noRedirectOnClose'        => false,
            'uniqueTransactionPerBill' => true,
            'billing' => [
                'email'       => $c['email'] ?? ($member?->email),
                'name'        => $c['name'] ?? ($member?->full_name),
                'address'     => $c['address'] ?? null,
                'typeDoc'     => $c['doc_type'] ?? 'CC',
                'numberDoc'   => $c['doc_number'] ?? ($member?->document_number),
                'callingCode' => '+57',
                'mobilePhone' => $this->normalizeCoPhone($c['phone'] ?? ($member?->phone ?? '')),
            ],
            'extras' => [
                // extra1 = referencia interna → el webhook encuentra la transacción.
                'extra1' => $tx->reference,
                'extra2' => (string) $tx->member_id,
                'extra3' => (string) $tx->plan_id,
                'extra4' => $method, // método solicitado (no se fuerza en ePayco)
            ],
        ];

        $r = $api->createCheckoutSession($payload);
        $sessionId = $r['ok'] ? ($r['session_id'] ?? null) : null;

        // Definitivo: si session/create NO devuelve sessionId pero hay LLAVE
        // PÚBLICA, NO se marca failed. El bridge abrirá el checkout con la llave
        // pública + datos del backend (fallback). Solo se falla si no hay forma
        // de abrir el checkout (sin sessionId y sin llave pública).
        $publicKey = (string) (config('services.epayco.public_key') ?? '');
        if (! $sessionId && $publicKey === '') {
            return $this->transitionTo($tx, PaymentTransaction::STATUS_FAILED, [
                'failure_reason' => $r['message'] ?? 'No pudimos iniciar el pago con ePayco.',
                'raw_response'   => $this->safeRaw((array) ($r['raw'] ?? [])),
            ]);
        }

        // SIEMPRE se expone NUESTRO bridge (carga checkout-v2.js; intercepta el
        // retorno a response_url). Si ePayco dio una URL directa, se guarda meta.
        $bridgeUrl = $this->checkoutBridgeUrl($tx->reference);
        $raw = is_array($tx->raw_response) ? $tx->raw_response : [];
        $raw['flow'] = 'smart_checkout';
        $raw['session_id'] = $sessionId; // puede ser null → bridge usa fallback
        $raw['requested_method'] = $method;
        if (! empty($r['checkout_url'])) {
            $raw['provider_checkout_url'] = $r['checkout_url'];
        }

        $tx = $this->transitionTo($tx, PaymentTransaction::STATUS_PENDING, [
            'failure_reason' => null,
            'checkout_url'   => $bridgeUrl,
            'raw_response'   => $raw,
        ]);

        Log::info('ePayco smart checkout listo', [
            'reference'   => $tx->reference,
            'method'      => $method,
            'mode'        => $sessionId ? 'session' : 'public_key_fallback',
        ]);

        return $tx;
    }

    /** URL FIRMADA del bridge de checkout (TTL corto). No expone el sessionId. */
    public function checkoutBridgeUrl(string $reference): string
    {
        $ttl = (int) config('services.epayco.checkout_bridge_ttl', 900);
        $exp = time() + max(60, $ttl);
        $token = $this->bridgeToken($reference, $exp);

        return url('/payments/epayco/checkout-bridge/' . rawurlencode($reference))
            . '?exp=' . $exp . '&t=' . $token;
    }

    /** Firma HMAC del bridge (clave de app, NUNCA p_key). */
    protected function bridgeToken(string $reference, int $exp): string
    {
        return hash_hmac('sha256', $reference . '|' . $exp, (string) config('app.key'));
    }

    /** Valida la firma + vigencia de una URL de bridge. */
    public function verifyBridgeToken(string $reference, int $exp, string $token): bool
    {
        if ($exp < time()) {
            return false;
        }
        return hash_equals($this->bridgeToken($reference, $exp), $token);
    }

    /** Normaliza un teléfono colombiano a 10 dígitos (quita +57/57/símbolos). */
    protected function normalizeCoPhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) === 12 && str_starts_with($digits, '57')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    /**
     * Devuelve la transacción por referencia, refrescando desde ePayco si sigue
     * en curso (permite recuperar el estado aunque la app perdiera internet).
     */
    public function statusFor(string $reference): ?PaymentTransaction
    {
        $tx = PaymentTransaction::where('reference', $reference)->first();
        if (!$tx) {
            return null;
        }
        if ($tx->isInFlight()) {
            try {
                $this->refreshFromProvider($tx);
            } catch (Throwable $e) {
                Log::warning('ePayco refresh status failed', [
                    'reference' => $reference,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $tx->fresh();
    }

    /**
     * Maneja el webhook de confirmación de ePayco. Idempotente: si la
     * transacción ya está aprobada, no se vuelve a procesar.
     */
    public function handleConfirmation(array $payload): ?PaymentTransaction
    {
        $reference = $payload['x_extra1']
            ?? $payload['x_id_invoice']
            ?? $payload['x_invoice']
            ?? $payload['x_extra4']
            ?? null;

        $tx = $reference
            ? PaymentTransaction::where('reference', $reference)->first()
            : null;

        // Fallback: ubicar por ref_payco/transaction_id ya guardado (Smart
        // Checkout puede no devolver el invoice en algunos webhooks).
        if (!$tx) {
            $providerRef = $payload['x_ref_payco'] ?? $payload['x_transaction_id'] ?? null;
            if ($providerRef) {
                $tx = PaymentTransaction::where('provider_ref', $providerRef)->first();
            }
        }

        if (!$tx) {
            Log::warning('ePayco confirmation: transacción no encontrada', [
                'reference' => $reference,
            ]);
            return null;
        }

        $cfg = config('services.epayco');
        $keysConfigured = !empty($cfg['p_cust_id_cliente']) && !empty($cfg['p_key']);

        $signatureOk = $this->verifySignature($payload);

        // Si no podemos validar por firma (faltan p_key/p_cust_id) confirmamos
        // contra la API de ePayco usando x_ref_payco (fuente de verdad).
        $remote = null;
        if (!$signatureOk && !empty($payload['x_ref_payco'])) {
            $remote = $this->queryValidationApi($payload['x_ref_payco']);
        }

        // Fallback SOLO sandbox: en modo pruebas y sin llaves de firma
        // configuradas, se confía en el x_cod_response del webhook para poder
        // probar el flujo. En producción (test=false o con p_key) NUNCA aplica.
        $sandboxTrust = $cfg['test'] && !$keysConfigured;

        if (!$signatureOk && !$remote && !$sandboxTrust) {
            Log::warning('ePayco confirmation: no validada (firma y API fallaron)', [
                'reference' => $tx->reference,
            ]);
            return $tx; // no se cambia el estado ante datos no confiables
        }
        if ($sandboxTrust && !$signatureOk && !$remote) {
            Log::info('ePayco confirmation: aceptada por SANDBOX (sin p_key)', [
                'reference' => $tx->reference,
            ]);
        }

        $codResponse = (int) ($payload['x_cod_response']
            ?? $payload['x_cod_transaction_state']
            ?? ($remote['x_cod_transaction_state'] ?? 0));
        $reason = $payload['x_response_reason_text']
            ?? $payload['x_response']
            ?? null;
        $providerRef = $payload['x_ref_payco']
            ?? $payload['x_transaction_id']
            ?? ($remote['x_ref_payco'] ?? null);

        // Validar monto (no aceptar si difiere del esperado).
        $paidAmount = (float) ($payload['x_amount'] ?? ($remote['x_amount'] ?? 0));
        if ($paidAmount > 0 && abs($paidAmount - (float) $tx->amount) > 0.5) {
            Log::warning('ePayco confirmation: monto no coincide', [
                'reference' => $tx->reference,
                'expected'  => $tx->amount,
                'got'       => $paidAmount,
            ]);
            return $this->transitionTo($tx, PaymentTransaction::STATUS_FAILED, [
                'failure_reason' => 'Monto no coincide con el esperado',
                'provider_ref'   => $providerRef,
                'raw_response'   => $this->safeRaw($payload),
            ]);
        }

        // Validar moneda: solo COP. Otra moneda con monto presente = no confiable.
        $currency = strtoupper((string) ($payload['x_currency_code'] ?? ($remote['x_currency_code'] ?? '')));
        if ($currency !== '' && $currency !== strtoupper((string) $tx->currency)) {
            Log::warning('ePayco confirmation: moneda no coincide', [
                'reference' => $tx->reference,
                'expected'  => $tx->currency,
                'got'       => $currency,
            ]);
            return $this->transitionTo($tx, PaymentTransaction::STATUS_FAILED, [
                'failure_reason' => 'Moneda no coincide con la esperada',
                'provider_ref'   => $providerRef,
                'raw_response'   => $this->safeRaw($payload),
            ]);
        }

        $newStatus = $this->mapEpaycoState($codResponse);

        return $this->transitionTo($tx, $newStatus, [
            'failure_reason' => $newStatus === PaymentTransaction::STATUS_APPROVED ? null : $reason,
            'provider_ref'   => $providerRef,
            'raw_response'   => $this->safeRaw($payload),
        ]);
    }

    /** Refresca el estado consultando la API pública de validación de ePayco. */
    public function refreshFromProvider(PaymentTransaction $tx): void
    {
        $ref = $tx->provider_ref;
        if (!$ref) {
            return; // todavía no hay ref_payco (usuario no volvió de ePayco)
        }
        $remote = $this->queryValidationApi($ref);
        if (!$remote) {
            return;
        }
        $cod = (int) ($remote['x_cod_transaction_state']
            ?? $remote['x_cod_response'] ?? 0);
        $status = $this->mapEpaycoState($cod);
        // Preservar la URL del banco (PSE) entre consultas de estado: la
        // respuesta de transacción no la trae y se perdería.
        $prev = is_array($tx->raw_response) ? $tx->raw_response : [];
        if (!empty($prev['urlbanco']) && empty($remote['urlbanco'])) {
            $remote['urlbanco'] = $prev['urlbanco'];
        }
        $this->transitionTo($tx, $status, [
            'failure_reason' => $status === PaymentTransaction::STATUS_APPROVED
                ? null
                : ($remote['x_response_reason_text'] ?? $remote['x_transaction_state'] ?? null),
            'raw_response' => $this->safeRaw($remote),
        ]);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Transición de estado segura e idempotente. La orden solo puede marcarse
     * pagada UNA vez (de ahí no se vuelve a salir hacia otro estado, y no se
     * duplica el registro legado ni la extensión de membresía).
     */
    protected function transitionTo(
        PaymentTransaction $tx,
        string $status,
        array $attrs = []
    ): PaymentTransaction {
        return DB::transaction(function () use ($tx, $status, $attrs) {
            /** @var PaymentTransaction $fresh */
            $fresh = PaymentTransaction::lockForUpdate()->find($tx->id);

            // Ya aprobada → estado terminal, no se reprocesa (anti doble pago).
            if ($fresh->status === PaymentTransaction::STATUS_APPROVED) {
                return $fresh;
            }
            // No degradar un estado finalizado a "pending/processing".
            if ($fresh->isSettled()
                && in_array($status, [
                    PaymentTransaction::STATUS_PENDING,
                    PaymentTransaction::STATUS_PROCESSING,
                ], true)) {
                return $fresh;
            }

            $fresh->fill(array_filter($attrs, fn ($v) => $v !== null));
            if (array_key_exists('failure_reason', $attrs)) {
                $fresh->failure_reason = $attrs['failure_reason'];
            }
            $fresh->status = $status;

            if ($status === PaymentTransaction::STATUS_APPROVED && !$fresh->paid_at) {
                $fresh->paid_at = now();
            }
            $fresh->save();

            if ($status === PaymentTransaction::STATUS_APPROVED) {
                $this->onApproved($fresh);
                // Real-time: la app refresca al instante (sin polling). Se emiten
                // las tres señales que escucha el cliente para destrabar el Home:
                // pago actualizado, membresía activada y snapshot global (AppState).
                \App\Services\RealtimeEvents::payment($fresh->member_id);
                \App\Services\RealtimeEvents::membership($fresh->member_id);
                \App\Services\RealtimeEvents::appState($fresh->member_id);
            }

            // Pago rechazado/fallido → notifica al miembro y al CRM (ADITIVO,
            // best-effort, idempotente por event_key payment_rejected_REF).
            if ($status === PaymentTransaction::STATUS_FAILED) {
                try {
                    $member = $fresh->member_id ? Member::find($fresh->member_id) : null;
                    app(NotificationService::class)->notifyPaymentRejected($member, $fresh);
                } catch (Throwable $e) {
                    Log::warning('Notificación de pago rechazado falló', ['error' => $e->getMessage()]);
                }
            }

            return $fresh;
        });
    }

    /**
     * Al aprobarse: crea el registro legado en `payments` y extiende membresía.
     * Si llega member_id, usa su user_id enlazado para mantener una sola ficha
     * CRM. Best-effort: nunca rompe la confirmación.
     */
    protected function onApproved(PaymentTransaction $tx): void
    {
        try {
            if (!$tx->user_id && $tx->member_id) {
                $member = Member::with('user')->find($tx->member_id);
                if ($member?->user_id) {
                    $tx->forceFill(['user_id' => $member->user_id])->save();
                }
            }

            if (!$tx->user_id || !User::whereKey($tx->user_id)->exists()) {
                return; // app con usuario mock: no hay a quién asociar
            }
            // Evitar duplicado legado por la misma referencia.
            $payment = Payment::firstOrCreate(
                ['reference' => $tx->reference],
                [
                    'user_id' => $tx->user_id,
                    'member_id' => $tx->member_id,
                    'plan_id' => $tx->plan_id,
                    'amount'  => $tx->amount,
                    'method'  => 'epayco',
                    'status'  => 'paid',
                    'paid_at' => $tx->paid_at ?? now(),
                ]
            );
            if ($payment->wasRecentlyCreated && $tx->plan_id) {
                $this->extendMembership($payment);
            }

            if ($tx->member_id) {
                Member::whereKey($tx->member_id)->update(['status' => Member::STATUS_ACTIVE]);
            }

            // Notificaciones (ADITIVO; no altera el resultado del pago). El
            // NotificationService es idempotente por event_key: aunque el
            // webhook reintente, la notificación se crea una sola vez.
            $member   = $tx->member_id ? Member::find($tx->member_id) : null;
            $notifier = app(NotificationService::class);
            $notifier->notifyPaymentApproved($member, $tx);
            if ($tx->plan_id) {
                $plan = Plan::find($tx->plan_id);
                $endDate = $tx->user_id ? optional(User::find($tx->user_id))->membership_end_date : null;
                $notifier->notifyMembershipActivated($member, [
                    'name'                => $plan?->name,
                    'id'                  => $tx->plan_id,
                    'membership_end_date' => $endDate,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('ePayco onApproved post-proceso falló', [
                'reference' => $tx->reference,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /** Espejo de PaymentController::applyMembershipExtension (sin tocarlo). */
    protected function extendMembership(Payment $payment): void
    {
        $user = User::find($payment->user_id);
        $plan = $payment->plan_id ? Plan::find($payment->plan_id) : null;
        if (!$user || !$plan || (int) $plan->duration_days <= 0) {
            return;
        }
        $paidDate = $payment->paid_at
            ? Carbon::parse($payment->paid_at)->startOfDay()
            : Carbon::today();
        $currentEnd = $user->membership_end_date
            ? Carbon::parse($user->membership_end_date)->startOfDay()
            : null;
        $baseDate = $currentEnd && $currentEnd->greaterThan($paidDate)
            ? $currentEnd
            : $paidDate;
        if (!$currentEnd || $currentEnd->lessThan($paidDate) || !$user->membership_start_date) {
            $user->membership_start_date = $paidDate->toDateString();
        }
        $user->membership_end_date = $baseDate->copy()
            ->addDays((int) $plan->duration_days)->toDateString();
        $user->plan = $plan->name;
        $user->status = 'active';
        $user->save();
    }

    /**
     * Mapea el código de estado de ePayco a nuestro estado interno.
     * 1 Aceptada · 2 Rechazada · 3 Pendiente · 4 Fallida · 6 Reversada
     * 7 Retenida · 8 Iniciada · 9 Expirada · 10/11 Abandonada/Cancelada
     */
    public function mapEpaycoState(int $cod): string
    {
        return match ($cod) {
            1       => PaymentTransaction::STATUS_APPROVED,
            3, 7, 8 => PaymentTransaction::STATUS_PROCESSING,
            6, 10, 11 => PaymentTransaction::STATUS_CANCELLED,
            9       => PaymentTransaction::STATUS_EXPIRED,
            2, 4    => PaymentTransaction::STATUS_FAILED,
            default => PaymentTransaction::STATUS_PROCESSING,
        };
    }

    /**
     * Verifica la firma del webhook:
     *   sha256(p_cust_id^p_key^x_ref_payco^x_transaction_id^x_amount^x_currency_code)
     * Si faltan p_cust_id/p_key devuelve false (se valida por API en su lugar).
     */
    protected function verifySignature(array $p): bool
    {
        $cfg = config('services.epayco');
        $custId = $cfg['p_cust_id_cliente'];
        $pKey   = $cfg['p_key'];
        $sig    = $p['x_signature'] ?? null;

        if (!$custId || !$pKey || !$sig
            || empty($p['x_ref_payco']) || empty($p['x_transaction_id'])) {
            return false;
        }
        $calc = hash('sha256', implode('^', [
            $custId,
            $pKey,
            $p['x_ref_payco'],
            $p['x_transaction_id'],
            $p['x_amount'] ?? '',
            $p['x_currency_code'] ?? '',
        ]));

        return hash_equals($calc, (string) $sig);
    }

    /**
     * Consulta el estado de una transacción por ref_payco usando el SDK
     * OFICIAL (`charge->transaction` → host correcto `secure.payco.co`).
     * Antes se usaba el host inválido `api.secure.epayco.co` (DNS error).
     * Devuelve el array de datos `x_*` o null si no se pudo consultar.
     */
    protected function queryValidationApi(string $refPayco): ?array
    {
        $cfg = config('services.epayco');
        if (empty($cfg['public_key']) || empty($cfg['private_key'])) {
            return null;
        }
        try {
            $epayco = new \Epayco\Epayco([
                'apiKey'     => $cfg['public_key'],
                'privateKey' => $cfg['private_key'],
                'test'       => (bool) $cfg['test'],
                'lenguage'   => 'ES',
            ]);
            $resp = $epayco->charge->transaction($refPayco);
            $arr = is_array($resp)
                ? $resp
                : (is_object($resp)
                    ? json_decode(json_encode($resp), true)
                    : (is_string($resp) ? json_decode($resp, true) : null));
            if (!is_array($arr)) {
                return null;
            }
            // El SDK devuelve { success, data:{ x_cod_response, ... } }.
            $data = $arr['data'] ?? $arr;
            return is_array($data) && $data !== [] ? $data : null;
        } catch (Throwable $e) {
            Log::warning('ePayco consulta de transacción falló', [
                'code' => $e->getCode(),
            ]);
            return null;
        }
    }

    protected function generateReference(): string
    {
        return 'IRON-' . now()->format('Ymd') . '-'
            . strtoupper(Str::random(6)) . '-' . substr((string) time(), -5);
    }

    /**
     * ePayco exige una IP pública del cliente en charge. En dev, detrás de
     * ngrok o si el proxy no propagó X-Forwarded-For, la IP vista es
     * loopback/privada y ePayco responde "Error validando datos". Sustituimos
     * por una IP pública neutral cuando detectamos rangos no enrutables.
     */
    protected function sanitizeClientIp(?string $ip): string
    {
        $fallback = '200.21.179.249'; // IP pública neutral (usada como default).
        if (!$ip) {
            return $fallback;
        }
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $fallback;
        }
        // FILTER_FLAG_NO_PRIV_RANGE + NO_RES_RANGE rechaza 10/8, 172.16/12,
        // 192.168/16, 127/8, 169.254/16, etc. → si no pasa, no es pública.
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
        return $isPublic ? $ip : $fallback;
    }

    /** Solo datos de contacto/facturación; jamás datos de tarjeta. */
    protected function sanitizeCustomer(array $c): array
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

    /**
     * Normaliza la tarjeta para ePayco: mes a 2 dígitos y AÑO A 4 DÍGITOS
     * (la app envía MM/AA → "27"; ePayco exige "2027"). No se loguea nada.
     */
    protected function normalizeCard(array $card): array
    {
        if (empty($card)) {
            return [];
        }
        $month = preg_replace('/\D/', '', (string) ($card['exp_month'] ?? ''));
        $year  = preg_replace('/\D/', '', (string) ($card['exp_year'] ?? ''));
        if (strlen($month) === 1) {
            $month = '0' . $month;
        }
        if (strlen($year) === 2) {
            $year = '20' . $year; // 27 → 2027
        }
        return [
            'number'    => preg_replace('/\s+/', '', (string) ($card['number'] ?? '')),
            'exp_month' => $month,
            'exp_year'  => $year,
            'cvc'       => preg_replace('/\D/', '', (string) ($card['cvc'] ?? '')),
        ];
    }

    /** Quita posibles campos sensibles antes de persistir el payload crudo. */
    protected function safeRaw(array $raw): array
    {
        unset(
            $raw['x_signature'],
            $raw['p_key'],
            $raw['x_card'],
            $raw['x_cardnumber'],
            $raw['cardnumber'],
            $raw['cvc'],
            $raw['cvv']
        );
        return $raw;
    }

    public function responseUrl(): string
    {
        return config('services.epayco.response_url') ?: url('/api/payments/epayco/response');
    }

    public function confirmationUrl(): string
    {
        return config('services.epayco.confirmation_url') ?: url('/api/payments/epayco/confirmation');
    }
}
