<?php

namespace App\Services\Marketing\Ultron\Prompts;

/**
 * EL COMPILADOR DE LOS PROMPTS DE ULTRON (Enmienda M-1 del contrato H5; plan
 * de motores §B.3, textos aprobados por el lead tal cual, M1-3).
 *
 * Convierte los valores gobernados de una versión publicada
 * (`persona.tone`, `persona.emojis`, `persona.maxChars`, `strategy.goal` y,
 * desde la versión 2, `rules.forbiddenPhrases`) en los cinco prompts de
 * sistema que usa n8n, partiendo de la base del repositorio
 * ({@see UltronPromptBase}). LA PROPIEDAD CENTRAL: con los valores de la línea
 * base (`cercano`, `true`, `320`, `cita` y ninguna frase, que significan «como
 * hoy», M1-4) el resultado es la base byte a byte y su huella es
 * {@see UltronPromptBase::LEGACY_BUNDLE_HASH}.
 *
 * VERSIÓN 2 (reglas S-NNN en WhatsApp, opción A de la auditoría de cierre
 * §3.2): las frases que prohíben las reglas del Studio entran como UN parche
 * por rol con texto FIJO del compilador alrededor, en anclas ya únicas y
 * vigiladas: Composer y Composer Reintento tras la línea de `FORMA:`; Critic
 * y Critic 2 tras la de `naturalness:`. La lista va ordenada por bytes y sin
 * repetidos, con como mucho {@see self::MAX_PHRASES} frases de 3 a 60
 * caracteres del juego CERRADO {@see self::PHRASE_PATTERN} (letras con sus
 * tildes, ñ y ü, cifras, espacio y `, . ¿ ? ¡ ! -`) y con al menos una letra
 * o una cifra: ni saltos de línea, ni comillas, ni llaves, ni dos puntos, ni
 * frases de dos letras o solo de signos (el Critic las buscaría como subcadena
 * y suspendería casi cualquier borrador). Con la lista vacía no hay ni un parche,
 * así que todo lo que compilaba la versión 1 da los mismos bytes y la misma
 * huella. Una lista que no cumple NO se trunca ni se filtra: lanza
 * (`invalid_phrases`) y quien publica se niega.
 *
 * PARCHES ANCLADOS, NO PLANTILLAS. La base no se edita: cada valor distinto de
 * la línea base aplica parches `{rol, ancla, operación, texto}` donde el ancla
 * es una subcadena que tiene que aparecer EXACTAMENTE UNA VEZ (sensible a
 * mayúsculas) en el texto que se va a parchear; si no, lanza. Dos operaciones:
 *
 *  - `insert_after_line`: inserta una línea nueva (el texto) justo después de
 *    la línea que contiene el ancla: «…línea del ancla» + "\n" + texto +
 *    "\n" + «resto». Si el ancla está en la última línea, el texto queda al
 *    final tras un "\n".
 *  - `replace`: sustituye la subcadena del ancla por el texto.
 *
 * Los parches se aplican uno detrás de otro, en el orden de los parámetros
 * ({@see self::PARAMETERS}: tono, emojis, longitud, objetivo, frases) y,
 * dentro de un parámetro, en el orden de {@see self::patches()}. Cada uno se
 * aplica sobre el resultado del anterior. Consecuencia que hay que saber leer:
 * dos inserciones tras LA MISMA línea quedan en orden inverso al de aplicación
 * (la última queda pegada al ancla). Pasa en los Critic con tono y emojis a la
 * vez («naturalness: …» → «EMOJIS: …» → «REGISTRO PUBLICADO: …») y, como las
 * frases van las últimas, su línea queda siempre pegada a su ancla
 * («FORMA: …» → «FRASES PROHIBIDAS PUBLICADAS: …» → «TOPE DE LONGITUD …»).
 *
 * Ningún texto de parche contiene un ancla (tampoco una frase: se rechaza la
 * que contenga una), así que la unicidad se cumple igual sobre la base que
 * sobre el texto ya parcheado (E2 lo comprueba).
 *
 * LÍMITES: cada texto de parche ≤ 1 200 bytes (el de las frases, que lleva
 * hasta 12 frases de 60 caracteres de 2 bytes, ≤ 2 048) y sin saltos de
 * línea; cada rol compilado ≤ 40 960 bytes; el paquete (suma de los cinco)
 * ≤ 163 840 bytes. Superarlos lanza (`too_large`).
 *
 * VERSIÓN: {@see self::VERSION} va firmada en `/2` (`prompts.compiler`).
 * Cambiar un texto, un ancla, una operación o el orden obliga a subirla: las
 * publicaciones firmadas con otra versión caen a Legacy en `decide` con motivo
 * `compiler_changed` hasta que se vuelve a publicar.
 *
 * PURA Y DETERMINISTA: sin base de datos, sin `config()`, sin reloj y sin log;
 * las mismas entradas dan los mismos bytes, en cualquier orden de claves. Lo
 * importan el motor ({@see UltronPromptOffer}) y el Studio (Enmienda M-1,
 * M1-6). Los textos de los parches son fijos: ningún dato de la conversación
 * entra en un prompt, y lo único escrito por el personal son las frases
 * prohibidas, entre comillas, dentro de un texto fijo y con el juego de
 * caracteres cerrado (la defensa frente a la inyección: sin comillas, sin
 * saltos de línea, sin dos puntos y sin llaves, una frase no puede cerrar su
 * lista, abrir una sección ni formar un marcador `{{…}}`).
 */
