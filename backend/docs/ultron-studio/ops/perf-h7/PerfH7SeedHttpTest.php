<?php

namespace Tests\PerfH7;

require_once __DIR__.'/PerfH7Seeding.php';

use App\Models\Admin;
use App\Models\Plan;
use App\Models\UltronStudio\StudioLabScenario;
use App\Services\Admin\AdminSessionService;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\UltronStudio\IntegrationFixtures;
use Tests\Feature\UltronStudio\LabFixtures;
use Tests\Feature\UltronStudio\PublicationFixtures;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\TestCase;

/**
 * PERF7: siembra la base del BANCO HTTP (`ultron_studio_h7_perf_http`) con el
 * tamaño grande de cada familia a la vez, deja los datos confirmados (sin
 * transacción de prueba) y escribe en PERF_STATE_DIR:
 *  - manifest.json: las rutas GET a medir, con sus uuid;
 *  - sesiones.txt (0600): una sesión por persona (8 personas Super Admin
 *    distintas, para rotar los cupos por credencial y por persona).
 * Lo lanza serve.sh seed. La configuración del servidor (serve.sh) tiene que
 * coincidir con la de aquí: conversación canaria 987654321, llave `canary`,
 * cuentas de Meta de IntegrationFixtures, laboratorio con el conductor falso.
 */
final class PerfH7SeedHttpTest extends TestCase
{
    use IntegrationFixtures, LabFixtures, PerfH7Seeding, PublicationFixtures, StudioFixtures {
        PublicationFixtures::personWith insteadof IntegrationFixtures;
    }

