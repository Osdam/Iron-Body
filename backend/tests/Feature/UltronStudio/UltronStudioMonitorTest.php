<?php

namespace Tests\Feature\UltronStudio;

use App\Console\Commands\UltronStudio\MonitorCommand;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * El monitor del Studio (contrato H7, OPS7 (a)): con la bandera apagada no se
 * programa nada; encendida, el monitor cada 30 minutos y la instantánea de
 * integraciones a diario. El comando agrupa `publication-check`,
 * `integrations-doctor --expect=…` (más la deriva de la configuración
 * autorizada) y la salud de las colas, sale con 1 si algo no cuadra y abre o
 * suma al incidente de IRON GUARD de su clase; `--dry-run` solo lee.
 */
class UltronStudioMonitorTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** La clasificación de las integraciones en la suite (sin credenciales de nadie). */
    private string $suiteExpect = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Lo que afirma el doctor en esta suite: la expectativa «todo en orden» se lee de él, no se supone.
        Artisan::call('ultron-studio:integrations-doctor', ['--json' => true]);
        $proveedores = json_decode(Artisan::output(), true)['providers'] ?? [];
        $pares = [];
        foreach ($proveedores as $p) {
            if (in_array($p['id'] ?? null, ['twilio_voice', 'elevenlabs'], true) && ($p['available'] ?? false) === true) {
                $pares[] = $p['id'].':'.($p['id'] === 'elevenlabs' ? ($p['code'] ?? $p['classification']) : $p['classification']);
            }
        }
        $this->suiteExpect = implode(',', $pares);
        $this->assertNotSame('', $this->suiteExpect, 'el doctor tiene que clasificar la voz en la suite');
        config(['ultron_studio.monitor.expect' => $this->suiteExpect]);
    }

    // ── La bandera y el programador ──────────────────────────────────────────

    /** @return list<Event> los eventos que programa routes/console.php con la configuración de ahora */
    private function scheduledStudioEvents(): array
    {
        $this->app->instance(Schedule::class, new Schedule);
        Facade::clearResolvedInstances();
        require base_path('routes/console.php');

        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $e): bool => str_contains((string) $e->command, 'ultron-studio:') || str_contains((string) $e->command, 'queue:health'),
        ));
    }

    public function test_con_la_bandera_apagada_no_se_programa_nada_del_studio(): void
    {
        config(['ultron_studio.monitor.enabled' => false]);
        $this->assertSame([], $this->scheduledStudioEvents());
    }

    public function test_con_la_bandera_encendida_el_monitor_cada_30_minutos_y_la_instantanea_a_diario(): void
    {
        config(['ultron_studio.monitor.enabled' => true]);
        $eventos = $this->scheduledStudioEvents();
        $this->assertCount(2, $eventos, 'el monitor y la instantánea, nada más');

        [$monitor, $instantanea] = str_contains((string) $eventos[0]->command, 'ultron-studio:monitor') ? $eventos : array_reverse($eventos);
        $this->assertStringEndsWith("'artisan' ultron-studio:monitor", (string) $monitor->command);
        $this->assertSame('*/30 * * * *', $monitor->expression);
        $this->assertTrue($monitor->withoutOverlapping);
        $this->assertSame(10, $monitor->expiresAt);
        $this->assertTrue($monitor->onOneServer);

        $this->assertStringEndsWith("'artisan' ultron-studio:integrations-snapshot", (string) $instantanea->command);
        $this->assertSame('10 7 * * *', $instantanea->expression);
        $this->assertSame('America/Bogota', $instantanea->timezone);
        $this->assertTrue($instantanea->withoutOverlapping);
        $this->assertTrue($instantanea->onOneServer);
    }

    public function test_la_bandera_solo_se_enciende_con_true_y_la_expectativa_vacia_es_la_de_por_defecto(): void
    {
        $defecto = 'twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED';
        $leer = function (array $variables): array {
            $antes = [];
            foreach ($variables as $nombre => $valor) {
                $antes[$nombre] = getenv($nombre);
                unset($_SERVER[$nombre], $_ENV[$nombre]);
                putenv($nombre);
                if ($valor !== null) {
                    $_SERVER[$nombre] = $_ENV[$nombre] = $valor;
                    putenv("{$nombre}={$valor}");
                }
            }
            try {
                return (require base_path('config/ultron_studio.php'))['monitor'];
            } finally {
                foreach ($antes as $nombre => $proceso) {
                    unset($_SERVER[$nombre], $_ENV[$nombre]);
                    putenv($nombre);
                    if ($proceso !== false) {
                        putenv("{$nombre}={$proceso}");
                    }
                }
            }
        };

        $this->assertSame(['enabled' => false, 'expect' => $defecto], $leer(['ULTRON_STUDIO_MONITOR_ENABLED' => null, 'ULTRON_STUDIO_MONITOR_EXPECT' => null]));
        foreach (['off', 'no', 'false', '0', '', 'si', 'enabled', ' activa'] as $valor) {
            $this->assertFalse($leer(['ULTRON_STUDIO_MONITOR_ENABLED' => $valor])['enabled'], json_encode($valor));
        }
        foreach (['true', 'TRUE', '1', 'on', 'yes'] as $valor) {
            $this->assertTrue($leer(['ULTRON_STUDIO_MONITOR_ENABLED' => $valor])['enabled'], $valor);
        }
        // Una línea vacía no apaga la vigilancia; un valor propio se respeta tal cual (el monitor lo valida).
        $this->assertSame($defecto, $leer(['ULTRON_STUDIO_MONITOR_EXPECT' => '  '])['expect']);
        $this->assertSame('meta_ads:connected_read', $leer(['ULTRON_STUDIO_MONITOR_EXPECT' => ' meta_ads:connected_read '])['expect']);
    }

    public function test_env_example_documenta_las_dos_variables_con_sus_defectos(): void
    {
        $lineas = preg_split('/\R/', (string) file_get_contents(base_path('.env.example')));
        $this->assertContains('ULTRON_STUDIO_MONITOR_ENABLED=false', $lineas);
        $this->assertContains('ULTRON_STUDIO_MONITOR_EXPECT=', $lineas, 'vacía: rige la expectativa del código');
    }

    // ── El comando ───────────────────────────────────────────────────────────

    /** @return array{0:int,1:array<string,mixed>} */
    private function monitor(array $opciones = []): array
    {
        $codigo = Artisan::call('ultron-studio:monitor', $opciones + ['--json' => true]);
        $salida = Artisan::output();
        $json = json_decode($salida, true);
        $this->assertIsArray($json, 'el monitor tiene que devolver JSON: '.$salida);

        return [$codigo, $json];
    }

    public function test_con_todo_en_orden_sale_con_0_y_no_abre_nada(): void
    {
        [$codigo, $r] = $this->monitor();

        $this->assertSame(0, $codigo);
        $this->assertTrue($r['ok']);
        $this->assertSame(['publication', 'integrations', 'queues'], array_keys($r['checks']));
        $this->assertSame([], $r['checks']['publication']['problems']);
        $this->assertNull($r['checks']['publication']['head'], 'cadena vacía: Legacy');
        $this->assertSame(['meta_ads', 'twilio', 'voice'], array_keys($r['checks']['integrations']['configStates']));
        $this->assertNotContains('drift', $r['checks']['integrations']['configStates']);
        $this->assertSame([], $r['incidents']);
        $this->assertSame(0, Incident::query()->count());

        // En texto, lo mismo.
        $this->artisan('ultron-studio:monitor')->expectsOutputToContain('El Studio cuadra.')->assertSuccessful();
    }

    public function test_una_cabeza_que_no_verifica_abre_un_incidente_y_la_misma_deriva_suma_ocurrencias(): void
    {
        $this->openKeys();
        // Una entrada escrita a mano encima de la cadena, con una firma que no es la de la clave: el motor sella fallback.
        $falsa = str_repeat('a', 64);
        $entrada = $this->seedEntry(D::ACTION_LEGACY, null, null, null, ['signature' => $falsa]);

        [$codigo, $r] = $this->monitor(['--check' => ['publication']]);
        $this->assertSame(1, $codigo);
        $this->assertFalse($r['ok']);
        $this->assertSame(['head_invalid'], $r['checks']['publication']['problems']);
        $this->assertFalse($r['checks']['publication']['head']['signatureOk']);
        $this->assertSame(['publication'], array_keys($r['checks']), 'solo la comprobación pedida');

        $incidente = Incident::query()->sole();
        $this->assertSame([MonitorCommand::SOURCE, MonitorCommand::KIND_PUBLICATION, Incident::SEVERITY_HIGH, Incident::STATUS_OPEN, 1],
            [$incidente->source, $incidente->kind, $incidente->severity, $incidente->status, $incidente->occurrences]);
        $this->assertSame(['head_invalid'], $incidente->evidence['problems']);
        $this->assertSame('php artisan ultron-studio:publication-check', $incidente->evidence['check_command']);
        $this->assertSame(1, IncidentEvent::query()->where('incident_id', $incidente->id)->count());
        $this->assertSame([['check' => 'publication', 'id' => $incidente->id, 'kind' => 'publication_drift', 'severity' => 'high', 'occurrences' => 1]], $r['incidents']);

        // La misma deriva 30 minutos después: el mismo incidente con una ocurrencia más (no otro, no reabierto).
        $this->travel(30)->minutes();
        [$codigo2] = $this->monitor(['--check' => ['publication']]);
        $this->assertSame(1, $codigo2);
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(2, $incidente->fresh()->occurrences);
        $this->assertSame(1, IncidentEvent::query()->where('incident_id', $incidente->id)->count(), 'sumar no reabre');

        // Nada del cuerpo de la entrada sale en la salida ni en la evidencia.
        $todo = json_encode($r).json_encode($incidente->fresh()->evidence);
        foreach ([$falsa, (string) $entrada->effective_hash, (string) $entrada->effective_json, (string) $entrada->uuid, (string) self::CANARY_ID] as $secreto) {
            $this->assertStringNotContainsString($secreto, $todo);
        }
    }

    public function test_con_dry_run_solo_lee(): void
    {
        $this->openKeys();
        $this->seedEntry(D::ACTION_LEGACY, null, null, null, ['signature' => str_repeat('b', 64)]);

        [$codigo, $r] = $this->monitor(['--dry-run' => true]);
        $this->assertSame(1, $codigo);
        $this->assertTrue($r['dryRun']);
        $this->assertSame([], $r['incidents']);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_integraciones_distintas_de_lo_esperado_abren_su_incidente(): void
    {
        // Lo esperado ya no es lo que hay (p. ej., alguien configuró ElevenLabs): la expectativa no casa.
        config(['ultron_studio.monitor.expect' => 'elevenlabs:ELEVENLABS_VERIFIED']);

        [$codigo, $r] = $this->monitor(['--check' => ['integrations']]);
        $this->assertSame(1, $codigo);
        $i = $r['checks']['integrations'];
        $this->assertSame('drift', $i['status']);
        $this->assertCount(1, $i['mismatches']);
        $this->assertSame(['elevenlabs', 'ELEVENLABS_VERIFIED'], [$i['mismatches'][0]['id'], $i['mismatches'][0]['expected']]);
        $this->assertStringStartsWith('elevenlabs:ELEVENLABS_VERIFIED->', $i['problems'][0]);

        $incidente = Incident::query()->sole();
        $this->assertSame([MonitorCommand::SOURCE, MonitorCommand::KIND_INTEGRATIONS, Incident::SEVERITY_HIGH], [$incidente->source, $incidente->kind, $incidente->severity]);
        // En PostgreSQL la evidencia es jsonb, que reordena las claves de cada objeto (`id`, `actual`, `expected`):
        // se comparan con las claves ordenadas en los dos lados y con assertSame (mismos valores y mismos TIPOS; el
        // orden de las listas cuenta). En SQLite el orden ya coincide.
        $this->assertSame(self::clavesOrdenadas($i['mismatches']), self::clavesOrdenadas($incidente->evidence['mismatches']));
        $this->assertSame(['actual', 'expected', 'id'], array_keys(self::clavesOrdenadas($incidente->evidence['mismatches'][0])));
    }

    /**
     * Las claves de cada objeto (array asociativo) ordenadas, a cualquier profundidad; las listas conservan su orden.
     *
     * @param  array<mixed>  $valor
     * @return array<mixed>
     */
    private static function clavesOrdenadas(array $valor): array
    {
        foreach ($valor as $k => $v) {
            if (is_array($v)) {
                $valor[$k] = self::clavesOrdenadas($v);
            }
        }
        if (! array_is_list($valor)) {
            ksort($valor, SORT_STRING);
        }

        return $valor;
    }

    public function test_una_expectativa_mal_escrita_falla_en_voz_alta_y_no_tumba_a_las_demas(): void
    {
        foreach (['elevenlabs', 'nadie:connected', 'twilio:connected otp'] as $mal) {
            config(['ultron_studio.monitor.expect' => $mal]);
            [$codigo, $r] = $this->monitor(['--dry-run' => true]);
            $this->assertSame(1, $codigo, $mal);
            $this->assertSame(['expectation_invalid'], $r['checks']['integrations']['problems'], $mal);
            // Las otras dos se comprobaron igual.
            $this->assertTrue($r['checks']['publication']['ok'], $mal);
            $this->assertTrue($r['checks']['queues']['ok'], $mal);
        }

        config(['ultron_studio.monitor.expect' => 'elevenlabs']);
        $this->monitor();
        $incidente = Incident::query()->sole();
        $this->assertSame([MonitorCommand::KIND_CHECK_FAILED, Incident::SEVERITY_MEDIUM, ['expectation_invalid']],
            [$incidente->kind, $incidente->severity, $incidente->evidence['problems']]);
    }

    public function test_un_carril_sin_worker_sale_con_1_sin_incidente_propio(): void
    {
        // Trabajo esperando en el carril del laboratorio desde hace 20 minutos y ningún latido (su umbral es de 300 s).
        DB::table('jobs')->insert([
            'queue' => (string) config('queue.lanes.lab.queue'), 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->subMinutes(20)->timestamp, 'created_at' => now()->subMinutes(20)->timestamp,
        ]);

        [$codigo, $r] = $this->monitor(['--check' => ['queues']]);
        $this->assertSame(1, $codigo);
        $this->assertSame(['lab:unattended'], $r['checks']['queues']['problems']);
        // Las colas son de iron-guard:scan (queue_unattended por carril): el monitor no duplica el incidente.
        $this->assertSame([], $r['incidents']);
        $this->assertSame(0, Incident::query()->count());
    }

    /**
     * Paridad: el monitor aplica las reglas de `publication-check` y de
     * `integrations-doctor --expect` sobre los mismos servicios; si una de las
     * dos cambia, esta prueba lo dice.
     */
    public function test_el_veredicto_es_el_de_los_comandos_que_agrupa(): void
    {
        $this->openKeys();
        $expectativas = [$this->suiteExpect, 'elevenlabs:ELEVENLABS_VERIFIED', 'twilio_voice:connected', 'canaries:in_sync', 'meta_ads:not_configured'];
        foreach ([false, true] as $cabezaFalsa) {
            if ($cabezaFalsa) {
                $this->seedEntry(D::ACTION_LEGACY, null, null, null, ['signature' => str_repeat('c', 64)]);
            }
            $comando = Artisan::call('ultron-studio:publication-check');
            [, $r] = $this->monitor(['--check' => ['publication'], '--dry-run' => true]);
            $this->assertSame($comando === 0, $r['checks']['publication']['ok'], 'cabeza falsa: '.json_encode($cabezaFalsa));
            Artisan::call('ultron-studio:publication-check', ['--json' => true]);
            $this->assertSame(json_decode(Artisan::output(), true)['problems'], $r['checks']['publication']['problems']);
        }
        foreach ($expectativas as $e) {
            config(['ultron_studio.monitor.expect' => $e]);
            $comando = Artisan::call('ultron-studio:integrations-doctor', ['--json' => true, '--expect' => $e]);
            $doctor = json_decode(Artisan::output(), true);
            [, $r] = $this->monitor(['--check' => ['integrations'], '--dry-run' => true]);
            $this->assertSame($comando === 0, $r['checks']['integrations']['ok'], $e);
            $this->assertSame($doctor['mismatches'], $r['checks']['integrations']['mismatches'], $e);
        }
    }

    public function test_una_comprobacion_desconocida_sale_con_2_sin_comprobar_nada(): void
    {
        $this->assertSame(2, Artisan::call('ultron-studio:monitor', ['--check' => ['publication', 'todo']]));
        $this->assertStringContainsString('Comprobación desconocida: todo', Artisan::output());
        $this->assertSame(0, Incident::query()->count());
    }
}
