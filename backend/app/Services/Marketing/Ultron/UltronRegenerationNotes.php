<?php

namespace App\Services\Marketing\Ultron;

use InvalidArgumentException;

/**
 * LAS NOTAS DE REESCRITURA DEL SERVIDOR (cierre V2, apartado 4, «regenerar
 * antes de sustituir»; plan del arquitecto `plan-arquitecto-a4-regenerar.md`
 * §3 P-5, §4.1, §4.4 y §7.2).
 *
 * Cuando la comprobación del servidor encuentra en un borrador un defecto que
 * el modelo puede corregir reescribiendo, n8n le pide otra redacción con la
 * nota de ese defecto en `critic_notes` y su `issue` en `critic_issues`: los
 * mismos campos que ya usa la reescritura que pide el revisor
 * (`composer_retry.txt`: «Corrige EXACTAMENTE lo senalado en critic_issues y
 * critic_notes»). La llama `UltronDraftDefects`, desde la comprobación de solo
 * lectura `ai/check` (`UltronDraftCheck`).
 *
 * EL MECANISMO ES GENÉRICO: un código (o código/detalle) → su `issue`, del
 * vocabulario cerrado del revisor ({@see CriticContract::DIMENSIONS}), su
 * texto fijo y los parámetros que ese texto lleva, cada uno con su tipo.
 * Ampliarlo es una entrada más en {@see self::ENTRIES}, una nota aprobada y su
 * prueba.
 *
 * LOS TEXTOS SON LOS DE §7.2, LITERALES (N-R, N-L, N-P1 a N-P6 y N-B), sin
 * tildes como el resto de los prompts. Son textos fijos PENDIENTES DE LA
 * APROBACIÓN DEL PROPIETARIO (gate 4-H): no se retocan aquí.
 *
 * OLA 4b (decisión L-3 del lead: la condición 2 del propietario dice «o alguna
 * regla corregible»): las SEIS reglas de política que el modelo puede
 * corregir reescribiendo entran con el MISMO código que la violación del
 * commit ({@see CommercialTurnPolicy::violations()}), sin detalle ni
 * parámetros, y con su nota fija del encargo de la ola 4b (literal, también
 * pendiente del gate 4-H). NO entran: el saludo repetido (el commit lo arregla
 * recortando la fórmula), la contradicción con el compromiso de la visita (el
 * dato lo pone la fila del CRM), el panorama, la longitud, el registro y el
 * beneficio (ya tienen su código de arriba), el guard, el dinero, la
 * membresía, el no contactar, el relevo y las herramientas.
 *
 * CON LA LISTA Y EL TOPE (22-11-2026, corrección (1) del turno 2 de la tercera
 * prueba real): N-P1, N-P2 y N-L tienen una variante con el esqueleto de la
 * lista y su presupuesto ya expandido ({@see self::budgetNote()}); el código y
 * el `issue` no cambian.
 *
 * CON LA LISTA DEL SERVIDOR (PT-17, puerta 2, L1; `k` ≥ 8): los seis detalles
 * de la lista y la longitud de un borrador con la lista tienen otra variante
 * ({@see self::serverNote()}), que habla de la ranura LISTA_DE_PLANES y de lo
 * que ocupa la lista que compone Laravel; el código y el `issue`, los mismos.
 *
 * LOS PARÁMETROS NO LLEVAN TEXTO DE LA PERSONA: marcas de tuteo de la lista
 * cerrada de {@see UltronRegisterDetector::MARCAS}, números, y textos del
 * catálogo (el nombre de un plan o un beneficio) sin llaves, sin saltos de
 * línea y a 60 caracteres. Ninguna nota puede formar un marcador `{{…}}`.
 *
 * PURA: sin base de datos, sin config, sin reloj y sin log. Un código, un
 * detalle o unos parámetros que no son los de su entrada son un error de quien
 * llama: lanza `InvalidArgumentException`.
 */
final class UltronRegenerationNotes
{
    public const REGISTER_TUTEO = 'register_tuteo';

    public const LENGTH_OVER_CAP = 'length_over_cap';

    public const PLANS_OVERVIEW = 'plans_overview';

    public const BENEFIT_MISATTRIBUTED = 'benefit_misattributed';

    /** Ola 4b: las reglas de política corregibles, con el código de su violación (en este orden van en la respuesta). */
    public const POLICY_GREETING_SELLS = CommercialTurnPolicy::VIOLACION_SALUDO_COMERCIAL;

