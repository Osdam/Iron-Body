<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GUARDAS DE ULTRON STUDIO EN LA BASE. Contrato:
 * docs/ultron-studio/ULTRON_STUDIO_F_CONTRACT.md, §A1.
 *
 * Es la tercera capa de la inmutabilidad: 1) no hay ruta ni servicio que edite
 * contenido; 2) los modelos lo impiden; 3) esto, que vale aunque alguien
 * escriba sin pasar por el código (query builder crudo, SQL a mano).
 *
 *  · Auditoría y reseñas: solo inserción. UPDATE, DELETE y TRUNCATE fallan.
 *  · Versiones: no se borran ni se truncan, y solo cambian las cinco columnas
 *    que mueve la máquina de estados (VERSION_MUTABLE_COLUMNS). El disparador
 *    de UPDATE va por LISTA DE COLUMNAS (`BEFORE UPDATE OF …`), no por valor
 *    (`json` no tiene igualdad en PG): salta en cuanto una columna inmutable
 *    aparece en el SET, aunque el valor no cambie. Eloquent solo manda las
 *    columnas sucias, así que un save() que cambia el estado pasa.
 *  · Solo en PostgreSQL: CHECK de dominio de todos los enumerados y dos CHECK
 *    de las reglas (una del Studio nunca está activa ni la aplica el motor; una
 *    del código siempre es `enforcement = code`). SQLite no admite añadir
 *    restricciones a una tabla existente.
 *
 * Los disparadores se llaman igual en los dos motores. SQLite admite un solo
 * evento por disparador, así que UPDATE y DELETE van por separado también en
 * PG. PG suma uno de sentencia por tabla contra TRUNCATE; SQLite no tiene
 * TRUNCATE, y allí `DELETE FROM` sin WHERE pasa por el disparador de DELETE.
 *
 * Qué ve quien choca (el mismo texto en los dos motores, empieza por
 * «ultron_studio:»):
 *  · PostgreSQL: SQLSTATE P0001 (raise_exception, desde `ultron_studio_forbid()`);
 *    un CHECK violado es 23514.
 *  · SQLite: `RAISE(ABORT, …)` → SQLSTATE 23000 (código 19, SQLITE_CONSTRAINT).
 *
 * SIN TOLERAR ERRORES, a diferencia de btree_gist en 2026_10_17_200002: aquí la
 * protección es un requisito. Si algo no se puede crear (p. ej. el rol no puede
 * crear funciones en `public`), la migración falla y no queda registrada. Con
 * un driver que no sea pgsql ni sqlite también falla: aplicar el esquema sin
 * sus guardas sería hacerlo en silencio. En PG Laravel envuelve la migración en
 * una transacción; en SQLite no la abre, así que la abre esta migración para no
 * dejar nunca la mitad de las guardas.
 *
 * `CREATE OR REPLACE FUNCTION`: `migrate:fresh` (db:wipe) en PG borra las
 * tablas, y con ellas sus disparadores y CHECK, pero NO las funciones. Con un
 * CREATE a secas, el siguiente `migrate` fallaría. Reemplazar es idempotente y
 * no oculta ningún error.
 *
 * Las listas van escritas aquí y no tomadas de los modelos ni del enum: esta
 * migración se aplica ANTES de publicar el código.
 *
 * Lo que NO cubre:
 *  · El dueño de las tablas puede `ALTER TABLE … DISABLE TRIGGER` o borrar el
 *    disparador. Esto protege de fallos de la aplicación y de SQL a mano, no
 *    del dueño de la base.
 *  · SQLite: `INSERT OR REPLACE` borra la fila en conflicto SIN disparar el
 *    DELETE (con recursive_triggers apagado, el valor por defecto). Laravel no
 *    lo usa: `upsert()` compila a ON CONFLICT DO UPDATE, que dispara el UPDATE.
 *  · Una migración futura que RECONSTRUYA una de estas tablas en SQLite
 *    (Schema::table con FK, change(), dropColumn…) se lleva sus disparadores, y
 *    tiene que volver a crearlos. Una columna NUEVA en versiones es mutable
 *    hasta que se añada a VERSION_IMMUTABLE_COLUMNS y a su disparador.
 *
 * down() quita disparadores, función y CHECK con IF EXISTS.
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'ultron_studio:';

    public const FUNCTION_NAME = 'ultron_studio_forbid';

    /** Lo único que la máquina de estados (§A2) cambia de una versión. */
    public const VERSION_MUTABLE_COLUMNS = ['status', 'last_validation', 'submitted_at', 'status_changed_at', 'updated_at'];

    /**
     * Todas las demás columnas de una versión. Incluye `id`, que el contrato no
     * enumera pero cae en su «solo cambian…». up() comprueba que entre las dos
     * listas están exactamente las columnas de la tabla.
     */
    public const VERSION_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'label', 'note', 'content', 'content_hash',
        'catalog_version', 'catalog_hash', 'source_draft_revision',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    /**
     * Disparadores de fila, en los dos motores: nombre → [tabla, evento, motivo].
     * `UPDATE OF` es sobre VERSION_IMMUTABLE_COLUMNS.
     */
    public const ROW_TRIGGERS = [
        'usv_forbid_immutable_update' => ['ultron_studio_versions', 'UPDATE OF', 'una versión es inmutable; solo cambian status, last_validation, submitted_at, status_changed_at y updated_at'],
        'usv_forbid_delete' => ['ultron_studio_versions', 'DELETE', 'las versiones no se borran'],
        'usvr_forbid_update' => ['ultron_studio_version_reviews', 'UPDATE', 'las reseñas son de solo inserción'],
        'usvr_forbid_delete' => ['ultron_studio_version_reviews', 'DELETE', 'las reseñas son de solo inserción'],
        'usae_forbid_update' => ['ultron_studio_audit_events', 'UPDATE', 'la auditoría es de solo inserción'],
        'usae_forbid_delete' => ['ultron_studio_audit_events', 'DELETE', 'la auditoría es de solo inserción'],
        // Las reglas se editan y se archivan, nunca se borran: sin esta guarda un
        // DELETE por SQL saltaría la auditoría (propuesta P5 del especialista de datos).
        'usr_forbid_delete' => ['ultron_studio_rules', 'DELETE', 'las reglas se archivan, no se borran'],
    ];

    /** Disparadores de sentencia contra TRUNCATE, solo en PG: nombre → [tabla, motivo]. */
    public const TRUNCATE_TRIGGERS = [
        'usv_forbid_truncate' => ['ultron_studio_versions', 'las versiones no se borran'],
        'usvr_forbid_truncate' => ['ultron_studio_version_reviews', 'las reseñas son de solo inserción'],
        'usae_forbid_truncate' => ['ultron_studio_audit_events', 'la auditoría es de solo inserción'],
        'usr_forbid_truncate' => ['ultron_studio_rules', 'las reglas se archivan, no se borran'],
    ];

    /** CHECK de dominio, solo en PG: tabla → [nombre → [columna, valores]]. */
    public const DOMAIN_CHECKS = [
        'ultron_studio_versions' => [
            // Un estado que el motor no respalda no puede existir en la base.
            'usv_status_chk' => ['status', ['DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED']],
        ],
        'ultron_studio_version_reviews' => [
            'usvr_decision_chk' => ['decision', ['approved', 'rejected']],
        ],
        'ultron_studio_rules' => [
            'usr_source_chk' => ['source', ['legacy_baseline', 'studio']],
            'usr_enforcement_chk' => ['enforcement', ['code', 'none']],
            'usr_status_chk' => ['status', ['active', 'draft', 'archived']],
            'usr_type_chk' => ['type', ['restriction', 'permission', 'condition', 'style']],
            'usr_priority_chk' => ['priority', ['critical', 'high', 'normal']],
        ],
        'ultron_studio_audit_events' => [
            'usae_subject_type_chk' => ['subject_type', ['draft', 'version', 'rule', 'system']],
            'usae_actor_type_chk' => ['actor_type', ['admin', 'system']],
        ],
    ];

    /** CHECK entre columnas de las reglas, solo en PG: nombre → expresión. */
    public const RULE_CHECKS = [
        'usr_studio_never_active_chk' => "source <> 'studio' OR (status IN ('draft', 'archived') AND enforcement = 'none')",
        'usr_legacy_is_code_chk' => "source <> 'legacy_baseline' OR enforcement = 'code'",
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
        $this->assertVersionColumnsClassified();

        foreach (self::DOMAIN_CHECKS as $table => $checks) {
            foreach ($checks as $name => [$column, $values]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IN (".self::literalList($values).'))');
            }
        }
        foreach (self::RULE_CHECKS as $name => $expression) {
            DB::statement("ALTER TABLE ultron_studio_rules ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        // El mensaje lo arma con el motivo de cada disparador (TG_ARGV[0]), la
        // operación y la tabla: el mismo texto que self::message() en SQLite.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ultron_studio_forbid() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'ultron_studio: % (% en %)',
                    COALESCE(TG_ARGV[0], 'operación no permitida'), TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'P0001';
            END;
            $fn$
            SQL);

        foreach (self::ROW_TRIGGERS as $name => [$table, $event, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event)." ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
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

        foreach (array_keys(self::RULE_CHECKS) as $name) {
            DB::statement("ALTER TABLE IF EXISTS ultron_studio_rules DROP CONSTRAINT IF EXISTS {$name}");
        }
        foreach (self::DOMAIN_CHECKS as $table => $checks) {
            foreach (array_keys($checks) as $name) {
                DB::statement("ALTER TABLE IF EXISTS {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }
    }

    private function upSqlite(): void
    {
        $this->assertVersionColumnsClassified();

        foreach (self::ROW_TRIGGERS as $name => [$table, $event, $reason]) {
            $operation = $event === 'DELETE' ? 'DELETE' : 'UPDATE';
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event)." ON {$table} FOR EACH ROW "
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
     * Cada columna de una versión es mutable o inmutable, explícitamente. Si
     * alguien añade una columna a la tabla sin clasificarla, o la lista nombra
     * una que no existe (SQLite no lo comprobaría en `UPDATE OF`), esto falla.
     */
    private function assertVersionColumnsClassified(): void
    {
        $declared = [...self::VERSION_IMMUTABLE_COLUMNS, ...self::VERSION_MUTABLE_COLUMNS];
        $actual = Schema::getColumnListing('ultron_studio_versions');

        $unclassified = array_values(array_diff($actual, $declared));
        $missing = array_values(array_diff($declared, $actual));

        if ($unclassified !== [] || $missing !== []) {
            throw new RuntimeException('ultron_studio_versions: columnas sin clasificar ['.implode(', ', $unclassified)
                .'] o que no existen ['.implode(', ', $missing).']. Cada columna es mutable o inmutable, explícitamente.');
        }
    }

    private static function eventClause(string $event): string
    {
        return $event === 'UPDATE OF'
            ? 'UPDATE OF '.implode(', ', self::VERSION_IMMUTABLE_COLUMNS)
            : $event;
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
        return new RuntimeException("Las guardas de ULTRON Studio solo existen para pgsql y sqlite, y son obligatorias: con «{$driver}» la migración no se aplica.");
    }
};
