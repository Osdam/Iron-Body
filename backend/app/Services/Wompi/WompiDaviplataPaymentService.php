<?php

namespace App\Services\Wompi;

use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cobro con DAVIPLATA vía Wompi + ciclo OTP nativo (UI Iron Body).
 *
 * Contrato real de Wompi: tras crear la transacción, `data.payment_method.extra
 * .url_services` trae `{ token, code_otp_send, code_otp_validate }` — que pueden
 * NO venir en la primera respuesta (se consulta con backoff acotado).
 *
 *   - sendOtp:     Bearer = url_services.token (o el último access_token) →
 *                  POST code_otp_send → guarda `data.authorization.access_token`.
 *   - validateOtp: Bearer = último access_token → POST code_otp_validate con
 *                  `{"code": "<otp>"}` (NUNCA "otp") → reconsulta estado.
 *   - resendOtp:   reusa el token vigente, lo reemplaza con el nuevo access_token.
 *
 * Anti-duplicados: lock por referencia (no hay dos send/validate simultáneos).
 * Seguridad: el OTP/código y los tokens/URLs NUNCA se loguean ni se exponen a la
 * app. La activación de membresía sigue siendo idempotente y solo por estado real.
 */
class WompiDaviplataPaymentService extends AbstractWompiPaymentService
{
    public static function make(): self
    {
        return new self(
            WompiClient::fromConfig(),
            WompiTransactionService::make(),
            WompiSignatureService::fromConfig(),
            WompiAcceptanceService::make(),
            (array) config('wompi'),
        );
    }

    protected function method(): string
    {
        return 'daviplata';
    }

    protected function buildPaymentMethod(array $data, PaymentTransaction $transaction): ?array
    {
        $legalIdType = strtoupper((string) (
            $data['user_legal_id_type'] ?? $data['doc_type']
            ?? $transaction->customer_legal_id_type ?? 'CC'
        ));
        $legalId = (string) (
            $data['user_legal_id'] ?? $data['doc_number']
            ?? $transaction->customer_legal_id ?? ''
        );
        if ($legalId === '') {
            return null;
        }

        return array_filter([
            'type'                => 'DAVIPLATA',
            'user_legal_id'       => $legalId,
            'user_legal_id_type'  => $legalIdType,
            'payment_description' => mb_substr($this->description($transaction), 0, 60),
            'sandbox_status'      => $data['sandbox_status'] ?? null, // solo sandbox
        ], fn ($v) => $v !== null);
    }

    /** Inicia el pago. Intenta resolver url_services (best-effort, no fatal). */
    public function start(array $data, ?string $ip = null, ?string $userAgent = null): PaymentTransaction
    {
        $tx = $this->process($data, $ip, $userAgent);
        $this->initOtpState($tx);
        if ($tx->wompi_transaction_id) {
            $this->ensureUrlServices($tx); // si aún no están, sendOtp reintentará
        }
        return $tx->fresh();
    }

    // ── OTP lifecycle ─────────────────────────────────────────────────────────

    public function sendOtp(PaymentTransaction $tx): array
    {
        return $this->withLock($tx, fn () => $this->doSend($tx, 'send'));
    }

    public function resendOtp(PaymentTransaction $tx): array
    {
        return $this->withLock($tx, function () use ($tx) {
            $meta = $this->otpMeta($tx);
            $resends = (int) ($meta['resends'] ?? 0);
            if ($resends >= $this->maxResends()) {
                return ['ok' => false, 'message' => 'Alcanzaste el máximo de reenvíos. Inicia el pago de nuevo.'];
            }
            $this->saveOtpMeta($tx, [
                'resends'    => $resends + 1,
                'attempts'   => 0,
                'expires_at' => now()->addMinutes($this->ttlMinutes())->timestamp,
            ]);
            return $this->doSend($tx, 'resend');
        });
    }