    public const POLICY_ACTIVE_GOAL_LOST = CommercialTurnPolicy::VIOLACION_OBJETIVO_PERDIDO;

    public const POLICY_WRONG_FIRST_PLAN = CommercialTurnPolicy::VIOLACION_PLAN_EQUIVOCADO;

    public const POLICY_ANSWER_WITHOUT_FACT = CommercialTurnPolicy::VIOLACION_SIN_HECHO;

    public const POLICY_ONLY_DISCOVERY = CommercialTurnPolicy::VIOLACION_SOLO_DESCUBRIMIENTO;

    public const POLICY_MEMORY_TURN_SELLS = CommercialTurnPolicy::VIOLACION_MEMORIA_VENDE;

    /** Ola 4b: las seis de la política, en el orden en que van detrás de los cuatro de §4.4. */
    public const POLICY_CODES = [
        self::POLICY_GREETING_SELLS, self::POLICY_ACTIVE_GOAL_LOST, self::POLICY_WRONG_FIRST_PLAN,
        self::POLICY_ANSWER_WITHOUT_FACT, self::POLICY_ONLY_DISCOVERY, self::POLICY_MEMORY_TURN_SELLS,
    ];

    /** Los códigos corregibles, cerrados (P-5): los cuatro de §4.4 y, detrás, los seis de la política (ola 4b). */
    public const CODES = [self::REGISTER_TUTEO, self::LENGTH_OVER_CAP, self::PLANS_OVERVIEW, self::BENEFIT_MISATTRIBUTED, ...self::POLICY_CODES];

    /** Los detalles de `plans_overview` (§4.4). */
    public const DETAILS = UltronPlansOverview::DETAILS;

    /** La mezcla cabe en lo que admite `critic.notes` (y en lo que guarda el contrato del revisor). */
    public const MAX_NOTES = CriticContract::MAX_NOTES;

    /** Un texto del catálogo dentro de una nota: como mucho estos caracteres. */
    public const MAX_TEXT_PARAM = 60;

    /** Los tipos de parámetro. */
    private const MARKS = 'marks';

    private const INT = 'int';

    private const TEXT = 'text';

    // ── §7.2, literales ─────────────────────────────────────────────────────

    private const N_R = 'REESCRIBE DE USTED: la version publicada trata a la persona de usted y el borrador tutea ({MARCAS}). Pasa todo el mensaje a usted sin mezclar (le, su, usted, cuenteme, quiere) y conserva lo que decia.';

    private const N_L = 'DEMASIADO LARGO: el borrador mide {L} caracteres y el limite publicado es {N}. Reescribelo en unos {M}: primero la respuesta a lo que pregunto la persona, sin relleno, y como mucho una pregunta corta al final. Cada marcador cuenta como el texto que lo sustituye.';

    private const N_P1 = 'FALTA LA LISTA DE PLANES: la persona pidio los planes o los precios en general. Nombra al menos dos planes de commercial_turn_policy.plans_overview, en su orden, escribiendo solo sus marcadores PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves; el backend pone el nombre y el precio. Sin PLAN_NAME ni PLAN_PRICE.';

    private const N_P2 = 'MARCADORES MEZCLADOS: con la lista de planes usa solo PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves, cada uno una sola vez, sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION.';

    private const N_P3 = 'NOMBRE DUPLICADO: no escribas el nombre de un plan de la lista; su marcador ya pone el nombre y el precio.';

    private const N_P4 = 'MARCADOR INEXISTENTE: este turno solo tiene PLAN_1 a PLAN_{K}. Usa solo esos, entre dobles llaves.';

    private const N_P5 = 'SIN LISTA DE PLANES: en este turno no uses PLAN_1, PLAN_2 ni PLAN_3. Para un precio usa PLAN_PRICE con el plan de la estrategia.';

    private const N_P6 = 'SIN CIFRAS: no escribas precios ni cifras de dinero; el backend los pone con los marcadores.';

    private const N_B = 'BENEFICIO DE OTRO PLAN: el borrador atribuye a {PLAN} un beneficio que en active_plans es de otro plan ("{BENEFICIO}"). A cada plan, solo los benefits de su plan_id.';

    // ── Ola 4b, literales del encargo (`encargo-be-r4.md`) ─────────────────

