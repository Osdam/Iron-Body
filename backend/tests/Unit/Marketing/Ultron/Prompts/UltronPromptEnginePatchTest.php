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
 * se mueve. La versión del compilador sigue en 4 (no está publicado en ningún
 * sitio).
 *
 * Lo que se fija:
 *  1. La línea base: sin parches y con la huella Legacy, escrita a mano.
 *  2. Con CUALQUIER valor no base (los 305 de la rejilla y con frases): los
 *     cuatro parches del motor, los últimos, con sus textos de §7.1 copiados a
 *     mano, en anclas únicas, pegados a su ancla, ≤ 1 200 B; cada rol ≤ 40 960
 *     y el paquete ≤ 163 840.
 *  3. El peor caso (tono, sin emojis, tope, objetivo y 12 frases de 60
 *     caracteres de 2 bytes) cabe, con sus bytes al byte.
 *  4. La vista previa del Studio NO enseña el parche del motor (no es un
 *     parámetro): agrupa por `PARAMETERS`, y la huella que firma sí lo lleva.
 */
class UltronPromptEnginePatchTest extends TestCase
{
    /** §7.1, copiados a mano del plan. */
    private const PLANS_COMPOSER = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales mostrar. Esto manda sobre las reglas de precios y marcadores de este prompt: nombra al menos dos de esos planes, en el orden en que vienen, escribiendo por cada uno SOLO su marcador (PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves), que el backend sustituye por el nombre y el precio reales. No escribas sus nombres ni cifras, no uses PLAN_NAME, PLAN_PRICE ni PLAN_DURATION y no repitas un marcador. Para decir para quien es cada uno usa solo los benefits de su plan_id en active_plans. Si plans_overview no viene, nada de esto aplica.';

    private const PLANS_CRITIC = 'LISTA DE PLANES DEL BACKEND: los marcadores PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves los sustituye el backend por el nombre y el precio reales de planes que el mismo eligio. Cuentan como nombre y como marcador de precio de ese plan: un borrador que los usa para dar varios planes con su precio NO esquiva el precio, no es invented_plan ni invented_price, y nombrar dos o tres no es defecto de progressive_disclosure. Lo demas se juzga como siempre.';

    /** La huella Legacy, escrita a mano (la de `UltronPromptBase::LEGACY_BUNDLE_HASH`). */
    private const LEGACY = '3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902';

