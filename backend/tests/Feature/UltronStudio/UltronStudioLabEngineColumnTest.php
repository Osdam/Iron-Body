<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * B0 DEL APARTADO 5: LA COLUMNA `engine` DE LOS CASOS DEL LABORATORIO
 * (migración `2026_11_22_100001`), en los dos motores.
 *
 *  - es JSON NULL y una simulación la deja NULL;
 *  - se escribe mientras el caso vive y, terminal, la base rechaza cualquier
 *    UPDATE (el disparador terminal ya la cubre), también con el rol de la
 *    aplicación, que no es el dueño (PostgreSQL);
 *  - la migración no se lleva los disparadores (11 de fila en los dos; 16 con
 *    los de TRUNCATE en PostgreSQL), ni en up() ni en down() y up() otra vez;
 *  - C-A1 de database-specialist: down() se niega, sin cambiar nada, si algún
 *    caso lleva `engine` (se perdería la verificación de su `results_hash`).
 */
class UltronStudioLabEngineColumnTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const MIGRATION = '2026_11_22_100001_add_engine_to_ultron_studio_lab_run_cases.php';

    /** El rol de la aplicación de ESTA ejecución (PostgreSQL), con un nombre que no se repite. */
    private string $appRole = '';

    public function test_la_columna_existe_es_nula_y_una_simulacion_la_deja_nula(): void
    {
        $this->assertTrue(Schema::hasColumn('ultron_studio_lab_run_cases', 'engine'));
        $caso = $this->runningCase();
        $this->assertNull($caso->engine);

        Queue::fake();
        $this->labFake();
        $this->importLabScenarios();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $run = $this->drain((string) $this->launch($root, ['kind' => 'baseline'], ['C-01'])->assertCreated()->json('data.uuid'));
        $this->assertSame([null], StudioLabRunCase::query()->where('run_id', $run->id)->pluck('engine')->all());
    }

    public function test_se_escribe_mientras_vive_y_terminal_ya_no(): void
    {
        $caso = $this->runningCase();
        $this->assertTrue(StudioLabRunCase::advance($caso->id, [StudioLabRunCase::STATUS_RUNNING], ['engine' => ['flowRev' => 'S2', 'path' => ['strategist']]]));
        $this->assertSame(['flowRev' => 'S2', 'path' => ['strategist']], $caso->fresh()->engine);
        $this->assertTrue(StudioLabRunCase::advance($caso->id, [StudioLabRunCase::STATUS_RUNNING], ['status' => StudioLabRunCase::STATUS_DONE, 'outcome' => 'pass', 'engine' => ['flowRev' => 'S2', 'path' => ['strategist', 'composer']], 'finished_at' => now()]));

        $this->assertBlocked(fn () => DB::table('ultron_studio_lab_run_cases')->where('id', $caso->id)->update(['engine' => json_encode(['otro' => true])]));
        $this->assertBlocked(fn () => DB::table('ultron_studio_lab_run_cases')->where('id', $caso->id)->update(['engine' => DB::raw('engine')]));
        $this->assertSame(['flowRev' => 'S2', 'path' => ['strategist', 'composer']], $caso->fresh()->engine);
    }

    public function test_la_migracion_no_se_lleva_los_disparadores_ni_al_deshacerse(): void
    {
        $esperados = $this->isPostgres() ? 16 : 11;
        $this->assertSame($esperados, $this->triggers());

        $migracion = require database_path('migrations/'.self::MIGRATION);
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('ultron_studio_lab_run_cases', 'engine'));
        $this->assertSame($esperados, $this->triggers(), 'down() conserva los disparadores');
        $migracion->up();
        $this->assertTrue(Schema::hasColumn('ultron_studio_lab_run_cases', 'engine'));
        $this->assertSame($esperados, $this->triggers(), 'up() conserva los disparadores');

        // Y siguen mordiendo: un caso terminal no admite el UPDATE.
        $caso = $this->runningCase();
        StudioLabRunCase::advance($caso->id, [StudioLabRunCase::STATUS_RUNNING], ['status' => StudioLabRunCase::STATUS_ERROR, 'error_code' => 'timeout', 'finished_at' => now()]);
        $this->assertBlocked(fn () => DB::table('ultron_studio_lab_run_cases')->where('id', $caso->id)->update(['engine' => json_encode([])]));
    }

    public function test_c_a1_down_se_niega_con_casos_del_motor_y_no_cambia_nada(): void
    {
        $esperados = $this->isPostgres() ? 16 : 11;
        // Un caso del motor terminado, con su recorrido, y una simulación (engine NULL).
        $motor = $this->runningCase();
        $this->assertTrue(StudioLabRunCase::advance($motor->id, [StudioLabRunCase::STATUS_RUNNING], ['status' => StudioLabRunCase::STATUS_DONE, 'outcome' => 'pass', 'engine' => ['flowRev' => 'S2', 'path' => ['strategist']], 'finished_at' => now()]));
        $this->runningCase();
        $antes = DB::table('ultron_studio_lab_run_cases')->orderBy('id')->get()->map(static fn (object $f): array => (array) $f)->all();

        $migracion = require database_path('migrations/'.self::MIGRATION);
        $negativa = null;
        try {
            $migracion->down();
        } catch (RuntimeException $e) {
            $negativa = $e;
        }
        $this->assertNotNull($negativa, 'down() quitó la columna con un caso del motor dentro.');
        $this->assertStringContainsString('down() se niega a quitar la columna engine con 1 casos del recorrido del motor', $negativa->getMessage());
        $this->assertStringContainsString('results_hash', $negativa->getMessage());

        $this->assertTrue(Schema::hasColumn('ultron_studio_lab_run_cases', 'engine'));
        $this->assertSame($antes, DB::table('ultron_studio_lab_run_cases')->orderBy('id')->get()->map(static fn (object $f): array => (array) $f)->all(), 'ninguna fila cambia');
        $this->assertSame(['flowRev' => 'S2', 'path' => ['strategist']], $motor->fresh()->engine);
        $this->assertSame($esperados, $this->triggers());
    }

    public function test_c_a1_down_sin_casos_del_motor_quita_la_columna_aunque_haya_simulaciones(): void
    {
        $esperados = $this->isPostgres() ? 16 : 11;
        $simulacion = $this->runningCase();
        StudioLabRunCase::advance($simulacion->id, [StudioLabRunCase::STATUS_RUNNING], ['status' => StudioLabRunCase::STATUS_DONE, 'outcome' => 'pass', 'finished_at' => now()]);

        $migracion = require database_path('migrations/'.self::MIGRATION);
        $migracion->down();

        $this->assertFalse(Schema::hasColumn('ultron_studio_lab_run_cases', 'engine'));
        $this->assertSame(1, DB::table('ultron_studio_lab_run_cases')->where('id', $simulacion->id)->where('status', StudioLabRunCase::STATUS_DONE)->count(), 'la simulación no pierde nada');
        $this->assertSame($esperados, $this->triggers(), 'down() conserva los disparadores');
        $migracion->up();
        $this->assertTrue(Schema::hasColumn('ultron_studio_lab_run_cases', 'engine'));
    }

    public function test_en_postgres_el_rol_de_la_aplicacion_la_escribe_viva_y_no_terminal(): void
    {
        $caso = $this->runningCase();
        if (! $this->isPostgres()) {
            // SQLite no tiene roles: lo que se comprueba allí es la misma frontera sin rol (la escribe viva, terminal no).
            $this->assertTrue(StudioLabRunCase::advance($caso->id, [StudioLabRunCase::STATUS_RUNNING], ['engine' => ['path' => ['strategist']], 'status' => StudioLabRunCase::STATUS_DONE, 'outcome' => 'pass', 'finished_at' => now()]));
            $this->assertBlocked(fn () => DB::table('ultron_studio_lab_run_cases')->where('id', $caso->id)->update(['engine' => json_encode(['path' => []])]));

            return;
        }
        // Un rol PROPIO de esta ejecución (nunca uno que ya exista, con atributos que no se saben), creado
        // aquí y retirado aquí. CREATE ROLE es transaccional: si la prueba cae a medias, se va con la
        // transacción de RefreshDatabase.
        $this->appRole = 'ultron_studio_lab_app_'.bin2hex(random_bytes(6));
        DB::statement('CREATE ROLE '.$this->appRole.' NOLOGIN NOSUPERUSER NOINHERIT');
        DB::statement('GRANT USAGE ON SCHEMA public TO '.$this->appRole);
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ultron_studio_lab_run_cases, ultron_studio_lab_runs TO '.$this->appRole);

        [$estado] = $this->asApp(fn () => DB::table('ultron_studio_lab_run_cases')->where('id', $caso->id)->update(['engine' => json_encode(['path' => ['strategist']])]));
        $this->assertNull($estado, 'viva, la escribe');

        StudioLabRunCase::advance($caso->id, [StudioLabRunCase::STATUS_RUNNING], ['status' => StudioLabRunCase::STATUS_DONE, 'outcome' => 'pass', 'finished_at' => now()]);
        [$estado, $mensaje] = $this->asApp(fn () => DB::table('ultron_studio_lab_run_cases')->where('id', $caso->id)->update(['engine' => json_encode(['path' => []])]));
        $this->assertSame('P0001', $estado);
        $this->assertStringContainsString('ultron_studio_lab: un caso terminado', $mensaje);

        DB::statement('DROP OWNED BY '.$this->appRole);
        DB::statement('DROP ROLE '.$this->appRole);
        $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM pg_roles WHERE rolname = ?', [$this->appRole]), 'el rol de la prueba se retira');
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    private function runningCase(): StudioLabRunCase
    {
        Queue::fake();
        $this->labFake();
        $this->importLabScenarios();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root '.uniqid());
        $uuid = (string) $this->launch($root, ['kind' => 'baseline'], ['C-01'])->assertCreated()->json('data.uuid');
        $run = StudioLabRun::query()->where('uuid', $uuid)->sole();
        $caso = StudioLabRunCase::query()->where('run_id', $run->id)->sole();
        $this->assertTrue(StudioLabRunCase::advance($caso->id, [StudioLabRunCase::STATUS_PENDING], ['status' => StudioLabRunCase::STATUS_RUNNING, 'started_at' => now()]));

        return $caso->fresh();
    }

    private function triggers(): int
    {
        return $this->isPostgres()
            ? count(DB::select("SELECT t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_lab_%'"))
            : DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'usl%')->count();
    }

    private function assertBlocked(\Closure $escribir): void
    {
        try {
            DB::transaction(fn () => $escribir());
        } catch (QueryException $e) {
            $this->assertStringContainsString('ultron_studio_lab:', $e->getMessage());

            return;
        }
        $this->fail('La base admitió un UPDATE sobre un caso terminal.');
    }

    /** @return array{0:?string,1:string} [SQLSTATE o null, mensaje], deshaciendo lo que haga */
    private function asApp(\Closure $fn): array
    {
        $deshacer = new RuntimeException('deshecho');
        try {
            DB::transaction(function () use ($fn, $deshacer): void {
                DB::statement('SET LOCAL ROLE '.$this->appRole);
                $this->assertSame($this->appRole, DB::scalar('SELECT current_user'));
                $fn();

                throw $deshacer;
            });
        } catch (QueryException $e) {
            return [(string) ($e->getPrevious()?->errorInfo[0] ?? $e->getCode()), $e->getMessage()];
        } catch (RuntimeException $e) {
            if ($e === $deshacer) {
                return [null, 'deshecho'];
            }

            throw $e;
        }

        return [null, 'sin deshacer'];
    }
}
