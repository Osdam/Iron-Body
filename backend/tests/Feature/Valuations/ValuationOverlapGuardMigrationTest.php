<?php

namespace Tests\Feature\Valuations;

use App\Models\ValuationAppointment;
use App\Models\ValuationReminder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PDOException;
use Tests\TestCase;

/**
 * La migración 2026_10_17_200002 (red de seguridad, solo PostgreSQL).
 *
 * Revisión de BD I3: solo puede tolerar que btree_gist NO SE PUEDA INSTALAR
 * (sin permiso o no disponible), dejando un aviso; cualquier otro error (un
 * `lock_timeout`, p. ej.) tiene que propagarse para que la migración no quede
 * registrada como hecha sin su EXCLUDE. Y M3: CHECK del dominio de los estados,
 * canales y tipos de aviso.
 *
 * Con el rol de producción (NO superusuario, dueño de la base) en PostgreSQL;
 * en SQLite la migración no hace nada y así se comprueba.
 */
class ValuationOverlapGuardMigrationTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    private function migracion(): object
    {
        return require database_path('migrations/2026_10_17_200002_add_valuation_overlap_guard.php');
    }

    private static function errorPg(string $sqlstate): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$sqlstate}]: simulado");
        $pdo->errorInfo = [$sqlstate, 7, 'simulado'];

        return new QueryException('pgsql', 'CREATE EXTENSION IF NOT EXISTS btree_gist', [], $pdo);
    }

    private function pg(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    public function test_solo_se_tolera_que_la_extension_no_se_pueda_instalar(): void
    {
        $m = $this->migracion();

        foreach (['42501', '58P01', '0A000'] as $tolerado) {
            $this->assertTrue($m::isMissingExtension(self::errorPg($tolerado)), $tolerado);
        }
        // lock_timeout, statement_timeout, interbloqueo, objeto inexistente,
        // serialización, conexión caída, duplicado…: la migración falla.
        foreach (['55P03', '57014', '40P01', '42704', '40001', '08006', '42710', '23505', '22P02'] as $otro) {
            $this->assertFalse($m::isMissingExtension(self::errorPg($otro)), $otro);
        }
    }

    public function test_extension_no_disponible_avisa_y_sigue_y_otro_error_se_propaga(): void
    {
        $m = $this->migracion();

        if (! $this->pg()) {
            // SQLite: la migración no toca nada.
            $antes = DB::select('SELECT name, sql FROM sqlite_master ORDER BY name');
            $m->up();
            $m->down();
            $this->assertEquals($antes, DB::select('SELECT name, sql FROM sqlite_master ORDER BY name'));

            return;
        }

        Log::spy();

        // 58P01 de verdad: la extensión no existe en el servidor.
        $this->assertFalse($m->installExtension('btree_gist_no_existe'));
        Log::shouldHaveReceived('warning')->once()->with('valuations.overlap_guard.btree_gist_unavailable', Mockery::on(
            fn (array $c) => $c['sqlstate'] === '58P01' && str_contains($c['effect'], 'SIN la restricción EXCLUDE')
        ));
        // El savepoint dejó la transacción usable.
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS uno')->uno);

        // Ya instalada (por el rol dueño, al migrar): sin aviso.
        $this->assertTrue($m->installExtension('btree_gist'));

        // Un error que NO es «no se puede instalar» (42704: falta la extensión
        // de la que depende) se propaga, aunque venga de CREATE EXTENSION.
        try {
            $m->installExtension('earthdistance');
            $this->fail('tenía que propagarse');
        } catch (QueryException $e) {
            $this->assertSame('42704', $e->errorInfo[0]);
        }
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_el_rol_dueno_instala_btree_gist_y_crea_todas_las_restricciones(): void
    {
        if (! $this->pg()) {
            $this->assertSame([], DB::select("SELECT name FROM sqlite_master WHERE sql LIKE '%valuation_appointments_no_overlap%'"));

            return;
        }

        $rol = DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user');
        $this->assertFalse((bool) $rol->rolsuper, 'la suite de PostgreSQL corre con un rol NO superusuario, como producción');

        $restricciones = collect(DB::select(
            "SELECT conrelid::regclass::text AS tabla, conname, contype FROM pg_constraint
              WHERE conrelid IN ('valuation_appointments'::regclass, 'valuation_reminders'::regclass) AND contype IN ('c', 'x')"
        ))->mapWithKeys(fn ($r) => [$r->conname => $r->contype])->all();
        ksort($restricciones);

        $this->assertSame([
            'valuation_appointments_ends_after_starts' => 'c',
            'valuation_appointments_no_overlap' => 'x',
            'valuation_appointments_status_chk' => 'c',
            'valuation_reminders_channel_chk' => 'c',
            'valuation_reminders_kind_chk' => 'c',
            'valuation_reminders_status_chk' => 'c',
        ], $restricciones);
    }

    /** M3: la base rechaza un valor fuera del dominio aunque se salte el código. */
    public function test_postgresql_rechaza_estados_canales_y_tipos_fuera_del_dominio(): void
    {
        $m = $this->migracion();

        // Las listas de la migración (autocontenida) son las de los modelos.
        $this->assertEqualsCanonicalizing(ValuationAppointment::STATUSES, $m::DOMAIN_CHECKS['valuation_appointments']['valuation_appointments_status_chk'][1]);
        $constantes = (new \ReflectionClass(ValuationReminder::class))->getConstants();
        $de = fn (string $prefijo) => array_values(array_filter($constantes, fn ($v, $k) => str_starts_with($k, $prefijo) && is_string($v), ARRAY_FILTER_USE_BOTH));
        $this->assertEqualsCanonicalizing($de('STATUS_'), $m::DOMAIN_CHECKS['valuation_reminders']['valuation_reminders_status_chk'][1]);
        $this->assertEqualsCanonicalizing($de('CHANNEL_'), $m::DOMAIN_CHECKS['valuation_reminders']['valuation_reminders_channel_chk'][1]);
        $this->assertEqualsCanonicalizing($de('KIND_'), $m::DOMAIN_CHECKS['valuation_reminders']['valuation_reminders_kind_chk'][1]);

        $this->clock('2026-10-19 08:00');
        $cita = ValuationAppointment::create([
            'member_id' => $this->member()->id, 'trainer_id' => $this->trainer()->id, 'type' => 'initial', 'status' => 'scheduled',
            'starts_at' => Carbon::parse('2026-10-24 09:00', 'America/Bogota')->utc(),
            'ends_at' => Carbon::parse('2026-10-24 10:00', 'America/Bogota')->utc(),
            'duration_minutes' => 60, 'source' => 'crm',
        ]);
        $aviso = fn (array $x) => array_merge([
            'appointment_id' => $cita->id, 'kind' => 'reminder_long', 'channel' => 'member_app',
            'appointment_version' => 1, 'due_at' => now(), 'status' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $x);

        $intentos = [
            fn () => DB::table('valuation_appointments')->where('id', $cita->id)->update(['status' => 'SCHEDULED']),
            fn () => DB::table('valuation_reminders')->insert($aviso(['status' => 'sent'])),
            fn () => DB::table('valuation_reminders')->insert($aviso(['channel' => 'sms'])),
            fn () => DB::table('valuation_reminders')->insert($aviso(['kind' => 'otro'])),
        ];
        foreach ($intentos as $i => $intento) {
            try {
                DB::transaction($intento);
                $this->assertFalse($this->pg(), "en PostgreSQL el CHECK tenía que rechazar el intento {$i}");
            } catch (QueryException $e) {
                $this->assertTrue($this->pg());
                $this->assertSame('23514', $e->errorInfo[0], "intento {$i}: violación de CHECK");
            }
        }

        // Lo válido entra.
        DB::table('valuation_reminders')->insert($aviso(['kind' => 'internal_unattended', 'channel' => 'crm']));
        $this->assertSame(1, ValuationReminder::where('kind', 'internal_unattended')->count());
    }
}