    private const N_PS_SALUDO = 'SALUDO QUE VENDE: la persona solo saludo. Saluda, da la bienvenida y pregunta en que le puede ayudar. No nombres planes, precios ni pagos y no preguntes todavia su objetivo.';

    private const N_PS_VISITA = 'VISITA A MEDIAS: hay una visita de cortesia a medio concretar y la persona esta dando sus datos. Retoma la visita pidiendo solo el dato que falta (el dia o la hora). No nombres planes ni precios en este turno.';

    /*
     * Correcciones tras las revisiones (C3; I-3 del revisor): remite a
     * `commercial_turn_policy.allowed_plan_ids`, que el redactor SÍ recibe
     * (`UltronDecideService::politicaDelTurno()`), y no a `forbidden_plan_ids`,
     * que no viaja. Texto literal del lead, pendiente del propietario.
     */
    private const N_PS_PLAN = 'PRIMER PLAN EQUIVOCADO: el primer plan que nombres tiene que ser el de commercial_turn_policy.required_plan_id, y nombra solo planes de commercial_turn_policy.allowed_plan_ids. Los demas permitidos, solo despues y si aportan.';

    private const N_PS_DATO = 'FALTA EL DATO: la persona pidio informacion del gimnasio y el borrador no da ningun dato de knowledge_base. Responde primero con el dato pedido, copiado tal cual de knowledge_base (direccion, horario o servicios), y deja como mucho una pregunta al final.';

    private const N_PS_PREGUNTAS = 'SOLO PREGUNTAS: la persona pidio informacion y el borrador solo pregunta. Responde primero con lo que pidio, con un dato concreto, y deja como mucho una pregunta al final.';

    private const N_PS_MEMORIA = 'PREGUNTA DE MEMORIA: la persona pregunto por algo ya acordado (su visita). Responde solo eso, con el dato del compromiso. No vendas ni preguntes su objetivo en este turno.';

    // ── Con la lista y el tope firmados: el esqueleto y el presupuesto ──────

    /*
     * LA CORRECCIÓN (1) DEL TURNO 2 DE LA TERCERA PRUEBA REAL (22-11-2026;
     * `informe-debugger-a4-turno2.md` §1 y §7). Con la lista ofrecida y el
     * tope firmado, los tres borradores incumplieron la lista y salió el
     * respaldo: las notas de arriba dicen qué marcadores usar, pero no cómo
     * caben ni cuánto ocupan una vez expandidos (el modelo nunca ve un
     * precio). Estas tres variantes llevan, en palabras (una nota no puede
     * escribir llaves), el ESQUELETO —una frase con los marcadores, cada uno
     * una vez y con «para quién es» en su mención; si no caben, dos; una
     * pregunta corta o ninguna— y el PRESUPUESTO ya expandido: lo que ocupan
     * los marcadores ofrecidos ({X}) y lo que queda para todo lo demás ({R})
     * de lo que se le pide ({M}). {K} es cuántos planes tiene la lista.
     *
     * SOLO con la lista Y el tope (las elige {@see UltronDraftDefects::of()}).
     * Sin tope no hay presupuesto que decir, y sin lista la N-L no tiene
     * marcadores que medir: entonces salen las de arriba, BYTE A BYTE (la N-L
     * sin lista es además la del fixture de n8n S2, `check-reescribe.json`).
     *
     * CABEN JUNTAS: la nota de la lista y la de la longitud, con las cifras
     * más largas posibles, en {@see self::MAX_NOTES} (N-P1 + N-L, 498; N-P2 +
     * N-L, 469); y ninguna combinación pierde una nota de más prioridad que
     * hoy entraba. Lo fija `UltronRegenerationBudgetNotesTest`. Sin tildes,
     * como el resto. Textos fijos PENDIENTES DE LA APROBACIÓN DEL PROPIETARIO.
     */
    private const N_P1_PRESUPUESTO = 'FALTA LA LISTA DE PLANES: una frase con PLAN_1 a PLAN_{K} entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME ni PLAN_PRICE. Ya expandidos ocupan {X} caracteres: quedan {R} de {M}. Si no caben, dos; una pregunta corta o ninguna.';

    private const N_P2_PRESUPUESTO = 'MARCADOR REPETIDO O MEZCLADO: una frase con PLAN_1 a PLAN_{K} entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION. Ya expandidos ocupan {X} caracteres: quedan {R} de {M}.';

