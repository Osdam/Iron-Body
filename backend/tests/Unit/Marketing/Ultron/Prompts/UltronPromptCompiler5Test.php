<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase as Base;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as C;
use App\Services\Marketing\Ultron\Prompts\UltronPromptException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * PT-17 · EL COMPILADOR 5: LAS INSTRUCCIONES FIRMADAS DEL TURNO DE PLANES
 * (diseño del arquitecto `plans/informes-motores/diseno-arq-pt17-planes.md`,
 * §5 (a), N1, N2 y N4; Enmienda A4-5 del H5; plan de motores §B.3,
 * «Compilador 5»). N3 (la oferta) está en `UltronPublishedConfigPhrasesTest`.
 *
 * El parche del motor pasa de 4 entradas a 11: una inserción y una sustitución
 * en el estratega, el texto nuevo del redactor (misma ancla `FORMA:`) y tres
 * sustituciones que vuelven condicionales las frases de la base que hoy lo
 * contradicen. El revisor no cambia (`T_PLANS_CRITIC` byte a byte).
 *
 * Lo que se fija (los textos, escritos aquí a mano desde §Textos del diseño):
 *  N1. `compile(v3)` (`profesional · 200 · emojis sí · cita · sin frases`, lo
 *      publicado): el estratega lleva la inserción en la línea siguiente a
 *      `QUE PLAN SE COTIZA` y la sustitución de su l.23; los dos redactores,
 *      el texto nuevo pegado a `FORMA:` y sus sustituciones; los dos revisores,
 *      los bytes del compilador 4; 21 parches.
 *  N2. Una frase prohibida que contenga un ancla nueva no vale (`anchor`). Dos
 *      de las cinco (65 y 107 caracteres) no caben en una frase (máximo 60):
 *      dan `length`, antes y después, y ninguna frase puede contenerlas.
 *  N4. E2 ampliada: en las 305 combinaciones no base y con listas de frases,
 *      cada ancla aparece exactamente una vez en su rol al aplicarse su
 *      parche, ningún texto de parche contiene un ancla (las doce) y el parche
 *      del motor usa en cada rol exactamente sus anclas.
 */
class UltronPromptCompiler5Test extends TestCase
{
    // ── §Textos del diseño, a mano (ASCII, sin tildes ni llaves, una línea) ──

    private const A_COTIZA = 'QUE PLAN SE COTIZA';

    private const A_PRESENTALO = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos.';

    private const A_MARCADOR = 'Cualquier otro marcador hace que el mensaje se rechace.';

    private const A_ELIGE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues.';

    private const A_PRECIO = 'el precio solo del que se cotiza.';

    /** 1 · strategist, insert_after_line tras `QUE PLAN SE COTIZA`. */
    private const PLANS_STRATEGIST = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales se le muestran. En ese turno esto manda sobre las reglas de este apartado, sobre resolved_reference y sobre strategy_hints (suggested_question, should_recommend_now): el turno RESPONDE con esa lista; no descubre ni presenta un solo plan. Entonces next_best_action recommend_plan, conversation_goal recommend, recommendation_goal compare_plans, information_needed [] (ni experiencia, ni objetivo, ni horario), recommended_plan_id el que digan las reglas de abajo (nunca null) y response_goal: dar esos planes en su orden, con un beneficio corto de cada uno y sin cifras. Si plans_overview no viene, nada de esto aplica.';

    /** 2 · strategist, replace de {@see self::A_PRESENTALO}. */
    private const STRATEGIST_PRESENTALO = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos; si commercial_turn_policy.plans_overview trae planes, no: manda LISTA DE PLANES DEL BACKEND.';

    /** 3 y 7 · composer y composer_retry, insert_after_line tras `FORMA:` (sustituye al del compilador 4). */
    private const PLANS_COMPOSER = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales mostrar. En ese turno esto manda sobre todo este prompt (las reglas de precios y marcadores, recomendar un solo plan con su PORQUE, resolved_reference y suggested_question) y sobre la estrategia si habla de un solo plan: la respuesta es esa lista, en una frase. Nombra los planes en el orden en que vienen, escribiendo por cada uno SOLO su marcador (PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves), que el backend sustituye por el nombre y el precio reales, seguido de dos o tres palabras de uno de los benefits que trae ese marcador en plans_overview.plans. No escribas sus nombres ni cifras, no uses PLAN_NAME, PLAN_PRICE ni PLAN_DURATION y no repitas un marcador. Fuera de los marcadores, unas 15 palabras en todo el mensaje: un arranque corto, los beneficios y, como mucho, una pregunta corta para elegir (sobre cual amplio la informacion); nunca su experiencia ni su objetivo. Con un tope publicado de menos de 200 caracteres, solo PLAN_1 y PLAN_2. Si plans_overview no viene, nada de esto aplica.';

