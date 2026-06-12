<?php

namespace App\Services\Wompi;

use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Cobro con DAVIPLATA vía Wompi + ciclo OTP propio (manteniendo la UI Iron Body).
 *
 * IMPORTANTE (pendiente operativo): DaviPlata debe estar HABILITADO COMERCIALMENTE
 * en la cuenta Wompi y el contrato exacto del ciclo OTP (`url_services`) debe
 * VALIDARSE EN SANDBOX antes de producción. Por eso `methods.daviplata` viene en
 * false por defecto. La estructura aquí es defensiva: si Wompi no entrega las
 * URLs de servicio OTP, se devuelve un error CONTROLADO (jamás una aprobación
 * falsa). Nunca se guarda el OTP; solo se controlan intentos/expiración.
 */
class WompiDaviplataPaymentService extends AbstractWompiPaymentService
{
    private const MAX_OTP_ATTEMPTS = 3;
    private const MAX_OTP_RESENDS  = 2;
    private const OTP_TTL_MINUTES   = 10;

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

        return [
            'type'                => 'DAVIPLATA',
            'user_legal_id'       => $legalId,
            'user_legal_id_type'  => $legalIdType,
            'payment_description' => mb_substr($this->description($transaction), 0, 60),
            'sandbox_status'      => $data['sandbox_status'] ?? null, // solo sandbox de pruebas
        ];
    }

    /** Inicia el pago (crea la transacción; Wompi devuelve las URLs de OTP). */
    public function start(array $data, ?string $ip = null, ?string $userAgent = null): PaymentTransaction
    {
        $tx = $this->process($data, $ip, $userAgent);
        // Inicializa control de OTP (sin guardar el OTP).
        $this->initOtpState($tx);
        return $tx->fresh();
    }

    /** Solicita el envío del OTP usando la url_services de la transacción. */
    public function sendOtp(PaymentTransaction $tx): array
    {
        $url = $this->serviceUrl($tx, 'send_otp');
        if (! $url) {
            return $this->otpUnavailable();
        }
        $res = $this->client->postAbsolute($url, [
            'transaction_id' => $tx->wompi_transaction_id,
        ]);
        return $res['ok']
            ? ['ok' => true, 'message' => 'Te enviamos un código a tu DaviPlata.']
            : ['ok' => false, 'message' => 'No pudimos enviar el código. Intenta reenviarlo.'];
    }

    /** Valida el OTP. NO se persiste el OTP en ningún momento. */
    public function validateOtp(PaymentTransaction $tx, string $otp): array
    {
        $meta = (array) ($tx->metadata ?? []);
        if ($this->otpExpired($meta)) {
            return ['ok' => false, 'expired' => true, 'message' => 'El código expiró. Solicita uno nuevo.'];
        }
        $attempts = (int) ($meta['otp_attempts'] ?? 0);
        if ($attempts >= self::MAX_OTP_ATTEMPTS) {
            return ['ok' => false, 'message' => 'Superaste el número de intentos. Solicita un nuevo código.'];
        }

        $url = $this->serviceUrl($tx, 'validate_otp');
        if (! $url) {
            return $this->otpUnavailable();
        }

        $res = $this->client->postAbsolute($url, [
            'transaction_id' => $tx->wompi_transaction_id,
            'otp'            => preg_replace('/\D/', '', $otp), // solo se transmite, no se guarda
        ]);

        // Se incrementan intentos SIEMPRE (no se guarda el OTP).
        $meta['otp_attempts'] = $attempts + 1;
        $tx->forceFill(['metadata' => $meta])->save();

        if (! $res['ok']) {
            $remaining = max(0, self::MAX_OTP_ATTEMPTS - $meta['otp_attempts']);
            return ['ok' => false, 'attempts_left' => $remaining, 'message' => 'Código incorrecto. Intentos restantes: '.$remaining];
        }

        // Tras validar, reconsulta el estado real (nunca se aprueba localmente).
        $this->refreshFromWompi($tx);

        return ['ok' => true, 'message' => 'Código validado. Confirmando tu pago...'];
    }

    /** Reenvía el OTP respetando el límite de reenvíos. */
    public function resendOtp(PaymentTransaction $tx): array
    {
        $meta = (array) ($tx->metadata ?? []);
        $resends = (int) ($meta['otp_resends'] ?? 0);
        if ($resends >= self::MAX_OTP_RESENDS) {
            return ['ok' => false, 'message' => 'Alcanzaste el máximo de reenvíos. Inicia el pago de nuevo.'];
        }
        $meta['otp_resends'] = $resends + 1;
        $meta['otp_expires_at'] = now()->addMinutes(self::OTP_TTL_MINUTES)->timestamp;
        $meta['otp_attempts'] = 0;
        $tx->forceFill(['metadata' => $meta])->save();

        return $this->sendOtp($tx);
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

    // ── OTP state (sin almacenar el OTP) ──────────────────────────────────────

    private function initOtpState(PaymentTransaction $tx): void
    {
        $meta = (array) ($tx->metadata ?? []);
        $meta['otp_attempts'] = 0;
        $meta['otp_resends'] = 0;
        $meta['otp_expires_at'] = now()->addMinutes(self::OTP_TTL_MINUTES)->timestamp;
        $tx->forceFill(['metadata' => $meta])->save();
    }

    private function otpExpired(array $meta): bool
    {
        $exp = (int) ($meta['otp_expires_at'] ?? 0);
        return $exp > 0 && now()->timestamp > $exp;
    }

    /** Lee una URL de servicio OTP de la respuesta cruda de Wompi (si existe). */
    private function serviceUrl(PaymentTransaction $tx, string $key): ?string
    {
        $raw = is_array($tx->raw_response) ? $tx->raw_response : [];
        $services = data_get($raw, 'payment_method.extra.url_services')
            ?? data_get($raw, 'payment_method.extra.async_payment_url')
            ?? null;
        if (is_array($services)) {
            return $services[$key] ?? null;
        }
        // Algunas cuentas exponen una sola URL para todo el ciclo.
        return is_string($services) ? $services : null;
    }

    private function otpUnavailable(): array
    {
        Log::warning('wompi.daviplata.otp_unavailable');
        return [
            'ok'      => false,
            'message' => 'DaviPlata no está disponible por el momento. Usa otro método de pago.',
        ];
    }
}
