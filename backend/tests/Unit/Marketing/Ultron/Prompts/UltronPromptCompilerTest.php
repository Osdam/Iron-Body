<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase as Base;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as C;
use App\Services\Marketing\Ultron\Prompts\UltronPromptException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * E2 · EL COMPILADOR (plan de motores §B.3; Enmienda M-1 del H5, M1-3 y M1-4).
 *
 * Lo que se fija:
 *  1. LA PROPIEDAD CENTRAL: `compile(línea base)` es la base byte a byte y su
 *     huella es `LEGACY_BUNDLE_HASH`; sin un solo parche.
 *  2. Cada valor cambia SOLO sus roles; los demás quedan idénticos a la base.
 *  3. Los textos son los aprobados (escritos aquí a mano desde el plan, no
 *     copiados de la clase) y quedan donde dice §B.3.
 *  4. Las anclas aparecen exactamente una vez en la base, y siguen siendo
 *     únicas con cualquier combinación (las 306 compilan, cada una con su
 *     huella).
 *  5. Determinismo: el orden de las claves no cambia nada.
 *  6. Los 17 valores de `maxChars`; los valores fuera del contrato lanzan; un
 *     ancla ausente o repetida lanza; los topes.
 *  7. Compilador 3 (Enmienda A4-1 del H5, cierre V2, apartado 4): el texto del
 *     tope para el redactor pide un margen (`M`, `P` y `F`, de la tabla de
 *     abajo escrita a mano desde el plan §B.3, «Compilador 3»); el del Critic
 *     no cambia y la línea base sigue sin parche.
 *  8. Compilador 4 (Enmienda A4-2 del H5, cierre V2, apartado 4; H4-9): los dos
 *     textos del tono «profesional» (el del redactor manda sobre TODO el prompt
 *     y da las equivalencias de usted; el del revisor hace FALLAR el tuteo),
 *     escritos aquí a mano desde el plan §B.3, «Compilador 4». Solo se movían
 *     las huellas con `profesional`; Legacy, `cercano` y `entusiasta`, no.
 *  9. Compilador 4, el parche del MOTOR («regenerar antes de sustituir»; plan
 *     `plan-arquitecto-a4-regenerar.md` §7.1 y §B.3, «Compilador 4»): con
 *     cualquier valor no base se añaden al final los cuatro de la lista de
 *     planes (Composer y Reintento tras `FORMA:`, los dos Critic tras
 *     `progressive_disclosure:`), pegados a su ancla; mueven TODAS las huellas
 *     no base. La línea base y la huella Legacy, no. Lo fija además
 *     `UltronPromptEnginePatchTest`; aquí, cada texto de su sitio se lee
 *     quitando antes la línea del motor ({@see self::sinMotor()}).
 * 10. Compilador 5 (Enmienda A4-5 del H5; PT-17, `diseno-arq-pt17-planes.md`;
 *     §B.3, «Compilador 5»): el parche del motor pasa a once entradas: la
 *     inserción y la sustitución del Strategist, el texto nuevo del redactor
 *     tras `FORMA:` y tres sustituciones en los redactores (dos en el
 *     reintento); los Critic, igual. El Strategist cambia en TODO paquete no
 *     base. {@see self::sinMotor()} deshace las once entradas, así que «cada
 *     valor cambia solo sus roles» se sigue leyendo sobre lo gobernado; las
 *     anclas son doce (las siete de siempre y las cinco nuevas).
 */
class UltronPromptCompilerTest extends TestCase
{
    /**
     * Compilador 3: N → [M, P, F], escrita a mano (plan §B.3, «Compilador 3»):
     * M = el 80 % de N redondeado a la decena por abajo, P = intdiv(M, 30) · 5
     * y F = max(2, intdiv(M + 30, 60)). Los 16 topes distintos de 320.
     */
    private const MARGEN = [
        160 => [120, 20, 2],
        180 => [140, 20, 2],
        200 => [160, 25, 3],
        220 => [170, 25, 3],
        240 => [190, 30, 3],
        260 => [200, 30, 3],
        280 => [220, 35, 4],
        300 => [240, 40, 4],
        340 => [270, 45, 5],
        360 => [280, 45, 5],
        380 => [300, 50, 5],
        400 => [320, 50, 5],
        420 => [330, 55, 6],
        440 => [350, 55, 6],
        460 => [360, 60, 6],
        480 => [380, 60, 6],
    ];

    // Los textos aprobados (STUDIO_MOTORES_PLAN.md §B.3, Enmienda M-1, M1-3), a mano.
    // Los dos del tono «profesional» son los del compilador 4 (§B.3, «Compilador 4»,
    // Enmienda A4-2): sustituyen a los de los compiladores 1 a 3.
    private const PRO_COMPOSER = 'REGISTRO PUBLICADO: profesional. Esto manda sobre TODO este prompt, tambien sobre los ejemplos de abajo, knowledge_base.tone, suggested_question y approved_brand_copy: trata a la persona de usted en cada frase, sin mezclar. Los ejemplos se dicen de usted: "Te entiendo" es "Le entiendo", "tu objetivo" es "su objetivo", "te paso" es "le paso", "cuentame" es "cuenteme", "quieres" es "quiere". Nunca "te", "tu", "tus", "ti" ni "contigo". Frases completas y sobrias, sin muletillas coloquiales ("De una", "Listo", "Tranquilo") ni exclamaciones seguidas. Sigue siendo calido; profesional no es frio. El backend comprueba el registro antes de enviar y descarta el mensaje que tutee.';