final class UltronPromptCompiler
{
    /**
     * La versión del compilador (textos, anclas, operaciones y orden). Firmada
     * en `/2`. La 2 añade `rules.forbiddenPhrases`: una publicación firmada con
     * la 1 cae a Legacy en `decide` (`compiler_changed`) hasta volver a
     * publicarla.
     */
    public const VERSION = 2;

    /** La base sobre la que compila esta versión. */
    public const BASE = UltronPromptBase::ID;

    public const TONE = 'persona.tone';

    public const EMOJIS = 'persona.emojis';

    public const MAX_CHARS = 'persona.maxChars';

    public const GOAL = 'strategy.goal';

    /**
     * Versión 2: las frases que prohíben las reglas S-NNN vivas de la versión
     * cuyo «si» se cumple (las calcula el Studio con la semántica de la voz).
     * No es un parámetro del catálogo: es una lista de textos.
     */
    public const PHRASES = 'rules.forbiddenPhrases';

    /** Los gobernados, en el ORDEN DE APLICACIÓN de sus parches (las frases, las últimas). */
    public const PARAMETERS = [self::TONE, self::EMOJIS, self::MAX_CHARS, self::GOAL, self::PHRASES];

    /**
     * La línea base: «como hoy» (M1-4). Con estos valores no hay ni un parche.
     * Las claves van en el orden del contrato `/2` (alfabético).
     */
    public const BASELINE = [
        self::EMOJIS => true,
        self::MAX_CHARS => 320,
        self::TONE => 'cercano',
        self::PHRASES => [],
        self::GOAL => 'cita',
    ];

    /** Los valores admitidos (los del catálogo del Studio). */
    public const TONES = ['cercano', 'profesional', 'entusiasta'];

    public const GOALS = ['cita', 'venta', 'informar'];

    /** `persona.maxChars`: de 160 a 480 de 20 en 20 (17 valores; 320 es la línea base). */
    public const MAX_CHARS_MIN = 160;

    public const MAX_CHARS_MAX = 480;

    public const MAX_CHARS_STEP = 20;

    /**
     * `rules.forbiddenPhrases`: como mucho 12 frases, de 3 a 60 caracteres cada
     * una y con al menos una letra o una cifra ({@see self::PHRASE_TEXT_PATTERN}).
     * El mínimo era 2 hasta B-3 de la revisión de seguridad (11-11-2026, antes de
     * desplegar el compilador 2): «no», «..» o «¿?» compilaban y el Critic los
     * buscaría como subcadena en cualquier borrador.
     */
    public const MAX_PHRASES = 12;

    public const PHRASE_MIN_CHARS = 3;

    public const PHRASE_MAX_CHARS = 60;

