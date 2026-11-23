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
 * Critic como en el 4). Lo que se fija abajo es ya el del 5.
 *
 * Lo que se fija:
 *  1. La línea base: sin parches y con la huella Legacy, escrita a mano.
 *  2. Con CUALQUIER valor no base (los 305 de la rejilla y con frases): los
 *     once parches del motor, los últimos, con sus textos de §B.3 («Compilador
 *     5») copiados a mano, en anclas únicas; las inserciones pegadas a su
 *     ancla y las sustituciones en lugar de su frase; ≤ 1 200 B; cada rol
 *     ≤ 40 960 y el paquete ≤ 163 840.
 *  3. El peor caso (tono, sin emojis, tope, objetivo y 12 frases de 60
 *     caracteres de 2 bytes) cabe, con sus bytes al byte.
 *  4. La vista previa del Studio NO enseña el parche del motor (no es un
 *     parámetro): agrupa por `PARAMETERS`, y la huella que firma sí lo lleva.
 */
class UltronPromptEnginePatchTest extends TestCase
{
    /** §B.3, «Compilador 5» (§Textos del diseño de PT-17), copiados a mano. El del redactor sustituye al de §7.1. */
    private const PLANS_STRATEGIST = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales se le muestran. En ese turno esto manda sobre las reglas de este apartado, sobre resolved_reference y sobre strategy_hints (suggested_question, should_recommend_now): el turno RESPONDE con esa lista; no descubre ni presenta un solo plan. Entonces next_best_action recommend_plan, conversation_goal recommend, recommendation_goal compare_plans, information_needed [] (ni experiencia, ni objetivo, ni horario), recommended_plan_id el que digan las reglas de abajo (nunca null) y response_goal: dar esos planes en su orden, con un beneficio corto de cada uno y sin cifras. Si plans_overview no viene, nada de esto aplica.';

    private const PLANS_STRATEGIST_PRESENTALO = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos; si commercial_turn_policy.plans_overview trae planes, no: manda LISTA DE PLANES DEL BACKEND.';

    private const PLANS_COMPOSER = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales mostrar. En ese turno esto manda sobre todo este prompt (las reglas de precios y marcadores, recomendar un solo plan con su PORQUE, resolved_reference y suggested_question) y sobre la estrategia si habla de un solo plan: la respuesta es esa lista, en una frase. Nombra los planes en el orden en que vienen, escribiendo por cada uno SOLO su marcador (PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves), que el backend sustituye por el nombre y el precio reales, seguido de dos o tres palabras de uno de los benefits que trae ese marcador en plans_overview.plans. No escribas sus nombres ni cifras, no uses PLAN_NAME, PLAN_PRICE ni PLAN_DURATION y no repitas un marcador. Fuera de los marcadores, unas 15 palabras en todo el mensaje: un arranque corto, los beneficios y, como mucho, una pregunta corta para elegir (sobre cual amplio la informacion); nunca su experiencia ni su objetivo. Con un tope publicado de menos de 200 caracteres, solo PLAN_1 y PLAN_2. Si plans_overview no viene, nada de esto aplica.';

    private const PLANS_MARCADOR = 'Cualquier otro marcador hace que el mensaje se rechace, salvo PLAN_1 a PLAN_3 cuando commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND).';

    private const PLANS_ELIGE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues; si commercial_turn_policy.plans_overview trae planes, en vez de eso das esa lista (LISTA DE PLANES DEL BACKEND).';

    private const PLANS_PRECIO = 'el precio solo del que se cotiza o, si commercial_turn_policy.plans_overview trae planes, el de cada plan de esa lista con su marcador.';

    private const PLANS_CRITIC = 'LISTA DE PLANES DEL BACKEND: los marcadores PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves los sustituye el backend por el nombre y el precio reales de planes que el mismo eligio. Cuentan como nombre y como marcador de precio de ese plan: un borrador que los usa para dar varios planes con su precio NO esquiva el precio, no es invented_plan ni invented_price, y nombrar dos o tres no es defecto de progressive_disclosure. Lo demas se juzga como siempre.';

    /** La huella Legacy, escrita a mano (la de `UltronPromptBase::LEGACY_BUNDLE_HASH`). */
    private const LEGACY = '3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902';