    private const PRO_CRITIC = 'REGISTRO PUBLICADO: profesional. Tratar de usted NO es defecto. Si el borrador tutea en cualquier frase o mezcla tu y usted ("te", "tu", "tus", "ti", "contigo", "quieres", "puedes", "cuentame", "dime"), verdict fail con naturalness y notes "pasar todo el mensaje a usted". Lo que va entre comillas citando a la persona no cuenta.';

    private const ENT_COMPOSER = 'REGISTRO PUBLICADO: entusiasta. Mas energia: celebra lo que la persona quiere lograr y transmite ganas de acompanarla. Sin exagerar, sin prometer resultados y sin urgencia ni presion.';

    private const ENT_CRITIC = 'REGISTRO PUBLICADO: entusiasta. La energia NO es defecto de naturalness; la urgencia o la presion siguen siendo PRESSURE.';

    private const EMOJI_BASE = 'Emoji opcional y raro, nunca en temas sensibles.';

    private const EMOJI_OFF_COMPOSER = 'Sin emojis: ninguno, en ningun mensaje.';

    private const EMOJI_OFF_CRITIC = 'EMOJIS: la version publicada no usa emojis; si el borrador lleva alguno, verdict fail con naturalness.';

    private const VENTA = 'OBJETIVO COMERCIAL PUBLICADO: cerrar membresia. Con senales claras de compra (buying_signal strong, hot_lead_fast_path o READY) prioriza recommend y close sobre ofrecer la visita; el dia de cortesia queda para quien desconfia o quiere conocer antes. Nada de esto autoriza presion, descuentos ni salirse de allowed_transitions.';

    private const INFORMAR = 'OBJETIVO COMERCIAL PUBLICADO: informar y cualificar. Prioriza understand y recommend; no propongas close ni collect_payment salvo que la persona pida comprar o pagar con palabras.';

    /**
     * Compilador 5, el parche del motor (§B.3, «Compilador 5»; §Textos del diseño de PT-17), a mano.
     * El del redactor sustituye al del compilador 4; el del revisor no cambia.
     */
    private const MOTOR_STRATEGIST = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales se le muestran. En ese turno esto manda sobre las reglas de este apartado, sobre resolved_reference y sobre strategy_hints (suggested_question, should_recommend_now): el turno RESPONDE con esa lista; no descubre ni presenta un solo plan. Entonces next_best_action recommend_plan, conversation_goal recommend, recommendation_goal compare_plans, information_needed [] (ni experiencia, ni objetivo, ni horario), recommended_plan_id el que digan las reglas de abajo (nunca null) y response_goal: dar esos planes en su orden, con un beneficio corto de cada uno y sin cifras. Si plans_overview no viene, nada de esto aplica.';

    private const MOTOR_STRATEGIST_PRESENTALO = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos; si commercial_turn_policy.plans_overview trae planes, no: manda LISTA DE PLANES DEL BACKEND.';

    private const MOTOR_COMPOSER = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales mostrar. En ese turno esto manda sobre todo este prompt (las reglas de precios y marcadores, recomendar un solo plan con su PORQUE, resolved_reference y suggested_question) y sobre la estrategia si habla de un solo plan: la respuesta es esa lista, en una frase. Nombra los planes en el orden en que vienen, escribiendo por cada uno SOLO su marcador (PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves), que el backend sustituye por el nombre y el precio reales, seguido de dos o tres palabras de uno de los benefits que trae ese marcador en plans_overview.plans. No escribas sus nombres ni cifras, no uses PLAN_NAME, PLAN_PRICE ni PLAN_DURATION y no repitas un marcador. Fuera de los marcadores, unas 15 palabras en todo el mensaje: un arranque corto, los beneficios y, como mucho, una pregunta corta para elegir (sobre cual amplio la informacion); nunca su experiencia ni su objetivo. Con un tope publicado de menos de 200 caracteres, solo PLAN_1 y PLAN_2. Si plans_overview no viene, nada de esto aplica.';

    private const MOTOR_MARCADOR = 'Cualquier otro marcador hace que el mensaje se rechace, salvo PLAN_1 a PLAN_3 cuando commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND).';

    private const MOTOR_ELIGE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues; si commercial_turn_policy.plans_overview trae planes, en vez de eso das esa lista (LISTA DE PLANES DEL BACKEND).';

    private const MOTOR_PRECIO = 'el precio solo del que se cotiza o, si commercial_turn_policy.plans_overview trae planes, el de cada plan de esa lista con su marcador.';

    /** Las cinco anclas nuevas del compilador 5 (§B.3, «Compilador 5»). */
    private const A_COTIZA = 'QUE PLAN SE COTIZA';

    private const A_PRESENTALO = 'Presentalo con dos o tres beneficios reales, nunca con adjetivos.';

    private const A_MARCADOR = 'Cualquier otro marcador hace que el mensaje se rechace.';

    private const A_ELIGE = 'Elige el plan que mejor encaje, di su nombre y su precio con los marcadores, y si quieres pregunta despues.';

    private const A_PRECIO = 'el precio solo del que se cotiza.';

    /** Las doce anclas: ningún texto de parche contiene ninguna. */
    private const ANCLAS = ['COMO SUENAS:', 'FORMA:', 'Emoji opcional y raro, nunca en temas sensibles.', 'naturalness:', 'progressive_disclosure:', 'mas de ~900 caracteres', 'PRINCIPIO:', self::A_COTIZA, self::A_PRESENTALO, self::A_MARCADOR, self::A_ELIGE, self::A_PRECIO];

    private const MOTOR_CRITIC = 'LISTA DE PLANES DEL BACKEND: los marcadores PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves los sustituye el backend por el nombre y el precio reales de planes que el mismo eligio. Cuentan como nombre y como marcador de precio de ese plan: un borrador que los usa para dar varios planes con su precio NO esquiva el precio, no es invented_plan ni invented_price, y nombrar dos o tres no es defecto de progressive_disclosure. Lo demas se juzga como siempre.';

