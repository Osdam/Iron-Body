<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabRun;
use App\Services\UltronStudio\StudioLabRunner;
use App\Services\UltronStudio\StudioLabRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * La RESERVA ATÓMICA del presupuesto con DOS SESIONES de PostgreSQL de verdad
 * (contrato H4 §A3: «dos `POST lab/runs` que juntas superan el tope → 201 +
 * 409, nunca dos 201»).
 *
 * Un proceso hijo, con SU conexión, toma el cerrojo del presupuesto, reserva
 * casi todo el día con una ejecución propia y la CONFIRMA solo cuando ve en
 * `pg_locks` que la petición espera ese cerrojo. Con el cerrojo, la petición
 * lee lo comprometido DESPUÉS de esa confirmación y responde 409
 * `lab_daily_budget_exceeded`. Sin él (la mutación «sin cerrojo de
 * presupuesto»), no espera, no la ve y crea una segunda ejecución: 201.
 *
 * Además, sobre el SQL: READ COMMITTED primero y el cerrojo antes de leer lo
 * comprometido; y READ COMMITTED aunque la sesión vaya en REPEATABLE READ (la
 * lección B-2 de G).
 *
 * Solo PostgreSQL: SQLite no tiene cerrojos consultivos y escribe en serie.
 * El hijo confirma filas que la transacción de la prueba no puede deshacer (y
 * las tablas prohíben DELETE), así que al terminar se pide que la siguiente
 * prueba vuelva a crear el esquema.
 */
class UltronStudioLabBudgetLockTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $root;

    /** @var resource|null */
    private $hijo = null;

    /** @var array<int, resource> */
    private array $tuberias = [];

    private bool $confirmoFuera = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Dos sesiones y un cerrojo consultivo: solo PostgreSQL (SQLite escribe en serie).');
        }
        Queue::fake();
        $this->labOpenAi();
        $this->importLabScenarios();
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        DB::statement("SET lock_timeout = '30s'");
    }

    protected function tearDown(): void
    {
        $this->cerrarHijo();
        parent::tearDown();
        if ($this->confirmoFuera) {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_dos_ejecuciones_que_juntas_superan_el_tope_dan_un_201_y_un_409(): void
    {
        $cuerpo = ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01')]];
        $maximo = $this->lab('POST', 'estimate', $this->root, $cuerpo)->assertOk()->json('data.estimate.maxMicros');
        $this->assertGreaterThan(1000, $maximo);

        // La otra sesión reserva todo el día menos 1000 micro-US$ y confirma cuando la petición espere.
        $this->otraSesionReservaCuandoEspere(2000000 - 1000);
        $r = $this->lab('POST', 'runs', $this->root, $cuerpo + ['acceptedMaxCostMicros' => $maximo, 'clientRequestId' => (string) Str::uuid()]);
        $hijo = $this->esperarHijo();

        $this->assertSame(1, $hijo['waiter'], 'POST lab/runs no esperó el cerrojo del presupuesto: '.$hijo['salida']);
        $r->assertStatus(409)
            ->assertJsonPath('code', 'lab_daily_budget_exceeded')
            ->assertJsonPath('requiredMicros', $maximo)
            ->assertJsonPath('remainingMicros', 1000);
        $this->assertSame(1, StudioLabRun::query()->count(), 'solo la de la otra sesión');
    }

    public function test_read_committed_primero_y_el_cerrojo_antes_de_leer_lo_comprometido(): void
    {
        $sentencias = [];
        DB::listen(function ($q) use (&$sentencias): void {
            $sentencias[] = [$q->sql, $q->bindings];
        });
        $this->launch($this->root, ['kind' => 'baseline'], ['C-01'])->assertCreated();

        $nivel = $cerrojo = $lee = $inserta = null;
        foreach ($sentencias as $i => [$sql, $bindings]) {
            if ($nivel === null && $sql === 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED') {
                $nivel = $i;
            }
            if ($cerrojo === null && str_contains($sql, 'pg_advisory_xact_lock') && $bindings === [crc32('ultron_studio_lab:budget')]) {
                $cerrojo = $i;
            }
            if ($cerrojo !== null && $lee === null && str_contains($sql, 'from "ultron_studio_lab_run_cases" as "c" inner join "ultron_studio_lab_runs" as "r"')) {
                $lee = $i;
            }
            if ($inserta === null && str_starts_with($sql, 'insert into "ultron_studio_lab_runs"')) {
                $inserta = $i;
            }
        }
        $this->assertNotNull($cerrojo, 'POST lab/runs no toma el cerrojo del presupuesto');
        $this->assertNotNull($nivel);
        $this->assertLessThan($cerrojo, $nivel, 'READ COMMITTED va antes del cerrojo');
        $this->assertNotNull($lee, 'no se leyó lo comprometido DESPUÉS del cerrojo');
        $this->assertLessThan($inserta, $lee);
    }

    public function test_los_cerrojos_van_en_read_committed_aunque_la_sesion_vaya_en_repeatable_read(): void
    {
        // Una sesión PROPIA, fuera de la transacción de la prueba, con REPEATABLE
        // READ por defecto, como la dejaría un `default_transaction_isolation`
        // en el rol (lección B-2 de G). Los dos cerrojos del laboratorio (el del
        // presupuesto y el de los reclamos) fijan READ COMMITTED como PRIMERA
        // sentencia de su transacción.
        config(['database.connections.lab_rr' => config('database.connections.'.DB::getDefaultConnection())]);
        $rr = DB::connection('lab_rr');
        $rr->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $rr->statement("SET lock_timeout = '5s'");
        $this->assertSame('repeatable read', $rr->transaction(static fn () => $rr->selectOne('SHOW transaction_isolation')->transaction_isolation));

        $runs = app(StudioLabRuns::class);
        $presupuesto = new \ReflectionMethod($runs, 'serializeBudget');
        $runner = app(StudioLabRunner::class);
        $porDefecto = DB::getDefaultConnection();
        DB::setDefaultConnection('lab_rr');
        try {
            $niveles = [
                $rr->transaction(function () use ($presupuesto, $runs, $rr): string {
                    $presupuesto->invoke($runs);

                    return $rr->selectOne('SHOW transaction_isolation')->transaction_isolation;
                }),
                $rr->transaction(function () use ($runner, $rr): string {
                    $runner->serializeClaims();

                    return $rr->selectOne('SHOW transaction_isolation')->transaction_isolation;
                }),
            ];
        } finally {
            DB::setDefaultConnection($porDefecto);
            DB::purge('lab_rr');
        }
        $this->assertSame(['read committed', 'read committed'], $niveles);
    }

    /**
     * Un proceso hijo con SU conexión: BEGIN, el cerrojo del presupuesto, un
     * escenario, una ejecución en cola de HOY (día de Bogotá) y su caso con
     * `$reserva` micro-US$ reservados; en cuanto otra sesión espera ese
     * cerrojo, COMMIT. Si nadie espera en 10 s, confirma igualmente y lo dice.
     */
    private function otraSesionReservaCuandoEspere(int $reserva): void
    {
        $cfg = DB::connection()->getConfig();
        $orden = [
            'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']),
            'user' => $cfg['username'],
            'password' => $cfg['password'],
            'key' => crc32('ultron_studio_lab:budget'),
            'day' => StudioLabRuns::budgetDay(),
            'reserve' => $reserva,
            'scenario' => [(string) Str::uuid(), 'E-900'],
            'run' => [(string) Str::uuid(), (string) Str::uuid()],
        ];
        $propios = (int) DB::scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND objid = ".crc32('ultron_studio_lab:budget'));
        $this->assertSame(0, $propios, 'La sesión de la prueba ya tiene el cerrojo del presupuesto: el hijo no podría tomarlo.');

        $script = <<<'PHP'
// Las columnas son `timestamp without time zone` con la hora de pared UTC que
// escribe Eloquent: el hijo escribe igual (`now()` iría en la zona del servidor).
$o = json_decode(getenv('ULTRON_STUDIO_LAB_TEST_CHILD'), true);
$pdo = new PDO($o['dsn'], $o['user'], $o['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET lock_timeout = '15s'");
$pdo->beginTransaction();
$pdo->prepare('SELECT pg_advisory_xact_lock(?)')->execute([$o['key']]);
$s = $pdo->prepare("INSERT INTO ultron_studio_lab_scenarios (uuid, code, source, status, category, title, description, content, content_hash, revision, created_at, updated_at)
    VALUES (?, ?, 'studio', 'active', 'otro', 'De la otra sesión', NULL, '{\"turns\":[],\"facts\":{},\"criteria\":[]}', repeat('a', 64), 1, (now() AT TIME ZONE 'UTC'), (now() AT TIME ZONE 'UTC')) RETURNING id");
$s->execute($o['scenario']);
$escenario = (int) $s->fetchColumn();
$r = $pdo->prepare("INSERT INTO ultron_studio_lab_runs (uuid, client_request_id, target_kind, target_label, target_content_hash, target_snapshot, catalog_version, mode, model, prompt_version, prices, status, case_count, estimate_expected_micros, estimate_max_micros, accepted_max_micros, budget_day, created_by_admin_id, created_by_name, created_at, updated_at)
    VALUES (?, ?, 'baseline', 'Otra sesión', repeat('b', 64), '{}', 'x', 'openai', 'gpt-lab-pruebas', 'x', '{}', 'queued', 1, ?, ?, ?, ?, 1, 'Otra sesión', (now() AT TIME ZONE 'UTC'), (now() AT TIME ZONE 'UTC')) RETURNING id");
$r->execute([$o['run'][0], $o['run'][1], $o['reserve'], $o['reserve'], $o['reserve'], $o['day']]);
$run = (int) $r->fetchColumn();
$pdo->prepare("INSERT INTO ultron_studio_lab_run_cases (run_id, position, scenario_id, scenario_code, scenario_revision, scenario_snapshot, status, budget_day, reserved_micros, overrun_micros, created_at, updated_at)
    VALUES (?, 1, ?, 'E-900', 1, '{}', 'pending', ?, ?, 0, (now() AT TIME ZONE 'UTC'), (now() AT TIME ZONE 'UTC'))")->execute([$run, $escenario, $o['day'], $o['reserve']]);
fwrite(STDOUT, "LOCKED\n");
fflush(STDOUT);
$espera = $pdo->prepare("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND pid <> pg_backend_pid() AND database = (SELECT oid FROM pg_database WHERE datname = current_database())");
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
$pdo->commit();
fwrite(STDOUT, "WAITER={$waiter}\nCOMMITTED\n");
PHP;

        $this->hijo = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->tuberias, null, ['ULTRON_STUDIO_LAB_TEST_CHILD' => json_encode($orden)] + getenv());
        if (! is_resource($this->hijo)) {
            throw new RuntimeException('No se pudo lanzar la otra sesión.');
        }
        $this->confirmoFuera = true;

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