    private const N_L_PRESUPUESTO = 'DEMASIADO LARGO: mide {L} caracteres y el limite publicado es {N}. Reescribelo en unos {M}: primero la respuesta, sin relleno. Los marcadores de la lista ya ocupan {X}: quedan {R}. Si no caben, dos planes; una pregunta corta o ninguna.';

    // ── Con la lista del servidor (PT-17, puerta 2, L1; compilador 8) ──────

    /*
     * LAS NOTAS CUANDO LA LISTA LA COMPONE LARAVEL (`k` ≥ 8,
     * {@see UltronPlansOverview::serverList()}): las mismas claves y el mismo
     * `issue` que las de arriba, pero hablan de la ranura LISTA_DE_PLANES (en
     * palabras: una nota no puede escribir llaves) y no de los marcadores
     * PLAN_1 a PLAN_3, que el modelo ya no ve. {N} es el tope de la lista del
     * turno ({@see UltronReplyFitter::plansListCap()}); {X}, lo que mide la
     * lista compuesta ({@see UltronPlansOverview::compose()}); {R} = max(0,
     * N − X); {L}, la medida del mensaje con la lista. Textos aprobados por el
     * propietario el 24-11-2026 (H0), copiados byte a byte de la
     * especificación de la puerta 2 (§1.4). La peor mezcla (falta la lista +
     * longitud) cabe en {@see self::MAX_NOTES}: lo fija
     * `UltronRegenerationBudgetNotesTest`.
     */
    private const N_S_P1 = 'FALTA LA LISTA DE PLANES: pon LISTA_DE_PLANES entre dobles llaves una sola vez, donde va la lista; el backend escribe cada plan con su nombre y su precio. Sin nombres de plan, cifras ni marcadores PLAN_. La lista ocupa {X} de {N} caracteres: tu texto, como mucho {R}.';

    private const N_S_P2 = 'MARCADORES MEZCLADOS: usa solo LISTA_DE_PLANES entre dobles llaves, una sola vez; sin marcadores que empiecen por PLAN_. El backend pone la lista entera, en su orden. La lista ocupa {X} de {N} caracteres: tu texto, como mucho {R}.';

    private const N_S_P3 = 'NOMBRE DUPLICADO: no escribas el nombre de ningun plan de la lista; el backend ya lo pone, con su precio, en la lista. Si hablas de uno, nombralo por su lugar (el primero, el segundo, el tercero).';

    private const N_S_P4 = 'MARCADOR INEXISTENTE: en este turno la lista se pide con LISTA_DE_PLANES entre dobles llaves, en mayusculas; no uses otros marcadores de plan.';

    private const N_S_P5 = 'SIN LISTA DE PLANES: en este turno no uses LISTA_DE_PLANES ni marcadores PLAN_ con numero. Para un precio usa PLAN_PRICE con el plan de la estrategia.';

    private const N_S_P6 = 'SIN CIFRAS: no escribas precios ni cifras de dinero; el backend pone los precios en la lista.';

    private const N_S_L = 'DEMASIADO LARGO: con la lista mide {L} caracteres y el limite de este turno es {N}. La lista ya ocupa {X}: tu texto, como mucho {R}. Primero la respuesta; una pregunta corta o ninguna. No quites planes: la lista va entera.';

    /** Las notas con la lista del servidor: `código` o `código/detalle` → [texto, [parámetro => tipo]], en el orden de §1.4. El `issue` es el de su entrada en {@see self::ENTRIES}. */
    private const DEL_SERVIDOR = [
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::MISSING => [self::N_S_P1, ['X' => self::INT, 'N' => self::INT, 'R' => self::INT]],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::MIXED => [self::N_S_P2, ['X' => self::INT, 'N' => self::INT, 'R' => self::INT]],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::NAME_WRITTEN => [self::N_S_P3, []],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::UNKNOWN_MARKER => [self::N_S_P4, []],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::NO_LIST => [self::N_S_P5, []],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::PRICE_WRITTEN => [self::N_S_P6, []],
        self::LENGTH_OVER_CAP => [self::N_S_L, ['L' => self::INT, 'N' => self::INT, 'X' => self::INT, 'R' => self::INT]],
    ];

