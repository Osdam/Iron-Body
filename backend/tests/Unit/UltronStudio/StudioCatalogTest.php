<?php

namespace Tests\Unit\UltronStudio;

use App\Models\MarketingKnowledgeItem;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\UltronDecideService;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioDraftNormalizer;
use App\Services\UltronStudio\StudioGraphValidator as V;
use App\Services\UltronStudio\StudioRuleCoherence as R;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * El catálogo REAL de ULTRON STUDIO es coherente consigo mismo y con el motor
 * que describe, y es la transcripción exacta del que tenía el CRM salvo donde
 * el Hito G lo declara.
 *
 * LO QUE SE PROTEGE. Diez capas en orden, puertos y aristas que se
 * corresponden, el commit como segmento sellado, una línea base publicable que
 * refleja producción (enlaces de pago apagados), parámetros, configuración y
 * restricciones que se señalan entre sí, ninguna cadena que cartografíe el
 * servidor, y que nadie cambie un dato sin subir la versión. Desde G, además:
 * cada parámetro con su mando real (`bindings`) y lo declarado fiel al código,
 * las lecturas descritas, y las reglas del código con condiciones que
 * equivalen a su restricción y no se contradicen.
 */
class StudioCatalogTest extends TestCase
{
    private const ORDEN = ['knowledge', 'persona', 'intent', 'context', 'memory', 'strategy', 'rules', 'composer', 'integrations', 'governance'];

    /**
     * La huella de cada versión del catálogo.
     *
     * Si esta prueba falla porque cambió la huella, cambiaron los datos: sube
     * StudioCatalog::VERSION, añade aquí su huella, actualiza
     * tests/fixtures/ultron-studio/ (catalog-real.json y los casos «real») y
     * vuelve a exportar los fixtures del CRM. Un catálogo distinto con la misma
     * versión haría que un borrador validado con uno se lea con el otro.
     */
    private const HUELLAS = [
        '2026-10-18.1' => '704dd094ddbbd2a5ccbfec6de3989be15b078401dac9a74c0fd6316628268473',
        '2026-10-19.1' => '0c8a26bc462baac6c23f2a6260e29c0e3ed43d0b1c85899ba207bcb0e0c5ef70',
    ];

    /** Los parámetros que lee `GET engine/readings` (contrato G §A2, `params`): los únicos `live`. */
    private const EN_VIVO = ['intent.driver', 'rules.paymentLinksEnabled', 'governance.enabled', 'governance.canaryOnly', 'strategy.offerPriority'];

    /** Las lecturas extra del contrato G §A2, en su orden. */
    private const LECTURAS = ['governance.paused', 'governance.brake', 'rules.paymentCanary', 'knowledge.base', 'strategy.plans'];

    /** La lista prohibida de F, más la de G para los textos de mando y lecturas. */
    private const PROHIBIDO_F = ['config/marketing', 'php artisan', 'HMAC', 'wamid', 'NO-GO', 'frase del propietario', 'runbook', 'app/Services', 'app/Http'];

    private const PROHIBIDO_G = ['MARKETING_', 'ULTRON_STUDIO_', '.env', 'config('];

    public function test_ten_layers_in_their_order_all_critical_and_backed_by_real_components(): void
    {
        $capas = StudioCatalog::graph()['layers'];

        $this->assertSame(self::ORDEN, array_column($capas, 'id'));
        $this->assertSame(self::ORDEN, StudioCatalog::layerIds());
        $this->assertSame(range(1, 10), array_column($capas, 'order'));
        foreach ($capas as $l) {
            $this->assertTrue($l['critical'], $l['id']);
            $this->assertGreaterThanOrEqual(3, count($l['realComponents']), $l['id']);
            foreach ($l['realComponents'] as $c) {
                $this->assertNotSame('', $c['ref'] ?? '', "{$l['id']}: {$c['label']}");
            }
            $this->assertNotEmpty($l['fixedInCode'], $l['id']);
        }
    }

    public function test_ports_belong_to_existing_layers_with_stable_ids(): void
    {
        $vistos = [];
        foreach (StudioCatalog::graph()['ports'] as $p) {
            $this->assertContains($p['layer'], self::ORDEN);
            $this->assertSame("{$p['layer']}:{$p['direction']}:{$p['dataType']}", $p['id']);
            $this->assertArrayNotHasKey($p['id'], $vistos, "puerto duplicado {$p['id']}");
            $vistos[$p['id']] = true;
        }
        foreach (self::ORDEN as $capa) {
            $this->assertContains($capa, array_column(StudioCatalog::graph()['ports'], 'layer'), "{$capa} sin puertos");
        }
    }

