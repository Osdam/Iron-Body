<?php

namespace App\Services\Outreach\Run\Classifier;

/**
 * AI9 · EL ENMASCARADO MÍNIMO del texto de una respuesta antes de que salga
 * hacia el modelo (contrato H9 §4, «Encargados: OpenAI, para AI9, con
 * `store: false`, solo el texto de la respuesta y sin otros datos de la
 * persona»). PURA. Lo que la persona puede teclear dentro de su propio texto
 * y no hace falta para clasificar:
 *
 *  - correos → `[correo]`;
 *  - enlaces (`http(s)://…`, `www.…`) → `[enlace]` (pueden llevar
 *    identificadores);
 *  - cualquier tira de 6 o más cifras, con o sin `+`, espacios, puntos,
 *    guiones, barras o paréntesis entre ellas → `[número]`: teléfonos,
 *    cédulas, NIT, cuentas y tarjetas. Las cifras cortas («a las 5»,
 *    «50.000», «15/11») se quedan: dicen cuándo o cuánto, no quién.
 *
 * También quita los caracteres de control e invisibles, junta los espacios y
 * corta a {@see MAX_CHARS} caracteres. Un texto que no es UTF-8 válido o que
 * PCRE no puede leer devuelve '' (no se clasifica: falla cerrado). NO
 * reconoce nombres propios ni cifras escritas con letras: lo que la persona
 * escriba así sale tal cual dentro de su respuesta (limitación conocida).
 */
final class ReplyTextMask
{
    public const MAX_CHARS = 500;

    public const EMAIL = '[correo]';

    public const LINK = '[enlace]';

    public const NUMBER = '[número]';

    /** En este orden: el correo y el enlace antes que las cifras (los dos pueden llevarlas). */
    private const PATTERNS = [
        '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)+/u' => self::EMAIL,
        '/\b(?:https?:\/\/|www\.)\S+/iu' => self::LINK,
        '/\+?\p{Nd}(?:[\s.\-\/()]*\p{Nd}){5,}/u' => self::NUMBER,
    ];

    public static function mask(?string $text): string
    {
        if ($text === null || $text === '' || ! mb_check_encoding($text, 'UTF-8')) {
            return '';
        }
        // Control (salvo el salto de línea y el tabulador, que pasan a espacio) e invisibles de formato.
        $t = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', ' ', $text);
        if (! is_string($t)) {
            return '';
        }
        foreach (self::PATTERNS as $patron => $marca) {
            $t = preg_replace($patron, $marca, $t);
            if (! is_string($t)) {
                return '';
            }
        }
        $t = preg_replace('/\s+/u', ' ', $t);
        if (! is_string($t)) {
            return '';
        }

        return mb_substr(trim($t), 0, self::MAX_CHARS, 'UTF-8');
    }
}
