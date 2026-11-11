<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ULTRON STUDIO · PLANTILLAS DE WHATSAPP DE META DESDE EL CRM, paquete 1a
 * (`database-specialist`): LO QUE DICE META, ESCRITO POR EL SISTEMA. Plan:
 * docs/ultron-studio/plans/PLANTILLAS_META_PLAN.md, §5.3, §5.6 y §5.7.2;
 * contrato H9, fila L9-96 (enmienda de la protección 12 y de D-5). Informe:
 * plans/informes-plantillas/informe-db.md.
 *
 * AMPLIACIÓN ADITIVA de `ultron_studio_template_meta_checks` (W9,
 * `2026_11_13_200001`), que SIGUE SIENDO DE SOLO INSERCIÓN: esta migración no
 * toca sus disparadores, su guarda entre tablas, su CHECK de forma, el de
 * idioma ni su REVOKE, y no ejecuta ningún UPDATE ni DELETE sobre sus filas.
 *
 *  · `source` varchar(10) NOT NULL DEFAULT 'manual' — `manual` (la orden de
 *    consola de hoy, que no la nombra: sus filas entran igual) o `meta_api`
 *    (la sincronización y «Comprobar ahora»). Las filas que ya existen leen
 *    `manual` (las escribió la orden de consola): en PG 14 el valor por
 *    defecto queda en el catálogo, sin reescribir la tabla.
 *  · `submission_id` bigint NULL — el envío de la tabla nueva
 *    (`ultron_studio_meta_template_submissions`), SIN FK (como `template_id`):
 *    lo comprueba la guarda entre tablas nueva. Nulo en las manuales y en las
 *    de la API de plantillas registradas a mano (§5.5, 3b).
 *  · `meta_components_hash` varchar(64) NULL — la huella de los componentes
 *    que enseña Meta (§5.3). Del mismo tipo que las otras dos huellas de la
 *    tabla (varchar(64) con CHECK de forma; el plan decía char(64): mismo
 *    comportamiento con el CHECK, y sin el relleno de `bpchar`).
 *  · `status` varchar(10) → varchar(20) y `ustmc_status_chk` con los 4 de W9,
 *    LITERALES y en su orden, más los de Meta (`PENDING`, `IN_APPEAL`,
 *    `PENDING_DELETION`, `DELETED`, `ARCHIVED`, `LIMIT_EXCEEDED`) y dos locales
 *    (`NOT_FOUND`: Meta ya no la tiene; `UNKNOWN`: un estado que este código no
 *    conoce). El canal solo acepta `APPROVED`: todo lo nuevo bloquea.
 *  · `category` varchar(10) → varchar(16) y `ustmc_category_chk` con
 *    `authentication` (14 caracteres: NO CABÍA en varchar(10); el plan no lo
 *    decía), para registrar fielmente una recategorización. El canal nunca la
 *    acepta.
 *  · CHECK nuevos (solo PG, como todos los de W9): `ustmc_source_chk` (el
 *    dominio) y `ustmc_source_shape_chk`: una fila `meta_api` trae SIEMPRE su
 *    huella de componentes (64 hex); una `manual`, NUNCA huella de componentes
 *    ni envío (la orden de consola no sabe leerlos: §5.6).
 *  · Disparador nuevo (los dos motores, el patrón de W9): una fila que cita un
 *    envío cita uno de la MISMA plantilla y de su huella exacta. En PG con su
 *    función propia (`ultron_studio_chk_ustmc_submission_of_template`,
 *    plpgsql, INVOKER, tabla cualificada con `public.`); en SQLite, en el WHEN.
 *
 * ESTADOS SOLO EN PG, como W9: en SQLite no hay CHECK que ampliar (la puerta
 * PG es obligatoria) y el largo de un varchar no existe; allí se añaden las
 * tres columnas (ADD COLUMN nativo, sin recrear la tabla: recrearla se llevaría
 * sus disparadores) y el disparador.
 *
 * UNA SOLA SENTENCIA EN PG (el patrón de V9 y SC-3): ADD COLUMN, los dos ALTER
 * TYPE y el DROP + ADD de los CHECK van en un único ALTER TABLE: un solo
 * cerrojo ACCESS EXCLUSIVE y ningún instante sin CHECK. Ampliar un varchar no
 * reescribe la tabla. Las filas que ya existen cumplen los CHECK nuevos por
 * construcción (estados y categorías solo se suman; `source` = 'manual' sin
 * envío ni huella), así que la validación no puede fallar por datos.
 *
 * EL REVOKE DE W9 SE VUELVE A COMPROBAR después del ALTER (no se vuelve a
 * conceder ni a quitar nada): el rol de la aplicación (`iron_app`, o
 * `ULTRON_STUDIO_DB_APP_ROLE`) no puede hacer UPDATE en ninguna columna, nuevas
 * incluidas, ni DELETE. Si puede, la migración falla y no se registra (alguien
 * concedió fuera de las migraciones: hay que mirarlo). Sin el rol en el
 * clúster, nada que comprobar, salvo en `APP_ENV=production`: error.
 *
 * CERROJOS. Si la sesión no trae `lock_timeout` (el de `ironbody-migrate` es
 * de 5 s), `SET LOCAL lock_timeout = '5s'`: nunca espera sin límite. El
 * cerrojo ACCESS EXCLUSIVE se toma ANTES de contar (sin subir de un cerrojo
 * más débil a mitad de camino); con un lector o un escritor abiertos sobre la
 * tabla, cae limpia (55P03), sin tocar nada, y se reintenta. 0 filas en
 * producción a la fecha (L9-94).
 *
 * RECUENTOS antes y después: las mismas filas y la misma huella de sus 15
 * columnas de W9; +3 columnas; +2 CHECK (PG) con los mismos nombres de antes
 * para estado y categoría; +1 disparador; en `ultron_studio_%`, +2 CHECK (PG) y
 * +1 disparador. Se niega a ampliar dos veces o un esquema que no es el de W9.
 *
 * down() SE NIEGA, con el cerrojo ya tomado y antes de tocar nada, si alguna
 * fila es `meta_api`, tiene un estado o una categoría nuevos, cita un envío o
 * trae huella de componentes (sin los CHECK en SQLite, se mira todo): el
 * esquema de W9 no los admitiría y nunca borra filas (R6 del plan: a partir de
 * la primera fila de la API, no hay vuelta atrás del esquema). Sin ellas,
 * restaura EXACTAMENTE W9 (lee sus CHECK y sus largos de su migración: una
 * sola verdad) y deja las filas de W9 intactas. En producción no se ejecuta
 * nunca (protección 11).
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'ultron_studio:';

    public const CHECK_FUNCTION_PREFIX = 'ultron_studio_chk_';

    public const TABLE = 'ultron_studio_template_meta_checks';

    /** La tabla nueva (2026_11_15_100001): solo se referencia. */
    public const SUBMISSIONS = 'ultron_studio_meta_template_submissions';

    /** La migración de W9 que crea la tabla (y a la que down() vuelve, tal cual). */
    public const W9_MIGRATION = '2026_11_13_200001_create_ultron_studio_template_meta_checks_table';

    public const APP_ROLE = 'iron_app';

    public const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    public const LOCK_TIMEOUT = '5s';

    public const SOURCES = ['manual', 'meta_api'];

    public const SOURCE_DEFAULT = 'manual';

    public const SOURCE_API = 'meta_api';

    /** Los que se suman a los 4 de W9: los de Meta y dos locales. */
    public const ADDED_STATUSES = ['PENDING', 'IN_APPEAL', 'PENDING_DELETION', 'DELETED', 'ARCHIVED', 'LIMIT_EXCEEDED', 'NOT_FOUND', 'UNKNOWN'];

    /** La recategorización a AUTHENTICATION se registra; el canal nunca la acepta. */
    public const ADDED_CATEGORIES = ['authentication'];

    /** Los largos nuevos (W9: 10 y 10). `PENDING_DELETION` tiene 16; `authentication`, 14. */
    public const STATUS_LENGTH = 20;

    public const CATEGORY_LENGTH = 16;

    public const W9_STATUS_LENGTH = 10;

    public const W9_CATEGORY_LENGTH = 10;

    /** En su orden de creación. */
    public const ADDED_COLUMNS = ['source', 'submission_id', 'meta_components_hash'];

    public const STATUS_CHECK = 'ustmc_status_chk';

    public const CATEGORY_CHECK = 'ustmc_category_chk';

    public const SOURCE_CHECK = 'ustmc_source_chk';

    public const SOURCE_SHAPE_CHECK = 'ustmc_source_shape_chk';

    /** Una fila `meta_api` trae su huella de componentes; una `manual`, ni huella de componentes ni envío. */
    public const SOURCE_SHAPE = "(source = 'manual' AND submission_id IS NULL AND meta_components_hash IS NULL) "
        ."OR (source = 'meta_api' AND meta_components_hash IS NOT NULL AND meta_components_hash ~ '^[0-9a-f]{64}$' AND (submission_id IS NULL OR submission_id > 0))";

    public const CROSS_TRIGGER = 'ustmc_submission_of_template';

    public const CROSS_WHEN = 'NEW.submission_id IS NOT NULL';

    public const CROSS_VIOLATION = 'NOT EXISTS (SELECT 1 FROM %T%'.self::SUBMISSIONS.' AS s WHERE s.id = NEW.submission_id '
        .'AND s.template_id = NEW.template_id AND s.template_content_hash = NEW.template_content_hash)';

    public const CROSS_REASON = 'una comprobación de Meta que cita un envío cita uno de la misma plantilla y de su huella exacta';

    public function up(): void
    {
        $driver = self::driver();
        // Laravel ya la envuelve en una transacción; esta (un SAVEPOINT dentro de ella) la hace atómica también fuera del migrador.
        DB::transaction(fn () => $driver === 'pgsql' ? $this->upPostgres() : $this->upSqlite());
    }

    public function down(): void
    {
        $driver = self::driver();
        DB::transaction(fn () => $driver === 'pgsql' ? $this->downPostgres() : $this->downSqlite());
    }

    /** El texto del error, igual en los dos motores (el de F). */
    public static function message(string $reason, string $operation, string $table): string
    {
        return self::MESSAGE_PREFIX.' '.$reason.' ('.$operation.' en '.$table.')';
    }

    private static ?object $w9 = null;

    /** La migración de W9 (una sola verdad para lo que down() restaura). */
    public static function w9(): object
    {
        return self::$w9 ??= require database_path('migrations/'.self::W9_MIGRATION.'.php');
    }

    /** @return list<string> los 4 de W9, literales y en su orden, y los nuevos */
    public static function statuses(): array
    {
        return [...self::w9()::STATUSES, ...self::ADDED_STATUSES];
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return [...self::w9()::CATEGORIES, ...self::ADDED_CATEGORIES];
    }

    /** @return list<string> los CHECK de la tabla tras ampliarla (solo PG) */
    public static function checkNames(): array
    {
        $nombres = [...array_keys(self::w9()::DOMAIN_CHECKS), self::w9()::SHAPE_CHECK, self::SOURCE_CHECK, self::SOURCE_SHAPE_CHECK];
        sort($nombres, SORT_STRING);

        return $nombres;
    }

    /** @return list<string> los de W9 en ese motor (sin ampliar) */
    public static function w9TriggerNames(string $driver): array
    {
        $w9 = self::w9();
        $nombres = [...array_keys($w9::ROW_TRIGGERS), $w9::CROSS_TRIGGER];
        if ($driver === 'pgsql') {
            $nombres[] = $w9::TRUNCATE_TRIGGER;
        }
        sort($nombres, SORT_STRING);

        return $nombres;
    }

    /** @return list<string> */
    public static function w9Columns(): array
    {
        return ['id', 'uuid', 'client_request_id', 'template_id', 'template_content_hash', 'meta_name', 'language', 'category', 'meta_body_hash',
            'meta_template_id', 'status', 'checked_at', 'recorded_by_admin_id', 'recorded_by_name', 'created_at'];
    }

    public static function crossFunction(): string
    {
        return self::CHECK_FUNCTION_PREFIX.self::CROSS_TRIGGER;
    }

    /** El rol de la aplicación (como DB9 y W9): solo minúsculas, dígitos y `_`, hasta 63. */
    public static function appRole(): string
    {
        $env = getenv(self::APP_ROLE_ENV);
        $role = is_string($env) && $env !== '' ? $env : self::APP_ROLE;
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new RuntimeException('ultron_studio (plantillas de Meta): '.self::APP_ROLE_ENV.' no es un nombre de rol válido (minúsculas, dígitos y _, hasta 63).');
        }

        return $role;
    }

    private function upPostgres(): void
    {
        $this->boundLockWait();
        // El cerrojo ANTES de contar: nadie escribe entre el recuento y el ALTER, y no se sube de un cerrojo más débil a mitad.
        DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
        $antes = $this->footprint('pgsql');
        if ($antes !== null) {
            $this->assertW9Shape('pgsql', $antes, 'ampliar');
        }

        DB::statement('ALTER TABLE '.self::TABLE
            ." ADD COLUMN source varchar(10) NOT NULL DEFAULT '".self::SOURCE_DEFAULT."',"
            .' ADD COLUMN submission_id bigint NULL,'
            .' ADD COLUMN meta_components_hash varchar(64) NULL,'
            .' ALTER COLUMN status TYPE varchar('.self::STATUS_LENGTH.'),'
            .' ALTER COLUMN category TYPE varchar('.self::CATEGORY_LENGTH.'),'
            .' DROP CONSTRAINT '.self::STATUS_CHECK.', ADD CONSTRAINT '.self::STATUS_CHECK.' CHECK (status IN ('.self::literalList(self::statuses()).')),'
            .' DROP CONSTRAINT '.self::CATEGORY_CHECK.', ADD CONSTRAINT '.self::CATEGORY_CHECK.' CHECK (category IN ('.self::literalList(self::categories()).')),'
            .' ADD CONSTRAINT '.self::SOURCE_CHECK.' CHECK (source IN ('.self::literalList(self::SOURCES).')),'
            .' ADD CONSTRAINT '.self::SOURCE_SHAPE_CHECK.' CHECK ('.self::SOURCE_SHAPE.')');

        DB::statement(strtr(<<<'SQL'
            CREATE OR REPLACE FUNCTION {fn}() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                IF ({violation}) THEN
                    RAISE EXCEPTION 'ultron_studio: % (% en %)', TG_ARGV[0], TG_OP, TG_TABLE_NAME
                        USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END;
            $fn$
            SQL, ['{fn}' => self::crossFunction(), '{violation}' => self::dialect(self::CROSS_VIOLATION, 'pgsql')]));
        DB::statement('CREATE TRIGGER '.self::CROSS_TRIGGER.' BEFORE INSERT ON '.self::TABLE.' '
            .'FOR EACH ROW WHEN ('.self::CROSS_WHEN.') '
            .'EXECUTE FUNCTION '.self::crossFunction().'('.self::literal(self::CROSS_REASON).')');

        $this->assertAppStillCannotWrite();

        if ($antes !== null) {
            $this->assertExtended('pgsql', $antes);
        }
    }

    private function downPostgres(): void
    {
        $this->boundLockWait();
        if (DB::connection()->pretending() || Schema::hasTable(self::TABLE)) {
            DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
        }
        $this->refuseWithNewRows();
        $antes = $this->footprint('pgsql');

        $w9 = self::w9();
        DB::statement('DROP TRIGGER IF EXISTS '.self::CROSS_TRIGGER.' ON '.self::TABLE);
        DB::statement('ALTER TABLE '.self::TABLE
            .' DROP CONSTRAINT IF EXISTS '.self::SOURCE_SHAPE_CHECK.','
            .' DROP CONSTRAINT IF EXISTS '.self::SOURCE_CHECK.','
            .' DROP CONSTRAINT IF EXISTS '.self::STATUS_CHECK.','
            .' DROP CONSTRAINT IF EXISTS '.self::CATEGORY_CHECK.','
            .' DROP COLUMN IF EXISTS meta_components_hash,'
            .' DROP COLUMN IF EXISTS submission_id,'
            .' DROP COLUMN IF EXISTS source,'
            .' ALTER COLUMN status TYPE varchar('.self::W9_STATUS_LENGTH.'),'
            .' ALTER COLUMN category TYPE varchar('.self::W9_CATEGORY_LENGTH.'),'
            .' ADD CONSTRAINT '.self::STATUS_CHECK.' CHECK (status IN ('.self::literalList($w9::STATUSES).')),'
            .' ADD CONSTRAINT '.self::CATEGORY_CHECK.' CHECK (category IN ('.self::literalList($w9::CATEGORIES).'))');
        DB::statement('DROP FUNCTION IF EXISTS '.self::crossFunction().'()');

        if ($antes !== null) {
            $this->assertRestored('pgsql', $antes);
        }
    }

    private function upSqlite(): void
    {
        $antes = $this->footprint('sqlite');
        if ($antes !== null) {
            $this->assertW9Shape('sqlite', $antes, 'ampliar');
        }

        // ADD COLUMN nativo (sin recrear la tabla, que se llevaría sus disparadores). Sin largo ni CHECK: los de W9 son solo de PG.
        DB::statement('ALTER TABLE '.self::TABLE." ADD COLUMN source varchar NOT NULL DEFAULT '".self::SOURCE_DEFAULT."'");
        DB::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN submission_id integer NULL');
        DB::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN meta_components_hash varchar NULL');
        DB::statement('CREATE TRIGGER '.self::CROSS_TRIGGER.' BEFORE INSERT ON '.self::TABLE.' FOR EACH ROW '
            .'WHEN ('.self::CROSS_WHEN.' AND '.self::dialect(self::CROSS_VIOLATION, 'sqlite').') '
            .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message(self::CROSS_REASON, 'INSERT', self::TABLE)).'); END');

        if ($antes !== null) {
            $this->assertExtended('sqlite', $antes);
        }
    }

    private function downSqlite(): void
    {
        $this->refuseWithNewRows();
        $antes = $this->footprint('sqlite');

        // Primero el disparador: SQLite no quita una columna que nombre un disparador.
        DB::statement('DROP TRIGGER IF EXISTS '.self::CROSS_TRIGGER);
        foreach (array_reverse(self::ADDED_COLUMNS) as $columna) {
            if (DB::connection()->pretending() || Schema::hasColumn(self::TABLE, $columna)) {
                DB::statement('ALTER TABLE '.self::TABLE.' DROP COLUMN '.$columna);
            }
        }

        if ($antes !== null) {
            $this->assertRestored('sqlite', $antes);
        }
    }

    private static function driver(): string
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("ultron_studio (plantillas de Meta): driver de base de datos no soportado: {$driver}. Solo pgsql y sqlite, y las guardas son obligatorias.");
        }

        return $driver;
    }

    /**
     * Sin un límite en la sesión, esta migración no espera más de
     * LOCK_TIMEOUT por un cerrojo: falla limpia (55P03), no queda registrada y
     * se reintenta. Si la sesión ya trae uno (el de `ironbody-migrate`), manda
     * ese. SET LOCAL: vale hasta el final de la transacción de la migración.
     */
    private function boundLockWait(): void
    {
        $sentencia = "SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'";
        if (DB::connection()->pretending()) {
            DB::statement($sentencia);

            return;
        }
        if ((string) DB::scalar("SELECT current_setting('lock_timeout')") === '0') {
            DB::statement($sentencia);
        }
    }

    /**
     * El REVOKE de W9, comprobado de nuevo tras el ALTER: sin UPDATE en ninguna
     * columna (también las nuevas; `has_any_column_privilege` mira la tabla y
     * cada columna, y la pertenencia a otros roles y PUBLIC) y sin DELETE. No
     * concede ni quita nada: si el rol puede, alguien lo concedió fuera de las
     * migraciones y la migración falla.
     */
    private function assertAppStillCannotWrite(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }
        $role = self::appRole();
        if (DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]) === null) {
            if (app()->environment('production')) {
                throw new RuntimeException("ultron_studio (plantillas de Meta): el rol de la aplicación «{$role}» no existe en este clúster y APP_ENV es production: "
                    .'el REVOKE de W9 sobre las comprobaciones de Meta no se puede comprobar. La migración no se registra; '
                    .'si el rol se llama de otra forma, '.self::APP_ROLE_ENV.' en el entorno del proceso que migra.');
            }

            return;
        }
        $puede = DB::selectOne(
            "SELECT has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d",
            [$role, 'public.'.self::TABLE, $role, 'public.'.self::TABLE],
        );
        if ((int) $puede->u === 1 || (int) $puede->d === 1) {
            throw new RuntimeException('ultron_studio (plantillas de Meta): tras ampliar '.self::TABLE.", «{$role}» puede "
                .((int) $puede->u === 1 ? 'hacer UPDATE (en la tabla o en alguna columna)' : '').((int) $puede->u === 1 && (int) $puede->d === 1 ? ' y ' : '')
                .((int) $puede->d === 1 ? 'borrar' : '').': la tabla es de solo inserción (REVOKE de W9) y alguien lo concedió fuera de las migraciones. La migración no se registra.');
        }
    }

    /**
     * Protección 11 y R6 del plan: con filas que el esquema de W9 no admite,
     * revertir las destruiría o fallaría a medias. Se niega con su recuento,
     * antes de tocar nada, y nunca borra. Sin los CHECK (SQLite) se mira todo.
     */
    private function refuseWithNewRows(): void
    {
        if (DB::connection()->pretending() || ! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'source')) {
            return;
        }
        $w9 = self::w9();
        $t = static fn () => DB::table(self::TABLE);
        $api = $t()->where('source', '<>', self::SOURCE_DEFAULT)->count();
        $estados = $t()->whereNotIn('status', $w9::STATUSES)->count();
        $categorias = $t()->whereNotIn('category', $w9::CATEGORIES)->count();
        $citas = $t()->where(static fn ($q) => $q->whereNotNull('submission_id')->orWhereNotNull('meta_components_hash'))->count();

        if ($api + $estados + $categorias + $citas > 0) {
            throw new RuntimeException('ultron_studio (plantillas de Meta): down() se niega a devolver '.self::TABLE.' al esquema de W9 con filas que ese esquema no admite '
                ."({$api} de la API de Meta, {$estados} con un estado nuevo, {$categorias} con una categoría nueva y {$citas} con un envío o una huella de componentes). "
                .'La tabla es de solo inserción y nunca se borra nada (R6 del plan: desde la primera fila de la API no hay vuelta atrás del esquema). '
                .'Para volver al código anterior no hace falta revertir el esquema (las columnas se quedan).');
        }
    }

    /**
     * Lo que se compara antes y después (null con `--pretend`): columnas (y en
     * PG sus tipos), CHECK de la tabla con su definición (PG), disparadores de
     * la tabla, totales de `ultron_studio_%`, filas y una huella de las 15
     * columnas de W9 de todas las filas.
     *
     * @return array{columns: array<string, string>, checks: array<string, string>, triggers: list<string>, studio_triggers: int, studio_checks: int, rows: int, digest: string}|null
     */
    private function footprint(string $driver): ?array
    {
        if (DB::connection()->pretending()) {
            return null;
        }
        $columnas = [];
        foreach (Schema::getColumns(self::TABLE) as $c) {
            $columnas[(string) $c['name']] = (string) $c['type'];
        }
        ksort($columnas, SORT_STRING);
        $filas = DB::table(self::TABLE)->count();

        if ($driver === 'pgsql') {
            $checks = [];
            foreach (DB::select("SELECT k.conname AS n, pg_get_constraintdef(k.oid) AS def FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname = ?", [self::TABLE]) as $f) {
                $checks[(string) $f->n] = (string) $f->def;
            }
            ksort($checks, SORT_STRING);
            $triggers = array_map(static fn (object $f): string => (string) $f->n, DB::select('SELECT t.tgname AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname = ?', [self::TABLE]));
            $cols = implode(', ', array_map(static fn (string $c): string => "coalesce(m.{$c}::text, '∅')", self::w9Columns()));
            $digest = (string) DB::scalar("SELECT md5(coalesce(string_agg(concat_ws('|', {$cols}), E'\\n' ORDER BY m.id), '')) FROM ".self::TABLE.' AS m');
            $studioTriggers = (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\\_studio\\_%'");
            $studioChecks = (int) DB::scalar("SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron\\_studio\\_%'");
        } else {
            $checks = [];
            $triggers = DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', self::TABLE)->pluck('name')->map(static fn ($n): string => (string) $n)->all();
            $digest = hash('sha256', (string) json_encode(DB::table(self::TABLE)->orderBy('id')->get(self::w9Columns())));
            $studioTriggers = DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'like', 'ultron_studio_%')->count();
            $studioChecks = 0;
        }
        sort($triggers, SORT_STRING);

        return ['columns' => $columnas, 'checks' => $checks, 'triggers' => $triggers, 'studio_triggers' => $studioTriggers,
            'studio_checks' => $studioChecks, 'rows' => $filas, 'digest' => $digest];
    }

    /**
     * El esquema es el de W9 (sin ampliar): sus 15 columnas, sus disparadores
     * y, en PG, sus 4 CHECK con estado y categoría de W9 y sus largos.
     *
     * @param  array<string, mixed>  $f
     */
    private function assertW9Shape(string $driver, array $f, string $para): void
    {
        $w9 = self::w9();
        $columnas = self::w9Columns();
        sort($columnas, SORT_STRING);
        $problemas = [];
        if (array_keys($f['columns']) !== $columnas) {
            $problemas[] = 'columnas ['.implode(', ', array_keys($f['columns'])).']';
        }
        if ($f['triggers'] !== self::w9TriggerNames($driver)) {
            $problemas[] = 'disparadores ['.implode(', ', $f['triggers']).']';
        }
        if ($driver === 'pgsql') {
            $nombres = [...array_keys($w9::DOMAIN_CHECKS), $w9::SHAPE_CHECK];
            sort($nombres, SORT_STRING);
            if (array_keys($f['checks']) !== $nombres) {
                $problemas[] = 'CHECK ['.implode(', ', array_keys($f['checks'])).']';
            } elseif (str_contains($f['checks'][self::STATUS_CHECK], "'PENDING'") || str_contains($f['checks'][self::CATEGORY_CHECK], "'authentication'")) {
                $problemas[] = 'estados o categorías ya ampliados';
            }
            if (($f['columns']['status'] ?? null) !== 'character varying('.self::W9_STATUS_LENGTH.')' || ($f['columns']['category'] ?? null) !== 'character varying('.self::W9_CATEGORY_LENGTH.')') {
                $problemas[] = 'largos de status o category';
            }
        }
        if ($problemas !== []) {
            throw new RuntimeException("ultron_studio (plantillas de Meta): para {$para}, ".self::TABLE.' tiene que estar como la deja W9 ('.self::W9_MIGRATION.'), y no lo está: '
                .implode('; ', $problemas).'. La migración no se aplica.');
        }
    }

    /** @param  array<string, mixed>  $antes */
    private function assertExtended(string $driver, array $antes): void
    {
        $despues = (array) $this->footprint($driver);
        $columnas = [...self::w9Columns(), ...self::ADDED_COLUMNS];
        sort($columnas, SORT_STRING);
        $disparadores = [...self::w9TriggerNames($driver), self::CROSS_TRIGGER];
        sort($disparadores, SORT_STRING);
        $pg = $driver === 'pgsql';
        $problemas = [];
        if (array_keys($despues['columns']) !== $columnas) {
            $problemas[] = 'columnas';
        }
        if ($despues['triggers'] !== $disparadores || $despues['studio_triggers'] !== $antes['studio_triggers'] + 1) {
            $problemas[] = "disparadores ({$antes['studio_triggers']} → {$despues['studio_triggers']} en ultron_studio_%)";
        }
        if ($despues['rows'] !== $antes['rows'] || $despues['digest'] !== $antes['digest']) {
            $problemas[] = "filas ({$antes['rows']} → {$despues['rows']}) o su contenido";
        }
        if ($pg) {
            if (array_keys($despues['checks']) !== self::checkNames() || $despues['studio_checks'] !== $antes['studio_checks'] + 2) {
                $problemas[] = "CHECK ({$antes['studio_checks']} → {$despues['studio_checks']} en ultron_studio_%)";
            } elseif (! str_contains($despues['checks'][self::STATUS_CHECK], "'PENDING_DELETION'") || ! str_contains($despues['checks'][self::CATEGORY_CHECK], "'authentication'")) {
                $problemas[] = 'estados o categorías sin ampliar';
            }
            if ($despues['columns']['status'] !== 'character varying('.self::STATUS_LENGTH.')' || $despues['columns']['category'] !== 'character varying('.self::CATEGORY_LENGTH.')') {
                $problemas[] = 'largos de status o category';
            }
        }
        if ($problemas !== []) {
            throw new RuntimeException('ultron_studio (plantillas de Meta): el recuento no cuadra tras ampliar '.self::TABLE.': '.implode('; ', $problemas).'. La migración no se registra.');
        }
    }

    /** @param  array<string, mixed>  $antes */
    private function assertRestored(string $driver, array $antes): void
    {
        $despues = (array) $this->footprint($driver);
        $this->assertW9Shape($driver, $despues, 'darla por revertida');
        $pg = $driver === 'pgsql';
        $problemas = [];
        $ampliada = in_array('source', array_keys($antes['columns']), true);
        if ($despues['rows'] !== $antes['rows'] || $despues['digest'] !== $antes['digest']) {
            $problemas[] = "filas ({$antes['rows']} → {$despues['rows']}) o su contenido";
        }
        if ($despues['studio_triggers'] !== $antes['studio_triggers'] - ($ampliada ? 1 : 0)) {
            $problemas[] = "disparadores ({$antes['studio_triggers']} → {$despues['studio_triggers']} en ultron_studio_%)";
        }
        if ($pg) {
            $w9 = self::w9();
            if ($despues['studio_checks'] !== $antes['studio_checks'] - ($ampliada ? 2 : 0)) {
                $problemas[] = "CHECK ({$antes['studio_checks']} → {$despues['studio_checks']} en ultron_studio_%)";
            }
            foreach ([self::STATUS_CHECK => $w9::STATUSES, self::CATEGORY_CHECK => $w9::CATEGORIES] as $check => $valores) {
                preg_match_all("/'([A-Za-z_]+)'::character varying/", $despues['checks'][$check], $m);
                if ($m[1] !== $valores) {
                    $problemas[] = "{$check} no es el de W9";
                }
            }
        }
        if ($problemas !== []) {
            throw new RuntimeException('ultron_studio (plantillas de Meta): el recuento no cuadra tras devolver '.self::TABLE.' a W9: '.implode('; ', $problemas).'. No se revierte.');
        }
    }

    /** `%T%` en el dialecto de cada motor. */
    private static function dialect(string $sql, string $driver): string
    {
        return strtr($sql, ['%T%' => $driver === 'pgsql' ? 'public.' : '']);
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
};
