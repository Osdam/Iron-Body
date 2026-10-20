<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Services\UltronStudio\StudioLabRunner;
use App\Services\UltronStudio\StudioLabRuns;
use App\Services\UltronStudio\StudioLabStepJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * EL CERROJO DE LOS RECLAMOS con DOS SESIONES de PostgreSQL de verdad.
 *
 * CANCELAR FRENTE AL RECLAMO (Enmienda 4.5; HIP-1 del tester): una
 * cancelación confirmada nunca deja que se reclame (ni se pague) un caso
 * posterior.
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
 * CANCELAR SIN CASO EN VUELO (Enmienda 5; H-P2-1 del tester): la cancelación
 * espera el cerrojo y, si al entrar no hay ningún caso en vuelo, cierra en el
 * acto; si el otro acaba de reclamar un caso, solo marca.
 *
 * PASOS DUPLICADOS (H-P2-3 del tester): el reclamo vuelve a mirar, bajo el
 * cerrojo, si hay un caso en curso en el sistema; el paso que esperaba se
 * vuelve a encolar y nunca procesa un segundo caso a la vez.
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

    public function test_cancelar_sin_caso_en_vuelo_la_cierra_en_el_acto_detras_del_cerrojo(): void
    {
        // Enmienda 5 (H-P2-1): la otra sesión tiene el cerrojo de los reclamos
        // (un paso que no llegó a reclamar) cuando llega la cancelación. La
        // cancelación espera el cerrojo y, sin ningún caso en vuelo, cierra en
        // el acto: pendientes saltados, huella y `run.finished`.
        $guion = $this->script([self::ACTOR_OK, self::JUDGE_PASS]);
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));

        $this->otraSesionDetrasDelCerrojo(null);
        $d = $this->lab('POST', "runs/{$uuid}/cancel", $this->root)->assertOk()->json('data');
        $hijo = $this->esperarHijo();

        $this->assertSame(1, $hijo['waiter'], 'la cancelación no esperó el cerrojo de los reclamos: '.$hijo['salida']);
        $this->assertSame(['cancelled', true], [$d['status'], $d['cancelRequested']]);
        $this->assertSame(['done', 'skipped', 'skipped'], array_column($d['cases'], 'status'));
        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertSame([$run->results_hash, $run->results_hash], [$d['resultsHash'], StudioLabRuns::resultsHash($run, $run->cases()->get())]);
        $this->assertSame(['run.created', 'run.cancel_requested', 'run.finished'], StudioLabEvent::query()->where('subject_uuid', $uuid)->orderBy('id')->pluck('event')->all());
        $this->assertCount(2, $guion->calls);
    }

    public function test_cancelar_con_un_caso_en_vuelo_solo_la_marca(): void
    {
        // La otra sesión es un paso que, con el cerrojo, reclama el caso 2
        // justo cuando la cancelación lo espera. La cancelación, al entrar, ve
        // el caso en vuelo (READ COMMITTED tras el cerrojo) y solo marca: la
        // llamada en vuelo termina (y se cobra) y el siguiente paso la cierra.
        $this->script([self::ACTOR_OK, self::JUDGE_PASS]);
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));

        $this->otraSesionReclamaCuandoEspere((int) StudioLabRunCase::query()->where('run_id', $id)->where('position', 2)->value('id'));
        $d = $this->lab('POST', "runs/{$uuid}/cancel", $this->root)->assertOk()->json('data');
        $hijo = $this->esperarHijo();

        $this->assertSame(1, $hijo['waiter'], 'la cancelación no esperó el cerrojo de los reclamos: '.$hijo['salida']);
        $this->assertStringContainsString('UPDATED=1', $hijo['salida'], 'la otra sesión no reclamó el caso 2');
        $this->assertSame(['running', true], [$d['status'], $d['cancelRequested']], 'cerró la ejecución con un caso en vuelo');
        $this->assertSame(['allowed' => false, 'code' => 'cancel_requested', 'message' => 'Ya se pidió cancelarla: la cierra el siguiente paso.'], $d['actions']['cancel']);
        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertSame(['running', null, null], [$run->status, $run->finished_at, $run->results_hash]);
        $this->assertNotNull($run->cancel_requested_at);
        $this->assertSame(['done', 'running', 'pending'], $run->cases()->pluck('status')->all());
        $this->assertSame(['run.created', 'run.cancel_requested'], StudioLabEvent::query()->where('subject_uuid', $uuid)->orderBy('id')->pluck('event')->all());
    }

    public function test_dos_pasos_duplicados_detras_del_cerrojo_nunca_procesan_dos_casos_a_la_vez(): void
    {
        // H-P2-3 del tester. Dos pasos de la misma ejecución a la vez: la otra
        // sesión es el primero, que con el cerrojo reclama el caso 2; este paso
        // ya pasó la comprobación de ocupado de ANTES del cerrojo y lo espera.
        // Al entrar, el reclamo vuelve a mirar si hay un caso en curso en el
        // sistema (`busy()` dentro de `claim()`): lo hay, y el paso se vuelve a
        // encolar con retraso sin reclamar ni llamar al proveedor. Sin esa
        // comprobación (R-DUP) reclama el caso 3 con el 2 en curso.
        $guion = $this->script([self::ACTOR_OK, self::JUDGE_PASS, self::ACTOR_OK, self::JUDGE_PASS]);
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $antes = count(Queue::pushed(StudioLabStepJob::class));

        $this->otraSesionReclamaCuandoEspere((int) StudioLabRunCase::query()->where('run_id', $id)->where('position', 2)->value('id'));
        $resultado = app(StudioLabRunner::class)->step($id);
        $hijo = $this->esperarHijo();

        $this->assertSame(1, $hijo['waiter'], 'el paso no esperó el cerrojo de los reclamos: '.$hijo['salida']);
        $this->assertStringContainsString('UPDATED=1', $hijo['salida'], 'la otra sesión no reclamó el caso 2');
        $this->assertSame('busy', $resultado, 'el paso duplicado reclamó con otro caso en curso');
        $this->assertSame(['done', 'running', 'pending'], StudioLabRunCase::query()->where('run_id', $id)->orderBy('position')->pluck('status')->all(), 'dos casos a la vez');
        $this->assertCount(2, $guion->calls, 'el paso duplicado llamó al proveedor');
        $reencolados = Queue::pushed(StudioLabStepJob::class)->slice($antes)->values();
        $this->assertCount(1, $reencolados);
        $this->assertNotNull($reencolados[0]->delay, 'se vuelve a encolar con retraso');
    }

    /** La petición de cancelar desde la otra sesión (UPDATE condicionado, lo que dejaba `cancel()` en curso). */
    private function otraSesionCancelaCuandoEspere(int $runId): void
    {
        $this->otraSesionDetrasDelCerrojo("UPDATE ultron_studio_lab_runs SET cancel_requested_at = (clock_timestamp() AT TIME ZONE 'UTC'), cancel_requested_by_name = 'Otra sesión', updated_at = (clock_timestamp() AT TIME ZONE 'UTC')
    WHERE id = ? AND status IN ('queued', 'running') AND cancel_requested_at IS NULL", [$runId]);
    }

    /** El reclamo de un caso desde la otra sesión (UPDATE condicionado `pending → running`, como `claim()`). */
    private function otraSesionReclamaCuandoEspere(int $caseId): void
    {
        $this->otraSesionDetrasDelCerrojo("UPDATE ultron_studio_lab_run_cases SET status = 'running', started_at = (clock_timestamp() AT TIME ZONE 'UTC'), updated_at = (clock_timestamp() AT TIME ZONE 'UTC')
    WHERE id = ? AND status = 'pending'", [$caseId]);
    }

    /**
     * Un proceso hijo con SU conexión: BEGIN y el cerrojo de los reclamos; en
     * cuanto otra sesión espera ese cerrojo, `$sql` con `$params` (si lo hay)
     * y COMMIT. Si nadie espera en 10 s, sigue igualmente y lo dice
     * (`WAITER=0`). `UPDATED` son las filas que cambió `$sql`.
     *
     * @param  list<int|string>  $params
     */
    private function otraSesionDetrasDelCerrojo(?string $sql, array $params = []): void
    {
        $cfg = DB::connection()->getConfig();
        $orden = [
            'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']),
            'user' => $cfg['username'],
            'password' => $cfg['password'],
            'key' => crc32('ultron_studio_lab:claim'),
            'sql' => $sql,
            'params' => $params,
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
$filas = 0;
if ($o['sql'] !== null) {
    $u = $pdo->prepare($o['sql']);
    $u->execute($o['params']);
    $filas = $u->rowCount();
}
$pdo->commit();
fwrite(STDOUT, "WAITER={$waiter}\nUPDATED={$filas}\nCOMMITTED\n");
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
