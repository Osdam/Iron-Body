<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envío real vía LabsMobile (proveedor con cobertura en Colombia). API JSON con
 * autenticación básica username + token. Credenciales en config/otp.php.
 */
class LabsMobileSmsSender implements SmsSender
{
    public function send(string $to, string $message): bool
    {
        $username = (string) config('otp.labsmobile.username');
        $token    = (string) config('otp.labsmobile.token');
        $sender   = (string) config('otp.labsmobile.sender');
        $base     = rtrim((string) config('otp.labsmobile.base'), '/');

        if ($username === '' || $token === '') {
            Log::warning('LabsMobileSmsSender: credenciales incompletas, no se envía SMS.');
            return false;
        }

        try {
            $response = Http::withBasicAuth($username, $token)
                ->acceptJson()
                ->timeout(15)
                ->post("{$base}/json/v2/sms", [
                    'message'   => $message,
                    'tpoa'      => $sender,
                    'recipient' => [['msisdn' => preg_replace('/\D+/', '', $to)]],
                ]);

            if ($response->successful() && (int) ($response->json('code') ?? 1) === 0) {
                return true;
            }

            Log::warning('LabsMobileSmsSender: respuesta no exitosa', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::warning('LabsMobileSmsSender: excepción al enviar', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function name(): string
    {
        return 'labsmobile';
    }
}