    /**
     * El juego CERRADO de una frase: letras (con sus tildes, ñ y ü, en
     * mayúscula o minúscula), cifras, espacio y `, . ¿ ? ¡ ! -`. Formas
     * compuestas (NFC); una tilde combinada aparte no pasa.
     */
    public const PHRASE_PATTERN = '/\A[A-Za-z0-9áéíóúÁÉÍÓÚñÑüÜ ,.¿?¡!\-]+\z/u';

    /** Una frase tiene que llevar al menos UNA letra o cifra del juego cerrado (si no, `no_text`). */
    public const PHRASE_TEXT_PATTERN = '/[A-Za-z0-9áéíóúÁÉÍÓÚñÑüÜ]/u';

    /** Por qué no vale una frase ({@see self::phraseViolation()}), códigos cerrados. */
    public const PHRASE_LENGTH = 'length';

    public const PHRASE_CHARSET = 'charset';

    public const PHRASE_NO_TEXT = 'no_text';

    public const PHRASE_SPACING = 'spacing';

    public const PHRASE_ANCHOR = 'anchor';

    /** Por qué no vale la lista ({@see self::phrasesViolation()}). */
    public const PHRASES_TOO_MANY = 'too_many';

    public const PHRASES_ORDER = 'order';

    /** Las dos operaciones. */
    public const INSERT_AFTER_LINE = 'insert_after_line';

    public const REPLACE = 'replace';

    /** Topes, en bytes UTF-8. */
    public const MAX_PATCH_BYTES = 1200;

    /** El parche de las frases: 12 frases de 60 caracteres de 2 bytes con sus comillas y el texto fijo caben con holgura. */
    public const MAX_PHRASES_PATCH_BYTES = 2048;

    public const MAX_ROLE_BYTES = 40960;

    public const MAX_BUNDLE_BYTES = 163840;

    // ── Anclas (verificadas sobre la base n8n-29de5119; una vez cada una en su rol) ──

    private const A_COMO_SUENAS = 'COMO SUENAS:';

    private const A_FORMA = 'FORMA:';

    private const A_EMOJI = 'Emoji opcional y raro, nunca en temas sensibles.';

    private const A_NATURALNESS = 'naturalness:';

    private const A_PROGRESSIVE = 'progressive_disclosure:';

    private const A_900 = 'mas de ~900 caracteres';

    private const A_PRINCIPIO = 'PRINCIPIO:';

    // ── Textos aprobados (plan §B.3, M1-3). `{N}` es el valor de persona.maxChars. ──

    private const T_TONE_PRO_COMPOSER = 'REGISTRO PUBLICADO: profesional. Esto manda sobre los ejemplos de arriba: trata a la persona de usted, frases completas y sobrias, sin muletillas coloquiales ("De una", "Listo", "Tranquilo") ni exclamaciones seguidas. Sigue siendo calido; profesional no es frio.';

    private const T_TONE_PRO_CRITIC = 'REGISTRO PUBLICADO: profesional. Tratar de usted NO es defecto de naturalness; tutear o usar muletillas coloquiales SI lo es.';

    private const T_TONE_ENT_COMPOSER = 'REGISTRO PUBLICADO: entusiasta. Mas energia: celebra lo que la persona quiere lograr y transmite ganas de acompanarla. Sin exagerar, sin prometer resultados y sin urgencia ni presion.';

    private const T_TONE_ENT_CRITIC = 'REGISTRO PUBLICADO: entusiasta. La energia NO es defecto de naturalness; la urgencia o la presion siguen siendo PRESSURE.';

    private const T_EMOJIS_OFF_COMPOSER = 'Sin emojis: ninguno, en ningun mensaje.';

    private const T_EMOJIS_OFF_CRITIC = 'EMOJIS: la version publicada no usa emojis; si el borrador lleva alguno, verdict fail con naturalness.';

    private const T_MAX_COMPOSER = 'TOPE DE LONGITUD PUBLICADO: como maximo unos {N} caracteres por mensaje, contando cada marcador como el texto que lo sustituye; tambien la informacion general respeta el tope: si no cabe, da lo esencial y ofrece ampliar.';

    private const T_MAX_CRITIC_900 = 'mas de ~{N} caracteres';

    private const T_MAX_CRITIC = 'TOPE PUBLICADO: un borrador de mas de ~{N} caracteres falla por progressive_disclosure aunque se haya pedido detalle.';

