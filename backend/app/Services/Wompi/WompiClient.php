<?php

namespace App\Services\Wompi;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cliente HTTP de la API de Wompi. ÚNICO punto que habla con Wompi desde el
 * backend. Reglas:
 *
 *  - Llave PÚBLICA  → endpoints públicos (merchant, instituciones PSE, tokens).
 *  - Llave PRIVADA  → crear/consultar transacciones. Nunca sale del backend.
 *  - Timeout + connect timeout configurables.
 *  - Reintentos con backoff SOLO en GET (idempotente). Un POST /transactions
 *    NUNCA se reintenta a ciegas (anti doble cobro): la idempotencia se controla
 *    arriba (referencia única) y se reconcilia por GET.
 *  - Logs SANITIZADOS: método, path, status, correlation-id, code de error. Sin
 *    Authorization, sin tokens, sin datos del pagador, sin cuerpos crudos.
 *  - Toda respuesta se normaliza a un array estable; nada de excepciones que
 *    burbujeen datos sensibles.
 *
 * Forma de retorno (siempre):
 *   [
 *     'ok'         => bool,    // 2xx
 *     'status'     => int,     // http status (0 = fallo de transporte)
 *     'data'       => array,   // body['data'] o body
 *     'error'      => ?string, // mensaje legible (sanitizado)
 *     'error_code' => ?string, // código de Wompi si lo hay
 *     'raw'        => array,   // body completo (ya sin secretos del comercio)
 *     'correlation_id' => string,
 *   ]
 */
class WompiClient
{
    public function __construct(private array $cfg) {}

    public static function fromConfig(): self
    {
        return new self((array) config('wompi'));
    }

    // ── Endpoints públicos (llave pública) ───────────────────────────────────

    /** GET /merchants/{public_key} — datos del comercio + tokens de aceptación. */
    public function getMerchant(): array
    {
        $public = (string) ($this->cfg['public_key'] ?? '');
        if ($public === '') {
            return $this->transportError('Wompi no está configurado (falta llave pública).');
        }

        return $this->request('GET', '/merchants/'.$public, auth: null);
    }

    /** GET /pse/financial_institutions — bancos PSE (llave pública). */
    public function getPseInstitutions(): array
    {
        return $this->request('GET', '/pse/financial_institutions', auth: 'public');
    }

    /** POST /tokens/nequi — token de suscripción Nequi (llave pública). */
    public function createNequiToken(string $phoneNumber): array
    {
        return $this->request('POST', '/tokens/nequi', auth: 'public', body: [
            'phone_number' => $phoneNumber,
        ]);
    }

    // ── Endpoints privados (llave privada) ───────────────────────────────────

    /**
     * POST /transactions — crea la transacción. Sin reintento (anti doble cobro).
     * La idempotencia se garantiza por `reference` única y, opcionalmente, por
     * cabecera Idempotency-Key.
     */
    public function createTransaction(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/transactions', auth: 'private', body: $payload, idempotencyKey: $idempotencyKey, retry: false);
    }

    /** GET /transactions/{id} — consulta de estado (reconciliación). */
    public function getTransaction(string $id): array
    {
        return $this->request('GET', '/transactions/'.$id, auth: 'private');
    }

    // ── Fuentes de pago / cobro recurrente (payment_sources) ─────────────────
    // Los tres métodos son INERTES si WOMPI_RECURRING_ENABLED=false: devuelven un
    // error controlado y NO tocan la red. Así el módulo queda "preparado, apagado"
    // sin poder cobrar nada por accidente. Ninguno loguea token/datos sensibles.