    /** Las anclas de las inserciones del parche del motor (`grep -c` en la base: una vez en cada rol) y el texto que queda pegado a cada una. */
    private const ANCLAS = ['strategist' => ['QUE PLAN SE COTIZA', self::PLANS_STRATEGIST], 'composer' => ['FORMA:', self::PLANS_COMPOSER], 'composer_retry' => ['FORMA:', self::PLANS_COMPOSER], 'critic' => ['progressive_disclosure:', self::PLANS_CRITIC], 'critic_retry' => ['progressive_disclosure:', self::PLANS_CRITIC]];

    /** Las sustituciones del parche del motor: rol → [frase de la base, su sustituto]. */
    private const SUSTITUCIONES = [
        'strategist' => [['Presentalo con dos o tres beneficios reales, nunca con adjetivos.', self::PLANS_STRATEGIST_PRESENTALO]],
        'composer' => [['Cualquier otro marcador hace que el mensaje se rechace.', self::PLANS_MARCADOR], ['Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues.', self::PLANS_ELIGE], ['el precio solo del que se cotiza.', self::PLANS_PRECIO]],
        'composer_retry' => [['Cualquier otro marcador hace que el mensaje se rechace.', self::PLANS_MARCADOR], ['el precio solo del que se cotiza.', self::PLANS_PRECIO]],
    ];