    public function test_each_edge_goes_from_an_output_to_an_input_of_another_layer_with_the_same_type(): void
    {
        $g = StudioCatalog::graph();
        $puertos = array_column($g['ports'], null, 'id');
        $vistas = [];

        foreach ($g['edges'] as $e) {
            $s = $puertos[$e['source']] ?? null;
            $t = $puertos[$e['target']] ?? null;
            $this->assertNotNull($s, "{$e['id']}: salida {$e['source']}");
            $this->assertNotNull($t, "{$e['id']}: entrada {$e['target']}");
            $this->assertSame('out', $s['direction'], $e['id']);
            $this->assertSame('in', $t['direction'], $e['id']);
            $this->assertNotSame($s['layer'], $t['layer'], $e['id']);
            $this->assertSame($s['dataType'], $t['dataType'], $e['id']);
            $this->assertNotSame('', $e['label'], $e['id']);
            if (! $e['mandatory']) {
                $this->assertNotSame('', $e['consequence'], "{$e['id']} es opcional y no dice qué pasa sin ella");
            }
            $this->assertArrayNotHasKey($e['id'], $vistas, "arista duplicada {$e['id']}");
            $vistas[$e['id']] = true;
        }

        // Ninguna salida queda sin destino posible en el motor.
        foreach ($g['ports'] as $p) {
            if ($p['direction'] === 'out') {
                $this->assertContains($p['id'], array_column($g['edges'], 'source'), "{$p['id']} no alimenta a nadie");
            }
        }
    }

    public function test_the_commit_is_a_sealed_segment(): void
    {
        $aristas = StudioCatalog::graph()['edges'];

        foreach ($aristas as $e) {
            $this->assertFalse(
                str_starts_with($e['source'], 'composer:') && str_starts_with($e['target'], 'integrations:'),
                "{$e['id']}: nada va de la redacción al canal sin pasar por las reglas",
            );
        }
        $porExtremos = [];
        foreach ($aristas as $e) {
            $porExtremos[$e['source'].' → '.$e['target']] = $e;
        }
        $this->assertTrue($porExtremos['composer:out:decision → rules:in:decision']['mandatory']);
        $this->assertTrue($porExtremos['rules:out:reply → integrations:in:reply']['mandatory']);
        // El commit lee directamente de la memoria y del conocimiento.
        $this->assertTrue($porExtremos['memory:out:memory → rules:in:memory']['mandatory']);
        $this->assertTrue($porExtremos['knowledge:out:facts → rules:in:facts']['mandatory']);
    }

    public function test_the_baseline_reflects_production_publishable_and_only_warns_about_payment_links(): void
    {
        $g = StudioCatalog::graph();
        $base = StudioCatalog::baseline();
        $def = ['layers' => self::ORDEN, 'edgeIds' => $base['edgeIds']];

        $this->assertSame(['E14', 'E16'], array_values(array_diff(array_column($g['edges'], 'id'), $base['edgeIds'])), 'producción hoy: enlaces de pago apagados');

        $soloGrafo = V::validate($g, $def, null);
        $this->assertTrue(V::isPublishable($soloGrafo));
        $this->assertSame(['opcional-E14', 'opcional-E16'], V::warnings($soloGrafo));
        $this->assertNull(V::findCycle($g, V::presentEdges($g, $def)));
        foreach ($g['requiredPaths'] as $p) {
            $this->assertTrue(V::hasPath($g, V::presentEdges($g, $def), $p['from'], $p['to']), $p['id']);
        }

        $conConfig = V::validate($g, $def, $base['configs']);
        $this->assertTrue(V::isPublishable($conConfig));
        $this->assertSame(['opcional-E14', 'opcional-E16'], V::warnings($conConfig));
        $this->assertSame('ok', array_column($conConfig, 'level', 'id')['configuracion'] ?? null);

        // Con todo conectado (y sin configuración) no queda nada que no sea ok.
        $todo = V::validate($g, ['layers' => self::ORDEN, 'edgeIds' => array_column($g['edges'], 'id')], null);
        $this->assertSame([], array_values(array_filter($todo, static fn (array $f): bool => $f['level'] !== 'ok')));
    }

    public function test_factory_positions_sit_on_the_grid_without_overlapping_and_configs_cover_every_parameter(): void
    {
        $base = StudioCatalog::baseline();
        $parametros = StudioCatalog::parameters();
        $vistas = [];

        $this->assertSame(StudioCatalog::BASELINE_LABEL, $base['label']);
        $this->assertSame('LEGACY_BASELINE_V1', $base['label']);
        $this->assertStringContainsString('No es una versión', $base['description']);

        foreach (self::ORDEN as $capa) {
            $p = $base['positions'][$capa];
            $this->assertIsInt($p['x'], $capa);
            $this->assertIsInt($p['y'], $capa);
            $this->assertSame(0, $p['x'] % 16, $capa);
            $this->assertSame(0, $p['y'] % 16, $capa);
            $this->assertArrayNotHasKey("{$p['x']},{$p['y']}", $vistas, "{$capa} solapa otra capa");
            $vistas["{$p['x']},{$p['y']}"] = true;

            // Todo parámetro del inspector tiene su valor de línea base y viceversa.
            $claves = array_keys($base['configs'][$capa]);
            $this->assertSame(array_column($parametros[$capa], 'key'), $claves, $capa);
        }
        $this->assertSame(self::ORDEN, array_keys($base['configs']));
        $this->assertSame(self::ORDEN, array_keys($parametros));
    }

