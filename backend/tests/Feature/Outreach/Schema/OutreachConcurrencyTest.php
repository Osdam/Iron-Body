<?php

namespace Tests\Feature\Outreach\Schema;

use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PgSql\Connection as PgConnection;
use PgSql\Result;
use Tests\TestCase;

/**
 * CARRERAS REALES EN POSTGRESQL (Hito 9, DB9): dos conexiones de verdad, la
 * segunda asíncrona (`pg_send_query_params`), y la espera comprobada con
 * `pg_blocking_pids` antes de confirmar la primera. Lo que se demuestra es la
 * BASE: las garantías no dependen de que el servicio tome un cerrojo antes.
 *
 *  · prueba 13 y 14: dos trabajadores inscriben la misma ocurrencia → una;
 *  · prueba 5: dos estrategias planifican a la misma persona → un trabajo vivo;
 *  · prueba 16: dos reservas que juntas pasan del techo → una; con techo N,
 *    exactamente N; sin la comprobación en el UPDATE, el CHECK;
 *  · D9-8, etapa 6: dos despachadores con `FOR UPDATE SKIP LOCKED` no reclaman
 *    el mismo trabajo;
 *  · 11.3: dos publicaciones a la vez → el UNIQUE de seq (el 409 del servicio);
 *  · T14: el mismo resultado de la misma fuente dos veces → uno;
 *  · D9-14: soltar el mismo freno a la vez → una sola vez.
 *
 * Los datos de partida se CONFIRMAN por una tercera conexión (la transacción
 * de `RefreshDatabase` no los vería) y se borran al final, solo esos, con los
 * disparadores apagados por `session_replication_role` (base desechable).
 */
class OutreachConcurrencyTest extends TestCase
{
    use OutreachSchemaFixtures;
    use RefreshDatabase;

    private const CONNECTION = 'h9_carreras';

    /** @var list<PgConnection> */
    private array $pg = [];

    private int $identidadesAntes = 0;

