<?php

namespace App\Services\Billing;

/**
 * Saneo defensivo de payloads antes de persistirlos o loguearlos. Elimina
 * recursivamente cualquier clave cuyo nombre contenga (substring,
 * case-insensitive) una de las prohibidas en config('billing.forbidden_log_keys')
 * — password, client_secret, access_token, refresh_token, authorization, etc.
 *
 * Mismo enfoque que App\Services\AutomationEventService::sanitizePayload:
 * aunque un caller pase datos sensibles, NUNCA salen a la BD ni a los logs.
 */
class FactusPayloadSanitizer
{
    /** @var string[] */
    private array $forbidden;

    public function __construct(?array $forbidden = null)
    {
        $this->forbidden = array_map(
            'strtolower',
            $forbidden ?? (array) config('billing.forbidden_log_keys', [])
        );
    }

    /** Devuelve una copia del payload sin claves sensibles (a cualquier nivel). */
    public function sanitize(array $payload): array
    {
        return $this->clean($payload);
    }

    /**
     * Recorta el payload para logs: lo sanea y trunca strings largos para no
     * inflar la traza con XML/base64 completos.
     */
    public function excerpt(array $payload, int $maxString = 500): array
    {
        return $this->clean($payload, $maxString);
    }

    private function clean(array $data, ?int $maxString = null): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isForbidden(strtolower($key))) {
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->clean($value, $maxString);
            } elseif ($maxString !== null && is_string($value) && mb_strlen($value) > $maxString) {
                $out[$key] = mb_substr($value, 0, $maxString) . '…[truncated]';
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function isForbidden(string $lowerKey): bool
    {
        foreach ($this->forbidden as $bad) {
            if ($bad !== '' && str_contains($lowerKey, $bad)) {
                return true;
            }
        }

        return false;
    }
}