    public function test_parameters_configuration_and_constraints_agree(): void
    {
        $parametros = StudioCatalog::parameters();
        $base = StudioCatalog::baseline()['configs'];
        $restricciones = StudioCatalog::graph()['constraints'];

        foreach ($parametros as $capa => $lista) {
            foreach ($lista as $p) {
                $campo = "{$capa}.{$p['key']}";
                $valor = $base[$capa][$p['key']];
                $this->assertContains($p['type'], ['text', 'textarea', 'select', 'toggle', 'range', 'tags'], $campo);
                if (! empty($p['important'])) {
                    $this->assertNotEmpty($p['consequence'] ?? null, "{$campo} es importante y no explica su consecuencia");
                }
                match ($p['type']) {
                    'select' => $this->assertContains($valor, array_column($p['options'], 'value'), $campo),
                    'range' => $this->assertTrue(is_int($valor) && $valor >= $p['min'] && $valor <= $p['max'], $campo),
                    'toggle' => $this->assertIsBool($valor, $campo),
                    'tags' => $this->assertTrue(array_is_list($valor) && $valor === array_filter($valor, 'is_string'), $campo),
                    default => $this->assertIsString($valor, $campo),
                };
                // Toda consecuencia que promete validación la tiene.
                if (preg_match('/validaci/i', $p['consequence'] ?? '') === 1) {
                    $this->assertNotEmpty(array_filter($restricciones, static fn (array $c): bool => $c['layer'] === $capa && $c['key'] === $p['key']), $campo);
                }
            }
        }

        // Cada restricción apunta a un parámetro que existe, con un valor de su tipo.
        foreach ($restricciones as $c) {
            $this->assertContains($c['key'], array_column($parametros[$c['layer']] ?? [], 'key'), $c['id']);
            $this->assertContains($c['level'], ['warning', 'blocking'], $c['id']);
            $this->assertTrue(array_key_exists('forbidden', $c) || array_key_exists('when', $c), $c['id']);
            foreach ([...($c['requires'] ?? []), ...($c['forbids'] ?? [])] as $arista) {
                $this->assertContains($arista, array_column(StudioCatalog::graph()['edges'], 'id'), $c['id']);
            }
        }
    }

    public function test_it_does_not_map_the_server(): void
    {
        foreach (StudioCatalog::graph()['layers'] as $l) {
            foreach ($l['realComponents'] as $c) {
                $this->assertStringNotContainsString('/', $c['ref'], "{$l['id']}: {$c['label']}");
                $this->assertDoesNotMatchRegularExpression('/:\d/', $c['ref'], "{$l['id']}: {$c['label']}");
                $this->assertStringNotContainsString('.php', $c['ref'], "{$l['id']}: {$c['label']}");
            }
        }

        $todo = json_encode([StudioCatalog::toArray(), StudioCatalog::legacyRules()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ([...self::PROHIBIDO_F, ...self::PROHIBIDO_G] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $todo, $prohibido);
        }
    }

    public function test_binding_and_reading_texts_name_no_paths_env_variables_or_commands(): void
    {
        $textos = [];
        foreach (StudioCatalog::bindings() as $capa => $porClave) {
            foreach ($porClave as $clave => $b) {
                $textos["{$capa}.{$clave}.effect"] = $b['effect'];
                $textos["{$capa}.{$clave}.changeVia"] = $b['changeVia'];
            }
        }
        foreach (StudioCatalog::readings() as $l) {
            foreach (['label', 'effect', 'changeVia'] as $campo) {
                $textos["{$l['id']}.{$campo}"] = $l[$campo];
            }
        }

        $this->assertCount(21 * 2 + 5 * 3, $textos);
        foreach ($textos as $donde => $texto) {
            foreach ([...self::PROHIBIDO_F, ...self::PROHIBIDO_G, 'artisan', '.php'] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $texto, "{$donde}: {$prohibido}");
            }
            $this->assertStringNotContainsString('/', $texto, "{$donde}: una ruta");
            $this->assertDoesNotMatchRegularExpression('/:\d/', $texto, "{$donde}: un número de línea");
            // `ultron:abort`, `marketing:knowledge-review`… : un comando.
            $this->assertDoesNotMatchRegularExpression('/\b[a-z][a-z-]*:[a-z][a-z-]*\b/', $texto, "{$donde}: un comando");
            // MARKETING_ULTRON_ENABLED, N8N_WEBHOOK_SECRET…: una variable de entorno.
            $this->assertDoesNotMatchRegularExpression('/\b[A-Z][A-Z0-9]*_[A-Z0-9_]+\b/', $texto, "{$donde}: una variable de entorno");
        }
    }

