<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use App\Services\Marketing\Ultron\ConversationMemory;
use App\Services\Marketing\Ultron\Prompts\UltronPromptBase as Base;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as C;
use App\Services\Marketing\Ultron\Prompts\UltronPromptException;
use App\Services\Marketing\Ultron\StrategyContract;
use App\Services\UltronStudio\StudioLabEngineFlow;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * PT-17 · PUERTA 2 · L5 · EL COMPILADOR 8: LA LISTA LA ESCRIBE EL BACKEND
 * (especificación del arquitecto del 24-11-2026, §2 y §4; plan de motores
 * §B.3, «Compilador 8»). Los textos son los que aprobó el propietario el
 * 24-11-2026 (paso H0), escritos aquí tal cual.
 *
 * Sustituye a `UltronPromptCompiler7Test`, que ya no aplica: lo firmado con
 * otro compilador va por Legacy en `decide` (`compiler_changed`), así que
 * ningún paquete se compila ya con los textos del 7. Lo que el 7 fijaba y
 * sigue valiendo (las anclas, las quince entradas y su orden, la línea base,
 * la estrategia coherente con la lista, la libertad de redacción) se fija
 * aquí sobre el 8; N9 comprueba que del 7 al 8 solo cambian los siete textos
 * y el sufijo.
 *
 * El 8 cambia siete textos del parche del motor: el del estratega; en los
 * redactores, el marcador, «Elige el plan…», el precio y la guía del final,
 * que pide la ranura LISTA_DE_PLANES entre dobles llaves y solo lleva L (se
 * van W y «unos 25»); en los revisores, el bloque de la lista (juzga solo la
 * prosa) y la frase de lo que comprueba el backend, con el veredicto de P-1 A.
 * Y uno gobernado: el tope del redactor suma un sufijo (en un turno de lista
 * no aplica). Las anclas, las operaciones, el orden, «Presentalo…», la frase
 * de `suggested_question`, la base y la huella Legacy no cambian.
 *
 * Lo que se fija:
 *  N1.  `compile(v5)` (`profesional · 200`, el contenido de la v4): 25
 *       parches en el orden de la tabla; la última línea de los dos
 *       redactores es la guía con L = 320; tras `FORMA:`, el tope con el
 *       sufijo; las líneas 5 y 38, sustituidas; las dos últimas de los
 *       revisores, CHECKED y LISTA; HARD_FAIL y FALLA TAMBIEN, byte a byte; la
 *       huella, la de Node y distinta de la del 7 (`200019e4…`).
 *  N2.  Una frase con un ancla no vale (las anclas no cambian).
 *  N3.  En toda la rejilla: cada ancla, una vez al aplicarse; ningún texto
 *       contiene un ancla; el motor usa en cada rol sus anclas; sus textos,
 *       los últimos; la guía con la L de su tope; el sufijo, solo con el tope.
 *  N4.  Los textos al byte (las medidas de §2.1 de la especificación); la
 *       guía, una sola cifra, L; el estratega, solo 160; ningún texto del
 *       redactor anuncia `PLAN_` seguido de una cifra.
 *  N5.  Ninguna frase modelo ni pregunta de cierre fija en el redactor.
 *  N6.  La línea base y la huella Legacy no se mueven.
 *  N7.  El laboratorio sigue al compilador 8 (`motor-S2-c8-…`).
 *  N8.  PENDIENTE DE L1: que L sea `UltronReplyFitter::plansListCap()` para
 *       un borrador de forma B (la ranura) y uno de forma A (`PLAN_k`) se fija
 *       cuando se integren `UltronPlansOverview::compose()` y la condición de
 *       la ranura en `plansListCap()`. Aquí solo L = max(N, 320) (N3 y N4).
 *  N9.  Devolviendo los siete textos y el sufijo a los del 7, los 306 casos
 *       dan la huella de la rejilla del 7, fijada antes del cambio; sin
 *       devolverlos, la del 8 que da Node.
 *  N10. La estrategia es coherente con los contratos de hoy y el
 *       `response_goal` va sin cifras, nombres de plan ni marcadores.
 *  N11. Ninguna frase fija ni respuesta predefinida en los textos del motor.
 *  N12. La ranura: el literal LISTA_DE_PLANES en la guía (la constante
 *       `UltronPlansOverview::SLOT` llega con L1).
 *  N13. La recomendación va después de la lista, y los planes se nombran por
 *       su lugar.
 *  N14. P-1 A: la frase exacta del veredicto del revisor.
 */
class UltronPromptCompiler8Test extends TestCase
{
    // ── Las anclas (las quince del 7, sin cambios) ──────────────────────────

    private const A_SUGERIDA = 'Si vas a preguntar, usa suggested_question cuando venga:';

    private const A_FIN_COMPOSER = 'price_verification true significa';

    private const A_FIN_CRITIC = 'Si el borrador explica POR QUE la persona vio otro precio';

    private const A_COTIZA = 'QUE PLAN SE COTIZA';

    private const A_PRESENTALO = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos.';

    private const A_MARCADOR = 'Cualquier otro marcador hace que el mensaje se rechace.';

    private const A_ELIGE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues.';

    private const A_PRECIO = 'el precio solo del que se cotiza.';

    /** Las quince: las siete de siempre, las cinco del 5 y las tres del 6. */
    private const ANCLAS = [
        'COMO SUENAS:', 'FORMA:', 'Emoji opcional y raro, nunca en temas sensibles.', 'naturalness:', 'progressive_disclosure:', 'mas de ~900 caracteres', 'PRINCIPIO:',
        self::A_COTIZA, self::A_PRESENTALO, self::A_MARCADOR, self::A_ELIGE, self::A_PRECIO,
        self::A_SUGERIDA, self::A_FIN_COMPOSER, self::A_FIN_CRITIC,
    ];

    // ── Los textos del compilador 8 (§2.2 de la especificación, tal cual; ASCII, sin llaves, una línea) ──

    /** 1 · strategist, insert_after_line tras {@see self::A_COTIZA} (sustituye al del 7). */
    private const PLANS_STRATEGIST = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend decidio responder con esa lista, que escribe el backend con el nombre y el precio de cada plan, en su orden. En ese turno esto manda sobre las reglas de este apartado, la lista de next_best_action, resolved_reference y strategy_hints (suggested_question, should_recommend_now): el turno RESPONDE con la lista entera; no descubre; una recomendacion no sustituye la lista: solo si se pidio, va tras ella. Entonces next_best_action answer (de allowed_next_actions), conversation_goal recommend, recommendation_goal compare_plans, information_needed [] y recommended_plan_id el que digan las reglas de abajo (nunca null), solo como referencia del contrato: se destaca solo si se pidio. response_goal con tus palabras, sin cifras ni nombres de plan y en menos de 160 caracteres: que va la lista de planes del backend, lo demas que pregunto en ese mensaje, nombrandolo, y, si cabe, una pregunta breve para elegir. Si plans_overview no viene, nada de esto aplica.';

    /** 4 y 9 · composer y composer_retry, replace de {@see self::A_MARCADOR} (sustituye al del 5). */
    private const COMPOSER_MARCADOR = 'Cualquier otro marcador hace que el mensaje se rechace, salvo LISTA_DE_PLANES entre dobles llaves cuando commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND).';

    /** 5 · composer, replace de {@see self::A_ELIGE} (sustituye al del 5). */
    private const COMPOSER_ELIGE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues; si commercial_turn_policy.plans_overview trae planes, en vez de eso el backend pone esa lista (LISTA DE PLANES DEL BACKEND).';

    /** 6 y 10 · composer y composer_retry, replace de {@see self::A_PRECIO} (sustituye al del 6). */
    private const PLANS_COMPOSER_PRICE = 'el precio solo del que se cotiza o, si commercial_turn_policy.plans_overview trae planes, ni nombres ni precios a mano: la lista con nombres y precios la pone el backend (LISTA DE PLANES DEL BACKEND).';

    /** 7 y 11 · composer y composer_retry, insert_after_line tras {@see self::A_FIN_COMPOSER} (sustituye a la guía del 6). `{L}` sale del tope ({@see self::lista()}). */
    private const PLANS_COMPOSER = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios y el backend ya eligio cuales mostrar y escribe la lista: cada plan de plans_overview.plans, en su orden, con su nombre y su precio oficiales. Tu escribes el resto con tus palabras y pones LISTA_DE_PLANES entre dobles llaves, una sola vez, donde va la lista; si no la pones, el backend la pone antes de tu pregunta final. No escribas nombres de plan, cifras ni marcadores que empiecen por PLAN_. Puedes hablar de los planes sin nombrarlos o por su lugar en la lista (el primero, el segundo, el tercero), con sus benefits. Si pregunto ademas otra cosa, respondela tambien; si pidio una recomendacion, va despues de la lista. Puedes abrir con una frase corta y cerrar con UNA pregunta breve que ayude a elegir o a avanzar; suggested_question no es obligatoria aqui. LONGITUD DE ESTE TURNO: el mensaje completo, ya con la lista, no pasa de {L} caracteres. En este turno esto manda sobre lo anterior que choque (orden del mensaje, recomendar un solo plan, preguntar antes de informar, los marcadores y el tope general). Si plans_overview no viene, nada de esto aplica.';

