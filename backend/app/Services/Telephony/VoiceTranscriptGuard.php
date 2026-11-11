<?php

namespace App\Services\Telephony;

use App\Models\MarketingMessage;
use App\Services\Marketing\OutboundContentGuard;
use UnexpectedValueException;

/**
 * La guarda POSTERIOR de la voz (contrato H8, D8-10 capa 4; fixture
 * `elevenlabs-webhook-bodies.json` §guard). El ÚNICO adaptador de la
 * telefonía hacia la guarda del motor: solo `OutboundContentGuard::inspect()`
 * (pura, auditoría SEC8 A-9), con el autor de máquina; nunca `assertSafe`, que
 * registra. No escribe ni registra nada: devuelve CÓDIGOS y números de turno,
 * y quien la llama guarda solo eso.
 *
 * Cada turno del AGENTE (los de la persona no se juzgan), con su índice en la
 * transcripción (DF-E11):
 *  - el primero tiene que llevar el aviso [P-9, E1-3]: que atiende un
 *    asistente virtual, que es inteligencia artificial y cómo pedir no recibir
 *    más llamadas (`voice_notice_missing`);
 *  - P-3: las cifras que devolvió `consultar_planes` EN ESA LLAMADA se tapan
 *    con la expresión monetaria entera ANTES de inspeccionar (tapar después
 *    ensombrecería los motivos de detrás: hallazgo A-9 de SEC8); una cifra
 *    que sigue ahí es `voice_money_not_from_tool`;
 *  - el traspaso a una persona solo se admite en el turno que transfiere;
 *  - cualquier otro código del motor se guarda tal cual;
 *  - cada destino pedido a `transfer_to_number` tiene que ser uno aprobado
 *    (su huella `voice-phone`); sin la lista de aprobados, ninguno lo es:
 *    `voice_transfer_not_approved` (imposible por configuración, se
 *    comprueba igual).
 *
 * EL ESPAÑOL REAL DEL MODELO (SEC8-c M-1, B8-g; {@see VoiceTranscriptLexicon}).
 * La guarda del motor compara precio y descuento sin plegar tildes y no
 * conoce la jerga de Colombia, el inglés ni lo propio de la voz. Por eso, sin
 * tocar el motor, cada turno se inspecciona:
 *  1. con los modismos neutralizados y los precios en palabras pasados a cifras
 *     («ochenta y nueve mil» → «89.000»), antes de tapar los del catálogo: así
 *     un precio de `consultar_planes` dicho en palabras se tapa, y uno
 *     inventado sigue siendo dinero;
 *  2. si el motor no ve nada, otra vez PLEGADO (minúsculas y sin tildes): el
 *     primer código de los dos;
 *  3. y con el léxico propio de la voz sobre el texto plegado (sus códigos,
 *     cada uno una vez por turno), que completa al motor: un código del léxico
 *     de la misma familia que el que ya dio el motor (descuento o dinero) no
 *     se repite.
 *
 * B8-h (REV8-c):
 *  - M-2 (cerrada con P-M2: la plantilla `/3` fija la frase exacta de cada
 *    negativa): antes de buscar nada, esas frases (una lista cerrada de
 *    cláusulas exactas, {@see VoiceTranscriptAllowlist}, nunca en pregunta:
 *    r-2) salen del turno; el resto se busca igual que antes;
 *  - m-1: el plegado es la forma NORMALIZADA (sin marcas, sin invisibles, sin
 *    ancho completo ni homoglifos) y se busca en todas las formas de
 *    {@see VoiceTranscriptLexicon::variants()} (también con las palabras
 *    partidas unidas): el motor, en orden, hasta el primer código; el léxico,
 *    en todas;
 *  - m-2: si PCRE no puede evaluar algo del turno, el turno NO pasa por limpio:
 *    `voice_guard_unprocessable` (freno, como cualquier violación) y, además, el
 *    motor sobre el texto tal cual, como antes de B8-g.
 */
final class VoiceTranscriptGuard
{
    public const CODE_NOTICE_MISSING = 'voice_notice_missing';

    public const CODE_MONEY_NOT_FROM_TOOL = 'voice_money_not_from_tool';

    public const CODE_TRANSFER_NOT_APPROVED = 'voice_transfer_not_approved';