    private const T_GOAL_VENTA = 'OBJETIVO COMERCIAL PUBLICADO: cerrar membresia. Con senales claras de compra (buying_signal strong, hot_lead_fast_path o READY) prioriza recommend y close sobre ofrecer la visita; el dia de cortesia queda para quien desconfia o quiere conocer antes. Nada de esto autoriza presion, descuentos ni salirse de allowed_transitions.';

    private const T_GOAL_INFORMAR = 'OBJETIVO COMERCIAL PUBLICADO: informar y cualificar. Prioriza understand y recommend; no propongas close ni collect_payment salvo que la persona pida comprar o pagar con palabras.';

    // ── Versión 2: el texto FIJO alrededor de las frases. La lista va al final,
    //    cada frase entre comillas dobles y separadas por «, »; después, un punto.

    private const T_PHRASES_COMPOSER = 'FRASES PROHIBIDAS PUBLICADAS: nunca escribas ninguna de estas frases, tampoco con otras mayusculas; si la respuesta parece necesitar una, dilo con otras palabras. Las frases: ';

    private const T_PHRASES_CRITIC = 'FRASES PROHIBIDAS PUBLICADAS: si el borrador contiene alguna de estas frases, aunque cambien las mayusculas, verdict fail con naturalness. Las frases: ';

    private const T_PHRASES_END = '.';

    /** Las anclas, para rechazar una frase que contenga alguna (la unicidad no puede depender de lo que escriba el personal). */
    private const ANCHORS = [self::A_COMO_SUENAS, self::A_FORMA, self::A_EMOJI, self::A_NATURALNESS, self::A_PROGRESSIVE, self::A_900, self::A_PRINCIPIO];

    /**
     * Compila el paquete de los cinco prompts.
     *
     * @param  array<string,mixed>  $governed  exactamente las cinco claves de {@see self::BASELINE}, con sus tipos
     * @return array{contract:string,base:string,compiler:int,governed:array{"persona.emojis":bool,"persona.maxChars":int,"persona.tone":string,"rules.forbiddenPhrases":list<string>,"strategy.goal":string},roles:array<string,string>,bundleHash:string,bytes:int,patches:list<array{parameter:string,value:bool|int|string|list<string>,role:string,operation:string,anchor:string,text:string}>}
     *
     * @throws UltronPromptException valores fuera del contrato, base ilegible o alterada, ancla ausente o repetida, o un tope superado
     */
    public static function compile(array $governed): array
    {
        $valores = self::validate($governed);
        $parches = self::patches($valores);
        $roles = UltronPromptBase::roles();

        foreach ($parches as $parche) {
            $roles[$parche['role']] = self::apply($roles[$parche['role']], $parche);
        }

        $total = 0;
        foreach ($roles as $rol => $texto) {
            if (strlen($texto) > self::MAX_ROLE_BYTES) {
                throw new UltronPromptException(UltronPromptException::TOO_LARGE, "El rol {$rol} compilado supera ".self::MAX_ROLE_BYTES.' bytes.');
            }
            $total += strlen($texto);
        }
        if ($total > self::MAX_BUNDLE_BYTES) {
            throw new UltronPromptException(UltronPromptException::TOO_LARGE, 'El paquete compilado supera '.self::MAX_BUNDLE_BYTES.' bytes.');
        }

        return [
            'contract' => UltronPromptBase::CONTRACT,
            'base' => self::BASE,
            'compiler' => self::VERSION,
            'governed' => $valores,
            'roles' => $roles,
            'bundleHash' => UltronPromptBase::bundleHash($roles),
            'bytes' => $total,
            'patches' => $parches,
        ];
    }