    public function test_every_parameter_has_exactly_one_valid_binding(): void
    {
        $parametros = StudioCatalog::parameters();
        $bindings = StudioCatalog::bindings();
        $origenes = ['env_flag' => 0, 'engine_setting' => 0, 'engine_data' => 0, 'proposal' => 0, 'fixed' => 0];
        $enVivo = [];

        // Uno por parámetro, en el mismo orden, y ninguno sin parámetro.
        $this->assertSame(self::ORDEN, array_keys($bindings));
        foreach ($parametros as $capa => $lista) {
            $this->assertSame(array_column($lista, 'key'), array_keys($bindings[$capa]), $capa);
        }

        foreach ($bindings as $capa => $porClave) {
            $porParametro = array_column($parametros[$capa], null, 'key');
            foreach ($porClave as $clave => $b) {
                $campo = "{$capa}.{$clave}";
                $param = $porParametro[$clave];
                $this->assertArrayHasKey($b['origin'], $origenes, $campo);
                $origenes[$b['origin']]++;
                $this->assertContains($b['valueSource'], ['live', 'declared', 'none'], $campo);
                $this->assertIsBool($b['comparable'], $campo);
                $this->assertIsString($b['effect'], $campo);
                $this->assertIsString($b['changeVia'], $campo);
                $this->assertNotSame('', trim($b['effect']), $campo);
                $this->assertNotSame('', trim($b['changeVia']), $campo);
                $this->assertLessThanOrEqual(240, mb_strlen($b['effect']), "{$campo}: effect ≤ 240");
                $this->assertLessThanOrEqual(120, mb_strlen($b['changeVia']), "{$campo}: changeVia ≤ 120");

                $esperadas = ['origin', 'valueSource'];
                if ($b['valueSource'] === 'declared') {
                    $esperadas[] = 'declaredValue';
                    $this->assertTrue(self::admite($param, $b['declaredValue']), "{$campo}: declaredValue no pasa el normalizador de su tipo");
                    $this->assertTrue($b['comparable'], "{$campo}: un valor declarado se puede comparar");
                }
                if ($b['valueSource'] === 'live') {
                    $enVivo[] = $campo;
                }
                if ($b['valueSource'] === 'none') {
                    $this->assertFalse($b['comparable'], "{$campo}: sin valor no hay «≠ motor»");
                }
                if (array_key_exists('engineOption', $b)) {
                    $esperadas[] = 'engineOption';
                    $this->assertSame('intent.driver', $campo, 'solo el driver tiene opción «lo que tenga el motor»');
                    $this->assertTrue(self::admite($param, $b['engineOption']), "{$campo}: engineOption es una opción del parámetro");
                }
                // Las claves del contrato, en su orden; las opcionales se omiten.
                $this->assertSame([...$esperadas, 'comparable', 'effect', 'changeVia'], array_keys($b), $campo);
            }
        }

        $this->assertSame(['env_flag' => 4, 'engine_setting' => 0, 'engine_data' => 1, 'proposal' => 5, 'fixed' => 11], $origenes);
        $esperadosEnVivo = self::EN_VIVO;
        sort($esperadosEnVivo);
        sort($enVivo);
        $this->assertSame($esperadosEnVivo, $enVivo, '`live` solo en lo que lee GET engine/readings');
        $this->assertSame('entorno', $bindings['intent']['driver']['engineOption']);
        $this->assertFalse($bindings['strategy']['offerPriority']['comparable'], 'los nombres de los planes no se comparan con las etiquetas');
    }

    public function test_the_declared_values_are_the_ones_of_the_contract_table(): void
    {
        $declarados = [];
        foreach (StudioCatalog::bindings() as $capa => $porClave) {
            foreach ($porClave as $clave => $b) {
                if ($b['valueSource'] === 'declared') {
                    $declarados["{$capa}.{$clave}"] = $b['declaredValue'];
                }
            }
        }

        $this->assertSame([
            'knowledge.strictFacts' => true,
            'context.windowTurns' => 10,
            'memory.useLeadProfile' => true,
            'strategy.discountsAllowed' => false,
            'rules.handoffOnlyCorroborated' => true,
            'integrations.channel' => 'whatsapp',
            'integrations.appointments' => true,
            'governance.autoBrake' => true,
        ], $declarados);

        // Lo declarado no contradice los controles del motor: con esos valores
        // la línea base sigue sin bloqueos de configuración.
        $configs = StudioCatalog::baseline()['configs'];
        foreach ($declarados as $campo => $valor) {
            [$capa, $clave] = explode('.', $campo);
            $configs[$capa][$clave] = $valor;
        }
        $def = ['layers' => self::ORDEN, 'edgeIds' => StudioCatalog::baseline()['edgeIds']];
        $this->assertTrue(V::isPublishable(V::validate(StudioCatalog::graph(), $def, $configs)));
    }

