<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioGraphValidator as V;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas del grafo en el servidor, una a una, sobre un catálogo pequeño.
 *
 * LO QUE SE PROTEGE. Los matices en los que una reimplementación «razonable»
 * se apartaría de la referencia TS sin que nadie lo notara: el orden en que se
 * recorren las aristas, que la realimentación y la arista que abre el turno no
 * cierren ciclos, el orden y la forma de los hallazgos (con `layer` solo donde
 * la referencia la pone) y la igualdad de `JSON.stringify` en la
 * configuración. La paridad exacta, caso a caso, está en
 * StudioValidationParityTest.
 *
 *   a ──x──▶ b ──y──▶ d          (obligatorias)
 *            b ──z──▶ c          (opcional)
 *            c ··z··▶ b          (opcional: cerraría un ciclo b → c → b)
 *   d ··w··▶ a  realimentación entre turnos
 *   d ··v··▶ b  abre el turno
 */
class StudioGraphValidatorTest extends TestCase
{
    private static function catalogo(): array
    {
        $capa = static fn (string $id, string $name, bool $critical): array => ['id' => $id, 'name' => $name, 'critical' => $critical];
        $puerto = static fn (string $layer, string $dir, string $tipo): array => ['id' => "{$layer}:{$dir}:{$tipo}", 'layer' => $layer, 'direction' => $dir, 'dataType' => $tipo, 'label' => strtoupper($tipo)];

        return [
            'layers' => [$capa('a', 'Alfa', true), $capa('b', 'Beta', true), $capa('c', 'Gamma', false), $capa('d', 'Delta', true)],
            'ports' => [
                $puerto('a', 'out', 'x'), $puerto('a', 'in', 'w'),
                $puerto('b', 'in', 'x'), $puerto('b', 'in', 'z'), $puerto('b', 'in', 'v'), $puerto('b', 'out', 'y'), $puerto('b', 'out', 'z'),
                $puerto('c', 'in', 'z'), $puerto('c', 'out', 'z'),
                $puerto('d', 'in', 'y'), $puerto('d', 'out', 'w'), $puerto('d', 'out', 'v'),
            ],
            'edges' => [
                ['id' => 'ab', 'source' => 'a:out:x', 'target' => 'b:in:x', 'label' => 'alfa → beta', 'mandatory' => true, 'consequence' => ''],
                ['id' => 'bd', 'source' => 'b:out:y', 'target' => 'd:in:y', 'label' => 'beta → delta', 'mandatory' => true, 'consequence' => ''],
                ['id' => 'bc', 'source' => 'b:out:z', 'target' => 'c:in:z', 'label' => 'beta → gamma', 'mandatory' => false, 'consequence' => 'Gamma no participa.'],
                ['id' => 'cb', 'source' => 'c:out:z', 'target' => 'b:in:z', 'label' => 'gamma → beta', 'mandatory' => false, 'consequence' => 'Reentrada.'],
                ['id' => 'da', 'source' => 'd:out:w', 'target' => 'a:in:w', 'label' => 'delta → alfa (siguiente turno)', 'mandatory' => false, 'feedback' => true, 'consequence' => 'No se aprende.'],
                ['id' => 'db', 'source' => 'd:out:v', 'target' => 'b:in:v', 'label' => 'mensaje entrante', 'mandatory' => false, 'opensTurn' => true, 'consequence' => 'No entra nada.'],
            ],
            'requiredPaths' => [['id' => 'a-d', 'from' => 'a', 'to' => 'd', 'title' => 'Alfa llega a Delta', 'detail' => 'Sin camino no hay respuesta.']],
            'constraints' => [
                ['id' => 'estricto', 'layer' => 'a', 'key' => 'estricto', 'forbidden' => false, 'level' => 'blocking', 'title' => 'No estricto', 'detail' => 'Alfa tiene que ser estricta.'],
                ['id' => 'modo', 'layer' => 'c', 'key' => 'modo', 'when' => 'x', 'requires' => ['bc', 'fantasma'], 'forbids' => ['cb', 'inexistente'], 'level' => 'warning', 'title' => 'Modo x', 'detail' => 'El modo x pide gamma.'],
                ['id' => 'limite', 'layer' => 'd', 'key' => 'limite', 'forbidden' => 320, 'level' => 'warning', 'title' => 'Límite 320', 'detail' => 'No con 320.'],
                ['id' => 'fuentes', 'layer' => 'a', 'key' => 'fuentes', 'forbidden' => ['p', 'q'], 'level' => 'warning', 'title' => 'Fuentes p y q', 'detail' => 'No esas, en ese orden.'],
            ],
        ];
    }

    private static function def(array $edgeIds, array $layers = ['a', 'b', 'c', 'd']): array
    {
        return ['layers' => $layers, 'edgeIds' => $edgeIds];
    }

    public function test_present_edges_follow_the_catalog_order_and_ignore_unknowns_and_repeats(): void
    {
        $presentes = V::presentEdges(self::catalogo(), self::def(['db', 'bc', 'nada', 'ab', 'bc']));

        $this->assertSame(['ab', 'bc', 'db'], array_column($presentes, 'id'));
    }

