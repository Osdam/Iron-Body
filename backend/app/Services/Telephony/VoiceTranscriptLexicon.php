<?php

namespace App\Services\Telephony;

use App\Services\Voice\Dto\VoiceAgentSpec;
use UnexpectedValueException;

/**
 * EL ESPAÑOL REAL DEL MODELO para la guarda posterior de la voz (contrato H8,
 * D8-10 capa 4; SEC8-c M-1). Puro: sin base, configuración, reloj, red ni
 * registro. Lo usa {@see VoiceTranscriptGuard}, que es la única que habla con
 * la guarda del motor; aquí no se nombra el motor (protección 5).
 *
 * La guarda del motor compara precio y descuento SIN plegar tildes y no conoce
 * el español de Colombia ni la voz: «promoción», «descuentos», «gratuita», «no
 * tiene costo», «ochenta lucas», «un palo», «un millón», el inglés, la fuga del
 * prompt, los datos de otros socios o un teléfono dictado en palabras pasaban
 * (las 18 frases de SEC8-c). Aquí:
 *  - {@see fold()}: la forma NORMALIZADA (B8-h, REV8-c m-1): minúsculas, sin
 *    diacríticos (precompuestos o combinados, NFD), sin caracteres de formato
 *    ni de ancho cero, el ancho completo y los alfanuméricos matemáticos como
 *    letras normales (el subconjunto de NFKC del alfabeto latino) y los
 *    homoglifos cirílicos y griegos más comunes como su letra latina;
 *  - {@see variants()}: las formas en que se busca: la normalizada, con los
 *    caracteres de formato como espacio si los había, y con las palabras
 *    partidas unidas («des-cuen-to», «g.r.a.t.i.s», «g r a t i s»). Solo
 *    AÑADEN formas: lo que veía la forma anterior lo sigue viendo una de ellas;
 *  - {@see neutralizeIdioms()}: los modismos con cifras que no son dinero
 *    («un millón de gracias»), antes de convertir números;
 *  - {@see numbersToDigits()}: un precio dicho en palabras (o en cifras con
 *    «mil»/«millones») pasa a cifras con puntos de miles («ochenta y nueve mil»
 *    → «89.000»). Solo cuando lleva «mil» o «millón» detrás de un número,
 *    como hace el motor: «mil gracias» o «más de mil socios» no son precios.
 *    Así la máscara del catálogo (P-3) reconoce un precio de `consultar_planes`
 *    dicho en palabras, y uno inventado sigue siendo dinero;
 *  - {@see codes()}: el léxico propio de la voz, un conjunto CERRADO
 *    ({@see CODES}), sobre el texto plegado.
 *
 * SIN `ext-intl`: producción no la tiene (contrato G; `ReceiptTextLayout`).
 * La normalización son tablas propias y cerradas; una prueba las compara con
 * NFKD de `Normalizer` donde la extensión sí está (las pruebas).
 *
 * FALLA CERRADO (B8-h, REV8-c m-2): si PCRE no puede evaluar una expresión
 * (límite de la pila JIT o del retroceso, UTF-8 inválido), nada devuelve un
 * texto vacío o a medias: se lanza `UnexpectedValueException` (sin el texto)
 * y la guarda marca el turno como `voice_guard_unprocessable`.
 *
 * Es una heurística: el fixture de SEC8-c la fija (18 frases de ataque, 20
 * legítimas) y un falso positivo cuesta un freno que suelta una persona, que
 * es el lado seguro.
 */
final class VoiceTranscriptLexicon
{
    /** Rebajas, regalos y gratuidades del español de Colombia (y los extra de la plantilla de voz). */
    public const CODE_DISCOUNT_EXTRA = 'voice_discount_extra';

    /** Dinero en jerga («lucas», «barras», «palos»), otras monedas y «millón» suelto. */
    public const CODE_MONEY_SLANG = 'voice_money_slang';

    /** El agente habla en otro idioma (la plantilla manda español). */
    public const CODE_LANGUAGE_NOT_SPANISH = 'voice_language_not_spanish';

    /** El agente nombra sus herramientas, sus variables o sus instrucciones. */
    public const CODE_PROMPT_LEAK = 'voice_prompt_leak';

    /** El agente afirma algo de un socio o de un pago (no tiene herramienta que lo sepa). */
    public const CODE_MEMBER_OR_PAYMENT_CLAIM = 'voice_member_or_payment_claim';

