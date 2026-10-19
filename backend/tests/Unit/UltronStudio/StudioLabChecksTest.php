<?php

namespace Tests\Unit\UltronStudio;

use App\Services\Marketing\Ultron\ComposerStyleGuard;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioLabChecks as K;
use App\Services\UltronStudio\StudioRuleCoherence;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Las pruebas deterministas del Studio (contrato H4 §A4): `lab-vacia`,
 * `lab-longitud`, `lab-emojis` y `lab-regla-<S-NNN>`.
 *
 * LO QUE SE PROTEGE. Niveles y disparos de la tabla; que los emojis se
 * cuenten con los MISMOS rangos que la guardia de estilo del motor y la
 * respuesta se canonicalice como las frases de G (comparados por
 * reflexión: si uno cambia, esto falla); que solo cuenten las afirmaciones
 * `forbid text contains` de las S-NNN cuyo «si» se da; y la forma de cada
 * hallazgo.
 */
class StudioLabChecksTest extends TestCase
{
    /** @param  array<string,mixed>  $persona */
    private static function target(array $persona = [], array $rules = [], ?array $configs = null, array $edgeIds = []): array
    {
        $configs ??= StudioCatalog::baseline()['configs'];
        $configs['persona'] = $persona + $configs['persona'];

        return ['configs' => $configs, 'edgeIds' => $edgeIds, 'rules' => $rules];
    }

