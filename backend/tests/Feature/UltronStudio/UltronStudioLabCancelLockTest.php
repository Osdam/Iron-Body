<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Services\UltronStudio\StudioLabRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * CANCELAR FRENTE AL RECLAMO con DOS SESIONES de PostgreSQL de verdad
 * (Enmienda 4.5; HIP-1 del tester): una cancelación confirmada nunca deja
 * que se reclame (ni se pague) un caso posterior.
 *
 * El paso carga la ejecución (todavía sin petición de cancelar) y se queda
 * esperando el cerrojo de los reclamos, que tiene OTRA sesión: un proceso
 * hijo con su conexión. Cuando el hijo ve en `pg_locks` que el paso espera,
 * confirma la petición de cancelar detrás de ese cerrojo (lo que hace
 * `StudioLabRuns::cancel()`: el cerrojo primero, después el UPDATE) y lo
 * suelta. Con la relectura bajo el cerrojo, el paso la ve y cierra
 * `cancelled` sin reclamar; sin ella (la mutación), reclama el caso 2 con la
 * ejecución ya cancelada y llama al proveedor.
 *
 * Sin la transacción de RefreshDatabase: el hijo tiene que VER la ejecución,
 * así que todo lo de la prueba se confirma. Las tablas prohíben DELETE: al
 * terminar se pide que la siguiente prueba vuelva a crear el esquema.
 *
 * Solo PostgreSQL: SQLite no tiene cerrojos consultivos y escribe en serie (el
 * mismo orden, simulado en una sesión, está en UltronStudioLabRunnerTest).
 */
class UltronStudioLabCancelLockTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** @var list<string> sin transacción envolvente: la otra sesión ve lo confirmado */
    protected array $connectionsToTransact = [];

    private const ACTOR_OK = ['reply' => 'Hola, ¿en qué te puedo ayudar hoy?', 'usage' => [900, 40]];

    private const JUDGE_PASS = ['judge' => 'pass', 'usage' => [1500, 300]];

    private Admin $root;

    /** @var resource|null */
    private $hijo = null;

    /** @var array<int, resource> */
    private array $tuberias = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Dos sesiones y un cerrojo consultivo: solo PostgreSQL (SQLite escribe en serie).');
        }
        // Desde aquí todo se confirma: la siguiente prueba rehará el esquema.
        RefreshDatabaseState::$migrated = false;
        Queue::fake();
        $this->labOpenAi();
        $this->importBaseline();
        $this->importLabScenarios();
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        DB::statement("SET lock_timeout = '30s'");
    }

    protected function tearDown(): void
    {
        $this->cerrarHijo();
        parent::tearDown();
    }

    public function test_una_cancelacion_confirmada_mientras_el_paso_espera_el_cerrojo_nunca_deja_reclamar(): void
    {
        $guion = $this->script([self::ACTOR_OK, self::JUDGE_PASS, self::ACTOR_OK, self::JUDGE_PASS]);
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id), 'el primer caso, en curso la ejecución');

        $this->otraSesionCancelaCuandoEspere($id);
        $resultado = app(StudioLabRunner::class)->step($id);
        $hijo = $this->esperarHijo();

        $this->assertSame(1, $hijo['waiter'], 'el paso no esperó el cerrojo de los reclamos: '.$hijo['salida']);
        $this->assertStringContainsString('UPDATED=1', $hijo['salida'], 'la otra sesión no confirmó la petición de cancelar');
        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertNotNull($run->cancel_requested_at);
        $this->assertSame(['closed', 'cancelled'], [$resultado, $run->status], 'el paso reclamó con la cancelación ya confirmada');
        $this->assertSame(['done', 'skipped', 'skipped'], $run->cases()->pluck('status')->all());
        $this->assertSame([1], StudioLabRunCase::query()->where('run_id', $id)->whereNotNull('started_at')->pluck('position')->all(), 'solo el primer caso llegó a empezar');
        $this->assertCount(2, $guion->calls, 'tras la cancelación no se llamó al proveedor (ni se pagó) otro caso');
    }

    /**
     * Un proceso hijo con SU conexión: BEGIN y el cerrojo de los reclamos; en
     * cuanto otra sesión espera ese cerrojo, la petición de cancelar de la
     * ejecución `$runId` (UPDATE condicionado, como `cancel()`) y COMMIT. Si
     * nadie espera en 10 s, confirma igualmente y lo dice.
     */
    private function otraSesionCancelaCuandoEspere(int $runId): void
    {
        $cfg = DB::connection()->getConfig();
        $orden = [
            'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']),
            'user' => $cfg['username'],
            'password' => $cfg['password'],
            'key' => crc32('ultron_studio_lab:claim'),
            'run' => $runId,
        ];
        $propios = (int) DB::scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND objid = ".crc32('ultron_studio_lab:claim'));
        $this->assertSame(0, $propios, 'La sesión de la prueba ya tiene el cerrojo de los reclamos: el hijo no podría tomarlo.');

        $script = <<<'PHP'