    public function test_feedback_and_turn_opening_edges_do_not_close_cycles(): void
    {
        $cat = self::catalogo();

        $this->assertNull(V::findCycle($cat, V::presentEdges($cat, self::def(['ab', 'bd', 'da', 'db']))));
        $this->assertTrue(V::hasPath($cat, V::presentEdges($cat, self::def(['ab', 'bd'])), 'a', 'd'));
        // La realimentación tampoco cuenta como camino dentro del turno.
        $this->assertFalse(V::hasPath($cat, V::presentEdges($cat, self::def(['ab', 'bd', 'da'])), 'd', 'a'));
        $this->assertFalse(V::hasPath($cat, V::presentEdges($cat, self::def(['db'])), 'd', 'b'));
        // Desde una capa a sí misma siempre hay camino, como en la referencia.
        $this->assertTrue(V::hasPath($cat, [], 'c', 'c'));
    }

    public function test_a_cycle_is_named_from_its_first_layer_in_the_depth_first_walk(): void
    {
        $cat = self::catalogo();

        // Desde «a» (orden del catálogo): a → b → (d) → c → b: el ciclo empieza en b.
        $this->assertSame(['b', 'c', 'b'], V::findCycle($cat, V::presentEdges($cat, self::def(['ab', 'bd', 'bc', 'cb']))));

        $hallazgos = V::validate($cat, self::def(['ab', 'bd', 'bc', 'cb']), null);
        $this->assertSame(
            ['id' => 'ciclos', 'level' => 'blocking', 'title' => 'Ciclo dentro de un turno', 'detail' => 'Beta → Gamma → Beta'],
            array_column($hallazgos, null, 'id')['ciclos'],
        );
    }

    public function test_findings_come_in_the_reference_order_with_layer_only_where_the_reference_puts_it(): void
    {
        $hallazgos = V::validate(self::catalogo(), self::def(['zz', 'bc', 'yy', 'zz'], ['a', 'b']), ['a' => ['estricto' => false]]);

        $this->assertSame([
            ['id' => 'capas-criticas', 'level' => 'blocking', 'title' => 'Faltan capas críticas', 'detail' => 'Delta'],
            ['id' => 'aristas-obligatorias', 'level' => 'blocking', 'title' => 'Faltan conexiones obligatorias', 'detail' => 'alfa → beta · beta → delta'],
            ['id' => 'aristas-desconocidas', 'level' => 'blocking', 'title' => 'Conexiones que el motor real no tiene', 'detail' => 'zz, yy, zz'],
            ['id' => 'ciclos', 'level' => 'ok', 'title' => 'Sin ciclos dentro de un turno', 'detail' => 'La memoria entre turnos no cuenta como ciclo.'],
            ['id' => 'camino-a-d', 'level' => 'blocking', 'title' => 'Alfa llega a Delta', 'detail' => 'Sin camino no hay respuesta.', 'layer' => 'd'],
            ['id' => 'opcional-cb', 'level' => 'warning', 'title' => 'Sin «gamma → beta»', 'detail' => 'Reentrada.'],
            ['id' => 'opcional-da', 'level' => 'warning', 'title' => 'Sin «delta → alfa (siguiente turno)»', 'detail' => 'No se aprende.'],
            ['id' => 'opcional-db', 'level' => 'warning', 'title' => 'Sin «mensaje entrante»', 'detail' => 'No entra nada.'],
            ['id' => 'aislada-a', 'level' => 'blocking', 'title' => 'Alfa quedó aislada', 'detail' => 'Una capa crítica sin conexiones no participa del turno.', 'layer' => 'a'],
            ['id' => 'aislada-d', 'level' => 'blocking', 'title' => 'Delta quedó aislada', 'detail' => 'Una capa crítica sin conexiones no participa del turno.', 'layer' => 'd'],
            ['id' => 'config-estricto', 'level' => 'blocking', 'title' => 'No estricto', 'detail' => 'Alfa tiene que ser estricta.', 'layer' => 'a'],
        ], $hallazgos);
    }

    public function test_everything_connected_is_ok_with_counted_details(): void
    {
        $hallazgos = V::validate(self::catalogo(), self::def(['ab', 'bd', 'bc', 'da', 'db']), ['a' => ['estricto' => true, 'fuentes' => ['q', 'p']], 'd' => ['limite' => 300]]);

        $this->assertSame('2 conexiones obligatorias presentes.', $hallazgos[1]['detail']);
        $this->assertSame(['opcional-cb'], V::warnings($hallazgos));
        $this->assertTrue(V::isPublishable($hallazgos));
        $this->assertSame(
            ['id' => 'configuracion', 'level' => 'ok', 'title' => 'La configuración respeta las reglas del motor', 'detail' => '3 comprobaciones sobre los parámetros del borrador.'],
            end($hallazgos),
        );
    }