    public function test_declared_values_have_not_drifted_from_the_engine_code(): void
    {
        // La cortesía de valoración siempre está permitida (integrations.appointments = sí).
        $this->assertContains(SalesIntents::TOOL_COURTESY_REQUEST, UltronDecideService::V1_ALLOWED_TOOLS);

        // Diez mensajes de contexto (context.windowTurns = 10): `recentMessages` sigue con limit(10).
        $fuente = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Marketing/Ultron/UltronDecideService.php');
        $tokens = array_values(array_filter(token_get_all($fuente), static fn (mixed $t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $texto = static fn (mixed $t): string => is_array($t) ? $t[1] : $t;

        $inicio = null;
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_FUNCTION && $texto($tokens[$i + 1] ?? '') === 'recentMessages') {
                $inicio = $i;
                break;
            }
        }
        $this->assertNotNull($inicio, 'UltronDecideService::recentMessages ya no existe: revisa el binding de context.windowTurns');

        $profundidad = 0;
        $cuerpo = [];
        for ($i = $inicio; $i < count($tokens); $i++) {
            $s = $texto($tokens[$i]);
            if ($s === '{' || (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $profundidad++;
            } elseif ($s === '}') {
                $profundidad--;
                if ($profundidad === 0) {
                    break;
                }
            }
            if ($profundidad > 0) {
                $cuerpo[] = $s;
            }
        }
        $this->assertStringContainsString('->limit(10)', implode('', $cuerpo), 'el motor ya no pasa 10 mensajes: revisa el binding de context.windowTurns');
    }

    public function test_the_engine_readings_are_described(): void
    {
        $lecturas = StudioCatalog::readings();
        $claves = [];
        foreach (StudioCatalog::parameters() as $capa => $lista) {
            foreach ($lista as $p) {
                $claves[] = "{$capa}.{$p['key']}";
            }
        }

        $this->assertSame(self::LECTURAS, array_column($lecturas, 'id'));
        foreach ($lecturas as $l) {
            $this->assertSame(['id', 'layer', 'label', 'origin', 'effect', 'changeVia'], array_keys($l), $l['id']);
            $this->assertContains($l['layer'], self::ORDEN, $l['id']);
            $this->assertStringStartsWith($l['layer'].'.', $l['id']);
            $this->assertContains($l['origin'], ['env_flag', 'engine_setting', 'engine_data', 'proposal', 'fixed'], $l['id']);
            $this->assertNotContains($l['id'], $claves, "{$l['id']}: una lectura extra no es un parámetro");
            $this->assertNotSame('', trim($l['label']), $l['id']);
            $this->assertLessThanOrEqual(240, mb_strlen($l['effect']), $l['id']);
            $this->assertLessThanOrEqual(120, mb_strlen($l['changeVia']), $l['id']);
        }
        $this->assertSame(
            ['engine_setting', 'engine_setting', 'env_flag', 'engine_data', 'engine_data'],
            array_column($lecturas, 'origin'),
        );
    }

    public function test_only_the_four_equivalent_constraints_name_a_legacy_rule(): void
    {
        $conRegla = [];
        foreach (StudioCatalog::graph()['constraints'] as $c) {
            if (array_key_exists('rule', $c)) {
                $conRegla[$c['id']] = $c['rule'];
            }
        }

        $this->assertSame(['hechos' => 'R-01', 'descuentos' => 'R-02', 'derivacion' => 'R-07', 'canario' => 'R-12'], $conRegla);
        foreach ($conRegla as $id => $codigo) {
            $this->assertArrayHasKey($codigo, StudioCatalog::legacyConditions(), "{$id} → {$codigo} sin condiciones");
        }
    }

    public function test_each_legacy_assertion_allows_exactly_what_its_constraint_allows(): void
    {
        $catalogo = StudioCatalog::toArray();
        $controles = array_column(R::controlRules($catalogo), 'conditions', 'with');

        foreach (StudioCatalog::graph()['constraints'] as $c) {
            if (! array_key_exists('rule', $c)) {
                continue;
            }
            $restriccion = array_column(R::allowedSets($controles['control:'.$c['id']], $catalogo), 'allowed', 'target');
            $regla = array_column(R::allowedSets(StudioCatalog::legacyConditions()[$c['rule']], $catalogo), 'allowed', 'target');
            $objetivo = "config:{$c['layer']}.{$c['key']}";

            $this->assertArrayHasKey($objetivo, $regla, "{$c['rule']} no afirma {$objetivo}");
            $this->assertSame($restriccion[$objetivo], $regla[$objetivo], "{$c['rule']} ≠ {$c['id']}");
        }
    }

    public function test_the_twelve_legacy_rules_have_canonical_conditions_and_no_issues(): void
    {
        $catalogo = StudioCatalog::toArray();
        $reglas = array_map(static fn (array $r): array => [
            'code' => $r['code'], 'source' => 'legacy_baseline', 'enforcement' => 'code', 'status' => 'active',
            'description' => $r['description'], 'layers' => $r['layers'], 'conditions' => $r['conditions'], 'refs' => $r['refs'],
        ], StudioCatalog::legacyRules());

        $legacyConditions = [];
        foreach (StudioCatalog::legacyRules() as $r) {
            $this->assertArrayHasKey('conditions', $r, $r['code']);
            if ($r['conditions'] === null) {
                continue;
            }
            $legacyConditions[$r['code']] = $r['conditions'];
            // Ya están en su forma canónica y respetan sus propias capas.
            $this->assertSame(['conditions' => $r['conditions'], 'errors' => []], R::check($r['conditions'], $catalogo, $r['layers']), $r['code']);
        }
        $this->assertSame(['R-01', 'R-02', 'R-07', 'R-12'], array_keys($legacyConditions));
        $this->assertSame($legacyConditions, StudioCatalog::legacyConditions());

        $analisis = R::analyzeAll($reglas, $catalogo);
        foreach ($analisis as $codigo => $a) {
            $this->assertSame([], $a['issues'], "{$codigo} no tiene contradicciones ni incompatibilidades");
        }
        $this->assertSame(
            ['R-01' => 'studio', 'R-02' => 'studio', 'R-03' => 'none', 'R-04' => 'none', 'R-05' => 'none', 'R-06' => 'none',
                'R-07' => 'studio', 'R-08' => 'none', 'R-09' => 'none', 'R-10' => 'none', 'R-11' => 'none', 'R-12' => 'studio'],
            array_map(static fn (array $a): string => $a['verification'], $analisis),
        );

        // Cada restricción `blocking` se traduce en un control, y cada regla con condiciones también.
        $bloqueo = array_values(array_filter(StudioCatalog::graph()['constraints'], static fn (array $c): bool => $c['level'] === 'blocking'));
        $this->assertSame(
            [...array_map(static fn (array $c): string => 'control:'.$c['id'], $bloqueo), 'R-01', 'R-02', 'R-07', 'R-12'],
            array_column(R::controlRules($catalogo), 'with'),
        );
    }

    public function test_the_version_is_pinned_to_its_hash(): void
    {
        $this->assertArrayHasKey(StudioCatalog::VERSION, self::HUELLAS, 'Versión nueva del catálogo: añade su huella a HUELLAS y exporta los fixtures del CRM.');
        $this->assertSame(
            self::HUELLAS[StudioCatalog::VERSION],
            StudioCatalog::hash(),
            'Los datos del catálogo cambiaron sin subir StudioCatalog::VERSION.',
        );

        // La huella cubre todo lo que se sirve, y es la que viaja en `GET catalog`.
        $servido = StudioCatalog::toArray();
        $this->assertSame(StudioCatalog::hash(), $servido['hash']);
        unset($servido['hash']);
        $this->assertSame(StudioCatalog::hash(), StudioContentHasher::hash($servido));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', StudioCatalog::hash());
    }

    public function test_to_array_has_the_shape_of_get_catalog_and_omits_optional_keys(): void
    {
        $catalogo = StudioCatalog::toArray();

        $this->assertSame(['version', 'hash', 'layers', 'ports', 'edges', 'requiredPaths', 'constraints', 'parameters', 'baseline', 'bindings', 'readings', 'legacyConditions'], array_keys($catalogo));
        $this->assertSame(StudioCatalog::bindings(), $catalogo['bindings']);
        $this->assertSame(StudioCatalog::readings(), $catalogo['readings']);
        $this->assertSame(StudioCatalog::legacyConditions(), $catalogo['legacyConditions']);
        $this->assertSame(StudioCatalog::VERSION, $catalogo['version']);
        $this->assertSame(['label', 'description', 'positions', 'edgeIds', 'configs'], array_keys($catalogo['baseline']));
        $this->assertSame(
            ['layers', 'ports', 'edges', 'requiredPaths', 'constraints'],
            array_keys(StudioCatalog::graph()),
        );

        // Las opcionales se omiten donde no aplican: ninguna vale false.
        $porId = array_column($catalogo['edges'], null, 'id');
        $this->assertTrue($porId['E01']['opensTurn']);
        $this->assertArrayNotHasKey('feedback', $porId['E01']);
        foreach (['E15', 'E16', 'E17'] as $id) {
            $this->assertTrue($porId[$id]['feedback'], $id);
            $this->assertArrayNotHasKey('opensTurn', $porId[$id], $id);
        }
        foreach ($catalogo['edges'] as $e) {
            foreach (['feedback', 'opensTurn'] as $clave) {
                $this->assertTrue(! array_key_exists($clave, $e) || $e[$clave] === true, "{$e['id']}.{$clave}");
            }
        }
        foreach ($catalogo['parameters'] as $capa => $lista) {
            foreach ($lista as $p) {
                $campo = "{$capa}.{$p['key']}";
                $this->assertTrue(! array_key_exists('important', $p) || $p['important'] === true, $campo);
                if ($p['type'] !== 'select') {
                    $this->assertArrayNotHasKey('options', $p, $campo);
                }
                if ($p['type'] !== 'range') {
                    $this->assertSame([], array_intersect(['min', 'max', 'step'], array_keys($p)), $campo);
                }
            }
        }
        $emojis = array_column($catalogo['parameters']['persona'], null, 'key')['emojis'];
        $this->assertSame(['key', 'label', 'hint', 'type'], array_keys($emojis));
    }

    public function test_the_twelve_legacy_rules_point_at_things_that_exist(): void
    {
        $reglas = StudioCatalog::legacyRules();
        $parametros = StudioCatalog::parameters();
        $aristas = array_column(StudioCatalog::graph()['edges'], 'id');

        $this->assertSame(array_map(static fn (int $n): string => sprintf('R-%02d', $n), range(1, 12)), array_column($reglas, 'code'));
        foreach ($reglas as $r) {
            $this->assertNotEmpty($r['layers'], $r['code']);
            foreach ($r['layers'] as $capa) {
                $this->assertContains($capa, self::ORDEN, $r['code']);
            }
            $this->assertContains($r['type'], ['restriction', 'permission', 'condition', 'style'], $r['code']);
            $this->assertContains($r['priority'], ['critical', 'high', 'normal'], $r['code']);
            $this->assertNotSame('', $r['origin'], $r['code']);
            $this->assertSame('active', $r['status'], $r['code']);
            $this->assertSame(['code', 'description', 'type', 'layers', 'priority', 'origin', 'status', 'refs', 'conditions'], array_keys($r), $r['code']);
            $this->assertSame(['configs', 'edges', 'knowledgeKeys', 'knowledgeCategories'], array_keys($r['refs']), $r['code']);
            foreach ($r['refs']['configs'] as $ref) {
                $this->assertSame(['layer', 'key'], array_keys($ref), $r['code']);
                $this->assertContains($ref['key'], array_column($parametros[$ref['layer']] ?? [], 'key'), $r['code']);
            }
            foreach ($r['refs']['edges'] as $arista) {
                $this->assertContains($arista, $aristas, $r['code']);
            }
            foreach ($r['refs']['knowledgeCategories'] as $categoria) {
                $this->assertContains($categoria, MarketingKnowledgeItem::CATEGORIES, $r['code']);
            }
            $this->assertSame([], $r['refs']['knowledgeKeys'], $r['code']);
        }

        // Las referencias sugeridas por el contrato de F, más las aristas que
        // añade la tabla de transcripción de G (auditoría §5), y ninguna más.
        $conRefs = [];
        foreach ($reglas as $r) {
            if ($r['refs']['configs'] || $r['refs']['edges'] || $r['refs']['knowledgeCategories']) {
                $conRefs[$r['code']] = $r['refs'];
            }
        }
        $refs = static fn (array $configs, array $edges = [], array $categories = []): array => ['configs' => $configs, 'edges' => $edges, 'knowledgeKeys' => [], 'knowledgeCategories' => $categories];
        $this->assertSame([
            'R-01' => $refs([['layer' => 'knowledge', 'key' => 'strictFacts']], ['E07', 'E19']),
            'R-02' => $refs([['layer' => 'strategy', 'key' => 'discountsAllowed']]),
            'R-04' => $refs([], ['E18']),
            'R-05' => $refs([], ['E18']),
            'R-07' => $refs([['layer' => 'rules', 'key' => 'handoffOnlyCorroborated']]),
            'R-09' => $refs([], ['E18']),
            'R-10' => $refs([], ['E08', 'E19'], ['brand_copy', 'tone']),
            'R-12' => $refs([['layer' => 'governance', 'key' => 'canaryOnly']], ['E12', 'E13']),
        ], $conRefs);

        // Las condiciones de la tabla de transcripción (contrato G §A3), sin inventar.
        $requiere = static fn (string $layer, string $key, bool $value): array => ['kind' => 'require', 'target' => 'config', 'layer' => $layer, 'key' => $key, 'op' => 'eq', 'value' => $value];
        $this->assertSame([
            'R-01' => ['when' => [], 'then' => [$requiere('knowledge', 'strictFacts', true)]],
            'R-02' => ['when' => [], 'then' => [
                $requiere('strategy', 'discountsAllowed', false),
                ['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => ['gratis', '2x1', 'precio especial']],
            ]],
            'R-03' => null, 'R-04' => null, 'R-05' => null, 'R-06' => null,
            'R-07' => ['when' => [], 'then' => [$requiere('rules', 'handoffOnlyCorroborated', true)]],
            'R-08' => null, 'R-09' => null, 'R-10' => null, 'R-11' => null,
            'R-12' => ['when' => [], 'then' => [$requiere('governance', 'canaryOnly', true)]],
        ], array_column($reglas, 'conditions', 'code'));
        // Las tres frases de R-02 están en su descripción.
        foreach (['gratis', '2x1', 'precio especial'] as $frase) {
            $this->assertStringContainsString("«{$frase}»", $reglas[1]['description']);
        }
    }

    /**
     * La transcripción de F (el volcado de `studio-catalog.ts`@4f7d774) solo
     * cambia donde G lo declara (contrato G §A2 y §A7): la clave `rule` de las
     * restricciones, `conditions` y `refs` de las reglas, y exactamente dos
     * textos de `intent.driver`.
     */
    public function test_the_f_transcription_only_changes_where_g_declares_it(): void
    {
        $ts = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/ultron-studio/catalog-real.json'), true, 512, JSON_THROW_ON_ERROR);

        // Igualdad de DATOS: el orden de las claves no cuenta; el de las listas sí.
        $grafo = StudioCatalog::graph();
        $grafo['constraints'] = array_map(static function (array $c): array {
            unset($c['rule']);

            return $c;
        }, $grafo['constraints']);
        $this->assertSame(self::ordenar($ts['STUDIO_CATALOG']), self::ordenar($grafo));

        // Parámetros: solo cambian la pista del driver y la etiqueta de `entorno`.
        $diferencias = self::diferencias($ts['LAYER_PARAMETERS'], StudioCatalog::parameters());
        $this->assertSame([
            'intent.0.hint' => [
                'En producción se elige por entorno (hermes → openai → reglas); el valor efectivo no se ha verificado desde el Studio.',
                'En producción lo elige el entorno (hermes → openai → reglas); el inspector enseña el valor efectivo que lee el servidor.',
            ],
            'intent.0.options.0.label' => ['Según el entorno (no verificado)', 'Según el entorno'],
        ], $diferencias);
        $this->assertStringNotContainsString('verificado', json_encode(StudioCatalog::parameters()['intent'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $base = StudioCatalog::baseline();
        unset($base['label'], $base['description']);
        $this->assertSame(self::ordenar($ts['STUDIO_DEFAULTS']), self::ordenar($base));

        // Las reglas: las del CRM sin su marca `demo` (`id` es `code`); `refs` y `conditions` son nuevos.
        $esperadas = array_map(static function (array $r): array {
            $r['code'] = $r['id'];
            unset($r['id'], $r['demo']);

            return $r;
        }, $ts['DEMO_RULES']);
        $transcritas = array_map(static function (array $r): array {
            unset($r['refs'], $r['conditions']);

            return $r;
        }, StudioCatalog::legacyRules());
        $this->assertSame(self::ordenar($esperadas), self::ordenar($transcritas));
    }

    /**
     * Las hojas que difieren entre dos datos, por ruta (`capa.0.hint`), con
     * `[antes, después]`. Una clave que falta en un lado cuenta como null.
     *
     * @return array<string,array{0:mixed,1:mixed}>
     */
    private static function diferencias(mixed $antes, mixed $despues, string $ruta = ''): array
    {
        if (! is_array($antes) || ! is_array($despues)) {
            return $antes === $despues ? [] : [ltrim($ruta, '.') => [$antes, $despues]];
        }
        $out = [];
        foreach (array_unique([...array_keys($antes), ...array_keys($despues)]) as $clave) {
            $out += self::diferencias($antes[$clave] ?? null, $despues[$clave] ?? null, "{$ruta}.{$clave}");
        }

        return $out;
    }

    /** ¿Pasa `$valor` el normalizador del borrador para este parámetro? */
    private static function admite(array $param, mixed $valor): bool
    {
        $capa = null;
        foreach (StudioCatalog::parameters() as $id => $lista) {
            if (in_array($param, $lista, true)) {
                $capa = $id;
            }
        }

        try {
            $limpio = StudioDraftNormalizer::normalize([
                'definition' => ['edgeIds' => StudioCatalog::baseline()['edgeIds']],
                'configs' => [$capa => [$param['key'] => $valor]],
            ]);
        } catch (Throwable) {
            // Un rechazo del normalizador (o cualquier fallo) es «no lo admite»: la prueba lo dirá.
            return false;
        }

        return $limpio['configs'][$capa][$param['key']] === $valor;
    }

    /** Ordena las claves de los objetos, recursivamente; las listas quedan como están. */
    private static function ordenar(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map(self::ordenar(...), $v);
        if (! array_is_list($v)) {
            ksort($v, SORT_STRING);
        }

        return $v;
    }
}
