<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase as Base;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as C;
use App\Services\UltronStudio\StudioPromptBundle;
use PHPUnit\Framework\TestCase;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · OLA 2 · EL PARCHE DEL MOTOR EN EL
 * COMPILADOR 4 (plan del arquitecto `plan-arquitecto-a4-regenerar.md` §3 P-3,
 * cambio 3, §6 «Ola 2» y §7.1; decisiones L-2 y L-7 del lead).
 *
 * La instrucción de la lista de planes (`{{PLAN_1}}`…`{{PLAN_3}}`) va en un
 * parche del MOTOR (`engine.plansOverview`), no de un parámetro gobernado: se
 * añade AL FINAL y SOLO si la versión ya tiene algún parche gobernado. Con la
 * línea base no hay ninguno y `compile()` sigue siendo la base byte a byte con
 * la huella Legacy: la propiedad central de M-1 («línea base = como hoy») no
 * se mueve. La versión del compilador siguió en 4 (no estaba publicado en
 * ningún sitio).
 *
 * COMPILADOR 5 (Enmienda A4-5 del H5; PT-17, `diseno-arq-pt17-planes.md`; §B.3,
 * «Compilador 5»): el parche del motor pasa de cuatro entradas a once (la
 * inserción y la sustitución del Strategist, el texto nuevo del redactor tras
 * `FORMA:`, tres sustituciones en Composer y dos en su reintento, y los dos
 * Critic como en el 4).
 *
 * COMPILADOR 6 (Enmienda A4-6 del H5; PT-17b, `diseno-arq-pt17b-planes-c6.md`
 * con el anexo del propietario; §B.3, «Compilador 6»): quince entradas. En los
 * redactores, la sustitución de la frase de `suggested_question`, la del
 * precio con texto nuevo y la GUÍA de la lista tras su ÚLTIMA línea (queda la
 * última del prompt), con la L y la W de su tope ({@see self::guia()}); en los
 * Critic, dos inserciones tras su última línea: la frase de lo que comprueba
 * el backend y el bloque de la lista, la última.
 *
 * COMPILADOR 7 (Enmienda A4-7 del H5; PT-17c; §B.3, «Compilador 7»): solo
 * cambia el texto de la lista del Strategist; las quince entradas, sus anclas
 * y su orden, como en el 6.
 *
 * COMPILADOR 8 (PT-17, puerta 2, L5; §B.3, «Compilador 8»): la lista la
 * escribe el backend. Cambian siete textos del motor (la lista del
 * Strategist; el marcador, «Elige el plan…», el precio y la guía de los
 * redactores, que ya solo lleva L; los dos del revisor); las quince entradas,
 * sus anclas y su orden, como en el 6 y el 7. Lo que se fija abajo es ya el
 * del 8.
 *
 * Lo que se fija:
 *  1. La línea base: sin parches y con la huella Legacy, escrita a mano.
 *  2. Con CUALQUIER valor no base (los 305 de la rejilla y con frases): los
 *     quince parches del motor, los últimos, con sus textos de §B.3
 *     («Compilador 5», «Compilador 6» y «Compilador 8») copiados a mano, en anclas únicas; las
 *     inserciones pegadas a su ancla (las del redactor y el revisor, al final
 *     del prompt) y las sustituciones en lugar de su frase; ≤ 1 200 B; cada
 *     rol ≤ 40 960 y el paquete ≤ 163 840.
 *  3. El peor caso (tono, sin emojis, tope, objetivo y 12 frases de 60
 *     caracteres de 2 bytes) cabe, con sus bytes al byte.
 *  4. La vista previa del Studio NO enseña el parche del motor (no es un
 *     parámetro): agrupa por `PARAMETERS`, y la huella que firma sí lo lleva.
 */