    private bool $sembrado = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Solo PostgreSQL: las carreras necesitan dos conexiones reales.');
        }
        if (! function_exists('pg_send_query_params')) {
            $this->markTestSkipped('Sin la extensión pgsql de PHP no hay consultas asíncronas.');
        }
        config(['database.connections.'.self::CONNECTION => config('database.connections.'.config('database.default'))]);
        $this->fixtureConnection = self::CONNECTION;
        $c = DB::connection(self::CONNECTION);
        foreach ([...self::EXECUTION_TABLES, ...self::GOVERNANCE_TABLES] as $tabla) {
            $this->assertSame(0, $c->table($tabla)->count(), "{$tabla} tiene filas confirmadas de otra prueba: no se borran filas ajenas");
        }
        $this->identidadesAntes = (int) $c->table('identities')->max('id');
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
                // Solo las filas de esta prueba: setUp() comprobó que las tablas estaban vacías (los cursores no tienen id).
                foreach ([...array_reverse(self::EXECUTION_TABLES), ...array_reverse(self::GOVERNANCE_TABLES)] as $tabla) {
                    if ($tabla !== self::T_CURSORS) {
                        $c->table($tabla)->where('id', '>', 0)->delete();
                    }
                }
                $c->table(self::T_CURSORS)->where('source', 'like', 'h9-carrera%')->delete();
                $c->table('identities')->where('id', '>', $this->identidadesAntes)->delete();
            } finally {
                $c->statement('SET session_replication_role = DEFAULT');
            }
            DB::purge(self::CONNECTION);
        }
        parent::tearDown();
    }

    public function test_dos_trabajadores_inscriben_la_misma_ocurrencia_y_solo_entra_una(): void
    {
        $live = $this->liveVersion();
        $persona = static fn (int $n): array => ['subject_key' => "m:{$n}", 'member_id' => $n, 'subject_ref' => hash('sha256', "h9-db-m:{$n}")];
        [$a, $b] = [$this->connect(), $this->connect()];

        // La primera confirma: la segunda, que esperaba, choca con el UNIQUE de la ocurrencia.
        $fila = $this->enrollmentRow($live, $persona(501));
        $this->exec($a, 'BEGIN');
        $this->assertSame(1, $this->exec($a, ...$this->insertSql(self::T_ENROLLMENTS, $fila)));
        $this->exec($b, 'BEGIN');
        $this->send($b, ...$this->insertSql(self::T_ENROLLMENTS, ['uuid' => (string) Str::uuid()] + $fila));
        $this->waitBlocked($b, $a);
        $this->exec($a, 'COMMIT');
        [$estado, , $mensaje] = $this->asyncResult($b);
        $this->assertSame('23505', $estado, $mensaje);
        $this->assertStringContainsString('oenr_occurrence_unique', $mensaje);
        $this->exec($b, 'ROLLBACK');

        // Si la primera se deshace, la segunda entra: lo decide el índice, no un cerrojo que rechace.
        $otra = $this->enrollmentRow($live, $persona(502));
        $this->exec($a, 'BEGIN');
        $this->exec($a, ...$this->insertSql(self::T_ENROLLMENTS, $otra));
        $this->exec($b, 'BEGIN');
        $this->send($b, ...$this->insertSql(self::T_ENROLLMENTS, ['uuid' => (string) Str::uuid()] + $otra));
        $this->waitBlocked($b, $a);
        $this->exec($a, 'ROLLBACK');
        $this->assertSame([null, 1], array_slice($this->asyncResult($b), 0, 2));
        $this->exec($b, 'COMMIT');

        $this->assertSame([1, 1], [
            DB::connection(self::CONNECTION)->table(self::T_ENROLLMENTS)->where('subject_key', 'm:501')->count(),
            DB::connection(self::CONNECTION)->table(self::T_ENROLLMENTS)->where('subject_key', 'm:502')->count(),
        ]);
    }

    public function test_dos_estrategias_planifican_a_la_misma_persona_y_queda_un_solo_trabajo_vivo(): void
    {
        $persona = ['subject_key' => 'm:503', 'member_id' => 503, 'subject_ref' => $this->hex('m:503'), 'mode' => 'live'];
        $uno = $this->insertEnrollment($this->liveVersion(), $persona);
        $dos = $this->insertEnrollment($this->liveVersion(), $persona);
        [$a, $b] = [$this->connect(), $this->connect()];

        $this->exec($a, 'BEGIN');
        $this->exec($a, ...$this->insertSql(self::T_JOBS, $this->jobRow($uno)));
        $this->exec($b, 'BEGIN');
        $this->send($b, ...$this->insertSql(self::T_JOBS, $this->jobRow($dos)));
        $this->waitBlocked($b, $a);
        $this->exec($a, 'COMMIT');
        [$estado, , $mensaje] = $this->asyncResult($b);
        $this->assertSame('23505', $estado, $mensaje);
        $this->assertStringContainsString('ojob_one_live', $mensaje);
        $this->exec($b, 'ROLLBACK');

        $this->assertSame(1, DB::connection(self::CONNECTION)->table(self::T_JOBS)->where('subject_key', 'm:503')->whereIn('status', ['planned', 'claimed', 'dispatching'])->count());
    }

    public function test_dos_reservas_que_juntas_pasan_del_techo_y_con_techo_n_exactamente_n(): void
    {
        $contador = $this->insertBudget(['cap_units' => 5]);
        $reserva = 'UPDATE '.self::T_BUDGETS.' SET reserved_units = reserved_units + $1 WHERE id = $2 AND reserved_units + $1 <= cap_units';
        [$a, $b] = [$this->connect(), $this->connect()];

        // Con la comprobación en el UPDATE: la segunda, tras esperar, vuelve a mirar la fila y no reserva (budget_exhausted).
        $this->exec($a, 'BEGIN');
        $this->assertSame(1, $this->exec($a, $reserva, [3, $contador]));
        $this->exec($b, 'BEGIN');
        $this->send($b, $reserva, [3, $contador]);
        $this->waitBlocked($b, $a);
        $this->exec($a, 'COMMIT');
        $this->assertSame([null, 0], array_slice($this->asyncResult($b), 0, 2));
        $this->exec($b, 'COMMIT');
        $this->assertSame(3, $this->reserved($contador));

        // Sin ella, la base no deja pasar: el CHECK del techo.
        $sinComprobar = 'UPDATE '.self::T_BUDGETS.' SET reserved_units = reserved_units + $1 WHERE id = $2';
        $this->exec($a, 'BEGIN');
        $this->assertSame(1, $this->exec($a, $sinComprobar, [2, $contador]));
        $this->exec($b, 'BEGIN');
        $this->send($b, $sinComprobar, [1, $contador]);
        $this->waitBlocked($b, $a);
        $this->exec($a, 'COMMIT');
        [$estado, , $mensaje] = $this->asyncResult($b);
        $this->assertSame('23514', $estado, $mensaje);
        $this->assertStringContainsString('obud_limits_chk', $mensaje);
        $this->exec($b, 'ROLLBACK');
        $this->assertSame(5, $this->reserved($contador));

        // Con presupuesto para N, exactamente N, por mucho que se alternen las conexiones.
        $otro = $this->insertBudget(['scope' => 'channel:whatsapp_template', 'cap_units' => 4]);
        $hechas = 0;
        for ($i = 0; $i < 7; $i++) {
            $hechas += $this->exec($i % 2 === 0 ? $a : $b, $reserva, [1, $otro]);
        }
        $this->assertSame([4, 4], [$hechas, $this->reserved($otro)]);
    }

    public function test_dos_despachadores_no_reclaman_el_mismo_trabajo(): void
    {
        $live = $this->liveVersion();
        $ids = [];
        foreach ([504, 505, 506] as $n) {
            $ids[] = $this->insertJob($this->insertEnrollment($live, ['subject_key' => "m:{$n}", 'member_id' => $n, 'subject_ref' => $this->hex("m:{$n}")]));
        }
        $reclamar = 'SELECT id FROM '.self::T_JOBS." WHERE status = 'planned' AND id = ANY($1::bigint[]) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED";
        $lista = '{'.implode(',', $ids).'}';
        [$a, $b] = [$this->connect(), $this->connect()];

        $this->exec($a, 'BEGIN');
        $this->exec($b, 'BEGIN');
        $deA = (int) pg_fetch_result($this->query($a, $reclamar, [$lista]), 0, 0);
        $deB = (int) pg_fetch_result($this->query($b, $reclamar, [$lista]), 0, 0);   // no espera: salta la bloqueada
        $this->assertSame([$ids[0], $ids[1]], [$deA, $deB]);
        foreach ([[$a, $deA], [$b, $deB]] as [$conexion, $id]) {
            $this->assertSame(1, $this->exec($conexion, 'UPDATE '.self::T_JOBS." SET status = 'claimed', claimed_at = now() WHERE id = \$1 AND status = 'planned'", [$id]));
            $this->exec($conexion, 'COMMIT');
        }
        $this->assertSame(['claimed', 'claimed', 'planned'], DB::connection(self::CONNECTION)->table(self::T_JOBS)->whereIn('id', $ids)->orderBy('id')->pluck('status')->all());
    }

    public function test_dos_publicaciones_a_la_vez_una_gana_y_la_otra_choca_con_el_unique_de_seq(): void
    {
        [$s, $v] = $this->chainTarget();
        $cabeza = $this->appendPublication(['strategy_id' => $s, 'version_id' => $v]);
        $seq = $this->nextSeq();
        $primera = $this->publicationRow($seq, $cabeza, ['strategy_id' => $s, 'version_id' => $v, 'action' => 'pause', 'version_id' => null]);
        $segunda = $this->publicationRow($seq, $cabeza, ['strategy_id' => $s, 'version_id' => $v, 'action' => 'promote']);
        [$a, $b] = [$this->connect(), $this->connect()];

        $this->exec($a, 'BEGIN');
        $this->exec($a, ...$this->insertSql(self::T_PUBLICATIONS, $primera));
        $this->exec($b, 'BEGIN');
        // Su disparador de cadena ve la cabeza confirmada (la de antes) y pasa; el índice de seq espera a la primera.
        $this->send($b, ...$this->insertSql(self::T_PUBLICATIONS, $segunda));
        $this->waitBlocked($b, $a);
        $this->exec($a, 'COMMIT');
        [$estado, , $mensaje] = $this->asyncResult($b);
        $this->assertSame('23505', $estado, $mensaje);
        $this->assertStringContainsString('usspub_seq_unique', $mensaje);
        $this->exec($b, 'ROLLBACK');
        $this->assertSame([$seq, 'pause'], [(int) DB::connection(self::CONNECTION)->table(self::T_PUBLICATIONS)->max('seq'),
            DB::connection(self::CONNECTION)->table(self::T_PUBLICATIONS)->orderByDesc('seq')->value('action')]);
    }

    public function test_el_mismo_resultado_de_la_misma_fuente_a_la_vez_queda_una_vez(): void
    {
        $e = $this->insertEnrollment($this->liveVersion());
        $fila = $this->outcomeRow($e, ['source' => 'system', 'outcome' => 'no_response', 'source_key' => 'ventana:vencida']);
        [$sql, $parametros] = $this->insertSql(self::T_OUTCOMES, $fila);
        [$a, $b] = [$this->connect(), $this->connect()];

        $this->exec($a, 'BEGIN');
        $this->exec($a, $sql, $parametros);
        $this->exec($b, 'BEGIN');
        // Como `insertOrIgnore`: ON CONFLICT DO NOTHING (solo pide INSERT) espera a la primera y no inserta.
        [$sqlB, $parametrosB] = $this->insertSql(self::T_OUTCOMES, ['uuid' => (string) Str::uuid()] + $fila);
        $this->send($b, $sqlB.' ON CONFLICT (enrollment_id, source, source_key) DO NOTHING', $parametrosB);
        $this->waitBlocked($b, $a);
        $this->exec($a, 'COMMIT');
        $this->assertSame([null, 0], array_slice($this->asyncResult($b), 0, 2));
        $this->exec($b, 'COMMIT');
        $this->assertSame(1, DB::connection(self::CONNECTION)->table(self::T_OUTCOMES)->where('enrollment_id', $e)->count());
    }

    public function test_soltar_el_mismo_freno_a_la_vez_lo_suelta_una_vez(): void
    {
        $freno = $this->insertBrake();   // la PARADA GLOBAL de Ana
        $p = $this->people();
        $soltar = 'UPDATE '.self::T_BRAKES.' SET released_at = opened_at + interval \'1 hour\', released_by_admin_id = $1, released_by_name = $2, released_by_person_id = $3, release_note = $4 '
            .'WHERE id = $5 AND released_at IS NULL';
        [$a, $b] = [$this->connect(), $this->connect()];

        $this->exec($a, 'BEGIN');
        $this->assertSame(1, $this->exec($a, $soltar, [2, 'Beto', $p[2], 'Revisado', $freno]));
        $this->exec($b, 'BEGIN');
        $this->send($b, $soltar, [3, 'Caro', $p[3], 'También revisado', $freno]);
        $this->waitBlocked($b, $a);
        $this->exec($a, 'COMMIT');
        // El UPDATE condicionado vuelve a mirar la fila: ya está suelto y no toca nada.
        $this->assertSame([null, 0], array_slice($this->asyncResult($b), 0, 2));
        $this->exec($b, 'COMMIT');

        // Y sin la condición, la base lo impide: un freno se suelta una vez.
        $sinCondicion = str_replace(' AND released_at IS NULL', '', $soltar);
        $this->exec($b, 'BEGIN');
        $r = @pg_query_params($b, $sinCondicion, [3, 'Caro', $p[3], 'Otra vez', $freno]);
        $this->assertFalse($r);
        $this->assertStringContainsString('outreach: un freno se suelta una sola vez (UPDATE en outreach_brakes)', (string) pg_last_error($b));
        $this->exec($b, 'ROLLBACK');
        $this->assertSame(2, (int) DB::connection(self::CONNECTION)->table(self::T_BRAKES)->where('id', $freno)->value('released_by_admin_id'));
    }

    // ── Conexiones ──────────────────────────────────────────────────────────

    private function connect(): PgConnection
    {
        $cfg = (array) config('database.connections.'.self::CONNECTION);
        $partes = ['host='.$cfg['host'], 'port='.$cfg['port'], 'dbname='.$cfg['database'], 'user='.$cfg['username'], 'gssencmode=disable', 'application_name=h9_carreras'];
        if ((string) ($cfg['password'] ?? '') !== '') {
            $partes[] = "password='".addcslashes((string) $cfg['password'], "'\\")."'";
        }
        $conexion = pg_connect(implode(' ', $partes), PGSQL_CONNECT_FORCE_NEW);
        $this->assertInstanceOf(PgConnection::class, $conexion, 'no se pudo abrir una conexión de la carrera');
        $this->pg[] = $conexion;

        return $conexion;
    }

    /** @param  array<string, mixed>  $fila  @return array{0: string, 1: list<mixed>} */
    private function insertSql(string $tabla, array $fila): array
    {
        $columnas = array_keys($fila);
        $valores = array_map(static fn (mixed $v): mixed => match (true) {
            is_bool($v) => $v ? 't' : 'f',
            $v instanceof DateTimeInterface => $v->format('Y-m-d H:i:s'),
            default => $v,
        }, array_values($fila));
        $marcas = implode(', ', array_map(static fn (int $i): string => '$'.($i + 1), array_keys($columnas)));

        return ["INSERT INTO {$tabla} (".implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', $columnas)).") VALUES ({$marcas})", $valores];
    }

    /** Síncrona; falla la prueba si la base rechaza. Devuelve las filas afectadas. @param  list<mixed>  $parametros */
    private function exec(PgConnection $conexion, string $sql, array $parametros = []): int
    {
        return pg_affected_rows($this->query($conexion, $sql, $parametros));
    }

    /** @param  list<mixed>  $parametros */
    private function query(PgConnection $conexion, string $sql, array $parametros = []): Result
    {
        $r = @pg_query_params($conexion, $sql, $parametros);
        if ($r === false) {
            $this->fail('La base rechazó lo que tenía que aceptar: '.pg_last_error($conexion));
        }

        return $r;
    }

    /** @param  list<mixed>  $parametros */
    private function send(PgConnection $conexion, string $sql, array $parametros = []): void
    {
        $this->assertTrue(pg_send_query_params($conexion, $sql, $parametros));
    }

    /** Hasta que `$espera` esté bloqueada por `$tiene` (pg_blocking_pids), como mucho 5 s. */
    private function waitBlocked(PgConnection $espera, PgConnection $tiene): void
    {
        $observador = DB::connection(self::CONNECTION);
        for ($i = 0; $i < 200; $i++) {
            if ((bool) $observador->scalar('SELECT ?::int = ANY(pg_blocking_pids(?::int))', [pg_get_pid($tiene), pg_get_pid($espera)])) {
                $this->addToAssertionCount(1);

                return;
            }
            usleep(25000);
        }
        $this->fail('La segunda conexión no llegó a esperar a la primera: la carrera no se produjo.');
    }

    /** El resultado de la consulta asíncrona. @return array{0: ?string, 1: int, 2: string} [SQLSTATE o null, filas afectadas, mensaje] */
    private function asyncResult(PgConnection $conexion): array
    {
        $r = pg_get_result($conexion);
        while (pg_get_result($conexion) !== false) {
            // vaciar
        }
        $estado = pg_result_error_field($r, PGSQL_DIAG_SQLSTATE);

        return [$estado === false || $estado === null ? null : (string) $estado, $estado ? 0 : pg_affected_rows($r), (string) pg_result_error($r)];
    }

    private function reserved(int $contador): int
    {
        return (int) DB::connection(self::CONNECTION)->table(self::T_BUDGETS)->where('id', $contador)->value('reserved_units');
    }
}