    /** Las tres variantes con el presupuesto de la lista: `código` o `código/detalle` → [texto, [parámetro => tipo]]. El `issue` es el de su entrada en {@see self::ENTRIES}. */
    private const CON_PRESUPUESTO = [
        self::LENGTH_OVER_CAP => [self::N_L_PRESUPUESTO, ['L' => self::INT, 'N' => self::INT, 'M' => self::INT, 'X' => self::INT, 'R' => self::INT]],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::MISSING => [self::N_P1_PRESUPUESTO, ['K' => self::INT, 'X' => self::INT, 'R' => self::INT, 'M' => self::INT]],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::MIXED => [self::N_P2_PRESUPUESTO, ['K' => self::INT, 'X' => self::INT, 'R' => self::INT, 'M' => self::INT]],
    ];

    /**
     * La tabla: `código` o `código/detalle` → [issue, texto, [parámetro => tipo]].
     * `M` de N-L es `UltronPromptCompiler::composerTargetChars(N)`: lo calcula
     * quien llama (el mismo margen que pide el texto del tope al redactor).
     */
    private const ENTRIES = [
        self::REGISTER_TUTEO => ['naturalness', self::N_R, ['MARCAS' => self::MARKS]],
        self::LENGTH_OVER_CAP => ['progressive_disclosure', self::N_L, ['L' => self::INT, 'N' => self::INT, 'M' => self::INT]],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::MISSING => ['relevance', self::N_P1, []],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::MIXED => ['relevance', self::N_P2, []],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::NAME_WRITTEN => ['relevance', self::N_P3, []],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::UNKNOWN_MARKER => ['relevance', self::N_P4, ['K' => self::INT]],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::NO_LIST => ['relevance', self::N_P5, []],
        self::PLANS_OVERVIEW.'/'.UltronPlansOverview::PRICE_WRITTEN => ['factuality', self::N_P6, []],
        self::BENEFIT_MISATTRIBUTED => ['factuality', self::N_B, ['PLAN' => self::TEXT, 'BENEFICIO' => self::TEXT]],
        /*
         * Ola 4b. El `issue`, del vocabulario del revisor y con su definición
         * en `critic.txt`: el saludo y la memoria son `customer_fit` (ahí
         * define el revisor el modo recepción y el modo memoria, :18); la
         * visita a medias y el plan obligatorio, `phase_alignment` («coherente
         * con la fase y el objetivo de la estrategia», :15); el dato y las
         * preguntas, `relevance` («responde lo que la persona pregunto», :6).
         */
        self::POLICY_GREETING_SELLS => ['customer_fit', self::N_PS_SALUDO, []],
        self::POLICY_ACTIVE_GOAL_LOST => ['phase_alignment', self::N_PS_VISITA, []],
        self::POLICY_WRONG_FIRST_PLAN => ['phase_alignment', self::N_PS_PLAN, []],
        self::POLICY_ANSWER_WITHOUT_FACT => ['relevance', self::N_PS_DATO, []],
        self::POLICY_ONLY_DISCOVERY => ['relevance', self::N_PS_PREGUNTAS, []],
        self::POLICY_MEMORY_TURN_SELLS => ['customer_fit', self::N_PS_MEMORIA, []],
    ];

    /** Los códigos que llevan detalle (los demás, ninguno). */
    private const CON_DETALLE = [self::PLANS_OVERVIEW];

    /**
     * El `issue` del revisor para un código (y su detalle, si lo lleva).
     *
     * @throws InvalidArgumentException código o detalle desconocidos
     */
    public static function issue(string $code, ?string $detail = null): string
    {
        return self::entrada($code, $detail)[0];
    }

    /**
     * La nota de un código (y su detalle), con sus parámetros: exactamente los
     * que declara su entrada.
     *
     * - `marks`: lista de marcas de tuteo; solo entran las de la lista cerrada
     *   del detector, sin repetir, como mucho {@see UltronRegisterDetector::MAX_MARCAS},
     *   separadas por «, ». Si no queda ninguna, lanza.
     * - `int`: un entero no negativo.
     * - `text`: un texto del catálogo; sin llaves, sin saltos de línea ni
     *   espacios dobles, las comillas dobles por simples (no cierran la cita
     *   de la nota) y a {@see self::MAX_TEXT_PARAM} caracteres. Si queda
     *   vacío, lanza.
     *
     * @param  array<string,mixed>  $params
     *
     * @throws InvalidArgumentException
     */
    public static function note(string $code, ?string $detail = null, array $params = []): string
    {
        [, $plantilla, $tipos] = self::entrada($code, $detail);

        return self::rellena($code, $plantilla, $tipos, $params);
    }

