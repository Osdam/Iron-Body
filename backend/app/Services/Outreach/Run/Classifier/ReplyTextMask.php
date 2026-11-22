<?php

namespace App\Services\Outreach\Run\Classifier;

/**
 * AI9 · EL ENMASCARADO MÍNIMO del texto de una respuesta antes de que salga
 * hacia el modelo (contrato H9 §4, «Encargados: OpenAI, para AI9, con
 * `store: false`, solo el texto de la respuesta y sin otros datos de la
 * persona»). PURA. Lo que la persona puede teclear dentro de su propio texto
 * y no hace falta para clasificar:
 *
 *  - correos → `[correo]`, también escritos con espacios o con «arroba» (y
 *    «punto») si acaban en un dominio de primer nivel conocido: «ana arroba
 *    gmail punto com» se oculta; «una arroba de papa» y «hola @ todos. bien»
 *    se quedan (M1 de security);
 *  - enlaces (`http(s)://…`, `www.…`, y un dominio con ruta sin esquema como
 *    `instagram.com/usuario`) y cuentas (`@usuario`) → `[enlace]` (pueden
 *    llevar identificadores; la cuenta usa la misma marca porque las
 *    instrucciones fijas solo nombran tres, y una cuarta cambiaría su huella
 *    y su versión);
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

    /** Los dominios de primer nivel con los que un correo escrito con espacios o con «arroba» se reconoce como correo. */
    private const LOOSE_EMAIL_TLDS = 'com|co|net|org|edu|gov|es|info|io|me|mx|us';

    /**
     * En este orden: los correos (el pegado y después el escrito con espacios
     * o con «arroba»), los enlaces y las cuentas antes que las cifras (todos
     * pueden llevarlas), y la cuenta después de los correos (un correo
     * también lleva `@`).
     */
    private const PATTERNS = [
        '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)+/u' => self::EMAIL,
        '/[\p{L}\p{N}._%+\-]+\s*(?:@|[(\[]\s*arroba\s*[)\]]|\barroba\b)\s*[\p{L}\p{N}\-]+(?:\s*(?:\.|\bpunto\b)\s*[\p{L}\p{N}\-]+)*\s*(?:\.|\bpunto\b)\s*(?:'.self::LOOSE_EMAIL_TLDS.')\b/iu' => self::EMAIL,
        '/\b(?:https?:\/\/|www\.)\S+/iu' => self::LINK,
        // Un dominio con ruta y sin esquema: el primer nivel en minúsculas («Hola.Buenas/tardes» no es un enlace).
        '/\b[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)*\.[a-z]{2,24}\/\S*/u' => self::LINK,
        // Una cuenta: `@` pegado a su nombre y sin nada de un correo delante; el punto final de la frase se queda.
        '/(?<![\p{L}\p{N}._%+\-])@[\p{L}\p{N}_](?:[\p{L}\p{N}_.]*[\p{L}\p{N}_])?/u' => self::LINK,
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
