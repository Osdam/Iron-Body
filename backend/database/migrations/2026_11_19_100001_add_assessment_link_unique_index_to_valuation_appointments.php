<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de valoraciones (CONTRATO_CICLO_VALORACIONES.md §1.5): índice ÚNICO
 * PARCIAL `valuation_appointments_assessment_uq` sobre
 * `valuation_appointments (professional_assessment_id)` con
 * `professional_assessment_id IS NOT NULL`. Una valoración profesional nunca
 * cierra dos citas.
 *
 * La columna existe desde 2026_10_17_200001 («referencia futura… v1 no la
 * rellena»), sin FK y sin índice. Desde este ciclo la escribe el envío de la
 * valoración (`ValuationAgenda::completeFromAssessment`) y el comando
 * `valuations:link-assessments`. El servicio ya comprueba que la valoración no
 * esté enlazada; el índice es la segunda puerta, la que no depende del código.
 * Sin FK, a juego con `professional_assessments`, que no las usa (auditoría
 * 18-11 §12.2).
 *
 * EN LOS DOS MOTORES Y CON EL MISMO TEXTO, como `vc_one_call_per_strategy_job`
 * (2026_11_14_100002): SQLite lo guarda tal cual en `sqlite_master` y la suite
 * lo ve.
 *
 * Antes de crearlo se niega si ya existe (el esquema no es el que espera) o si
 * hay una valoración enlazada a más de una cita: lo dice con su recuento y NO
 * borra nada. En producción la columna está vacía (0 de 49 filas, Q02).
 *
 * CERROJOS (PostgreSQL). `CREATE UNIQUE INDEX` toma SHARE sobre
 * `valuation_appointments` mientras se construye (49 filas: milisegundos) y
 * `DROP INDEX`, ACCESS EXCLUSIVE. No CONCURRENTLY: no cabe en la transacción del
 * migrador y aquí no hace falta. Si la sesión no trae `lock_timeout` (el de
 * `ironbody-migrate` es de 5 s), pone `SET LOCAL lock_timeout = '5s'`: nunca
 * espera sin límite; con otra transacción sobre la tabla, cae limpia (55P03) y
 * se reintenta.
 *
 * down(): quita el índice (quitar un índice no borra filas). Reversible.
 */
return new class extends Migration
{
    public const TABLE = 'valuation_appointments';

    public const INDEX = 'valuation_appointments_assessment_uq';

    /** El predicado, el mismo texto en los dos motores. */
    public const PREDICATE = 'professional_assessment_id IS NOT NULL';

    public const LOCK_TIMEOUT = '5s';

    public function up(): void
    {
        $driver = self::driver();
        DB::transaction(function () use ($driver): void {
            $this->boundLockWait($driver);
            if (! DB::connection()->pretending()) {
                if ($this->indexExists($driver)) {
                    throw new RuntimeException('valoraciones: '.self::INDEX.' ya existe en '.self::TABLE.': el esquema no es el que esta migración espera.');
                }
                $repetidas = DB::table(self::TABLE)
                    ->whereNotNull('professional_assessment_id')
                    ->groupBy('professional_assessment_id')
                    ->havingRaw('count(*) > 1')
                    ->select('professional_assessment_id')
                    ->get()
                    ->count();
                if ($repetidas > 0) {
                    throw new RuntimeException("valoraciones: {$repetidas} valoración(es) enlazada(s) a más de una cita en ".self::TABLE
                        .': el índice no se puede crear y las filas no se tocan. Hay que mirarlas a mano.');
                }
            }

            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON '.self::TABLE.' (professional_assessment_id) WHERE '.self::PREDICATE);

            if (! DB::connection()->pretending() && ! $this->indexExists($driver)) {
                throw new RuntimeException('valoraciones: '.self::INDEX.' no aparece tras crearlo. La migración no se registra.');
            }
        });
    }

    public function down(): void
    {
        $driver = self::driver();
        DB::transaction(function () use ($driver): void {
            $this->boundLockWait($driver);
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        });
    }

    private static function driver(): string
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("valoraciones: driver de base de datos no soportado: {$driver}. Solo pgsql y sqlite.");
        }

        return $driver;
    }

    private function indexExists(string $driver): bool
    {
        return $driver === 'pgsql'
            ? (int) DB::scalar('SELECT count(*) FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid JOIN pg_class t ON t.oid = i.indrelid WHERE c.relname = ? AND t.relname = ?', [self::INDEX, self::TABLE]) > 0
            : DB::table('sqlite_master')->where('type', 'index')->where('name', self::INDEX)->exists();
    }

    /**
     * Solo PostgreSQL: sin un límite en la sesión, esta migración no espera más
     * de LOCK_TIMEOUT por un cerrojo. Si la sesión ya trae uno (el de
     * `ironbody-migrate`), manda ese. SET LOCAL: vale hasta el final de la
     * transacción.
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