    public function validateOtp(PaymentTransaction $tx, string $code): array
    {
        return $this->withLock($tx, function () use ($tx, $code) {
            $meta = $this->otpMeta($tx);

            if ($this->otpExpired($meta)) {
                return ['ok' => false, 'expired' => true, 'message' => 'El código expiró. Solicita uno nuevo.'];
            }
            $attempts = (int) ($meta['attempts'] ?? 0);
            if ($attempts >= $this->maxAttempts()) {
                return ['ok' => false, 'message' => 'Superaste el número de intentos. Solicita un nuevo código.'];
            }

            $urls = $this->storedUrls($tx) ?? $this->ensureUrlServices($tx);
            $access = $meta['access_token'] ?? null;
            if (! $urls || ! $access) {
                // Sin token de envío consumido aún: pedir un nuevo código.
                return ['ok' => false, 'message' => 'Solicita un nuevo código para continuar.'];
            }

            // Wompi espera EXACTAMENTE {"code": "..."} (no "otp"). El código solo
            // se transmite; nunca se guarda ni se loguea.
            $res = $this->client->postAbsolute(
                $urls['validate'],
                ['code' => preg_replace('/\D/', '', $code)],
                'public',
                $access,
            );

            $this->saveOtpMeta($tx, ['attempts' => $attempts + 1]);
            $this->log('validate', $tx, $res['status'] ?? 0);

            if (! $res['ok']) {
                $remaining = max(0, $this->maxAttempts() - ($attempts + 1));
                return ['ok' => false, 'attempts_left' => $remaining, 'message' => 'Código incorrecto. Intentos restantes: '.$remaining];
            }

            // Algunos flujos rotan el token tras validar.
            $newAccess = data_get($res, 'data.authorization.access_token');
            if (is_string($newAccess) && $newAccess !== '') {
                $this->saveOtpMeta($tx, ['access_token' => $newAccess]);
            }

            // Nunca se aprueba localmente: se reconsulta el estado real.
            $this->refreshFromWompi($tx);

            return ['ok' => true, 'message' => 'Código validado. Confirmando tu pago…'];
        });
    }

    /** Reconsulta el estado real en Wompi y aplica la máquina de estados. */
    public function refreshFromWompi(PaymentTransaction $tx): void
    {
        if (! $tx->wompi_transaction_id) {
            return;
        }
        $res = $this->client->getTransaction($tx->wompi_transaction_id);
        if ($res['ok'] && ! empty($res['data']['id'])) {
            $this->tx->applyWompiTransaction($tx, $res['data']);
        }
    }

    // ── Núcleo del envío (sin lock; llamado dentro de withLock) ────────────────

    private function doSend(PaymentTransaction $tx, string $stage): array
    {
        $urls = $this->ensureUrlServices($tx);
        if (! $urls) {
            // url_services aún no disponible tras el backoff → NO es terminal: el
            // pago sigue en vuelo y el usuario puede reintentar en unos segundos.
            return [
                'ok'        => false,
                'preparing' => true,
                'message'   => 'Estamos preparando tu pago con DaviPlata. Intenta de nuevo en unos segundos.',
            ];
        }

        $tx->refresh();
        $meta = $this->otpMeta($tx);
        // send usa el token vigente: access_token si ya existe (reenvío), si no el
        // token inicial de url_services.
        $bearer = $meta['access_token'] ?? $meta['initial_token'] ?? $urls['token'];

        $res = $this->client->postAbsolute(
            $urls['send'],
            ['transaction_id' => $tx->wompi_transaction_id],
            'public',
            $bearer,
        );
        $this->log($stage, $tx, $res['status'] ?? 0);

        if (! $res['ok']) {
            return ['ok' => false, 'message' => 'No pudimos enviar el código. Intenta reenviarlo.'];
        }

        $access = data_get($res, 'data.authorization.access_token');
        $patch = ['expires_at' => now()->addMinutes($this->ttlMinutes())->timestamp];
        if (is_string($access) && $access !== '') {
            $patch['access_token'] = $access; // reemplaza/invalida el anterior
        }
        $this->saveOtpMeta($tx, $patch);

        return [
            'ok'      => true,
            'message' => $stage === 'resend'
                ? 'Te reenviamos el código a tu DaviPlata.'
                : 'Te enviamos un código a tu DaviPlata.',
        ];
    }