// Las columnas son `timestamp without time zone` con la hora de pared UTC que
// escribe Eloquent: el hijo escribe igual.
$o = json_decode(getenv('ULTRON_STUDIO_LAB_TEST_CHILD'), true);
$pdo = new PDO($o['dsn'], $o['user'], $o['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET lock_timeout = '15s'");
$pdo->beginTransaction();
$pdo->prepare('SELECT pg_advisory_xact_lock(?)')->execute([$o['key']]);
fwrite(STDOUT, "LOCKED\n");
fflush(STDOUT);
$espera = $pdo->prepare("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND objid = ".(int) $o['key']." AND pid <> pg_backend_pid() AND database = (SELECT oid FROM pg_database WHERE datname = current_database())");
$waiter = 0;
$hasta = microtime(true) + 10;
while (microtime(true) < $hasta) {
    $espera->execute();
    if ((int) $espera->fetchColumn() > 0) {
        $waiter = 1;
        break;
    }
    usleep(10000);
}
$u = $pdo->prepare("UPDATE ultron_studio_lab_runs SET cancel_requested_at = (clock_timestamp() AT TIME ZONE 'UTC'), cancel_requested_by_name = 'Otra sesión', updated_at = (clock_timestamp() AT TIME ZONE 'UTC')
    WHERE id = ? AND status IN ('queued', 'running') AND cancel_requested_at IS NULL");
$u->execute([$o['run']]);
$pdo->commit();
fwrite(STDOUT, "WAITER={$waiter}\nUPDATED=".$u->rowCount()."\nCOMMITTED\n");
PHP;

        $this->hijo = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->tuberias, null, ['ULTRON_STUDIO_LAB_TEST_CHILD' => json_encode($orden)] + getenv());
        if (! is_resource($this->hijo)) {
            throw new RuntimeException('No se pudo lanzar la otra sesión.');
        }

        $leer = [$this->tuberias[1]];
        $nada = null;
        $linea = stream_select($leer, $nada, $nada, 20) === 1 ? fgets($this->tuberias[1]) : false;
        if ($linea !== "LOCKED\n") {
            throw new RuntimeException('La otra sesión no tomó el cerrojo: '.$linea.stream_get_contents($this->tuberias[2]));
        }
    }

    /** @return array{waiter:int,salida:string} */
    private function esperarHijo(): array
    {
        $salida = stream_get_contents($this->tuberias[1]).stream_get_contents($this->tuberias[2]);
        $this->cerrarHijo();
        if (! str_contains($salida, 'COMMITTED')) {
            throw new RuntimeException('La otra sesión no confirmó: '.$salida);
        }

        return ['waiter' => preg_match('/WAITER=1/', $salida) === 1 ? 1 : 0, 'salida' => trim($salida)];
    }

    private function cerrarHijo(): void
    {
        foreach ($this->tuberias as $t) {
            if (is_resource($t)) {
                fclose($t);
            }
        }
        $this->tuberias = [];
        if (is_resource($this->hijo)) {
            proc_close($this->hijo);
        }
        $this->hijo = null;
    }
}
