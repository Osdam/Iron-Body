<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher as H;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Las huellas del Studio.
 *
 * LO QUE SE PROTEGE. Que la huella identifique el COMPORTAMIENTO y nada más:
 * ni el orden de las claves, ni el de las aristas, ni la disposición del
 * lienzo la cambian; el orden de una lista de etiquetas sí. Y que sea
 * reproducible fuera de PHP: los vectores conocidos de abajo se calcularon con
 * node (JSON.stringify sobre claves ordenadas + sha256) a partir del volcado
 * del catálogo del CRM, no con esta clase.
 *
 * Hito G (contrato §A7): el formato 1 es EXACTAMENTE el de F (los vectores de
 * F siguen saliendo con las entradas de F), el formato 2 añade `conditions` en
 * cada regla y `format` en el comportamiento, y una regla sin condiciones
 * conserva su huella de F.
 */
class StudioContentHasherTest extends TestCase
{
    /** La versión del catálogo con la que se calcularon los vectores de F. */
    private const CATALOGO_F = '2026-10-18.1';

    private static function borrador(): array
    {
        $base = StudioCatalog::baseline();

        return [
            'definition' => ['layers' => StudioCatalog::layerIds(), 'edgeIds' => $base['edgeIds']],
            'configs' => $base['configs'],
            'layout' => ['positions' => $base['positions'], 'viewport' => null],
        ];
    }

    /**
     * Las doce reglas del código como quedaban copiadas en una versión de F
     * recién importada: sin `conditions` y sin las aristas que añadió G.
     */
    private static function reglas(): array
    {
        return array_map(static fn (array $r): array => [
            'uuid' => 'no-cuenta-'.$r['code'],
            'code' => $r['code'],
            'revision' => 1,
            'source' => 'legacy_baseline',
            'enforcement' => 'code',
            'status' => $r['status'],
            'description' => $r['description'],
            'type' => $r['type'],
            'layers' => $r['layers'],
            'priority' => $r['priority'],
            'refs' => array_replace($r['refs'], ['edges' => []]),
            'origin' => $r['origin'],
        ], StudioCatalog::legacyRules());
    }

    /**
     * Las reglas como las copia una versión de formato 2: las del código ya
     * sincronizadas (revisión 2, con condiciones y aristas) y una del Studio.
     */
    private static function reglasG(): array
    {
        $codigo = array_map(static fn (array $r): array => [
            'code' => $r['code'],
            'revision' => 2,
            'source' => 'legacy_baseline',
            'enforcement' => 'code',
            'status' => $r['status'],
            'description' => $r['description'],
            'type' => $r['type'],
            'layers' => $r['layers'],
            'priority' => $r['priority'],
            'refs' => $r['refs'],
            'conditions' => $r['conditions'],
        ], StudioCatalog::legacyRules());

        return [...$codigo, [
            'code' => 'S-001',
            'revision' => 1,
            'source' => 'studio',
            'enforcement' => 'none',
            'status' => 'draft',
            'description' => 'Longitud máxima de 300 caracteres.',
            'type' => 'style',
            'layers' => ['persona'],
            'priority' => 'normal',
            'refs' => ['configs' => [['layer' => 'persona', 'key' => 'maxChars']], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []],
            'conditions' => ['when' => [], 'then' => [['kind' => 'limit', 'target' => 'config', 'layer' => 'persona', 'key' => 'maxChars', 'op' => 'lte', 'value' => 300]]],
        ]];
    }

    public function test_canonical_json_sorts_keys_recursively_and_keeps_lists_in_order(): void
    {
        $valor = ['b' => [3, 1, 2], 'a' => ['d' => 'é/ñ', 'c' => null], 'e' => true];

        $this->assertSame('{"a":{"c":null,"d":"é/ñ"},"b":[3,1,2],"e":true}', H::canonicalJson($valor));
        $this->assertSame('aaea2d2124aa9b57153440bec2420b595fc8430646a9c25be0ffa0c11bacd633', H::hash($valor), 'vector conocido (node y shasum)');
        $this->assertSame('[]', H::canonicalJson([]));
    }