    /**
     * Resuelve y persiste url_services con BACKOFF acotado. Devuelve
     * ['token','send','validate'] o null si tras el límite aún no están.
     */
    private function ensureUrlServices(PaymentTransaction $tx): ?array
    {
        $stored = $this->storedUrls($tx);
        if ($stored) {
            return $stored;
        }
        if (! $tx->wompi_transaction_id) {
            return null;
        }

        $attempts = max(1, (int) ($this->cfg['daviplata']['poll_attempts'] ?? 6));
        $sleepMs = (int) ($this->cfg['daviplata']['poll_sleep_ms'] ?? 900);

        for ($i = 0; $i < $attempts; $i++) {
            $res = $this->client->getTransaction($tx->wompi_transaction_id);
            $httpStatus = (int) ($res['status'] ?? 0);

            if ($res['ok'] && ! empty($res['data']['id'])) {
                // Aplica el estado (mantiene la tx en su estado real / pending).
                $this->tx->applyWompiTransaction($tx, $res['data']);

                $svc = data_get($res, 'data.payment_method.extra.url_services');
                $urls = [
                    'token'    => is_array($svc) ? ($svc['token'] ?? null) : null,
                    'send'     => is_array($svc) ? ($svc['code_otp_send'] ?? null) : null,
                    'validate' => is_array($svc) ? ($svc['code_otp_validate'] ?? null) : null,
                ];
                if ($urls['token'] && $urls['send'] && $urls['validate']) {
                    $this->storeUrls($tx, $urls);
                    $this->log('start', $tx, $httpStatus, $urls);
                    return $urls;
                }
            }

            $this->log('start.poll', $tx, $httpStatus);
            if ($i < $attempts - 1 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
        return null;
    }

    // ── Estado OTP en metadata (server-side; jamás a Flutter/logs) ─────────────

    private function initOtpState(PaymentTransaction $tx): void
    {
        $this->saveOtpMeta($tx, [
            'attempts'   => 0,
            'resends'    => 0,
            'expires_at' => now()->addMinutes($this->ttlMinutes())->timestamp,
        ]);
    }

    private function otpMeta(PaymentTransaction $tx): array
    {
        $tx->refresh();
        $meta = is_array($tx->metadata) ? $tx->metadata : [];
        return is_array($meta['otp'] ?? null) ? $meta['otp'] : [];
    }

    private function saveOtpMeta(PaymentTransaction $tx, array $patch): void
    {
        $tx->refresh();
        $meta = is_array($tx->metadata) ? $tx->metadata : [];
        $otp = is_array($meta['otp'] ?? null) ? $meta['otp'] : [];
        $meta['otp'] = array_merge($otp, $patch);
        $tx->forceFill(['metadata' => $meta])->save();
    }

    private function storeUrls(PaymentTransaction $tx, array $urls): void
    {
        $this->saveOtpMeta($tx, [
            'send_url'      => $urls['send'],
            'validate_url'  => $urls['validate'],
            'initial_token' => $urls['token'],
        ]);
    }

    private function storedUrls(PaymentTransaction $tx): ?array
    {
        $meta = $this->otpMeta($tx);
        if (! empty($meta['send_url']) && ! empty($meta['validate_url']) && ! empty($meta['initial_token'])) {
            return [
                'token'    => $meta['initial_token'],
                'send'     => $meta['send_url'],
                'validate' => $meta['validate_url'],
            ];
        }
        return null;
    }

    private function otpExpired(array $meta): bool
    {
        $exp = (int) ($meta['expires_at'] ?? 0);
        return $exp > 0 && now()->timestamp > $exp;
    }

    // ── Anti-duplicados (lock por referencia) ─────────────────────────────────

    private function withLock(PaymentTransaction $tx, callable $fn): array
    {
        $lock = Cache::lock('wompi:daviplata:otp:'.$tx->reference, 10);
        if (! $lock->get()) {
            // Ya hay una operación OTP en curso para esta referencia (doble toque /
            // request concurrente): no se duplica la llamada a Wompi.
            return [
                'ok'      => false,
                'busy'    => true,
                'message' => 'Estamos procesando tu solicitud anterior. Espera un momento.',
            ];
        }
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    // ── Config helpers ────────────────────────────────────────────────────────

    private function ttlMinutes(): int
    {
        return (int) ($this->cfg['daviplata']['otp_ttl_minutes'] ?? 10);
    }

    private function maxAttempts(): int
    {
        return (int) ($this->cfg['daviplata']['max_attempts'] ?? 3);
    }

    private function maxResends(): int
    {
        return (int) ($this->cfg['daviplata']['max_resends'] ?? 2);
    }

    /** Log SEGURO: solo presencia de datos, nunca tokens/OTP/URLs/documento. */
    private function log(string $stage, PaymentTransaction $tx, int $httpStatus, ?array $urls = null): void
    {
        $meta = $this->otpMeta($tx);
        $u = $urls ?? $this->storedUrls($tx);
        Log::info('wompi.daviplata', [
            'reference'         => $tx->reference,
            'transaction_id'    => $tx->wompi_transaction_id,
            'stage'             => $stage,
            'http_status'       => $httpStatus,
            'has_url_services'  => $u !== null,
            'has_send_url'      => (bool) ($u['send'] ?? null),
            'has_validate_url'  => (bool) ($u['validate'] ?? null),
            'has_initial_token' => (bool) ($u['token'] ?? ($meta['initial_token'] ?? null)),
            'has_access_token'  => (bool) ($meta['access_token'] ?? null),
        ]);
    }
}