    /**
     * Los parches que aplicaría {@see self::compile()}, en su orden, SIN leer
     * la base: lo que la vista previa del Studio enseña como «cambio exacto».
     * Con la línea base, `[]`.
     *
     * @param  array<string,mixed>  $governed
     * @return list<array{parameter:string,value:bool|int|string|list<string>,role:string,operation:string,anchor:string,text:string}>
     *
     * @throws UltronPromptException valores fuera del contrato o un texto de parche fuera de tope
     */
    public static function patches(array $governed): array
    {
        $valores = self::validate($governed);
        $parches = [];

        foreach (self::PARAMETERS as $parametro) {
            $valor = $valores[$parametro];
            $tope = $parametro === self::PHRASES ? self::MAX_PHRASES_PATCH_BYTES : self::MAX_PATCH_BYTES;
            foreach (self::patchesOf($parametro, $valor) as [$rol, $operacion, $ancla, $texto]) {
                if (strlen($texto) > $tope || str_contains($texto, "\n") || str_contains($texto, "\r")) {
                    throw new UltronPromptException(UltronPromptException::TOO_LARGE, "Un parche de {$parametro} supera {$tope} bytes o tiene saltos de línea.");
                }
                $parches[] = [
                    'parameter' => $parametro,
                    'value' => $valor,
                    'role' => $rol,
                    'operation' => $operacion,
                    'anchor' => $ancla,
                    'text' => $texto,
                ];
            }
        }

        return $parches;
    }

    /**
     * Los gobernados validados y en el orden canónico (el de
     * {@see self::BASELINE}). Estricto: exactamente esas cinco claves; tipos
     * exactos (`persona.emojis` bool, `persona.maxChars` int —ni float ni
     * cadena—, el tono y el objetivo cadenas, las frases una lista de
     * cadenas); valores del catálogo; `maxChars` en [160, 480] y múltiplo de
     * 20 desde 160; las frases, {@see self::phrasesViolation()}.
     *
     * @param  array<string,mixed>  $governed
     * @return array{"persona.emojis":bool,"persona.maxChars":int,"persona.tone":string,"rules.forbiddenPhrases":list<string>,"strategy.goal":string}
     *
     * @throws UltronPromptException `invalid_governed` (claves, tipos o valores) o `invalid_phrases` (el contenido de la lista)
     */
    public static function validate(array $governed): array
    {
        $claves = array_map('strval', array_keys($governed));
        sort($claves);
        $esperadas = array_keys(self::BASELINE);
        sort($esperadas);
        if ($claves !== $esperadas) {
            throw new UltronPromptException(UltronPromptException::INVALID_GOVERNED, 'Los gobernados no traen exactamente las cinco claves del contrato.');
        }

        $emojis = $governed[self::EMOJIS];
        $max = $governed[self::MAX_CHARS];
        $tono = $governed[self::TONE];
        $objetivo = $governed[self::GOAL];
        $frases = $governed[self::PHRASES];

        if (! is_bool($emojis)) {
            throw new UltronPromptException(UltronPromptException::INVALID_GOVERNED, self::EMOJIS.' no es booleano.');
        }
        if (! is_int($max) || $max < self::MAX_CHARS_MIN || $max > self::MAX_CHARS_MAX || ($max - self::MAX_CHARS_MIN) % self::MAX_CHARS_STEP !== 0) {
            throw new UltronPromptException(UltronPromptException::INVALID_GOVERNED, self::MAX_CHARS.' no es un entero de 160 a 480 de 20 en 20.');
        }
        if (! is_string($tono) || ! in_array($tono, self::TONES, true)) {
            throw new UltronPromptException(UltronPromptException::INVALID_GOVERNED, self::TONE.' no es un valor del catálogo.');
        }
        if (! is_string($objetivo) || ! in_array($objetivo, self::GOALS, true)) {
            throw new UltronPromptException(UltronPromptException::INVALID_GOVERNED, self::GOAL.' no es un valor del catálogo.');
        }
        if (! is_array($frases) || ! array_is_list($frases) || array_filter($frases, static fn (mixed $f): bool => ! is_string($f)) !== []) {
            throw new UltronPromptException(UltronPromptException::INVALID_GOVERNED, self::PHRASES.' no es una lista de textos.');
        }
        $problema = self::phrasesViolation($frases);
        if ($problema !== null) {
            // El motivo es un código cerrado; nunca la frase (es texto del personal).
            throw new UltronPromptException(UltronPromptException::INVALID_PHRASES, self::PHRASES." no cumple el contrato ({$problema['reason']}).");
        }

        return [
            self::EMOJIS => $emojis,
            self::MAX_CHARS => $max,
            self::TONE => $tono,
            self::PHRASES => $frases,
            self::GOAL => $objetivo,
        ];
    }

