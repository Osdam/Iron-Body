<?php

namespace Tests\Unit\UltronStudio;

use App\Models\MarketingKnowledgeItem;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioGraphValidator as V;
use PHPUnit\Framework\TestCase;

/**
 * El catálogo REAL de ULTRON STUDIO es coherente consigo mismo y con el motor
 * que describe, y es la transcripción exacta del que tenía el CRM.
 *
 * LO QUE SE PROTEGE. Diez capas en orden, puertos y aristas que se
 * corresponden, el commit como segmento sellado, una línea base publicable que
 * refleja producción (enlaces de pago apagados), parámetros, configuración y
 * restricciones que se señalan entre sí, ninguna cadena que cartografíe el
 * servidor, y que nadie cambie un dato sin subir la versión.
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
    ];

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
        foreach (['config/marketing', 'php artisan', 'HMAC', 'wamid', 'NO-GO', 'frase del propietario', 'runbook', 'app/Services', 'app/Http'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $todo, $prohibido);
        }
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

        $this->assertSame(['version', 'hash', 'layers', 'ports', 'edges', 'requiredPaths', 'constraints', 'parameters', 'baseline'], array_keys($catalogo));
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

        // Las referencias sugeridas por el contrato, y ninguna más.
        $conRefs = [];
        foreach ($reglas as $r) {
            if ($r['refs']['configs'] || $r['refs']['edges'] || $r['refs']['knowledgeCategories']) {
                $conRefs[$r['code']] = $r['refs'];
            }
        }
        $config = static fn (string $layer, string $key): array => ['configs' => [['layer' => $layer, 'key' => $key]], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []];
        $this->assertSame([
            'R-01' => $config('knowledge', 'strictFacts'),
            'R-02' => $config('strategy', 'discountsAllowed'),
            'R-07' => $config('rules', 'handoffOnlyCorroborated'),
            'R-10' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => ['brand_copy', 'tone']],
            'R-12' => $config('governance', 'canaryOnly'),
        ], $conRefs);
    }

    public function test_the_transcription_matches_the_ts_reference_dump(): void
    {
        $ts = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/ultron-studio/catalog-real.json'), true, 512, JSON_THROW_ON_ERROR);

        // Igualdad de DATOS: el orden de las claves no cuenta; el de las listas sí.
        $this->assertSame(self::ordenar($ts['STUDIO_CATALOG']), self::ordenar(StudioCatalog::graph()));
        $this->assertSame(self::ordenar($ts['LAYER_PARAMETERS']), self::ordenar(StudioCatalog::parameters()));

        $base = StudioCatalog::baseline();
        unset($base['label'], $base['description']);
        $this->assertSame(self::ordenar($ts['STUDIO_DEFAULTS']), self::ordenar($base));

        // Las reglas: las del CRM sin su marca `demo` (`id` es `code`); `refs` es nuevo.
        $esperadas = array_map(static function (array $r): array {
            $r['code'] = $r['id'];
            unset($r['id'], $r['demo']);

            return $r;
        }, $ts['DEMO_RULES']);
        $transcritas = array_map(static function (array $r): array {
            unset($r['refs']);

            return $r;
        }, StudioCatalog::legacyRules());
        $this->assertSame(self::ordenar($esperadas), self::ordenar($transcritas));
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