    /** Un teléfono dictado en palabras (7 o más números seguidos) o un correo («arroba»). */
    public const CODE_SPOKEN_CONTACT_DATA = 'voice_spoken_contact_data';

    public const CODES = [self::CODE_DISCOUNT_EXTRA, self::CODE_MONEY_SLANG, self::CODE_LANGUAGE_NOT_SPANISH, self::CODE_PROMPT_LEAK,
        self::CODE_MEMBER_OR_PAYMENT_CLAIM, self::CODE_SPOKEN_CONTACT_DATA];

    /**
     * Un número dicho en palabras, ya plegado (para el teléfono dictado por
     * pares o tríos: «tres diez, quinientos cincuenta y cinco…»). Lleva su
     * propio final de palabra: «un» no se queda con el principio de «uno».
     */
    private const NUMBER_WORD = '(?:cero|uno|una|un|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|dieci[a-z]+|veinte|veinti[a-z]+|'
        .'treinta|cuarenta|cincuenta|sesenta|setenta|ochenta|noventa|cien|ciento|doscientos|trescientos|cuatrocientos|quinientos|seiscientos|setecientos|'
        .'ochocientos|novecientos|mil)\b';

    /**
     * Los patrones, sobre el texto PLEGADO. Los extra de la plantilla
     * (`StudioVoiceTemplate::DISCOUNT_EXTRA_PATTERN` y `MONEY_EXTRA_PATTERN`,
     * que la telefonía no puede nombrar) van copiados aquí; una prueba fija que
     * esta copia caza todo lo que caza la de la plantilla. B8-h (REV8-c m-1):
     * «luquitas», «no te cuesta nada», «gratuitamente», «te la regalamos» y
     * «regalado», «por cuenta de la casa», «el cincuenta por ciento menos»,
     * «sin pagar inscripción», «está al día con sus pagos» (no la condicional «si estás
     * al día…», REV8-c segunda mirada), «es socio activo» y
     * el teléfono dictado por pares o tríos.
     */
    private const PATTERNS = [
        self::CODE_DISCOUNT_EXTRA => '/\b(descuentos|descuentic\w*|rebajas|rebajit\w*|promociones|promos?|ofertas?|gratuit(?:[oa]s?|amente)|regalo|te\s+regalo|de\s+regalo|'
            .'regalamos|regalaremos|regalad[oa]s?|cortesia|precio\s+de\s+amigo|te\s+lo\s+(dejo|puedo\s+dejar)\s+(en|a)|'
            .'no\s+(tiene|tendria|te\s+cuesta|te\s+va\s+a\s+costar)\s+(ningun\s+|nada\s+de\s+)?costo|no\s+(te|le|les)\s+(cuesta|costaria|costara|va\s+a\s+costar)\s+nada|'
            .'por\s+cuenta\s+de\s+la\s+casa|por\s+ciento\s+(menos|de\s+descuento|de\s+rebaja)|sin\s+pagar\s+(la\s+|el\s+)?(inscripcion|matricula|afiliacion|mensualidad|primer\s+mes)|'
            .'sin\s+cobro|no\s+te\s+cobr\w+|bonos?|cupon(es)?|codigo\s+promocional|obsequi\w+|bonificaci\w+|dos\s+por\s+uno)\b/u',
        self::CODE_MONEY_SLANG => '/\b(\d{1,4}|un|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|veinte|treinta|cuarenta|cincuenta|sesenta|setenta|ochenta|noventa|cien|ciento)\s+'
            .'(lucas?|luquitas?|barras?(?!\s+(olimpicas?|de\s+(pesas|dominadas|discos)|paralelas)\b))\b|\b(un|medio|dos|tres)\s+palos?\b|\bmillon(es)?\b|\busd\b|\bdolar(es)?\b|\beuros?\b|\x{20AC}/u',
        self::CODE_MEMBER_OR_PAYMENT_CLAIM => '/\b((esta|estas|sigue|sigues)\s+(inscrit[oa]|afiliad[oa])|(su|tu)\s+(membresia|afiliacion|plan)\s+(esta|sigue|vence|vencio|quedo)|'
            .'(registrado|recibido|confirmado|aprobado|quedo)\s+(tu|su|el)\s+pago|pago\s+(recibido|registrado|confirmado|aprobado|quedo)|'
            .'(?<!\bsi\s)(?<!\bcuando\s)(?<!\bmientras\s)(esta|estas|sigue|sigues|va|vas)\s+al\s+dia\s+(con|en)\s+(sus|tus|su|tu|los)\s+pagos?|(es|esta|sigue)\s+(socio|socia|afiliad[oa]|miembro)\s+activ[oa])\b/u',
        self::CODE_SPOKEN_CONTACT_DATA => '/\b(cero|uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve)([\s,.-]+(cero|uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve)){6,}\b|\barroba\b|'
            .'\b'.self::NUMBER_WORD.'(?:[\s,.\-]+(?:y\s+)?'.self::NUMBER_WORD.'){6,}+/u',
    ];