    /** Las anclas del parche del motor (`grep -c` en la base: una vez en cada rol). */
    private const ANCLAS = ['composer' => 'FORMA:', 'composer_retry' => 'FORMA:', 'critic' => 'progressive_disclosure:', 'critic_retry' => 'progressive_disclosure:'];

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
        $this->assertSame(4, C::VERSION, 'el compilador 4 no está publicado: no se sube');
        $this->assertSame('engine.plansOverview', C::ENGINE);
        $this->assertNotContains(C::ENGINE, C::PARAMETERS, 'el del motor no es un parámetro gobernado');
    }

    public function test_con_cualquier_valor_no_base_los_cuatro_parches_del_motor_al_final(): void
    {
        $casos = self::noBase();
        $this->assertCount(305, $casos);
        $casos[] = array_replace(C::BASELINE, [C::PHRASES => ['gratis']]);
        $casos[] = array_replace(C::BASELINE, [C::TONE => 'profesional', C::MAX_CHARS => 200, C::PHRASES => ['gratis', 'te lo regalo']]);

        $base = Base::roles();
        foreach ($casos as $g) {
            $p = C::patches($g);
            $nombre = json_encode($g);
            $motor = array_slice($p, -4);
            $this->assertSame([
                ['parameter' => C::ENGINE, 'value' => true, 'role' => 'composer', 'operation' => C::INSERT_AFTER_LINE, 'anchor' => 'FORMA:', 'text' => self::PLANS_COMPOSER],
                ['parameter' => C::ENGINE, 'value' => true, 'role' => 'composer_retry', 'operation' => C::INSERT_AFTER_LINE, 'anchor' => 'FORMA:', 'text' => self::PLANS_COMPOSER],
                ['parameter' => C::ENGINE, 'value' => true, 'role' => 'critic', 'operation' => C::INSERT_AFTER_LINE, 'anchor' => 'progressive_disclosure:', 'text' => self::PLANS_CRITIC],
                ['parameter' => C::ENGINE, 'value' => true, 'role' => 'critic_retry', 'operation' => C::INSERT_AFTER_LINE, 'anchor' => 'progressive_disclosure:', 'text' => self::PLANS_CRITIC],
            ], $motor, $nombre);
            $this->assertCount(4, array_filter($p, fn (array $x) => $x['parameter'] === C::ENGINE), 'una vez cada rol: '.$nombre);
            $this->assertNotSame([], array_filter(array_slice($p, 0, -4), fn (array $x) => in_array($x['parameter'], C::PARAMETERS, true)), 'hay algún parche gobernado delante');

            $c = C::compile($g);
            $this->assertSame($p, $c['patches']);
            foreach (self::ANCLAS as $rol => $ancla) {
                $this->assertSame(1, substr_count($base[$rol], $ancla), "{$rol}: el ancla, una vez en la base");
                $this->assertSame(1, substr_count($c['roles'][$rol], $ancla), "{$rol}: y una vez en lo compilado");
                // Aplicado el último, queda pegado a su ancla: la línea siguiente a la del ancla.
                $lineas = explode("\n", $c['roles'][$rol]);
                $i = array_key_first(array_filter($lineas, fn (string $l) => str_contains($l, $ancla)));
                $this->assertSame(str_starts_with($rol, 'composer') ? self::PLANS_COMPOSER : self::PLANS_CRITIC, $lineas[$i + 1], "{$rol}: {$nombre}");
                $this->assertLessThanOrEqual(C::MAX_ROLE_BYTES, strlen($c['roles'][$rol]));
            }
            $this->assertLessThanOrEqual(C::MAX_BUNDLE_BYTES, $c['bytes']);
            $this->assertSame(['composer', 'critic', 'composer_retry', 'critic_retry'], array_values(array_intersect(C::changedRoles($g), ['composer', 'critic', 'composer_retry', 'critic_retry'])));
            $this->assertFalse(C::isBaseline($g));
        }
    }

    public function test_los_textos_del_motor_al_byte_y_en_su_forma(): void
    {
        $this->assertSame(697, strlen(self::PLANS_COMPOSER));
        $this->assertSame(451, strlen(self::PLANS_CRITIC));
        foreach ([self::PLANS_COMPOSER, self::PLANS_CRITIC] as $t) {
            $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen($t));
            $this->assertMatchesRegularExpression('/\A[\x20-\x7E]+\z/', $t, 'ASCII imprimible, una línea, como el resto');
            $this->assertStringNotContainsString('{', $t, 'sin llaves: no forma un marcador ni un hueco');
            $this->assertStringNotContainsString('}', $t);
            foreach (['COMO SUENAS:', 'FORMA:', 'Emoji opcional y raro, nunca en temas sensibles.', 'naturalness:', 'progressive_disclosure:', 'mas de ~900 caracteres', 'PRINCIPIO:'] as $ancla) {
                $this->assertStringNotContainsString($ancla, $t, 'ningún texto de parche contiene un ancla');
            }
        }
        // Un solo caso: los textos compilados son los de §7.1 y no hay otros del motor.
        $p = C::patches(array_replace(C::BASELINE, [C::GOAL => 'venta']));
        $this->assertSame(['strategy.goal', C::ENGINE, C::ENGINE, C::ENGINE, C::ENGINE], array_column($p, 'parameter'), 'un cambio solo del estratega también lleva el del motor');
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

        $this->assertCount(4 + 4 + 6 + 1 + 4 + 4, $c['patches'], 'tono 4, emojis 4, tope 6, objetivo 1, frases 4 y motor 4');
        $this->assertSame([1887, 1887, 1932, 1932], array_map(static fn (array $x): int => strlen($x['text']), array_values(array_filter($c['patches'], fn (array $x) => $x['parameter'] === C::PHRASES))));
        // Calculado al margen del PHP (Node: la base, `parches-m1.cjs`, los textos fijos de las frases de §B.3 y los dos de §7.1).
        $bytes = array_map('strlen', $c['roles']);
        $this->assertSame(['strategist' => 29570, 'composer' => 30876, 'critic' => 18519, 'composer_retry' => 28767, 'critic_retry' => 18171], $bytes);
        $this->assertSame(125903, $c['bytes']);
        $this->assertLessThanOrEqual(C::MAX_ROLE_BYTES, max($bytes));
        $this->assertLessThanOrEqual(C::MAX_BUNDLE_BYTES, $c['bytes']);
    }

    public function test_la_vista_previa_del_studio_no_ensena_el_parche_del_motor(): void
    {
        $g = array_replace(C::BASELINE, [C::TONE => 'profesional', C::MAX_CHARS => 200]);
        $vista = StudioPromptBundle::compile($g);
        $c = C::compile($g);

        $this->assertSame(['persona.tone', 'persona.maxChars'], array_column($vista['changes'], 'param'), 'solo lo gobernado');
        $this->assertSame(count($c['patches']) - 4, array_sum(array_map(fn (array $x) => count($x['patches']), $vista['changes'])));
        $this->assertStringNotContainsString('LISTA DE PLANES DEL BACKEND', json_encode($vista['changes'], JSON_UNESCAPED_UNICODE));
        $this->assertSame($c['bundleHash'], $vista['prompts']['bundleHash'], 'pero la huella que se firma es la del paquete entero, con el del motor');
        $this->assertSame($c['bytes'], $vista['bytes']);
        $this->assertFalse($vista['baseline']);

        $linea = StudioPromptBundle::compile(C::BASELINE);
        $this->assertSame([[], true, self::LEGACY], [$linea['changes'], $linea['baseline'], $linea['prompts']['bundleHash']]);
    }
}
