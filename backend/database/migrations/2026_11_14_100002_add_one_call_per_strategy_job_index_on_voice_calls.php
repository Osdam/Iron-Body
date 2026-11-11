<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SC-13 del cierre (D-V9-2, «una llamada por trabajo»;
 * `plans/informes-h9/informe-sec-cierre.md`): índice ÚNICO PARCIAL
 * `vc_one_call_per_strategy_job` sobre `voice_calls (client_request_id)` con
 * `requested_by_type = 'strategy'`: el respaldo EN EL ESQUEMA de la
 * idempotencia por trabajo del H9 (V9, Enmienda 17 del H8).
 *
 * Hasta ahora descansaba solo en el servicio: `OutboundGate::requestForStrategy()`
 * busca la fila del trabajo bajo el cerrojo consultivo de la puerta
 * (`pg_advisory_xact_lock`) antes de insertar, y el UNIQUE de DB8
 * (`vc_client_request_unique`, por persona) no ve dos filas sin cuenta (dos
 * NULL no chocan). Con el índice, una segunda fila del mismo trabajo es
 * imposible aunque algo se salte el cerrojo; la puerta ya convierte la
 * violación de un UNIQUE en 409 (`outbound_race`) sin crear nada. Las filas de
 * una estrategia son siempre un primer intento (`vc_requester_chk`, `attempt = 1`):
 * un reintento de negocio del H9 es OTRO trabajo con OTRO uuid.
 *
 * EN LOS DOS MOTORES Y CON EL MISMO TEXTO, como los parciales de DB8
 * (`PARTIAL_UNIQUE_INDEXES` de `2026_11_10_100002`): SQLite lo guarda tal cual en
 * `sqlite_master`, y la suite lo ve.
 *
 * Antes de crearlo se niega si ya existe (el esquema no es el que espera) o si
 * hay más de una llamada de estrategia con el mismo trabajo: lo dice con su
 * recuento y NO borra nada (habría que mirarlas a mano). Con su RECUENTO de
 * índices parciales en `voice_%`: +1 al crearlo, −1 al quitarlo; si no, falla y
 * no se registra.
 *
 * CERROJOS (PostgreSQL). `CREATE UNIQUE INDEX` toma SHARE sobre `voice_calls`
 * (para las escrituras mientras se construye; la tabla está vacía en
 * producción: las salientes siguen cerradas en el código) y `DROP INDEX`,
 * ACCESS EXCLUSIVE. No CONCURRENTLY: no cabe en la transacción del migrador y
 * aquí no hace falta. Si la sesión no trae `lock_timeout` (el de
 * `ironbody-migrate` es de 5 s), pone `SET LOCAL lock_timeout = '5s'`: nunca
 * espera sin límite; con otra transacción sobre `voice_calls`, cae limpia
 * (55P03) y se reintenta.
 *
 * down(): quita el índice (quitar un índice no borra filas). En producción no
 * se ejecuta nunca (`migrate:rollback` está prohibido, protección 11).
 */
return new class extends Migration
{
    public const TABLE = 'voice_calls';

    public const INDEX = 'vc_one_call_per_strategy_job';

    /** El predicado, el mismo texto en los dos motores. */
    public const PREDICATE = "requested_by_type = 'strategy'";

    public const LOCK_TIMEOUT = '5s';

    public function up(): void
    {
        $driver = self::driver();
        DB::transaction(function () use ($driver): void {
            $this->boundLockWait($driver);
            if (! DB::connection()->pretending()) {
                if ($this->indexExists($driver)) {
                    throw new RuntimeException('voice (SC-13): '.self::INDEX.' ya existe en '.self::TABLE.': el esquema no es el que esta migración espera.');
                }
                $trabajos = DB::table(self::TABLE)->where('requested_by_type', 'strategy')->groupBy('client_request_id')->havingRaw('count(*) > 1')->select('client_request_id')->get()->count();
                if ($trabajos > 0) {
                    throw new RuntimeException("voice (SC-13): {$trabajos} trabajo(s) de estrategia con más de una llamada en ".self::TABLE
                        .': el índice no se puede crear y las filas no se borran. Hay que mirarlas a mano.');
                }
            }
            $antes = $this->partialIndexCount($driver);

            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON '.self::TABLE.' (client_request_id) WHERE '.self::PREDICATE);

            $despues = $this->partialIndexCount($driver);
            if ($antes !== null && $despues !== null && $despues !== $antes + 1) {
                throw new RuntimeException('voice (SC-13): el recuento de índices parciales en voice_% no cuadra tras crear '.self::INDEX." ({$antes} → {$despues}). La migración no se registra.");
            }
        });
    }

    public function down(): void
    {
        $driver = self::driver();
        DB::transaction(function () use ($driver): void {
            $this->boundLockWait($driver);
            // Sin el índice no hay nada que quitar (idempotente); con él, el recuento baja exactamente uno.
            $existia = DB::connection()->pretending() || $this->indexExists($driver);
            $antes = $this->partialIndexCount($driver);

            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

            $despues = $this->partialIndexCount($driver);
            if ($antes !== null && $despues !== null && $despues !== $antes - ($existia ? 1 : 0)) {
                throw new RuntimeException('voice (SC-13): el recuento de índices parciales en voice_% no cuadra tras quitar '.self::INDEX." ({$antes} → {$despues}). No se revierte.");
            }
        });
    }

    private static function driver(): string
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("voice (SC-13): driver de base de datos no soportado: {$driver}. Solo pgsql y sqlite.");
        }

        return $driver;
    }

    private function indexExists(string $driver): bool
    {
        return $driver === 'pgsql'
            ? (int) DB::scalar('SELECT count(*) FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid JOIN pg_class t ON t.oid = i.indrelid WHERE c.relname = ? AND t.relname = ?', [self::INDEX, self::TABLE]) > 0
            : DB::table('sqlite_master')->where('type', 'index')->where('name', self::INDEX)->exists();
    }

    /** Los índices parciales de las tablas `voice_*` (null con `--pretend`). */
    private function partialIndexCount(string $driver): ?int
    {
        if (DB::connection()->pretending()) {
            return null;
        }

        return $driver === 'pgsql'
            ? (int) DB::scalar("SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'voice\\_%' AND i.indpred IS NOT NULL")
            : DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', 'like', 'voice_%')->where('sql', 'like', '% WHERE %')->count();
    }

    /**
     * Solo PostgreSQL: sin un límite en la sesión, esta migración no espera más
     * de LOCK_TIMEOUT por un cerrojo (falla limpia, 55P03, y se reintenta). Si
     * la sesión ya trae uno (el de `ironbody-migrate`), manda ese. SET LOCAL:
     * vale hasta el final de la transacción.
     */
    private function boundLockWait(string $driver): void
    {
        if ($driver !== 'pgsql') {
            return;
        }
        $sentencia = "SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'";
        if (DB::connection()->pretending()) {
            DB::statement($sentencia);

            return;
        }
        if ((string) DB::scalar("SELECT current_setting('lock_timeout')") === '0') {
            DB::statement($sentencia);
        }
    }
};