    public function test_siembra_la_base_del_banco_http(): void
    {
        $db = (string) DB::connection()->getDatabaseName();
        if (DB::connection()->getDriverName() !== 'pgsql' || $db !== 'ultron_studio_h7_perf_http') {
            $this->fail("Base no permitida para la siembra HTTP: {$db}");
        }
        $estado = (string) getenv('PERF_STATE_DIR');
        if ($estado === '' || ! is_dir($estado)) {
            $this->fail('Falta PERF_STATE_DIR (un directorio propio, fuera del repo).');
        }
        Http::fake();
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));

        $this->importBaseline();
        $this->importLabScenarios();
        $this->openKeys(['marketing.ultron.studio_config' => 'canary']);
        $this->integrationsFlags(true);
        $this->labFake(['ultron_studio.lab.max_runs_per_day' => 100000]);
        Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso completo'])]);

        // Personas: 8 Super Admin distintas (sesiones del banco) y una administradora (cuatro ojos de las siembras).
        $personas = [];
        for ($i = 1; $i <= 8; $i++) {
            $personas[] = $this->person(Admin::ROLE_SUPER_ADMIN, "Persona del banco {$i}");
        }
        [$ana] = $personas;
        $luis = $this->person(Admin::ROLE_ADMINISTRADOR, 'Luis Gómez');
        $otra = $this->personWith(self::SUPER_KEYS, 'Segunda persona de integraciones');

        // A · 200 versiones en estados de un historial real; un borrador guardado por persona.
        $this->crecerVersiones($ana, $luis, 200);
        foreach ($personas as $p) {
            $this->saveDraft($p)->assertOk();
        }

        // B · la cadena: 199 entradas que nombran la PUBLISHED (hace 6 días) y la cabeza, la publicación en canario (hace 5).
        $publicada = $this->seededVersion($ana, $luis, $this->baseline(), 'PUBLISHED');
        $canaria = $this->seededVersion($ana, $luis, $this->baseline(), 'CANARY');
        $this->travelTo(CarbonImmutable::now()->subDays(6));
        for ($i = 0; $i < 199; $i++) {
            $this->seedEntry($i % 2 === 0 ? D::ACTION_PUBLISH : D::ACTION_PROMOTE, $publicada, $this->partOf($publicada), $this->partOf($publicada));
        }
        $this->travelBack();
        $desde = CarbonImmutable::now()->subDays(5);
        $this->travelTo($desde);
        $this->seedEntry(D::ACTION_PUBLISH, $canaria, $this->partOf($canaria), $this->partOf($publicada));
        $this->travelBack();

        // C · fondo de ~6 meses y 200 turnos del canario (el tope del acta).
        $this->sembrarFondo((int) (getenv('PERF_BG_ACTIONS') ?: 20000));
        $this->sembrarTurnosDelCanario((string) $canaria->uuid, $desde, 0, 200);

        // D · Meta con 3 cuentas, 500 campañas en el espejo y 50 asociaciones activas (+100 cerradas); 3 canarios activos.
        $this->metaConfigured();
        $this->crecerEspejo(0, 500);
        $destinos = [$publicada, $canaria, $this->versionIn($ana, 'APPROVED')];
        $this->crecerEnlaces($ana, $destinos, 0, 50);
        foreach (['+573105550001', '+573105550002', '+573105550003'] as $i => $tel) {
            $this->activeCanary($ana, $otra, $tel, 'whatsapp', 'Móvil de pruebas '.($i + 1));
        }

        // E · 50 ejecuciones del laboratorio de 12 casos (una real con el conductor falso y 49 copias).
        $codigos = StudioLabScenario::query()->orderBy('code')->limit(12)->pluck('code')->all();
        $run = (string) $this->launch($ana, ['kind' => 'baseline'], $codigos)->assertCreated()->json('data.uuid');
        $modelo = $this->drain($run, 200);
        $this->assertTrue($modelo->isTerminal());
        for ($i = 1; $i < 50; $i++) {
            $this->clonarEjecucion($modelo);
        }

        // Sesiones del banco (las del arnés en proceso no valen: se emiten aquí, en la base del banco).
        $sesiones = array_map(static fn (Admin $p): string => app(AdminSessionService::class)->issueSession($p)['token'], $personas);
        $fichero = $estado.'/sesiones.txt';
        file_put_contents($fichero, implode("\n", $sesiones)."\n");
        chmod($fichero, 0600);

        $rutas = [
            ['GET status', 'status', 'read', 'cabeza = publicación en canario; 2 partes'],
            ['GET catalog', 'catalog', 'read', 'catálogo 2026-10-19.1'],
            ['GET draft', 'draft', 'read', 'borrador guardado (línea base)'],
            ['GET rules', 'rules', 'read', '12 reglas de la línea base'],
            ['GET versions (20)', 'versions', 'read', '203 versiones'],
            ['GET versions?per_page=50', 'versions?per_page=50', 'read', '203 versiones'],
            ['GET versions/{uuid} (1 entrada)', 'versions/'.$canaria->uuid, 'read', 'la CANARY, 1 entrada'],
            ['GET versions/{uuid} (199 entradas)', 'versions/'.$publicada->uuid, 'read', 'la PUBLISHED, 199 entradas (sin LIMIT)'],
            ['GET publications (20)', 'publications', 'read', 'cadena de 200'],
            ['GET publications?per_page=50', 'publications?per_page=50', 'read', 'cadena de 200'],
            ['GET versions/{uuid}/canary-evidence', 'versions/'.$canaria->uuid.'/canary-evidence', 'evidence', '200 turnos del canario + 20 000 acciones de fondo'],
            ['GET audit', 'audit', 'read', 'libro de la siembra'],
            ['GET engine/readings', 'engine/readings', 'read', '—'],
            ['GET integrations/status', 'integrations/status', 'read', '3 cuentas, 500 campañas, 3 canarios'],
            ['GET integrations/meta/campaigns (20)', 'integrations/meta/campaigns', 'read', '500 campañas / 50 activas'],
            ['GET integrations/meta/campaigns?per_page=50', 'integrations/meta/campaigns?per_page=50', 'read', '500 campañas / 50 activas'],
            ['GET integrations/meta/campaigns?linked=yes&per_page=50', 'integrations/meta/campaigns?linked=yes&per_page=50', 'read', '500 campañas / 50 activas'],
            ['GET integrations/meta/links (20)', 'integrations/meta/links', 'read', '150 asociaciones (50 activas)'],
            ['GET integrations/canaries', 'integrations/canaries', 'read', '3 canarios activos'],
            ['GET lab/status', 'lab/status', 'read', '50 ejecuciones'],
            ['GET lab/runs (20)', 'lab/runs', 'read', '50 ejecuciones de 12 casos'],
            ['GET lab/runs?per_page=50', 'lab/runs?per_page=50', 'read', '50 ejecuciones de 12 casos'],
            ['GET lab/runs/{uuid} (12 casos)', 'lab/runs/'.$run, 'read', 'ejecución terminada de 12 casos'],
        ];
        file_put_contents($estado.'/manifest.json', json_encode([
            'sizes' => [
                'versions' => 203, 'chain' => 200, 'canaryTurns' => 200, 'backgroundActions' => (int) (getenv('PERF_BG_ACTIONS') ?: 20000),
                'metaCampaigns' => 500, 'activeLinks' => 50, 'labRuns' => 50, 'casesPerRun' => count($codigos),
                'canaryConversation' => self::CANARY_ID,
            ],
            'routes' => array_map(static fn (array $r): array => ['name' => $r[0], 'path' => $r[1], 'limiter' => $r[2], 'size' => $r[3]], $rutas),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->assertFileExists($estado.'/manifest.json');
    }
}