    /** 12 y 14 · critic y critic_retry, insert_after_line tras {@see self::A_FIN_CRITIC} (sustituye al del 6). */
    private const PLANS_CRITIC = 'LISTA DE PLANES DEL BACKEND (va al final porque manda sobre todo lo anterior): si reply_draft lleva LISTA_DE_PLANES, o PLAN_1, PLAN_2 o PLAN_3, entre dobles llaves, o la persona pidio los planes o los precios en general y el borrador no nombra planes ni precios, el backend pone ahi, o antes de la pregunta final, la lista con el nombre y el precio oficiales de cada plan elegido, en su orden. Esa lista no la juzgas: juzga solo la prosa. Cuenta como el precio de cada plan: no es invented_plan ni invented_price, no esquiva el precio y no es plantilla, folleto ni exceso de planes. En ese borrador NO son defecto: no preguntar antes objetivo, experiencia u horario, aunque la fase sea NEW_LEAD o DISCOVERY o la estrategia traiga recommended_plan_id, ni cerrar con una pregunta corta para elegir o sin pregunta. Un nombre de plan o una cifra escritos a mano los rechaza el backend.';

    /** 13 y 15 · critic y critic_retry, insert_after_line tras {@see self::A_FIN_CRITIC} (sustituye al del 6; queda encima de {@see self::PLANS_CRITIC}). */
    private const PLANS_CRITIC_CHECKED = 'VEREDICTO EN LA LISTA DE PLANES: si reply_draft entra en LISTA DE PLANES DEL BACKEND (abajo), el registro (tu/usted), la longitud y la lista ya los comprueba el backend de forma determinista: no falles, no bajes naturalness ni los menciones en notes por ellos. En ese borrador es fail con hard_fail solo un incumplimiento real de seguridad, exactitud o politica de HARD_FAIL o de FALLA TAMBIEN (un plan, clase, servicio, beneficio u horario que no esta en active_plans, gym o knowledge_base es invented_class), y fail sin hard_fail solo si deja sin responder una pregunta directa del mensaje. Lo demas es verdict pass, con la mejora en notes.';

    /** El sufijo del tope gobernado del redactor, tras «…ofrece ampliar.» (empieza por un espacio). */
    private const SUFIJO_TOPE = ' Si commercial_turn_policy.plans_overview trae planes, este tope no aplica a ese mensaje: manda LONGITUD DE ESTE TURNO, al final del prompt.';

    // ── Los que siguen igual ────────────────────────────────────────────────

    /** 2 · strategist, replace de {@see self::A_PRESENTALO} (el del 5). */
    private const STRATEGIST_PRESENTALO = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos; si commercial_turn_policy.plans_overview trae planes, no: manda LISTA DE PLANES DEL BACKEND.';

    /** 3 y 8 · composer y composer_retry, replace de {@see self::A_SUGERIDA} (el del 6). */
    private const PLANS_COMPOSER_ASK = 'Si vas a preguntar, usa suggested_question cuando venga, nunca si commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND):';

    /** El tope del redactor del compilador 3 (§B.3), con `{N}`, `{M}`, `{P}` y `{F}` ({@see self::tope()}). */
    private const MAX_COMPOSER_C3 = 'TOPE DE LONGITUD PUBLICADO: el limite es {N} caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos {M} caracteres, que son unas {P} palabras: como mucho {F} frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar.';

    private const MAX_CRITIC_200 = 'TOPE PUBLICADO: un borrador de mas de ~200 caracteres falla por progressive_disclosure aunque se haya pedido detalle.';

    // ── Los del compilador 7 que el 8 sustituye: N9 los devuelve; con el 8 no van en ningún rol ──

    private const PLANS_STRATEGIST_C7 = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend decidio responder con esa lista. En ese turno esto manda sobre las reglas de este apartado, la lista de next_best_action, resolved_reference y strategy_hints (suggested_question, should_recommend_now): el turno RESPONDE con la lista entera; no descubre; una recomendacion no sustituye la lista: solo si se pidio, va tras ella. PLAN_k es la posicion k de plans_overview.plans, no el plan de id k. Entonces next_best_action answer (de allowed_next_actions), conversation_goal recommend, recommendation_goal compare_plans, information_needed [] y recommended_plan_id el que digan las reglas de abajo (nunca null), solo como referencia del contrato: se destaca solo si se pidio. response_goal con tus palabras, sin cifras y en menos de 160 caracteres: se presentan los planes de plans_overview, cada uno con su marcador y un beneficio tal como esta en sus benefits, sin cambiarle el sentido; se contesta tambien lo demas que pregunto en ese mensaje, nombrandolo; y cabe una pregunta breve para elegir. Si plans_overview no viene, nada de esto aplica.';

    private const COMPOSER_MARCADOR_C7 = 'Cualquier otro marcador hace que el mensaje se rechace, salvo PLAN_1 a PLAN_3 cuando commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND).';

    private const COMPOSER_ELIGE_C7 = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues; si commercial_turn_policy.plans_overview trae planes, en vez de eso das esa lista (LISTA DE PLANES DEL BACKEND).';

    private const PLANS_COMPOSER_PRICE_C7 = 'el precio solo del que se cotiza o, si commercial_turn_policy.plans_overview trae planes, ni nombres ni precios a mano: los pone el marcador de cada plan (LISTA DE PLANES DEL BACKEND).';

    /** La guía del 6 y el 7, con `{L}` y `{W}` ({@see self::listaDelSiete()}). */
    private const PLANS_COMPOSER_C7 = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios y el backend ya eligio cuales mostrar. Presentalos con tus palabras, en una o dos frases naturales: cada plan con su marcador PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves (el de su posicion en plans_overview.plans, una sola vez) y un beneficio breve tomado de sus benefits. El backend cambia cada marcador por el nombre y el precio oficiales: no escribas nombres de plan, ni cifras, ni PLAN_NAME ni PLAN_PRICE. Si la persona pregunto ademas otra cosa, respondela tambien en este mensaje. Puedes abrir con una frase corta y cerrar con UNA pregunta breve que ayude a elegir o a avanzar; suggested_question no es obligatoria aqui. LONGITUD DE ESTE TURNO: el mensaje completo, ya con nombres y precios, no pasa de {L} caracteres; cada marcador ocupara unos 25, asi que escribe como mucho unas {W} palabras tuyas. En este turno esto manda sobre lo anterior que choque (orden del mensaje, recomendar un solo plan, preguntar antes de informar y el tope general). Si plans_overview no viene, nada de esto aplica.';

    private const PLANS_CRITIC_C7 = 'LISTA DE PLANES DEL BACKEND (va al final porque manda sobre todo lo anterior): si reply_draft lleva PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves, la persona pidio los planes o los precios y el backend eligio esos planes; cada marcador pone el nombre y el precio reales. En ese borrador NO son defecto: dar dos o tres planes en una frase con un beneficio corto de cada uno, que es la forma pedida y no plantilla ni folleto (naturalness, progressive_disclosure); darlos sin preguntar antes objetivo, experiencia u horario, aunque la fase sea NEW_LEAD o DISCOVERY o la estrategia traiga recommended_plan_id (sales_judgment, phase_alignment, TECNICA, ni customer_fit por la fase); cerrar con una pregunta corta para elegir, con una sola pregunta aunque lleve una o, o sin pregunta (next_step, question_discipline); su longitud, que el backend mide con los marcadores ya sustituidos. No es invented_plan ni invented_price ni esquiva el precio. Todo lo demas falla como siempre: un nombre o una cifra escritos a mano, un beneficio que no esta en active_plans, mas de una pregunta, y cada regla de FALLA TAMBIEN y de HARD_FAIL.';

    private const PLANS_CRITIC_CHECKED_C7 = 'REGISTRO Y LONGITUD EN LA LISTA DE PLANES: si reply_draft lleva PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves, el registro (tu/usted) y la longitud ya los comprueba el backend de forma determinista: no falles, no bajes naturalness ni los menciones en notes por ellos.';

    /** El arranque de la plantilla del diseño del 6, que el propietario descartó: no va en ningún rol. */
    private const PLANTILLA_DEL_DISENO = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios y el backend ya eligio cuales mostrar. Entonces reply_draft es SOLO esta frase, en este orden: Claro, tenemos PLAN_1, B1; PLAN_2, B2; y PLAN_3, B3. Q.';

    // ── Huellas ─────────────────────────────────────────────────────────────

    /**
     * La de la v5 (profesional + 200) con el compilador 8 y sus bytes: la del
     * generador de Node (`derivar-compile-cases.cjs`, c074 de
     * `compile-cases.json`), que transcribe los textos de §B.3 sin leer el
     * compilador.
     */
    private const V5_C8_HASH = '8200e50e3da7f6fe7cfb0e21d1d93705d8eb824fb5e1cb7887ee3c2e557d3caa';

    private const V5_C8_BYTES = 123254;

    /** La de ese contenido con el compilador 7 (`UltronPromptCompiler7Test`): con el 8 ya no la da. */
    private const V4_C7_HASH = '200019e4a89013b9502007622c6d62965bb3178e1359efadb7d79c6193113631';

    /** La de la v4 SOLO con lo gobernado del compilador 4 (sin el motor y sin el sufijo del 8, `7b09b89c…cc35`). */
    private const V4_GOBERNADO_HASH = '7b09b89c98d48b01561b9cbc07e048c152c0af6a4ba7f583becd49f8e7e8cc35';