/** Contrato histórico c8 explícito: las firmas y los bytes grabados no se actualizan a c9. */
class UltronPromptEnginePatchTest extends TestCase
{
    /** §B.3, «Compilador 8» (la lista del Strategist, el marcador, «Elige el plan…», el precio, la guía y los dos del revisor), «Compilador 5» («Presentalo…») y «Compilador 6» (la frase de suggested_question), copiados a mano. */
    private const PLANS_STRATEGIST = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend decidio responder con esa lista, que escribe el backend con el nombre y el precio de cada plan, en su orden. En ese turno esto manda sobre las reglas de este apartado, la lista de next_best_action, resolved_reference y strategy_hints (suggested_question, should_recommend_now): el turno RESPONDE con la lista entera; no descubre; una recomendacion no sustituye la lista: solo si se pidio, va tras ella. Entonces next_best_action answer (de allowed_next_actions), conversation_goal recommend, recommendation_goal compare_plans, information_needed [] y recommended_plan_id el que digan las reglas de abajo (nunca null), solo como referencia del contrato: se destaca solo si se pidio. response_goal con tus palabras, sin cifras ni nombres de plan y en menos de 160 caracteres: que va la lista de planes del backend, lo demas que pregunto en ese mensaje, nombrandolo, y, si cabe, una pregunta breve para elegir. Si plans_overview no viene, nada de esto aplica.';

    private const PLANS_STRATEGIST_PRESENTALO = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos; si commercial_turn_policy.plans_overview trae planes, no: manda LISTA DE PLANES DEL BACKEND.';

    private const PLANS_COMPOSER = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios y el backend ya eligio cuales mostrar y escribe la lista: cada plan de plans_overview.plans, en su orden, con su nombre y su precio oficiales. Tu escribes el resto con tus palabras y pones LISTA_DE_PLANES entre dobles llaves, una sola vez, donde va la lista; si no la pones, el backend la pone antes de tu pregunta final. No escribas nombres de plan, cifras ni marcadores que empiecen por PLAN_. Puedes hablar de los planes sin nombrarlos o por su lugar en la lista (el primero, el segundo, el tercero), con sus benefits. Si pregunto ademas otra cosa, respondela tambien; si pidio una recomendacion, va despues de la lista. Puedes abrir con una frase corta y cerrar con UNA pregunta breve que ayude a elegir o a avanzar; suggested_question no es obligatoria aqui. LONGITUD DE ESTE TURNO: el mensaje completo, ya con la lista, no pasa de {L} caracteres. En este turno esto manda sobre lo anterior que choque (orden del mensaje, recomendar un solo plan, preguntar antes de informar, los marcadores y el tope general). Si plans_overview no viene, nada de esto aplica.';

    private const PLANS_SUGERIDA = 'Si vas a preguntar, usa suggested_question cuando venga, nunca si commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND):';

    private const PLANS_MARCADOR = 'Cualquier otro marcador hace que el mensaje se rechace, salvo LISTA_DE_PLANES entre dobles llaves cuando commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND).';

    private const PLANS_ELIGE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues; si commercial_turn_policy.plans_overview trae planes, en vez de eso el backend pone esa lista (LISTA DE PLANES DEL BACKEND).';

    private const PLANS_PRECIO = 'el precio solo del que se cotiza o, si commercial_turn_policy.plans_overview trae planes, ni nombres ni precios a mano: la lista con nombres y precios la pone el backend (LISTA DE PLANES DEL BACKEND).';

    private const PLANS_CRITIC = 'LISTA DE PLANES DEL BACKEND (va al final porque manda sobre todo lo anterior): si reply_draft lleva LISTA_DE_PLANES, o PLAN_1, PLAN_2 o PLAN_3, entre dobles llaves, o la persona pidio los planes o los precios en general y el borrador no nombra planes ni precios, el backend pone ahi, o antes de la pregunta final, la lista con el nombre y el precio oficiales de cada plan elegido, en su orden. Esa lista no la juzgas: juzga solo la prosa. Cuenta como el precio de cada plan: no es invented_plan ni invented_price, no esquiva el precio y no es plantilla, folleto ni exceso de planes. En ese borrador NO son defecto: no preguntar antes objetivo, experiencia u horario, aunque la fase sea NEW_LEAD o DISCOVERY o la estrategia traiga recommended_plan_id, ni cerrar con una pregunta corta para elegir o sin pregunta. Un nombre de plan o una cifra escritos a mano los rechaza el backend.';

    private const PLANS_CRITIC_COMPRUEBA = 'VEREDICTO EN LA LISTA DE PLANES: si reply_draft entra en LISTA DE PLANES DEL BACKEND (abajo), el registro (tu/usted), la longitud y la lista ya los comprueba el backend de forma determinista: no falles, no bajes naturalness ni los menciones en notes por ellos. En ese borrador es fail con hard_fail solo un incumplimiento real de seguridad, exactitud o politica de HARD_FAIL o de FALLA TAMBIEN (un plan, clase, servicio, beneficio u horario que no esta en active_plans, gym o knowledge_base es invented_class), y fail sin hard_fail solo si deja sin responder una pregunta directa del mensaje. Lo demas es verdict pass, con la mejora en notes.';

