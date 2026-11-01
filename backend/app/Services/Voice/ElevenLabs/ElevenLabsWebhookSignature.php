<?php

namespace App\Services\Voice\ElevenLabs;

use InvalidArgumentException;

/**
 * La firma del webhook post-llamada de ElevenLabs (contrato H6 §I6.4): una
 * función PURA, sin configuración, reloj ni red. NO HAY RUTA PÚBLICA que la
 * use en este hito: la monta el hito de voz.
 *
 * Cabecera `ElevenLabs-Signature: t=<segundos>,v0=<hex>`; la firma es
 * HMAC-SHA256 de `"{t}.{cuerpo crudo}"` con el secreto del webhook, y caduca
 * a los 30 minutos (documentación oficial consultada al congelar).
 *
 * Algoritmo de §I6.4, en este orden:
 *  1. Cabecera null o vacía tras `trim` → `missing_signature`.
 *  2. Se parte por `,`, se recortan las partes y se ignoran las vacías. Cada
 *     parte se parte por el PRIMER `=`; una parte sin `=` → `malformed_signature`.
 *  3. Exactamente un `t` y casa con `^\d{1,12}$`; uno o más `v0` y todos
 *     casan con `^[0-9a-fA-F]{64}$` (se comparan en minúsculas). Si no,
 *     `malformed_signature`. Las claves desconocidas se ignoran; las claves
 *     distinguen mayúsculas (`T` es una clave desconocida).
 *  4. `t < now − 1800` → `expired`; `t > now + 300` → `from_future` (decisión
 *     defensiva: el ejemplo oficial solo mira el pasado). Los bordes valen.
 *  5. Esperado = `hash_hmac('sha256', t . '.' . $rawBody, $secret)` con `t`
 *     tal como viene en la cabecera. `ok` si ALGÚN `v0` coincide con
 *     `hash_equals` (tiempo constante); si no, `mismatch`.
 *  6. Siempre sobre el cuerpo crudo, nunca sobre JSON vuelto a serializar.
 */
final class ElevenLabsWebhookSignature
{
    public const TOLERANCE_SECONDS = 1800;

    public const FUTURE_SKEW_SECONDS = 300;

    public const OK = 'ok';

    public const MISSING_SIGNATURE = 'missing_signature';

    public const MALFORMED_SIGNATURE = 'malformed_signature';

    public const EXPIRED = 'expired';

    public const FROM_FUTURE = 'from_future';

    public const MISMATCH = 'mismatch';

    /** Conjunto cerrado de §I2, en su orden. */
    public const RESULTS = [self::OK, self::MISSING_SIGNATURE, self::MALFORMED_SIGNATURE, self::EXPIRED, self::FROM_FUTURE, self::MISMATCH];

    /**
     * @return 'ok'|'missing_signature'|'malformed_signature'|'expired'|'from_future'|'mismatch'
     *
     * @throws InvalidArgumentException si $secret === ''  (un secreto vacío es un fallo de configuración, no una firma mala)
     */
    public static function verify(string $rawBody, ?string $header, string $secret, int $now): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('El secreto del webhook de voz no puede estar vacío.');
        }

        if ($header === null || trim($header) === '') {
            return self::MISSING_SIGNATURE;
        }

        $tiempos = [];
        $firmas = [];
        foreach (explode(',', $header) as $parte) {
            $parte = trim($parte);
            if ($parte === '') {
                continue;
            }
            $igual = strpos($parte, '=');
            if ($igual === false) {
                return self::MALFORMED_SIGNATURE;
            }
            $clave = substr($parte, 0, $igual);
            $valor = substr($parte, $igual + 1);
            if ($clave === 't') {
                $tiempos[] = $valor;
            } elseif ($clave === 'v0') {
                $firmas[] = $valor;
            }
        }

        if (count($tiempos) !== 1 || preg_match('/^\d{1,12}$/D', $tiempos[0]) !== 1 || $firmas === []) {
            return self::MALFORMED_SIGNATURE;
        }
        foreach ($firmas as $firma) {
            if (preg_match('/^[0-9a-fA-F]{64}$/D', $firma) !== 1) {
                return self::MALFORMED_SIGNATURE;
            }
        }

        $t = $tiempos[0];
        if ((int) $t < $now - self::TOLERANCE_SECONDS) {
            return self::EXPIRED;
        }
        if ((int) $t > $now + self::FUTURE_SKEW_SECONDS) {
            return self::FROM_FUTURE;
        }

        $esperado = hash_hmac('sha256', $t.'.'.$rawBody, $secret);
        $coincide = false;
        foreach ($firmas as $firma) {
            // Sin cortocircuito: se comparan todas, cada una en tiempo constante.
            $coincide = hash_equals($esperado, strtolower($firma)) || $coincide;
        }

        return $coincide ? self::OK : self::MISMATCH;
    }
}