    /** Lo que solo dice quien recita su configuración: las secciones y marcas de la plantilla y del motor de la voz. */
    private const LEAK_MARKERS = ['{{', 'system__', 'datos de esta llamada', 'mis instrucciones', 'mi prompt', 'instrucciones del sistema', 'mis herramientas'];

    /** B8-h (REV8-c m-1): una herramienta dicha sin guion bajo, cuando el agente dice que es una herramienta («la herramienta consultar planes»). */
    private const LEAK_TOOL_PATTERN = '/\bherramientas?\s+(?:(?:de|para|llamada|que\s+se\s+llama)\s+)?(?:consultar\s+planes|consultar\s+horarios|solicitar\s+humano|'
        .'no\s+volver\s+a\s+llamar|end\s+call|transfer\s+to\s+number)\b/u';

    /**
     * Palabras que no son españolas y delatan una respuesta en inglés. Con dos
     * distintas en el mismo turno, no es español (una sola puede ser un
     * préstamo: «free», «plan»). B8-h (REV8-c m-1): también las palabras
     * gramaticales y los saludos («Hello! Welcome to…, how may I help?» no
     * llevaba ninguna); ninguna es una palabra española («has», «me», «no» o
     * «a», que lo son, no están).
     */
    private const ENGLISH_MARKERS = ['the', 'you', 'your', 'we', 'our', 'us', 'is', 'are', 'can', 'will', 'would', 'sure', 'of course', 'discount', 'free', 'price',
        'cost', 'costs', 'dollar', 'dollars', 'bucks', 'thousand', 'hundred', 'month', 'monthly', 'membership', 'deal', 'off', 'charge', 'half price', 'on the house',
        'hello', 'hi', 'welcome', 'how', 'help', 'may', 'i', 'my', 'what', 'thanks', 'thank', 'please', 'yes', 'and', 'for', 'with', 'this', 'that', 'have', 'it',
        'to', 'today', 'here', 'get'];

    /** Los modismos con una cifra que no es dinero. */
    private const IDIOMS = ['/\b(un\s+)?mill[oó]n(es)?\s+de\s+gracias\b/iu' => 'muchas gracias'];

    /** Los números en palabras, plegados → su valor. */
    private const NUMBER_WORDS = [
        'un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9,
        'diez' => 10, 'once' => 11, 'doce' => 12, 'trece' => 13, 'catorce' => 14, 'quince' => 15,
        'dieciseis' => 16, 'diecisiete' => 17, 'dieciocho' => 18, 'diecinueve' => 19,
        'veinte' => 20, 'veintiun' => 21, 'veintiuno' => 21, 'veintiuna' => 21, 'veintidos' => 22, 'veintitres' => 23, 'veinticuatro' => 24,
        'veinticinco' => 25, 'veintiseis' => 26, 'veintisiete' => 27, 'veintiocho' => 28, 'veintinueve' => 29,
        'treinta' => 30, 'cuarenta' => 40, 'cincuenta' => 50, 'sesenta' => 60, 'setenta' => 70, 'ochenta' => 80, 'noventa' => 90,
        'cien' => 100, 'ciento' => 100, 'doscientos' => 200, 'doscientas' => 200, 'trescientos' => 300, 'trescientas' => 300,
        'cuatrocientos' => 400, 'cuatrocientas' => 400, 'quinientos' => 500, 'quinientas' => 500, 'seiscientos' => 600, 'seiscientas' => 600,
        'setecientos' => 700, 'setecientas' => 700, 'ochocientos' => 800, 'ochocientas' => 800, 'novecientos' => 900, 'novecientas' => 900,
    ];

    /**
     * Lo que no se ve ni se oye y parte una palabra: los caracteres de formato
     * (`\p{Cf}`: anchura cero, bidi, guion blando, U+2060–U+2064, BOM, las
     * etiquetas U+E0000–U+E007F…) y los rellenos coreanos, que son letras
     * invisibles. Los separadores de línea y de párrafo no están: ya son
     * espacio para `\s`.
     */
    private const INVISIBLE = '[\p{Cf}\x{115F}\x{1160}\x{3164}\x{FFA0}]';

