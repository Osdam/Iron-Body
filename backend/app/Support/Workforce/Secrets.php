<?php

namespace App\Support\Workforce;

use Illuminate\Support\Str;

/**
 * Secretos de corta vida del kiosko: códigos que se dictan de palabra y tokens
 * opacos. En la base solo se guarda su HMAC con la APP_KEY: un volcado de la
 * base no permite probar códigos fuera de línea ni reutilizar tokens.
 */
final class Secrets
{
    public static function hash(string $secret): string
    {
        return hash_hmac('sha256', $secret, self::key());
    }

    /** Código numérico de 8 dígitos (autorizaciones de un administrador). */
    public static function numericCode(): string
    {
        return str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
    }

    /**
     * Código de vinculación de 8 caracteres sin ambigüedades al dictarlo (sin
     * 0/O, 1/I/L), mostrado como XXXX-XXXX.
     */
    public static function pairingCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /** Mayúsculas, sin guiones ni espacios. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $code));
    }

    public static function displayPairingCode(string $normalized): string
    {
        return substr($normalized, 0, 4).'-'.substr($normalized, 4);
    }

    public static function token(string $prefix): string
    {
        return $prefix.'_'.Str::random(48);
    }

    private static function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}