    /**
     * La huella de la rejilla de los 306 casos con el compilador 7: sha256 de
     * las líneas «id:bundleHash» unidas por "\n", en el orden de
     * `compile-cases.json`. Calculada ANTES del cambio (HEAD `3be8cffb`) sobre
     * el fichero del 7 (Node) y con el compilador de PHP: las dos daban esta.
     */
    private const REJILLA_C7 = '2cb4516ac27feecca9c97d51b4126ff0eae2acd4663addd0a3be9983fa392643';

    /** La misma huella con el compilador 8, sobre el `compile-cases.json` regenerado con Node. */
    private const REJILLA_C8 = '7383dbc752ff9a5067b36994477757eafa0f430760921345f397b16e42860f8b';

    private const CASOS = __DIR__.'/../../../../fixtures/ultron-prompts/compile-cases.json';

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** L = max(N, 320) (§B.3, «Compilador 8»), calculada AQUÍ y no con el compilador. */
    private static function l(int $n): int
    {
        return max($n, 320);
    }

    /** La guía del 8 con la L de un tope. */
    private static function lista(int $n): string
    {
        return str_replace('{L}', (string) self::l($n), self::PLANS_COMPOSER);
    }

    /** La guía del 7 con la L y la W de un tope (W = intdiv(L − 110, 30) · 5, §B.3 «Compilador 6»). */
    private static function listaDelSiete(int $n): string
    {
        $l = self::l($n);

        return str_replace(['{L}', '{W}'], [(string) $l, (string) (intdiv($l - 110, 30) * 5)], self::PLANS_COMPOSER_C7);
    }

    /**
     * El tope del redactor para un N, con las fórmulas de §B.3 «Compilador 3»
     * (M = intdiv(N · 8, 100) · 10, P = intdiv(M, 30) · 5,
     * F = max(2, intdiv(M + 30, 60))): con el sufijo del 8 o, sin él, el del 7.
     */
    private static function tope(int $n, bool $conSufijo = true): string
    {
        $m = intdiv($n * 8, 100) * 10;
        $texto = str_replace(['{N}', '{M}', '{P}', '{F}'], [(string) $n, (string) $m, (string) (intdiv($m, 30) * 5), (string) max(2, intdiv($m + 30, 60))], self::MAX_COMPOSER_C3);

        return $conSufijo ? $texto.self::SUFIJO_TOPE : $texto;
    }

    /** La v5 (el contenido de la v4): `profesional · 200 · emojis sí · cita · sin frases`. */
    private static function v5(): array
    {
        return array_replace(C::BASELINE, [C::TONE => 'profesional', C::MAX_CHARS => 200]);
    }

    /** Las 15 entradas del parche del motor, en el orden de la tabla, con el tope de la v5: [rol, operación, ancla, texto]. */
    private static function motor(): array
    {
        return [
            ['strategist', C::INSERT_AFTER_LINE, self::A_COTIZA, self::PLANS_STRATEGIST],
            ['strategist', C::REPLACE, self::A_PRESENTALO, self::STRATEGIST_PRESENTALO],
            ['composer', C::REPLACE, self::A_SUGERIDA, self::PLANS_COMPOSER_ASK],
            ['composer', C::REPLACE, self::A_MARCADOR, self::COMPOSER_MARCADOR],
            ['composer', C::REPLACE, self::A_ELIGE, self::COMPOSER_ELIGE],
            ['composer', C::REPLACE, self::A_PRECIO, self::PLANS_COMPOSER_PRICE],
            ['composer', C::INSERT_AFTER_LINE, self::A_FIN_COMPOSER, self::lista(200)],
            ['composer_retry', C::REPLACE, self::A_SUGERIDA, self::PLANS_COMPOSER_ASK],
            ['composer_retry', C::REPLACE, self::A_MARCADOR, self::COMPOSER_MARCADOR],
            ['composer_retry', C::REPLACE, self::A_PRECIO, self::PLANS_COMPOSER_PRICE],
            ['composer_retry', C::INSERT_AFTER_LINE, self::A_FIN_COMPOSER, self::lista(200)],
            ['critic', C::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::PLANS_CRITIC],
            ['critic', C::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::PLANS_CRITIC_CHECKED],
            ['critic_retry', C::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::PLANS_CRITIC],
            ['critic_retry', C::INSERT_AFTER_LINE, self::A_FIN_CRITIC, self::PLANS_CRITIC_CHECKED],
        ];
    }

    /** Los siete textos del 7 que el 8 sustituye y el tope sin sufijo, para un tope N: [del 8, del 7]. */
    private static function delOchoAlSiete(int $n): array
    {
        return [
            [self::PLANS_STRATEGIST, self::COMPOSER_MARCADOR, self::COMPOSER_ELIGE, self::PLANS_COMPOSER_PRICE, self::lista($n), self::PLANS_CRITIC, self::PLANS_CRITIC_CHECKED, self::tope($n)],
            [self::PLANS_STRATEGIST_C7, self::COMPOSER_MARCADOR_C7, self::COMPOSER_ELIGE_C7, self::PLANS_COMPOSER_PRICE_C7, self::listaDelSiete($n), self::PLANS_CRITIC_C7, self::PLANS_CRITIC_CHECKED_C7, self::tope($n, false)],
        ];
    }

    /** La línea que sigue a la primera que contiene `$ancla`. */
    private static function lineaTras(string $texto, string $ancla): ?string
    {
        $lineas = explode("\n", $texto);
        foreach ($lineas as $i => $linea) {
            if (str_contains($linea, $ancla)) {
                return $lineas[$i + 1] ?? null;
            }
        }

        return null;
    }

    /** La primera línea que contiene `$texto`. */
    private static function lineaCon(string $rol, string $texto): ?string
    {
        foreach (explode("\n", $rol) as $linea) {
            if (str_contains($linea, $texto)) {
                return $linea;
            }
        }

        return null;
    }

    /**
     * Los roles con SOLO los parches gobernados (sin el motor), aplicados con
     * el `apply()` del compilador: lo que el motor deja intacto.
     *
     * @return array<string,string>
     */
    private static function soloGobernado(array $g): array
    {
        $aplicar = new ReflectionMethod(C::class, 'apply');
        $roles = Base::roles();
        foreach (C::patches($g) as $p) {
            if ($p['parameter'] !== C::ENGINE) {
                $roles[$p['role']] = $aplicar->invoke(null, $roles[$p['role']], $p);
            }
        }

        return $roles;
    }

    /** @return list<array<string,mixed>> los 305 valores no base de la rejilla del contrato */
    private static function noBase(): array
    {
        $out = [];
        foreach (C::TONES as $tono) {
            foreach ([true, false] as $emojis) {
                foreach (range(C::MAX_CHARS_MIN, C::MAX_CHARS_MAX, C::MAX_CHARS_STEP) as $n) {
                    foreach (C::GOALS as $objetivo) {
                        if ([$tono, $emojis, $n, $objetivo] !== ['cercano', true, 320, 'cita']) {
                            $out[] = [C::TONE => $tono, C::EMOJIS => $emojis, C::MAX_CHARS => $n, C::GOAL => $objetivo, C::PHRASES => []];
                        }
                    }
                }
            }
        }

        return $out;
    }

    // ── N1 ──────────────────────────────────────────────────────────────────