    /** B8-h (REV8-c m-2): la guarda no pudo evaluar el turno (p. ej., PCRE agotó su pila con un turno enorme). Falla cerrado. */
    public const CODE_UNPROCESSABLE = 'voice_guard_unprocessable';

    public const TRANSFER_TOOL = 'transfer_to_number';

    /** SEC8-c M-1: el código del léxico de la voz que sobra si el motor ya marcó el turno con uno de su familia. */
    private const SAME_FAMILY = [
        VoiceTranscriptLexicon::CODE_DISCOUNT_EXTRA => [OutboundContentGuard::CODE_INVENTED_DISCOUNT],
        VoiceTranscriptLexicon::CODE_MONEY_SLANG => [self::CODE_MONEY_NOT_FROM_TOOL],
    ];

    /** Lo que el aviso tiene que decir (D8-17), sin tildes ni mayúsculas. */
    public const NOTICE_ELEMENTS = [['asistente virtual'], ['inteligencia artificial'], ['no quieres recibir mas llamadas', 'no recibir mas llamadas']];

    /** Una cantidad de dinero hablada: «$ 89.000», «89.000 pesos», «COP 89000»… */
    private const MONEY = '/(?:\$\s?|COP\s?)?\d{1,3}(?:[.,\s]\d{3})+(?:,\d{1,2})?(?:\s?(?:pesos|COP))?|(?:\$\s?|COP\s?)\d+|\d{4,}\s?(?:pesos|COP)/iu';

    private const MASK = '[precio del catálogo]';

    /**
     * @param  list<array{index:int, text:string, tools:list<string>, transfers:list<string>, planResults:list<string>}>  $turns  los del agente
     * @param  list<string>|null  $approvedTransferHashes  null = la lista no está disponible (fallo cerrado)
     * @return list<array{turn:int, code:string}>
     */
    public static function inspect(array $turns, ?array $approvedTransferHashes, bool $pricesFromToolAllowed = true): array
    {
        $guarda = new OutboundContentGuard;
        $violaciones = [];
        $cifras = [];
        $primero = true;

        foreach ($turns as $turno) {
            foreach ($turno['planResults'] as $resultado) {
                foreach (self::moneyDigits($resultado) as $d) {
                    $cifras[$d] = true;
                }
            }
            if ($primero) {
                $primero = false;
                if (! self::hasNotice($turno['text'])) {
                    $violaciones[] = ['turn' => $turno['index'], 'code' => self::CODE_NOTICE_MISSING];
                }
                // Y se inspecciona como los demás: el modelo pudo seguir hablando en el mismo turno.
            }

            $transfiere = in_array(self::TRANSFER_TOOL, $turno['tools'], true);
            try {
                $codigos = self::contentCodes($guarda, $turno['text'], $pricesFromToolAllowed ? $cifras : [], $transfiere);
            } catch (UnexpectedValueException) {
                // REV8-c m-2: PCRE no pudo con el turno (límite de pila o de retroceso, UTF-8 inválido). Antes el turno
                // quedaba vacío y pasaba por limpio; ahora es una violación con su código, más lo que diga el motor sobre
                // el texto tal cual (lo que hacía la guarda antes de B8-g), sin tapar precios: en duda, más estricto.
                $codigos = [self::CODE_UNPROCESSABLE];
                $delMotor = self::engineCode($guarda, $turno['text'], $transfiere);
                if ($delMotor !== null) {
                    $codigos[] = $delMotor;
                }
            }
            foreach ($codigos as $codigo) {
                $violaciones[] = ['turn' => $turno['index'], 'code' => $codigo];
            }

            foreach ($turno['transfers'] as $destino) {
                $huella = VoicePhone::isE164($destino) ? VoicePhone::hash($destino) : null;
                if ($huella === null || $approvedTransferHashes === null || ! in_array($huella, $approvedTransferHashes, true)) {
                    $violaciones[] = ['turn' => $turno['index'], 'code' => self::CODE_TRANSFER_NOT_APPROVED];
                    break;
                }
            }
        }

        return $violaciones;
    }

