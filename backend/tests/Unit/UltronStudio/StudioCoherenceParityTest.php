<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioRuleCoherence as R;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PARIDAD de la coherencia de reglas (contrato G §A9): el servidor reproduce
 * EXACTAMENTE cada caso de `tests/fixtures/ultron-studio/coherence-cases.json`.
 *
 * Ese fichero lo escribió el tester a mano desde el contrato (ver su README);
 * no lo genera ninguna implementación. Si un caso discrepa, se corrige el PHP;
 * el fixture solo cambia con una enmienda del contrato. Catálogo: el real.
 * `errorKeys` es un conjunto (ordenado por bytes); lo demás, `assertSame`.
 */
class StudioCoherenceParityTest extends TestCase
{
    private static ?array $fixture = null;

    private static function fixture(): array
    {
        return self::$fixture ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/ultron-studio/coherence-cases.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function casos(): iterable
    {
        foreach (['canonicalize', 'analyze', 'conflicts', 'evaluate'] as $seccion) {
            foreach (self::fixture()[$seccion] as $caso) {
                yield $caso['name'] => [$caso + ['kind' => $seccion]];
            }
        }
    }

    #[DataProvider('casos')]
    public function test_the_server_reproduces_the_case_exactly(array $caso): void
    {
        $catalogo = StudioCatalog::toArray();

        $obtenido = match ($caso['kind']) {
            'canonicalize' => (static function () use ($caso, $catalogo): array {
                $r = R::check($caso['input']['conditions'], $catalogo, $caso['input']['layers']);
                $claves = array_keys($r['errors']);
                sort($claves, SORT_STRING);

                return ['conditions' => $r['conditions'], 'errorKeys' => $claves];
            })(),
            'analyze' => R::analyzeAll($caso['rules'], $catalogo),
            'conflicts' => R::conflicts($caso['candidate'], $caso['live'], $catalogo),
            'evaluate' => R::evaluate($caso['rules'], $caso['definition'], $caso['configs'], $catalogo, $caso['format'] ?? 2),
        };

        $this->assertSame($caso['expected'], $obtenido, 'Discrepa del fixture escrito desde el contrato: se corrige el PHP, nunca expected.');
    }

    public function test_the_fixture_is_well_formed_and_matches_the_catalog_version(): void
    {
        $f = self::fixture();

        $this->assertSame(1, $f['version']);
        $this->assertSame(StudioCatalog::VERSION, $f['catalogVersion'], 'Los casos se escribieron con otra versión del catálogo.');
        $nombres = [];
        foreach (['canonicalize', 'analyze', 'conflicts', 'evaluate'] as $seccion) {
            $this->assertNotEmpty($f[$seccion], $seccion);
            foreach ($f[$seccion] as $caso) {
                $this->assertSame($seccion, $caso['kind'] ?? $seccion, $caso['name']);
                $nombres[] = $caso['name'];
            }
        }
        $this->assertGreaterThanOrEqual(45, count($nombres), 'el contrato pide al menos 45 casos');
        $this->assertSame(count($nombres), count(array_unique($nombres)), 'nombres de caso repetidos');
    }
}