    public function test_n1_la_v5_compila_con_la_guia_de_la_ranura_al_final_de_los_redactores_y_el_bloque_al_final_de_los_revisores(): void
    {
        $c = C::compile(self::v5());
        $r = $c['roles'];
        $base = Base::roles();
        $gobernado = self::soloGobernado(self::v5());

        // 25 parches: tono 4, tope 6 y el motor 15, este al final y en el orden de la tabla.
        $this->assertCount(25, $c['patches']);
        $this->assertSame([...array_fill(0, 4, C::TONE), ...array_fill(0, 6, C::MAX_CHARS), ...array_fill(0, 15, C::ENGINE)], array_column($c['patches'], 'parameter'));
        $this->assertSame(self::motor(), array_map(static fn (array $p): array => [$p['role'], $p['operation'], $p['anchor'], $p['text']], array_slice($c['patches'], -15)));
        // Lo gobernado: el del 4 (sin el motor) con el sufijo en el tope de los dos redactores, y nada más.
        $this->assertSame([0, 1, 0, 1, 0], array_map(static fn (string $t): int => substr_count($t, self::SUFIJO_TOPE), array_values($gobernado)), 'el sufijo, una vez en cada redactor');
        $this->assertSame(self::V4_GOBERNADO_HASH, Base::bundleHash(array_map(static fn (string $t): string => str_replace(self::SUFIJO_TOPE, '', $t), $gobernado)), 'sin el sufijo, lo gobernado de siempre');

        // El estratega: la inserción tras `QUE PLAN SE COTIZA` (con el texto del 8) y la sustitución de la l.23 (la del 5).
        $this->assertSame(self::PLANS_STRATEGIST, self::lineaTras($r['strategist'], self::A_COTIZA));
        $this->assertStringNotContainsString(self::PLANS_STRATEGIST_C7, $r['strategist'], 'la lista del estratega del 7 ya no va');
        $lineas = explode("\n", $base['strategist']);
        $i = array_key_first(array_filter($lineas, fn (string $l): bool => str_contains($l, self::A_COTIZA)));
        $this->assertSame(17, $i, 'QUE PLAN SE COTIZA es la l.18 de la base');
        $this->assertStringContainsString(self::A_PRESENTALO, $lineas[22], 'y la frase sustituida, la l.23');
        array_splice($lineas, $i + 1, 0, [self::PLANS_STRATEGIST]);
        $this->assertSame(str_replace(self::A_PRESENTALO, self::STRATEGIST_PRESENTALO, implode("\n", $lineas)), $r['strategist'], 'el estratega es la base con esas dos entradas, byte a byte');

        // Los dos redactores.
        foreach (['composer', 'composer_retry'] as $rol) {
            $lineasR = explode("\n", $r[$rol]);
            $this->assertSame(self::lista(200), end($lineasR), "{$rol}: la guía, con L = 320, es la ÚLTIMA línea del prompt");
            $this->assertStringContainsString(self::A_FIN_COMPOSER, $lineasR[count($lineasR) - 2], "{$rol}: justo detrás de la última línea de la base");
            $this->assertSame(self::tope(200), self::lineaTras($r[$rol], 'FORMA:'), "{$rol}: tras FORMA: queda el tope, con el sufijo del 8");
            $this->assertStringEndsWith('ofrece ampliar.'.self::SUFIJO_TOPE, self::lineaTras($r[$rol], 'FORMA:'), "{$rol}: el sufijo va tras «…ofrece ampliar.»");
            $this->assertSame(1, substr_count($r[$rol], self::lista(200)), $rol);
            $this->assertStringContainsString('no pasa de 320 caracteres', end($lineasR), "{$rol}: con tope 200, el de la lista es 320");
            foreach (['palabras tuyas', 'unos 25', '{{', '}}'] as $fuera) {
                $this->assertStringNotContainsString($fuera, end($lineasR), "{$rol}: la guía no lleva «{$fuera}»");
            }
            foreach ([self::listaDelSiete(200), self::COMPOSER_MARCADOR_C7, self::COMPOSER_ELIGE_C7, self::PLANS_COMPOSER_PRICE_C7, self::tope(200, false)."\n", self::PLANTILLA_DEL_DISENO] as $delSiete) {
                $this->assertStringNotContainsString($delSiete, $r[$rol], "{$rol}: un texto del 7 (o la plantilla del diseño) ya no va");
            }
            // composer.txt:5 y :38, sustituidas en su sitio.
            $sugerida = self::lineaCon($base[$rol], self::A_SUGERIDA);
            $this->assertSame($sugerida, explode("\n", $base[$rol])[4], "{$rol}: la de suggested_question es la l.5 de la base");
            $this->assertSame(str_replace(self::A_SUGERIDA, self::PLANS_COMPOSER_ASK, $sugerida), self::lineaCon($r[$rol], self::PLANS_COMPOSER_ASK), "{$rol}: l.5");
            $precio = self::lineaCon($base[$rol], self::A_PRECIO);
            $this->assertSame($precio, explode("\n", $base[$rol])[37], "{$rol}: la del precio es la l.38 de la base");
            $this->assertSame(str_replace(self::A_PRECIO, self::PLANS_COMPOSER_PRICE, $precio), self::lineaCon($r[$rol], self::PLANS_COMPOSER_PRICE), "{$rol}: l.38");
            foreach ([self::A_SUGERIDA, self::A_MARCADOR, self::A_PRECIO] as $ancla) {
                $this->assertSame([1, 0], [substr_count($base[$rol], $ancla), substr_count($r[$rol], $ancla)], "{$rol}: «{$ancla}» sustituida");
            }
            // Byte a byte: lo gobernado con las sustituciones en su sitio y la guía al final.
            $esperado = str_replace(
                [self::A_SUGERIDA, self::A_MARCADOR, self::A_ELIGE, self::A_PRECIO],
                [self::PLANS_COMPOSER_ASK, self::COMPOSER_MARCADOR, self::COMPOSER_ELIGE, self::PLANS_COMPOSER_PRICE],
                $gobernado[$rol],
            )."\n".self::lista(200);
            $this->assertSame($esperado, $r[$rol], "{$rol}: lo gobernado, las sustituciones y la guía, nada más");
        }
        $this->assertSame([1, 0, 1], [substr_count($base['composer'], self::A_ELIGE), substr_count($r['composer'], self::A_ELIGE), substr_count($r['composer'], self::COMPOSER_ELIGE)]);
        $this->assertSame(0, substr_count($base['composer_retry'], 'Elige el plan'), 'el reintento no tiene la frase de :50');

        // Los dos revisores: CHECKED y LISTA, las dos últimas; el tope sigue pegado a progressive_disclosure:.
        foreach (['critic', 'critic_retry'] as $rol) {
            $lineasR = explode("\n", $r[$rol]);
            $n = count($lineasR);
            $this->assertSame([self::PLANS_CRITIC_CHECKED, self::PLANS_CRITIC], [$lineasR[$n - 2], $lineasR[$n - 1]], "{$rol}: el veredicto y después LISTA, la última");
            $this->assertStringContainsString(self::A_FIN_CRITIC, $lineasR[$n - 3], "{$rol}: tras la última línea de la base");
            $this->assertSame(self::MAX_CRITIC_200, self::lineaTras($r[$rol], 'progressive_disclosure:'), "{$rol}: tras progressive_disclosure: queda el tope");
            $this->assertStringNotContainsString(self::PLANS_CRITIC_C7, $r[$rol], "{$rol}: el bloque del revisor del 7 ya no va");
            $this->assertStringNotContainsString(self::PLANS_CRITIC_CHECKED_C7, $r[$rol], "{$rol}: la frase del 7 ya no va");
            // Quitando las dos líneas insertadas, la base con los parches gobernados: el motor no sustituye nada en un revisor.
            $this->assertSame($gobernado[$rol]."\n".self::PLANS_CRITIC_CHECKED."\n".self::PLANS_CRITIC, $r[$rol], "{$rol}: solo inserciones");
            $this->assertSame($gobernado[$rol], implode("\n", array_slice($lineasR, 0, -2)), $rol);
            // HARD_FAIL, FALLA TAMBIEN y el resto de reglas de la base, byte a byte: cada línea de la base sigue, una vez.
            foreach (['HARD_FAIL', 'FALLA TAMBIEN'] as $regla) {
                $deLaBase = array_values(array_filter(explode("\n", $base[$rol]), static fn (string $l): bool => str_contains($l, $regla) && ! str_contains($l, 'LISTA DE PLANES')));
                $this->assertNotSame([], $deLaBase, "{$rol}: la base tiene {$regla}");
                foreach ($deLaBase as $linea) {
                    $this->assertContains($linea, $lineasR, "{$rol}: la línea de {$regla} sigue tal cual");
                }
            }
            $this->assertSame([], array_values(array_diff(explode("\n", $gobernado[$rol]), $lineasR)), "{$rol}: el motor no quita ni cambia ninguna línea de lo gobernado");
        }

        // La huella: la de Node; no la del 7.
        $this->assertSame([self::V5_C8_HASH, self::V5_C8_BYTES], [$c['bundleHash'], $c['bytes']]);
        $this->assertNotSame(self::V4_C7_HASH, $c['bundleHash'], 'la del compilador 7');
        $this->assertSame(8, C::VERSION);
        $this->assertSame(8, $c['compiler']);
    }

    // ── N2 ──────────────────────────────────────────────────────────────────

    public function test_n2_una_frase_con_un_ancla_no_vale(): void
    {
        // La del revisor cabe en una frase (57 caracteres del juego cerrado): `anchor`, sola, en una lista y dentro de otra.
        $this->assertSame(57, mb_strlen(self::A_FIN_CRITIC));
        $this->assertSame(C::PHRASE_ANCHOR, C::phraseViolation(self::A_FIN_CRITIC));
        $this->assertSame(['reason' => C::PHRASE_ANCHOR, 'index' => 1], C::phrasesViolation(['aaa', self::A_FIN_CRITIC]));
        $this->assertSame(C::PHRASE_ANCHOR, C::phraseViolation('y '.self::A_FIN_CRITIC));
        $this->assertSame(C::PHRASE_ANCHOR, C::phraseViolation(self::A_FIN_CRITIC.'.'));
        try {
            C::compile(array_replace(C::BASELINE, [C::PHRASES => [self::A_FIN_CRITIC]]));
            $this->fail('la frase con el ancla del revisor tenía que lanzar');
        } catch (UltronPromptException $e) {
            $this->assertSame(UltronPromptException::INVALID_PHRASES, $e->reason, 'se rechaza la frase, antes de llegar al ancla repetida');
        }
        // Las otras dos del 6 llevan `_` y `:`, fuera del juego cerrado: caen antes, por `charset`.
        foreach ([self::A_SUGERIDA, self::A_FIN_COMPOSER] as $ancla) {
            $this->assertLessThanOrEqual(C::PHRASE_MAX_CHARS, mb_strlen($ancla), "«{$ancla}»");
            $this->assertSame(C::PHRASE_CHARSET, C::phraseViolation($ancla), "«{$ancla}»");
        }
        // Las del compilador 5 siguen siendo anclas.
        foreach ([self::A_COTIZA, self::A_MARCADOR, self::A_PRECIO] as $ancla) {
            $this->assertSame(C::PHRASE_ANCHOR, C::phraseViolation($ancla), "«{$ancla}»");
        }
        foreach ([self::A_PRESENTALO, self::A_ELIGE] as $ancla) {
            $this->assertSame(C::PHRASE_LENGTH, C::phraseViolation($ancla), "«{$ancla}»");
        }
        // Lo que no contiene un ancla entera sigue valiendo.
        $this->assertNull(C::phraseViolation('Si el borrador explica por que la persona vio otro precio'));
        $this->assertNull(C::phraseViolation('la persona vio otro precio'));
    }

