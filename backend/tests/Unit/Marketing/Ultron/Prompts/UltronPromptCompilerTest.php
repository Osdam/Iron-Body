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

    /** Compilador 4, el parche del motor (§7.1 del plan del arquitecto y §B.3, «Compilador 4»), a mano. */
    private const MOTOR_COMPOSER = 'LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales mostrar. Esto manda sobre las reglas de precios y marcadores de este prompt: nombra al menos dos de esos planes, en el orden en que vienen, escribiendo por cada uno SOLO su marcador (PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves), que el backend sustituye por el nombre y el precio reales. No escribas sus nombres ni cifras, no uses PLAN_NAME, PLAN_PRICE ni PLAN_DURATION y no repitas un marcador. Para decir para quien es cada uno usa solo los benefits de su plan_id en active_plans. Si plans_overview no viene, nada de esto aplica.';

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

    /** El rol sin la línea del parche del motor (va en todo paquete no base; se comprueba en su prueba). */
    private static function sinMotor(string $rol, string $texto): string
    {
        $linea = str_starts_with($rol, 'composer') ? self::MOTOR_COMPOSER : self::MOTOR_CRITIC;

        return $rol === 'strategist' ? $texto : str_replace("\n".$linea, '', $texto);
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
        $this->assertSame(['contract' => 'ultron-prompts/1', 'base' => 'n8n-29de5119', 'compiler' => 4], array_intersect_key($paquete, array_flip(['contract', 'base', 'compiler'])));
        $this->assertSame(self::BASE, $paquete['governed']);
        $this->assertSame(self::BASE, C::BASELINE);
        $this->assertTrue(C::isBaseline(self::BASE));
        $this->assertSame([], C::changedRoles(self::BASE));
        $this->assertSame(4, C::VERSION, 'la 2 añade las frases de las reglas S-NNN; la 3, el margen del tope para el redactor; la 4, los textos del tono profesional');
        $this->assertSame('3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902', $paquete['bundleHash'], 'la huella Legacy, escrita a mano: ni el compilador 3 ni el 4 la mueven');
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
            // Compilador 4, el parche del motor: un cambio solo del estratega también lleva los cuatro del motor.
            'goal=venta' => [['strategy.goal' => 'venta'], ['strategist', ...$cuatro]],
            'goal=informar' => [['strategy.goal' => 'informar'], ['strategist', ...$cuatro]],
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

        $this->assertEqualsCanonicalizing($tocados, C::changedRoles(self::con($cambio)));
        foreach (Base::ROLES as $rol) {
            if (in_array($rol, $tocados, true)) {
                $this->assertNotSame($base[$rol], $paquete['roles'][$rol], "{$rol} cambia");
            } else {
                $this->assertSame($base[$rol], $paquete['roles'][$rol], "{$rol} queda idéntico a la base");
            }
        }
        foreach (array_slice($paquete['patches'], 0, -4) as $parche) {
            $this->assertSame(array_key_first($cambio), $parche['parameter'], 'cada parche es del parámetro que cambió');
        }
        $this->assertSame(array_fill(0, 4, C::ENGINE), array_column(array_slice($paquete['patches'], -4), 'parameter'), 'y al final, los cuatro del motor');
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
            $this->assertSame($base['strategist'], str_replace("\n".$texto, '', $s), $objetivo);
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
        $this->assertSame($base['strategist'], $r['strategist']);
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
        $this->assertSame(Base::roles()['strategist'], $paquete['roles']['strategist']);
        $this->assertCount(4 + 6 + 4, $paquete['patches'], 'cuatro de tono, seis de longitud y cuatro del motor');
        $this->assertSame(['persona.tone', 'persona.tone', 'persona.tone', 'persona.tone', 'persona.maxChars', 'persona.maxChars', 'persona.maxChars', 'persona.maxChars', 'persona.maxChars', 'persona.maxChars', C::ENGINE, C::ENGINE, C::ENGINE, C::ENGINE], array_column($paquete['patches'], 'parameter'), 'en el orden de los parámetros y el del motor al final');
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
     * motor se mueven TODAS las no base; Legacy, no. Las de ahora, escritas a
     * mano desde `compile-cases.json` regenerado (derivado en Node, sin PHP).
     */
    public function test_las_huellas_del_compilador_4_con_el_parche_del_motor(): void
    {
        $huella = static fn (array $cambios): string => C::compile(self::con($cambios))['bundleHash'];

        $this->assertSame('3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902', $huella([]), 'Legacy: no se mueve');
        $c001 = $huella(['persona.maxChars' => 160]);
        $this->assertSame('028bc1eda8fa852ff9cd0a268cd30e7be1529088d993259215fe77bfd8b4e585', $c001, 'cercano · 160');
        $this->assertNotSame('bedc1c5dca22113b065384e0c9867f8d1f5bbe3652227562927cfdee9d20f294', $c001, 'la del compilador 3 y del 4 sin el motor');
        $c304 = $huella(['persona.tone' => 'entusiasta', 'persona.emojis' => false, 'persona.maxChars' => 480, 'strategy.goal' => 'venta']);
        $this->assertSame('10417eaa561c9efcad29a2d96d9e6d7daf42dcbf77e803454d3a2521cdf4a21e', $c304, 'entusiasta · sin emojis · 480 · venta');
        $this->assertNotSame('7ea13909374e001f7b5e50dd1ab7a26100dc168c20f0918f6d3350b7f7cb3be5', $c304);
        // «profesional + 200» (c074).
        $g8 = C::compile(self::con(['persona.tone' => 'profesional', 'persona.maxChars' => 200]));
        $this->assertSame('26d57565894df0fb9885d73be4c4aba818f16529bfb0fe4ae2e4418c5e1b1075', $g8['bundleHash'], 'profesional · 200, compilador 4 con el motor');
        $this->assertNotSame('7b09b89c98d48b01561b9cbc07e048c152c0af6a4ba7f583becd49f8e7e8cc35', $g8['bundleHash'], 'la del 4 sin el motor');
        $this->assertNotSame('af9e0d23de42368f1cda211b03dc3164ce1a341acc7e6f22abf3d71afbdb4d51', $g8['bundleHash'], 'la del compilador 3');
        $this->assertSame(117746, $g8['bytes'], '115 446 sin el motor, más 2 300: dos Composer × (697 + 1) y dos Critic × (451 + 1)');
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
        $this->assertSame(4 + 4 + 4 + 6 + 1 + 1 + 6 * 4, $vistas, 'y los cuatro del motor en cada uno de los seis paquetes');

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
                            foreach (['COMO SUENAS:', 'FORMA:', self::EMOJI_BASE, 'naturalness:', 'progressive_disclosure:', 'mas de ~900 caracteres', 'PRINCIPIO:'] as $ancla) {
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
        $motor = static fn (string $rol, string $ancla, string $texto): array => ['parameter' => 'engine.plansOverview', 'value' => true, 'role' => $rol, 'operation' => 'insert_after_line', 'anchor' => $ancla, 'text' => $texto];

        $this->assertSame([[
            'parameter' => 'strategy.goal',
            'value' => 'venta',
            'role' => 'strategist',
            'operation' => 'insert_after_line',
            'anchor' => 'PRINCIPIO:',
            'text' => self::VENTA,
        ],
            $motor('composer', 'FORMA:', self::MOTOR_COMPOSER),
            $motor('composer_retry', 'FORMA:', self::MOTOR_COMPOSER),
            $motor('critic', 'progressive_disclosure:', self::MOTOR_CRITIC),
            $motor('critic_retry', 'progressive_disclosure:', self::MOTOR_CRITIC),
        ], $parches);
    }
}
