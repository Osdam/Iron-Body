<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Run\BudgetReservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PgSql\Connection as PgConnection;
use Tests\TestCase;

/**
 * LA CARRERA REAL de la reserva (contrato H9, D9-8 etapa 5, L9-2; §13.1 #16;
 * solo PostgreSQL): cinco conexiones de verdad disparan A LA VEZ la MISMA
 * sentencia que usa `BudgetReservations::reserve()` contra un contador con
 * techo 3, y exactamente 3 reservan; la cuarta y la quinta reciben 0 filas
 * (`budget_exhausted`), sin leer y escribir después. El contador queda
 * exactamente en el techo (el CHECK `obud_limits_chk` está debajo).
 *
 * Los datos de partida se confirman por una conexión aparte (la transacción
 * de `RefreshDatabase` no los vería) y se borran al final con los
 * disparadores apagados (base desechable, nunca producción). Las carreras de
 * la inscripción (#13) y del trabajo vivo (#5) están en
 * `OutreachConcurrencyTest` de DB9 sobre las mismas tablas, y la ejecución las
 * recoge como `already_enrolled` / `JobCollision` (pruebas del planificador).
 */
class OutreachRaceTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION = 'h9_run_carreras';

    private const DAY = '2026-11-17';

    private const SCOPE = 'channel:assisted';

    /** @var list<PgConnection> */
    private array $pg = [];

    private bool $sembrado = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Solo PostgreSQL: la carrera necesita conexiones reales.');
        }
        if (! function_exists('pg_send_query_params')) {
            $this->markTestSkipped('Sin la extensión pgsql de PHP no hay consultas asíncronas.');
        }
        config(['database.connections.'.self::CONNECTION => config('database.connections.'.config('database.default'))]);
        $c = DB::connection(self::CONNECTION);
        $this->assertSame(0, $c->table('outreach_budget_counters')->where('budget_day', self::DAY)->where('scope', self::SCOPE)->count(), 'quedan filas confirmadas de otra prueba');
        $c->table('outreach_budget_counters')->insert([
            'budget_day' => self::DAY, 'scope' => self::SCOPE, 'mode' => 'live', 'cap_units' => 3, 'cap_micros' => 0,
            'reserved_units' => 0, 'reserved_micros' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->sembrado = true;
    }

    protected function tearDown(): void
    {
        foreach ($this->pg as $conexion) {
            @pg_query($conexion, 'ROLLBACK');
            pg_close($conexion);
        }
        $this->pg = [];
        if ($this->sembrado) {
            $c = DB::connection(self::CONNECTION);
            $c->statement('SET session_replication_role = replica');
            try {
                $c->table('outreach_budget_counters')->where('budget_day', self::DAY)->where('scope', self::SCOPE)->delete();
            } finally {
                $c->statement('SET session_replication_role = DEFAULT');
            }
        }
        parent::tearDown();
    }

    public function test_16_con_techo_3_cinco_reservas_simultaneas_reservan_exactamente_3(): void
    {
        $dsn = $this->dsn();
        $sql = self::positional(BudgetReservations::reserveSql());
        $parcela = BudgetReservations::parcel(self::SCOPE, 1, 0, 3, 0);
        $ahora = new \DateTimeImmutable('2026-11-17 15:00:00', new \DateTimeZone('UTC'));
        $bindings = BudgetReservations::reserveBindings(self::DAY, 'live', $parcela, $ahora);

        // Cinco conexiones, cada una en su transacción, todas con la sentencia enviada ANTES de que ninguna confirme.
        for ($i = 0; $i < 5; $i++) {
            $conexion = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
            $this->assertNotFalse($conexion, 'conexión '.$i);
            $this->pg[] = $conexion;
            pg_query($conexion, 'BEGIN');
            $this->assertTrue(pg_send_query_params($conexion, $sql, $bindings), 'envío '.$i);
        }
        // Las cuatro siguientes quedan bloqueadas por la fila de la primera (comprobado con pg_blocking_pids) antes de que nadie confirme.
        for ($i = 1; $i < 5; $i++) {
            $this->waitBlocked($this->pg[$i]);
        }
        // Se confirman una a una: cada COMMIT suelta la fila, la siguiente reevalúa el WHERE con el valor nuevo.
        $afectadas = [];
        foreach ($this->pg as $i => $conexion) {
            $afectadas[] = $this->finish($conexion, $i);
        }
        sort($afectadas);
        $this->assertSame([0, 0, 1, 1, 1], $afectadas, 'exactamente N en paralelo; las demás, 0 filas (sin presupuesto)');
        $fila = DB::connection(self::CONNECTION)->table('outreach_budget_counters')->where('budget_day', self::DAY)->where('scope', self::SCOPE)->first();
        $this->assertSame(3, (int) $fila->reserved_units, 'el contador queda exactamente en el techo');

        // Una más, ya sin carrera: 0 filas (sin presupuesto), sin leer y escribir después. Todo por la conexión de apoyo:
        // la conexión por defecto está dentro de la transacción de la prueba y un UPDATE suyo dejaría la fila bloqueada
        // (y el DELETE del tearDown esperaría para siempre).
        $apoyo = DB::connection(self::CONNECTION);
        $this->assertSame(0, $apoyo->update(BudgetReservations::reserveSql(), $bindings));
        $this->assertSame(3, (int) $apoyo->table('outreach_budget_counters')->where('budget_day', self::DAY)->where('scope', self::SCOPE)->value('reserved_units'));

        // Los dos techos mandan POR SEPARADO. El de la FILA: aunque la configuración diga 10, la fila a 3/3 sigue negando.
        $diez = BudgetReservations::reserveBindings(self::DAY, 'live', BudgetReservations::parcel(self::SCOPE, 1, 0, 10, 0), $ahora);
        $this->assertSame(0, $apoyo->update(BudgetReservations::reserveSql(), $diez), 'el techo de la fila niega aunque la configuración diga más');
        // El de la CONFIGURACIÓN: con la fila elevada a 10, la configuración a 3 sigue negando; a 10, concede exactamente una.
        $apoyo->table('outreach_budget_counters')->where('budget_day', self::DAY)->where('scope', self::SCOPE)->update(['cap_units' => 10]);
        $this->assertSame(0, $apoyo->update(BudgetReservations::reserveSql(), $bindings), 'el techo de la configuración niega aunque la fila diga más');
        $this->assertSame(1, $apoyo->update(BudgetReservations::reserveSql(), $diez));
        $this->assertSame(4, (int) $apoyo->table('outreach_budget_counters')->where('budget_day', self::DAY)->where('scope', self::SCOPE)->value('reserved_units'));
    }

    /** Recoge el resultado de la sentencia asíncrona (y vacía la conexión) y confirma; devuelve las filas afectadas. */
    private function finish(PgConnection $conexion, int $i): int
    {
        $r = pg_get_result($conexion);
        $this->assertNotFalse($r, "resultado de la conexión {$i}");
        $filas = (int) pg_affected_rows($r);
        while (pg_get_result($conexion) !== false) {
            // Nada pendiente debe quedar antes de otra sentencia síncrona.
        }
        $this->assertNotFalse(pg_query($conexion, 'COMMIT'), "COMMIT de la conexión {$i}");

        return $filas;
    }

    /** Espera a que la conexión quede bloqueada por otra (o termine), con `pg_blocking_pids`. */
    private function waitBlocked(PgConnection $conexion): void
    {
        $pid = pg_get_pid($conexion);
        $vigia = DB::connection(self::CONNECTION);
        for ($i = 0; $i < 200; $i++) {
            $fila = $vigia->selectOne('SELECT cardinality(pg_blocking_pids(?)) AS n, state FROM pg_stat_activity WHERE pid = ?', [$pid, $pid]);
            if ($fila !== null && ((int) $fila->n > 0 || $fila->state === 'idle in transaction')) {
                return;
            }
            usleep(20_000);
        }
    }

    private function dsn(): string
    {
        $c = (array) config('database.connections.'.config('database.default'));
        $partes = ['host='.($c['host'] ?? '127.0.0.1'), 'port='.($c['port'] ?? 5432), 'dbname='.$c['database'], 'user='.$c['username']];
        if (! empty($c['password'])) {
            $partes[] = 'password='.$c['password'];
        }

        return implode(' ', $partes);
    }

    /** `?` → `$1`, `$2`… para `pg_send_query_params`. */
    private static function positional(string $sql): string
    {
        $n = 0;

        return (string) preg_replace_callback('/\?/', static function () use (&$n): string {
            $n++;

            return '$'.$n;
        }, $sql);
    }
}