    /**
     * Por qué no vale UNA frase, o null, en este orden: `charset` (UTF-8
     * inválido), `length` (fuera de 3 a 60 caracteres), `charset` (algo fuera
     * de {@see self::PHRASE_PATTERN}), `no_text` (ni una letra ni una cifra:
     * solo signos y espacios), `spacing` (empieza o acaba en espacio, o lleva dos seguidos: la forma
     * canónica de las frases del Studio nunca los tiene) o `anchor` (contiene
     * un ancla del compilador). Pura; la usa también el Studio para decir en la
     * vista previa qué frase de qué regla no cabe.
     */
    public static function phraseViolation(string $phrase): ?string
    {
        if (! mb_check_encoding($phrase, 'UTF-8')) {
            return self::PHRASE_CHARSET;
        }
        $largo = mb_strlen($phrase, 'UTF-8');
        if ($largo < self::PHRASE_MIN_CHARS || $largo > self::PHRASE_MAX_CHARS) {
            return self::PHRASE_LENGTH;
        }
        if (preg_match(self::PHRASE_PATTERN, $phrase) !== 1) {
            return self::PHRASE_CHARSET;
        }
        if (preg_match(self::PHRASE_TEXT_PATTERN, $phrase) !== 1) {
            return self::PHRASE_NO_TEXT;
        }
        if ($phrase !== trim($phrase, ' ') || str_contains($phrase, '  ')) {
            return self::PHRASE_SPACING;
        }
        foreach (self::ANCHORS as $ancla) {
            if (str_contains($phrase, $ancla)) {
                return self::PHRASE_ANCHOR;
            }
        }

        return null;
    }

    /**
     * Por qué no vale una LISTA de frases (ya comprobado que son textos), o
     * null: la primera razón que encuentra, en este orden: `too_many` (más de
     * {@see self::MAX_PHRASES}); la de la primera frase que no vale
     * ({@see self::phraseViolation()}), con su posición; `order` (no está
     * ordenada por bytes o tiene repetidas: se exige, no se arregla).
     *
     * @param  list<string>  $phrases
     * @return array{reason:string,index:int|null}|null
     */
    public static function phrasesViolation(array $phrases): ?array
    {
        if (count($phrases) > self::MAX_PHRASES) {
            return ['reason' => self::PHRASES_TOO_MANY, 'index' => null];
        }
        foreach ($phrases as $i => $frase) {
            $motivo = self::phraseViolation($frase);
            if ($motivo !== null) {
                return ['reason' => $motivo, 'index' => $i];
            }
        }
        for ($i = 1, $n = count($phrases); $i < $n; $i++) {
            if (strcmp($phrases[$i - 1], $phrases[$i]) >= 0) {
                return ['reason' => self::PHRASES_ORDER, 'index' => $i];
            }
        }

        return null;
    }

    /**
     * ¿Son estos los valores de la línea base? (con ellos, `compile()` es la base).
     *
     * @param  array<string,mixed>  $governed
     *
     * @throws UltronPromptException
     */
    public static function isBaseline(array $governed): bool
    {
        return self::validate($governed) === self::BASELINE;
    }

