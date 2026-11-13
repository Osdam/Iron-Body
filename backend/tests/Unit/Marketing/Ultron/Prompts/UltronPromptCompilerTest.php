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
    private const PRO_COMPOSER = 'REGISTRO PUBLICADO: profesional. Esto manda sobre los ejemplos de arriba: trata a la persona de usted, frases completas y sobrias, sin muletillas coloquiales ("De una", "Listo", "Tranquilo") ni exclamaciones seguidas. Sigue siendo calido; profesional no es frio.';

    private const PRO_CRITIC = 'REGISTRO PUBLICADO: profesional. Tratar de usted NO es defecto de naturalness; tutear o usar muletillas coloquiales SI lo es.';

    private const ENT_COMPOSER = 'REGISTRO PUBLICADO: entusiasta. Mas energia: celebra lo que la persona quiere lograr y transmite ganas de acompanarla. Sin exagerar, sin prometer resultados y sin urgencia ni presion.';

    private const ENT_CRITIC = 'REGISTRO PUBLICADO: entusiasta. La energia NO es defecto de naturalness; la urgencia o la presion siguen siendo PRESSURE.';

    private const EMOJI_BASE = 'Emoji opcional y raro, nunca en temas sensibles.';

    private const EMOJI_OFF_COMPOSER = 'Sin emojis: ninguno, en ningun mensaje.';

    private const EMOJI_OFF_CRITIC = 'EMOJIS: la version publicada no usa emojis; si el borrador lleva alguno, verdict fail con naturalness.';

    private const VENTA = 'OBJETIVO COMERCIAL PUBLICADO: cerrar membresia. Con senales claras de compra (buying_signal strong, hot_lead_fast_path o READY) prioriza recommend y close sobre ofrecer la visita; el dia de cortesia queda para quien desconfia o quiere conocer antes. Nada de esto autoriza presion, descuentos ni salirse de allowed_transitions.';

    private const INFORMAR = 'OBJETIVO COMERCIAL PUBLICADO: informar y cualificar. Prioriza understand y recommend; no propongas close ni collect_payment salvo que la persona pida comprar o pagar con palabras.';

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
        $this->assertSame(['contract' => 'ultron-prompts/1', 'base' => 'n8n-29de5119', 'compiler' => 3], array_intersect_key($paquete, array_flip(['contract', 'base', 'compiler'])));
        $this->assertSame(self::BASE, $paquete['governed']);
        $this->assertSame(self::BASE, C::BASELINE);
        $this->assertTrue(C::isBaseline(self::BASE));
        $this->assertSame([], C::changedRoles(self::BASE));
        $this->assertSame(3, C::VERSION, 'la 2 añade las frases de las reglas S-NNN; la 3, el margen del tope para el redactor');
        $this->assertSame('3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902', $paquete['bundleHash'], 'la huella Legacy, escrita a mano: el compilador 3 no la mueve');
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

        $this->assertEqualsCanonicalizing($tocados, C::changedRoles(self::con($cambio)));
        foreach (Base::ROLES as $rol) {
            if (in_array($rol, $tocados, true)) {
                $this->assertNotSame($base[$rol], $paquete['roles'][$rol], "{$rol} cambia");
            } else {
                $this->assertSame($base[$rol], $paquete['roles'][$rol], "{$rol} queda idéntico a la base");
            }
        }
        foreach ($paquete['patches'] as $parche) {
            $this->assertSame(array_key_first($cambio), $parche['parameter'], 'cada parche es del parámetro que cambió');
        }
    }

    // ── 3 · Los textos aprobados, en su sitio ─────────────────────────────────

    public function test_tono_profesional(): void
    {
        $r = C::compile(self::con(['persona.tone' => 'profesional']))['roles'];
        $base = Base::roles();

        foreach (['composer', 'composer_retry'] as $rol) {
            $this->assertSame(self::PRO_COMPOSER, self::lineaTras($r[$rol], 'COMO SUENAS:'), $rol);
            $this->assertSame(substr_count($base[$rol], "\n") + 1, substr_count($r[$rol], "\n"), "{$rol}: una línea más");
            $this->assertSame($base[$rol], str_replace("\n".self::PRO_COMPOSER, '', $r[$rol]), "{$rol}: quitando la línea, la base");
        }
        foreach (['critic', 'critic_retry'] as $rol) {
            $this->assertSame(self::PRO_CRITIC, self::lineaTras($r[$rol], 'naturalness:'), $rol);
            $this->assertSame($base[$rol], str_replace("\n".self::PRO_CRITIC, '', $r[$rol]), $rol);
        }
    }

    public function test_tono_entusiasta(): void
    {
        $r = C::compile(self::con(['persona.tone' => 'entusiasta']))['roles'];
        $base = Base::roles();

        foreach (['composer', 'composer_retry'] as $rol) {
            $this->assertSame(self::ENT_COMPOSER, self::lineaTras($r[$rol], 'COMO SUENAS:'), $rol);
            $this->assertSame($base[$rol], str_replace("\n".self::ENT_COMPOSER, '', $r[$rol]), $rol);
        }
        foreach (['critic', 'critic_retry'] as $rol) {
            $this->assertSame(self::ENT_CRITIC, self::lineaTras($r[$rol], 'naturalness:'), $rol);
            $this->assertSame($base[$rol], str_replace("\n".self::ENT_CRITIC, '', $r[$rol]), $rol);
        }
    }

    public function test_sin_emojis(): void
    {
        $r = C::compile(self::con(['persona.emojis' => false]))['roles'];
        $base = Base::roles();

        foreach (['composer', 'composer_retry'] as $rol) {
            $this->assertStringNotContainsString(self::EMOJI_BASE, $r[$rol]);
            $this->assertSame(1, substr_count($r[$rol], self::EMOJI_OFF_COMPOSER));
            $this->assertSame(str_replace(self::EMOJI_BASE, self::EMOJI_OFF_COMPOSER, $base[$rol]), $r[$rol], "{$rol}: solo la sustitución");
            $this->assertSame(substr_count($base[$rol], "\n"), substr_count($r[$rol], "\n"), "{$rol}: mismas líneas");
        }
        foreach (['critic', 'critic_retry'] as $rol) {
            $this->assertSame(self::EMOJI_OFF_CRITIC, self::lineaTras($r[$rol], 'naturalness:'), $rol);
            $this->assertSame($base[$rol], str_replace("\n".self::EMOJI_OFF_CRITIC, '', $r[$rol]), $rol);
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
            $this->assertSame(self::maxComposer($n), self::lineaTras($r[$rol], 'FORMA:'), $rol);
            $this->assertSame($base[$rol], str_replace("\n".self::maxComposer($n), '', $r[$rol]), $rol);
        }
        foreach (['critic', 'critic_retry'] as $rol) {
            $this->assertStringNotContainsString('mas de ~900 caracteres', $r[$rol], $rol);
            $this->assertSame(2, substr_count($r[$rol], "mas de ~{$n} caracteres"), "{$rol}: la sustitución y la línea del tope");
            $this->assertSame(self::maxCritic($n), self::lineaTras($r[$rol], 'progressive_disclosure:'), $rol);
            $esperado = str_replace('mas de ~900 caracteres', "mas de ~{$n} caracteres", $base[$rol]);
            $this->assertSame($esperado, str_replace("\n".self::maxCritic($n), '', $r[$rol]), $rol);
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
            $this->assertSame($texto, self::lineaTras($paquete['roles'][$rol], 'FORMA:'), $rol);
        }
        $this->assertSame(1, preg_match('/\A[\x20-\x7E]+\z/', $texto), 'ASCII imprimible, sin tildes ni saltos, como los demás textos');
        $this->assertStringNotContainsString('{', $texto, 'ningún marcador sin sustituir');
        $this->assertLessThanOrEqual(C::MAX_PATCH_BYTES, strlen($texto));
        // El del Critic no cambia con el compilador 3.
        foreach (['critic', 'critic_retry'] as $rol) {
            $this->assertSame(self::maxCritic($n), self::lineaTras($paquete['roles'][$rol], 'progressive_disclosure:'), $rol);
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
        $this->assertCount(4 + 6, $paquete['patches'], 'cuatro de tono y seis de longitud');
        $this->assertSame(['persona.tone', 'persona.tone', 'persona.tone', 'persona.tone', 'persona.maxChars', 'persona.maxChars', 'persona.maxChars', 'persona.maxChars', 'persona.maxChars', 'persona.maxChars'], array_column($paquete['patches'], 'parameter'), 'en el orden de los parámetros');
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
        $this->assertSame(4 + 4 + 4 + 6 + 1 + 1, $vistas);

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

        $this->assertSame([[
            'parameter' => 'strategy.goal',
            'value' => 'venta',
            'role' => 'strategist',
            'operation' => 'insert_after_line',
            'anchor' => 'PRINCIPIO:',
            'text' => self::VENTA,
        ]], $parches);
    }
}
