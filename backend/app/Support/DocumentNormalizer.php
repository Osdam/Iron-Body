<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalización determinista de documentos de identidad. La identidad de una
 * persona se ancla a su documento normalizado: misma persona ⇒ misma cadena.
 *
 * Reglas: se eliminan separadores comunes (espacios, puntos, guiones), se pasa
 * a mayúsculas y se conserva solo alfanumérico (admite documentos de extranjero
 * con letras). Un documento vacío/sin dígitos devuelve null: NO se enlaza por
 * ausencia de documento (evita fusionar personas distintas sin documento).
 */
final class DocumentNormalizer
{
    public static function normalize(?string $document): ?string
    {
        if ($document === null) {
            return null;
        }

        $clean = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $document) ?? '');

        return $clean === '' ? null : $clean;
    }

    /**
     * Normaliza un teléfono a solo dígitos (con prefijo + opcional). Se usa para
     * comparar el destino del OTP, nunca como credencial. Devuelve null si no
     * queda ningún dígito.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        return $digits === '' ? null : $digits;
    }
}
