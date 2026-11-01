<?php

namespace App\Services\UltronStudio;

use App\Support\Privacy\PhoneFingerprint;
use Closure;
use InvalidArgumentException;

/**
 * El teléfono y el alias de un NÚMERO CANARIO (contrato H6 §I4.3): núcleo
 * puro, sin base de datos ni configuración. Lo usan la validación del registro
 * (ruta 49) y la paridad con el fixture del tester.
 *
 * ESTRICTO A PROPÓSITO (hallazgo T-8: `TwilioVerifyService::toE164()` es
 * permisivo). Solo se quitan cinco separadores (U+0020, `-`, `.`, `(` y `)`);
 * cualquier otro carácter —tabulador, espacio duro, raya, dígitos de ancho
 * completo o arábigo-índicos, letras, un `+` en medio— es `invalid_format`.
 * Por eso los patrones van con `[0-9]` y sin `u` (en PHP, `\d` con `u` acepta
 * dígitos de otros alfabetos) y con `D` (sin él, `$` deja pasar un salto de
 * línea final).
 *
 * El número completo solo existe aquí, en memoria, mientras dura la petición:
 * de él salen la huella ({@see PhoneFingerprint}), los 4
 * últimos dígitos y, solo en voz, el cifrado. Nunca se devuelve.
 *
 * Aquí vive también la regla de AS-1 (Enmienda 3): los textos libres del hito
 * que el libro guarda para siempre (motivos de 48, 52 y 53, nota de 55 y el
 * alias) no admiten un teléfono ni un correo ({@see contactDataError()}).
 */
final class StudioCanaryPhone
{
    public const CHANNELS = ['whatsapp', 'voice'];

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_VOICE = 'voice';

    public const INVALID_FORMAT = 'invalid_format';

    public const COUNTRY_NOT_ALLOWED = 'country_not_allowed';

    public const NOT_ALLOWED_FOR_CHANNEL = 'not_allowed_for_channel';

    public const INVALID_ALIAS = 'invalid_alias';

    /** Los textos fijos de los 422 del teléfono y del alias (§I4.9): no repiten lo que se escribió. */
    public const MESSAGES = [
        self::INVALID_FORMAT => 'El teléfono no tiene formato internacional (+57…).',
        self::COUNTRY_NOT_ALLOWED => 'Ese país no está permitido para números canarios.',
        self::NOT_ALLOWED_FOR_CHANNEL => 'Ese número no sirve para este canal.',
    ];

    /**
     * `not_allowed_for_channel` con la pista de WhatsApp (O-2 de la Enmienda 2):
     * solo si el canal es WhatsApp y el número colombiano no es un móvil. Con
     * un móvil de longitud equivocada, o en voz, la pista confundiría.
     */
    public const NOT_MOBILE_MESSAGE = 'Ese número no sirve para este canal (WhatsApp exige un móvil).';

    public const ALIAS_MESSAGE = 'Usa una función de 3 a 40 caracteres, sin teléfonos ni correos.';

    /** Tope del teléfono tal como llega, antes de normalizar (§I4.9). */
    public const RAW_MAX = 32;

    /** Lo único que se quita antes de validar. */
    public const SEPARATORS = [' ', '-', '.', '(', ')'];

    public const E164_PATTERN = '/^\+[1-9][0-9]{7,14}$/D';

    /** La forma de un prefijo de país admitido (§I12). */
    public const COUNTRY_CODE_PATTERN = '/^[1-9][0-9]{0,2}$/D';

    /** Las reglas de canal, solo con el país 57. */
    public const COLOMBIA = '57';

    public const COLOMBIA_WHATSAPP = '/^\+573[0-9]{9}$/D';

    public const COLOMBIA_VOICE = '/^\+57(?:3[0-9]{9}|60[0-9]{8})$/D';

    public const ALIAS_MIN = 3;

    public const ALIAS_MAX = 40;

    /** Una función, no una persona: letras (con tildes del español), dígitos, espacio y `.-_()`. Sin `\p{…}` (PCRE 10.39 en producción). */
    public const ALIAS_PATTERN = '/^[A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ .\-_()]+$/uD';

    /** Una serie de 4 dígitos o más delata un teléfono o un documento. */
    public const ALIAS_DIGIT_RUN = '/[0-9]{4,}/';

    public const CONTACT_DATA = 'contact_data';

    /** El 422 de AS-1: no repite lo que se escribió. */
    public const CONTACT_DATA_MESSAGE = 'Sin teléfonos ni correos: este texto queda en el libro y no se puede corregir.';

    /**
     * Las tres expresiones de `LeadSourceClassifier` (motor), COPIADAS a
     * propósito para no añadir un lector nuevo del motor (AS-1). Una serie de 8
     * o más dígitos aunque lleve separadores cortos que no son letras
     * («+57 300-123-4567», «300 123 45 67»); una fecha con forma de fecha
     * («2026-11-01») se respeta; y un correo. Llevan `\p{L}` y `u` como el
     * original, que ya corre en producción; `D` donde hay `$` (Enmienda 1).
     */
    public const CONTACT_DIGIT_RUN = '/\+?\d(?:[^\p{L}\d]{0,3}\d){7,}/u';

    public const CONTACT_DATE_SHAPE = '/^(?:\d{4}([-\/._])\d{2}\1\d{2}|\d{2}([-\/._])\d{2}\2\d{4})$/D';

    public const CONTACT_EMAIL = '/[^\s@]+@[^\s@]+\.[^\s@]+/u';

    /** El punto de enmascarar (U+2022). */
    private const BULLETS = '•••';

