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
 */
class StudioContentHasherTest extends TestCase
{
    private static function borrador(): array
    {
        $base = StudioCatalog::baseline();

        return [
            'definition' => ['layers' => StudioCatalog::layerIds(), 'edgeIds' => $base['edgeIds']],
            'configs' => $base['configs'],
            'layout' => ['positions' => $base['positions'], 'viewport' => null],
        ];
    }

    /** Las doce reglas del código como quedarían copiadas en una versión recién importada. */
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
            'refs' => $r['refs'],
            'origin' => $r['origin'],
        ], StudioCatalog::legacyRules());
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
        $this->assertSame(
            '69d437bd7bed256129dc701330ae9152d6dee7674874345434c7e5d7042c8e51',
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
        $reglas = self::reglas();
        $esperada = H::versionHash(StudioCatalog::VERSION, self::borrador(), $reglas);

        $this->assertSame('667c9168ee6dc3951d6da09266e3ac677a0038641f07b32297fe83acf0a1abff', $esperada, 'vector conocido (node)');
        $this->assertNotSame(H::draftHash(StudioCatalog::VERSION, self::borrador()), $esperada);

        // El orden en que lleguen las reglas y sus campos ajenos no cuentan.
        $revueltas = array_map(static fn (array $r): array => ['createdAt' => 'ayer', 'origin' => 'otro'] + array_reverse($r, true), array_reverse($reglas));
        $this->assertSame($esperada, H::versionHash(StudioCatalog::VERSION, self::borrador(), $revueltas));

        // La revisión, el estado o el texto de una regla, sí.
        foreach (['revision' => 2, 'status' => 'archived', 'description' => 'Otra cosa.', 'enforcement' => 'none'] as $campo => $nuevo) {
            $cambiadas = $reglas;
            $cambiadas[3][$campo] = $nuevo;
            $this->assertNotSame($esperada, H::versionHash(StudioCatalog::VERSION, self::borrador(), $cambiadas), $campo);
        }

        $this->assertNotSame($esperada, H::versionHash(StudioCatalog::VERSION, self::borrador(), array_slice($reglas, 1)));
    }

    public function test_the_rule_hash_covers_its_content_not_its_state(): void
    {
        $r01 = self::reglas()[0];
        $esperada = H::ruleHash($r01);

        $this->assertSame('eb347e01d06cb34b66190f15eda678d9ffa804ca58eb8b5ebc41d0ecbdb8afe3', $esperada, 'vector conocido (node)');
        $this->assertSame($esperada, H::ruleHash(['status' => 'archived', 'revision' => 9] + $r01));
        $this->assertNotSame($esperada, H::ruleHash(['description' => 'Otra.'] + $r01));
        $this->assertNotSame($esperada, H::ruleHash(['origin' => null] + $r01));
        $this->assertSame(64, strlen(H::ruleHash(['origin' => null] + $r01)), 'una regla del Studio no tiene origen');
    }

    public function test_missing_fields_are_an_error_not_a_different_hash(): void
    {
        $reglaSinRevision = self::reglas()[0];
        unset($reglaSinRevision['revision']);
        $sinConfigs = self::borrador();
        unset($sinConfigs['configs']);

        foreach ([
            static fn () => H::versionHash(StudioCatalog::VERSION, self::borrador(), [$reglaSinRevision]),
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