    /** La línea base del compilador 2: las cuatro de siempre y ninguna frase (en el orden del contrato). */
    private const BASE = ['persona.emojis' => true, 'persona.maxChars' => 320, 'persona.tone' => 'cercano', 'rules.forbiddenPhrases' => [], 'strategy.goal' => 'cita'];

    /** Compilador 3: el texto del tope para el redactor, con N y su margen de {@see self::MARGEN}. */
    private static function maxComposer(int $n): string
    {
        [$m, $p, $f] = self::MARGEN[$n];

        return "TOPE DE LONGITUD PUBLICADO: el limite es {$n} caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos {$m} caracteres, que son unas {$p} palabras: como mucho {$f} frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar.";
    }

    private static function maxCritic(int $n): string
    {
        return "TOPE PUBLICADO: un borrador de mas de ~{$n} caracteres falla por progressive_disclosure aunque se haya pedido detalle.";
    }

    /** @param array<string,mixed> $cambios */
    private static function con(array $cambios): array
    {
        return array_replace(self::BASE, $cambios);
    }

    /**
     * El rol sin el parche del motor (va en todo paquete no base; se comprueba en
     * su prueba): quita sus líneas insertadas y devuelve las frases sustituidas a
     * las de la base. Compilador 5: también en el Strategist.
     */
    private static function sinMotor(string $rol, string $texto): string
    {
        return match ($rol) {
            'strategist' => str_replace(["\n".self::MOTOR_STRATEGIST, self::MOTOR_STRATEGIST_PRESENTALO], ['', self::A_PRESENTALO], $texto),
            'composer', 'composer_retry' => str_replace(["\n".self::MOTOR_COMPOSER, self::MOTOR_MARCADOR, self::MOTOR_ELIGE, self::MOTOR_PRECIO], ['', self::A_MARCADOR, self::A_ELIGE, self::A_PRECIO], $texto),
            default => str_replace("\n".self::MOTOR_CRITIC, '', $texto),
        };
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

    // ── 1 · La propiedad central ──────────────────────────────────────────────

    public function test_la_linea_base_compila_a_la_base_byte_a_byte_y_a_la_huella_legacy(): void
    {
        $paquete = C::compile(self::BASE);

        $this->assertSame(Base::roles(), $paquete['roles']);
        foreach (Base::ROLES as $rol) {
            $this->assertSame(Base::SHA256[$rol], hash('sha256', $paquete['roles'][$rol]), $rol);
        }
        $this->assertSame(Base::LEGACY_BUNDLE_HASH, $paquete['bundleHash']);
        $this->assertSame([], $paquete['patches']);
        $this->assertSame(112216, $paquete['bytes']);
        $this->assertSame(['contract' => 'ultron-prompts/1', 'base' => 'n8n-29de5119', 'compiler' => 5], array_intersect_key($paquete, array_flip(['contract', 'base', 'compiler'])));
        $this->assertSame(self::BASE, $paquete['governed']);
        $this->assertSame(self::BASE, C::BASELINE);
        $this->assertTrue(C::isBaseline(self::BASE));
        $this->assertSame([], C::changedRoles(self::BASE));
        $this->assertSame(5, C::VERSION, 'la 2 añade las frases de las reglas S-NNN; la 3, el margen del tope para el redactor; la 4, los textos del tono profesional; la 5, el parche del motor del turno de planes');
        $this->assertSame('3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902', $paquete['bundleHash'], 'la huella Legacy, escrita a mano: ni el compilador 3, ni el 4, ni el 5 la mueven');
        $this->assertSame(['contract', 'base', 'compiler', 'governed', 'roles', 'bundleHash', 'bytes', 'patches'], array_keys($paquete));
        $this->assertSame(Base::ROLES, array_keys($paquete['roles']));
    }

    public function test_la_huella_del_paquete_es_la_de_la_base_sobre_sus_roles(): void
    {
        $paquete = C::compile(self::con(['persona.tone' => 'profesional', 'persona.maxChars' => 200]));

        $this->assertSame(Base::bundleHash($paquete['roles']), $paquete['bundleHash']);
        $this->assertNotSame(Base::LEGACY_BUNDLE_HASH, $paquete['bundleHash']);
        $this->assertSame(array_sum(array_map('strlen', $paquete['roles'])), $paquete['bytes']);
    }

    // ── 2 · Cada valor cambia solo sus roles ──────────────────────────────────

    /** @return array<string, array{array<string,mixed>, list<string>}> */
    public static function valoresSueltos(): array
    {
        $cuatro = ['composer', 'critic', 'composer_retry', 'critic_retry'];

        return [
            'tone=profesional' => [['persona.tone' => 'profesional'], $cuatro],
            'tone=entusiasta' => [['persona.tone' => 'entusiasta'], $cuatro],
            'emojis=false' => [['persona.emojis' => false], $cuatro],
            'maxChars=160' => [['persona.maxChars' => 160], $cuatro],
            'maxChars=480' => [['persona.maxChars' => 480], $cuatro],
            // Los roles de lo GOBERNADO; el parche del motor toca además los cinco (compilador 5: también el Strategist).
            'goal=venta' => [['strategy.goal' => 'venta'], ['strategist']],
            'goal=informar' => [['strategy.goal' => 'informar'], ['strategist']],
        ];
    }

    /**
     * @param  array<string,mixed>  $cambio
     * @param  list<string>  $tocados
     */
    #[DataProvider('valoresSueltos')]
    public function test_cada_valor_cambia_solo_sus_roles(array $cambio, array $tocados): void
    {
        $base = Base::roles();
        $paquete = C::compile(self::con($cambio));

        $this->assertSame(Base::ROLES, C::changedRoles(self::con($cambio)), 'con el parche del motor, los cinco');
        foreach (Base::ROLES as $rol) {
            $sinMotor = self::sinMotor($rol, $paquete['roles'][$rol]);
            if (in_array($rol, $tocados, true)) {
                $this->assertNotSame($base[$rol], $sinMotor, "{$rol} cambia");
            } else {
                $this->assertSame($base[$rol], $sinMotor, "{$rol}: quitando el motor, idéntico a la base");
                $this->assertNotSame($base[$rol], $paquete['roles'][$rol], "{$rol}: lleva el motor");
            }
        }
        foreach (array_slice($paquete['patches'], 0, -11) as $parche) {
            $this->assertSame(array_key_first($cambio), $parche['parameter'], 'cada parche es del parámetro que cambió');
        }
        $this->assertSame(array_fill(0, 11, C::ENGINE), array_column(array_slice($paquete['patches'], -11), 'parameter'), 'y al final, los once del motor');
    }

    // ── 3 · Los textos aprobados, en su sitio ─────────────────────────────────

    public function test_tono_profesional(): void
    {
        $r = C::compile(self::con(['persona.tone' => 'profesional']))['roles'];
        $base = Base::roles();

        foreach (['composer', 'composer_retry'] as $rol) {
            $this->assertSame(self::PRO_COMPOSER, self::lineaTras($r[$rol], 'COMO SUENAS:'), $rol);
            $this->assertSame(substr_count($base[$rol], "\n") + 2, substr_count($r[$rol], "\n"), "{$rol}: una línea más, y la del motor");
            $this->assertSame($base[$rol], str_replace("\n".self::PRO_COMPOSER, '', self::sinMotor($rol, $r[$rol])), "{$rol}: quitando la línea (y la del motor), la base");
        }
        foreach (['critic', 'critic_retry'] as $rol) {
            $this->assertSame(self::PRO_CRITIC, self::lineaTras($r[$rol], 'naturalness:'), $rol);
            $this->assertSame($base[$rol], str_replace("\n".self::PRO_CRITIC, '', self::sinMotor($rol, $r[$rol])), $rol);
        }
    }

    public function test_tono_entusiasta(): void
    {
        $r = C::compile(self::con(['persona.tone' => 'entusiasta']))['roles'];
        $base = Base::roles();

        foreach (['composer', 'composer_retry'] as $rol) {
            $this->assertSame(self::ENT_COMPOSER, self::lineaTras($r[$rol], 'COMO SUENAS:'), $rol);
            $this->assertSame($base[$rol], str_replace("\n".self::ENT_COMPOSER, '', self::sinMotor($rol, $r[$rol])), $rol);
        }
        foreach (['critic', 'critic_retry'] as $rol) {
            $this->assertSame(self::ENT_CRITIC, self::lineaTras($r[$rol], 'naturalness:'), $rol);
            $this->assertSame($base[$rol], str_replace("\n".self::ENT_CRITIC, '', self::sinMotor($rol, $r[$rol])), $rol);
        }
    }

    public function test_sin_emojis(): void
    {
        $r = C::compile(self::con(['persona.emojis' => false]))['roles'];
        $base = Base::roles();

        foreach (['composer', 'composer_retry'] as $rol) {
            $this->assertStringNotContainsString(self::EMOJI_BASE, $r[$rol]);
            $this->assertSame(1, substr_count($r[$rol], self::EMOJI_OFF_COMPOSER));
            $this->assertSame(str_replace(self::EMOJI_BASE, self::EMOJI_OFF_COMPOSER, $base[$rol]), self::sinMotor($rol, $r[$rol]), "{$rol}: solo la sustitución (y la línea del motor)");
            $this->assertSame(substr_count($base[$rol], "\n") + 1, substr_count($r[$rol], "\n"), "{$rol}: mismas líneas más la del motor");
        }
        foreach (['critic', 'critic_retry'] as $rol) {
            $this->assertSame(self::EMOJI_OFF_CRITIC, self::lineaTras($r[$rol], 'naturalness:'), $rol);
            $this->assertSame($base[$rol], str_replace("\n".self::EMOJI_OFF_CRITIC, '', self::sinMotor($rol, $r[$rol])), $rol);
        }
    }

    public function test_objetivos_del_strategist(): void
    {
        $base = Base::roles();
        foreach (['venta' => self::VENTA, 'informar' => self::INFORMAR] as $objetivo => $texto) {
            $s = C::compile(self::con(['strategy.goal' => $objetivo]))['roles']['strategist'];
            $this->assertSame($texto, self::lineaTras($s, 'PRINCIPIO:'), $objetivo);
            $this->assertSame($base['strategist'], str_replace("\n".$texto, '', self::sinMotor('strategist', $s)), $objetivo.': quitando la línea (y el motor), la base');
        }
    }

    /** @return array<string, array{int}> */
    public static function longitudes(): array
    {
        $out = [];
        for ($n = 160; $n <= 480; $n += 20) {
            $out["maxChars={$n}"] = [$n];
        }

        return $out;
    }

    #[DataProvider('longitudes')]
    public function test_los_17_valores_de_max_chars(int $n): void
    {
        $this->assertCount(17, self::longitudes());
        $paquete = C::compile(self::con(['persona.maxChars' => $n]));
        $r = $paquete['roles'];
        $base = Base::roles();

        if ($n === 320) {
            $this->assertSame($base, $r, 'la línea base no toca nada');
            $this->assertSame(Base::LEGACY_BUNDLE_HASH, $paquete['bundleHash']);

            return;
        }

        foreach (['composer', 'composer_retry'] as $rol) {
            // El del motor va el último: queda pegado a FORMA: y el del tope, justo detrás.
            $this->assertSame(self::MOTOR_COMPOSER, self::lineaTras($r[$rol], 'FORMA:'), $rol);
            $this->assertSame(self::maxComposer($n), self::lineaTras($r[$rol], self::MOTOR_COMPOSER), $rol);
            $this->assertSame($base[$rol], str_replace("\n".self::maxComposer($n), '', self::sinMotor($rol, $r[$rol])), $rol);
        }
        foreach (['critic', 'critic_retry'] as $rol) {
            $this->assertStringNotContainsString('mas de ~900 caracteres', $r[$rol], $rol);
            $this->assertSame(2, substr_count($r[$rol], "mas de ~{$n} caracteres"), "{$rol}: la sustitución y la línea del tope");
            $this->assertSame(self::MOTOR_CRITIC, self::lineaTras($r[$rol], 'progressive_disclosure:'), $rol);
            $this->assertSame(self::maxCritic($n), self::lineaTras($r[$rol], self::MOTOR_CRITIC), $rol);
            $esperado = str_replace('mas de ~900 caracteres', "mas de ~{$n} caracteres", $base[$rol]);
            $this->assertSame($esperado, str_replace("\n".self::maxCritic($n), '', self::sinMotor($rol, $r[$rol])), $rol);
        }
        $this->assertSame($base['strategist'], self::sinMotor('strategist', $r['strategist']), 'el Strategist solo lleva el motor');
    }

    // ── 7 · Compilador 3: el margen del redactor ──────────────────────────────

    public function test_el_margen_del_redactor_sale_de_la_tabla_n_m_p_f(): void
    {
        $this->assertCount(16, self::MARGEN, 'los 17 topes menos la línea base');
        foreach (self::MARGEN as $n => [$m, $p, $f]) {
            $this->assertSame($m, C::composerTargetChars($n), "M de {$n}");
            $this->assertSame($p, C::composerTargetWords($n), "P de {$n}");
            $this->assertSame($f, C::composerMaxSentences($n), "F de {$n}");
            $this->assertLessThan($n, $m, "{$n}: el objetivo queda por debajo del límite");
            $this->assertSame(0, $m % 10, "{$n}: M es una decena");
        }
        // Los ejemplos del encargo, uno por uno.
        $this->assertSame([160, 120, 140, 380], array_map(C::composerTargetChars(...), [200, 160, 180, 480]));
        $this->assertSame([25, 20, 60], array_map(C::composerTargetWords(...), [200, 160, 480]));
        $this->assertSame([3, 2, 6], array_map(C::composerMaxSentences(...), [200, 160, 480]));
    }

    /** @return array<string, array{int, string}> */
    public static function textosDelTopeCompilados(): array
    {
        return [
            'maxChars=160' => [160, 'TOPE DE LONGITUD PUBLICADO: el limite es 160 caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos 120 caracteres, que son unas 20 palabras: como mucho 2 frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar.'],
            'maxChars=200' => [200, 'TOPE DE LONGITUD PUBLICADO: el limite es 200 caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos 160 caracteres, que son unas 25 palabras: como mucho 3 frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar.'],
            'maxChars=480' => [480, 'TOPE DE LONGITUD PUBLICADO: el limite es 480 caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos 380 caracteres, que son unas 60 palabras: como mucho 6 frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar.'],
        ];
    }

    #[DataProvider('textosDelTopeCompilados')]
    public function test_el_texto_del_tope_del_redactor_compilado_tal_cual(int $n, string $texto): void
    {
        $paquete = C::compile(self::con(['persona.maxChars' => $n]));

        foreach (['composer', 'composer_retry'] as $rol) {
            $this->assertSame($texto, self::lineaTras($paquete['roles'][$rol], self::MOTOR_COMPOSER), $rol.': tras la línea del motor, que va pegada a FORMA:');
        }
        $this->assertSame(1, preg_match('/\A[\x20-\x7E]+\z/', $texto), 'ASCII imprimible, sin tildes ni saltos, como los demás textos');
        $this->assertStringNotContainsString('{', $texto, 'ningún marcador sin sustituir');
        $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen($texto));
        // El del Critic no cambia con el compilador 3.
        foreach (['critic', 'critic_retry'] as $rol) {
            $this->assertSame(self::maxCritic($n), self::lineaTras($paquete['roles'][$rol], self::MOTOR_CRITIC), $rol);
        }
    }