    /**
     * La variante CON EL PRESUPUESTO DE LA LISTA de una nota (las tres de
     * {@see self::CON_PRESUPUESTO}: N-P1, N-P2 y N-L), con sus parámetros
     * exactos y los mismos tipos que {@see self::note()}. Quien llama la pide
     * solo con la lista y el tope firmados ({@see UltronDraftDefects::of()}).
     *
     * @param  array<string,mixed>  $params
     *
     * @throws InvalidArgumentException código o detalle desconocidos, una nota sin esa variante o parámetros que no son los suyos
     */
    public static function budgetNote(string $code, ?string $detail, array $params): string
    {
        self::entrada($code, $detail);
        $clave = $detail === null ? $code : $code.'/'.$detail;
        if (! isset(self::CON_PRESUPUESTO[$clave])) {
            throw new InvalidArgumentException("La nota de {$code} no tiene variante con el presupuesto de la lista.");
        }
        [$plantilla, $tipos] = self::CON_PRESUPUESTO[$clave];

        return self::rellena($code, $plantilla, $tipos, $params);
    }

    /**
     * La nota CON LA LISTA DEL SERVIDOR (`k` ≥ 8; PT-17, puerta 2, L1) de un
     * código y su detalle ({@see self::DEL_SERVIDOR}): los seis de la lista y
     * la de la longitud de un borrador con la lista, con sus parámetros
     * exactos y los mismos tipos que {@see self::note()}. Mismo código y mismo
     * `issue` que la nota de siempre. Quien llama la pide solo con
     * {@see UltronPlansOverview::serverList()} ({@see UltronDraftDefects::of()}).
     *
     * @param  array<string,mixed>  $params
     *
     * @throws InvalidArgumentException código o detalle desconocidos, una nota sin esa variante o parámetros que no son los suyos
     */
    public static function serverNote(string $code, ?string $detail, array $params = []): string
    {
        self::entrada($code, $detail);
        $clave = $detail === null ? $code : $code.'/'.$detail;
        if (! isset(self::DEL_SERVIDOR[$clave])) {
            throw new InvalidArgumentException("La nota de {$code} no tiene variante con la lista del servidor.");
        }
        [$plantilla, $tipos] = self::DEL_SERVIDOR[$clave];

        return self::rellena($code, $plantilla, $tipos, $params);
    }

    /**
     * Una plantilla con sus parámetros: exactamente los que declara, cada uno
     * con su tipo ({@see self::note()}).
     *
     * @param  array<string,string>  $tipos
     * @param  array<string,mixed>  $params
     *
     * @throws InvalidArgumentException
     */
    private static function rellena(string $code, string $plantilla, array $tipos, array $params): string
    {
        $faltan = array_diff_key($tipos, $params);
        $sobran = array_diff_key($params, $tipos);
        if ($faltan !== [] || $sobran !== []) {
            throw new InvalidArgumentException("La nota de {$code} no lleva esos parámetros.");
        }

        $sustituciones = [];
        foreach ($tipos as $nombre => $tipo) {
            $sustituciones['{'.$nombre.'}'] = match ($tipo) {
                self::MARKS => self::marcas($params[$nombre]),
                self::INT => self::entero($params[$nombre]),
                self::TEXT => self::texto($params[$nombre]),
            };
        }

        return strtr($plantilla, $sustituciones);
    }

    /**
     * Las notas en orden, unidas por un espacio, hasta {@see self::MAX_NOTES}
     * caracteres POR NOTAS ENTERAS: una que no cabe se deja fuera entera y se
     * prueba la siguiente (las notas vienen por orden de prioridad). Las
     * vacías no cuentan.
     *
     * @param  list<string>  $notes
     */
    public static function merge(array $notes): string
    {
        $out = '';
        foreach ($notes as $nota) {
            $nota = trim($nota);
            if ($nota === '') {
                continue;
            }
            $con = $out === '' ? $nota : $out.' '.$nota;
            if (mb_strlen($con) <= self::MAX_NOTES) {
                $out = $con;
            }
        }

        return $out;
    }