    public function test_configuration_equality_is_json_stringify_not_php_equality(): void
    {
        $cat = self::catalogo();
        $def = self::def(['ab', 'bd']);
        $salta = static fn (array $configs, string $id): bool => in_array("config-{$id}", array_column(V::checkConfigs($cat, $def, $configs), 'id'), true);

        // En JavaScript 320 y 320.0 son el mismo número.
        $this->assertTrue($salta(['d' => ['limite' => 320]], 'limite'));
        $this->assertTrue($salta(['d' => ['limite' => 320.0]], 'limite'));
        $this->assertFalse($salta(['d' => ['limite' => '320']], 'limite'));
        $this->assertFalse($salta(['d' => ['limite' => 320.5]], 'limite'));

        // false no es 0, ni null, ni "false", ni la cadena vacía.
        $this->assertTrue($salta(['a' => ['estricto' => false]], 'estricto'));
        foreach ([0, null, 'false', '', []] as $parecido) {
            $this->assertFalse($salta(['a' => ['estricto' => $parecido]], 'estricto'), var_export($parecido, true));
        }

        // En una lista cuenta el orden.
        $this->assertTrue($salta(['a' => ['fuentes' => ['p', 'q']]], 'fuentes'));
        $this->assertFalse($salta(['a' => ['fuentes' => ['q', 'p']]], 'fuentes'));
    }

    public function test_a_null_value_is_checked_but_a_missing_one_or_a_non_object_layer_is_not(): void
    {
        $cat = self::catalogo();
        $def = self::def(['ab', 'bd']);

        $this->assertSame('1 comprobaciones sobre los parámetros del borrador.', V::checkConfigs($cat, $def, ['a' => ['estricto' => null]])[0]['detail']);
        $this->assertSame([], V::checkConfigs($cat, $def, ['a' => ['otra' => false]]));
        $this->assertSame([], V::checkConfigs($cat, $def, ['a' => null, 'd' => 'limite', 'c' => ['modo']]));
    }

    public function test_without_configuration_or_constraints_there_is_nothing_to_check(): void
    {
        $cat = self::catalogo();
        $def = self::def(['ab', 'bd']);

        $this->assertSame([], V::checkConfigs($cat, $def, null));
        $this->assertSame([], V::checkConfigs($cat, $def, []));
        $sinReglas = $cat;
        unset($sinReglas['constraints']);
        $this->assertSame([], V::checkConfigs($sinReglas, $def, ['a' => ['estricto' => false]]));
        $this->assertSame([], array_values(array_filter(
            V::validate($cat, $def, null),
            static fn (array $f): bool => str_starts_with($f['id'], 'config'),
        )));
    }

    public function test_requires_and_forbids_name_edges_by_label_or_id_with_the_reference_separators(): void
    {
        $cat = self::catalogo();

        $ambas = V::checkConfigs($cat, self::def(['ab', 'cb', 'inexistente']), ['c' => ['modo' => 'x']]);
        $this->assertSame([[
            'id' => 'config-modo',
            'level' => 'warning',
            'title' => 'Modo x',
            'detail' => 'El modo x pide gamma. (falta: beta → gamma · fantasma; sobra: gamma → beta · inexistente)',
            'layer' => 'c',
        ]], $ambas);

        $soloSobra = V::checkConfigs($cat, self::def(['bc', 'fantasma', 'cb']), ['c' => ['modo' => 'x']]);
        $this->assertSame('El modo x pide gamma. (sobra: gamma → beta)', $soloSobra[0]['detail']);

        // Coherente: no hay hallazgo, y la comprobación cuenta.
        $coherente = V::checkConfigs($cat, self::def(['bc', 'fantasma']), ['c' => ['modo' => 'x']]);
        $this->assertSame(['configuracion'], array_column($coherente, 'id'));

        // Con otro valor, la coherencia no aplica.
        $this->assertSame(['configuracion'], array_column(V::checkConfigs($cat, self::def([]), ['c' => ['modo' => 'y']]), 'id'));
    }

    public function test_publishable_means_no_blocking_and_warnings_keep_their_order(): void
    {
        $hallazgos = [
            ['id' => 'w2', 'level' => 'warning', 'title' => '', 'detail' => ''],
            ['id' => 'ok', 'level' => 'ok', 'title' => '', 'detail' => ''],
            ['id' => 'w1', 'level' => 'warning', 'title' => '', 'detail' => ''],
        ];

        $this->assertTrue(V::isPublishable($hallazgos));
        $this->assertSame(['w2', 'w1'], V::warnings($hallazgos));
        $this->assertFalse(V::isPublishable([...$hallazgos, ['id' => 'b', 'level' => 'blocking', 'title' => '', 'detail' => '']]));
        $this->assertTrue(V::isPublishable([]));
    }

    public function test_a_malformed_definition_is_a_caller_error_not_a_finding(): void
    {
        foreach ([['layers' => ['a']], ['edgeIds' => []], ['layers' => ['a'], 'edgeIds' => ['ab', 7]], ['layers' => 'a', 'edgeIds' => []], ['layers' => [], 'edgeIds' => ['x' => 'ab']]] as $mala) {
            try {
                V::validate(self::catalogo(), $mala, null);
                $this->fail('Debería rechazar '.json_encode($mala));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
