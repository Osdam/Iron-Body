<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GUARDAS DEL HITO 5 DE ULTRON STUDIO EN LA BASE. Contrato:
 * docs/ultron-studio/ULTRON_STUDIO_H5_CONTRACT.md, §A3.2 y §A3.4. Mismo
 * patrón que las de F (2026_10_18_100002) y las del laboratorio
 * (2026_10_19_100003).
 *
 * Es la tercera capa: 1) ninguna ruta edita ni borra una entrada; 2) el modelo
 * lo impide; 3) esto, que vale aunque alguien escriba sin pasar por el código
 * (query builder crudo, SQL a mano).
 *
 * En `ultron_studio_publications` (la cadena, de solo inserción):
 *  · Disparadores `uspub_forbid_update` y `uspub_forbid_delete` en los dos
 *    motores; `uspub_forbid_truncate` (de sentencia) solo en PG. SQLite admite
 *    un solo evento por disparador, así que UPDATE y DELETE van por separado
 *    también en PG; SQLite no tiene TRUNCATE, y allí `DELETE FROM` sin WHERE
 *    pasa por el de DELETE.
 *  · Solo en PG (SQLite no admite añadir restricciones a una tabla existente):
 *    CHECK de dominio de `action` y `engine_contract`, y `uspub_reason_chk`
 *    (revertir y volver a Legacy llevan motivo).
 *
 * En `ultron_studio_versions` (de F):
 *  · Solo en PG: `usv_status_chk` pasa de los 5 estados de F a los 9 que
 *    alcanza H5 (`VALIDATING` sigue fuera: no se guarda nunca). DROP + ADD en
 *    UNA sentencia `ALTER TABLE`: un solo cerrojo y ningún instante sin CHECK.
 *    Valida las filas existentes, que ya cumplían los 5.
 *  · En los dos motores: índices únicos PARCIALES `usv_one_canary` y
 *    `usv_one_published` (como mucho una versión en CANARY y una en PUBLISHED,
 *    contrato §A2). Una segunda es 23505 en PG; en SQLite, 23000 con «UNIQUE
 *    constraint failed».
 *  · Sin columnas nuevas (§A3.3) y SIN reconstruir la tabla: en SQLite, una
 *    reconstrucción (Schema::table con FK, change(), dropColumn…) recrearía
 *    los índices sin su WHERE —un único parcial pasaría a único total, una sola
 *    versión por estado— y perdería sus disparadores. La prueba de la migración
 *    comprueba el WHERE en los dos motores.
 *
 * FUNCIÓN DE F, `ultron_studio_forbid()`: los disparadores de la cadena la
 * reutilizan (contrato §A3.2), y esta migración la vuelve a declarar con
 * `CREATE OR REPLACE` y el MISMO cuerpo que F, byte a byte: es idempotente,
 * no cambia su dueño ni su comportamiento y garantiza que existe. down() no la
 * borra: es de F. Qué ve quien choca (el mismo texto en los dos motores,
 * empieza por «ultron_studio:»):
 *  · PostgreSQL: SQLSTATE P0001; un CHECK violado es 23514.
 *  · SQLite: `RAISE(ABORT, …)` → SQLSTATE 23000 (código 19, SQLITE_CONSTRAINT).
 *
 * SIN TOLERAR ERRORES: la protección es un requisito. Si algo no se puede
 * crear (el rol no es dueño de la función, `usv_status_chk` no existe, ya hay
 * dos versiones CANARY…), la migración falla y no queda registrada. Con un
 * driver que no sea pgsql ni sqlite también falla. En PG Laravel envuelve la
 * migración en una transacción; en SQLite no la abre, así que la abre esta
 * migración para no dejar nunca la mitad.
 *
 * Cerrojos (PG), en este orden: la cadena (nueva, nadie la usa) y al final
 * versiones, para tener su cerrojo el menor tiempo posible:
 *  · `ALTER TABLE … DROP/ADD CONSTRAINT` pide ACCESS EXCLUSIVE sobre
 *    `ultron_studio_versions`: choca con CUALQUIER transacción abierta que la
 *    haya tocado, también una que solo la haya LEÍDO (a diferencia de las FKs
 *    de H4). Mientras espera, las lecturas nuevas de versiones hacen cola
 *    detrás. Con el `lock_timeout` de `ironbody-migrate` (5 s) falla limpia
 *    (55P03), no queda registrada y se reintenta. El motor no lee versiones:
 *    la cola, si la hay, es solo del Studio.
 *  · `CREATE INDEX` (no CONCURRENTLY: no cabe en la transacción de la
 *    migración y la tabla tiene decenas de filas) pide SHARE, ya cubierto por
 *    el ACCESS EXCLUSIVE anterior.
 *  · Ningún ALTER sobre tablas del motor (`marketing_*`, `ultron_aborts`).
 *
 * Las listas van escritas aquí y no tomadas de los modelos ni del enum: esta
 * migración se aplica ANTES de publicar el código.
 *
 * Lo que NO cubre (igual que F):
 *  · El dueño de las tablas puede `ALTER TABLE … DISABLE TRIGGER` o borrar el
 *    disparador. El rol de la aplicación, solo DML, no puede (42501).
 *  · SQLite: `INSERT OR REPLACE` borra la fila en conflicto SIN disparar el
 *    DELETE (con recursive_triggers apagado, el valor por defecto). Laravel no
 *    lo usa: `upsert()` compila a ON CONFLICT DO UPDATE, que dispara el UPDATE.
 *  · Que la cabeza de la cadena coincida con la versión CANARY/PUBLISHED, que
 *    `previous_publication_id` sea la cabeza anterior o que solo `legacy` vaya
 *    sin versión: lo garantiza el servicio, en la misma transacción.
 *
 * down() SE NIEGA si hay alguna publicación o alguna versión en CANARY,
 * PUBLISHED, RETIRED o ROLLED_BACK (contrato §A3.4), antes de tocar nada y
 * con el cerrojo ya tomado (comprobar y deshacer es atómico). Sin filas de H5
 * quita los índices parciales, devuelve `usv_status_chk` a los 5 estados de F
 * y quita disparadores y CHECK de la cadena, con IF EXISTS.
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'ultron_studio:';

    /** La de F (2026_10_18_100002). */
    public const FUNCTION_NAME = 'ultron_studio_forbid';

    public const TABLE = 'ultron_studio_publications';

    public const VERSIONS_TABLE = 'ultron_studio_versions';

    public const REASON = 'la cadena de publicaciones es de solo inserción';

    /** Disparadores de fila, en los dos motores: nombre → evento. */
    public const ROW_TRIGGERS = [
        'uspub_forbid_update' => 'UPDATE',
        'uspub_forbid_delete' => 'DELETE',
    ];

    /** Disparador de sentencia contra TRUNCATE, solo en PG. */
    public const TRUNCATE_TRIGGER = 'uspub_forbid_truncate';

    /** CHECK de dominio de la cadena, solo en PG: nombre → [columna, valores]. */
    public const DOMAIN_CHECKS = [
        'uspub_action_chk' => ['action', ['publish', 'promote', 'rollback', 'legacy']],
        'uspub_engine_contract_chk' => ['engine_contract', ['ultron-engine-config/1']],
    ];

    /** CHECK entre columnas de la cadena, solo en PG: nombre → expresión. */
    public const ROW_CHECKS = [
        'uspub_reason_chk' => "action NOT IN ('rollback', 'legacy') OR reason IS NOT NULL",
    ];

    public const STATUS_CHECK = 'usv_status_chk';

    /** Lo que admitía `usv_status_chk` en F; a esto vuelve down(). */
    public const F_VERSION_STATUSES = ['DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED'];

    /** Lo que solo existe con H5 (contrato §A2); con una fila así, down() se niega. */
    public const H5_VERSION_STATUSES = ['CANARY', 'PUBLISHED', 'RETIRED', 'ROLLED_BACK'];

    /** Índices únicos parciales sobre `ultron_studio_versions.status`, en los dos motores: nombre → estado. */
    public const PARTIAL_UNIQUE_INDEXES = [
        'usv_one_canary' => 'CANARY',
        'usv_one_published' => 'PUBLISHED',
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
            DB::transaction(fn () => $this->downPostgres());

            return;
        }
        if ($driver === 'sqlite') {
            DB::transaction(fn () => $this->downSqlite());

            return;
        }

        throw self::unsupported($driver);
    }

    /** El texto del error, igual en los dos motores (el de F). */
    public static function message(string $reason, string $operation, string $table): string
    {
        return self::MESSAGE_PREFIX.' '.$reason.' ('.$operation.' en '.$table.')';
    }

    /**
     * Los 9 estados que admite `usv_status_chk` con H5.
     *
     * @return list<string>
     */
    public static function h5VersionCheckStatuses(): array
    {
        return [...self::F_VERSION_STATUSES, ...self::H5_VERSION_STATUSES];
    }

    private function upPostgres(): void
    {
        foreach (self::DOMAIN_CHECKS as $name => [$column, $values]) {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT {$name} CHECK ({$column} IN (".self::literalList($values).'))');
        }
        foreach (self::ROW_CHECKS as $name => $expression) {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        // El mismo cuerpo que F (2026_10_18_100002), byte a byte: reemplazarla
        // no cambia nada y garantiza que existe. Si el rol no es su dueño,
        // falla (42501) y la migración no se aplica.
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

        foreach (self::ROW_TRIGGERS as $name => $event) {
            DB::statement("CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE.' '
                .'FOR EACH ROW EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal(self::REASON).')');
        }
        DB::statement('CREATE TRIGGER '.self::TRUNCATE_TRIGGER.' BEFORE TRUNCATE ON '.self::TABLE.' '
            .'FOR EACH STATEMENT EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal(self::REASON).')');

        // Versiones, al final: su ACCESS EXCLUSIVE dura lo menos posible.
        $this->replaceStatusCheck(self::h5VersionCheckStatuses(), ifExists: false);
        $this->createPartialUniqueIndexes();
    }

    private function downPostgres(): void
    {
        // Los cerrojos que va a pedir lo que sigue, tomados ANTES de comprobar:
        // así nadie publica entre el recuento y el DROP. Mismo orden que up().
        // Con `--pretend` las lecturas devuelven vacío: se escribe igual, para
        // que la vista previa enseñe el cerrojo.
        if (DB::connection()->pretending() || Schema::hasTable(self::TABLE)) {
            DB::statement('LOCK TABLE '.self::TABLE.', '.self::VERSIONS_TABLE.' IN ACCESS EXCLUSIVE MODE');
        }
        $this->refuseWithH5Rows();

        foreach (array_keys(self::PARTIAL_UNIQUE_INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
        $this->replaceStatusCheck(self::F_VERSION_STATUSES, ifExists: true);

        foreach ([...array_keys(self::ROW_TRIGGERS), self::TRUNCATE_TRIGGER] as $name) {
            DB::statement("DROP TRIGGER IF EXISTS {$name} ON ".self::TABLE);
        }
        foreach ([...array_keys(self::ROW_CHECKS), ...array_keys(self::DOMAIN_CHECKS)] as $name) {
            DB::statement('ALTER TABLE IF EXISTS '.self::TABLE." DROP CONSTRAINT IF EXISTS {$name}");
        }
        // `ultron_studio_forbid()` es de F: se queda.
    }

    private function upSqlite(): void
    {
        foreach (self::ROW_TRIGGERS as $name => $event) {
            DB::statement("CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE.' FOR EACH ROW '
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message(self::REASON, $event, self::TABLE)).'); END');
        }

        $this->createPartialUniqueIndexes();
    }

    private function downSqlite(): void
    {
        $this->refuseWithH5Rows();

        foreach (array_keys(self::PARTIAL_UNIQUE_INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
        foreach (array_keys(self::ROW_TRIGGERS) as $name) {
            DB::statement("DROP TRIGGER IF EXISTS {$name}");
        }
    }

    /**
     * DROP + ADD en una sola sentencia: un solo cerrojo y ningún instante sin
     * CHECK. En up() sin IF EXISTS: si el CHECK de F no está, el esquema no es
     * el que esta migración espera y tiene que fallar.
     *
     * @param  list<string>  $statuses
     */
    private function replaceStatusCheck(array $statuses, bool $ifExists): void
    {
        DB::statement('ALTER TABLE '.self::VERSIONS_TABLE
            .' DROP CONSTRAINT '.($ifExists ? 'IF EXISTS ' : '').self::STATUS_CHECK
            .', ADD CONSTRAINT '.self::STATUS_CHECK.' CHECK (status IN ('.self::literalList($statuses).'))');
    }

    /** El mismo texto en los dos motores; SQLite lo guarda tal cual en `sqlite_master`. */
    private function createPartialUniqueIndexes(): void
    {
        foreach (self::PARTIAL_UNIQUE_INDEXES as $name => $status) {
            DB::statement("CREATE UNIQUE INDEX {$name} ON ".self::VERSIONS_TABLE.' (status) WHERE status = '.self::literal($status));
        }
    }

    /**
     * Contrato §A3.4: con filas de H5, revertir el esquema destruiría la
     * cadena y dejaría versiones en estados que ni el CHECK de F ni su código
     * pueden mover. Se niega con un mensaje claro, antes de tocar nada.
     */
    private function refuseWithH5Rows(): void
    {
        $publications = Schema::hasTable(self::TABLE) ? DB::table(self::TABLE)->count() : 0;
        $versions = DB::table(self::VERSIONS_TABLE)->whereIn('status', self::H5_VERSION_STATUSES)->count();

        if ($publications > 0 || $versions > 0) {
            throw new RuntimeException(self::TABLE.': down() se niega a revertir el Hito 5 con datos del Hito 5 '
                ."({$publications} publicaciones, {$versions} versiones en ".implode(', ', self::H5_VERSION_STATUSES).'). '
                .'La cadena es de solo inserción y revertir la destruiría (contrato H5 §A3.4). '
                .'Para volver al código de H4 no hace falta revertir el esquema (§A3.5).');
        }
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
        return new RuntimeException("Las guardas del Hito 5 de ULTRON Studio solo existen para pgsql y sqlite, y son obligatorias: con «{$driver}» la migración no se aplica.");
    }
};
