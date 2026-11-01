<?php

namespace Tests\Feature\UltronStudio;

use App\Models\UltronStudio\StudioIntegrationSnapshot;
use App\Services\UltronStudio\StudioIntegrationConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\IntegrationRaceSessions;
use Tests\TestCase;

/**
 * LA CENTINELA CON LA CONFIGURACIÓN DE VERDAD (contrato H6 §I12 y §I9.6). Las
 * demás pruebas ponen la configuración con `config([...])`; aquí un PROCESO
 * HIJO arranca la aplicación con un ENTORNO como el de producción y calcula la
 * configuración con `config/*.php` —incluido `twilio_aliases`, que se calcula
 * al cachear desde `env()` y que en producción es el único sitio donde se
 * comparan `TWILIO_TOKEN` y `TWILIO_AUTH_TOKEN`—. Los dos nombres llevan
 * valores DISTINTOS y reconocibles: el panel solo puede decir «no coinciden»
 * (un booleano) y de dónde sale cada credencial (un nombre). Ni el alias que
 * no se usa ni su huella aparecen en ninguna respuesta, instantánea, consola
 * o log del hijo, y ningún otro secreto del entorno tampoco.
 *
 * Solo PostgreSQL (el hijo tiene que ver las filas y la sesión que confirma
 * la prueba).
 */
class UltronStudioIntegrationsEnvSentinelAdversarialTest extends TestCase
{
    use IntegrationFixtures;
    use IntegrationRaceSessions;
    use RefreshDatabase;
    use StudioFixtures;

    protected array $connectionsToTransact = [];

    private const ENV = [
        'APP_ENV' => 'testing',
        'OTP_DRIVER' => 'twilio',
        'TWILIO_SID' => 'AC5e17e1a55e17e1a55e17e1a55e17a001',
        'TWILIO_ACCOUNT_SID' => 'AC5e17e1a55e17e1a55e17e1a55e17a002',
        'TWILIO_TOKEN' => 'CENTINELA3tokenUSADO9a1f',
        'TWILIO_AUTH_TOKEN' => 'CENTINELA3tokenALIAS0b2e',
        'TWILIO_VERIFY_SERVICE_SID' => 'VA5e17e1a55e17e1a55e17e1a55e17a003',
        'TWILIO_FROM' => '+576015554433',
        'META_ADS_ACCESS_TOKEN' => 'CENTINELA3metaTOKEN1c3d',
        'META_AD_ACCOUNT_IDS' => '5550003330009533,5550004440009644',
        'META_ADS_SYNC_ENABLED' => 'true',
        'ELEVENLABS_ENABLED' => 'false',
        'ELEVENLABS_API_KEY' => 'CENTINELA3elevenAPIKEY2d4e',
        'ELEVENLABS_WEBHOOK_SECRET' => 'CENTINELA3elevenWEBHOOK3e5f',
        'ELEVENLABS_TOOL_SECRET' => 'CENTINELA3elevenTOOL4f6a',
        'ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED' => 'true',
        'ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET' => 'CENTINELA3probeSECRET5a7b',
    ];

    private const SECRETS = ['TWILIO_TOKEN', 'TWILIO_AUTH_TOKEN', 'META_ADS_ACCESS_TOKEN', 'ELEVENLABS_API_KEY', 'ELEVENLABS_WEBHOOK_SECRET', 'ELEVENLABS_TOOL_SECRET', 'ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET'];

    private bool $confirmoFuera = false;

    protected function setUp(): void
    {
        // Se omite ANTES de arrancar la aplicación (ver la nota de la prueba de carreras).
        if (getenv('DB_CONNECTION') !== 'pgsql') {
            $this->markTestSkipped('Un proceso hijo con su entorno: solo PostgreSQL (el hijo no ve la base en memoria de la prueba).');
        }
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        $this->assertTrue($this->isPostgres());
        $this->confirmoFuera = true;
        Carbon::setTestNow(Carbon::parse('2026-11-03T15:00:00Z'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03T15:00:00Z'));
    }

    protected function tearDown(): void
    {
        $this->stopRacers();
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
        if ($this->confirmoFuera) {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_los_alias_de_twilio_con_valores_distintos_solo_dan_un_booleano_y_ningun_secreto_del_entorno_sale(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $hijo = ['inherit' => false, 'env' => self::ENV];
        $ids = [];
        foreach (['twilio', 'config', 'status', 'voice', 'meta/campaigns'] as $ruta) {
            $ids[] = $this->spawnRacer($ruta, $this->raceRequest('GET', "integrations/{$ruta}", $root), $hijo);
        }
        $ids[] = $this->spawnRacer('doctor', null, $hijo + ['artisan' => ['command' => 'ultron-studio:integrations-doctor', 'params' => ['--json' => true]]]);
        $ids[] = $this->spawnRacer('snapshot', null, $hijo + ['artisan' => ['command' => 'ultron-studio:integrations-snapshot', 'params' => ['--json' => true]]]);
        $r = $this->collectRacers($ids);

        foreach ($r as $x) {
            $this->assertNull($x['error'], "{$x['label']}: ".json_encode($x['error']));
            if ($x['exit'] === null) {
                $this->assertSame(200, $x['status'], "{$x['label']}: ".$x['body']);
            } else {
                $this->assertContains($x['exit'], [0, 1], "{$x['label']}: ".$x['output']);
            }
        }
        // El hijo calculó la configuración de verdad (no la de la prueba).
        $twilio = $r[0]['json']['data'];
        $this->assertSame('TWILIO_SID', $twilio['credentials']['accountSid']['source']);
        $this->assertSame('TWILIO_TOKEN', $twilio['credentials']['authToken']['source']);
        $this->assertSame(['sid' => false, 'token' => false], $twilio['credentials']['aliasesConsistent'], 'los dos nombres tienen valores distintos: el panel tiene que decirlo');
        $this->assertSame('AC…a001', $twilio['credentials']['accountSid']['ref']);
        $this->assertSame(1, StudioIntegrationSnapshot::query()->where('provider', 'twilio')->where('actor_type', 'system')->count(), 'el comando anotó lo observado');

        $todo = implode("\n", array_map(static fn (array $x): string => $x['body'].' '.$x['output'].' '.implode(' ', $x['logs']).' '.$x['stderr'], $r))
            .json_encode(DB::table('ultron_studio_integration_snapshots')->get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .json_encode(DB::table('ultron_studio_audit_events')->get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (self::SECRETS as $variable) {
            $this->assertStringNotContainsString(self::ENV[$variable], $todo, "el valor de {$variable} salió por alguna vía");
        }
        foreach (['TWILIO_ACCOUNT_SID', 'TWILIO_SID', 'TWILIO_VERIFY_SERVICE_SID', '5550003330009533', '5550004440009644', '6015554433'] as $c) {
            $this->assertStringNotContainsString(self::ENV[$c] ?? $c, $todo, "«{$c}» salió entero");
        }
        // Ni la huella del alias que NO se usa (con la APP_KEY de la máquina: la que usa el hijo).
        $this->assertStringNotContainsString('"'.StudioIntegrationConfig::valueFingerprint(self::ENV['TWILIO_AUTH_TOKEN']).'"', $todo, 'la huella del alias que no se usa salió');
        $this->assertStringContainsString('"'.StudioIntegrationConfig::valueFingerprint(self::ENV['TWILIO_TOKEN']).'"', $todo, 'la huella de la credencial que sí se usa es la que se enseña');
    }
}
