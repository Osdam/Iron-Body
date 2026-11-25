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
 * VERSIÓN 3 (Enmienda A4-1 del H5; cierre V2, apartado 4): el texto del tope
 * para el redactor ({@see self::T_MAX_COMPOSER}) dice que el límite se mide
 * antes de enviar y le pide un margen: unos `M` caracteres (el 80 % de `N` a
 * la decena por abajo), unas `P` palabras y como mucho `F` frases. El del
 * Critic, las anclas, las operaciones y el orden no cambian, y con 320 sigue
 * sin haber parche: `compile(línea base)` y la huella Legacy quedan igual.
 *
 * VERSIÓN 4 (Enmienda A4-2 del H5; cierre V2, apartado 4, H4-9): cambian los
 * dos textos del tono `profesional`. El del redactor
 * ({@see self::T_TONE_PRO_COMPOSER}) manda sobre TODO el prompt (también
 * sobre los ejemplos que van debajo de su línea, `knowledge_base.tone`,
 * `suggested_question` y `approved_brand_copy`) y da las equivalencias de
 * usted; el del revisor ({@see self::T_TONE_PRO_CRITIC}) hace FALLAR el
 * tuteo (verdict fail), que es lo único que provoca el reintento de n8n.
 * Cambian también los dos textos fijos de las frases prohibidas
 * ({@see self::T_PHRASES_COMPOSER} y {@see self::T_PHRASES_CRITIC}; revisión
 * en `plans/informes-motores/revision-frases-prohibidas.md`): mandan sobre el
 * resto del prompt y dicen qué cuenta (tildes, plural, citas, negación) y qué
 * no (un trozo de otra palabra). Las anclas, las operaciones, el orden y el
 * juego cerrado de las frases no cambian; `cercano` y `entusiasta` sin
 * frases, y la línea base, tampoco.
 *
 * EL PARCHE DEL MOTOR (también compilador 4, que no está publicado en ningún
 * sitio; cierre V2, apartado 4, «regenerar antes de sustituir», plan del
 * arquitecto `plan-arquitecto-a4-regenerar.md` §3 P-3, cambio 3, y §7.1). La
 * instrucción de la lista de planes que redacta el modelo con `{{PLAN_1}}` a
 * `{{PLAN_3}}` ({@see self::T_PLANS_COMPOSER} y {@see self::T_PLANS_CRITIC})
 * no es de un parámetro gobernado: es del motor ({@see self::ENGINE}), como la
 * base. Se añade AL FINAL de los parches y SOLO si la versión ya tiene alguno
 * gobernado: con la línea base no hay ninguno y `compile()` sigue siendo la
 * base byte a byte con la huella Legacy (M1-4: «línea base = como hoy»). Va al
 * redactor y a su reintento tras `FORMA:` y a los dos revisores tras
 * `progressive_disclosure:` (sin él, el revisor tumbaría la lista por «no
 * lleva el marcador de precio»). Como es el último, queda pegado a su ancla.
 * Cambian las huellas de TODO paquete no base. La vista previa del Studio no
 * lo enseña (agrupa por {@see self::PARAMETERS}); la huella que se firma, sí
 * lo lleva. `UltronPromptOffer` firma `sp.o = 1` cuando el paquete lo trae.
 *
 * VERSIÓN 5 (Enmienda A4-5 del H5; PT-17, diseño del arquitecto
 * `plans/informes-motores/diseno-arq-pt17-planes.md`): el parche del motor
 * pasa de cuatro entradas a once, porque en la cuarta prueba real tres
 * instrucciones firmadas empujaban contra la lista en el PRIMER borrador (el
 * estratega, la base del redactor y «escribes dentro del objetivo de la
 * estrategia»). En el estratega, una inserción tras `QUE PLAN SE COTIZA`
 * ({@see self::T_PLANS_STRATEGIST}) y una sustitución de «Presentalo con dos o
 * tres beneficios reales…» ({@see self::T_PLANS_STRATEGIST_PRESENT}); en los
 * dos redactores, el texto nuevo tras `FORMA:` ({@see self::T_PLANS_COMPOSER},
 * sustituye al del 4: esqueleto y presupuesto en palabras) y las frases de la
 * base que lo contradecían, vueltas condicionales EN SU SITIO
 * ({@see self::T_PLANS_COMPOSER_MARKER}, {@see self::T_PLANS_COMPOSER_PRICE}
 * y, solo en el redactor, {@see self::T_PLANS_COMPOSER_CHOOSE}). El revisor
 * no cambia ({@see self::T_PLANS_CRITIC}, byte a byte). Las cinco anclas
 * nuevas van a {@see self::ANCHORS}; ningún texto contiene un ancla y cada
 * sustituto cambia el signo final de la frase que sustituye. Sigue yendo al
 * final y solo si hay algún parche gobernado: la línea base y la huella
 * Legacy no se mueven; cambian las huellas de TODO paquete no base.
 *
 * VERSIÓN 6 (Enmienda A4-6 del H5; PT-17b, diseño del arquitecto
 * `plans/informes-motores/diseno-arq-pt17b-planes-c6.md` con la enmienda del
 * lead): solo textos y solo el parche del motor, que pasa de once entradas a
 * quince, porque en la medición de la versión 4 el primer borrador no se
 * enteró del texto del 5 (iba a mitad del prompt y lo contradecían las reglas
 * de detrás) y el revisor tumbaba listas válidas por un tuteo que no había.
 * En los dos redactores, el texto de la lista se va al FINAL (inserción tras
 * la última línea, `price_verification true significa`) como GUÍA con
 * libertad de redacción, sin frase modelo ni pregunta fija
 * ({@see self::T_PLANS_COMPOSER}, sustituye al del 5; decisión del
 * propietario, que descartó la plantilla del diseño): su longitud la dice en
 * caracteres `<L>` = el tope de la lista ({@see self::plansListChars()}, la
 * misma cifra con la que la mide Laravel) y en palabras `<W>`
 * ({@see self::plansListWords()}), las dos del tope gobernado. La frase de
 * `suggested_question` ({@see self::T_PLANS_COMPOSER_ASK}) y la del precio
 * ({@see self::T_PLANS_COMPOSER_PRICE}, sustituye a la del 5) se vuelven
 * condicionales EN SU SITIO. En los dos revisores, solo inserciones tras su
 * última línea («Si el borrador explica POR QUE la persona vio otro
 * precio…»): el bloque del turno de planes ({@see self::T_PLANS_CRITIC},
 * sustituye al del 4 y el 5) y una frase corta que dice que el registro y la
 * longitud de ese borrador ya los comprueba el backend
 * ({@see self::T_PLANS_CRITIC_CHECKED}); como son dos inserciones tras la
 * misma línea, quedan esa frase y después LISTA, la última. Las dos acotadas
 * a un borrador con `PLAN_1` a `PLAN_3`. El estratega, el tono, el tope, las
 * frases, la base y la huella Legacy no cambian. Las tres anclas nuevas van a
 * {@see self::ANCHORS}.
 *
 * VERSIÓN 7 (Enmienda A4-7 del H5; PT-17c, nota de diseño
 * `plans/informes-motores/diseno-pt17c-estratega-c7.md`): solo un texto, el
 * de la lista del estratega ({@see self::T_PLANS_STRATEGIST}, sustituye al del
 * 5 y el 6; misma ancla y misma operación). En la medición de la v4 con el 6,
 * ese texto pedía `next_best_action recommend_plan` y un `recommended_plan_id`
 * «nunca null» junto a la lista, y el revisor, que no recibe `plans_overview`,
 * juzgaba la lista contra una estrategia de un solo plan. Ahora la estrategia
 * es coherente con la lista que decidió el servidor (`answer`, de
 * `allowed_next_actions`; el plan, solo referencia del contrato; `PLAN_k` =
 * la posición k; la lista, siempre entera: una recomendación, solo si se
 * pidió y después de la lista). Los redactores, los revisores, las anclas, el orden, el
 * tope de la lista, la base y la huella Legacy no cambian.
 *
 * VERSIÓN 8 (PT-17, puerta 2, L5; especificación del arquitecto del
 * 24-11-2026, §2, con los textos que aprobó el propietario ese día, paso H0):
 * la lista de planes la escribe el backend y el modelo escribe la prosa.
 * Cambian siete textos del parche del motor y uno gobernado; las anclas, las
 * operaciones, el orden, las quince entradas, la base y la huella Legacy, no.
 * El estratega ({@see self::T_PLANS_STRATEGIST}): un `response_goal` sin
 * cifras, nombres de plan ni marcadores. Los redactores: el marcador, «Elige
 * el plan…» y el precio ({@see self::T_PLANS_COMPOSER_MARKER},
 * {@see self::T_PLANS_COMPOSER_CHOOSE} y {@see self::T_PLANS_COMPOSER_PRICE})
 * y la guía del final ({@see self::T_PLANS_COMPOSER}), que pide la ranura
 * `LISTA_DE_PLANES` entre dobles llaves (sin ella, el backend pone la lista
 * antes de la pregunta final) y lleva una sola cifra, `<L>` (se van `<W>` y
 * «unos 25»). Los revisores: la lista no se juzga, solo la prosa
 * ({@see self::T_PLANS_CRITIC}), y en ella el registro, la longitud y la lista
 * los comprueba el backend y solo es `hard_fail` lo de HARD_FAIL y FALLA
 * TAMBIEN, P-1 A ({@see self::T_PLANS_CRITIC_CHECKED}). El tope gobernado del
 * redactor ({@see self::T_MAX_COMPOSER}) suma un sufijo: en un turno de lista
 * no aplica. {@see self::T_PLANS_STRATEGIST_PRESENT} y
 * {@see self::T_PLANS_COMPOSER_ASK}, igual que en el 7.
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
 * ({@see self::PARAMETERS}: tono, emojis, longitud, objetivo, frases; y, si
 * hay alguno, al final el del motor) y,
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
     * publicarla. La 3 (Enmienda A4-1 del H5, cierre V2, apartado 4) solo
     * cambia el texto del tope para el redactor ({@see self::T_MAX_COMPOSER}):
     * le pide un margen por debajo del límite, porque el commit lo mide antes
     * de enviar. Cambian las huellas de todo paquete con `maxChars` distinto
     * de 320; la línea base y la huella Legacy, no. La 4 (Enmienda A4-2 del H5)
     * cambia los dos textos del tono `profesional`
     * ({@see self::T_TONE_PRO_COMPOSER} y {@see self::T_TONE_PRO_CRITIC}) y
     * los dos textos fijos de las frases prohibidas
     * ({@see self::T_PHRASES_COMPOSER} y {@see self::T_PHRASES_CRITIC}):
     * cambian las huellas de todo paquete con ese tono o con frases, y
     * ninguna otra. Antes de publicarse en ningún sitio, el 4 suma además el
     * parche del motor de la lista de planes ({@see self::ENGINE}; «regenerar
     * antes de sustituir»): con él cambian las huellas de TODO paquete no
     * base; la línea base y la huella Legacy, no. No se sube a 5 porque el 4
     * no ha llegado a producción ni a ninguna publicación. La 5 (Enmienda A4-5
     * del H5, PT-17) cambia el parche del motor de la lista de planes: once
     * entradas en vez de cuatro (estratega y redactores; el revisor, igual).
     * El 4 sí está publicado (la v3 en canario): lo firmado con él cae a
     * Legacy (`compiler_changed`) hasta publicar una versión nueva. La 6
     * (Enmienda A4-6 del H5, PT-17b) vuelve a cambiar solo el parche del
     * motor: quince entradas (los redactores, con el texto de la lista al
     * final, y los revisores, con dos inserciones al final); el texto de la
     * lista lleva el tope de la lista, la cifra con la que Laravel mide desde
     * este compilador (`sp.k`) el mensaje que la trae. Con el 5 no se publicó
     * nada; lo que se firme con él caería igual a `compiler_changed`. La 7
     * (Enmienda A4-7 del H5, PT-17c) cambia SOLO el texto de la lista del
     * estratega ({@see self::T_PLANS_STRATEGIST}): mismas quince entradas,
     * mismas anclas; los redactores y los revisores, byte a byte los del 6.
     * Lo firmado con el 6 (la v4 en canario) cae a Legacy
     * (`compiler_changed`) hasta publicar una versión nueva; la regla «tope
     * de lista» sigue valiendo (`sp.k` ≥ 6). La 8 (PT-17, puerta 2, L5)
     * cambia siete textos del parche del motor (la lista la escribe el
     * backend: la guía de los redactores pide la ranura `LISTA_DE_PLANES` y
     * solo lleva `<L>`) y el del tope del redactor
     * ({@see self::T_MAX_COMPOSER}, un sufijo); mismas quince entradas, mismas
     * anclas. Lo firmado con otro compilador cae a Legacy
     * (`compiler_changed`) hasta publicar una versión nueva.
     */
    public const VERSION = 9;

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
     * El parche del MOTOR (compilador 4, «regenerar antes de sustituir»): la
     * instrucción de la lista de planes. NO es un parámetro gobernado (no está
     * en {@see self::PARAMETERS} ni en la línea base): va detrás de todos,
     * solo si hay alguno, con `parameter` = esto y `value` = true.
     */
    public const ENGINE = 'engine.plansOverview';

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
     * Compilador 6 (Enmienda A4-6 del H5; regla «tope de lista» del
     * propietario): un mensaje con la lista de planes del servidor se mide
     * contra el tope publicado y nunca contra menos de esto
     * ({@see self::plansListChars()}).
     */
    public const PLANS_LIST_MIN_CHARS = 320;

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

    // Compilador 5 (Enmienda A4-5; PT-17): las del parche del motor en el
    // estratega (las dos primeras) y en los redactores (la de «Elige el plan…»,
    // solo en `composer`). Una vez cada una en su rol de la base.

    private const A_COTIZA = 'QUE PLAN SE COTIZA';

    private const A_PRESENTALO = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos.';

    private const A_MARCADOR = 'Cualquier otro marcador hace que el mensaje se rechace.';

    private const A_ELIGE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues.';

    private const A_PRECIO = 'el precio solo del que se cotiza.';

    // Compilador 6 (Enmienda A4-6; PT-17b): la de `suggested_question` en los
    // dos redactores (`composer.txt:5`), la última línea de los dos redactores y
    // la última de los dos revisores (`insert_after_line` sobre la última línea
    // añade el texto al final). Una vez cada una en su rol de la base.

    private const A_SUGERIDA = 'Si vas a preguntar, usa suggested_question cuando venga:';

    private const A_FIN_COMPOSER = 'price_verification true significa';

    private const A_FIN_CRITIC = 'Si el borrador explica POR QUE la persona vio otro precio';

    // ── Textos aprobados (plan §B.3, M1-3). `{N}` es el valor de persona.maxChars;
    //    `{M}`, `{P}` y `{F}`, su margen para el redactor (compilador 3, §B.3
    //    «Compilador 3»): {@see self::composerTargetChars()},
    //    {@see self::composerTargetWords()} y {@see self::composerMaxSentences()}. ──

    /**
     * Compilador 4 (§B.3, «Compilador 4», Enmienda A4-2 del H5). Sustituyen a
     * los de los compiladores 1 a 3 (que quedan escritos como históricos en la
     * tabla de §B.3): en la prueba real del 18-11-2026 el redactor tuteó con
     * «profesional», porque el texto anterior solo mandaba sobre «los ejemplos
     * de arriba», y el revisor aprobó, porque tutear solo contaba en
     * naturalness y no lo hacía fallar. Pendientes de la aprobación del
     * propietario.
     */
    private const T_TONE_PRO_COMPOSER = 'REGISTRO PUBLICADO: profesional. Esto manda sobre TODO este prompt, tambien sobre los ejemplos de abajo, knowledge_base.tone, suggested_question y approved_brand_copy: trata a la persona de usted en cada frase, sin mezclar. Los ejemplos se dicen de usted: "Te entiendo" es "Le entiendo", "tu objetivo" es "su objetivo", "te paso" es "le paso", "cuentame" es "cuenteme", "quieres" es "quiere". Nunca "te", "tu", "tus", "ti" ni "contigo". Frases completas y sobrias, sin muletillas coloquiales ("De una", "Listo", "Tranquilo") ni exclamaciones seguidas. Sigue siendo calido; profesional no es frio. El backend comprueba el registro antes de enviar y descarta el mensaje que tutee.';

    private const T_TONE_PRO_CRITIC = 'REGISTRO PUBLICADO: profesional. Tratar de usted NO es defecto. Si el borrador tutea en cualquier frase o mezcla tu y usted ("te", "tu", "tus", "ti", "contigo", "quieres", "puedes", "cuentame", "dime"), verdict fail con naturalness y notes "pasar todo el mensaje a usted". Lo que va entre comillas citando a la persona no cuenta.';

    private const T_TONE_ENT_COMPOSER = 'REGISTRO PUBLICADO: entusiasta. Mas energia: celebra lo que la persona quiere lograr y transmite ganas de acompanarla. Sin exagerar, sin prometer resultados y sin urgencia ni presion.';

    private const T_TONE_ENT_CRITIC = 'REGISTRO PUBLICADO: entusiasta. La energia NO es defecto de naturalness; la urgencia o la presion siguen siendo PRESSURE.';

    private const T_EMOJIS_OFF_COMPOSER = 'Sin emojis: ninguno, en ningun mensaje.';

    private const T_EMOJIS_OFF_CRITIC = 'EMOJIS: la version publicada no usa emojis; si el borrador lleva alguno, verdict fail con naturalness.';

    /**
     * Compilador 3. Sustituye al del compilador 1–2 («como maximo unos {N}
     * caracteres…», que queda escrito como histórico en §B.3): en la prueba
     * real del 12-11-2026 el redactor superó el tope de 200 en 4 de 5
     * borradores. Pendiente de la aprobación del propietario (HUMAN GATE 4-A).
     * Compilador 8 (PT-17, puerta 2, L5): suma un sufijo tras «…ofrece
     * ampliar.»; en un turno de lista este tope no aplica y manda la longitud
     * de la guía de la lista ({@see self::T_PLANS_COMPOSER}), que va al final.
     */
    private const T_MAX_COMPOSER = 'TOPE DE LONGITUD PUBLICADO: el limite es {N} caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos {M} caracteres, que son unas {P} palabras: como mucho {F} frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar. Si commercial_turn_policy.plans_overview trae planes, este tope no aplica a ese mensaje: manda LONGITUD DE ESTE TURNO, al final del prompt.';

    private const T_MAX_CRITIC_900 = 'mas de ~{N} caracteres';

    private const T_MAX_CRITIC = 'TOPE PUBLICADO: un borrador de mas de ~{N} caracteres falla por progressive_disclosure aunque se haya pedido detalle.';

    private const T_GOAL_VENTA = 'OBJETIVO COMERCIAL PUBLICADO: cerrar membresia. Con senales claras de compra (buying_signal strong, hot_lead_fast_path o READY) prioriza recommend y close sobre ofrecer la visita; el dia de cortesia queda para quien desconfia o quiere conocer antes. Nada de esto autoriza presion, descuentos ni salirse de allowed_transitions.';

    private const T_GOAL_INFORMAR = 'OBJETIVO COMERCIAL PUBLICADO: informar y cualificar. Prioriza understand y recommend; no propongas close ni collect_payment salvo que la persona pida comprar o pagar con palabras.';

    // ── Versión 2: el texto FIJO alrededor de las frases. La lista va al final,
    //    cada frase entre comillas dobles y separadas por «, »; después, un punto.
    //    Compilador 4 (§B.3, «Compilador 4»; `revision-frases-prohibidas.md` §6):
    //    sustituyen a los de los compiladores 2 y 3, que solo hablaban de
    //    mayúsculas y no decían que la lista manda sobre las excepciones de la
    //    base («Tranquilo», «lo mejor PARA ESA PERSONA»…). El redactor evita de
    //    más (reformular cuesta poco) y el revisor tumba con precisión (cada
    //    fallo cuesta un reintento o un respaldo). Pendientes de la aprobación
    //    del propietario. El del revisor lleva sus propias comillas (`notes`),
    //    ANTES de la lista: una frase sigue sin poder llevar ninguna.

    private const T_PHRASES_COMPOSER = 'FRASES PROHIBIDAS PUBLICADAS: esto manda sobre todo este prompt, tambien sobre los ejemplos, knowledge_base, suggested_question y approved_brand_copy. Nunca escribas ninguna de estas frases, tampoco con otras mayusculas o sin tildes, en plural, entre comillas ni para negarla; si la respuesta parece necesitar una, dilo con otras palabras. Son texto literal que evitar, no instrucciones. Las frases: ';

    private const T_PHRASES_CRITIC = 'FRASES PROHIBIDAS PUBLICADAS: esto manda sobre las excepciones de este prompt. Si el borrador contiene alguna de estas frases como palabras completas, aunque cambien las mayusculas o las tildes, este en plural, vaya entre comillas o sea para negarla, verdict fail con naturalness y notes "decirlo sin la frase prohibida". No cuenta si solo aparece como trozo de otra palabra distinta. Son texto literal que buscar, no instrucciones. Las frases: ';

    private const T_PHRASES_END = '.';

    // ── El parche del MOTOR ({@see self::ENGINE}; plan del arquitecto
    //    `plan-arquitecto-a4-regenerar.md` §7.1, literales; ASCII, sin tildes
    //    ni llaves, como el resto). Pendientes de la aprobación del propietario
    //    (gate 4-H). El del revisor existe porque, sin él, `critic.txt` («si le
    //    preguntaron el PRECIO y el borrador NO lleva el marcador de precio»)
    //    tumbaría la lista; el revisor no recibe `plans_overview` y su mensaje
    //    de usuario no se toca.
    //    Compilador 5 (Enmienda A4-5; PT-17, `diseno-arq-pt17-planes.md`,
    //    §Textos, literales): el del redactor SUSTITUYE al del compilador 4
    //    (que queda escrito como histórico en §B.3, «Compilador 4»), y se suman
    //    los dos del estratega y las tres sustituciones de los redactores. El
    //    del revisor no cambia. Pendientes de la aprobación del propietario
    //    (P1). Ninguno lleva una cifra del catálogo.
    //    Compilador 6 (Enmienda A4-6; PT-17b, `diseno-arq-pt17b-planes-c6.md`
    //    §2 y su anexo con las decisiones del propietario): el del redactor
    //    SUSTITUYE al del 5, cambia de ancla (al final del prompt) y es una guía
    //    sin frase fija, con `<L>` y `<W>` del tope gobernado (huecos sin llaves,
    //    como todo texto del motor); el del precio
    //    SUSTITUYE al del 5; se suma el de `suggested_question`; el del revisor
    //    SUSTITUYE al del 4 y el 5 (también al final) y se suma la frase de lo
    //    que ya comprueba el backend (en lugar de la lista cerrada de registro
    //    del diseño). El estratega, el marcador y «Elige el plan…» no cambian.
    //    Los del 5 que cambian quedan escritos como históricos en §B.3.
    //    Aprobados por el propietario (23-11-2026). Ninguno lleva una cifra del
    //    catálogo (el 25 es lo que ocupa un marcador expandido, de media). ──

    //    Compilador 7 (Enmienda A4-7; PT-17c,
    //    `plans/informes-motores/diseno-pt17c-estratega-c7.md`): solo el
    //    texto de la lista del estratega SUSTITUYE al del 5 y el 6 (que queda
    //    escrito como histórico en §B.3, «Compilador 5»). La estrategia deja de
    //    pedir «recomendar un plan» cuando el servidor ya decidió la lista:
    //    la lista va siempre entera y una recomendación nunca la sustituye
    //    (solo si la persona la pidió, y después de la lista);
    //    `next_best_action answer` (está en `allowed_next_actions` de la
    //    política en todo turno con lista), `recommended_plan_id` solo como
    //    referencia del contrato (el que se destaca si se pidió la
    //    recomendación), `PLAN_k` = la posición k de la lista, y el
    //    `response_goal` dicho por el estratega (cada plan con su marcador y un
    //    beneficio fiel a sus `benefits`, lo demás que preguntó y una pregunta
    //    breve opcional), sin frase fija. Los textos del redactor y del
    //    revisor, igual que en el 6, byte a byte. Ninguna cifra del catálogo
    //    (el 160 es el tope de `response_goal` del commit).

    //    Compilador 8 (PT-17, puerta 2, L5; textos aprobados por el
    //    propietario el 24-11-2026, paso H0, copiados byte a byte): la lista la
    //    escribe el backend. SUSTITUYEN a los del 7 (que quedan escritos como
    //    históricos en §B.3) el del estratega (el `response_goal`, sin nombres
    //    de plan ni marcadores; ya no explica `PLAN_k`), el del marcador, el de
    //    «Elige el plan…», el del precio, la guía del redactor (la ranura
    //    `LISTA_DE_PLANES` entre dobles llaves y una sola cifra, `<L>`), el del
    //    revisor (juzga solo la prosa) y la frase de lo que comprueba el
    //    backend (con el veredicto de P-1 A: `hard_fail` solo para HARD_FAIL y
    //    FALLA TAMBIEN). El de «Presentalo…» y el de `suggested_question`, igual.
    //    Ninguna cifra del catálogo: la guía, solo `<L>`; el estratega, el 160.

    private const T_PLANS_STRATEGIST = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend decidio responder con esa lista, que escribe el backend con el nombre y el precio de cada plan, en su orden. En ese turno esto manda sobre las reglas de este apartado, la lista de next_best_action, resolved_reference y strategy_hints (suggested_question, should_recommend_now): el turno RESPONDE con la lista entera; no descubre; una recomendacion no sustituye la lista: solo si se pidio, va tras ella. Entonces next_best_action answer (de allowed_next_actions), conversation_goal recommend, recommendation_goal compare_plans, information_needed [] y recommended_plan_id el que digan las reglas de abajo (nunca null), solo como referencia del contrato: se destaca solo si se pidio. response_goal con tus palabras, sin cifras ni nombres de plan y en menos de 160 caracteres: que va la lista de planes del backend, lo demas que pregunto en ese mensaje, nombrandolo, y, si cabe, una pregunta breve para elegir. Si plans_overview no viene, nada de esto aplica.';

    private const T_PLANS_STRATEGIST_PRESENT = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos; si commercial_turn_policy.plans_overview trae planes, no: manda LISTA DE PLANES DEL BACKEND.';

    private const T_PLANS_COMPOSER = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios y el backend ya eligio cuales mostrar y escribe la lista: cada plan de plans_overview.plans, en su orden, con su nombre y su precio oficiales. Tu escribes el resto con tus palabras y pones LISTA_DE_PLANES entre dobles llaves, una sola vez, donde va la lista; si no la pones, el backend la pone antes de tu pregunta final. No escribas nombres de plan, cifras ni marcadores que empiecen por PLAN_. Puedes hablar de los planes sin nombrarlos o por su lugar en la lista (el primero, el segundo, el tercero), con sus benefits. Si pregunto ademas otra cosa, respondela tambien; si pidio una recomendacion, va despues de la lista. Puedes abrir con una frase corta y cerrar con UNA pregunta breve que ayude a elegir o a avanzar; suggested_question no es obligatoria aqui. LONGITUD DE ESTE TURNO: el mensaje completo, ya con la lista, no pasa de <L> caracteres. En este turno esto manda sobre lo anterior que choque (orden del mensaje, recomendar un solo plan, preguntar antes de informar, los marcadores y el tope general). Si plans_overview no viene, nada de esto aplica.';

    private const T_PLANS_COMPOSER_ASK = 'Si vas a preguntar, usa suggested_question cuando venga, nunca si commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND):';

    private const T_PLANS_COMPOSER_MARKER = 'Cualquier otro marcador hace que el mensaje se rechace, salvo LISTA_DE_PLANES entre dobles llaves cuando commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND).';

    private const T_PLANS_COMPOSER_CHOOSE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues; si commercial_turn_policy.plans_overview trae planes, en vez de eso el backend pone esa lista (LISTA DE PLANES DEL BACKEND).';

    private const T_PLANS_COMPOSER_PRICE = 'el precio solo del que se cotiza o, si commercial_turn_policy.plans_overview trae planes, ni nombres ni precios a mano: la lista con nombres y precios la pone el backend (LISTA DE PLANES DEL BACKEND).';

    private const T_PLANS_CRITIC = 'LISTA DE PLANES DEL BACKEND (va al final porque manda sobre todo lo anterior): si reply_draft lleva LISTA_DE_PLANES, o PLAN_1, PLAN_2 o PLAN_3, entre dobles llaves, o la persona pidio los planes o los precios en general y el borrador no nombra planes ni precios, el backend pone ahi, o antes de la pregunta final, la lista con el nombre y el precio oficiales de cada plan elegido, en su orden. Esa lista no la juzgas: juzga solo la prosa. Cuenta como el precio de cada plan: no es invented_plan ni invented_price, no esquiva el precio y no es plantilla, folleto ni exceso de planes. En ese borrador NO son defecto: no preguntar antes objetivo, experiencia u horario, aunque la fase sea NEW_LEAD o DISCOVERY o la estrategia traiga recommended_plan_id, ni cerrar con una pregunta corta para elegir o sin pregunta. Un nombre de plan o una cifra escritos a mano los rechaza el backend.';

    private const T_PLANS_CRITIC_CHECKED = 'VEREDICTO EN LA LISTA DE PLANES: si reply_draft entra en LISTA DE PLANES DEL BACKEND (abajo), el registro (tu/usted), la longitud y la lista ya los comprueba el backend de forma determinista: no falles, no bajes naturalness ni los menciones en notes por ellos. En ese borrador es fail con hard_fail solo un incumplimiento real de seguridad, exactitud o politica de HARD_FAIL o de FALLA TAMBIEN (un plan, clase, servicio, beneficio u horario que no esta en active_plans, gym o knowledge_base es invented_class), y fail sin hard_fail solo si deja sin responder una pregunta directa del mensaje. Lo demas es verdict pass, con la mejora en notes.';

    /**
     * Las anclas, para rechazar una frase que contenga alguna (la unicidad no
     * puede depender de lo que escriba el personal). Las cinco del compilador 5
     * también: una frase va a los redactores ANTES que el parche del motor, y
     * con un ancla dentro lo dejaría sin ancla única. Dos de ellas
     * ({@see self::A_PRESENTALO} y {@see self::A_ELIGE}) son más largas que
     * {@see self::PHRASE_MAX_CHARS}: ninguna frase puede contenerlas. Las tres
     * del compilador 6 también; dos ({@see self::A_SUGERIDA} y
     * {@see self::A_FIN_COMPOSER}) llevan `_` o `:`, fuera del juego cerrado de
     * una frase; la del revisor ({@see self::A_FIN_CRITIC}, 57 caracteres del
     * juego) sí podría ir dentro de una frase, y una frase va a los revisores
     * ANTES que el parche del motor.
     */
    private const ANCHORS = [self::A_COMO_SUENAS, self::A_FORMA, self::A_EMOJI, self::A_NATURALNESS, self::A_PROGRESSIVE, self::A_900, self::A_PRINCIPIO, self::A_COTIZA, self::A_PRESENTALO, self::A_MARCADOR, self::A_ELIGE, self::A_PRECIO, self::A_SUGERIDA, self::A_FIN_COMPOSER, self::A_FIN_CRITIC];

    /**
     * Compila el paquete de los cinco prompts.
     *
     * @param  array<string,mixed>  $governed  exactamente las cinco claves de {@see self::BASELINE}, con sus tipos
     * @return array{contract:string,base:string,compiler:int,governed:array{"persona.emojis":bool,"persona.maxChars":int,"persona.tone":string,"rules.forbiddenPhrases":list<string>,"strategy.goal":string},roles:array<string,string>,bundleHash:string,bytes:int,patches:list<array{parameter:string,value:bool|int|string|list<string>,role:string,operation:string,anchor:string,text:string}>}
     *
     * @throws UltronPromptException valores fuera del contrato, base ilegible o alterada, ancla ausente o repetida, o un tope superado
     */
    public static function compile(array $governed, int $compiler = self::VERSION): array
    {
        // Compiler 8 remains reproducible for archived evidence and outstanding
        // signed tokens. New publications/offers always request VERSION.
        if (! in_array($compiler, [8, self::VERSION], true)) {
            throw new UltronPromptException(UltronPromptException::INVALID_GOVERNED, 'Versión de compilador no reproducible.');
        }
        $valores = self::validate($governed);
        $parches = self::patches($valores);
        $roles = UltronPromptBase::roles();

        foreach ($parches as $parche) {
            $roles[$parche['role']] = self::apply($roles[$parche['role']], $parche);
        }

        // Both alternatives are signed. Selection happens before inference;
        // the generic quotation prompt is never sent on the list route.
        // Baseline/Legacy stays byte-for-byte identical to its historical base.
        if ($compiler >= UltronListTurnPrompts::SINCE_COMPILER && $parches !== []) {
            foreach ($roles as $rol => $texto) {
                $roles[$rol] = UltronListTurnPrompts::pack($rol, $texto, $valores);
            }
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
            'compiler' => $compiler,
            'governed' => $valores,
            'roles' => $roles,
            'bundleHash' => UltronPromptBase::bundleHash($roles),
            'bytes' => $total,
            'patches' => $parches,
        ];
    }

    /**
     * Los parches que aplicaría {@see self::compile()}, en su orden, SIN leer
     * la base: los de los parámetros gobernados, que la vista previa del Studio
     * enseña como «cambio exacto», y, al final, los del motor
     * ({@see self::ENGINE}), que no enseña (agrupa por {@see self::PARAMETERS}).
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

        // El del motor, al final y solo si hay algún parche gobernado: con la línea base, ninguno (M1-4).
        if ($parches !== []) {
            foreach (self::enginePatches($valores[self::MAX_CHARS]) as [$rol, $operacion, $ancla, $texto]) {
                if (strlen($texto) > self::MAX_PATCH_BYTES || str_contains($texto, "\n") || str_contains($texto, "\r")) {
                    throw new UltronPromptException(UltronPromptException::TOO_LARGE, 'Un parche de '.self::ENGINE.' supera '.self::MAX_PATCH_BYTES.' bytes o tiene saltos de línea.');
                }
                $parches[] = [
                    'parameter' => self::ENGINE,
                    'value' => true,
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

    // ── El margen del redactor (compilador 3; plan §B.3, «Compilador 3») ─────
    //    Enteros, sin coma flotante: 200 → 160, 25 y 3; 160 → 120, 20 y 2; 480 → 380, 60 y 6.

    /** `{M}`: los caracteres a los que apunta el redactor, el 80 % del tope redondeado a la decena por abajo. */
    public static function composerTargetChars(int $maxChars): int
    {
        return intdiv($maxChars * 8, 100) * 10;
    }

    /** `{P}`: esos caracteres en palabras, unas seis letras por palabra, de cinco en cinco por abajo. */
    public static function composerTargetWords(int $maxChars): int
    {
        return intdiv(self::composerTargetChars($maxChars), 30) * 5;
    }

    /** `{F}`: las frases cortas como mucho, una por cada 60 caracteres (redondeando), y nunca menos de dos. */
    public static function composerMaxSentences(int $maxChars): int
    {
        return max(2, intdiv(self::composerTargetChars($maxChars) + 30, 60));
    }

    // ── El tope de la lista de planes (compilador 6; plan §B.3, «Compilador 6») ─
    //    Enteros: 160, 200 y 320 → 320 y 35; 480 → 480 y 60. Desde el
    //    compilador 8 la guía solo lleva L (§B.3, «Compilador 8»).

    /**
     * `<L>` del texto de la lista del redactor ({@see self::T_PLANS_COMPOSER}):
     * el tope de un mensaje con la lista de planes del servidor, el publicado y
     * nunca menos de {@see self::PLANS_LIST_MIN_CHARS}. Es la MISMA cifra con la
     * que Laravel mide ese mensaje
     * (`UltronReplyFitter::plansListCap()`, en `App\Services\Marketing\Ultron`):
     * lo que se le pide al modelo y lo que se le exige salen de aquí.
     */
    public static function plansListChars(int $maxChars): int
    {
        return max($maxChars, self::PLANS_LIST_MIN_CHARS);
    }

    /**
     * `<W>`: las palabras del redactor fuera de los marcadores con ese tope,
     * (L − 110) / 6 de cinco en cinco por abajo (unos 25 caracteres por cada
     * marcador ya expandido y unas seis letras por palabra).
     *
     * SIN USO desde el compilador 8: la guía ya no lleva `<W>` (la lista la
     * escribe el backend, así que no hay marcadores que descontar). Se queda
     * como histórico de §B.3, «Compilador 6»; no se borra.
     */
    public static function plansListWords(int $maxChars): int
    {
        return intdiv(self::plansListChars($maxChars) - 110, 30) * 5;
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
                // Compilador 3: el margen sale del valor, no del texto (son cifras: ninguna sustitución pisa a otra).
                $redactor = str_replace(
                    ['{M}', '{P}', '{F}'],
                    [(string) self::composerTargetChars($valor), (string) self::composerTargetWords($valor), (string) self::composerMaxSentences($valor)],
                    $n(self::T_MAX_COMPOSER),
                );

                return [
                    ...$ambos($composers, self::INSERT_AFTER_LINE, self::A_FORMA, $redactor),
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
     * Los parches del motor ({@see self::ENGINE}), en el orden de la tabla de
     * §2 del diseño de PT-17b (compilador 6): el estratega, como en el 5
     * (inserción tras `QUE PLAN SE COTIZA`, con el texto del 8, y sustitución
     * de «Presentalo…»);
     * el redactor (sustituciones de la frase de `suggested_question`, de
     * «Cualquier otro marcador…», de «Elige el plan…» y de «el precio solo del
     * que se cotiza.», y la inserción tras su última línea, que deja el texto
     * de la lista el último del prompt); el reintento (las mismas salvo
     * «Elige el plan…», que no está en su base); y cada revisor, dos
     * inserciones tras su última línea (la lista y la frase de lo que ya
     * comprueba el backend: quedan en orden inverso, esa frase y después
     * LISTA, la última). Cada ancla, una vez en su rol de la base; en un
     * revisor, el motor solo inserta. Desde el compilador 8, el texto de la
     * lista del redactor solo lleva `<L>`, que sale del tope gobernado
     * ({@see self::plansListChars()}: L = max(N, 320)); `<W>` ya no
     * ({@see self::plansListWords()} queda sin uso).
     *
     * @return list<array{0:string,1:string,2:string,3:string}> [rol, operación, ancla, texto]
     */
    private static function enginePatches(int $maxChars): array
    {
        $lista = str_replace('<L>', (string) self::plansListChars($maxChars), self::T_PLANS_COMPOSER);

        return [
            ['strategist', self::INSERT_AFTER_LINE, self::A_COTIZA, self::T_PLANS_STRATEGIST],
            ['strategist', self::REPLACE, self::A_PRESENTALO, self::T_PLANS_STRATEGIST_PRESENT],
            ['composer', self::REPLACE, self::A_SUGERIDA, self::T_PLANS_COMPOSER_ASK],
            ['composer', self::REPLACE, self::A_MARCADOR, self::T_PLANS_COMPOSER_MARKER],
            ['composer', self::REPLACE, self::A_ELIGE, self::T_PLANS_COMPOSER_CHOOSE],
            ['composer', self::REPLACE, self::A_PRECIO, self::T_PLANS_COMPOSER_PRICE],
            ['composer', self::INSERT_AFTER_LINE, self::A_FIN_COMPOSER, $lista],
            ['composer_retry', self::REPLACE, self::A_SUGERIDA, self::T_PLANS_COMPOSER_ASK],
            ['composer_retry', self::REPLACE, self::A_MARCADOR, self::T_PLANS_COMPOSER_MARKER],
            ['composer_retry', self::REPLACE, self::A_PRECIO, self::T_PLANS_COMPOSER_PRICE],
            ['composer_retry', self::INSERT_AFTER_LINE, self::A_FIN_COMPOSER, $lista],
            ['critic', self::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::T_PLANS_CRITIC],
            ['critic', self::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::T_PLANS_CRITIC_CHECKED],
            ['critic_retry', self::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::T_PLANS_CRITIC],
            ['critic_retry', self::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::T_PLANS_CRITIC_CHECKED],
        ];
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