    /** 4 y 8 · composer y composer_retry, replace de {@see self::A_MARCADOR}. */
    private const COMPOSER_MARCADOR = 'Cualquier otro marcador hace que el mensaje se rechace, salvo PLAN_1 a PLAN_3 cuando commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND).';

    /** 5 · composer, replace de {@see self::A_ELIGE}. */
    private const COMPOSER_ELIGE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues; si commercial_turn_policy.plans_overview trae planes, en vez de eso das esa lista (LISTA DE PLANES DEL BACKEND).';

    /** 6 y 9 · composer y composer_retry, replace de {@see self::A_PRECIO}. */
    private const COMPOSER_PRECIO = 'el precio solo del que se cotiza o, si commercial_turn_policy.plans_overview trae planes, el de cada plan de esa lista con su marcador.';

    /** 10 y 11 · critic y critic_retry, tras `progressive_disclosure:`: el del compilador 4, sin cambios. */
    private const PLANS_CRITIC = 'LISTA DE PLANES DEL BACKEND: los marcadores PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves los sustituye el backend por el nombre y el precio reales de planes que el mismo eligio. Cuentan como nombre y como marcador de precio de ese plan: un borrador que los usa para dar varios planes con su precio NO esquiva el precio, no es invented_plan ni invented_price, y nombrar dos o tres no es defecto de progressive_disclosure. Lo demas se juzga como siempre.';

    /** El texto del redactor del compilador 4 (histórico): con el 5 ya no va en ningún rol. */
    private const PLANS_COMPOSER_C4 = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales mostrar. Esto manda sobre las reglas de precios y marcadores de este prompt: nombra al menos dos de esos planes, en el orden en que vienen, escribiendo por cada uno SOLO su marcador (PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves), que el backend sustituye por el nombre y el precio reales. No escribas sus nombres ni cifras, no uses PLAN_NAME, PLAN_PRICE ni PLAN_DURATION y no repitas un marcador. Para decir para quien es cada uno usa solo los benefits de su plan_id en active_plans. Si plans_overview no viene, nada de esto aplica.';

    /** Las doce anclas del compilador 5: las siete de siempre y las cinco nuevas. */
    private const ANCLAS = [
        'COMO SUENAS:', 'FORMA:', 'Emoji opcional y raro, nunca en temas sensibles.', 'naturalness:', 'progressive_disclosure:', 'mas de ~900 caracteres', 'PRINCIPIO:',
        self::A_COTIZA, self::A_PRESENTALO, self::A_MARCADOR, self::A_ELIGE, self::A_PRECIO,
    ];

    /**
     * Los revisores de c074 (`profesional · 200`) con el compilador 4, de
     * `compile-cases.json` ANTES de regenerarlo: con el 5 tienen que ser los
     * mismos bytes (el revisor no se toca).
     */
    private const CRITICS_C4 = [
        'critic' => ['e605f3d4b3de743046c725c7cdd36dbe56a25b416c152175926d0e05c9c0da46', 16483],
        'critic_retry' => ['6c1d1750233a05252a5e528a9a95f3ff3afbde4a9c3ef44af80223a5dfda138a', 16135],
    ];

    /** Lo publicado (v3): `profesional · 200 · emojis sí · cita · sin frases`. */
    private static function v3(): array
    {
        return array_replace(C::BASELINE, [C::TONE => 'profesional', C::MAX_CHARS => 200]);
    }