    public function test_dos_inserciones_tras_la_misma_linea_quedan_en_orden_inverso_al_de_aplicacion(): void
    {
        // Tono (primero) y emojis (después) insertan los dos tras «naturalness:» en los Critic.
        $r = C::compile(self::con(['persona.tone' => 'profesional', 'persona.emojis' => false]))['roles'];

        foreach (['critic', 'critic_retry'] as $rol) {
            $lineas = explode("\n", $r[$rol]);
            $i = array_key_first(array_filter($lineas, static fn (string $l): bool => str_starts_with($l, 'naturalness:')));
            $this->assertSame(self::EMOJI_OFF_CRITIC, $lineas[$i + 1], "{$rol}: la última aplicada queda pegada al ancla");
            $this->assertSame(self::PRO_CRITIC, $lineas[$i + 2], $rol);
        }
    }

    public function test_el_cambio_de_aceptacion_profesional_y_200(): void
    {
        $paquete = C::compile(self::con(['persona.tone' => 'profesional', 'persona.maxChars' => 200]));

        $this->assertStringContainsString(self::PRO_COMPOSER, $paquete['roles']['composer']);
        $this->assertStringContainsString(self::maxComposer(200), $paquete['roles']['composer']);
        $this->assertSame(Base::roles()['strategist'], self::sinMotor('strategist', $paquete['roles']['strategist']), 'el Strategist solo lleva el motor');
        $this->assertCount(4 + 6 + 11, $paquete['patches'], 'cuatro de tono, seis de longitud y once del motor');
        $this->assertSame([...array_fill(0, 4, 'persona.tone'), ...array_fill(0, 6, 'persona.maxChars'), ...array_fill(0, 11, C::ENGINE)], array_column($paquete['patches'], 'parameter'), 'en el orden de los parámetros y el del motor al final');
    }

