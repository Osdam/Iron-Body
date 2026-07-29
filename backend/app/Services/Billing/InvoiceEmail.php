<?php

namespace App\Services\Billing;

/**
 * Qué correo sirve para ENTREGAR un comprobante fiscal.
 *
 * No es la misma pregunta que «¿es un correo bien formado?». El sistema genera
 * direcciones sintéticas para socios sin correo real —`socio-1033751057@
 * ironbody.local`— que pasan cualquier validación de formato y no existen. Si una
 * de esas llega a `customer.email` con `send_email=true`, Factus intenta entregar
 * el PDF/XML a un buzón inexistente: el cliente nunca recibe su factura y no
 * queda constancia del fallo.
 *
 * Los dominios rechazados no son una lista arbitraria: `.local`, `.invalid`,
 * `.test` y `.example` están RESERVADOS por el RFC 2606 y el RFC 6761
 * precisamente para que nunca se resuelvan en Internet.
 */
final class InvoiceEmail
{
    /** TLD reservados que jamás resuelven fuera de una red local. */
    public const TLD_RESERVADOS = ['local', 'invalid', 'test', 'example', 'localhost'];

    /**
     * Dominio completo que este sistema usa para fabricar correos de relleno.
     * Se nombra aparte porque su TLD ya está en la lista, pero conviene que el
     * mensaje de error pueda ser específico.
     */
    public const DOMINIO_SINTETICO = 'ironbody.local';

    /** ¿Es una dirección a la que se puede entregar de verdad? */
    public static function esEntregable(?string $email): bool
    {
        $email = trim((string) $email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return ! self::esSintetico($email);
    }

    /** ¿Es una dirección fabricada o de dominio reservado? */
    public static function esSintetico(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '' || ! str_contains($email, '@')) {
            return false;
        }

        $dominio = substr($email, strrpos($email, '@') + 1);

        if ($dominio === self::DOMINIO_SINTETICO) {
            return true;
        }

        // Se compara el ÚLTIMO segmento y también el dominio completo, para que
        // tanto `algo.local` como `local` queden cubiertos.
        $partes = explode('.', $dominio);
        $tld = (string) end($partes);

        return in_array($tld, self::TLD_RESERVADOS, true);
    }

    /**
     * Devuelve el correo si sirve para entregar; null si no.
     *
     * Deliberadamente NO tiene fallback: sustituir en silencio un correo
     * inservible por otro «parecido» es lo que produce facturas enviadas a
     * direcciones que nadie lee. Quien llame decide qué hacer con el null.
     */
    public static function normalizar(?string $email): ?string
    {
        $email = trim((string) $email);

        return self::esEntregable($email) ? $email : null;
    }

    /** Primer correo entregable de la lista, o null si ninguno lo es. */
    public static function primeroEntregable(?string ...$candidatos): ?string
    {
        foreach ($candidatos as $c) {
            if (($valido = self::normalizar($c)) !== null) {
                return $valido;
            }
        }

        return null;
    }

    /** Mensaje único para el usuario final, en todos los flujos. */
    public static function mensajeDeRechazo(): string
    {
        return 'El correo indicado no puede recibir la factura electrónica: '
            .'es una dirección de un dominio reservado o generada por el sistema. '
            .'Indica un correo real donde el cliente pueda recibir el PDF y el XML.';
    }
}