    /** Las 11 entradas del parche del motor, en el orden de §Textos: [rol, operación, ancla, texto]. */
    private static function motor(): array
    {
        return [
            ['strategist', C::INSERT_AFTER_LINE, self::A_COTIZA, self::PLANS_STRATEGIST],
            ['strategist', C::REPLACE, self::A_PRESENTALO, self::STRATEGIST_PRESENTALO],
            ['composer', C::INSERT_AFTER_LINE, 'FORMA:', self::PLANS_COMPOSER],
            ['composer', C::REPLACE, self::A_MARCADOR, self::COMPOSER_MARCADOR],
            ['composer', C::REPLACE, self::A_ELIGE, self::COMPOSER_ELIGE],
            ['composer', C::REPLACE, self::A_PRECIO, self::COMPOSER_PRECIO],
            ['composer_retry', C::INSERT_AFTER_LINE, 'FORMA:', self::PLANS_COMPOSER],
            ['composer_retry', C::REPLACE, self::A_MARCADOR, self::COMPOSER_MARCADOR],
            ['composer_retry', C::REPLACE, self::A_PRECIO, self::COMPOSER_PRECIO],
            ['critic', C::INSERT_AFTER_LINE, 'progressive_disclosure:', self::PLANS_CRITIC],
            ['critic_retry', C::INSERT_AFTER_LINE, 'progressive_disclosure:', self::PLANS_CRITIC],
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

    public function test_n1_lo_publicado_compila_con_las_instrucciones_del_turno_de_planes(): void
    {
        $c = C::compile(self::v3());
        $r = $c['roles'];
        $base = Base::roles();

        // 21 parches: tono 4, tope 6 y el motor 11, este al final y en el orden de §Textos.
        $this->assertCount(21, $c['patches']);
        $this->assertSame([...array_fill(0, 4, C::TONE), ...array_fill(0, 6, C::MAX_CHARS), ...array_fill(0, 11, C::ENGINE)], array_column($c['patches'], 'parameter'));
        $this->assertSame(self::motor(), array_map(static fn (array $p): array => [$p['role'], $p['operation'], $p['anchor'], $p['text']], array_slice($c['patches'], -11)));

        // El estratega: la inserción en la línea siguiente a `QUE PLAN SE COTIZA` y la l.23 sustituida; nada más.
        $this->assertSame(self::PLANS_STRATEGIST, self::lineaTras($r['strategist'], self::A_COTIZA));
        $this->assertSame(0, substr_count($r['strategist'], self::A_PRESENTALO), 'la frase de la l.23 ya no está sola');
        $this->assertSame(1, substr_count($r['strategist'], self::STRATEGIST_PRESENTALO));
        $lineas = explode("\n", $base['strategist']);
        $i = array_key_first(array_filter($lineas, fn (string $l): bool => str_contains($l, self::A_COTIZA)));
        $this->assertSame(17, $i, 'QUE PLAN SE COTIZA es la l.18 de la base');
        $this->assertStringContainsString(self::A_PRESENTALO, $lineas[22], 'y la frase sustituida, la l.23');
        array_splice($lineas, $i + 1, 0, [self::PLANS_STRATEGIST]);
        $this->assertSame(str_replace(self::A_PRESENTALO, self::STRATEGIST_PRESENTALO, implode("\n", $lineas)), $r['strategist'], 'el estratega es la base con esas dos entradas, byte a byte');

        // Los dos redactores: el texto nuevo pegado a `FORMA:` y las frases de la base, condicionales.
        foreach (['composer', 'composer_retry'] as $rol) {
            $this->assertSame(self::PLANS_COMPOSER, self::lineaTras($r[$rol], 'FORMA:'), $rol);
            $this->assertStringNotContainsString(self::PLANS_COMPOSER_C4, $r[$rol], "{$rol}: el texto del compilador 4 ya no va");
            $this->assertSame(1, substr_count($base[$rol], self::A_MARCADOR), "{$rol}: el ancla, una vez en la base");
            $this->assertSame(0, substr_count($r[$rol], self::A_MARCADOR), "{$rol}: «Cualquier otro marcador…» ya no está sola");
            $this->assertSame(1, substr_count($r[$rol], self::COMPOSER_MARCADOR), "{$rol}: está su sustituto");
            $this->assertSame(0, substr_count($r[$rol], self::A_PRECIO), $rol);
            $this->assertSame(1, substr_count($r[$rol], self::COMPOSER_PRECIO), "{$rol}: el de :38");
        }
        $this->assertSame(0, substr_count($r['composer'], self::A_ELIGE));
        $this->assertSame(1, substr_count($r['composer'], self::COMPOSER_ELIGE), 'composer: el de :50');
        $this->assertSame(0, substr_count($base['composer_retry'], 'Elige el plan'), 'el reintento no tiene la frase de :50');
        $this->assertStringNotContainsString(self::COMPOSER_ELIGE, $r['composer_retry']);

        // Los dos revisores: los bytes del compilador 4.
        foreach (self::CRITICS_C4 as $rol => $huella) {
            $this->assertSame($huella, [hash('sha256', $r[$rol]), strlen($r[$rol])], "{$rol}: el revisor no se toca");
            $this->assertSame(self::PLANS_CRITIC, self::lineaTras($r[$rol], 'progressive_disclosure:'), $rol);
        }
        $this->assertSame(5, C::VERSION);
    }

    // ── N2 ──────────────────────────────────────────────────────────────────

    public function test_n2_una_frase_con_un_ancla_nueva_no_vale(): void
    {
        foreach ([self::A_COTIZA, self::A_MARCADOR, self::A_PRECIO] as $ancla) {
            $this->assertLessThanOrEqual(C::PHRASE_MAX_CHARS, mb_strlen($ancla), "«{$ancla}» cabe en una frase");
            $this->assertSame(C::PHRASE_ANCHOR, C::phraseViolation($ancla), "«{$ancla}»");
            $this->assertSame(['reason' => C::PHRASE_ANCHOR, 'index' => 1], C::phrasesViolation(['aaa', $ancla]), "«{$ancla}» en una lista");
            try {
                C::compile(array_replace(C::BASELINE, [C::PHRASES => [$ancla]]));
                $this->fail("«{$ancla}» como frase tenía que lanzar");
            } catch (UltronPromptException $e) {
                $this->assertSame(UltronPromptException::INVALID_PHRASES, $e->reason, "«{$ancla}»: se rechaza la frase, antes de llegar al ancla repetida");
            }
        }
        // Contenida en una frase más larga, también.
        $this->assertSame(C::PHRASE_ANCHOR, C::phraseViolation('nunca QUE PLAN SE COTIZA'));
        $this->assertSame(C::PHRASE_ANCHOR, C::phraseViolation('ojo, el precio solo del que se cotiza.'));
        // Las dos largas no caben en una frase: `length`, y ninguna frase válida puede contenerlas.
        foreach ([self::A_PRESENTALO, self::A_ELIGE] as $ancla) {
            $this->assertGreaterThan(C::PHRASE_MAX_CHARS, mb_strlen($ancla), "«{$ancla}»");
            $this->assertSame(C::PHRASE_LENGTH, C::phraseViolation($ancla), "«{$ancla}»");
        }
        // Lo que no contiene un ancla entera sigue valiendo.
        $this->assertNull(C::phraseViolation('que plan se cotiza'));
        $this->assertNull(C::phraseViolation('el precio solo del que se cotiza'));
    }

    // ── N4 ──────────────────────────────────────────────────────────────────

    public function test_n4_en_toda_combinacion_cada_ancla_una_vez_al_aplicarse_y_ningun_texto_contiene_un_ancla(): void
    {
        $frases = array_map(static fn (string $l): string => str_repeat('ñ', 59).$l, ['á', 'é', 'í', 'ó', 'ú', 'ü', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü']);
        sort($frases, SORT_STRING);
        $casos = self::noBase();
        $this->assertCount(305, $casos);
        $casos[] = array_replace(C::BASELINE, [C::PHRASES => ['gratis']]);
        $casos[] = array_replace(self::v3(), [C::PHRASES => ['gratis', 'te lo regalo']]);
        $casos[] = [C::TONE => 'profesional', C::EMOJIS => false, C::MAX_CHARS => 480, C::GOAL => 'venta', C::PHRASES => $frases];

        $aplicar = new ReflectionMethod(C::class, 'apply');
        $esperadas = [
            'strategist' => [self::A_COTIZA, self::A_PRESENTALO],
            'composer' => ['FORMA:', self::A_MARCADOR, self::A_ELIGE, self::A_PRECIO],
            'composer_retry' => ['FORMA:', self::A_MARCADOR, self::A_PRECIO],
            'critic' => ['progressive_disclosure:'],
            'critic_retry' => ['progressive_disclosure:'],
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

    /** Los seis textos nuevos: sus bytes (los del diseño), su forma y su tope. */
    public function test_los_textos_nuevos_al_byte_y_en_su_forma(): void
    {
        $textos = [self::PLANS_STRATEGIST, self::STRATEGIST_PRESENTALO, self::PLANS_COMPOSER, self::COMPOSER_MARCADOR, self::COMPOSER_ELIGE, self::COMPOSER_PRECIO];
        $this->assertSame([784, 158, 1165, 165, 220, 135], array_map('strlen', $textos));
        $this->assertSame(451, strlen(self::PLANS_CRITIC));
        foreach ([...$textos, self::PLANS_CRITIC] as $t) {
            $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen($t));
            $this->assertMatchesRegularExpression('/\A[\x20-\x7E]+\z/', $t, 'ASCII imprimible, una línea, como el resto');
            $this->assertStringNotContainsString('{', $t, 'sin llaves: no forma un marcador ni un hueco');
            $this->assertStringNotContainsString('}', $t);
        }
        // Los sustitutos no contienen su ancla (cambia el signo final).
        foreach ([[self::A_PRESENTALO, self::STRATEGIST_PRESENTALO], [self::A_MARCADOR, self::COMPOSER_MARCADOR], [self::A_ELIGE, self::COMPOSER_ELIGE], [self::A_PRECIO, self::COMPOSER_PRECIO]] as [$ancla, $sustituto]) {
            $this->assertStringNotContainsString($ancla, $sustituto);
            $this->assertStringStartsWith(substr($ancla, 0, -1), $sustituto, 'empieza por la frase de la base sin su punto');
        }
    }
}