    /**
     * Los códigos del CONTENIDO de un turno (el motor y el léxico; las
     * transferencias van aparte), en orden: el del motor primero, si lo hay.
     *
     * @param  array<string, true>  $cifras  las del catálogo que se tapan ([] = ninguna, P-3 = no)
     * @return list<string>
     *
     * @throws UnexpectedValueException si PCRE no puede evaluar el turno (REV8-c m-2)
     */
    private static function contentCodes(OutboundContentGuard $guarda, string $texto, array $cifras, bool $transfiere): array
    {
        // REV8-c M-2: las negativas que manda la plantilla salen ANTES de buscar (solo cláusulas exactas de la lista).
        $base = VoiceTranscriptAllowlist::strip($texto);
        // SEC8-c M-1: los precios en palabras pasan a cifras ANTES de tapar los del catálogo. REV8-c m-1: en el texto tal
        // cual y en cada forma normalizada.
        $cifrar = static fn (string $t): string => VoiceTranscriptLexicon::numbersToDigits(VoiceTranscriptLexicon::neutralizeIdioms($t));
        $normalizadas = array_map($cifrar, VoiceTranscriptLexicon::variants($base));
        $formas = array_values(array_unique([$cifrar($base), ...$normalizadas]));

        // El motor, forma a forma, hasta el primer código (las de antes primero: su código no cambia).
        $delMotor = null;
        foreach ($formas as $forma) {
            $delMotor = self::engineCode($guarda, self::maskApproved($forma, $cifras), $transfiere);
            if ($delMotor !== null) {
                break;
            }
        }

        // El léxico de la voz, en todas las formas normalizadas, completa al motor: no repite la familia que el motor ya
        // marcó en el turno. Cada código una vez, en el orden del conjunto cerrado.
        $delLexico = [];
        foreach ($normalizadas as $forma) {
            $delLexico = [...$delLexico, ...VoiceTranscriptLexicon::codes(self::maskApproved($forma, $cifras))];
        }
        $codigos = $delMotor === null ? [] : [$delMotor];
        foreach (VoiceTranscriptLexicon::CODES as $codigo) {
            if (in_array($codigo, $delLexico, true) && ($delMotor === null || ! in_array($delMotor, self::SAME_FAMILY[$codigo] ?? [], true))) {
                $codigos[] = $codigo;
            }
        }

        return $codigos;
    }

    /** El código del motor para un texto (el precio inventado, con el nombre de la voz), o null si está limpio. */
    private static function engineCode(OutboundContentGuard $guarda, string $texto, bool $transfiere): ?string
    {
        $veredicto = $guarda->inspect($texto, MarketingMessage::SENDER_AI, $transfiere);
        if (($veredicto['safe'] ?? true) === true || ! is_string($veredicto['code'] ?? null)) {
            return null;
        }

        return $veredicto['code'] === OutboundContentGuard::CODE_INVENTED_PRICE ? self::CODE_MONEY_NOT_FROM_TOOL : $veredicto['code'];
    }

    /** ¿Lleva el aviso? Un texto que no se puede normalizar (REV8-c m-2) no lo lleva: falla cerrado. */
    public static function hasNotice(string $text): bool
    {
        try {
            $t = VoiceTranscriptLexicon::fold($text);
        } catch (UnexpectedValueException) {
            return false;
        }
        foreach (self::NOTICE_ELEMENTS as $alternativas) {
            $esta = false;
            foreach ($alternativas as $frase) {
                $esta = $esta || str_contains($t, $frase);
            }
            if (! $esta) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> las cifras de dinero de un texto, solo sus dígitos (sin decimales de 2) */
    public static function moneyDigits(string $text): array
    {
        if (preg_match_all(self::MONEY, $text, $m) === false) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(self::digitsOf(...), $m[0]), static fn (string $d): bool => $d !== '')));
    }

    /**
     * @param  array<string, true>  $cifras
     *
     * @throws UnexpectedValueException si PCRE falla (REV8-c m-2: antes devolvía '' y el motor veía un turno vacío)
     */
    private static function maskApproved(string $text, array $cifras): string
    {
        if ($cifras === []) {
            return $text;
        }
        $tapado = preg_replace_callback(self::MONEY, static fn (array $m): string => isset($cifras[self::digitsOf($m[0])]) ? self::MASK : $m[0], $text);
        if ($tapado === null) {
            throw new UnexpectedValueException('La guarda no pudo tapar los precios del turno (PCRE: '.preg_last_error_msg().').');
        }

        return $tapado;
    }

    private static function digitsOf(string $expresion): string
    {
        $sinCentavos = (string) preg_replace('/,\d{1,2}(?=\D*$)/u', '', $expresion);

        return ltrim((string) preg_replace('/\D+/', '', $sinCentavos), '0');
    }
}