    /**
     * Los roles que cambian con estos valores, en orden canónico.
     *
     * @param  array<string,mixed>  $governed
     * @return list<string>
     *
     * @throws UltronPromptException
     */
    public static function changedRoles(array $governed): array
    {
        $tocados = array_unique(array_column(self::patches($governed), 'role'));

        return array_values(array_filter(UltronPromptBase::ROLES, static fn (string $r): bool => in_array($r, $tocados, true)));
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * La tabla de §B.3: los parches de UN parámetro con UN valor, en su orden.
     * La línea base no tiene ninguno.
     *
     * @param  bool|int|string|list<string>  $valor  ya validado
     * @return list<array{0:string,1:string,2:string,3:string}> [rol, operación, ancla, texto]
     */
    private static function patchesOf(string $parametro, bool|int|string|array $valor): array
    {
        $ambos = static fn (array $roles, string $op, string $ancla, string $texto): array => array_map(
            static fn (string $rol): array => [$rol, $op, $ancla, $texto],
            $roles,
        );
        $composers = ['composer', 'composer_retry'];
        $critics = ['critic', 'critic_retry'];

        switch ($parametro) {
            case self::TONE:
                return match ($valor) {
                    'profesional' => [
                        ...$ambos($composers, self::INSERT_AFTER_LINE, self::A_COMO_SUENAS, self::T_TONE_PRO_COMPOSER),
                        ...$ambos($critics, self::INSERT_AFTER_LINE, self::A_NATURALNESS, self::T_TONE_PRO_CRITIC),
                    ],
                    'entusiasta' => [
                        ...$ambos($composers, self::INSERT_AFTER_LINE, self::A_COMO_SUENAS, self::T_TONE_ENT_COMPOSER),
                        ...$ambos($critics, self::INSERT_AFTER_LINE, self::A_NATURALNESS, self::T_TONE_ENT_CRITIC),
                    ],
                    default => [],
                };

            case self::EMOJIS:
                return $valor === false ? [
                    ...$ambos($composers, self::REPLACE, self::A_EMOJI, self::T_EMOJIS_OFF_COMPOSER),
                    ...$ambos($critics, self::INSERT_AFTER_LINE, self::A_NATURALNESS, self::T_EMOJIS_OFF_CRITIC),
                ] : [];

            case self::MAX_CHARS:
                if ($valor === self::BASELINE[self::MAX_CHARS]) {
                    return [];
                }
                $n = static fn (string $plantilla): string => str_replace('{N}', (string) $valor, $plantilla);

                return [
                    ...$ambos($composers, self::INSERT_AFTER_LINE, self::A_FORMA, $n(self::T_MAX_COMPOSER)),
                    ['critic', self::REPLACE, self::A_900, $n(self::T_MAX_CRITIC_900)],
                    ['critic', self::INSERT_AFTER_LINE, self::A_PROGRESSIVE, $n(self::T_MAX_CRITIC)],
                    ['critic_retry', self::REPLACE, self::A_900, $n(self::T_MAX_CRITIC_900)],
                    ['critic_retry', self::INSERT_AFTER_LINE, self::A_PROGRESSIVE, $n(self::T_MAX_CRITIC)],
                ];

            case self::GOAL:
                return match ($valor) {
                    'venta' => [['strategist', self::INSERT_AFTER_LINE, self::A_PRINCIPIO, self::T_GOAL_VENTA]],
                    'informar' => [['strategist', self::INSERT_AFTER_LINE, self::A_PRINCIPIO, self::T_GOAL_INFORMAR]],
                    default => [],
                };

            case self::PHRASES:
                if (! is_array($valor) || $valor === []) {
                    return [];
                }
                // Concatenación, no plantilla: una frase nunca se interpreta.
                $lista = implode(', ', array_map(static fn (string $f): string => '"'.$f.'"', $valor));

                return [
                    ...$ambos($composers, self::INSERT_AFTER_LINE, self::A_FORMA, self::T_PHRASES_COMPOSER.$lista.self::T_PHRASES_END),
                    ...$ambos($critics, self::INSERT_AFTER_LINE, self::A_NATURALNESS, self::T_PHRASES_CRITIC.$lista.self::T_PHRASES_END),
                ];
        }

        return [];
    }

    /**
     * Un parche sobre un texto. El ancla tiene que aparecer exactamente una vez.
     *
     * @param  array{role:string,operation:string,anchor:string,text:string}  $parche
     *
     * @throws UltronPromptException
     */
    private static function apply(string $texto, array $parche): string
    {
        $ancla = $parche['anchor'];
        $veces = substr_count($texto, $ancla);
        if ($veces !== 1) {
            throw new UltronPromptException(UltronPromptException::ANCHOR, "El ancla de un parche de {$parche['role']} aparece {$veces} veces (tiene que ser una).");
        }
        $donde = (int) strpos($texto, $ancla);

        if ($parche['operation'] === self::REPLACE) {
            return substr_replace($texto, $parche['text'], $donde, strlen($ancla));
        }

        $finDeLinea = strpos($texto, "\n", $donde + strlen($ancla));
        if ($finDeLinea === false) {
            return $texto."\n".$parche['text'];
        }

        return substr($texto, 0, $finDeLinea)."\n".$parche['text'].substr($texto, $finDeLinea);
    }
}