    public function test_decimals_and_objects_are_rejected(): void
    {
        foreach ([0.5, 320.0, ['a' => ['b' => [1, 2.5]]], new stdClass, ['x' => new stdClass]] as $malo) {
            try {
                H::canonicalJson($malo);
                $this->fail('Debería rechazar '.get_debug_type($malo));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_baseline_draft_hash_is_a_known_vector(): void
    {
        // El de F, con la versión del catálogo de F: el borrador no cambia de forma.
        $this->assertSame(
            '69d437bd7bed256129dc701330ae9152d6dee7674874345434c7e5d7042c8e51',
            H::draftHash(self::CATALOGO_F, self::borrador()),
        );
        // Con la versión actual cambia solo porque cambia `catalogVersion` (vector de node).
        $this->assertSame(
            'c1d5fff543455240ccd7da6d6db09924c06007bf11809dc5289b57e9317b7e19',
            H::draftHash(StudioCatalog::VERSION, self::borrador()),
        );
    }

    public function test_key_order_edge_order_and_repeated_edges_do_not_change_the_draft_hash(): void
    {
        $original = self::borrador();
        $esperada = H::draftHash(StudioCatalog::VERSION, $original);

        $revuelto = $original;
        $revuelto['configs'] = array_reverse($original['configs'], true);
        $revuelto['configs']['persona'] = array_reverse($original['configs']['persona'], true);
        $revuelto['definition'] = [
            'edgeIds' => [...array_reverse($original['definition']['edgeIds']), 'E01'],
            'layers' => array_reverse($original['definition']['layers']),
        ];

        $this->assertSame($esperada, H::draftHash(StudioCatalog::VERSION, $revuelto));
    }

    public function test_the_layout_does_not_count(): void
    {
        $original = self::borrador();
        $movido = $original;
        $movido['layout'] = ['positions' => ['knowledge' => ['x' => -9999, 'y' => 3]], 'viewport' => ['x' => -120.5, 'y' => 40, 'scale' => 0.8]];
        $sinLayout = $original;
        unset($sinLayout['layout']);

        $this->assertSame(H::draftHash(StudioCatalog::VERSION, $original), H::draftHash(StudioCatalog::VERSION, $movido));
        $this->assertSame(H::draftHash(StudioCatalog::VERSION, $original), H::draftHash(StudioCatalog::VERSION, $sinLayout));
    }

    public function test_what_decides_behaviour_does_count(): void
    {
        $original = self::borrador();
        $esperada = H::draftHash(StudioCatalog::VERSION, $original);

        $orden = $original;
        $orden['configs']['strategy']['offerPriority'] = ['Segunda', 'Primera'];
        $ordenInverso = $original;
        $ordenInverso['configs']['strategy']['offerPriority'] = ['Primera', 'Segunda'];
        $this->assertNotSame(H::draftHash(StudioCatalog::VERSION, $orden), H::draftHash(StudioCatalog::VERSION, $ordenInverso), 'el orden de las etiquetas es significativo');

        $valor = $original;
        $valor['configs']['persona']['maxChars'] = 340;
        $this->assertNotSame($esperada, H::draftHash(StudioCatalog::VERSION, $valor));

        $arista = $original;
        $arista['definition']['edgeIds'][] = 'E14';
        $this->assertNotSame($esperada, H::draftHash(StudioCatalog::VERSION, $arista));

        $this->assertNotSame($esperada, H::draftHash('2099-01-01.1', $original), 'la versión del catálogo forma parte de la huella');
    }

    public function test_the_version_hash_adds_the_rules_sorted_by_code_and_is_a_known_vector(): void
    {
        // Formato 1: una versión de F (sin `format` en el contenido), con las entradas de F.
        $reglas = self::reglas();
        $esperada = H::versionHash(self::CATALOGO_F, self::borrador(), $reglas);

        $this->assertSame('667c9168ee6dc3951d6da09266e3ac677a0038641f07b32297fe83acf0a1abff', $esperada, 'vector conocido de F (node)');
        $this->assertNotSame(H::draftHash(self::CATALOGO_F, self::borrador()), $esperada);

        // El orden en que lleguen las reglas y sus campos ajenos no cuentan.
        $revueltas = array_map(static fn (array $r): array => ['createdAt' => 'ayer', 'origin' => 'otro'] + array_reverse($r, true), array_reverse($reglas));
        $this->assertSame($esperada, H::versionHash(self::CATALOGO_F, self::borrador(), $revueltas));

        // La revisión, el estado o el texto de una regla, sí.
        foreach (['revision' => 2, 'status' => 'archived', 'description' => 'Otra cosa.', 'enforcement' => 'none'] as $campo => $nuevo) {
            $cambiadas = $reglas;
            $cambiadas[3][$campo] = $nuevo;
            $this->assertNotSame($esperada, H::versionHash(self::CATALOGO_F, self::borrador(), $cambiadas), $campo);
        }

        $this->assertNotSame($esperada, H::versionHash(self::CATALOGO_F, self::borrador(), array_slice($reglas, 1)));
    }

    public function test_format_1_is_exactly_f_and_ignores_conditions(): void
    {
        $esperada = '667c9168ee6dc3951d6da09266e3ac677a0038641f07b32297fe83acf0a1abff';

        // `format: 1` explícito es lo mismo que no tenerlo.
        $this->assertSame(1, H::format(self::borrador()));
        $this->assertSame($esperada, H::versionHash(self::CATALOGO_F, ['format' => 1] + self::borrador(), self::reglas()));
        $this->assertSame($esperada, H::versionHash(self::CATALOGO_F, ['format' => null] + self::borrador(), self::reglas()), '`content.format ?? 1`');

        // En formato 1, `conditions` no entra (las versiones de F no la tenían).
        $conCondiciones = array_map(static fn (array $r): array => $r + ['conditions' => ['when' => [], 'then' => []]], self::reglas());
        $this->assertSame($esperada, H::versionHash(self::CATALOGO_F, self::borrador(), $conCondiciones));
        $this->assertSame(H::VERSION_RULE_FIELDS, H::versionRuleFields(1));
        $this->assertSame([...H::VERSION_RULE_FIELDS, 'conditions'], H::versionRuleFields(2));
    }

    public function test_format_2_adds_conditions_and_the_format_and_is_a_known_vector(): void
    {
        $contenido = ['format' => 2] + self::borrador();
        $reglas = self::reglasG();
        $esperada = H::versionHash(StudioCatalog::VERSION, $contenido, $reglas);

        $this->assertSame(2, H::format($contenido));
        $this->assertSame(H::LATEST_FORMAT, H::format($contenido));
        $this->assertSame('1e6fdcf7580a0a332b392f82dac5c554a343fd13bb267816f91d5cedfec70d4b', $esperada, 'vector conocido (node)');

        // El orden de las reglas no cuenta; sus condiciones, sí.
        $this->assertSame($esperada, H::versionHash(StudioCatalog::VERSION, $contenido, array_reverse($reglas)));
        $otras = $reglas;
        $otras[12]['conditions']['then'][0]['value'] = 320;
        $this->assertNotSame($esperada, H::versionHash(StudioCatalog::VERSION, $contenido, $otras));
        $sinCondiciones = $reglas;
        $sinCondiciones[12]['conditions'] = null;
        $this->assertNotSame($esperada, H::versionHash(StudioCatalog::VERSION, $contenido, $sinCondiciones));

        // Las mismas reglas sin condiciones dan otra huella en formato 2 que en formato 1: `format` cuenta.
        $todasNulas = array_map(static fn (array $r): array => ['conditions' => null] + $r, self::reglas());
        $this->assertNotSame(
            H::versionHash(self::CATALOGO_F, self::borrador(), self::reglas()),
            H::versionHash(self::CATALOGO_F, ['format' => 2] + self::borrador(), $todasNulas),
        );
    }

    public function test_format_2_needs_conditions_in_every_rule_and_unknown_formats_are_errors(): void
    {
        $sinClave = self::reglasG();
        unset($sinClave[0]['conditions']);

        foreach ([
            'regla sin conditions' => static fn () => H::versionHash(StudioCatalog::VERSION, ['format' => 2] + self::borrador(), $sinClave),
            'formato 3' => static fn () => H::versionHash(StudioCatalog::VERSION, ['format' => 3] + self::borrador(), self::reglasG()),
            'formato "2"' => static fn () => H::format(['format' => '2']),
            'formato 2.0' => static fn () => H::format(['format' => 2.0]),
            'formato 0' => static fn () => H::format(['format' => 0]),
            'campos del formato 3' => static fn () => H::versionRuleFields(3),
        ] as $caso => $llamada) {
            try {
                $llamada();
                $this->fail("{$caso}: debería rechazarse");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_rule_hash_covers_its_content_not_its_state(): void
    {
        $r01 = self::reglas()[0];
        $esperada = H::ruleHash($r01);

        $this->assertSame('eb347e01d06cb34b66190f15eda678d9ffa804ca58eb8b5ebc41d0ecbdb8afe3', $esperada, 'vector conocido de F (node)');
        $this->assertSame($esperada, H::ruleHash(['status' => 'archived', 'revision' => 9] + $r01));
        $this->assertNotSame($esperada, H::ruleHash(['description' => 'Otra.'] + $r01));
        $this->assertNotSame($esperada, H::ruleHash(['origin' => null] + $r01));
        $this->assertSame(64, strlen(H::ruleHash(['origin' => null] + $r01)), 'una regla del Studio no tiene origen');
    }

    public function test_conditions_enter_the_rule_hash_only_when_not_null(): void
    {
        $r01 = self::reglas()[0];

        // Una regla sin condiciones conserva la huella de F, tenga la clave o no.
        $this->assertSame(H::ruleHash($r01), H::ruleHash($r01 + ['conditions' => null]));

        // Con condiciones, entran (vector de node: R-01 sincronizada con G).
        $g = StudioCatalog::legacyRules()[0];
        $this->assertSame('91548a40a1a66daf4c012321889018ba1cee45143730686dfba3b4a9c700cc97', H::ruleHash($g), 'vector conocido (node)');
        $this->assertNotSame(H::ruleHash(['conditions' => null] + $g), H::ruleHash($g));
    }

    public function test_missing_fields_are_an_error_not_a_different_hash(): void
    {
        $reglaSinRevision = self::reglas()[0];
        unset($reglaSinRevision['revision']);
        $sinConfigs = self::borrador();
        unset($sinConfigs['configs']);

        foreach ([
            static fn () => H::versionHash(self::CATALOGO_F, self::borrador(), [$reglaSinRevision]),
            static fn () => H::draftHash(StudioCatalog::VERSION, $sinConfigs),
            static fn () => H::draftHash(StudioCatalog::VERSION, ['definition' => ['layers' => [], 'edgeIds' => [3]], 'configs' => []]),
            static fn () => H::ruleHash(['description' => 'x']),
        ] as $i => $llamada) {
            try {
                $llamada();
                $this->fail("caso {$i}: debería rechazarse");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