    private static function forbid(string $code, array $phrases, array $when = []): array
    {
        return ['code' => $code, 'revision' => 1, 'description' => "Regla {$code}", 'conditions' => ['when' => $when, 'then' => [
            ['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => $phrases],
        ]]];
    }

    /** @param  list<array<string,mixed>>  $hallazgos */
    private static function ids(array $hallazgos): array
    {
        return array_column($hallazgos, 'id');
    }

    public function test_respuesta_vacia_bloquea(): void
    {
        foreach (['', '   ', "\n\t", "\u{3000}\u{00A0}"] as $vacia) {
            $h = K::check($vacia, self::target());
            $this->assertSame(['lab-vacia'], self::ids($h), json_encode($vacia));
            $this->assertSame(['id' => 'lab-vacia', 'source' => 'studio', 'level' => 'blocking', 'title' => 'La respuesta está vacía', 'detail' => 'El actor no escribió nada: no hay mensaje que enviar.'], $h[0]);
        }
        $this->assertSame([], K::check('Hola', self::target()));
    }

    public function test_longitud_por_encima_de_max_chars_avisa(): void
    {
        $exacta = str_repeat('á', 160);
        $this->assertSame([], K::check($exacta, self::target(['maxChars' => 160])), 'en el límite no avisa (cuenta caracteres, no bytes)');
        $this->assertSame([], K::check("  {$exacta}  ", self::target(['maxChars' => 160])), 'los bordes recortados no cuentan');

        $h = K::check($exacta.'x', self::target(['maxChars' => 160]));
        $this->assertSame(['lab-longitud'], self::ids($h));
        $this->assertSame('warning', $h[0]['level']);
        $this->assertSame('Tiene 161 caracteres y la longitud máxima es 160.', $h[0]['detail']);

        $sinLimite = self::target();
        unset($sinLimite['configs']['persona']['maxChars']);
        $this->assertSame([], K::check(str_repeat('x', 5000), $sinLimite), 'sin maxChars no hay nada que comprobar');
    }

    public function test_emojis_solo_avisan_si_la_version_no_los_usa(): void
    {
        $this->assertSame([], K::check('Hola 💪', self::target(['emojis' => true])));

        $h = K::check('Hola 💪🏽 qué tal', self::target(['emojis' => false]));
        $this->assertSame(['lab-emojis'], self::ids($h));
        $this->assertSame('warning', $h[0]['level']);
        $this->assertSame('💪🏽', $h[0]['quote'], 'la cita es el emoji, con su tono');

        $this->assertSame(['lab-emojis'], self::ids(K::check('Vamos 🇨🇴', self::target(['emojis' => false]))), 'una bandera cuenta');
        $this->assertSame(['lab-emojis'], self::ids(K::check('Listo ✅', self::target(['emojis' => false]))));
        $this->assertSame([], K::check('Iron Body® y Marca™ ©', self::target(['emojis' => false])), '©®™ son prosa');
        $this->assertSame([], K::check('Letra suelta 🇨', self::target(['emojis' => false])), 'un indicador regional solo no es bandera');
    }

    public function test_los_rangos_de_emojis_son_los_de_la_guardia_de_estilo_del_motor(): void
    {
        $motor = (new ReflectionClassConstant(ComposerStyleGuard::class, 'PICTO'))->getValue();
        $this->assertSame($motor, K::PICTO, 'Los rangos de pictogramas derivaron de ComposerStyleGuard::PICTO.');
        $this->assertStringNotContainsString('Extended_Pictographic', K::EMOJI_PATTERN);
    }

    public function test_la_canonicalizacion_es_la_de_las_frases_de_g(): void
    {
        $this->assertSame((new ReflectionClassConstant(StudioRuleCoherence::class, 'JS_TRIM'))->getValue(), K::JS_TRIM);
        $this->assertSame((new ReflectionClassConstant(StudioRuleCoherence::class, 'LOWER'))->getValue(), K::LOWER);
        $this->assertSame('compra ya, ¡ahora mismo! ñandú', K::canonical("  COMPRA\u{00A0}\u{00A0}Ya,\n¡Ahora MISMO!  ÑANDÚ "));
    }

    public function test_frase_prohibida_por_una_s_avisa_con_la_regla(): void
    {
        $reglas = [self::forbid('S-003', ['compra ya', 'sin compromiso']), self::forbid('S-004', ['precio']), StudioCatalog::legacyRules()[1]];

        $h = K::check('¡COMPRA   YA! Es sin compromiso, compra ya', self::target(rules: $reglas));
        $this->assertSame(['lab-regla-S-003'], self::ids($h), 'una por regla; las R-xx no cuentan aquí (las mira el motor)');
        $this->assertSame('warning', $h[0]['level']);
        $this->assertSame('studio', $h[0]['source']);
        $this->assertSame('compra ya', $h[0]['quote']);
        $this->assertSame('La respuesta contiene una frase que prohíbe S-003', $h[0]['title']);
        $this->assertStringContainsString('«compra ya», «sin compromiso»', $h[0]['detail']);

        // R-02 prohíbe «gratis», pero es del código: no es un hallazgo del Studio.
        $this->assertSame([], K::check('Es gratis', self::target(rules: $reglas)));
    }

    public function test_solo_cuenta_forbid_text_contains_y_si_el_si_se_da(): void
    {
        $exige = ['code' => 'S-010', 'conditions' => ['when' => [], 'then' => [['kind' => 'require', 'target' => 'text', 'op' => 'contains', 'phrases' => ['hola']]]]];
        $this->assertSame([], K::check('hola', self::target(rules: [$exige])), 'require text no se comprueba aquí');

        $siVenta = self::forbid('S-011', ['ahora'], [['target' => 'config', 'layer' => 'strategy', 'key' => 'goal', 'op' => 'eq', 'value' => 'venta']]);
        $this->assertSame([], K::check('ahora', self::target(rules: [$siVenta])), 'la línea base orienta a cita: el «si» no se da');
        $venta = self::target(rules: [$siVenta]);
        $venta['configs']['strategy']['goal'] = 'venta';
        $this->assertSame(['lab-regla-S-011'], self::ids(K::check('ahora', $venta)));

        $siArista = self::forbid('S-012', ['enlace'], [['target' => 'edge', 'edge' => 'E14', 'op' => 'present']]);
        $this->assertSame([], K::check('enlace', self::target(rules: [$siArista])));
        $this->assertSame(['lab-regla-S-012'], self::ids(K::check('enlace', self::target(rules: [$siArista], edgeIds: ['E14']))));

        $siRango = self::forbid('S-013', ['largo'], [['target' => 'config', 'layer' => 'persona', 'key' => 'maxChars', 'op' => 'gte', 'value' => 400]]);
        $this->assertSame([], K::check('largo', self::target(rules: [$siRango])));
        $this->assertSame(['lab-regla-S-013'], self::ids(K::check('largo', self::target(['maxChars' => 420], [$siRango]))));

        $siEtiqueta = self::forbid('S-014', ['oferta'], [['target' => 'config', 'layer' => 'strategy', 'key' => 'offerPriority', 'op' => 'contains', 'value' => 'Plan insignia (is_recommended)']]);
        $this->assertSame(['lab-regla-S-014'], self::ids(K::check('oferta', self::target(rules: [$siEtiqueta]))));

        $variableAusente = self::forbid('S-015', ['nada'], [['target' => 'config', 'layer' => 'persona', 'key' => 'noExiste', 'op' => 'eq', 'value' => true]]);
        $this->assertSame([], K::check('nada', self::target(rules: [$variableAusente])), 'lo que no se puede comprobar no avisa');

        $documental = ['code' => 'S-016', 'conditions' => null];
        $this->assertSame([], K::check('nada', self::target(rules: [$documental])));
    }

    public function test_el_orden_de_los_hallazgos_es_el_de_la_tabla_y_las_citas_miden_120_como_mucho(): void
    {
        $largo = str_repeat('compra ya ', 40).'💪';
        $h = K::check($largo, self::target(['maxChars' => 160, 'emojis' => false], [self::forbid('S-020', ['compra ya compra ya'])]));

        $this->assertSame(['lab-longitud', 'lab-emojis', 'lab-regla-S-020'], self::ids($h));
        $this->assertSame('compra ya compra ya', $h[2]['quote']);
        foreach ($h as $hallazgo) {
            $this->assertContains($hallazgo['level'], K::LEVELS);
            $this->assertLessThanOrEqual(K::QUOTE_MAX, mb_strlen($hallazgo['quote'] ?? ''));
        }

        $cita = K::quote(str_repeat('ñ', 300));
        $this->assertSame(120, mb_strlen($cita));
        $this->assertStringEndsWith('…', $cita);
        $this->assertSame(str_repeat('ñ', 120), K::quote(str_repeat('ñ', 120)));
        $this->assertArrayNotHasKey('quote', K::finding('x', 'info', 't', 'd', ''), 'sin cita no hay clave');
    }
}
