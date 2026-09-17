<?php

namespace App\Services\Marketing\Ultron;

/**
 * Lo que la memoria puede guardar de un texto, y lo que no.
 *
 * La memoria vive más que la ventana de mensajes recientes y se le enseña al
 * modelo en cada turno. Por eso no guarda prosa cruda: del cliente se quitan
 * teléfonos, documentos y correos; del agente se quitan las cifras de precio,
 * que el modelo no debe ver nunca (el precio es del backend, siempre).
 */
final class MemoryRedactor
{
    public const MAX = 160;

    /** Texto escrito por la persona: sin datos que la identifiquen. */
    public static function lead(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($t === '') {
            return null;
        }
        $t = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', '[correo]', $t) ?? $t;
        // Siete o más dígitos, aunque vengan separados por puntos, espacios o guiones.
        $t = preg_replace('/\b\d(?:[\s.\-]?\d){6,}\b/u', '[numero]', $t) ?? $t;
        $t = preg_replace('/\b\d{1,3}(?:[.,]\d{3}){2,}\b/u', '[numero]', $t) ?? $t;

        return mb_substr($t, 0, self::MAX);
    }

    /** Texto escrito por la máquina: sin cifras de precio. */
    public static function agent(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($t === '') {
            return null;
        }
        $t = preg_replace('/\$\s?\d[\d.,]*(\s?(cop|pesos))?/iu', '[precio]', $t) ?? $t;
        $t = preg_replace('/\b\d{1,3}(?:[.,]\d{3})+\b(\s?(cop|pesos))?/iu', '[precio]', $t) ?? $t;
        $t = preg_replace('/\b\d{4,}\s?(cop|pesos)\b/iu', '[precio]', $t) ?? $t;

        return mb_substr($t, 0, self::MAX);
    }

    /** @return array{source:string, text:string}|null */
    public static function quote(?string $redacted): ?array
    {
        return $redacted === null ? null : ['source' => 'lead_verbatim', 'text' => $redacted];
    }
}