    /** El guion o el punto que parte una palabra entre dos letras («des-cuen-to», «g.r.a.t.i.s», «pro·mo·ción»). */
    private const SPLIT_MARK = '[\-.\x{00B7}\x{2022}\x{2027}\x{2010}-\x{2015}\x{2212}*_~]';

    /**
     * Ya en minúsculas y sin marcas combinadas: cada letra latina con
     * diacrítico (Latin-1 y Latin Extended-A) pasa a su base, y los homoglifos
     * cirílicos y griegos más comunes, a la letra latina que imitan (la lista
     * del lead, а е о р с у х і, más las mayúsculas que ya bajaron: в к м н т
     * ѕ ј, y las griegas α ε ι κ ν ο ρ τ υ χ…). Un conjunto CERRADO.
     */
    private const FOLD_MAP = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i', 'ĳ' => 'ij', 'ĵ' => 'j', 'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's', 'ſ' => 's', 'ß' => 'ss',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        // Cirílico.
        'а' => 'a', 'в' => 'b', 'е' => 'e', 'ё' => 'e', 'і' => 'i', 'ї' => 'i', 'ј' => 'j', 'к' => 'k', 'м' => 'm', 'н' => 'h', 'о' => 'o', 'р' => 'p',
        'с' => 'c', 'т' => 't', 'у' => 'y', 'х' => 'x', 'ѕ' => 's', 'һ' => 'h', 'ԁ' => 'd', 'ԛ' => 'q', 'ԝ' => 'w', 'ӏ' => 'l',
        // Griego.
        'α' => 'a', 'β' => 'b', 'γ' => 'y', 'ε' => 'e', 'ζ' => 'z', 'η' => 'n', 'ι' => 'i', 'κ' => 'k', 'μ' => 'u', 'ν' => 'v', 'ο' => 'o', 'ρ' => 'p',
        'τ' => 't', 'υ' => 'u', 'χ' => 'x', 'ω' => 'w', 'ϲ' => 'c', 'ϳ' => 'j',
        // Latín: las formas de IPA que imitan una letra.
        'ɑ' => 'a', 'ɡ' => 'g', 'ɩ' => 'i', 'ʏ' => 'y',
    ];

    /** Las ligaduras latinas de compatibilidad (U+FB00–U+FB06), como las deja NFKC. */
    private const LIGATURES = ["\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl', "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl', "\u{FB05}" => 'st', "\u{FB06}" => 'st'];

    /**
     * La forma NORMALIZADA de un texto (B8-h, REV8-c m-1), sin `ext-intl`:
     *  1. el subconjunto de NFKC del alfabeto latino: el ancho completo
     *     (U+FF01–U+FF5E y el espacio U+3000), los alfanuméricos matemáticos
     *     (U+1D400–U+1D6A3 y U+1D7CE–U+1D7FF), las letras en círculo y las
     *     ligaduras;
     *  2. minúsculas;
     *  3. fuera las marcas combinadas (`\p{M}`: lo que deja NFD) y lo invisible
     *     ({@see INVISIBLE});
     *  4. sin diacríticos y sin homoglifos ({@see FOLD_MAP}).
     * Lo de antes («PROMOCIÓN Gratuíta Niño Pingüino» → «promocion gratuita
     * nino pinguino») sale igual.
     *
     * @throws UnexpectedValueException si no es UTF-8 válido o PCRE falla (m-2)
     */
    public static function fold(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new UnexpectedValueException('La guarda no pudo normalizar el turno: no es UTF-8 válido.');
        }
        $t = self::replaceCallback('/[\x{FF01}-\x{FF5E}\x{1D400}-\x{1D6A3}\x{1D7CE}-\x{1D7FF}\x{24B6}-\x{24E9}]/u', static function (array $m): string {
            $c = (int) mb_ord($m[0], 'UTF-8');
            $letra = ($c - 0x1D400) % 52;

            // En orden de punto de código (la expresión solo deja pasar estos cuatro rangos).
            return match (true) {
                $c <= 0x24CF => chr(0x41 + $c - 0x24B6),                          // Ⓐ–Ⓩ
                $c <= 0x24E9 => chr(0x61 + $c - 0x24D0),                          // ⓐ–ⓩ
                $c <= 0xFF5E => (string) mb_chr($c - 0xFEE0, 'UTF-8'),            // ancho completo → ASCII
                $c <= 0x1D6A3 => chr($letra < 26 ? 0x41 + $letra : 0x61 + $letra - 26), // 13 estilos de 52 letras (A–Z, a–z)
                default => chr(0x30 + ($c - 0x1D7CE) % 10),                       // 5 estilos de 10 cifras
            };
        }, strtr($text, ["\u{3000}" => ' '] + self::LIGATURES));
        $t = self::replace('/'.self::INVISIBLE.'|\p{M}/u', '', mb_strtolower($t, 'UTF-8'));

        return strtr($t, self::FOLD_MAP);
    }

    /**
     * Las formas NORMALIZADAS en las que se busca (B8-h, REV8-c m-1), sin
     * repetir y en este orden:
     *  - {@see fold()} (lo invisible, fuera: «des» + U+200B + «cuento» es
     *    «descuento»);
     *  - si había algo invisible, la misma con lo invisible como espacio (si
     *    separaba palabras, quitarlo las pegaría);
     *  - cada una con las palabras partidas unidas: un guion o un punto entre
     *    dos letras desaparece («des-cuen-to», «g.r.a.t.i.s») y tres o más
     *    letras sueltas seguidas se juntan («g r a t i s»).
     * Solo se AÑADEN formas: unir palabras podría pegar otras («clase-gratis»),
     * y la forma sin unir sigue ahí.
     *
     * @return list<string>
     *
     * @throws UnexpectedValueException si no es UTF-8 válido o PCRE falla (m-2)
     */
    public static function variants(string $text): array
    {
        $formas = [self::fold($text)];
        if (self::matches('/'.self::INVISIBLE.'/u', $text)) {
            $formas[] = self::fold(self::replace('/'.self::INVISIBLE.'+/u', ' ', $text));
        }
        $unidas = [];
        foreach ($formas as $forma) {
            $sinMarcas = self::replace('/(?<=\p{L})'.self::SPLIT_MARK.'(?=\p{L})/u', '', $forma);
            $unidas[] = self::replaceCallback('/(?<![\p{L}\p{N}])\p{L}(?![\p{L}\p{N}])(?:\s+\p{L}(?![\p{L}\p{N}])){2,}/u',
                static fn (array $m): string => self::replace('/\s+/u', '', $m[0]), $sinMarcas);
        }

        return array_values(array_unique([...$formas, ...$unidas]));
    }

    /**
     * Los modismos con cifras que no son dinero, por su sentido («un millón de gracias» → «muchas gracias»).
     *
     * @throws UnexpectedValueException si PCRE falla (m-2)
     */
    public static function neutralizeIdioms(string $text): string
    {
        foreach (self::IDIOMS as $patron => $sentido) {
            $text = self::replace($patron, $sentido, $text);
        }

        return $text;
    }

    /**
     * Un número dicho con «mil» o «millón» (en palabras, en cifras o mezclado:
     * «ochenta y nueve mil», «ciento veinte mil», «89 mil», «un millón
     * doscientos mil») pasa a cifras con puntos de miles. Solo si EMPIEZA por
     * un número y lleva un multiplicador: «mil gracias» no cambia. Admite
     * tildes y mayúsculas.
     *
     * @throws UnexpectedValueException si PCRE falla (m-2: con una cadena muy
     *                                  larga de números se agota la pila JIT; antes devolvía '' y el turno
     *                                  pasaba por limpio)
     */
    public static function numbersToDigits(string $text): string
    {
        // Cada vocal admite su tilde («dieciséis», «veintiún», «millón»…): vale para el texto original y para el plegado.
        $tolerante = static fn (string $p): string => strtr($p, ['a' => '[aá]', 'e' => '[eé]', 'i' => '[ií]', 'o' => '[oó]', 'u' => '[uúü]']);
        $palabras = array_keys(self::NUMBER_WORDS);
        usort($palabras, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $numero = '(?:\d{1,3}|'.implode('|', array_map($tolerante, $palabras)).')';
        $multiplicador = '(?:'.$tolerante('mil').'|'.$tolerante('millon').'(?:es)?)';
        $tramo = '/\b'.$numero.'(?:\s+(?:y\s+)?'.$numero.')*\s+'.$multiplicador.'(?:\s+(?:y\s+)?(?:'.$numero.'|'.$multiplicador.'))*\b/iu';

        return self::replaceCallback($tramo, static function (array $m): string {
            $valor = self::valueOf($m[0]);

            return $valor === null ? $m[0] : number_format($valor, 0, ',', '.');
        }, $text);
    }

    /**
     * Los códigos de la voz que dispara un texto YA PLEGADO (y con los números
     * normalizados), en el orden de {@see CODES}, cada uno una vez.
     *
     * @return list<string>
     *
     * @throws UnexpectedValueException si PCRE falla (m-2)
     */
    public static function codes(string $folded): array
    {
        $codigos = [];
        foreach (self::CODES as $codigo) {
            $hay = match ($codigo) {
                self::CODE_PROMPT_LEAK => self::leaks($folded),
                self::CODE_LANGUAGE_NOT_SPANISH => self::notSpanish($folded),
                default => self::matches(self::PATTERNS[$codigo], $folded),
            };
            if ($hay) {
                $codigos[] = $codigo;
            }
        }

        return $codigos;
    }

    /** El valor de un tramo de número con multiplicador, o null si no se puede leer. */
    private static function valueOf(string $tramo): ?int
    {
        $millones = 0;
        $miles = 0;
        $actual = 0;
        $piezas = preg_split('/\s+/u', self::fold($tramo));
        if ($piezas === false) {
            throw new UnexpectedValueException('La guarda no pudo normalizar el turno (PCRE: '.preg_last_error_msg().').');
        }
        foreach ($piezas as $t) {
            if ($t === 'y' || $t === '') {
                continue;
            }
            if (ctype_digit($t)) {
                $actual += (int) $t;
            } elseif (isset(self::NUMBER_WORDS[$t])) {
                $actual += self::NUMBER_WORDS[$t];
            } elseif ($t === 'mil') {
                $miles += max(1, $actual) * 1000;
                $actual = 0;
            } elseif ($t === 'millon' || $t === 'millones') {
                $millones += max(1, $miles + $actual);
                $miles = 0;
                $actual = 0;
            } else {
                return null;
            }
        }
        $valor = $millones * 1000000 + $miles + $actual;

        return $valor > 0 && $valor < 1000000000000 ? $valor : null;
    }

    /** Sus herramientas, sus variables o la estructura de sus instrucciones, dichas en voz alta. */
    private static function leaks(string $folded): bool
    {
        foreach ([...VoiceAgentSpec::TOOLS, ...VoiceAgentSpec::SYSTEM_TOOLS, ...VoiceAgentSpec::VARIABLES, ...self::LEAK_MARKERS] as $marca) {
            if (str_contains($folded, $marca)) {
                return true;
            }
        }

        return self::matches(self::LEAK_TOOL_PATTERN, $folded);
    }

    /** Dos marcas distintas de inglés en el mismo turno. */
    private static function notSpanish(string $folded): bool
    {
        $vistas = 0;
        foreach (self::ENGLISH_MARKERS as $marca) {
            if (self::matches('/(?<![\p{L}\p{N}])'.str_replace(' ', '\s+', preg_quote($marca, '/')).'(?![\p{L}\p{N}])/u', $folded) && ++$vistas >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * m-2: ¿casa? Si PCRE no puede evaluarla, lanza (nunca «no casa»).
     *
     * @throws UnexpectedValueException
     */
    private static function matches(string $pattern, string $subject): bool
    {
        $r = preg_match($pattern, $subject);
        if ($r === false) {
            throw new UnexpectedValueException('La guarda no pudo evaluar el turno (PCRE: '.preg_last_error_msg().').');
        }

        return $r === 1;
    }

    /**
     * m-2: `preg_replace` que nunca devuelve '' por un fallo de PCRE.
     *
     * @throws UnexpectedValueException
     */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        $r = preg_replace($pattern, $replacement, $subject);
        if ($r === null) {
            throw new UnexpectedValueException('La guarda no pudo normalizar el turno (PCRE: '.preg_last_error_msg().').');
        }

        return $r;
    }

    /**
     * m-2: `preg_replace_callback` que nunca devuelve '' por un fallo de PCRE.
     *
     * @param  callable(array<int|string, string>): string  $callback
     *
     * @throws UnexpectedValueException
     */
    private static function replaceCallback(string $pattern, callable $callback, string $subject): string
    {
        $r = preg_replace_callback($pattern, $callback, $subject);
        if ($r === null) {
            throw new UnexpectedValueException('La guarda no pudo normalizar el turno (PCRE: '.preg_last_error_msg().').');
        }

        return $r;
    }
}
