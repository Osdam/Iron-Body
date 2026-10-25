<?php

namespace Tests\Unit\UltronStudio;

use App\Services\Marketing\Ultron\ComposerStyleGuard;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioLabChecks as K;
use App\Services\UltronStudio\StudioLabEngineChecks;
use App\Services\UltronStudio\StudioLabOutcome;
use App\Services\UltronStudio\StudioLabPersonalData;
use App\Services\UltronStudio\StudioRuleCoherence;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Las pruebas deterministas del Studio (contrato H4 §A4 y Enmienda 4.2):
 * `lab-invisibles`, `lab-vacia`, `lab-longitud`, `lab-emojis` y
 * `lab-regla-<S-NNN>`.
 *
 * LO QUE SE PROTEGE. Niveles y disparos de la tabla; que los emojis se
 * cuenten con los MISMOS rangos que la guardia de estilo del motor y la
 * respuesta se canonicalice como las frases de G (comparados por
 * reflexión: si uno cambia, esto falla); que solo cuenten las afirmaciones
 * `forbid text contains` de las S-NNN cuyo «si» se da; que un invisible (la
 * clase del filtro de entrada menos el ZWJ: Enmienda 6.2) bloquee aunque las
 * guardas del motor no vean nada, y que el ZWJ de los emojis compuestos no; y
 * la forma de cada hallazgo.
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

    public function test_cada_invisible_de_la_enmienda_bloquea_con_su_forma_y_sin_cita(): void
    {
        $lista = [
            0x202A, 0x202B, 0x202C, 0x202D, 0x202E, 0x2066, 0x2067, 0x2068, 0x2069, 0x061C, // bidi
            0x200E, 0x200F,                                                                 // LRM y RLM (decisión del lead)
            0x200B, 0x200C, 0x2060, 0xFEFF,                                                 // anchura cero
            0x00AD, 0x180E,                                                                 // guion suave, separador mongol
            0xE0000, 0xE0001, 0xE0020, 0xE0041, 0xE007F,                                    // etiquetas (bordes y dentro)
            0x2028, 0x2029,                                                                 // separadores de línea y párrafo
            // Enmienda 6.2 (I-2 del reviewer): la clase del filtro de entrada.
            0x2061, 0x2062, 0x2063, 0x2064,                                                 // operadores invisibles
            0x206A, 0x206F, 0xFFF9, 0xFFFB, 0x0600, 0x06DD, 0x070F, 0x110BD, 0x1D173,      // otros de formato (Cf)
            0x0000, 0x0008, 0x000B, 0x000C, 0x000E, 0x001F, 0x007F, 0x0085, 0x009F,        // controles C0/C1 salvo \t \n \r
        ];
        foreach ($lista as $cp) {
            $h = K::check('Hola, '.mb_chr($cp, 'UTF-8').'te ayudo con gusto', self::target());
            $this->assertSame(['lab-invisibles'], self::ids($h), sprintf('U+%04X', $cp));
            $this->assertSame([
                'id' => 'lab-invisibles',
                'source' => 'studio',
                'level' => 'blocking',
                'title' => 'Caracteres invisibles en la respuesta',
                'detail' => 'La respuesta contiene caracteres de control o invisibles que pueden ocultar texto a los filtros',
            ], $h[0], sprintf('U+%04X: forma literal y SIN cita (repetiría el invisible)', $cp));
        }

        // Va PRIMERO: un BOM solo es invisible y además deja la respuesta vacía.
        $this->assertSame(['lab-invisibles', 'lab-vacia'], self::ids(K::check("\u{FEFF}", self::target())));
        $h = K::check("compra ya\u{200B} 💪", self::target(['emojis' => false], [self::forbid('S-030', ['compra ya'])]));
        $this->assertSame(['lab-invisibles', 'lab-emojis', 'lab-regla-S-030'], self::ids($h));
    }

    public function test_el_zwj_de_los_emojis_compuestos_y_los_espacios_tipograficos_no_son_invisibles(): void
    {
        // La mujer levantando pesas, el hombre corriendo y la familia (con U+200D y U+FE0F): avisan como emojis, nunca como invisibles.
        foreach (["\u{1F3CB}\u{FE0F}\u{200D}\u{2640}\u{FE0F}", "\u{1F3C3}\u{200D}\u{2642}\u{FE0F}", "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"] as $emoji) {
            $this->assertSame([], K::check("¡Vamos! {$emoji}", self::target(['emojis' => true])), json_encode($emoji));
            $this->assertSame(['lab-emojis'], self::ids(K::check("¡Vamos! {$emoji}", self::target(['emojis' => false]))), json_encode($emoji));
        }
        $this->assertSame([], K::check("a\u{200D}b", self::target()), 'el ZWJ suelto tampoco: la enmienda lo deja fuera');

        // Lo que escribe el español de verdad: espacio duro, espacio fino y estrecho, salto de línea.
        $this->assertSame([], K::check("La mensualidad es de {{PLAN_PRICE}}\u{00A0}al mes.\nTe espero a las 6\u{202F}p.\u{2009}m.", self::target()));
    }

    public function test_un_invisible_bloquea_aunque_las_guardas_del_motor_no_vean_nada(): void
    {
        $hechos = ['paymentState' => 'none', 'handoffRequested' => false];
        // Sonda de security (B-2): el precio escrito al revés entre controles bidi
        // se PINTA «$80.000» y las guardas del motor no lo ven.
        $alReves = "Vale \u{202E}000.08\$\u{202C} al mes";
        $this->assertSame([], (new StudioLabEngineChecks)->check($alReves, $hechos), 'si el motor lo viera, la sonda ya no reproduce el hueco');
        $this->assertNotSame([], (new StudioLabEngineChecks)->check('Vale $80.000 al mes', $hechos), 'sin el truco, el motor sí marca el precio');

        $h = [...K::check($alReves, self::target()), ...(new StudioLabEngineChecks)->check($alReves, $hechos)];
        $this->assertSame(['lab-invisibles'], self::ids($h));
        $this->assertSame(StudioLabOutcome::FAIL, StudioLabOutcome::forCase($h, null, []), 'un bloqueante gana siempre');

        // Sonda P09 de api-security: con un ZWSP en medio, ni el descuento ni el
        // pago se reconocen; con un LRM o un RLM, igual (por eso también cuentan).
        foreach ([
            "Te doy un des\u{200B}cuento del 20%", "Listo, tu pago ya lle\u{200B}gó y quedó con\u{200B}firmado",
            "Te doy un des\u{200E}cuento del 20%", "Te doy un des\u{200F}cuento del 20%", "Listo, tu pago ya lle\u{200E}gó y quedó con\u{200F}firmado",
            // I-2 del reviewer (Enmienda 6.2): un separador invisible y un control C0, que la lista de la 4.2 dejaba pasar.
            "Te hago un des\u{2063}cuento del 20%", "Te hago un des\u{0008}cuento del 20%",
        ] as $reply) {
            $this->assertSame([], (new StudioLabEngineChecks)->check($reply, $hechos), json_encode($reply));
            $this->assertSame(['lab-invisibles'], self::ids(K::check($reply, self::target())), json_encode($reply));
        }
        $this->assertContains('motor-machine_reply_invented_discount', self::ids((new StudioLabEngineChecks)->check('Te hago un descuento del 20%', $hechos)), 'sin el truco, el motor sí ve el descuento');
    }

    public function test_la_clase_es_la_del_filtro_de_entrada_menos_el_zwj(): void
    {
        // Enmienda 6.2: punto de código a punto de código (sin las suplentes),
        // `lab-invisibles` dispara exactamente donde el filtro de entrada
        // rechaza, salvo en el ZWJ. Si uno de los dos cambia, esto falla.
        $distintos = [];
        $marcados = 0;
        for ($cp = 0; $cp <= 0x10FFFF; $cp++) {
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                continue;
            }
            $c = mb_chr($cp, 'UTF-8');
            $esperado = $cp !== 0x200D && preg_match(StudioLabPersonalData::INVISIBLE_PATTERN, $c) === 1;
            $real = preg_match(K::INVISIBLE_PATTERN, $c) === 1;
            if ($esperado !== $real) {
                $distintos[] = sprintf('U+%04X', $cp);
            }
            $marcados += $real ? 1 : 0;
        }
        $this->assertSame([], $distintos);
        $this->assertGreaterThan(200, $marcados, 'la clase no puede quedar vacía');
        foreach (["\t", "\n", "\r", "\u{200D}", "\u{FE0F}", ' ', "\u{00A0}"] as $no) {
            $this->assertSame(0, preg_match(K::INVISIBLE_PATTERN, $no), json_encode($no));
        }
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
