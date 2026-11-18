<?php

namespace Tests\Feature\Valuations\Seguimiento;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberValuationFollowUp;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * LO QUE SQLITE NO PUEDE VER del PUT de seguimiento, contra PostgreSQL de
 * verdad: el `FOR UPDATE` de la fila, la carrera de dos pestañas que crean la
 * fila a la vez (`ON CONFLICT DO NOTHING`, sin 500 ni transacción abortada) y
 * los CHECK de la base.
 *
 * Commits reales —`DatabaseTruncation` y no `RefreshDatabase`—: dentro de la
 * transacción de la prueba, la segunda conexión no vería al socio. Se ejecuta
 * con DB_CONNECTION=pgsql contra una base desechable; la base se VACÍA.
 */
#[Group('pgsql')]
class FollowUpPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use SeguimientoFixtures;

    private ?Connection $otra = null;

    private Member $laura;

    private array $h;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? null)) !== 'pgsql') {
            $this->markTestSkipped('Solo en PostgreSQL: FOR UPDATE, ON CONFLICT y los CHECK no existen en SQLite. '
                .'Ejecutar con DB_CONNECTION=pgsql contra una base desechable.');
        }

        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->clock('2026-11-18 12:00');
        $andres = $this->trainer();
        $this->laura = $this->member('Laura Gómez');
        $this->valoracion($this->laura, $andres, '2026-11-01 10:00');
        $this->h = $this->reception();
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
            DB::statement('SET lock_timeout = 0');
            // Lo confirmado aquí es de verdad: se vacía y se obliga a la
            // siguiente prueba con RefreshDatabase a volver a migrar (y
            // sembrar), como en PlanTransferPostgresTest.
            $this->truncateDatabaseTables();
            RefreshDatabaseState::$migrated = false;
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Una segunda sesión de PostgreSQL: la otra pestaña. */
    private function otraConexion(): Connection
    {
        config(['database.connections.pgsql_otra' => config('database.connections.pgsql')]);

        return $this->otra = DB::connection('pgsql_otra');
    }

    public function test_el_cerrojo_de_la_fila_es_real_y_quien_espera_no_escribe(): void
    {
        $this->cambiar($this->h, $this->laura, ['interval_days' => 30])->assertOk()->assertJsonPath('data.version', 1);

        $otra = $this->otraConexion();
        $otra->beginTransaction();
        $otra->select('select id from member_valuation_follow_ups where member_id = ? for update', [$this->laura->id]);

        DB::statement("SET lock_timeout = '300ms'");
        $inicio = microtime(true);
        $this->cambiar($this->h, $this->laura, ['interval_days' => 90, 'expected_version' => 1])->assertStatus(500);
        $espera = microtime(true) - $inicio;
        DB::statement('SET lock_timeout = 0');

        $this->assertGreaterThanOrEqual(0.25, $espera, 'No esperó al cerrojo: el FOR UPDATE no está.');
        $fila = MemberValuationFollowUp::query()->where('member_id', $this->laura->id)->firstOrFail();
        $this->assertSame([30, 1], [$fila->interval_days, $fila->version]);

        // Liberada la fila, la misma operación entra.
        $otra->rollBack();
        $this->cambiar($this->h, $this->laura, ['interval_days' => 90, 'expected_version' => 1])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.interval.days', 90);
    }

    /**
     * EL `FOR UPDATE` ES LO QUE EVITA EL CAMBIO PERDIDO. Entre que este PUT lee
     * la versión y la escribe, otra sesión intenta escribir la fila: tiene que
     * encontrarla bloqueada (55P03 con su `lock_timeout`). Sin el cerrojo, la
     * otra escribiría la versión 2 y este PUT la pisaría con otra versión 2.
     */
    public function test_entre_leer_la_version_y_escribir_nadie_mas_escribe(): void
    {
        $this->cambiar($this->h, $this->laura, ['interval_days' => 30])->assertOk()->assertJsonPath('data.version', 1);

        $otra = $this->otraConexion();
        $otra->statement("SET lock_timeout = '300ms'");
        $resultado = null;
        DB::connection()->beforeExecuting(function (string $sql) use ($otra, &$resultado): void {
            if ($resultado !== null || ! str_starts_with(strtolower($sql), 'update "member_valuation_follow_ups"')) {
                return;
            }
            try {
                $otra->table('member_valuation_follow_ups')->where('member_id', $this->laura->id)
                    ->update(['interval_days' => 45, 'version' => 2, 'updated_at' => now()]);
                $resultado = 'la otra escribió';
            } catch (QueryException $e) {
                $resultado = (string) $e->getCode();
            }
        });

        $this->cambiar($this->h, $this->laura, ['interval_days' => 90, 'expected_version' => 1])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.interval.days', 90);
        $this->assertSame('55P03', $resultado, 'La fila no estaba bloqueada entre leer la versión y escribir: cambio perdido.');
    }

    /**
     * Las dos pestañas leyeron la versión 0 (sin fila). La otra confirma su PUT
     * justo antes de que esta inserte: el INSERT de esta no choca (ON CONFLICT
     * DO NOTHING), bloquea la fila de la otra, ve la versión 1 y responde 409
     * con el estado actual. Sin 500 y con la conexión sana.
     */
    public function test_carrera_al_crear_la_fila_la_segunda_recibe_409(): void
    {
        $otra = $this->otraConexion();
        $hecho = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($otra, &$hecho): void {
            if ($hecho || ! str_starts_with(strtolower($sql), 'insert') || ! str_contains($sql, 'member_valuation_follow_ups')) {
                return;
            }
            $hecho = true;
            $otra->table('member_valuation_follow_ups')->insert([
                'member_id' => $this->laura->id, 'interval_days' => 90, 'enabled' => true, 'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->cambiar($this->h, $this->laura, ['interval_days' => 30, 'expected_version' => 0])
            ->assertStatus(409)
            ->assertJsonPath('code', 'follow_up_changed')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.interval.days', 90);

        $this->assertTrue($hecho, 'la otra pestaña no llegó a confirmar: la prueba no probó la carrera');
        $this->assertSame(1, MemberValuationFollowUp::query()->where('member_id', $this->laura->id)->count());
        $this->assertSame(90, MemberValuationFollowUp::query()->where('member_id', $this->laura->id)->value('interval_days'));
        $this->assertSame(0, AuditLog::query()->where('entity', 'seguimiento_valoraciones')->count());

        // La conexión sigue viva: con la versión buena, entra.
        $this->cambiar($this->h, $this->laura, ['interval_days' => 30, 'expected_version' => 1])->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_los_check_de_la_base(): void
    {
        foreach ([0, 366] as $malo) {
            try {
                DB::table('member_valuation_follow_ups')->insert(['member_id' => $this->laura->id, 'interval_days' => $malo, 'version' => 0, 'enabled' => true]);
                $this->fail("interval_days = {$malo} entró en la base");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode(), $e->getMessage());
            }
            try {
                DB::table('valuation_settings')->update(['follow_up_interval_days' => $malo]);
                $this->fail("follow_up_interval_days = {$malo} entró en la base");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode(), $e->getMessage());
            }
        }
        DB::table('valuation_settings')->update(['follow_up_interval_days' => null]);
        $this->assertNull(DB::table('valuation_settings')->value('follow_up_interval_days'));

        // Borrar al socio se lleva su seguimiento (CASCADE).
        $this->cambiar($this->h, $this->laura, ['interval_days' => 30])->assertOk();
        DB::table('valuation_appointments')->where('member_id', $this->laura->id)->delete();
        Member::query()->whereKey($this->laura->id)->delete();
        $this->assertSame(0, MemberValuationFollowUp::query()->count());
    }
}
