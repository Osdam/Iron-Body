<?php

namespace Tests\Feature\Plans;

use App\Models\AuditLog;
use App\Models\Plan;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Members\MembershipTransferFixtures;
use Tests\TestCase;

/**
 * El plan borrado, contra PostgreSQL de verdad y con commits reales
 * (`DatabaseTruncation`), como MembershipTransferPostgresTest:
 *
 *   · la llave `payments_plan_id_foreign` es ON DELETE RESTRICT (y la
 *     migración se deshace y se rehace);
 *   · `plans:restore-deleted` en transacción RAÍZ: REPEATABLE READ, cerrojo
 *     consultivo liberado, en seco no deja nada y confirmado sí;
 *   · si otra sesión toca una fila que el comando va a bloquear, PostgreSQL
 *     aborta con 40001 y el comando lo reintenta.
 *
 *   docs/ultron-studio/ops/pgunit.sh con UNIT_DB=ultron_studio_planborrado
 *
 * La base se VACÍA en cada prueba: nunca contra una base con datos.
 */
#[Group('pgsql')]
class DeletedPlanPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use DeletedPlanFixtures;
    use MembershipTransferFixtures;

    private const MIGRATION = 'database/migrations/2026_10_31_100001_restrict_plan_delete_on_payments.php';

    private ?Connection $otra = null;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? null)) !== 'pgsql') {
            $this->markTestSkipped('Solo en PostgreSQL: la llave RESTRICT, REPEATABLE READ y el 40001 no existen en SQLite.');
        }

        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->planesDelCaso();
        $this->prepararTraspasos();
    }

    protected function tearDown(): void
    {
        if ($this->otra !== null) {
            if ($this->otra->transactionLevel() > 0) {
                $this->otra->rollBack();
            }
            $this->otra->disconnect();
        }
        if ($this->app !== null) {
            $this->truncateDatabaseTables();
            RefreshDatabaseState::$migrated = false;
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── La llave ───────────────────────────────────────────────────────────

    public function test_la_llave_de_payments_a_plans_es_restrict_y_validada(): void
    {
        $this->assertSame(['r', true], $this->llave());
    }

    public function test_postgres_no_deja_borrar_un_plan_con_cobros_pero_si_uno_sin_ellos(): void
    {
        $this->cobrar($this->socio('Con Cobro'), $this->totalAccess);

        try {
            DB::table('plans')->where('id', 22)->delete();
            $this->fail('Se borró un plan con cobros.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0]);
        }
        $this->assertNotNull(Plan::find(22));

        $suelto = Plan::create(['name' => 'Sin cobros', 'price' => 1000, 'duration_days' => 30, 'active' => true]);
        DB::table('plans')->where('id', $suelto->id)->delete();
        $this->assertNull(Plan::find($suelto->id));
    }

    public function test_la_migracion_se_deshace_y_se_rehace(): void
    {
        $migracion = require base_path(self::MIGRATION);

        $migracion->down();
        $this->assertSame(['n', true], $this->llave());
        $migracion->up();
        $this->assertSame(['r', true], $this->llave());
        // Ya está como se pide: no hace nada (y no falla).
        $migracion->up();
        $this->assertSame(['r', true], $this->llave());
    }

    // ── El comando en transacción raíz ─────────────────────────────────────

    public function test_en_seco_y_confirmado_con_commits_reales(): void
    {
        $yurany = $this->socio('Yurany Pabón');
        $cobro = $this->cobroAPlazos($yurany);
        $this->trasladoDePlan([$yurany->id]);
        $this->borrarPlanComoEl16DeOctubre($this->totalAccess);
        $this->reloj('2026-10-28 10:00');
        $trazas = AuditLog::count();

        $sql = [];
        DB::listen(function (QueryExecuted $q) use (&$sql): void {
            $sql[] = $q->sql;
        });
        [$codigo, $salida] = $this->restaurarPlan();
        $this->assertSame(0, $codigo, $salida);
        $this->assertNull(Plan::find(22), 'En seco quedó el plan.');
        $this->assertNull($cobro->fresh()->plan_id);
        $this->assertSame($trazas, AuditLog::count());
        $this->assertContains('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $sql);
        $this->assertSame(0, $this->cerrojosConsultivos(), 'El cerrojo consultivo quedó tomado.');

        [$codigo, $salida] = $this->restaurarPlan(['--commit' => true]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertFalse(Plan::findOrFail(22)->active);
        $this->assertSame(22, (int) $cobro->fresh()->plan_id);
        $this->assertSame($trazas + 2, AuditLog::count());
        $this->assertSame(0, $this->cerrojosConsultivos());

        // La llave RESTRICT ya protege al plan restaurado.
        $this->deleteJson('/api/plans/22', [], $this->h())->assertStatus(409)->assertJsonPath('code', 'plan_has_history')
            ->assertJsonPath('can_archive', true);
    }

    public function test_si_otra_sesion_toca_una_fila_bloqueable_postgres_aborta_y_se_reintenta(): void
    {
        $yurany = $this->socio('Yurany Pabón');
        $cobro = $this->cobroAPlazos($yurany);
        $this->borrarPlanComoEl16DeOctubre($this->totalAccess);
        $this->reloj('2026-10-28 10:00');

        // Justo después de la primera lectura (la foto de REPEATABLE READ ya
        // está tomada), otra sesión cambia a la socia y confirma.
        $otra = $this->otraConexion();
        $una = false;
        DB::listen(function (QueryExecuted $q) use (&$una, $otra, $yurany): void {
            if (! $una && str_starts_with($q->sql, 'select * from "plans" where "id" = ?') && $q->connectionName === DB::getDefaultConnection()) {
                $una = true;
                $otra->table('users')->where('id', $yurany->id)->update(['updated_at' => now()->addSecond()]);
            }
        });

        [$codigo, $salida] = $this->restaurarPlan(['--commit' => true, '--json' => true]);

        $this->assertTrue($una, 'El gancho no se disparó.');
        $this->assertSame(0, $codigo, $salida);
        $r = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $r['attempts'], 'Tenía que abortar con 40001 y reintentar.');
        $this->assertSame(22, (int) $cobro->fresh()->plan_id);
        $this->assertSame(1, AuditLog::where('entity', 'plan')->where('action', 'create')->where('entity_id', '22')->count(),
            'El primer intento no dejó nada: una sola traza del plan.');
        $this->assertSame(0, $this->cerrojosConsultivos());
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: bool} confdeltype y convalidated */
    private function llave(): array
    {
        $fila = DB::selectOne("SELECT confdeltype, convalidated FROM pg_constraint WHERE conname = 'payments_plan_id_foreign'");

        return [(string) $fila->confdeltype, (bool) $fila->convalidated];
    }

    private function cerrojosConsultivos(): int
    {
        return (int) DB::selectOne("SELECT COUNT(*) AS n FROM pg_locks WHERE locktype = 'advisory'")->n;
    }

    private function otraConexion(): Connection
    {
        config(['database.connections.pgsql_otra' => config('database.connections.pgsql')]);

        return $this->otra = DB::connection('pgsql_otra');
    }
}
