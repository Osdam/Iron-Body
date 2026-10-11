<?php

namespace App\Support\Workforce;

/**
 * La firma de un dispositivo autorizado: qué se firma y cómo se comprueba.
 *
 * Cadena canónica (contrato §1):
 *
 *   IB-DEVICE-SIG-V1\n<MÉTODO>\n<ruta sin api/>\n<device id>\n<nonce>\n<sha256 hex del cuerpo>
 *
 * Firmar el MÉTODO, la RUTA y el HASH DEL CUERPO ata el nonce a una petición
 * concreta: quien intercepte una firma no puede usarla para otra operación, y el
 * nonce de un solo uso impide repetirla. ECDSA P-256 / SHA-256, firma en DER.
 */
final class DeviceSignature
{
    public const PREFIX = 'IB-DEVICE-SIG-V1';

    public const REGISTER_PREFIX = 'IB-DEVICE-REGISTER-V1';

    public static function canonical(string $method, string $path, string $deviceId, string $nonce, string $body): string
    {
        return implode("\n", [
            self::PREFIX,
            strtoupper($method),
            self::normalizePath($path),
            $deviceId,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    /** `/api/kiosk/x` y `api/kiosk/x` → `kiosk/x`. */
    public static function normalizePath(string $path): string
    {
        $path = ltrim($path, '/');

        return str_starts_with($path, 'api/') ? substr($path, 4) : $path;
    }

    /** Lo que firma la app al registrarse: prueba de que tiene la clave privada. */
    public static function registrationProof(string $normalizedCode, string $publicKeyDer): string
    {
        return implode("\n", [self::REGISTER_PREFIX, $normalizedCode, hash('sha256', $publicKeyDer)]);
    }

    /**
     * PEM de una clave pública EC P-256 a partir de su SubjectPublicKeyInfo DER,
     * o null si no lo es.
     */
    public static function publicKeyPem(string $spkiDer): ?string
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spkiDer), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            return null;
        }
        if (($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            return null;
        }

        return $pem;
    }

    public static function verify(string $data, string $signatureDer, string $publicKeyPem): bool
    {
        if ($signatureDer === '') {
            return false;
        }

        return openssl_verify($data, $signatureDer, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }

    /** base64 estricto (estándar o url-safe, con o sin relleno) o null. */
    public static function decodeBase64(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = strtr(trim($value), '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($value, true);

        return $decoded === false ? null : $decoded;
    }
}