    // ── N3 ──────────────────────────────────────────────────────────────────

    public function test_n3_en_toda_combinacion_cada_ancla_una_vez_al_aplicarse_y_ningun_texto_contiene_un_ancla(): void
    {
        $frases = array_map(static fn (string $l): string => str_repeat('ñ', 59).$l, ['á', 'é', 'í', 'ó', 'ú', 'ü', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü']);
        sort($frases, SORT_STRING);
        $casos = self::noBase();
        $this->assertCount(305, $casos);
        $casos[] = array_replace(C::BASELINE, [C::PHRASES => ['gratis']]);
        $casos[] = array_replace(self::v5(), [C::PHRASES => ['gratis', 'te lo regalo']]);
        $casos[] = [C::TONE => 'profesional', C::EMOJIS => false, C::MAX_CHARS => 480, C::GOAL => 'venta', C::PHRASES => $frases];

        $aplicar = new ReflectionMethod(C::class, 'apply');
        $esperadas = [
            'strategist' => [self::A_COTIZA, self::A_PRESENTALO],
            'composer' => [self::A_SUGERIDA, self::A_MARCADOR, self::A_ELIGE, self::A_PRECIO, self::A_FIN_COMPOSER],
            'composer_retry' => [self::A_SUGERIDA, self::A_MARCADOR, self::A_PRECIO, self::A_FIN_COMPOSER],
            'critic' => [self::A_FIN_CRITIC, self::A_FIN_CRITIC],
            'critic_retry' => [self::A_FIN_CRITIC, self::A_FIN_CRITIC],
        ];
        $fallos = [];
        foreach ($casos as $g) {
            $nombre = json_encode($g, JSON_UNESCAPED_UNICODE);
            $p = C::patches($g);

            $delMotor = [];
            foreach (array_filter($p, fn (array $x): bool => $x['parameter'] === C::ENGINE) as $x) {
                $delMotor[$x['role']][] = $x['anchor'];
            }
            $this->assertSame($esperadas, $delMotor, "{$nombre}: el parche del motor usa en cada rol exactamente sus anclas");

            // Aplicados uno detrás de otro: cada ancla, exactamente una vez en el texto en el que se aplica.
            $roles = Base::roles();
            foreach ($p as $k => $x) {
                if (substr_count($roles[$x['role']], $x['anchor']) !== 1) {
                    $fallos[] = "{$nombre} #{$k}: «{$x['anchor']}» en {$x['role']}";
                }
                $roles[$x['role']] = $aplicar->invoke(null, $roles[$x['role']], $x);
                foreach (self::ANCLAS as $ancla) {
                    if (str_contains($x['text'], $ancla)) {
                        $fallos[] = "{$nombre} #{$k}: el texto contiene «{$ancla}»";
                    }
                }
            }
            $c = C::compile($g);
            $this->assertSame($roles, $c['roles'], $nombre);
            // Los textos del motor quedan los últimos del prompt, con cualquier otro parche; la guía, con la L de su tope.
            foreach (['composer', 'composer_retry'] as $rol) {
                $l = explode("\n", $c['roles'][$rol]);
                $this->assertSame(self::lista($g[C::MAX_CHARS]), end($l), "{$rol}: {$nombre}");
                // El sufijo va solo con el tope gobernado: con 320 no hay tope ni sufijo.
                $conTope = $g[C::MAX_CHARS] !== 320;
                $this->assertSame([$conTope ? 1 : 0, $conTope ? 1 : 0], [substr_count($c['roles'][$rol], $conTope ? self::tope($g[C::MAX_CHARS]) : 'TOPE DE LONGITUD PUBLICADO'), substr_count($c['roles'][$rol], self::SUFIJO_TOPE)], "{$rol}: {$nombre}");
            }
            foreach (['critic', 'critic_retry'] as $rol) {
                $this->assertSame([self::PLANS_CRITIC_CHECKED, self::PLANS_CRITIC], array_slice(explode("\n", $c['roles'][$rol]), -2), "{$rol}: {$nombre}");
            }
            // Ninguna ancla queda repetida en lo compilado (las sustituidas desaparecen; las demás, una vez).
            foreach ($c['roles'] as $rol => $texto) {
                foreach (self::ANCLAS as $ancla) {
                    if (substr_count($texto, $ancla) > 1) {
                        $fallos[] = "{$nombre}: «{$ancla}» repetida en {$rol}";
                    }
                }
                $this->assertLessThanOrEqual(C::MAX_ROLE_BYTES, strlen($texto));
            }
            $this->assertLessThanOrEqual(C::MAX_BUNDLE_BYTES, $c['bytes']);
        }
        $this->assertSame([], $fallos);
    }

    // ── N4 ──────────────────────────────────────────────────────────────────

    /** Los siete textos nuevos, el sufijo y los dos que siguen: sus bytes (§2.1 de la especificación), su forma, sus cifras y su tope. */
    public function test_n4_los_textos_al_byte_y_en_su_forma(): void
    {
        // La guía, con cualquier tope, mide lo mismo: L tiene siempre tres cifras.
        $guias = array_map(self::lista(...), range(C::MAX_CHARS_MIN, C::MAX_CHARS_MAX, C::MAX_CHARS_STEP));
        $this->assertSame([1189], array_values(array_unique(array_map('strlen', $guias))));
        $nuevos = [self::PLANS_STRATEGIST, self::COMPOSER_MARCADOR, self::COMPOSER_ELIGE, self::PLANS_COMPOSER_PRICE, self::lista(200), self::PLANS_CRITIC, self::PLANS_CRITIC_CHECKED];
        $this->assertSame([1105, 185, 232, 200, 1189, 881, 642], array_map('strlen', $nuevos));
        $this->assertSame(140, strlen(self::SUFIJO_TOPE));
        $this->assertSame(strlen(self::tope(200, false)) + 140, strlen(self::tope(200)));
        // Los del 7 que sustituyen (§2.1: «B en el 7»).
        $this->assertSame([1195, 165, 220, 184, 1127, 1119, 265], array_map('strlen', [self::PLANS_STRATEGIST_C7, self::COMPOSER_MARCADOR_C7, self::COMPOSER_ELIGE_C7, self::PLANS_COMPOSER_PRICE_C7, self::listaDelSiete(200), self::PLANS_CRITIC_C7, self::PLANS_CRITIC_CHECKED_C7]));
        $siguen = [self::STRATEGIST_PRESENTALO, self::PLANS_COMPOSER_ASK];
        $this->assertSame([158, 146], array_map('strlen', $siguen));
        foreach ([...$nuevos, ...$guias, ...$siguen, self::SUFIJO_TOPE, self::tope(160), self::tope(480)] as $t) {
            $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen($t));
            $this->assertMatchesRegularExpression('/\A[\x20-\x7E]+\z/', $t, 'ASCII imprimible, una línea, como el resto');
            $this->assertStringNotContainsString('{', $t, 'sin llaves: no forma un marcador ni un hueco');
            $this->assertStringNotContainsString('}', $t);
            foreach (self::ANCLAS as $ancla) {
                $this->assertStringNotContainsString($ancla, $t, 'ningún texto contiene un ancla');
            }
        }
        // Las cifras: la guía, una sola, su L; el estratega, solo el 160 del tope de `response_goal`; el revisor, las de PLAN_1 a PLAN_3; los demás, ninguna.
        foreach (range(C::MAX_CHARS_MIN, C::MAX_CHARS_MAX, C::MAX_CHARS_STEP) as $n) {
            preg_match_all('/\d+/', self::lista($n), $cifras);
            $this->assertSame([(string) self::l($n)], $cifras[0], "la guía con tope {$n}: una sola cifra, L");
        }
        $cifrasDe = static fn (string $t): array => preg_match_all('/\d+/', $t, $m) ? $m[0] : [];
        $this->assertSame(['160'], $cifrasDe(self::PLANS_STRATEGIST));
        $this->assertSame(['1', '2', '3'], $cifrasDe(self::PLANS_CRITIC));
        foreach ([self::COMPOSER_MARCADOR, self::COMPOSER_ELIGE, self::PLANS_COMPOSER_PRICE, self::PLANS_CRITIC_CHECKED, self::SUFIJO_TOPE] as $t) {
            $this->assertSame([], $cifrasDe($t), $t);
        }
        // Ningún texto que lee un redactor anuncia PLAN_ seguido de una cifra: los del motor y el tope, en toda la rejilla.
        $vistos = 0;
        foreach (self::noBase() as $g) {
            foreach (C::patches($g) as $p) {
                if (in_array($p['role'], ['composer', 'composer_retry'], true) && in_array($p['parameter'], [C::ENGINE, C::MAX_CHARS], true)) {
                    $vistos++;
                    $this->assertDoesNotMatchRegularExpression('/PLAN_\d/', $p['text'], "{$p['role']}: {$p['parameter']}");
                }
            }
        }
        $this->assertSame(305 * 9 + 288 * 2, $vistos, 'las nueve entradas del motor de los redactores y el tope (con N ≠ 320), en los 305 casos');
        // Los sustitutos no contienen su ancla (cambia el signo final) y empiezan por ella sin ese signo.
        foreach ([[self::A_SUGERIDA, self::PLANS_COMPOSER_ASK], [self::A_PRECIO, self::PLANS_COMPOSER_PRICE], [self::A_PRESENTALO, self::STRATEGIST_PRESENTALO], [self::A_MARCADOR, self::COMPOSER_MARCADOR], [self::A_ELIGE, self::COMPOSER_ELIGE]] as [$ancla, $sustituto]) {
            $this->assertStringNotContainsString($ancla, $sustituto);
            $this->assertStringStartsWith(substr($ancla, 0, -1), $sustituto, 'empieza por la frase de la base sin su signo final');
        }
        // La guía dice la longitud de ESTE turno, con la lista dentro; el sufijo del tope remite a ella.
        $this->assertStringNotContainsString('TOPE DE LONGITUD PUBLICADO', self::PLANS_COMPOSER);
        $this->assertStringContainsString('LONGITUD DE ESTE TURNO: el mensaje completo, ya con la lista, no pasa de {L} caracteres.', self::PLANS_COMPOSER);
        $this->assertSame(' Si commercial_turn_policy.plans_overview trae planes, este tope no aplica a ese mensaje: manda LONGITUD DE ESTE TURNO, al final del prompt.', self::SUFIJO_TOPE);
        $this->assertStringContainsString('En este turno esto manda sobre lo anterior que choque (orden del mensaje, recomendar un solo plan, preguntar antes de informar, los marcadores y el tope general).', self::PLANS_COMPOSER);
        // Y son los del compilador: la v5 los lleva, cada uno en su rol.
        $roles = C::compile(self::v5())['roles'];
        foreach ([[self::PLANS_STRATEGIST, 'strategist'], [self::COMPOSER_MARCADOR, 'composer_retry'], [self::COMPOSER_ELIGE, 'composer'], [self::PLANS_COMPOSER_PRICE, 'composer'], [self::lista(200), 'composer_retry'], [self::PLANS_CRITIC, 'critic_retry'], [self::PLANS_CRITIC_CHECKED, 'critic'], [self::tope(200), 'composer']] as [$t, $rol]) {
            $this->assertSame(1, substr_count($roles[$rol], $t), $rol);
        }
    }

    // ── N5 ──────────────────────────────────────────────────────────────────

    /**
     * DECISIÓN A.1 DEL PROPIETARIO (desde el 6): el redactor tiene libertad de
     * redacción. Ningún texto del motor que lee un redactor lleva una frase
     * modelo, una pieza de plantilla ni una pregunta de cierre fija: ni
     * comillas que encierren un ejemplo, ni signos de interrogación. Se mira
     * lo que COMPILA, en toda la rejilla.
     */
    public function test_n5_ninguna_frase_modelo_ni_pregunta_de_cierre_fija_en_el_redactor(): void
    {
        $prohibidas = ['Claro', 'tenemos', 'Cual revisamos', 'revisamos', 'exactamente', 'SOLO esta frase', 'en este orden', 'B1', 'B2', 'B3', ' Q ', 'Q es', 'Q.', 'Ejemplo', 'ejemplo', 'por ejemplo', 'Por ejemplo', 'plantilla'];
        $vistos = 0;
        foreach (self::noBase() as $g) {
            foreach (C::patches($g) as $p) {
                if ($p['parameter'] !== C::ENGINE || ! in_array($p['role'], ['composer', 'composer_retry'], true)) {
                    continue;
                }
                $vistos++;
                foreach ($prohibidas as $x) {
                    $this->assertStringNotContainsString($x, $p['text'], "un texto del motor del redactor no lleva «{$x}»");
                }
                $this->assertStringNotContainsString('"', $p['text'], 'sin comillas: ninguna frase que copiar');
                $this->assertStringNotContainsString("'", $p['text']);
                $this->assertStringNotContainsString('?', $p['text'], 'sin una pregunta escrita que el modelo pueda copiar');
            }
        }
        $this->assertSame(305 * 9, $vistos, 'las nueve entradas del motor de los redactores, en los 305 casos');
        // Ni en lo compilado de la v5: ni la plantilla ni su pregunta en ningún rol.
        foreach (C::compile(self::v5())['roles'] as $rol => $texto) {
            $this->assertStringNotContainsString('Claro, tenemos', $texto, $rol);
            $this->assertStringNotContainsString('Cual revisamos', $texto, $rol);
            $this->assertStringNotContainsString(self::PLANTILLA_DEL_DISENO, $texto, $rol);
        }
        // Lo que sí dice: el resto con sus palabras, y UNA pregunta breve opcional.
        $this->assertStringContainsString('Tu escribes el resto con tus palabras', self::PLANS_COMPOSER);
        $this->assertStringContainsString('Puedes abrir con una frase corta y cerrar con UNA pregunta breve que ayude a elegir o a avanzar; suggested_question no es obligatoria aqui.', self::PLANS_COMPOSER);
        // Y el revisor, sin una lista cerrada de registro: lo comprueba el backend.
        $this->assertStringContainsString('el registro (tu/usted), la longitud y la lista ya los comprueba el backend de forma determinista: no falles, no bajes naturalness ni los menciones en notes por ellos.', self::PLANS_CRITIC_CHECKED);
    }

    // ── N6 ──────────────────────────────────────────────────────────────────

    public function test_n6_la_linea_base_es_la_base_y_su_huella_es_legacy(): void
    {
        $c = C::compile(C::BASELINE);
        $this->assertSame([], C::patches(C::BASELINE));
        $this->assertSame([], $c['patches']);
        $this->assertSame(Base::roles(), $c['roles']);
        $this->assertSame('3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902', $c['bundleHash'], 'la huella Legacy, escrita a mano: el compilador 8 no la mueve');
        $this->assertSame(Base::LEGACY_BUNDLE_HASH, $c['bundleHash']);
        foreach ([self::lista(200), self::lista(480), self::PLANS_CRITIC, self::PLANS_CRITIC_CHECKED, self::PLANS_COMPOSER_PRICE, self::COMPOSER_MARCADOR, self::COMPOSER_ELIGE, self::PLANS_STRATEGIST, self::SUFIJO_TOPE] as $t) {
            $this->assertStringNotContainsString($t, implode("\n", $c['roles']));
        }
    }

    // ── N7 ──────────────────────────────────────────────────────────────────

    public function test_n7_el_laboratorio_frente_al_motor_sigue_al_compilador_8(): void
    {
        $this->assertSame(C::VERSION, StudioLabEngineFlow::COMPILER);
        $this->assertSame(8, StudioLabEngineFlow::COMPILER);
        $this->assertSame('motor-S2-c8-2026-11-22.1', StudioLabEngineFlow::promptVersion());
        $this->assertTrue(StudioLabEngineFlow::isEnginePromptVersion(StudioLabEngineFlow::promptVersion()));
        // Lo medido con el 7 lleva otro nombre: nunca se compara con lo del 8.
        $this->assertNotSame('motor-S2-c7-2026-11-22.1', StudioLabEngineFlow::promptVersion());
    }

    // ── N9 ──────────────────────────────────────────────────────────────────

    /**
     * Del 7 al 8 solo cambian los siete textos del motor y el sufijo del tope:
     * devolviéndolos a los del 7 en lo que compila el 8, los 306 casos de la
     * rejilla dan la huella del 7, fijada antes del cambio. Sin devolverlos,
     * la del 8 que da el generador de Node.
     */
    public function test_n9_devolviendo_los_textos_del_7_la_rejilla_da_la_huella_del_7(): void
    {
        $casos = json_decode((string) file_get_contents(self::CASOS), true, 64, JSON_THROW_ON_ERROR)['cases'];
        $this->assertCount(306, $casos);
        $base = Base::roles();
        $del8 = [];
        $del7 = [];
        $cambian = 0;
        foreach ($casos as $caso) {
            $roles = C::compile($caso['governed'])['roles'];
            $del8[] = $caso['id'].':'.Base::bundleHash($roles);
            [$de, $a] = self::delOchoAlSiete($caso['governed'][C::MAX_CHARS]);
            $devueltos = array_map(static fn (string $t): string => str_replace($de, $a, $t), $roles);
            $del7[] = $caso['id'].':'.Base::bundleHash($devueltos);
            if ($devueltos !== $roles) {
                $cambian++;
                // Lo que se devuelve es todo lo del 8: ya no queda ningún texto suyo.
                foreach ($de as $texto) {
                    $this->assertStringNotContainsString($texto, implode("\n", $devueltos), $caso['id']);
                }
            } else {
                $this->assertSame($base, $roles, "{$caso['id']}: solo la línea base no lleva nada del 8");
            }
        }
        $this->assertSame(305, $cambian, 'todo paquete no base lleva textos del 8; la línea base, no');
        $this->assertSame(self::REJILLA_C7, hash('sha256', implode("\n", $del7)), 'con los textos del 7, la rejilla del 7 byte a byte');
        $this->assertSame(self::REJILLA_C8, hash('sha256', implode("\n", $del8)), 'con los del 8, la de Node');
    }

    // ── N10 ─────────────────────────────────────────────────────────────────

    /**
     * Cuando el servidor decide la lista, la estrategia que prescribe el texto
     * del 8 es coherente con esa decisión y usa SOLO valores que los contratos
     * de hoy admiten (sin cambiarlos): `next_best_action answer`, que está en
     * `allowed_next_actions` de TODA política con lista; `conversation_goal
     * recommend` y `recommendation_goal compare_plans`; `information_needed
     * []`; `recommended_plan_id` nunca null, solo como referencia del contrato.
     * El `response_goal` va sin cifras, sin nombres de plan y sin marcadores:
     * la lista la escribe el backend. Validar Estrategia no la corrige y
     * `fromProposal()` la conserva.
     */
    public function test_n10_la_estrategia_es_coherente_con_los_contratos_y_el_response_goal_va_sin_nombres(): void
    {
        $t = self::PLANS_STRATEGIST;
        foreach (['next_best_action answer (de allowed_next_actions)', 'conversation_goal recommend', 'recommendation_goal compare_plans', 'information_needed [] y recommended_plan_id', 'recommended_plan_id el que digan las reglas de abajo (nunca null), solo como referencia del contrato: se destaca solo si se pidio.', 'el turno RESPONDE con la lista entera; no descubre;', 'que escribe el backend con el nombre y el precio de cada plan, en su orden.'] as $prescrito) {
            $this->assertStringContainsString($prescrito, $t);
        }
        $this->assertStringNotContainsString('recommend_plan', $t, 'sin la acción de recomendar un plan');
        // El response_goal: lo que tiene que decir, sin cifras, sin nombres de plan y sin marcadores.
        foreach (['response_goal con tus palabras, sin cifras ni nombres de plan y en menos de 160 caracteres', 'que va la lista de planes del backend', 'lo demas que pregunto en ese mensaje, nombrandolo', 'si cabe, una pregunta breve para elegir'] as $pieza) {
            $this->assertStringContainsString($pieza, $t);
        }
        $this->assertStringNotContainsString('response_goal:', $t, 'sin una frase dictada detrás de response_goal');
        $this->assertStringNotContainsString('PLAN_', $t, 'el estratega ya no habla de marcadores');
        $this->assertStringNotContainsString('marcador', $t);
        $this->assertStringContainsString('cada uno con su marcador', self::PLANS_STRATEGIST_C7, 'el del 7 los pedía');

        // Los valores, en los contratos de hoy.
        $this->assertContains('recommend', StrategyContract::CONVERSATION_GOALS);
        $this->assertContains('compare_plans', StrategyContract::RECOMMENDATION_GOALS);
        $esquema = StudioLabEngineFlow::schemaOf('Strategist')['properties'];
        $this->assertSame(['type' => 'string'], $esquema['next_best_action'], 'el nodo acepta cualquier texto (el commit, hasta 60)');
        $this->assertContains('recommend', $esquema['conversation_goal']['enum']);
        $this->assertContains('compare_plans', $esquema['recommendation_goal']['enum']);
        $this->assertSame(['integer', 'null'], $esquema['recommended_plan_id']['type']);

        // `answer` está en `allowed_next_actions` de TODA política con lista.
        $planes = [['id' => 3, 'name' => 'Plan Valera', 'duration_days' => 30], ['id' => 4, 'name' => 'Plan Mensual', 'duration_days' => 30], ['id' => 8, 'name' => 'Élite', 'duration_days' => 30]];
        $conLista = 0;
        foreach (SalesAgentDecisionSchema::INTENTS as $intent) {
            foreach (['¿Qué planes tienen y cuánto cuesta la mensualidad?', '¿Qué planes tienen y abren los domingos?', 'Hola, precios por favor', 'Precios?', 'Info planes', 'Cuales son las tarifas'] as $texto) {
                $politica = CommercialTurnPolicy::decide($intent, 'NEW_LEAD', ConversationMemory::empty(), $planes, ['type' => 'none'], $texto);
                if (! CommercialTurnPolicy::pideElPanorama($texto, $politica, ['type' => 'none'])) {
                    continue;
                }
                $conLista++;
                $this->assertContains('answer', $politica['allowed_next_actions'], "{$intent} · {$texto}");
            }
        }
        $this->assertSame(4 * 6, $conLista, 'las cuatro intenciones del panorama, con los seis textos');

        // Validar Estrategia (el nodo de n8n, portado línea a línea): la propuesta del 8, sin correcciones.
        $decide = [
            'commercial_phase' => 'NEW_LEAD', 'allowed_transitions' => ['NEW_LEAD', 'GOAL_DISCOVERY', 'RECOMMENDATION'], 'allowed_tools' => [],
            'decision' => ['intent' => SalesIntents::PRICING_QUESTION],
            'context' => ['active_plans' => $planes, 'default_plan_id' => 3, 'strategy_hints' => ['may_ask' => ['objective', 'experience_level'], 'question_budget' => 1, 'hot_lead_fast_path' => false, 'do_not_sell' => false, 'payment_possible' => false]],
        ];
        $propuesta = self::propuestaDelOcho();
        foreach ([$propuesta, ['recommended_plan_id' => 3] + $propuesta, ['strategy_hints' => 'do_not_sell'] + $propuesta] as $i => $p) {
            $ctx = $decide;
            if (isset($p['strategy_hints'])) {
                unset($p['strategy_hints']);
                $ctx['context']['strategy_hints']['do_not_sell'] = true;
            }
            $v = StudioLabEngineFlow::validateStrategy($ctx, $p);
            $this->assertSame([], $v['corrections'], "propuesta {$i}: ninguna corrección");
            foreach (['next_best_action', 'conversation_goal', 'recommendation_goal', 'information_needed', 'question_needed', 'response_goal', 'recommended_plan_id'] as $campo) {
                $this->assertSame($p[$campo], $v['strategy'][$campo], "propuesta {$i}: {$campo}");
            }
        }

        // `fromProposal()` (lo que el commit persiste): la conserva.
        $this->assertSame([
            'conversation_goal' => 'recommend', 'information_needed' => [], 'question_needed' => false,
            'response_goal' => $propuesta['response_goal'], 'buying_signal' => 'weak', 'closing_opportunity' => false,
            'payment_opportunity' => false, 'app_support_opportunity' => false, 'recommendation_goal' => 'compare_plans',
        ], StrategyContract::fromProposal($propuesta));
        // Y el response_goal de la propuesta cumple lo que pide el texto: corto, sin cifras, sin nombres de plan y sin marcadores.
        $this->assertLessThanOrEqual(160, mb_strlen($propuesta['response_goal']));
        $this->assertDoesNotMatchRegularExpression('/\d/', $propuesta['response_goal']);
        $this->assertStringNotContainsString('{{', $propuesta['response_goal']);
        foreach ($planes as $plan) {
            $this->assertStringNotContainsString($plan['name'], $propuesta['response_goal']);
        }
    }

    /**
     * Una estrategia como la que prescribe el texto del 8 para «¿Qué planes
     * tienen y abren los domingos?»: los valores del texto; el `response_goal`,
     * escrito aquí como lo escribiría el estratega (no es una frase del motor).
     *
     * @return array<string,mixed>
     */
    private static function propuestaDelOcho(): array
    {
        return [
            'strategy_goal' => 'collect_data', 'next_state' => 'NEW_LEAD', 'intent' => SalesIntents::PRICING_QUESTION,
            'recommended_plan_id' => 8, 'main_barrier' => null, 'next_best_action' => 'answer', 'confidence' => 0.8,
            'tools_requested' => [], 'staff_review_reason' => null, 'reason' => 'pide los planes y el horario del domingo',
            'human_handoff_requested' => false, 'human_handoff_reason' => null, 'human_handoff_evidence' => null,
            'conversation_goal' => 'recommend', 'information_needed' => [], 'question_needed' => false,
            'response_goal' => 'Va la lista de planes del backend, contestar si abren el domingo y cerrar con una pregunta breve para elegir',
            'buying_signal' => 'weak', 'closing_opportunity' => false, 'payment_opportunity' => false, 'app_support_opportunity' => false,
            'recommendation_goal' => 'compare_plans',
        ];
    }

    // ── N11 ─────────────────────────────────────────────────────────────────

    /**
     * LIBERTAD CONVERSACIONAL (condición del propietario): ningún texto del
     * motor, en ningún rol y en toda la rejilla, lleva una frase modelo, una
     * pregunta escrita o una respuesta comercial predefinida que el modelo
     * pueda copiar: ni comillas, ni signos de interrogación, ni «Claro,
     * tenemos…», «ejemplo», «plantilla» (salvo para decirle al revisor que la
     * lista no lo es) ni piezas de plantilla.
     */
    public function test_n11_ninguna_frase_fija_ni_respuesta_predefinida_en_los_textos_del_motor(): void
    {
        $prohibidas = ['Claro', 'tenemos', 'Cual revisamos', 'revisamos', 'exactamente', 'SOLO esta frase', 'en este orden', 'B1', 'B2', 'B3', ' Q ', 'Q es', 'Q.', 'Ejemplo', 'ejemplo', 'por ejemplo', 'Por ejemplo', 'plantilla', 'literal', 'Le cuento', 'le cuento', 'Con gusto', 'con gusto'];
        $vistos = [];
        foreach (self::noBase() as $g) {
            foreach (C::patches($g) as $p) {
                if ($p['parameter'] !== C::ENGINE) {
                    continue;
                }
                $vistos[$p['role']] = ($vistos[$p['role']] ?? 0) + 1;
                $this->assertStringNotContainsString('"', $p['text'], "{$p['role']}: sin comillas, ninguna frase que copiar");
                $this->assertStringNotContainsString("'", $p['text'], $p['role']);
                $this->assertStringNotContainsString('?', $p['text'], "{$p['role']}: sin una pregunta escrita");
                $this->assertStringNotContainsString('¿', $p['text'], $p['role']);
                // El revisor puede decir que la lista NO es plantilla; los textos que hacen escribir (estratega y redactores), nada de esto.
                if (in_array($p['role'], ['strategist', 'composer', 'composer_retry'], true)) {
                    foreach ($prohibidas as $x) {
                        $this->assertStringNotContainsString($x, $p['text'], "{$p['role']}: un texto del motor no lleva «{$x}»");
                    }
                }
            }
        }
        $this->assertSame(['strategist' => 305 * 2, 'composer' => 305 * 5, 'composer_retry' => 305 * 4, 'critic' => 305 * 2, 'critic_retry' => 305 * 2], $vistos, 'las quince entradas del motor, en los 305 casos');
        $this->assertStringContainsString('response_goal con tus palabras', self::PLANS_STRATEGIST);
    }

    // ── N12 ─────────────────────────────────────────────────────────────────

    /**
     * LA RANURA. La guía le pide al redactor `LISTA_DE_PLANES` entre dobles
     * llaves, una sola vez, donde va la lista (forma B); si no la pone, el
     * backend pone la lista antes de su pregunta final. El literal es el de
     * la ranura que compone Laravel; la constante
     * `UltronPlansOverview::SLOT` llega con L1, y entonces esta prueba la
     * nombra. Ningún texto lleva llaves: la ranura se describe, no se escribe.
     */
    public function test_n12_la_guia_pide_la_ranura_lista_de_planes(): void
    {
        $ranura = 'LISTA_DE_PLANES';
        $this->assertSame(1, substr_count(self::PLANS_COMPOSER, $ranura), 'la guía nombra la ranura una vez');
        $this->assertStringContainsString('Tu escribes el resto con tus palabras y pones LISTA_DE_PLANES entre dobles llaves, una sola vez, donde va la lista; si no la pones, el backend la pone antes de tu pregunta final.', self::PLANS_COMPOSER);
        $this->assertStringContainsString('No escribas nombres de plan, cifras ni marcadores que empiecen por PLAN_.', self::PLANS_COMPOSER);
        // La única excepción a «cualquier otro marcador se rechaza» es la ranura, y el revisor la reconoce.
        $this->assertStringContainsString('salvo LISTA_DE_PLANES entre dobles llaves cuando commercial_turn_policy.plans_overview trae planes', self::COMPOSER_MARCADOR);
        $this->assertStringContainsString('si reply_draft lleva LISTA_DE_PLANES, o PLAN_1, PLAN_2 o PLAN_3, entre dobles llaves', self::PLANS_CRITIC);
        // Lo compila el 8 en los dos redactores y en los dos revisores; nunca con llaves.
        $roles = C::compile(self::v5())['roles'];
        foreach (['composer', 'composer_retry', 'critic', 'critic_retry'] as $rol) {
            $this->assertStringContainsString($ranura, $roles[$rol], $rol);
            $this->assertStringNotContainsString('{{'.$ranura, $roles[$rol], "{$rol}: la ranura no va escrita con llaves");
        }
        $this->assertStringNotContainsString($ranura, $roles['strategist'], 'el estratega no la usa');
        $this->assertStringNotContainsString($ranura, implode("\n", Base::roles()), 'la base no la conoce');
    }

    // ── N13 ─────────────────────────────────────────────────────────────────

    /**
     * LA RECOMENDACIÓN VA DESPUÉS DE LA LISTA, Y LOS PLANES SE NOMBRAN POR SU
     * LUGAR. El estratega: la lista entera, siempre; una recomendación no la
     * sustituye y, si se pidió, va tras ella. El redactor: no escribe nombres
     * de plan; habla de los planes sin nombrarlos o por su lugar en la lista
     * (el primero, el segundo, el tercero) y, si se pidió una recomendación,
     * va después de la lista.
     */
    public function test_n13_la_recomendacion_va_despues_de_la_lista_y_por_su_lugar(): void
    {
        $t = self::PLANS_STRATEGIST;
        $lista = strpos($t, 'el turno RESPONDE con la lista entera;');
        $noSustituye = strpos($t, 'una recomendacion no sustituye la lista: solo si se pidio, va tras ella.');
        $this->assertNotFalse($lista, 'el turno responde con la lista entera');
        $this->assertNotFalse($noSustituye, 'una recomendación no sustituye la lista y va tras ella si se pidió');
        $this->assertLessThan($noSustituye, $lista, 'primero la lista entera; después, la condición de la recomendación');
        $this->assertStringContainsString('solo como referencia del contrato: se destaca solo si se pidio.', $t);
        $this->assertDoesNotMatchRegularExpression('/\b(no|ni|nunca)\s+(recomienda|recomiendes|recomendar)\b/', $t, 'sin una prohibición de recomendar');

        $g = self::PLANS_COMPOSER;
        $porSuLugar = strpos($g, 'Puedes hablar de los planes sin nombrarlos o por su lugar en la lista (el primero, el segundo, el tercero), con sus benefits.');
        $despues = strpos($g, 'si pidio una recomendacion, va despues de la lista.');
        $this->assertNotFalse($porSuLugar, 'por su lugar en la lista');
        $this->assertNotFalse($despues, 'la recomendación, después de la lista');
        $this->assertLessThan($despues, strpos($g, 'No escribas nombres de plan'), 'sin nombres a mano, también al recomendar');
        $this->assertDoesNotMatchRegularExpression('/\b(no|ni|nunca)\s+(recomienda|recomiendes|recomendar)\b/', $g, 'sin una prohibición de recomendar');

        // Los dos, dentro del tope de un texto de parche y en su sitio de la v5.
        $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen($t));
        $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen(self::lista(480)));
        $v5 = C::compile(self::v5())['roles'];
        $this->assertSame($t, self::lineaTras($v5['strategist'], self::A_COTIZA));
        $this->assertSame(1, substr_count($v5['composer'], self::lista(200)));
    }

    // ── N14 ─────────────────────────────────────────────────────────────────

    /**
     * P-1 A: en un borrador de la lista, el revisor solo bloquea con
     * `hard_fail` (lo que L4 trata como fallo grave) y solo por lo de
     * HARD_FAIL o FALLA TAMBIEN; sin `hard_fail`, solo si deja sin responder
     * una pregunta directa; lo demás, `pass` con la mejora en `notes`. La
     * frase va tal cual, y los valores que nombra existen en el esquema del
     * revisor y en su base.
     */
    public function test_n14_p1a_la_frase_exacta_del_veredicto(): void
    {
        $veredicto = 'En ese borrador es fail con hard_fail solo un incumplimiento real de seguridad, exactitud o politica de HARD_FAIL o de FALLA TAMBIEN (un plan, clase, servicio, beneficio u horario que no esta en active_plans, gym o knowledge_base es invented_class), y fail sin hard_fail solo si deja sin responder una pregunta directa del mensaje. Lo demas es verdict pass, con la mejora en notes.';
        $this->assertSame(1, substr_count(self::PLANS_CRITIC_CHECKED, $veredicto));
        $this->assertStringEndsWith($veredicto, self::PLANS_CRITIC_CHECKED, 'el veredicto cierra la frase');
        $this->assertStringStartsWith('VEREDICTO EN LA LISTA DE PLANES: si reply_draft entra en LISTA DE PLANES DEL BACKEND (abajo), ', self::PLANS_CRITIC_CHECKED);

        // Lo que nombra existe: `hard_fail` con `invented_class` y `verdict` pass|fail en el esquema del revisor; HARD_FAIL y FALLA TAMBIEN en su base.
        $esquema = StudioLabEngineFlow::schemaOf('Critic Comercial');
        $this->assertContains('hard_fail', $esquema['required']);
        $this->assertContains('invented_class', $esquema['properties']['hard_fail']['enum']);
        $this->assertSame(['pass', 'fail'], $esquema['properties']['verdict']['enum']);
        foreach (['critic', 'critic_retry'] as $rol) {
            $deLaBase = Base::roles()[$rol];
            $this->assertStringContainsString('HARD_FAIL', $deLaBase, $rol);
            $this->assertStringContainsString('FALLA TAMBIEN', $deLaBase, $rol);
            $this->assertStringContainsString('invented_class', $deLaBase, $rol);
        }
        // «(abajo)»: en lo compilado, LISTA DE PLANES DEL BACKEND va justo debajo de esta frase.
        foreach (['critic', 'critic_retry'] as $rol) {
            $lineas = explode("\n", C::compile(self::v5())['roles'][$rol]);
            $this->assertSame([self::PLANS_CRITIC_CHECKED, self::PLANS_CRITIC], array_slice($lineas, -2), $rol);
            $this->assertStringStartsWith('LISTA DE PLANES DEL BACKEND', end($lineas), $rol);
        }
    }
}
