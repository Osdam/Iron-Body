<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HITO 9, paquete DB9: ÍNDICES DE LECTURA para los prefiltros de B9-facts.
 * Contrato: ULTRON_STUDIO_H9_CONTRACT.md, D9-5 («Prefiltros… indexados»),
 * D9-16, L9-29 y L9-31 (y `informe-backend-facts.md` §8.1, que los mide).
 * Informe: plans/informes-h9/informe-db.md.
 *
 * SOLO ÍNDICES, sobre tablas que ya existen y tienen datos: ninguna columna,
 * restricción ni dato cambia, y nada del H9 depende de que existan para ser
 * correcto (solo para ir rápido). Cuatro:
 *
 *  · users (membership_end_date)                — vencimientos por ventana
 *    (`MembershipFacts`) y el «activo hoy» de asistencia, valoraciones y clases.
 *    Sin parcial: la consulta no siempre filtra congelados ni plan.
 *  · payment_transactions (user_id, status)     — la foto por lotes
 *    (`member_id IN (…) OR user_id IN (…)`): con un lado sin índice no hay
 *    BitmapOr. El de `member_id` ya existe (`pt_member_status_seq_idx`).
 *  · class_reservations (member_id, session_date) — el `member_id` solo era la
 *    segunda columna del único `(class_id, member_id, session_date)`.
 *  · receivables (status, due_at)               — cartera vencida por intervalo;
 *    el que hay empieza por `type`, que el prefiltro no filtra.
 *
 * FUERA (Q-8 para el lead): `voice_contacts (source_type, source_id)` de L9-31.
 * La protección 9 del contrato (§3, intocable) dice que las tablas `voice_*` no
 * cambian el 15-11, y la ida y vuelta de DB8 (`down()`/`up()` de la telefonía)
 * se llevaría el índice. Hoy esa tabla está vacía (la fase 4b no ha empezado).
 *
 * POSTGRESQL: `CREATE INDEX CONCURRENTLY` (no bloquea escrituras; pide SHARE
 * UPDATE EXCLUSIVE y espera a las transacciones abiertas), por eso la migración
 * va FUERA de transacción (`$withinTransaction = false`) y cada índice es una
 * sentencia. Es idempotente: si el índice ya existe y es VÁLIDO, no hace nada; si
 * una construcción anterior falló y lo dejó INVÁLIDO (`indisvalid = false`,
 * p. ej., por `lock_timeout`), lo borra (`DROP INDEX CONCURRENTLY`) y lo vuelve
 * a crear, y al final COMPRUEBA que los cuatro son válidos: si no, falla. Sin
 * `lock_timeout` en la sesión pone 5 s mientras dura y deja el valor que había.
 * Si falla a medias, los índices ya creados quedan (son inocuos) y la migración
 * no se registra: se reintenta. OPS9 la ejecuta en hora de poco tráfico.
 *
 * SQLITE: `CREATE INDEX IF NOT EXISTS`, dentro de una transacción.
 *
 * down() borra SOLO estos cuatro (nombres propios con `_h9_`), en PG con
 * `DROP INDEX CONCURRENTLY IF EXISTS`; nunca un índice ajeno.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY no cabe en una transacción. */
    public $withinTransaction = false;

    public const LOCK_TIMEOUT = '5s';

    /** nombre → [tabla, columnas]. */
    public const INDEXES = [
        'users_membership_end_date_h9_idx' => ['users', ['membership_end_date']],
        'payment_transactions_user_status_h9_idx' => ['payment_transactions', ['user_id', 'status']],
        'class_reservations_member_date_h9_idx' => ['class_reservations', ['member_id', 'session_date']],
        'receivables_status_due_at_h9_idx' => ['receivables', ['status', 'due_at']],
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->withBoundedLockWait(function (): void {
                foreach (self::INDEXES as $name => [$table, $columns]) {
                    $this->createConcurrently($name, $table, $columns);
                }
                $this->assertAllValid();
            });

            return;
        }
        if ($driver === 'sqlite') {
            DB::transaction(function (): void {
                foreach (self::INDEXES as $name => [$table, $columns]) {
                    DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} (".implode(', ', $columns).')');
                }
            });

            return;
        }

        throw new RuntimeException("Los índices de lectura del Hito 9 solo existen para pgsql y sqlite: con «{$driver}» la migración no se aplica.");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->withBoundedLockWait(function (): void {
                foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
                    DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
                }
            });

            return;
        }
        if ($driver === 'sqlite') {
            DB::transaction(function (): void {
                foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
                    DB::statement("DROP INDEX IF EXISTS {$name}");
                }
            });

            return;
        }

        throw new RuntimeException("Los índices de lectura del Hito 9 solo existen para pgsql y sqlite: con «{$driver}» la migración no se revierte.");
    }

    /** @param  list<string>  $columns */
    private function createConcurrently(string $name, string $table, array $columns): void
    {
        if (! DB::connection()->pretending()) {
            $estado = DB::selectOne('SELECT i.indisvalid::int AS valido FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid '
                .'JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ?', [$name]);
            if ($estado !== null && (int) $estado->valido === 1) {
                return;
            }
            if ($estado !== null) {
                // Lo dejó una construcción fallida: IF NOT EXISTS se lo saltaría.
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
        }
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} (".implode(', ', $columns).')');
    }

    private function assertAllValid(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }
        $validos = DB::select('SELECT c.relname AS nombre FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid JOIN pg_namespace n ON n.oid = c.relnamespace '
            .'WHERE n.nspname = current_schema() AND i.indisvalid AND i.indisready AND c.relname = ANY(?::text[])', ['{'.implode(',', array_keys(self::INDEXES)).'}']);
        $faltan = array_values(array_diff(array_keys(self::INDEXES), array_map(static fn (object $f): string => $f->nombre, $validos)));
        if ($faltan !== []) {
            throw new RuntimeException('outreach (Hito 9): los índices de lectura ['.implode(', ', $faltan).'] no quedaron válidos. La migración no se registra; se reintenta.');
        }
    }

    /** Sin `lock_timeout` en la sesión, 5 s mientras dura la migración; después, el valor que había (va fuera de transacción: no vale SET LOCAL). */
    private function withBoundedLockWait(Closure $fn): void
    {
        if (DB::connection()->pretending()) {
            $fn();

            return;
        }
        $previo = (string) DB::scalar("SELECT current_setting('lock_timeout')");
        if ($previo === '0') {
            DB::statement("SET lock_timeout = '".self::LOCK_TIMEOUT."'");
        }
        try {
            $fn();
        } finally {
            if ($previo === '0') {
                DB::statement("SET lock_timeout = '0'");
            }
        }
    }
};