    /**
     * Las entradas de la tabla, para recorrerlas: [código, detalle, nombres de
     * sus parámetros, texto con sus huecos].
     *
     * @return list<array{0:string,1:?string,2:list<string>,3:string}>
     */
    public static function entries(): array
    {
        $out = [];
        foreach (self::ENTRIES as $clave => [, $plantilla, $tipos]) {
            [$code, $detail] = array_pad(explode('/', $clave, 2), 2, null);
            $out[] = [$code, $detail, array_keys($tipos), $plantilla];
        }

        return $out;
    }

    /**
     * Las variantes con el presupuesto de la lista ({@see self::CON_PRESUPUESTO}),
     * con la misma forma que {@see self::entries()}.
     *
     * @return list<array{0:string,1:?string,2:list<string>,3:string}>
     */
    public static function budgetEntries(): array
    {
        $out = [];
        foreach (self::CON_PRESUPUESTO as $clave => [$plantilla, $tipos]) {
            [$code, $detail] = array_pad(explode('/', $clave, 2), 2, null);
            $out[] = [$code, $detail, array_keys($tipos), $plantilla];
        }

        return $out;
    }

    /**
     * Las notas con la lista del servidor ({@see self::DEL_SERVIDOR}), con la
     * misma forma que {@see self::entries()} y en el orden de §1.4.
     *
     * @return list<array{0:string,1:?string,2:list<string>,3:string}>
     */
    public static function serverEntries(): array
    {
        $out = [];
        foreach (self::DEL_SERVIDOR as $clave => [$plantilla, $tipos]) {
            [$code, $detail] = array_pad(explode('/', $clave, 2), 2, null);
            $out[] = [$code, $detail, array_keys($tipos), $plantilla];
        }

        return $out;
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:array<string,string>} */
    private static function entrada(string $code, ?string $detail): array
    {
        if (! in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException('Código de reescritura desconocido.');
        }
        $conDetalle = in_array($code, self::CON_DETALLE, true);
        if ($conDetalle !== ($detail !== null)) {
            throw new InvalidArgumentException("El código {$code} ".($conDetalle ? 'lleva' : 'no lleva').' detalle.');
        }
        $clave = $detail === null ? $code : $code.'/'.$detail;
        if (! isset(self::ENTRIES[$clave])) {
            throw new InvalidArgumentException("Detalle desconocido para {$code}.");
        }

        return self::ENTRIES[$clave];
    }

    private static function marcas(mixed $valor): string
    {
        $marcas = [];
        foreach (is_array($valor) ? $valor : [] as $m) {
            if (is_string($m) && in_array($m, UltronRegisterDetector::MARCAS, true) && ! in_array($m, $marcas, true)) {
                $marcas[] = $m;
            }
        }
        if ($marcas === []) {
            throw new InvalidArgumentException('Las marcas de tuteo no son de la lista cerrada.');
        }

        return implode(', ', array_slice($marcas, 0, UltronRegisterDetector::MAX_MARCAS));
    }

    private static function entero(mixed $valor): string
    {
        if (! is_int($valor) || $valor < 0) {
            throw new InvalidArgumentException('Un parámetro numérico de la nota no es un entero no negativo.');
        }

        return (string) $valor;
    }

    /**
     * Un texto del catálogo tal como entra en una nota (el tipo `text`): sin
     * llaves, sin saltos de línea ni espacios dobles, las comillas dobles por
     * simples y a {@see self::MAX_TEXT_PARAM} caracteres. Vacío si no queda
     * nada (o si no es texto): quien llama lo mira antes de pedir la nota, que
     * con un texto vacío lanza (correcciones, C6; R-8 de seguridad).
     */
    public static function cleanText(mixed $valor): string
    {
        if (! is_string($valor)) {
            return '';
        }
        $limpio = str_replace(['{', '}', '"'], ['', '', "'"], $valor);
        $limpio = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $limpio));

        return rtrim(mb_substr($limpio, 0, self::MAX_TEXT_PARAM));
    }

    private static function texto(mixed $valor): string
    {
        if (! is_string($valor)) {
            throw new InvalidArgumentException('Un parámetro de texto de la nota no es texto.');
        }
        $limpio = self::cleanText($valor);
        if ($limpio === '') {
            throw new InvalidArgumentException('Un parámetro de texto de la nota queda vacío.');
        }

        return $limpio;
    }
}
