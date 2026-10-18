<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioGraphValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PARIDAD: el servidor valida exactamente igual que la referencia del CRM.
 *
 * Cada caso de `tests/fixtures/ultron-studio/validation-cases.json` lleva la
 * salida de `validateDraft()` de `studio-graph.ts` (CRM, commit 4f7d774),
 * obtenida EJECUTANDO la referencia, no escrita a mano. Aquí se exige la misma
 * lista de hallazgos, en el mismo orden, con las mismas claves y los mismos
 * textos (`assertSame`).
 *
 * Si un caso discrepa, se corrige el PHP. Nunca `expected`: un fixture
 * retocado para que pase deja de probar nada.
 *
 * Los casos «real» usan StudioCatalog::graph(): prueban a la vez la
 * transcripción del catálogo y la semántica. Los demás usan el catálogo del
 * fixture con ese nombre (`mini` es el de las pruebas del CRM, tal cual).
 */
class StudioValidationParityTest extends TestCase
{
    private static ?array $fixture = null;

    private static function fixture(): array
    {
        return self::$fixture ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/ultron-studio/validation-cases.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function casos(): iterable
    {
        foreach (self::fixture()['cases'] as $caso) {
            yield $caso['name'] => [$caso];
        }
    }

    #[DataProvider('casos')]
    public function test_the_server_reproduces_the_reference_exactly(array $caso): void
    {
        $catalogo = $caso['catalog'] === 'real' ? StudioCatalog::graph() : self::fixture()['catalogs'][$caso['catalog']];

        $this->assertSame(
            $caso['expected'],
            StudioGraphValidator::validate($catalogo, $caso['definition'], $caso['configs']),
            'Discrepa de studio-graph.ts@4f7d774: se corrige el PHP, nunca expected.',
        );
    }

    public function test_the_fixture_is_well_formed_and_matches_the_catalog_version(): void
    {
        $f = self::fixture();

        $this->assertSame(1, $f['version']);
        $this->assertSame(StudioCatalog::VERSION, $f['catalogVersion'], 'Los casos «real» se calcularon con otra versión del catálogo: hay que regenerarlos.');
        $this->assertGreaterThanOrEqual(24, count($f['cases']));
        $nombres = array_column($f['cases'], 'name');
        $this->assertSame(count($nombres), count(array_unique($nombres)), 'nombres de caso repetidos');
        $this->assertArrayHasKey('mini', $f['catalogs']);

        foreach ($f['cases'] as $caso) {
            $this->assertSame(['name', 'catalog', 'definition', 'configs', 'expected'], array_keys($caso), $caso['name']);
            $this->assertTrue($caso['catalog'] === 'real' || isset($f['catalogs'][$caso['catalog']]), $caso['name']);
            $this->assertSame(['layers', 'edgeIds'], array_keys($caso['definition']), $caso['name']);
            $this->assertNotEmpty($caso['expected'], $caso['name']);
        }
    }

    public function test_the_fixture_covers_what_the_contract_asks_for(): void
    {
        $casos = self::fixture()['cases'];
        $hallazgos = array_merge(...array_column($casos, 'expected'));
        $ids = array_unique(array_column(array_filter($hallazgos, static fn (array $h): bool => $h['level'] !== 'ok'), 'id'));

        // Cada bloqueo y aviso de la configuración real, y los del grafo.
        foreach ([
            'config-hechos', 'config-descuentos', 'config-critic', 'config-derivacion', 'config-hermes', 'config-canario',
            'config-cobro-sin-camino', 'config-apagado', 'config-cobro-aprobacion', 'config-cobro-sin-bandera',
            'capas-criticas', 'aristas-obligatorias', 'aristas-desconocidas', 'ciclos', 'camino-entrada-a-envio',
            'camino-hechos-a-salida', 'aislada-persona', 'opcional-E14', 'opcional-E16',
            'config-ventas-con-estrategia', 'config-estricto',
        ] as $id) {
            $this->assertContains($id, $ids, "ningún caso produce {$id}");
        }

        $detalles = array_column($hallazgos, 'detail');
        $this->assertContains('Intención → Estrategia → Intención', $detalles);
        $this->assertNotEmpty(array_filter($detalles, static fn (string $d): bool => str_contains($d, '(falta: ') && str_contains($d, '; sobra: ')), 'requires + forbids juntos');
        $this->assertContains('E99', $detalles);

        $porNombre = array_column($casos, null, 'name');
        $this->assertNull($porNombre['real · línea base sin configuración']['configs']);
        $this->assertSame([], $porNombre['real · configs vacío']['configs']);
        $this->assertNotContains('E10', $porNombre['real · falta E10']['definition']['edgeIds']);
        $this->assertNotContains('memory', $porNombre['real · falta una capa crítica']['definition']['layers']);
        $this->assertContains('e-events', $porNombre['mini · la realimentación sola no es ciclo']['definition']['edgeIds']);
    }
}
