<?php

namespace Tests\Unit\UltronStudio;

use App\Console\Commands\UltronStudio\ExportStudioFixturesCommand;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioIntegrationsExport;
use App\Services\UltronStudio\StudioLabCatalog;
use App\Services\UltronStudio\StudioPublicationExport;
use App\Services\UltronStudio\StudioVersionStatus;
use Tests\TestCase;

/**
 * El comando que lleva el catálogo, los casos de paridad, los escenarios del
 * laboratorio, desde el Hito 5 las formas de la publicación y, desde el
 * Hito 6, las de las integraciones comerciales al CRM.
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

    public function test_it_writes_the_six_files_and_creates_the_testing_folder_inside_an_existing_one(): void
    {
        $destino = $this->dir.'/testing';

        $this->artisan('ultron-studio:export-fixtures', ['--path' => $destino])
            ->expectsOutputToContain('de coherencia, 11 escenarios del laboratorio ('.StudioLabCatalog::VERSION.') y las formas de la publicación y de las integraciones')
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

        // Hito 5: las formas de la publicación, con los presentadores reales.
        $publicacion = (string) file_get_contents($destino.'/'.ExportStudioFixturesCommand::PUBLICATION_FILE);
        $this->assertStringStartsWith('// Formas de la publicación de ULTRON STUDIO', $publicacion);
        $this->assertStringContainsString('GENERADO — no editar a mano.', $publicacion);
        $this->assertStringEndsWith(" as const;\n", $publicacion);
        $formas = json_decode(self::literal($publicacion, 'BACKEND_PUBLICATION'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(json_decode((string) json_encode(StudioPublicationExport::shapes()), true), $formas, 'lo mismo que el servidor, en el mismo orden');
        $this->assertSame(StudioVersionStatus::lifecycle(), $formas['lifecycle']);
        $this->assertSame(['publish', 'promote', 'rollback', 'legacy'], array_keys($formas['examples']['entries']));
        // Enmienda M-1: `effect` y `prompts`, siempre, al final.
        $this->assertSame(['versionUuid', 'contentHash', 'previewHash', 'publishable', 'params', 'noEffect', 'requirements', 'blocking', 'effect', 'prompts'], array_keys($formas['examples']['preview']));
        $this->assertTrue($formas['examples']['preview']['publishable']);
        $this->assertSame(['declaration', null], [$formas['examples']['preview']['effect'], $formas['examples']['preview']['prompts']]);
        $this->assertSame('prompts', $formas['examples']['previewPrompts']['effect']);
        $this->assertSame('702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c', $formas['examples']['previewPrompts']['prompts']['bundleHash'], 'el cambio de aceptación (G8), con la huella real del compilador');
        $this->assertSame(['key', 'base', 'compiler', 'legacyBundleHash', 'current', 'lastTurns'], array_keys($formas['examples']['engine']['prompts']));
        $this->assertSame('prompts_off', $formas['examples']['engine']['prompts']['current']['reason']);
        $this->assertSame('studio', $formas['examples']['enginePrompts']['prompts']['current']['mode']);
        $this->assertSame(['ultron-engine-config/1', 'ultron-engine-config/2'], $formas['contracts']);
        $this->assertSame(['must_match', 'matches', 'no_effect', 'prompt'], $formas['paramClasses']);
        $this->assertSame(['publishing_disabled', 'lab_verdict_required'], $formas['examples']['previewBlocked']['blocking']);

        // Hito 6: las formas de las integraciones, con los serializadores reales y una APP_KEY sintética.
        $integraciones = (string) file_get_contents($destino.'/'.ExportStudioFixturesCommand::INTEGRATIONS_FILE);
        $this->assertStringStartsWith('// Formas de las integraciones comerciales de ULTRON STUDIO', $integraciones);
        $this->assertStringContainsString('GENERADO — no editar a mano.', $integraciones);
        $this->assertStringEndsWith(" as const;\n", $integraciones);
        $h6 = json_decode(self::literal($integraciones, 'BACKEND_INTEGRATIONS'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(json_decode((string) json_encode(StudioIntegrationsExport::shapes()), true), $h6, 'lo mismo que el servidor, en el mismo orden');
        $this->assertSame(['ultron.campaigns_manage', 'ultron.canary_manage', 'ultron.canary_authorize', 'ultron.integrations_manage'], array_column($h6['permissions'], 'key'));
        $this->assertSame([true, true, false, false], array_column($h6['permissions'], 'administrator'));
        // El conjunto cerrado de clasificaciones no contradice a `providerClassifications` del mismo fichero:
        // las seis de §I2 en su orden y, al final, `connected_voice` (Hito 8, D8-21), como el CRM.
        $this->assertSame(['connected_read', 'connected_otp_only', 'degraded', 'disabled', 'not_configured', 'registry', 'connected_voice'], $h6['sets']['classification']);
        $this->assertEqualsCanonicalizing(array_values(array_unique(array_merge(...array_values($h6['providerClassifications'])))), $h6['sets']['classification'],
            'exactamente lo que algún bloque puede afirmar: ni una de más ni una de menos');
        $this->assertCount(22, $h6['errors'], 'los 15 códigos nuevos de I7.3 y los 7 reutilizados');
        $this->assertSame(['status', 'statusDegraded', 'metaCampaigns', 'metaCampaignsNotConfigured', 'links', 'linkWrite', 'linkDeactivated', 'canaries', 'canariesUnreadable',
            'canaryWrite', 'twilio', 'twilioCalls', 'probe', 'voice', 'config', 'acknowledgement'], array_keys($h6['examples']));
        // Determinista: la APP_KEY de esta máquina no entra en el fichero.
        $antes = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertSame($h6, json_decode((string) json_encode(StudioIntegrationsExport::shapes()), true));
        config(['app.key' => $antes]);
        $this->assertStringNotContainsString('export-synthetic-twilio-token-0001', $integraciones, 'sin secretos: solo huellas');
        $this->assertStringNotContainsString('export-synthetic-meta-token-0001', $integraciones);
        $this->assertTrue($formas['examples']['evidence']['passes']);
        $this->assertSame(['turns'], $formas['examples']['evidenceInsufficient']['failing']);
        $this->assertSame(403, $formas['errors']['four_eyes_required']);
        $this->assertCount(22, $formas['errors'], 'los 19 códigos de §A7, publishing_disabled (de F), publication_head_invalid y prompts_compile_failed (Enmienda M-1)');
        $this->assertSame(409, $formas['errors']['prompts_compile_failed']);
        $this->assertStringContainsString("\n  \"version\": ", self::literal($publicacion, 'BACKEND_PUBLICATION'), 'sangría de dos espacios, como el CRM');
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
        $this->assertFileExists($this->dir.'/'.ExportStudioFixturesCommand::PUBLICATION_FILE);
        $this->assertFileExists($this->dir.'/'.ExportStudioFixturesCommand::INTEGRATIONS_FILE);
    }
}