    // ── 8 · Compilador 4: el registro profesional ────────────────────────────

    public function test_el_texto_del_tono_profesional_compilado_tal_cual(): void
    {
        $paquete = C::compile(self::con(['persona.tone' => 'profesional']));

        foreach (['composer', 'composer_retry'] as $rol) {
            $this->assertSame(self::PRO_COMPOSER, self::lineaTras($paquete['roles'][$rol], 'COMO SUENAS:'), $rol);
        }
        foreach (['critic', 'critic_retry'] as $rol) {
            $this->assertSame(self::PRO_CRITIC, self::lineaTras($paquete['roles'][$rol], 'naturalness:'), $rol);
        }
        foreach ([self::PRO_COMPOSER, self::PRO_CRITIC] as $texto) {
            $this->assertSame(1, preg_match('/\A[\x20-\x7E]+\z/', $texto), 'ASCII imprimible, sin tildes ni saltos, como los demás textos');
            $this->assertStringNotContainsString('{', $texto, 'ningún marcador');
            $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen($texto));
        }
        $this->assertSame([678, 329], [strlen(self::PRO_COMPOSER), strlen(self::PRO_CRITIC)], 'los bytes de los dos textos (con 1 200 de tope)');
        // Lo que el compilador 4 viene a cambiar (H4-9): el del redactor manda sobre TODO el
        // prompt, no solo sobre «los ejemplos de arriba»; el del revisor hace FALLAR el tuteo
        // (el reintento de n8n solo sale con verdict fail), no solo lo cuenta en naturalness.
        $this->assertStringContainsString('Esto manda sobre TODO este prompt, tambien sobre los ejemplos de abajo', self::PRO_COMPOSER);
        $this->assertStringNotContainsString('los ejemplos de arriba', self::PRO_COMPOSER);
        $this->assertStringContainsString('verdict fail con naturalness y notes "pasar todo el mensaje a usted"', self::PRO_CRITIC);
        $this->assertStringNotContainsString('muletillas', self::PRO_CRITIC, 'el revisor no falla por «Tranquilo» o «Listo»: la base lo tiene por muletilla normal fuera de un reclamo');
    }

    /**
     * Antes del parche del motor, el compilador 4 solo movía las huellas de
     * `profesional` (c001 y c304 seguían con las del 3). Con el parche del
     * motor se mueven TODAS las no base; Legacy, no. El compilador 5 las vuelve
     * a mover todas (el parche del motor cambia). Las de ahora, escritas a mano
     * desde `compile-cases.json` regenerado (derivado en Node, sin PHP).
     */
    public function test_las_huellas_del_compilador_5_con_el_parche_del_motor(): void
    {
        $huella = static fn (array $cambios): string => C::compile(self::con($cambios))['bundleHash'];

        $this->assertSame('3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902', $huella([]), 'Legacy: no se mueve');
        $c001 = $huella(['persona.maxChars' => 160]);
        $this->assertSame('7b3327dc70fd81246c0ab251347b81d10221194035392336575e2c7274951aba', $c001, 'cercano · 160');
        $this->assertNotSame('028bc1eda8fa852ff9cd0a268cd30e7be1529088d993259215fe77bfd8b4e585', $c001, 'la del compilador 4 con su motor');
        $this->assertNotSame('bedc1c5dca22113b065384e0c9867f8d1f5bbe3652227562927cfdee9d20f294', $c001, 'la del compilador 3 y del 4 sin el motor');
        $c304 = $huella(['persona.tone' => 'entusiasta', 'persona.emojis' => false, 'persona.maxChars' => 480, 'strategy.goal' => 'venta']);
        $this->assertSame('c3d77437f10772a4356b338836bb703e44f6dfed52546af370457807d110d1ad', $c304, 'entusiasta · sin emojis · 480 · venta');
        $this->assertNotSame('10417eaa561c9efcad29a2d96d9e6d7daf42dcbf77e803454d3a2521cdf4a21e', $c304, 'la del compilador 4 con su motor');
        $this->assertNotSame('7ea13909374e001f7b5e50dd1ab7a26100dc168c20f0918f6d3350b7f7cb3be5', $c304);
        // «profesional + 200» (c074).
        $g8 = C::compile(self::con(['persona.tone' => 'profesional', 'persona.maxChars' => 200]));
        $this->assertSame('2eb9be6f5d3e6b509b3410457b291c7d582aa73be08f0c9dd00f42229bb909c0', $g8['bundleHash'], 'profesional · 200, compilador 5 (lo publicado como v3, firmado con el 4, ya no la da)');
        $this->assertNotSame('26d57565894df0fb9885d73be4c4aba818f16529bfb0fe4ae2e4418c5e1b1075', $g8['bundleHash'], 'la del compilador 4 con su motor: la que firmó la v3');
        $this->assertNotSame('7b09b89c98d48b01561b9cbc07e048c152c0af6a4ba7f583becd49f8e7e8cc35', $g8['bundleHash'], 'la del 4 sin el motor');
        $this->assertNotSame('af9e0d23de42368f1cda211b03dc3164ce1a341acc7e6f22abf3d71afbdb4d51', $g8['bundleHash'], 'la del compilador 3');
        $this->assertSame(120097, $g8['bytes'], '115 446 sin el motor; 117 746 con el del 4 (más 2 300: dos Composer × (697 + 1) y dos Critic × (451 + 1)); con el del 5, 2 351 más: Strategist 785 + 93, Composer 468 + 110 + 113 + 102 y Composer Reintento 468 + 110 + 102');
        $this->assertSame([118079, 119206], [C::compile(self::con(['persona.maxChars' => 160]))['bytes'], C::compile(self::con(['persona.tone' => 'entusiasta', 'persona.emojis' => false, 'persona.maxChars' => 480, 'strategy.goal' => 'venta']))['bytes']], 'c001 y c304, con 2 351 más que con el 4');
    }

    // ── 4 · Anclas únicas; la matriz entera compila ───────────────────────────

    public function test_cada_ancla_aparece_exactamente_una_vez_en_su_rol_de_la_base(): void
    {
        $base = Base::roles();
        $vistas = 0;
        foreach (['persona.tone' => ['profesional', 'entusiasta'], 'persona.emojis' => [false], 'persona.maxChars' => [200], 'strategy.goal' => ['venta', 'informar']] as $parametro => $valores) {
            foreach ($valores as $valor) {
                foreach (C::patches(self::con([$parametro => $valor])) as $parche) {
                    $this->assertSame(1, substr_count($base[$parche['role']], $parche['anchor']), "«{$parche['anchor']}» en {$parche['role']}");
                    $this->assertContains($parche['operation'], [C::INSERT_AFTER_LINE, C::REPLACE]);
                    $vistas++;
                }
            }
        }
        $this->assertSame(4 + 4 + 4 + 6 + 1 + 1 + 6 * 11, $vistas, 'y los once del motor en cada uno de los seis paquetes');

        // Las dos que §B.3 dice «a principio de línea», lo están.
        foreach (['critic', 'critic_retry'] as $rol) {
            foreach (['naturalness:', 'progressive_disclosure:'] as $ancla) {
                $this->assertSame(1, preg_match_all('/^'.preg_quote($ancla, '/').'/m', $base[$rol]), "{$ancla} a principio de línea en {$rol}");
            }
        }
    }

    public function test_las_306_combinaciones_compilan_y_cada_una_tiene_su_huella(): void
    {
        $huellas = [];
        $mayorRol = 0;
        $mayorPaquete = 0;
        foreach (C::TONES as $tono) {
            foreach ([true, false] as $emojis) {
                for ($n = 160; $n <= 480; $n += 20) {
                    foreach (C::GOALS as $objetivo) {
                        $p = C::compile(self::con(['persona.tone' => $tono, 'persona.emojis' => $emojis, 'persona.maxChars' => $n, 'strategy.goal' => $objetivo]));
                        $huellas[$p['bundleHash']] = true;
                        $mayorRol = max($mayorRol, ...array_values(array_map('strlen', $p['roles'])));
                        $mayorPaquete = max($mayorPaquete, $p['bytes']);
                    }
                }
            }
        }

        $this->assertCount(3 * 2 * 17 * 3, $huellas, 'ninguna colisión: cada combinación es un paquete distinto');
        $this->assertLessThanOrEqual(C::MAX_ROLE_BYTES, $mayorRol);
        $this->assertLessThanOrEqual(C::MAX_BUNDLE_BYTES, $mayorPaquete);
    }

    // ── 5 · Determinismo ──────────────────────────────────────────────────────

    public function test_el_orden_de_las_claves_no_cambia_nada(): void
    {
        $valores = ['persona.tone' => 'entusiasta', 'persona.emojis' => false, 'persona.maxChars' => 260, 'rules.forbiddenPhrases' => ['gratis'], 'strategy.goal' => 'informar'];
        $uno = C::compile($valores);
        $otro = C::compile(array_reverse($valores, true));

        $this->assertSame($uno, $otro);
        $this->assertSame(C::compile(self::BASE), C::compile(array_reverse(self::BASE, true)));
        $this->assertSame(['persona.emojis', 'persona.maxChars', 'persona.tone', 'rules.forbiddenPhrases', 'strategy.goal'], array_keys($uno['governed']), 'orden canónico');
    }

    // ── 6 · Lo que lanza ──────────────────────────────────────────────────────

    /** @return array<string, array{array<string,mixed>}> */
    public static function valoresMalos(): array
    {
        $sinTono = self::BASE;
        unset($sinTono['persona.tone']);

        return [
            'maxChars fuera de paso' => [self::con(['persona.maxChars' => 170])],
            'maxChars por debajo' => [self::con(['persona.maxChars' => 140])],
            'maxChars por encima' => [self::con(['persona.maxChars' => 500])],
            'maxChars como cadena' => [self::con(['persona.maxChars' => '320'])],
            'maxChars como float' => [self::con(['persona.maxChars' => 320.0])],
            'tono desconocido' => [self::con(['persona.tone' => 'formal'])],
            'tono en mayúsculas' => [self::con(['persona.tone' => 'Profesional'])],
            'objetivo desconocido' => [self::con(['strategy.goal' => 'fidelizar'])],
            'emojis como cadena' => [self::con(['persona.emojis' => 'false'])],
            'emojis como entero' => [self::con(['persona.emojis' => 0])],
            'falta una clave' => [$sinTono],
            'sobra una clave' => [self::BASE + ['intent.fallback' => 'humano']],
            'vacío' => [[]],
        ];
    }

    /** @param array<string,mixed> $valores */
    #[DataProvider('valoresMalos')]
    public function test_un_valor_fuera_del_contrato_lanza(array $valores): void
    {
        foreach (['compile', 'patches', 'validate'] as $metodo) {
            try {
                C::$metodo($valores);
                $this->fail("{$metodo} tenía que lanzar");
            } catch (UltronPromptException $e) {
                $this->assertSame(UltronPromptException::INVALID_GOVERNED, $e->reason, $metodo);
            }
        }
    }

    /** @return array<string, array{string, string}> */
    public static function anclasMalas(): array
    {
        return [
            'ancla ausente' => ["uno\ndos\ntres", 'FORMA:'],
            'ancla repetida' => ["FORMA: a\nFORMA: b", 'FORMA:'],
        ];
    }

    #[DataProvider('anclasMalas')]
    public function test_un_ancla_ausente_o_repetida_lanza(string $texto, string $ancla): void
    {
        $aplicar = new ReflectionMethod(C::class, 'apply');

        foreach ([C::INSERT_AFTER_LINE, C::REPLACE] as $op) {
            try {
                $aplicar->invoke(null, $texto, ['role' => 'composer', 'operation' => $op, 'anchor' => $ancla, 'text' => 'X']);
                $this->fail("{$op} tenía que lanzar");
            } catch (UltronPromptException $e) {
                $this->assertSame(UltronPromptException::ANCHOR, $e->reason);
            }
        }
    }

    public function test_las_dos_operaciones_sobre_un_texto_pequeno(): void
    {
        $aplicar = new ReflectionMethod(C::class, 'apply');
        $texto = "a\nFORMA: b c\nd";

        $this->assertSame("a\nFORMA: b c\nX\nd", $aplicar->invoke(null, $texto, ['role' => 'composer', 'operation' => C::INSERT_AFTER_LINE, 'anchor' => 'FORMA:', 'text' => 'X']));
        $this->assertSame("a\nFORMA: b c\nX\nd", $aplicar->invoke(null, $texto, ['role' => 'composer', 'operation' => C::INSERT_AFTER_LINE, 'anchor' => 'b c', 'text' => 'X']), 'el ancla en mitad de línea inserta tras la línea entera');
        $this->assertSame("a\nFORMA: b c\nd\nX", $aplicar->invoke(null, $texto, ['role' => 'composer', 'operation' => C::INSERT_AFTER_LINE, 'anchor' => 'd', 'text' => 'X']), 'en la última línea, al final tras un salto');
        $this->assertSame("a\nFORMA: Y\nd", $aplicar->invoke(null, $texto, ['role' => 'composer', 'operation' => C::REPLACE, 'anchor' => 'b c', 'text' => 'Y']));
    }

    public function test_los_textos_de_parche_respetan_su_tope_y_no_tienen_saltos(): void
    {
        foreach (C::TONES as $tono) {
            foreach ([true, false] as $emojis) {
                foreach ([160, 480] as $n) {
                    foreach (C::GOALS as $objetivo) {
                        foreach (C::patches(self::con(['persona.tone' => $tono, 'persona.emojis' => $emojis, 'persona.maxChars' => $n, 'strategy.goal' => $objetivo])) as $p) {
                            $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen($p['text']));
                            $this->assertStringNotContainsString("\n", $p['text']);
                            // Ningún texto de parche contiene un ancla: la unicidad se conserva al aplicar en cadena.
                            foreach (self::ANCLAS as $ancla) {
                                $this->assertStringNotContainsString($ancla, $p['text']);
                            }
                            // Sin tildes ni caracteres fuera de ASCII, como la base en sus reglas.
                            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $p['text']);
                        }
                    }
                }
            }
        }
        $this->assertSame([1200, 40960, 163840], [C::MAX_PATCH_BYTES, C::MAX_ROLE_BYTES, C::MAX_BUNDLE_BYTES]);
    }

    public function test_la_forma_de_un_parche(): void
    {
        $parches = C::patches(self::con(['strategy.goal' => 'venta']));
        $motor = static fn (string $rol, string $ancla, string $texto, string $op = 'insert_after_line'): array => ['parameter' => 'engine.plansOverview', 'value' => true, 'role' => $rol, 'operation' => $op, 'anchor' => $ancla, 'text' => $texto];

        $this->assertSame([[
            'parameter' => 'strategy.goal',
            'value' => 'venta',
            'role' => 'strategist',
            'operation' => 'insert_after_line',
            'anchor' => 'PRINCIPIO:',
            'text' => self::VENTA,
        ],
            $motor('strategist', self::A_COTIZA, self::MOTOR_STRATEGIST),
            $motor('strategist', self::A_PRESENTALO, self::MOTOR_STRATEGIST_PRESENTALO, 'replace'),
            $motor('composer', 'FORMA:', self::MOTOR_COMPOSER),
            $motor('composer', self::A_MARCADOR, self::MOTOR_MARCADOR, 'replace'),
            $motor('composer', self::A_ELIGE, self::MOTOR_ELIGE, 'replace'),
            $motor('composer', self::A_PRECIO, self::MOTOR_PRECIO, 'replace'),
            $motor('composer_retry', 'FORMA:', self::MOTOR_COMPOSER),
            $motor('composer_retry', self::A_MARCADOR, self::MOTOR_MARCADOR, 'replace'),
            $motor('composer_retry', self::A_PRECIO, self::MOTOR_PRECIO, 'replace'),
            $motor('critic', 'progressive_disclosure:', self::MOTOR_CRITIC),
            $motor('critic_retry', 'progressive_disclosure:', self::MOTOR_CRITIC),
        ], $parches);
    }
}
