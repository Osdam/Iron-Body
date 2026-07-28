<?php

namespace App\Support\Moderation;

use Illuminate\Http\Request;

/**
 * Saneador de payloads antes de escribirlos en `moderation_audit_logs`.
 *
 * Regla: la bitácora existe para reconstruir QUÉ pasó, no para archivar datos
 * personales ni secretos. Todo lo que entra pasa por aquí.
 *
 * Se eliminan (por nombre de clave, en cualquier profundidad):
 *  - tokens, contraseñas, headers Authorization, cookies
 *  - credenciales/secretos de Firebase y URLs firmadas completas
 *  - documento, teléfono, email y otros identificadores directos
 *
 * Se recortan los textos largos: la bitácora guarda un extracto, no el
 * contenido íntegro del reporte (que ya vive en `content_reports`).
 */
final class AuditSanitizer
{
    /** Claves que nunca se persisten, se llamen como se llamen sus padres. */
    private const FORBIDDEN_KEYS = [
        'password', 'password_confirmation', 'token', 'access_token', 'id_token',
        'session_token', 'access_hash', 'bearer', 'authorization', 'cookie',
        'secret', 'api_key', 'apikey', 'private_key', 'credentials',
        'service_account', 'firebase_credentials', 'signature', 'signed_url',
        'download_url', 'otp', 'code', 'pin', 'document', 'document_number',
        'phone', 'email', 'ip', 'ip_address', 'user_agent',
    ];

    /** Longitud máxima de cualquier string persistido. */
    private const MAX_STRING = 300;

    /** Profundidad máxima — evita payloads gigantes o recursivos. */
    private const MAX_DEPTH = 4;

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    public static function clean(?array $data, int $depth = 0): ?array
    {
        if ($data === null) {
            return null;
        }
        if ($depth >= self::MAX_DEPTH) {
            return ['_truncated' => true];
        }

        $out = [];
        foreach ($data as $key => $value) {
            $normalized = strtolower((string) $key);

            if (self::isForbidden($normalized)) {
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::clean($value, $depth + 1);

                continue;
            }

            if (is_string($value)) {
                $out[$key] = self::truncate($value);

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;

                continue;
            }

            if ($value instanceof \DateTimeInterface) {
                $out[$key] = $value->format(DATE_ATOM);

                continue;
            }

            // Objetos no escalares: se descartan para no serializar modelos
            // completos (arrastran PII y relaciones).
            $out[$key] = '[object]';
        }

        return $out;
    }

    private static function isForbidden(string $key): bool
    {
        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            if ($key === $forbidden || str_contains($key, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    private static function truncate(string $value): string
    {
        // Neutraliza HTML/JS antes de guardar: la bitácora se renderiza en el
        // CRM y no debe poder inyectar nada.
        $clean = strip_tags($value);

        return mb_strlen($clean) > self::MAX_STRING
            ? mb_substr($clean, 0, self::MAX_STRING).'…'
            : $clean;
    }

    /**
     * HMAC de la IP con la APP_KEY. Permite correlacionar campañas coordinadas
     * (misma IP = mismo hash) sin almacenar la dirección.
     */
    public static function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /**
     * User-agent RESUMIDO: plataforma y familia, no la cadena completa (que es
     * un identificador de fingerprint).
     */
    public static function summarizeUserAgent(?string $ua): ?string
    {
        if ($ua === null || trim($ua) === '') {
            return null;
        }

        $ua = strtolower($ua);
        $platform = match (true) {
            str_contains($ua, 'android') => 'android',
            str_contains($ua, 'iphone'), str_contains($ua, 'ipad'), str_contains($ua, 'ios') => 'ios',
            str_contains($ua, 'windows') => 'windows',
            str_contains($ua, 'mac os'), str_contains($ua, 'macintosh') => 'macos',
            str_contains($ua, 'linux') => 'linux',
            default => 'unknown',
        };

        $client = match (true) {
            str_contains($ua, 'dart'), str_contains($ua, 'flutter') => 'app',
            str_contains($ua, 'chrome') => 'chrome',
            str_contains($ua, 'firefox') => 'firefox',
            str_contains($ua, 'safari') => 'safari',
            default => 'other',
        };

        return "{$platform}/{$client}";
    }

    /** Identificador de correlación de la petición (no es un secreto). */
    public static function requestId(?Request $request): ?string
    {
        if (! $request instanceof Request) {
            return null;
        }

        $header = $request->headers->get('X-Request-Id');

        return is_string($header) && $header !== ''
            ? mb_substr(preg_replace('/[^A-Za-z0-9\-]/', '', $header) ?? '', 0, 64)
            : null;
    }
}
