<?php

namespace Tests\Unit\UltronStudio;

use App\Console\Commands\UltronStudio\ExportStudioFixturesCommand;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioLabCatalog;
use Tests\TestCase;

/**
 * El comando que lleva el catálogo, los casos de paridad y los escenarios del
 * laboratorio al CRM.
 *
 * LO QUE SE PROTEGE. Que lo exportado sea exactamente lo que el servidor usa
 * (el catálogo de `GET catalog`, los casos tal cual, sin pasar un `{}` a `[]`,
 * y desde el Hito 4 el catálogo de escenarios del laboratorio) y que una ruta
 * mal escrita no siembre carpetas. Escribe SIEMPRE en un directorio temporal:
 * nunca en el CRM.
 */
class ExportStudioFixturesCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ultron-studio-export-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir.'/testing', $this->dir] as $dir) {
            foreach (glob($dir.'/*.ts') ?: [] as $fichero) {
                unlink($fichero);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        parent::tearDown();
    }

    /** El literal que sigue a `export const NOMBRE = ` y precede a ` as const;`. */
    private static function literal(string $ts, string $nombre): string
    {
        $inicio = strpos($ts, "export const {$nombre} = ");
        $fin = strrpos($ts, ' as const;');
        if ($inicio === false || $fin === false) {
            return '';
        }
        $inicio += strlen("export const {$nombre} = ");

        return substr($ts, $inicio, $fin - $inicio);
    }

    public function test_it_writes_the_four_files_and_creates_the_testing_folder_inside_an_existing_one(): void
    {
        $destino = $this->dir.'/testing';

        $this->artisan('ultron-studio:export-fixtures', ['--path' => $destino])
            ->expectsOutputToContain('de coherencia y 12 escenarios del laboratorio')
            ->assertSuccessful();

        $catalogo = (string) file_get_contents($destino.'/'.ExportStudioFixturesCommand::CATALOG_FILE);
        $this->assertStringStartsWith('// Catálogo de ULTRON STUDIO', $catalogo);
        $this->assertStringContainsString('GENERADO — no editar a mano.', $catalogo);
        $this->assertStringContainsString('huella '.StudioCatalog::hash(), $catalogo);
        $this->assertStringEndsWith(" as const;\n", $catalogo);
        $json = self::literal($catalogo, 'BACKEND_STUDIO_CATALOG');
        $this->assertSame(StudioCatalog::toArray(), json_decode($json, true, 512, JSON_THROW_ON_ERROR), 'la forma exacta de GET catalog, en el mismo orden');
        $this->assertStringStartsWith("{\n  \"version\": ", $json, 'sangría de dos espacios, como el CRM');

        $casos = (string) file_get_contents($destino.'/'.ExportStudioFixturesCommand::CASES_FILE);
        $this->assertStringStartsWith('// Casos de paridad', $casos);
        $original = rtrim((string) file_get_contents(base_path('tests/fixtures/ultron-studio/validation-cases.json')));
        $this->assertSame($original, self::literal($casos, 'BACKEND_VALIDATION_CASES'), 'los casos viajan tal cual');
        $this->assertStringContainsString('"configs": {}', $casos, 'un objeto vacío sigue siendo un objeto');

        // Hito G: los casos de coherencia, tal cual; el catálogo lleva las claves nuevas.
        $coherencia = (string) file_get_contents($destino.'/'.ExportStudioFixturesCommand::COHERENCE_FILE);
        $this->assertStringStartsWith('// Casos de paridad de la coherencia', $coherencia);
        $this->assertStringEndsWith(" as const;\n", $coherencia);
        $originalG = rtrim((string) file_get_contents(base_path('tests/fixtures/ultron-studio/coherence-cases.json')));
        $this->assertSame($originalG, self::literal($coherencia, 'BACKEND_COHERENCE_CASES'), 'los casos de coherencia viajan tal cual');
        $this->assertArrayHasKey('bindings', json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $this->assertArrayHasKey('legacyConditions', json_decode($json, true, 512, JSON_THROW_ON_ERROR));

        // Hito 4: los escenarios del catálogo del laboratorio, con sus listas y límites.
        $laboratorio = (string) file_get_contents($destino.'/'.ExportStudioFixturesCommand::LAB_SCENARIOS_FILE);
        $this->assertStringStartsWith('// Escenarios del catálogo del laboratorio', $laboratorio);
        $this->assertStringContainsString('GENERADO — no editar a mano.', $laboratorio);
        $this->assertStringContainsString('huella '.StudioLabCatalog::hash(), $laboratorio);
        $this->assertStringEndsWith(" as const;\n", $laboratorio);
        $lab = json_decode(self::literal($laboratorio, 'BACKEND_LAB_SCENARIOS'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(ExportStudioFixturesCommand::labScenarios(), $lab, 'lo mismo que el servidor, en el mismo orden');
        $this->assertSame(StudioLabCatalog::VERSION, $lab['version']);
        $this->assertSame(StudioLabCatalog::codes(), array_column($lab['scenarios'], 'code'));
        $this->assertSame(StudioLabCatalog::scenarioHash(StudioLabCatalog::byCode('C-04')), $lab['scenarios'][3]['contentHash']);
        $this->assertSame(StudioLabCatalog::CATEGORIES, $lab['categories']);
        $this->assertSame(100, $lab['limits']['maxLiveScenarios']);
        $this->assertStringContainsString("\n  \"version\": ", self::literal($laboratorio, 'BACKEND_LAB_SCENARIOS'), 'sangría de dos espacios, como el CRM');
    }

    public function test_it_refuses_a_path_whose_parent_does_not_exist(): void
    {
        $destino = $this->dir.'/no/existe/testing';

        $this->artisan('ultron-studio:export-fixtures', ['--path' => $destino])
            ->expectsOutputToContain('No existe el directorio')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->dir.'/no');
    }

    public function test_it_overwrites_previous_exports_in_an_existing_folder(): void
    {
        file_put_contents($this->dir.'/'.ExportStudioFixturesCommand::CATALOG_FILE, 'viejo');

        $this->artisan('ultron-studio:export-fixtures', ['--path' => $this->dir.'/'])->assertSuccessful();

        $this->assertStringContainsString('BACKEND_STUDIO_CATALOG', (string) file_get_contents($this->dir.'/'.ExportStudioFixturesCommand::CATALOG_FILE));
        $this->assertFileExists($this->dir.'/'.ExportStudioFixturesCommand::CASES_FILE);
        $this->assertFileExists($this->dir.'/'.ExportStudioFixturesCommand::COHERENCE_FILE);
        $this->assertFileExists($this->dir.'/'.ExportStudioFixturesCommand::LAB_SCENARIOS_FILE);
    }
}