    /**
     * Quita SOLO espacios (U+0020), '-', '.', '(' y ')'. Cualquier otro carácter → invalid_format.
     * Después: ^\+[1-9]\d{7,14}$ → si no, invalid_format.
     * País: el prefijo más largo de $allowedCountryCodes con el que empieza → si ninguno, country_not_allowed.
     * Canal, con país 57: whatsapp ^\+573\d{9}$; voice ^\+57(?:3\d{9}|60\d{8})$ → si no, not_allowed_for_channel.
     * Con otro país permitido no hay regla de canal: basta E.164.
     *
     * @param  list<string>  $allowedCountryCodes
     * @return array{ok:true, e164:string, countryCode:string, last4:string, masked:string}
     *                                                                                      | array{ok:false, error:'invalid_format'|'country_not_allowed'|'not_allowed_for_channel'}
     *
     * @throws InvalidArgumentException con un canal que no existe (es un error de quien llama)
     */
    public static function parse(#[\SensitiveParameter] string $raw, string $channel, array $allowedCountryCodes): array
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('Canal de número canario desconocido.');
        }

        $e164 = str_replace(self::SEPARATORS, '', $raw);
        if (preg_match(self::E164_PATTERN, $e164) !== 1) {
            return ['ok' => false, 'error' => self::INVALID_FORMAT];
        }

        $pais = null;
        foreach ($allowedCountryCodes as $prefijo) {
            if (! is_string($prefijo) || preg_match(self::COUNTRY_CODE_PATTERN, $prefijo) !== 1) {
                continue;
            }
            if (str_starts_with($e164, '+'.$prefijo) && ($pais === null || strlen($prefijo) > strlen($pais))) {
                $pais = $prefijo;
            }
        }
        if ($pais === null) {
            return ['ok' => false, 'error' => self::COUNTRY_NOT_ALLOWED];
        }

        if ($pais === self::COLOMBIA) {
            $regla = $channel === self::CHANNEL_WHATSAPP ? self::COLOMBIA_WHATSAPP : self::COLOMBIA_VOICE;
            if (preg_match($regla, $e164) !== 1) {
                return ['ok' => false, 'error' => self::NOT_ALLOWED_FOR_CHANNEL];
            }
        }

        $ultimos = substr($e164, -4);

        return ['ok' => true, 'e164' => $e164, 'countryCode' => $pais, 'last4' => $ultimos, 'masked' => self::masked($pais, $ultimos)];
    }

    /**
     * El texto del 422 de un teléfono que `parse()` rechazó con `$error`. Pura;
     * el número no sale del texto.
     */
    public static function message(string $error, #[\SensitiveParameter] string $raw, string $channel): string
    {
        // `not_allowed_for_channel` solo sale con el país 57: no es móvil si no empieza por +573.
        if ($error === self::NOT_ALLOWED_FOR_CHANNEL && $channel === self::CHANNEL_WHATSAPP
            && ! str_starts_with(str_replace(self::SEPARATORS, '', $raw), '+'.self::COLOMBIA.'3')) {
            return self::NOT_MOBILE_MESSAGE;
        }

        return self::MESSAGES[$error];
    }

    /** "+57 ••• ••• 4821" (U+2022). */
    public static function masked(string $countryCode, string $last4): string
    {
        return '+'.$countryCode.' '.self::BULLETS.' '.self::BULLETS.' '.$last4;
    }

    /**
     * null si vale. 'invalid_alias' si no cumple 3–40 caracteres de
     * ^[A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ .\-_()]+$ (flag u), si tiene una serie de 4
     * dígitos o más (\d{4,}) o si tiene '@'. Sin recorte: un espacio delante
     * cuenta. Sin \p{…} (PCRE 10.39 en producción).
     */
    public static function aliasError(string $alias): ?string
    {
        // El patrón va primero: con UTF-8 inválido, preg_match da false y no se cuenta nada más.
        if (preg_match(self::ALIAS_PATTERN, $alias) !== 1) {
            return self::INVALID_ALIAS;
        }
        $largo = mb_strlen($alias, 'UTF-8');
        if ($largo < self::ALIAS_MIN || $largo > self::ALIAS_MAX) {
            return self::INVALID_ALIAS;
        }
        if (preg_match(self::ALIAS_DIGIT_RUN, $alias) === 1 || str_contains($alias, '@')) {
            return self::INVALID_ALIAS;
        }
        // AS-1 (Enmienda 3) cierra O-1: también un teléfono escrito en grupos.
        if (self::contactDataError($alias) !== null) {
            return self::INVALID_ALIAS;
        }

        return null;
    }

    /**
     * `contact_data` si el texto lleva un correo o una serie de 8 o más dígitos
     * que no es una fecha; null si no. Pura. Con UTF-8 inválido falla cerrada.
     */
    public static function contactDataError(string $text): ?string
    {
        $correo = preg_match(self::CONTACT_EMAIL, $text);
        $series = preg_match_all(self::CONTACT_DIGIT_RUN, $text, $encontradas);
        if ($correo === false || $series === false) {
            return self::CONTACT_DATA;
        }
        if ($correo === 1) {
            return self::CONTACT_DATA;
        }
        foreach ($encontradas[0] as $serie) {
            if (preg_match(self::CONTACT_DATE_SHAPE, $serie) !== 1) {
                return self::CONTACT_DATA;
            }
        }

        return null;
    }

    /** La regla de validación de AS-1 para un motivo o una nota (texto fijo). */
    public static function contactDataRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && self::contactDataError($value) !== null) {
                $fail(self::CONTACT_DATA_MESSAGE);
            }
        };
    }
}
