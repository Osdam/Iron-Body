<?php

namespace App\Support\Workforce;

/**
 * Atestación de clave de Android (Key Attestation): prueba, firmada por Google,
 * de que la clave del dispositivo se generó DENTRO de hardware seguro (TEE o
 * StrongBox) y no se puede exportar.
 *
 * Qué se comprueba, en este orden, y basta que falle uno para que el resultado
 * sea «no verificada» (el dispositivo puede seguir vinculado: lo autoriza el
 * código del administrador; la atestación es una prueba ADICIONAL que el CRM
 * enseña):
 *
 *   1. Cada certificado de la cadena está firmado por el siguiente.
 *   2. La raíz es una de las de Google (huella SHA-256 de su clave pública,
 *      fijada abajo).
 *   3. La clave del certificado hoja es exactamente la que se registra.
 *   4. El desafío de la atestación es el código de vinculación: la cadena se
 *      generó para ESTA vinculación, no se copió de otra.
 *   5. El nivel de seguridad que declara la extensión KeyDescription.
 *
 * NO se consulta la lista de revocación de Google
 * (https://android.googleapis.com/attestation/status): sería una llamada de
 * red por registro. Queda anotado como riesgo residual en el informe.
 */
final class KeyAttestation
{
    /**
     * Huellas SHA-256 del SubjectPublicKeyInfo de las raíces de Google.
     * Fuente: https://android.googleapis.com/attestation/root y
     * https://developer.android.com/privacy-and-security/security-key-attestation
     * (descargadas y comparadas el 2026-10-10; las dos fuentes coinciden).
     */
    public const GOOGLE_ROOTS = [
        // RSA 4096, serialNumber=f92009e853b6b045 (certificados 2016, 2019, 2021, 2022).
        'feb2ea7551ee316ed4bb443c8293b884dbfdea40b603ee3e4f4a897e4580fbae',
        // ECDSA, CN=Key Attestation CA1 (2025-2035).
        '3ee44512a1af2beb39c889490c60ea3f82e43f5d5a5532f5ab9419f676cd07ec',
    ];

    /** OID 1.3.6.1.4.1.11129.2.1.17 (KeyDescription), codificado en DER. */
    private const KEY_DESCRIPTION_OID = "\x06\x0A\x2B\x06\x01\x04\x01\xD6\x79\x02\x01\x11";

    private const LEVELS = [0 => 'software', 1 => 'tee', 2 => 'strongbox'];

    /**
     * @param  list<string>  $chainDer  certificados DER, hoja primero
     * @param  list<string>|null  $trustedRoots  huellas aceptadas (por defecto, Google)
     * @return array{status:string, security_level:?string, reason:?string}
     */
    public static function verify(array $chainDer, string $expectedChallenge, string $publicKeyDer, ?array $trustedRoots = null): array
    {
        $trustedRoots ??= self::GOOGLE_ROOTS;
        $fail = fn (string $reason, ?string $level = null): array => ['status' => 'unverified', 'security_level' => $level, 'reason' => $reason];

        if (count($chainDer) < 2 || count($chainDer) > 10) {
            return $fail('chain_length');
        }

        $certs = [];
        foreach ($chainDer as $der) {
            $cert = @openssl_x509_read(self::pem($der));
            if ($cert === false) {
                return $fail('chain_unreadable');
            }
            $certs[] = $cert;
        }

        for ($i = 0; $i < count($certs) - 1; $i++) {
            $issuerKey = openssl_pkey_get_public($certs[$i + 1]);
            if ($issuerKey === false || openssl_x509_verify($certs[$i], $issuerKey) !== 1) {
                return $fail('chain_signature');
            }
        }

        $root = end($certs);
        if (! in_array(self::spkiFingerprint($root), $trustedRoots, true)) {
            return $fail('root_not_trusted');
        }

        if (self::spkiFingerprint($certs[0]) !== hash('sha256', $publicKeyDer)) {
            return $fail('leaf_key_mismatch');
        }

        $description = self::keyDescription($chainDer[0]);
        if ($description === null) {
            return $fail('no_key_description');
        }

        $level = self::LEVELS[$description['attestation_security_level']] ?? null;
        if (! hash_equals($expectedChallenge, $description['challenge'])) {
            return $fail('challenge_mismatch', $level);
        }

        return ['status' => 'verified', 'security_level' => $level, 'reason' => null];
    }