    /** La huella Legacy, escrita a mano (la de `UltronPromptBase::LEGACY_BUNDLE_HASH`). */
    private const LEGACY = '3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902';

    /**
     * Las anclas de las inserciones del parche del motor (`grep -c` en la base: una vez en cada rol) y lo que
     * queda pegado a cada una, en orden. Compilador 6: las del redactor y el revisor son su ÚLTIMA línea, así
     * que lo pegado es lo último del prompt; la del redactor, la guía con el tope de cada caso (`{L}`; hasta el 7, también `{W}`).
     */
    private const ANCLAS = ['strategist' => ['QUE PLAN SE COTIZA', [self::PLANS_STRATEGIST]], 'composer' => ['price_verification true significa', [self::PLANS_COMPOSER]], 'composer_retry' => ['price_verification true significa', [self::PLANS_COMPOSER]], 'critic' => ['Si el borrador explica POR QUE la persona vio otro precio', [self::PLANS_CRITIC_COMPRUEBA, self::PLANS_CRITIC]], 'critic_retry' => ['Si el borrador explica POR QUE la persona vio otro precio', [self::PLANS_CRITIC_COMPRUEBA, self::PLANS_CRITIC]]];

    /** Las quince anclas del compilador 6 (ningún texto de parche contiene ninguna). */
    private const TODAS_LAS_ANCLAS = ['COMO SUENAS:', 'FORMA:', 'Emoji opcional y raro, nunca en temas sensibles.', 'naturalness:', 'progressive_disclosure:', 'mas de ~900 caracteres', 'PRINCIPIO:', 'QUE PLAN SE COTIZA', 'Presentalo con dos o tres beneficios reales, nunca con adjetivos.', 'Cualquier otro marcador hace que el mensaje se rechace.', 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues.', 'el precio solo del que se cotiza.', 'Si vas a preguntar, usa suggested_question cuando venga:', 'price_verification true significa', 'Si el borrador explica POR QUE la persona vio otro precio'];

    /** Las sustituciones del parche del motor: rol → [frase de la base, su sustituto]. */
    private const SUSTITUCIONES = [
        'strategist' => [['Presentalo con dos o tres beneficios reales, nunca con adjetivos.', self::PLANS_STRATEGIST_PRESENTALO]],
        'composer' => [['Si vas a preguntar, usa suggested_question cuando venga:', self::PLANS_SUGERIDA], ['Cualquier otro marcador hace que el mensaje se rechace.', self::PLANS_MARCADOR], ['Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues.', self::PLANS_ELIGE], ['el precio solo del que se cotiza.', self::PLANS_PRECIO]],
        'composer_retry' => [['Si vas a preguntar, usa suggested_question cuando venga:', self::PLANS_SUGERIDA], ['Cualquier otro marcador hace que el mensaje se rechace.', self::PLANS_MARCADOR], ['el precio solo del que se cotiza.', self::PLANS_PRECIO]],
    ];

    /** La guía del redactor con el tope de la lista de N, con la fórmula de §B.3 («Compilador 8») a mano: L = max(N, 320); desde el 8, sin W. */
    private static function guia(int $n): string
    {
        return str_replace('{L}', (string) max($n, 320), self::PLANS_COMPOSER);
    }

    /** Las quince entradas del parche del motor para un tope N, en su orden, como las enumera `patches()`. */
    private static function motor(int $n): array
    {
        $m = static fn (string $rol, string $op, string $ancla, string $texto): array => ['parameter' => C::ENGINE, 'value' => true, 'role' => $rol, 'operation' => $op, 'anchor' => $ancla, 'text' => $texto];
        $s = self::SUSTITUCIONES;

        return [
            $m('strategist', C::INSERT_AFTER_LINE, 'QUE PLAN SE COTIZA', self::PLANS_STRATEGIST),
            $m('strategist', C::REPLACE, $s['strategist'][0][0], $s['strategist'][0][1]),
            $m('composer', C::REPLACE, $s['composer'][0][0], $s['composer'][0][1]),
            $m('composer', C::REPLACE, $s['composer'][1][0], $s['composer'][1][1]),
            $m('composer', C::REPLACE, $s['composer'][2][0], $s['composer'][2][1]),
            $m('composer', C::REPLACE, $s['composer'][3][0], $s['composer'][3][1]),
            $m('composer', C::INSERT_AFTER_LINE, 'price_verification true significa', self::guia($n)),
            $m('composer_retry', C::REPLACE, $s['composer_retry'][0][0], $s['composer_retry'][0][1]),
            $m('composer_retry', C::REPLACE, $s['composer_retry'][1][0], $s['composer_retry'][1][1]),
            $m('composer_retry', C::REPLACE, $s['composer_retry'][2][0], $s['composer_retry'][2][1]),
            $m('composer_retry', C::INSERT_AFTER_LINE, 'price_verification true significa', self::guia($n)),
            $m('critic', C::INSERT_AFTER_LINE, 'Si el borrador explica POR QUE la persona vio otro precio', self::PLANS_CRITIC),
            $m('critic', C::INSERT_AFTER_LINE, 'Si el borrador explica POR QUE la persona vio otro precio', self::PLANS_CRITIC_COMPRUEBA),
            $m('critic_retry', C::INSERT_AFTER_LINE, 'Si el borrador explica POR QUE la persona vio otro precio', self::PLANS_CRITIC),
            $m('critic_retry', C::INSERT_AFTER_LINE, 'Si el borrador explica POR QUE la persona vio otro precio', self::PLANS_CRITIC_COMPRUEBA),
        ];
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

    public function test_la_linea_base_sigue_sin_parches_y_con_la_huella_legacy(): void
    {
        $c = C::compile(C::BASELINE, 8);
        $this->assertSame([], $c['patches']);
        $this->assertSame(Base::roles(), $c['roles'], 'byte a byte la base');
        $this->assertSame(self::LEGACY, $c['bundleHash']);
        $this->assertSame(self::LEGACY, Base::LEGACY_BUNDLE_HASH);
        $this->assertSame([], C::patches(C::BASELINE));
        $this->assertSame([], C::changedRoles(C::BASELINE));
        $this->assertTrue(C::isBaseline(C::BASELINE));
        $this->assertSame(9, C::VERSION, 'el 4 llegó a la v3 publicada y el 6 a la v4: cambiar el parche del motor obliga a subirla (Enmienda A4-5 y, otra vez, A4-6, A4-7 y la puerta 2 de PT-17)');
        $this->assertSame('engine.plansOverview', C::ENGINE);
        $this->assertNotContains(C::ENGINE, C::PARAMETERS, 'el del motor no es un parámetro gobernado');
    }

    public function test_con_cualquier_valor_no_base_los_quince_parches_del_motor_al_final(): void
    {
        $casos = self::noBase();
        $this->assertCount(305, $casos);
        $casos[] = array_replace(C::BASELINE, [C::PHRASES => ['gratis']]);
        $casos[] = array_replace(C::BASELINE, [C::TONE => 'profesional', C::MAX_CHARS => 200, C::PHRASES => ['gratis', 'te lo regalo']]);

        $base = Base::roles();
        foreach ($casos as $g) {
            $p = C::patches($g);
            $nombre = json_encode($g);
            $this->assertSame(self::motor($g[C::MAX_CHARS]), array_slice($p, -15), $nombre);
            $this->assertCount(15, array_filter($p, fn (array $x) => $x['parameter'] === C::ENGINE), 'las quince, una vez: '.$nombre);
            $this->assertNotSame([], array_filter(array_slice($p, 0, -15), fn (array $x) => in_array($x['parameter'], C::PARAMETERS, true)), 'hay algún parche gobernado delante');

            $c = C::compile($g, 8);
            $this->assertSame($p, $c['patches']);
            foreach (self::ANCLAS as $rol => [$ancla, $textos]) {
                $textos = array_map(fn (string $t): string => $t === self::PLANS_COMPOSER ? self::guia($g[C::MAX_CHARS]) : $t, $textos);
                $this->assertSame(1, substr_count($base[$rol], $ancla), "{$rol}: el ancla, una vez en la base");
                $this->assertSame(1, substr_count($c['roles'][$rol], $ancla), "{$rol}: y una vez en lo compilado");
                // Aplicados los últimos de su ancla, quedan pegados a ella, en su orden: las líneas siguientes a la del ancla.
                $lineas = explode("\n", $c['roles'][$rol]);
                $i = array_key_first(array_filter($lineas, fn (string $l) => str_contains($l, $ancla)));
                $this->assertSame($textos, array_slice($lineas, $i + 1, count($textos)), "{$rol}: {$nombre}");
                if ($rol !== 'strategist') {
                    // Compilador 6: el ancla es la última línea de la base, así que lo del motor es lo último del prompt.
                    $this->assertSame(count($lineas) - 1 - count($textos), $i, "{$rol}: el motor, al final del prompt · {$nombre}");
                }
                foreach ($textos as $texto) {
                    $this->assertSame(1, substr_count($c['roles'][$rol], $texto), "{$rol}: una vez");
                }
                $this->assertLessThanOrEqual(C::MAX_ROLE_BYTES, strlen($c['roles'][$rol]));
            }
            // Las sustituidas: una vez en la base, ninguna en lo compilado, y su sustituto una vez.
            foreach (self::SUSTITUCIONES as $rol => $pares) {
                foreach ($pares as [$frase, $sustituto]) {
                    $this->assertSame([1, 0, 1], [substr_count($base[$rol], $frase), substr_count($c['roles'][$rol], $frase), substr_count($c['roles'][$rol], $sustituto)], "{$rol}: «{$frase}» · {$nombre}");
                }
            }
            $this->assertLessThanOrEqual(C::MAX_BUNDLE_BYTES, $c['bytes']);
            $this->assertSame(Base::ROLES, C::changedRoles($g), 'desde el compilador 5: el motor toca los cinco roles');
            $this->assertFalse(C::isBaseline($g));
        }
    }

    public function test_los_textos_del_motor_al_byte_y_en_su_forma(): void
    {
        $textos = [self::PLANS_STRATEGIST, self::PLANS_STRATEGIST_PRESENTALO, self::PLANS_SUGERIDA, self::guia(200), self::PLANS_MARCADOR, self::PLANS_ELIGE, self::PLANS_PRECIO, self::PLANS_CRITIC, self::PLANS_CRITIC_COMPRUEBA];
        $this->assertSame([1105, 158, 146, 1189, 185, 232, 200, 881, 642], array_map('strlen', $textos), 'la lista del Strategist, 784 con el 5 y el 6; 1 195 con el 7; con el 8, las medidas de §2.1 de la especificación de la puerta 2');
        $this->assertSame(1189, strlen(self::guia(480)), 'la guía mide lo mismo con cualquier tope (L, tres cifras)');
        foreach ([...$textos, self::guia(480)] as $t) {
            $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen($t));
            $this->assertMatchesRegularExpression('/\A[\x20-\x7E]+\z/', $t, 'ASCII imprimible, una línea, como el resto');
            $this->assertStringNotContainsString('{', $t, 'sin llaves: no forma un marcador ni un hueco');
            $this->assertStringNotContainsString('}', $t);
            foreach (self::TODAS_LAS_ANCLAS as $ancla) {
                $this->assertStringNotContainsString($ancla, $t, 'ningún texto de parche contiene un ancla');
            }
        }
        // Un solo caso: los textos compilados son los de §B.3 («Compilador 5», «Compilador 6» y «Compilador 8») y no hay otros del motor.
        $p = C::patches(array_replace(C::BASELINE, [C::GOAL => 'venta']));
        $this->assertSame(['strategy.goal', ...array_fill(0, 15, C::ENGINE)], array_column($p, 'parameter'), 'un cambio solo del estratega también lleva el del motor');
        $this->assertSame(self::motor(320), array_slice($p, 1));
    }

    public function test_el_orden_tras_la_misma_ancla_con_el_tope_y_las_frases(): void
    {
        $c = C::compile(array_replace(C::BASELINE, [C::TONE => 'profesional', C::EMOJIS => false, C::MAX_CHARS => 200, C::PHRASES => ['gratis']]), 8);
        // Compilador 6: bajo FORMA: quedan las frases (la última aplicada de esa ancla) y el tope; la guía del motor, al final.
        $composer = explode("\n", $c['roles']['composer']);
        $i = array_key_first(array_filter($composer, fn (string $l) => str_contains($l, 'FORMA:')));
        $this->assertStringStartsWith('FRASES PROHIBIDAS PUBLICADAS:', $composer[$i + 1]);
        $this->assertStringStartsWith('TOPE DE LONGITUD PUBLICADO:', $composer[$i + 2]);
        $this->assertSame(self::guia(200), end($composer));

        // Y en el revisor, bajo progressive_disclosure: el tope; las dos del motor, las dos últimas.
        $critic = explode("\n", $c['roles']['critic_retry']);
        $j = array_key_first(array_filter($critic, fn (string $l) => str_starts_with($l, 'progressive_disclosure:')));
        $this->assertStringStartsWith('TOPE PUBLICADO:', $critic[$j + 1]);
        $this->assertSame([self::PLANS_CRITIC_COMPRUEBA, self::PLANS_CRITIC], array_slice($critic, -2));
    }

    /**
     * El peor caso del paquete: «profesional», sin emojis, el tope (cualquiera
     * ≠ 320: el texto mide lo mismo con tres cifras), «venta» (el estratega más
     * grande) y 12 frases de 60 caracteres de 2 bytes. Cabe; sus bytes, al byte.
     */
    public function test_el_peor_caso_cabe_y_sus_bytes(): void
    {
        $frases = array_map(static fn (string $l): string => str_repeat('ñ', 59).$l, ['á', 'é', 'í', 'ó', 'ú', 'ü', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü']);
        sort($frases, SORT_STRING);
        $peor = [C::TONE => 'profesional', C::EMOJIS => false, C::MAX_CHARS => 480, C::GOAL => 'venta', C::PHRASES => $frases];
        $c = C::compile($peor, 8);

        $this->assertCount(4 + 4 + 6 + 1 + 4 + 15, $c['patches'], 'tono 4, emojis 4, tope 6, objetivo 1, frases 4 y motor 15');
        $this->assertSame([1887, 1887, 1932, 1932], array_map(static fn (array $x): int => strlen($x['text']), array_values(array_filter($c['patches'], fn (array $x) => $x['parameter'] === C::PHRASES))));
        // Calculado al margen del PHP (Node: la base, `parches-m1.cjs` del compilador 8 y los textos fijos de las frases de §B.3).
        // Con el compilador 4 eran 29 570, 30 876, 18 519, 28 767 y 18 171 (125 903); con el 5, 30 448, 31 669, 18 519, 29 447
        // y 18 171 (128 254); con el 6, 2 070 más: Composer y Reintento 101 cada uno y Critic y Critic 2 934 cada uno (130 324);
        // con el 7, 411 más, todos en el Strategist (la lista; 130 735); con el 8, 676 más: Strategist −90, Composer +250,
        // Composer Reintento +238 y Critic y Critic 2 +139 cada uno.
        $bytes = array_map('strlen', $c['roles']);
        $this->assertSame(['strategist' => 30769, 'composer' => 32020, 'critic' => 19592, 'composer_retry' => 29786, 'critic_retry' => 19244], $bytes);
        $this->assertSame(131411, $c['bytes']);
        $this->assertLessThanOrEqual(C::MAX_ROLE_BYTES, max($bytes));
        $this->assertLessThanOrEqual(C::MAX_BUNDLE_BYTES, $c['bytes']);
    }

    public function test_la_vista_previa_del_studio_no_ensena_el_parche_del_motor(): void
    {
        $g = array_replace(C::BASELINE, [C::TONE => 'profesional', C::MAX_CHARS => 200]);
        $vista = StudioPromptBundle::compile($g);
        $c = C::compile($g, 8);

        $this->assertSame(['persona.tone', 'persona.maxChars'], array_column($vista['changes'], 'param'), 'solo lo gobernado');
        $this->assertSame(count($c['patches']) - 15, array_sum(array_map(fn (array $x) => count($x['patches']), $vista['changes'])));
        $this->assertStringNotContainsString('LISTA DE PLANES DEL BACKEND', json_encode($vista['changes'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(C::compile($g)['bundleHash'], $vista['prompts']['bundleHash'], 'la publicación firma el paquete actual c9 completo; arriba se conservan los parches históricos c8');
        $this->assertSame(C::compile($g)['bytes'], $vista['bytes']);
        $this->assertFalse($vista['baseline']);

        $linea = StudioPromptBundle::compile(C::BASELINE);
        $this->assertSame([[], true, self::LEGACY], [$linea['changes'], $linea['baseline'], $linea['prompts']['bundleHash']]);
    }
}
