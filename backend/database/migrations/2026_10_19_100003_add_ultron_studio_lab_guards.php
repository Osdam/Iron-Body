<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GUARDAS DEL LABORATORIO DE ULTRON STUDIO EN LA BASE (Hito 4). Contrato:
 * docs/ultron-studio/ULTRON_STUDIO_H4_CONTRACT.md, §A8. Mismo patrón que las
 * de F (2026_10_18_100002).
 *
 * Es la tercera capa: 1) ninguna ruta borra ni reescribe; 2) los modelos lo
 * impiden; 3) esto, que vale aunque alguien escriba sin pasar por el código
 * (query builder crudo, SQL a mano).
 *
 *  · Escenarios: se editan y se archivan; no se borran ni se truncan.
 *  · Ejecuciones y casos: no se borran ni se truncan. Lo que se congela al
 *    crearlos (RUN_ / CASE_IMMUTABLE_COLUMNS) no cambia nunca. El resto lo
 *    mueve la máquina de estados mientras la fila no sea terminal; una vez
 *    terminal (ejecución completed | cancelled | stopped; caso done | error |
 *    skipped), la fila no admite NINGÚN UPDATE, ni uno que escriba los mismos
 *    valores.
 *  · Veredictos y libro: solo inserción. UPDATE, DELETE y TRUNCATE fallan.
 *  · Solo en PostgreSQL: CHECK de dominio de los enumerados y
 *    `uslr_target_chk` (cada clase de objetivo con su columna, y solo con
 *    ella). SQLite no admite añadir restricciones a una tabla existente.
 *
 * Los disparadores de columnas inmutables van por LISTA DE COLUMNAS (`BEFORE
 * UPDATE OF …`), no por valor (`json` no tiene igualdad en PG): saltan en
 * cuanto una columna inmutable aparece en el SET, aunque el valor no cambie.
 * Eloquent solo manda las columnas sucias. Los de estado terminal miran
 * `OLD.status`, el de ANTES: la transición que deja la fila terminal pasa, y
 * tiene que llevar en ese mismo UPDATE todo lo que se quiera escribir (el
 * resumen, la huella, el coste…), porque el siguiente ya no entra. Un UPDATE
 * condicionado (`WHERE status = 'running'`) que no encuentra la fila no
 * dispara nada: los disparadores de fila solo ven las filas que cambian.
 *
 * FUNCIÓN PROPIA, `ultron_studio_lab_forbid()`. No reutiliza
 * `ultron_studio_forbid()` de F: el laboratorio se puede retirar sin tocar las
 * guardas de F, y al revés. Qué ve quien choca (el mismo texto en los dos
 * motores, empieza por «ultron_studio_lab:»):
 *  · PostgreSQL: SQLSTATE P0001; un CHECK violado es 23514.
 *  · SQLite: `RAISE(ABORT, …)` → SQLSTATE 23000 (código 19, SQLITE_CONSTRAINT).
 *
 * Los disparadores se llaman igual en los dos motores. SQLite admite un solo
 * evento por disparador, así que UPDATE y DELETE van por separado también en
 * PG: 11 de fila en los dos. PG suma 5 de sentencia contra TRUNCATE (16);
 * SQLite no tiene TRUNCATE, y allí `DELETE FROM` sin WHERE pasa por el de
 * DELETE.
 *
 * SIN TOLERAR ERRORES: la protección es un requisito. Si algo no se puede
 * crear (p. ej. el rol no puede crear funciones en `public`), la migración
 * falla y no queda registrada. Con un driver que no sea pgsql ni sqlite
 * también falla: aplicar el esquema sin sus guardas sería hacerlo en silencio.
 * En PG Laravel envuelve la migración en una transacción; en SQLite no la
 * abre, así que la abre esta migración para no dejar nunca la mitad.
 *
 * `CREATE OR REPLACE FUNCTION`: `migrate:fresh` (db:wipe) en PG borra las
 * tablas, y con ellas sus disparadores y CHECK, pero NO las funciones. Con un
 * CREATE a secas, el siguiente `migrate` fallaría. Reemplazar es idempotente
 * y no oculta ningún error.
 *
 * Las listas van escritas aquí y no tomadas de los modelos: esta migración se
 * aplica ANTES de publicar el código. up() comprueba que cada columna de
 * ejecuciones y casos está clasificada, mutable o inmutable, y solo una vez
 * (salvo con `migrate --pretend`, que no crea nada: así sirve de vista previa).
 *
 * Cerrojos (PG): ADD CONSTRAINT y CREATE TRIGGER solo sobre las tablas del
 * laboratorio, que nadie usa hasta publicar el código. No toca F ni el motor.
 *
 * Lo que NO cubre (igual que F):
 *  · El dueño de las tablas puede `ALTER TABLE … DISABLE TRIGGER` o borrar el
 *    disparador. El rol de la aplicación, solo DML, no puede.
 *  · SQLite: `INSERT OR REPLACE` borra la fila en conflicto SIN disparar el
 *    DELETE (con recursive_triggers apagado, el valor por defecto). Laravel no
 *    lo usa: `upsert()` compila a ON CONFLICT DO UPDATE, que dispara el UPDATE.
 *  · Una migración futura que RECONSTRUYA una de estas tablas en SQLite
 *    (Schema::table con FK, change(), dropColumn…) se lleva sus disparadores,
 *    y tiene que volver a crearlos. Una columna NUEVA en ejecuciones o casos
 *    es mutable (mientras la fila no sea terminal) hasta que se añada a su
 *    lista de inmutables y a su disparador.
 *
 * down() quita disparadores, función y CHECK con IF EXISTS.
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'ultron_studio_lab:';

    public const FUNCTION_NAME = 'ultron_studio_lab_forbid';

    /**
     * Lo que una ejecución congela al crearse (§A3 y §A8). Incluye `id`, que
     * el contrato no enumera: nadie cambia una clave primaria.
     */
    public const RUN_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id',
        'target_kind', 'target_version_id', 'target_draft_admin_id', 'target_label', 'target_content_hash', 'target_snapshot',
        'catalog_version', 'mode', 'model', 'prompt_version', 'prices', 'case_count',
        'estimate_expected_micros', 'estimate_max_micros', 'accepted_max_micros', 'budget_day',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    /** Lo que mueven el runner, la cancelación y el cierre, hasta el estado terminal. */
    public const RUN_MUTABLE_COLUMNS = [
        'status', 'stop_reason', 'cost_micros', 'input_tokens', 'output_tokens', 'summary',
        'ai_recommendation', 'results_hash', 'cancel_requested_at', 'cancel_requested_by_name',
        'started_at', 'finished_at', 'updated_at',
    ];

    /** Lo que un caso congela al crearse: su ejecución, su escenario y su reserva. Incluye `id`. */
    public const CASE_IMMUTABLE_COLUMNS = [
        'id', 'run_id', 'position', 'scenario_id', 'scenario_code', 'scenario_revision', 'scenario_snapshot',
        'budget_day', 'reserved_micros', 'created_at',
    ];

    /** Lo que escribe el paso que procesa el caso (o lo salta), hasta el estado terminal. */
    public const CASE_MUTABLE_COLUMNS = [
        'status', 'outcome', 'error_code', 'reply', 'checks', 'judge', 'calls',
        'cost_micros', 'overrun_micros', 'input_tokens', 'output_tokens',
        'started_at', 'finished_at', 'updated_at',
    ];

    public const RUN_TERMINAL_STATUSES = ['completed', 'cancelled', 'stopped'];

    public const CASE_TERMINAL_STATUSES = ['done', 'error', 'skipped'];

    /**
     * Disparadores de fila, en los dos motores: nombre → [tabla, evento, motivo].
     * Evento: DELETE; UPDATE (cualquiera); IMMUTABLE (`UPDATE OF` las columnas
     * inmutables de la tabla); TERMINAL (cualquier UPDATE de una fila cuyo
     * estado ANTERIOR es terminal).
     */
    public const ROW_TRIGGERS = [
        'usls_forbid_delete' => ['ultron_studio_lab_scenarios', 'DELETE', 'los escenarios se archivan, no se borran'],
        'uslr_forbid_immutable_update' => ['ultron_studio_lab_runs', 'IMMUTABLE', 'una ejecución congela al crearse su objetivo, su modo, su modelo, sus precios, su estimación y su autoría'],
        'uslr_forbid_terminal_update' => ['ultron_studio_lab_runs', 'TERMINAL', 'una ejecución terminada (completed, cancelled o stopped) es inmutable'],
        'uslr_forbid_delete' => ['ultron_studio_lab_runs', 'DELETE', 'las ejecuciones no se borran'],
        'uslc_forbid_immutable_update' => ['ultron_studio_lab_run_cases', 'IMMUTABLE', 'un caso congela al crearse su ejecución, su posición, su escenario y su reserva'],
        'uslc_forbid_terminal_update' => ['ultron_studio_lab_run_cases', 'TERMINAL', 'un caso terminado (done, error o skipped) es inmutable'],
        'uslc_forbid_delete' => ['ultron_studio_lab_run_cases', 'DELETE', 'los casos no se borran'],
        'uslv_forbid_update' => ['ultron_studio_lab_verdicts', 'UPDATE', 'los veredictos son de solo inserción'],
        'uslv_forbid_delete' => ['ultron_studio_lab_verdicts', 'DELETE', 'los veredictos son de solo inserción'],
        'usle_forbid_update' => ['ultron_studio_lab_events', 'UPDATE', 'el libro del laboratorio es de solo inserción'],
        'usle_forbid_delete' => ['ultron_studio_lab_events', 'DELETE', 'el libro del laboratorio es de solo inserción'],
    ];

    /** Disparadores de sentencia contra TRUNCATE, solo en PG: nombre → [tabla, motivo]. */
    public const TRUNCATE_TRIGGERS = [
        'usls_forbid_truncate' => ['ultron_studio_lab_scenarios', 'los escenarios se archivan, no se borran'],
        'uslr_forbid_truncate' => ['ultron_studio_lab_runs', 'las ejecuciones no se borran'],
        'uslc_forbid_truncate' => ['ultron_studio_lab_run_cases', 'los casos no se borran'],
        'uslv_forbid_truncate' => ['ultron_studio_lab_verdicts', 'los veredictos son de solo inserción'],
        'usle_forbid_truncate' => ['ultron_studio_lab_events', 'el libro del laboratorio es de solo inserción'],
    ];

    /**
     * CHECK de dominio, solo en PG: tabla → [nombre → [columna, valores]]. En
     * una columna NULL-able (`ai_recommendation`, `outcome`) el NULL pasa: un
     * CHECK solo rechaza lo que da falso.
     */
    public const DOMAIN_CHECKS = [
        'ultron_studio_lab_scenarios' => [
            'usls_source_chk' => ['source', ['catalog', 'studio']],
            'usls_status_chk' => ['status', ['active', 'archived']],
            'usls_category_chk' => ['category', ['saludo', 'precio', 'descuento', 'pago', 'derivacion', 'cortesia', 'hechos', 'estilo', 'consentimiento', 'membresia', 'otro']],
        ],
        'ultron_studio_lab_runs' => [
            'uslr_target_kind_chk' => ['target_kind', ['version', 'draft', 'baseline']],
            'uslr_mode_chk' => ['mode', ['openai', 'fake']],
            'uslr_status_chk' => ['status', ['queued', 'running', 'completed', 'cancelled', 'stopped']],
            'uslr_ai_recommendation_chk' => ['ai_recommendation', ['approve', 'review', 'reject']],
        ],
        'ultron_studio_lab_run_cases' => [
            'uslc_status_chk' => ['status', ['pending', 'running', 'done', 'error', 'skipped']],
            'uslc_outcome_chk' => ['outcome', ['pass', 'review', 'fail']],
        ],
        'ultron_studio_lab_verdicts' => [
            'uslv_decision_chk' => ['decision', ['approved', 'rejected', 'needs_changes']],
        ],
        'ultron_studio_lab_events' => [
            'usle_subject_type_chk' => ['subject_type', ['scenario', 'run', 'system']],
            'usle_actor_type_chk' => ['actor_type', ['admin', 'system']],
        ],
    ];

    /**
     * CHECK entre columnas, solo en PG: tabla → [nombre → expresión]. Una
     * ejecución sobre una versión lleva su versión; una sobre un borrador, el
     * dueño del borrador; una sobre la línea base, ninguno de los dos.
     */
    public const ROW_CHECKS = [
        'ultron_studio_lab_runs' => [
            'uslr_target_chk' => "((target_kind = 'version') = (target_version_id IS NOT NULL)) AND ((target_kind = 'draft') = (target_draft_admin_id IS NOT NULL))",
        ],
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->upPostgres();

            return;
        }
        if ($driver === 'sqlite') {
            DB::transaction(fn () => $this->upSqlite());

            return;
        }

        throw self::unsupported($driver);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->downPostgres();

            return;
        }
        if ($driver === 'sqlite') {
            DB::transaction(fn () => $this->downSqlite());

            return;
        }

        throw self::unsupported($driver);
    }

    /** El texto del error, igual en los dos motores. */
    public static function message(string $reason, string $operation, string $table): string
    {
        return self::MESSAGE_PREFIX.' '.$reason.' ('.$operation.' en '.$table.')';
    }

    private function upPostgres(): void
    {
        $this->assertColumnsClassified();

        foreach (self::DOMAIN_CHECKS as $table => $checks) {
            foreach ($checks as $name => [$column, $values]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IN (".self::literalList($values).'))');
            }
        }
        foreach (self::ROW_CHECKS as $table => $checks) {
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }

        // El mensaje lo arma con el motivo de cada disparador (TG_ARGV[0]), la
        // operación y la tabla: el mismo texto que self::message() en SQLite.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ultron_studio_lab_forbid() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'ultron_studio_lab: % (% en %)',
                    COALESCE(TG_ARGV[0], 'operación no permitida'), TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'P0001';
            END;
            $fn$
            SQL);

        foreach (self::ROW_TRIGGERS as $name => [$table, $event, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($table, $event)." ON {$table} "
                .'FOR EACH ROW '.self::whenClause($table, $event)
                .'EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
        }
        foreach (self::TRUNCATE_TRIGGERS as $name => [$table, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE TRUNCATE ON {$table} "
                .'FOR EACH STATEMENT EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
        }
    }

    private function downPostgres(): void
    {
        foreach ([...self::ROW_TRIGGERS, ...self::TRUNCATE_TRIGGERS] as $name => [$table]) {
            DB::statement("DROP TRIGGER IF EXISTS {$name} ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS '.self::FUNCTION_NAME.'()');

        foreach ([self::ROW_CHECKS, self::DOMAIN_CHECKS] as $group) {
            foreach ($group as $table => $checks) {
                foreach (array_keys($checks) as $name) {
                    DB::statement("ALTER TABLE IF EXISTS {$table} DROP CONSTRAINT IF EXISTS {$name}");
                }
            }
        }
    }

    private function upSqlite(): void
    {
        $this->assertColumnsClassified();

        foreach (self::ROW_TRIGGERS as $name => [$table, $event, $reason]) {
            $operation = $event === 'DELETE' ? 'DELETE' : 'UPDATE';
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($table, $event)." ON {$table} "
                .'FOR EACH ROW '.self::whenClause($table, $event)
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, $operation, $table)).'); END');
        }
    }

    private function downSqlite(): void
    {
        foreach (array_keys(self::ROW_TRIGGERS) as $name) {
            DB::statement("DROP TRIGGER IF EXISTS {$name}");
        }
    }

    /**
     * Cada columna de ejecuciones y casos es mutable o inmutable,
     * explícitamente y una sola vez. Si alguien añade una columna a la tabla
     * sin clasificarla, la lista nombra una que no existe (SQLite no lo
     * comprobaría en `UPDATE OF`) o una columna está en las dos listas, esto
     * falla.
     */
    private function assertColumnsClassified(): void
    {
        // `migrate --pretend` no ejecuta nada, tampoco la lectura del esquema
        // (devuelve vacío), y no crea ningún disparador que comprobar: sin esto
        // la vista previa del despliegue fallaría aquí. En un `migrate` de
        // verdad la comprobación siempre corre.
        if (DB::connection()->pretending()) {
            return;
        }

        foreach ([
            'ultron_studio_lab_runs' => [self::RUN_IMMUTABLE_COLUMNS, self::RUN_MUTABLE_COLUMNS],
            'ultron_studio_lab_run_cases' => [self::CASE_IMMUTABLE_COLUMNS, self::CASE_MUTABLE_COLUMNS],
        ] as $table => [$immutable, $mutable]) {
            $declared = [...$immutable, ...$mutable];
            $actual = Schema::getColumnListing($table);

            $unclassified = array_values(array_diff($actual, $declared));
            $missing = array_values(array_diff($declared, $actual));
            $repeated = array_values(array_unique(array_diff_assoc($declared, array_unique($declared))));

            if ($unclassified !== [] || $missing !== [] || $repeated !== []) {
                throw new RuntimeException("{$table}: columnas sin clasificar [".implode(', ', $unclassified)
                    .'], que no existen ['.implode(', ', $missing).'] o clasificadas dos veces ['.implode(', ', $repeated)
                    .']. Cada columna es mutable o inmutable, explícitamente.');
            }
        }
    }

    private static function eventClause(string $table, string $event): string
    {
        return match ($event) {
            'IMMUTABLE' => 'UPDATE OF '.implode(', ', self::immutableColumns($table)),
            'TERMINAL', 'UPDATE' => 'UPDATE',
            'DELETE' => 'DELETE',
        };
    }

    /** `WHEN (…) ` con su espacio final, o nada. Igual en los dos motores. */
    private static function whenClause(string $table, string $event): string
    {
        return $event === 'TERMINAL'
            ? 'WHEN (OLD.status IN ('.self::literalList(self::terminalStatuses($table)).')) '
            : '';
    }

    /** @return list<string> */
    private static function immutableColumns(string $table): array
    {
        return match ($table) {
            'ultron_studio_lab_runs' => self::RUN_IMMUTABLE_COLUMNS,
            'ultron_studio_lab_run_cases' => self::CASE_IMMUTABLE_COLUMNS,
        };
    }

    /** @return list<string> */
    private static function terminalStatuses(string $table): array
    {
        return match ($table) {
            'ultron_studio_lab_runs' => self::RUN_TERMINAL_STATUSES,
            'ultron_studio_lab_run_cases' => self::CASE_TERMINAL_STATUSES,
        };
    }

    private static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /** @param  list<string>  $values */
    private static function literalList(array $values): string
    {
        return implode(', ', array_map(self::literal(...), $values));
    }

    private static function unsupported(string $driver): RuntimeException
    {
        return new RuntimeException("Las guardas del laboratorio de ULTRON Studio solo existen para pgsql y sqlite, y son obligatorias: con «{$driver}» la migración no se aplica.");
    }
};