    /**
     * Extrae de un certificado hoja la extensión KeyDescription:
     * SEQUENCE { attestationVersion INTEGER, attestationSecurityLevel ENUMERATED,
     * keyMintVersion INTEGER, keyMintSecurityLevel ENUMERATED,
     * attestationChallenge OCTET STRING, … }.
     *
     * Se recorre la ESTRUCTURA del certificado (Certificate → TBSCertificate →
     * [3] extensions → Extension con `extnID` exacto), no se buscan los bytes
     * del OID en cualquier parte: el sujeto u otra extensión podrían esconder
     * un KeyDescription falso (revisión de seguridad 2026-10-11, F7).
     *
     * @return array{attestation_security_level:int, challenge:string}|null
     */
    public static function keyDescription(string $leafDer): ?array
    {
        $cert = self::tlv($leafDer, 0);
        if ($cert === null || $cert['tag'] !== 0x30) {
            return null;
        }
        $tbs = self::children($leafDer, $cert)[0] ?? null;
        if ($tbs === null || $tbs['tag'] !== 0x30) {
            return null;
        }

        $extensions = null;
        foreach (self::children($leafDer, $tbs) as $field) {
            if ($field['tag'] === 0xA3) {
                $extensions = self::children($leafDer, $field)[0] ?? null;
            }
        }
        if ($extensions === null || $extensions['tag'] !== 0x30) {
            return null;
        }

        foreach (self::children($leafDer, $extensions) as $extension) {
            $parts = self::children($leafDer, $extension);
            $id = $parts[0] ?? null;
            if ($id === null || substr($leafDer, $id['header'], $id['end'] - $id['header']) !== self::KEY_DESCRIPTION_OID) {
                continue;
            }
            $value = end($parts);
            if ($value === false || $value['tag'] !== 0x04) {
                return null;
            }
            $seq = self::tlv($leafDer, $value['start']);
            if ($seq === null || $seq['tag'] !== 0x30) {
                return null;
            }
            $fields = array_slice(self::children($leafDer, $seq), 0, 5);
            if (count($fields) < 5 || $fields[1]['tag'] !== 0x0A || $fields[4]['tag'] !== 0x04) {
                return null;
            }

            return [
                'attestation_security_level' => self::integer(substr($leafDer, $fields[1]['start'], $fields[1]['end'] - $fields[1]['start'])),
                'challenge' => substr($leafDer, $fields[4]['start'], $fields[4]['end'] - $fields[4]['start']),
            ];
        }

        return null;
    }

    /**
     * Los elementos DER dentro de un elemento construido.
     *
     * @param  array{tag:int, header:int, start:int, end:int}  $parent
     * @return list<array{tag:int, header:int, start:int, end:int}>
     */
    private static function children(string $der, array $parent): array
    {
        $out = [];
        $cursor = $parent['start'];
        while ($cursor < $parent['end']) {
            $child = self::tlv($der, $cursor);
            if ($child === null || $child['end'] > $parent['end']) {
                return $out;
            }
            $out[] = $child;
            $cursor = $child['end'];
        }

        return $out;
    }

    /**
     * Un elemento DER en `$offset`: etiqueta, inicio y fin del contenido.
     *
     * @return array{tag:int, header:int, start:int, end:int}|null
     */
    private static function tlv(string $der, int $offset): ?array
    {
        $len = strlen($der);
        if ($offset + 2 > $len) {
            return null;
        }
        $tag = ord($der[$offset]);
        $first = ord($der[$offset + 1]);
        $cursor = $offset + 2;
        if ($first < 0x80) {
            $size = $first;
        } else {
            $bytes = $first & 0x7F;
            if ($bytes < 1 || $bytes > 4 || $cursor + $bytes > $len) {
                return null;
            }
            $size = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $size = ($size << 8) | ord($der[$cursor + $i]);
            }
            $cursor += $bytes;
        }
        if ($cursor + $size > $len) {
            return null;
        }

        return ['tag' => $tag, 'header' => $offset, 'start' => $cursor, 'end' => $cursor + $size];
    }

    private static function integer(string $bytes): int
    {
        $value = 0;
        foreach (str_split($bytes) as $b) {
            $value = ($value << 8) | ord($b);
        }

        return $value;
    }

    private static function spkiFingerprint(\OpenSSLCertificate $cert): ?string
    {
        $key = openssl_pkey_get_public($cert);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (! is_array($details) || ! isset($details['key'])) {
            return null;
        }
        $pem = (string) $details['key'];
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);

        return $der === false ? null : hash('sha256', $der);
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }
}