    /**
     * POST /payment_sources — crea una fuente de pago tokenizada (CARD/NEQUI) para
     * cobros recurrentes. Llave PRIVADA. Sin reintento a ciegas (no aplica a un
     * POST con efecto). El `token` (tok_.../nequi) NUNCA se loguea.
     *
     * @param  array  $data  { type, token, customer_email, acceptance_token, accept_personal_auth }
     */
    public function createPaymentSource(array $data, ?string $idempotencyKey = null): array
    {
        if (! $this->recurringEnabled()) {
            return $this->recurringDisabledError();
        }

        $payload = array_filter([
            'type' => strtoupper((string) ($data['type'] ?? 'CARD')),
            'token' => $data['token'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'acceptance_token' => $data['acceptance_token'] ?? null,
            'accept_personal_auth' => $data['accept_personal_auth'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // Validación mínima ANTES de gastar una llamada (y sin filtrar el token).
        if (empty($payload['token']) || empty($payload['customer_email'])
            || empty($payload['acceptance_token']) || empty($payload['accept_personal_auth'])) {
            return $this->transportError('Datos insuficientes para crear la fuente de pago.');
        }

        return $this->request('POST', '/payment_sources', auth: 'private', body: $payload, idempotencyKey: $idempotencyKey, retry: false);
    }

    /** GET /payment_sources/{id} — estado de la fuente (AVAILABLE/DECLINED/...). */
    public function getPaymentSource(string|int $id): array
    {
        if (! $this->recurringEnabled()) {
            return $this->recurringDisabledError();
        }
        $id = trim((string) $id);
        if ($id === '') {
            return $this->transportError('Falta el id de la fuente de pago.');
        }

        return $this->request('GET', '/payment_sources/'.$id, auth: 'private');
    }

    /**
     * POST /transactions con `payment_source_id` — COBRO RECURRENTE sin presencia
     * del usuario. Llave PRIVADA. Sin reintento a ciegas (anti doble cobro): la
     * idempotencia se garantiza aguas arriba (reference única + billing_period).
     *
     * SEPARACIÓN DE RESPONSABILIDADES: la firma de integridad la calcula el caller
     * (WompiSignatureService) y llega ya en `$payload['signature']`; este cliente
     * NUNCA conoce el integrity_secret. Igual que el flujo de pago único.
     *
     * @param  array  $payload  { payment_source_id, amount_in_cents, currency,
     *                          reference, signature, customer_email,
     *                          recurrent, payment_method?:{installments} }
     */
    public function chargeWithPaymentSource(array $payload, ?string $idempotencyKey = null): array
    {
        if (! $this->recurringEnabled()) {
            return $this->recurringDisabledError();
        }
        if (empty($payload['payment_source_id']) || empty($payload['reference'])
            || empty($payload['amount_in_cents']) || empty($payload['signature'])) {
            return $this->transportError('Datos insuficientes para el cobro recurrente. No se realizó ningún cobro.');
        }

        return $this->request('POST', '/transactions', auth: 'private', body: $payload, idempotencyKey: $idempotencyKey, retry: false);
    }

    /**
     * POST a una URL ABSOLUTA devuelta por Wompi (p. ej. `url_services` de
     * DaviPlata para el ciclo OTP). Misma sanitización y normalización; sin
     * reintento (operación con efecto). El OTP/código del body NO se loguea.
     *
     * @param  string|null  $bearer  token Bearer EXPLÍCITO (token de url_services o
     *                               authorization.access_token). Si se entrega, se
     *                               usa en vez de la llave pública/privada.
     */
    public function postAbsolute(string $url, array $body, ?string $auth = 'public', ?string $bearer = null): array
    {
        $correlationId = (string) Str::uuid();
        try {
            $req = $bearer !== null && $bearer !== ''
                ? $this->baseRequest(null, null, $correlationId)->withToken($bearer)
                : $this->baseRequest($auth, null, $correlationId);
            $response = $req->post($url, $body);
            $raw = $this->safeJson($response->json());
            $ok = $response->successful();

            Log::info('wompi.http.absolute', [
                'host' => parse_url($url, PHP_URL_HOST),
                'path' => parse_url($url, PHP_URL_PATH),
                'status' => $response->status(),
                'ok' => $ok,
                'correlation_id' => $correlationId,
            ]);

            return [
                'ok' => $ok,
                'status' => $response->status(),
                'data' => is_array($raw['data'] ?? null) ? $raw['data'] : $raw,
                'error' => $ok ? null : $this->extractError($raw, $response->status()),
                'error_code' => $ok ? null : $this->extractErrorCode($raw),
                'raw' => $raw,
                'correlation_id' => $correlationId,
            ];
        } catch (Throwable $e) {
            Log::error('wompi.http.absolute.transport_error', [
                'host' => parse_url($url, PHP_URL_HOST),
                'type' => get_class($e),
                'correlation_id' => $correlationId,
            ]);

            return $this->transportError('No pudimos comunicarnos con la pasarela. Intenta nuevamente.', $correlationId);
        }
    }

    // ── Núcleo ───────────────────────────────────────────────────────────────

    /**
     * @param  string  $method  GET|POST
     * @param  string  $path  relativo a api_url (con / inicial)
     * @param  string|null  $auth  'public' | 'private' | null (sin Authorization)
     * @param  array|null  $body  cuerpo JSON (POST)
     * @param  bool|null  $retry  forzar/inhabilitar reintento (default: solo GET)
     */
    public function request(
        string $method,
        string $path,
        ?string $auth = 'private',
        ?array $body = null,
        ?string $idempotencyKey = null,
        ?bool $retry = null
    ): array {
        $method = strtoupper($method);
        $correlationId = (string) Str::uuid();
        $url = rtrim((string) $this->cfg['api_url'], '/').$path;
        $isGet = $method === 'GET';
        $shouldRetry = $retry ?? $isGet; // por defecto, solo GET reintenta

        try {
            $req = $this->baseRequest($auth, $idempotencyKey, $correlationId);
            if ($shouldRetry) {
                $times = max(1, (int) ($this->cfg['retry_times'] ?? 2));
                $sleep = max(0, (int) ($this->cfg['retry_sleep_ms'] ?? 300));
                $req = $req->retry($times, $sleep, throw: false);
            }

            $response = $isGet
                ? $req->get($url, $body ?? [])
                : $req->send($method, $url, ['json' => $body ?? []]);

            $raw = $this->safeJson($response->json());
            $ok = $response->successful();

            $result = [
                'ok' => $ok,
                'status' => $response->status(),
                'data' => is_array($raw['data'] ?? null) ? $raw['data'] : $raw,
                'error' => $ok ? null : $this->extractError($raw, $response->status()),
                'error_code' => $ok ? null : $this->extractErrorCode($raw),
                'raw' => $raw,
                'correlation_id' => $correlationId,
            ];

            Log::info('wompi.http', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'ok' => $ok,
                'error_code' => $result['error_code'],
                'correlation_id' => $correlationId,
            ]);

            return $result;
        } catch (Throwable $e) {
            // Fallo de transporte (timeout/DNS/SSL): controlado, sin filtrar datos.
            Log::error('wompi.http.transport_error', [
                'method' => $method,
                'path' => $path,
                'type' => get_class($e),
                'detail' => mb_substr($e->getMessage(), 0, 200),
                'correlation_id' => $correlationId,
            ]);

            return $this->transportError('No pudimos comunicarnos con la pasarela. Intenta nuevamente.', $correlationId);
        }
    }

    private function baseRequest(?string $auth, ?string $idempotencyKey, string $correlationId): PendingRequest
    {
        $req = Http::asJson()
            ->acceptJson()
            ->timeout((int) ($this->cfg['timeout'] ?? 30))
            ->connectTimeout((int) ($this->cfg['connect_timeout'] ?? 10))
            ->withHeaders(['X-Correlation-Id' => $correlationId]);

        if ($auth === 'public') {
            $req = $req->withToken((string) ($this->cfg['public_key'] ?? ''));
        } elseif ($auth === 'private') {
            $req = $req->withToken((string) ($this->cfg['private_key'] ?? ''));
        }
        // null → algunos endpoints llevan la llave en el path (getMerchant).

        if ($idempotencyKey) {
            $req = $req->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $req;
    }

    /** ¿El módulo de cobro recurrente está habilitado? (apagado por defecto). */
    private function recurringEnabled(): bool
    {
        return (bool) data_get($this->cfg, 'recurring.enabled', false);
    }

    /** Error controlado cuando el módulo recurrente está apagado (no llama a Wompi). */
    private function recurringDisabledError(): array
    {
        return [
            'ok' => false,
            'status' => 0,
            'data' => [],
            'error' => 'El pago automático no está habilitado.',
            'error_code' => 'RECURRING_DISABLED',
            'raw' => [],
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    private function transportError(string $message, ?string $correlationId = null): array
    {
        return [
            'ok' => false,
            'status' => 0,
            'data' => [],
            'error' => $message,
            'error_code' => 'TRANSPORT_ERROR',
            'raw' => [],
            'correlation_id' => $correlationId ?? (string) Str::uuid(),
        ];
    }

    /** Decodifica de forma segura (Wompi siempre responde JSON). */
    private function safeJson(mixed $json): array
    {
        return is_array($json) ? $json : [];
    }

    private function extractError(array $raw, int $status): string
    {
        // Wompi: { "error": { "type": "...", "messages": {...}, "reason": "..." } }
        $reason = data_get($raw, 'error.reason');
        if (is_string($reason) && $reason !== '') {
            return $reason;
        }
        $type = data_get($raw, 'error.type');
        if (is_string($type) && $type !== '') {
            return $type;
        }

        return "Error de la pasarela (HTTP {$status}).";
    }

    private function extractErrorCode(array $raw): ?string
    {
        $type = data_get($raw, 'error.type');

        return is_string($type) && $type !== '' ? $type : null;
    }
}