    /** Las once entradas del parche del motor, en su orden, como las enumera `patches()`. */
    private static function motor(): array
    {
        $m = static fn (string $rol, string $op, string $ancla, string $texto): array => ['parameter' => C::ENGINE, 'value' => true, 'role' => $rol, 'operation' => $op, 'anchor' => $ancla, 'text' => $texto];
        $s = self::SUSTITUCIONES;

        return [
            $m('strategist', C::INSERT_AFTER_LINE, 'QUE PLAN SE COTIZA', self::PLANS_STRATEGIST),
            $m('strategist', C::REPLACE, $s['strategist'][0][0], $s['strategist'][0][1]),
            $m('composer', C::INSERT_AFTER_LINE, 'FORMA:', self::PLANS_COMPOSER),
            $m('composer', C::REPLACE, $s['composer'][0][0], $s['composer'][0][1]),
            $m('composer', C::REPLACE, $s['composer'][1][0], $s['composer'][1][1]),
            $m('composer', C::REPLACE, $s['composer'][2][0], $s['composer'][2][1]),
            $m('composer_retry', C::INSERT_AFTER_LINE, 'FORMA:', self::PLANS_COMPOSER),
            $m('composer_retry', C::REPLACE, $s['composer_retry'][0][0], $s['composer_retry'][0][1]),
            $m('composer_retry', C::REPLACE, $s['composer_retry'][1][0], $s['composer_retry'][1][1]),
            $m('critic', C::INSERT_AFTER_LINE, 'progressive_disclosure:', self::PLANS_CRITIC),
            $m('critic_retry', C::INSERT_AFTER_LINE, 'progressive_disclosure:', self::PLANS_CRITIC),
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
        $c = C::compile(C::BASELINE);
        $this->assertSame([], $c['patches']);
        $this->assertSame(Base::roles(), $c['roles'], 'byte a byte la base');
        $this->assertSame(self::LEGACY, $c['bundleHash']);
        $this->assertSame(self::LEGACY, Base::LEGACY_BUNDLE_HASH);
        $this->assertSame([], C::patches(C::BASELINE));
        $this->assertSame([], C::changedRoles(C::BASELINE));
        $this->assertTrue(C::isBaseline(C::BASELINE));
        $this->assertSame(5, C::VERSION, 'el 4 llegó a la v3 publicada: cambiar el parche del motor obliga a subirla (Enmienda A4-5)');
        $this->assertSame('engine.plansOverview', C::ENGINE);
        $this->assertNotContains(C::ENGINE, C::PARAMETERS, 'el del motor no es un parámetro gobernado');
    }

    public function test_con_cualquier_valor_no_base_los_once_parches_del_motor_al_final(): void
    {
        $casos = self::noBase();
        $this->assertCount(305, $casos);
        $casos[] = array_replace(C::BASELINE, [C::PHRASES => ['gratis']]);
        $casos[] = array_replace(C::BASELINE, [C::TONE => 'profesional', C::MAX_CHARS => 200, C::PHRASES => ['gratis', 'te lo regalo']]);

        $base = Base::roles();
        foreach ($casos as $g) {
            $p = C::patches($g);
            $nombre = json_encode($g);
            $this->assertSame(self::motor(), array_slice($p, -11), $nombre);
            $this->assertCount(11, array_filter($p, fn (array $x) => $x['parameter'] === C::ENGINE), 'las once, una vez: '.$nombre);
            $this->assertNotSame([], array_filter(array_slice($p, 0, -11), fn (array $x) => in_array($x['parameter'], C::PARAMETERS, true)), 'hay algún parche gobernado delante');

            $c = C::compile($g);
            $this->assertSame($p, $c['patches']);
            foreach (self::ANCLAS as $rol => [$ancla, $texto]) {
                $this->assertSame(1, substr_count($base[$rol], $ancla), "{$rol}: el ancla, una vez en la base");
                $this->assertSame(1, substr_count($c['roles'][$rol], $ancla), "{$rol}: y una vez en lo compilado");
                // Aplicado el último de su ancla, queda pegado a ella: la línea siguiente a la del ancla.
                $lineas = explode("\n", $c['roles'][$rol]);
                $i = array_key_first(array_filter($lineas, fn (string $l) => str_contains($l, $ancla)));
                $this->assertSame($texto, $lineas[$i + 1], "{$rol}: {$nombre}");
                $this->assertSame(1, substr_count($c['roles'][$rol], $texto), "{$rol}: una vez");
                $this->assertLessThanOrEqual(C::MAX_ROLE_BYTES, strlen($c['roles'][$rol]));
            }
            // Las sustituidas: una vez en la base, ninguna en lo compilado, y su sustituto una vez.
            foreach (self::SUSTITUCIONES as $rol => $pares) {
                foreach ($pares as [$frase, $sustituto]) {
                    $this->assertSame([1, 0, 1], [substr_count($base[$rol], $frase), substr_count($c['roles'][$rol], $frase), substr_count($c['roles'][$rol], $sustituto)], "{$rol}: «{$frase}» · {$nombre}");
                }
            }
            $this->assertLessThanOrEqual(C::MAX_BUNDLE_BYTES, $c['bytes']);
            $this->assertSame(Base::ROLES, C::changedRoles($g), 'compilador 5: el motor toca los cinco roles');
            $this->assertFalse(C::isBaseline($g));
        }
    }

    public function test_los_textos_del_motor_al_byte_y_en_su_forma(): void
    {
        $this->assertSame([784, 158, 1165, 165, 220, 135, 451], array_map('strlen', [self::PLANS_STRATEGIST, self::PLANS_STRATEGIST_PRESENTALO, self::PLANS_COMPOSER, self::PLANS_MARCADOR, self::PLANS_ELIGE, self::PLANS_PRECIO, self::PLANS_CRITIC]));
        foreach ([self::PLANS_STRATEGIST, self::PLANS_STRATEGIST_PRESENTALO, self::PLANS_COMPOSER, self::PLANS_MARCADOR, self::PLANS_ELIGE, self::PLANS_PRECIO, self::PLANS_CRITIC] as $t) {
            $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen($t));
            $this->assertMatchesRegularExpression('/\A[\x20-\x7E]+\z/', $t, 'ASCII imprimible, una línea, como el resto');
            $this->assertStringNotContainsString('{', $t, 'sin llaves: no forma un marcador ni un hueco');
            $this->assertStringNotContainsString('}', $t);
            foreach (['COMO SUENAS:', 'FORMA:', 'Emoji opcional y raro, nunca en temas sensibles.', 'naturalness:', 'progressive_disclosure:', 'mas de ~900 caracteres', 'PRINCIPIO:', 'QUE PLAN SE COTIZA', 'Presentalo con dos o tres beneficios reales, nunca con adjetivos.', 'Cualquier otro marcador hace que el mensaje se rechace.', 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues.', 'el precio solo del que se cotiza.'] as $ancla) {
                $this->assertStringNotContainsString($ancla, $t, 'ningún texto de parche contiene un ancla');
            }
        }
        // Un solo caso: los textos compilados son los de §B.3 («Compilador 5») y no hay otros del motor.
        $p = C::patches(array_replace(C::BASELINE, [C::GOAL => 'venta']));
        $this->assertSame(['strategy.goal', ...array_fill(0, 11, C::ENGINE)], array_column($p, 'parameter'), 'un cambio solo del estratega también lleva el del motor');
        $this->assertSame(self::motor(), array_slice($p, 1));
    }

    public function test_el_orden_tras_la_misma_ancla_con_el_tope_y_las_frases(): void
    {
        $c = C::compile(array_replace(C::BASELINE, [C::TONE => 'profesional', C::EMOJIS => false, C::MAX_CHARS => 200, C::PHRASES => ['gratis']]));
        $composer = explode("\n", $c['roles']['composer']);
        $i = array_key_first(array_filter($composer, fn (string $l) => str_contains($l, 'FORMA:')));
        $this->assertStringStartsWith('LISTA DE PLANES DEL BACKEND:', $composer[$i + 1]);
        $this->assertStringStartsWith('FRASES PROHIBIDAS PUBLICADAS:', $composer[$i + 2]);
        $this->assertStringStartsWith('TOPE DE LONGITUD PUBLICADO:', $composer[$i + 3]);

        $critic = explode("\n", $c['roles']['critic_retry']);
        $j = array_key_first(array_filter($critic, fn (string $l) => str_starts_with($l, 'progressive_disclosure:')));
        $this->assertStringStartsWith('LISTA DE PLANES DEL BACKEND:', $critic[$j + 1]);
        $this->assertStringStartsWith('TOPE PUBLICADO:', $critic[$j + 2]);
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
        $c = C::compile($peor);

        $this->assertCount(4 + 4 + 6 + 1 + 4 + 11, $c['patches'], 'tono 4, emojis 4, tope 6, objetivo 1, frases 4 y motor 11');
        $this->assertSame([1887, 1887, 1932, 1932], array_map(static fn (array $x): int => strlen($x['text']), array_values(array_filter($c['patches'], fn (array $x) => $x['parameter'] === C::PHRASES))));
        // Calculado al margen del PHP (Node: la base, `parches-m1.cjs` del compilador 5 y los textos fijos de las frases de §B.3).
        // Con el compilador 4 eran 29 570, 30 876, 18 519, 28 767 y 18 171 (125 903): 2 351 más, todo en Strategist y redactores.
        $bytes = array_map('strlen', $c['roles']);
        $this->assertSame(['strategist' => 30448, 'composer' => 31669, 'critic' => 18519, 'composer_retry' => 29447, 'critic_retry' => 18171], $bytes);
        $this->assertSame(128254, $c['bytes']);
        $this->assertLessThanOrEqual(C::MAX_ROLE_BYTES, max($bytes));
        $this->assertLessThanOrEqual(C::MAX_BUNDLE_BYTES, $c['bytes']);
    }

    public function test_la_vista_previa_del_studio_no_ensena_el_parche_del_motor(): void
    {
        $g = array_replace(C::BASELINE, [C::TONE => 'profesional', C::MAX_CHARS => 200]);
        $vista = StudioPromptBundle::compile($g);
        $c = C::compile($g);

        $this->assertSame(['persona.tone', 'persona.maxChars'], array_column($vista['changes'], 'param'), 'solo lo gobernado');
        $this->assertSame(count($c['patches']) - 11, array_sum(array_map(fn (array $x) => count($x['patches']), $vista['changes'])));
        $this->assertStringNotContainsString('LISTA DE PLANES DEL BACKEND', json_encode($vista['changes'], JSON_UNESCAPED_UNICODE));
        $this->assertSame($c['bundleHash'], $vista['prompts']['bundleHash'], 'pero la huella que se firma es la del paquete entero, con el del motor');
        $this->assertSame($c['bytes'], $vista['bytes']);
        $this->assertFalse($vista['baseline']);

        $linea = StudioPromptBundle::compile(C::BASELINE);
        $this->assertSame([[], true, self::LEGACY], [$linea['changes'], $linea['baseline'], $linea['prompts']['bundleHash']]);
    }
}
