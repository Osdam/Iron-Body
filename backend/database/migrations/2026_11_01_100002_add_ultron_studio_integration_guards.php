<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GUARDAS DEL HITO 6 DE ULTRON STUDIO EN LA BASE. Contrato:
 * docs/ultron-studio/ULTRON_STUDIO_H6_CONTRACT.md, §I3.1–I3.2, §I4.1–I4.2,
 * §I5.3 y §I8. Mismo patrón que las de F (2026_10_18_100002), el laboratorio
 * (2026_10_19_100003) y el Hito 5 (2026_10_25_100002).
 *
 * Es la tercera capa: 1) ninguna ruta borra ni reescribe; 2) los modelos lo
 * impiden; 3) esto, que vale aunque alguien escriba sin pasar por el código
 * (query builder crudo, SQL a mano).
 *
 * `ultron_studio_campaign_links` (un periodo de asociación):
 *  · No se borra ni se trunca. Lo que fija la asociación (LINK_IMMUTABLE_COLUMNS)
 *    no cambia nunca (`BEFORE UPDATE OF`, por lista de columnas: escribir el
 *    MISMO valor también falla).
 *  · `uscl_inactive_is_final`: con la fila inactiva, su cierre ya no cambia.
 *  · `uscl_replacement_once`: `replaced_by_link_id` se anota UNA vez, de NULL a
 *    un valor, y solo si la asociación queda inactiva; anotada, no se vuelve a
 *    escribir. DECISIÓN FUERA DEL CONTRATO
 *    (informe-db.md, D-1): el contrato pone `uscl_inactive_is_final` sobre
 *    CUALQUIER UPDATE, y así la sustitución de §I3.4 (paso 11) es imposible en
 *    los dos motores: la nueva no se puede insertar con la vieja aún activa
 *    (`uscl_one_active`), ni la vieja apuntar a una nueva que todavía no existe
 *    (FK inmediata), ni anotarse después (la vieja ya es inactiva). Con las dos
 *    guardas, la secuencia «desactivar la vieja → insertar la nueva → anotar la
 *    nueva en la vieja» pasa y todo lo demás sigue prohibido.
 *  · Solo en PG: CHECK de dominio, `uscl_target_chk` y `uscl_deactivation_chk`.
 *  · Único PARCIAL `uscl_one_active` (una activa por campaña), en los dos.
 *
 * `ultron_studio_canary_numbers` (el registro de números canarios):
 *  · No se borra ni se trunca; lo inmutable de su registro no cambia; una fila
 *    revocada no admite NINGÚN UPDATE (`uscn_revoked_is_final`, mira OLD.status:
 *    la transición que revoca pasa y tiene que llevar todo en ese UPDATE).
 *  · `uscn_cipher_only_shrinks`: el cifrado solo se borra (a NULL); nunca se
 *    escribe donde no lo había ni se cambia (el cast `encrypted` no puede
 *    volver a cifrar con un `save()` del modelo entero).
 *  · Solo en PG: 12 CHECK, entre ellos los DOS de cuatro ojos (autorizar y
 *    activar ≠ quien registró) y los que obligan a borrar el cifrado al revocar.
 *  · Único PARCIAL `uscn_one_live`: una fila viva por canal y teléfono.
 *
 * `ultron_studio_integration_snapshots`: solo inserción (UPDATE, DELETE y, en
 * PG, TRUNCATE fallan) y, solo en PG, 6 CHECK. `usis_outcome_chk` lleva un
 * `outcome IS NOT NULL` que el texto del contrato no tiene (D-2): sin él, una
 * sonda con `outcome` NULL pasaba el CHECK (NULL OR FALSE es NULL, y un CHECK
 * solo rechaza FALSE).
 *
 * `ultron_studio_audit_events` (el libro de F), SOLO EN PG: `usae_subject_type_chk`
 * pasa de 4 a 7 sujetos (§I8) con DROP + ADD en UNA sentencia `ALTER TABLE`: un
 * solo cerrojo y ningún instante sin CHECK; valida las filas existentes, que ya
 * cumplían los 4. En SQLite ese CHECK no existe (F lo añade solo en PG), así que
 * la tabla NO SE TOCA: ni se reconstruye ni pierde el WHERE de nada (memoria
 * «SQLite reconstruye y pierde el WHERE»; el libro no tiene índices parciales,
 * y la prueba de la migración comprueba que su definición no cambia).
 *
 * Los índices parciales van con SQL crudo y el MISMO texto en los dos motores;
 * SQLite lo guarda tal cual en `sqlite_master`. Una segunda fila viva es 23505
 * en PG; en SQLite, 23000 «UNIQUE constraint failed».
 *
 * FUNCIÓN DE F, `ultron_studio_forbid()`: los disparadores la reutilizan (§I3.2)
 * y esta migración la vuelve a declarar con `CREATE OR REPLACE` y el MISMO
 * cuerpo que F, byte a byte: es idempotente, no cambia su dueño ni su
 * comportamiento y garantiza que existe. down() no la borra: es de F. Qué ve
 * quien choca (el mismo texto en los dos motores, empieza por «ultron_studio:»):
 *  · PostgreSQL: SQLSTATE P0001; un CHECK violado es 23514.
 *  · SQLite: `RAISE(ABORT, …)` → SQLSTATE 23000 (código 19, SQLITE_CONSTRAINT).
 *
 * SIN TOLERAR ERRORES: la protección es un requisito. Si algo no se puede crear
 * (el rol no es dueño de la función, `usae_subject_type_chk` no existe, ya hay
 * dos filas vivas del mismo teléfono…), la migración falla y no queda
 * registrada. Con un driver que no sea pgsql ni sqlite también falla. En PG
 * Laravel envuelve la migración en una transacción; en SQLite no la abre, así
 * que la abre esta migración para no dejar nunca la mitad.
 *
 * Cerrojos (PG), en este orden: las tres tablas nuevas (nadie las usa hasta
 * publicar el código) y AL FINAL el libro, para tener su cerrojo el menor
 * tiempo posible. `ALTER TABLE … DROP/ADD CONSTRAINT` pide ACCESS EXCLUSIVE
 * sobre `ultron_studio_audit_events`: choca con CUALQUIER transacción abierta
 * que lo haya tocado, también una que solo lo haya LEÍDO. Mientras espera, las
 * lecturas y escrituras nuevas del libro hacen cola detrás. Con el
 * `lock_timeout` de `ironbody-migrate` (5 s) falla limpia (55P03), no queda
 * registrada y se reintenta. El motor no lee ni escribe el libro: la cola, si
 * la hay, es solo del Studio. `CREATE INDEX` (no CONCURRENTLY: no cabe en la
 * transacción de la migración y las tablas están vacías) pide SHARE sobre
 * tablas nuevas. Ningún ALTER sobre tablas del motor.
 *
 * Las listas van escritas aquí y no tomadas de los modelos: esta migración se
 * aplica ANTES de publicar el código. up() comprueba que cada columna de
 * asociaciones y canarios está clasificada, mutable o inmutable, y solo una vez
 * (salvo con `migrate --pretend`, que no crea nada: así sirve de vista previa).
 *
 * Lo que NO cubre (igual que F y H5):
 *  · El dueño de las tablas puede `ALTER TABLE … DISABLE TRIGGER` o borrar el
 *    disparador. El rol de la aplicación, solo DML, no puede (42501).
 *  · SQLite: `INSERT OR REPLACE` borra la fila en conflicto SIN disparar el
 *    DELETE (con recursive_triggers apagado, el valor por defecto). Laravel no
 *    lo usa: `upsert()` compila a ON CONFLICT DO UPDATE, que dispara el UPDATE.
 *  · Los CHECK solo existen en PG: en SQLite (la suite) los vigila el servicio,
 *    y la puerta PG (`ops/pgunit.sh`) es obligatoria antes de desplegar.
 *  · Que la sustituta sea de la misma campaña, que solo se escriban las
 *    transiciones de §I4.4 o que la versión esperada sea asociable: lo
 *    garantiza el servicio, en la misma transacción.
 *
 * down() SE NIEGA si hay alguna fila en las tres tablas o algún evento del
 * libro con un sujeto del Hito 6 (contrato, «Reversión», nivel 4), antes de
 * tocar nada y con los cerrojos ya tomados (comprobar y deshacer es atómico).
 * Sin filas: devuelve `usae_subject_type_chk` a los 4 sujetos y quita índices
 * parciales, disparadores y CHECK, con IF EXISTS.
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'ultron_studio:';

    /** La de F (2026_10_18_100002). */
    public const FUNCTION_NAME = 'ultron_studio_forbid';

    public const LINKS = 'ultron_studio_campaign_links';

    public const CANARIES = 'ultron_studio_canary_numbers';

    public const SNAPSHOTS = 'ultron_studio_integration_snapshots';

    public const AUDIT = 'ultron_studio_audit_events';

    /** Las tablas nuevas, en el orden de 2026_11_01_100001. */
    public const TABLES = [self::LINKS, self::CANARIES, self::SNAPSHOTS];

    /** Lo que fija una asociación al crearse (§I3.2). Incluye `id`: nadie cambia una clave primaria. */
    public const LINK_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'ad_account_id', 'campaign_id', 'campaign_name_at_link',
        'target_kind', 'version_id', 'version_content_hash',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    /** El cierre del periodo: con la fila inactiva, ninguna vuelve a cambiar (`uscl_inactive_is_final`). */
    public const LINK_CLOSING_COLUMNS = [
        'status', 'deactivated_at', 'deactivated_by_admin_id', 'deactivated_by_name', 'deactivated_by_role', 'deactivation_reason',
    ];

    /** Las mutables de §I3.2: el cierre y la sustituta, que se anota una vez (`uscl_replacement_once`). */
    public const LINK_MUTABLE_COLUMNS = [...self::LINK_CLOSING_COLUMNS, 'replaced_by_link_id'];

    /** Lo que fija el registro de un número (§I4.2). */
    public const CANARY_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'channel', 'alias', 'phone_hash', 'phone_last4', 'country_code',
        'consent_attested', 'registered_by_admin_id', 'registered_by_name', 'registered_by_role', 'created_at',
    ];

    /** Lo que mueve la máquina de estados (§I4.4); el cifrado solo hacia NULL. */
    public const CANARY_MUTABLE_COLUMNS = [
        'phone_encrypted', 'status', 'expected_version_id',
        'authorized_by_admin_id', 'authorized_by_name', 'authorized_by_role', 'authorized_at',
        'activated_by_admin_id', 'activated_by_name', 'activated_by_role', 'activated_at',
        'revoked_by_admin_id', 'revoked_by_name', 'revoked_by_role', 'revoked_at', 'revoke_reason',
        'status_changed_at', 'updated_at',
    ];

    /**
     * Disparadores de fila, en los dos motores:
     * nombre → [tabla, evento, columnas de `UPDATE OF` (o null), condición WHEN (o null), motivo].
     * La condición es la misma en los dos motores.
     */
    public const ROW_TRIGGERS = [
        'uscl_forbid_delete' => [self::LINKS, 'DELETE', null, null, 'las asociaciones de campaña no se borran'],
        'uscl_forbid_immutable_update' => [self::LINKS, 'UPDATE', self::LINK_IMMUTABLE_COLUMNS, null,
            'una asociación de campaña es inmutable; solo cambian su estado, su cierre y la asociación que la sustituye'],
        'uscl_inactive_is_final' => [self::LINKS, 'UPDATE', self::LINK_CLOSING_COLUMNS, "OLD.status = 'inactive'",
            'una asociación de campaña inactiva es definitiva'],
        // Anotada, ya no se escribe (ni con el mismo valor); sin anotar, solo
        // admite un valor si la fila queda inactiva. Escribir NULL sobre NULL
        // no cambia nada y pasa.
        'uscl_replacement_once' => [self::LINKS, 'UPDATE', ['replaced_by_link_id'],
            "OLD.replaced_by_link_id IS NOT NULL OR (NEW.replaced_by_link_id IS NOT NULL AND NEW.status <> 'inactive')",
            'la asociación que sustituye a otra se anota una sola vez, y solo en una asociación inactiva'],
        'uscn_forbid_delete' => [self::CANARIES, 'DELETE', null, null, 'los números canarios se revocan, no se borran'],
        'uscn_forbid_immutable_update' => [self::CANARIES, 'UPDATE', self::CANARY_IMMUTABLE_COLUMNS, null,
            'un número canario es inmutable en su canal, su alias, su huella, su consentimiento y su registro'],
        'uscn_revoked_is_final' => [self::CANARIES, 'UPDATE', null, "OLD.status = 'revoked'",
            'un número canario revocado es definitivo'],
        'uscn_cipher_only_shrinks' => [self::CANARIES, 'UPDATE', ['phone_encrypted'],
            'NEW.phone_encrypted IS NOT NULL AND (OLD.phone_encrypted IS NULL OR NEW.phone_encrypted <> OLD.phone_encrypted)',
            'el cifrado de un número canario solo se borra: no se escribe ni se cambia después del registro'],
        'usis_forbid_update' => [self::SNAPSHOTS, 'UPDATE', null, null, 'las instantáneas de integración son de solo inserción'],
        'usis_forbid_delete' => [self::SNAPSHOTS, 'DELETE', null, null, 'las instantáneas de integración son de solo inserción'],
    ];

    /** Disparadores de sentencia contra TRUNCATE, solo en PG: nombre → [tabla, motivo]. */
    public const TRUNCATE_TRIGGERS = [
        'uscl_forbid_truncate' => [self::LINKS, 'las asociaciones de campaña no se borran'],
        'uscn_forbid_truncate' => [self::CANARIES, 'los números canarios se revocan, no se borran'],
        'usis_forbid_truncate' => [self::SNAPSHOTS, 'las instantáneas de integración son de solo inserción'],
    ];

    /** CHECK de dominio, solo en PG: tabla → [nombre → [columna, valores]]. */
    public const DOMAIN_CHECKS = [
        self::LINKS => [
            'uscl_status_chk' => ['status', ['active', 'inactive']],
            'uscl_target_kind_chk' => ['target_kind', ['baseline', 'version']],
        ],
        self::CANARIES => [
            'uscn_channel_chk' => ['channel', ['whatsapp', 'voice']],
            'uscn_status_chk' => ['status', ['pending', 'authorized', 'active', 'revoked']],
        ],
        self::SNAPSHOTS => [
            'usis_provider_chk' => ['provider', ['meta_ads', 'twilio', 'voice']],
            'usis_kind_chk' => ['kind', ['config', 'probe']],
            'usis_actor_type_chk' => ['actor_type', ['admin', 'system']],
        ],
    ];

    /**
     * CHECK entre columnas, solo en PG: tabla → [nombre → expresión]. Todas
     * las columnas que nombran sin `IS [NOT] NULL` son NOT NULL, así que
     * ninguna expresión da NULL (que un CHECK aceptaría).
     */
    public const ROW_CHECKS = [
        self::LINKS => [
            'uscl_target_chk' => "(target_kind = 'baseline' AND version_id IS NULL AND version_content_hash IS NULL) OR (target_kind = 'version' AND version_id IS NOT NULL AND version_content_hash IS NOT NULL)",
            'uscl_deactivation_chk' => "status = 'active' OR (deactivated_at IS NOT NULL AND deactivated_by_admin_id IS NOT NULL AND deactivation_reason IS NOT NULL)",
        ],
        self::CANARIES => [
            'uscn_consent_chk' => 'consent_attested = true',
            'uscn_hash_chk' => "phone_hash ~ '^[0-9a-f]{64}$'",
            'uscn_last4_chk' => "phone_last4 ~ '^[0-9]{4}$'",
            'uscn_whatsapp_no_cipher_chk' => "channel = 'voice' OR phone_encrypted IS NULL",
            'uscn_voice_cipher_chk' => "channel = 'whatsapp' OR status = 'revoked' OR phone_encrypted IS NOT NULL",
            'uscn_four_eyes_chk' => 'authorized_by_admin_id IS NULL OR authorized_by_admin_id <> registered_by_admin_id',
            'uscn_activation_four_eyes_chk' => 'activated_by_admin_id IS NULL OR activated_by_admin_id <> registered_by_admin_id',
            'uscn_authorized_chk' => "status IN ('pending', 'revoked') OR authorized_by_admin_id IS NOT NULL",
            'uscn_active_chk' => "status <> 'active' OR activated_by_admin_id IS NOT NULL",
            'uscn_revoke_chk' => "status <> 'revoked' OR (revoked_at IS NOT NULL AND revoked_by_admin_id IS NOT NULL AND revoke_reason IS NOT NULL AND phone_encrypted IS NULL)",
        ],
        self::SNAPSHOTS => [
            'usis_fingerprint_chk' => "fingerprint ~ '^[0-9a-f]{64}$'",
            // `outcome IS NOT NULL`: D-2 (ver la cabecera).
            'usis_outcome_chk' => "(kind = 'probe' AND outcome IS NOT NULL AND outcome IN ('ok', 'degraded', 'auth_failed', 'unreachable', 'error')) OR (kind = 'config' AND outcome IS NULL)",
            'usis_actor_chk' => "actor_type = 'system' OR actor_admin_id IS NOT NULL",
        ],
    ];

    /** Índices únicos parciales, en los dos motores: nombre → [tabla, columnas, predicado]. */
    public const PARTIAL_UNIQUE_INDEXES = [
        'uscl_one_active' => [self::LINKS, ['ad_account_id', 'campaign_id'], "status = 'active'"],
        'uscn_one_live' => [self::CANARIES, ['channel', 'phone_hash'], "status <> 'revoked'"],
    ];

    public const SUBJECT_CHECK = 'usae_subject_type_chk';

    /** Lo que admitía `usae_subject_type_chk` hasta el Hito 5 (F); a esto vuelve down(). */
    public const H5_SUBJECT_TYPES = ['draft', 'version', 'rule', 'system'];

    /** Lo que solo existe con el Hito 6 (§I8); con un evento así, down() se niega. */
    public const H6_SUBJECT_TYPES = ['campaign_link', 'canary_number', 'integration'];

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
     * Los 7 sujetos que admite `usae_subject_type_chk` con el Hito 6.
     *
     * @return list<string>
     */
    public static function h6SubjectTypes(): array
    {
        return [...self::H5_SUBJECT_TYPES, ...self::H6_SUBJECT_TYPES];
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

        foreach (self::ROW_TRIGGERS as $name => [$table, $event, $columns, $when, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns)." ON {$table} "
                .'FOR EACH ROW '.self::whenClause($when)
                .'EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
        }
        foreach (self::TRUNCATE_TRIGGERS as $name => [$table, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE TRUNCATE ON {$table} "
                .'FOR EACH STATEMENT EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
        }

        $this->createPartialUniqueIndexes();

        // El libro, al final: su ACCESS EXCLUSIVE dura lo menos posible.
        $this->replaceSubjectCheck(self::h6SubjectTypes(), ifExists: false);
    }

    private function downPostgres(): void
    {
        // Los cerrojos que va a pedir lo que sigue, tomados ANTES de comprobar:
        // así nadie escribe entre el recuento y el primer DROP. Mismo orden que
        // up(). Con `--pretend` las lecturas devuelven vacío: se escribe igual,
        // para que la vista previa enseñe el cerrojo.
        $pretending = DB::connection()->pretending();
        $tables = array_values(array_filter(self::TABLES, static fn (string $table): bool => $pretending || Schema::hasTable($table)));
        DB::statement('LOCK TABLE '.implode(', ', [...$tables, self::AUDIT]).' IN ACCESS EXCLUSIVE MODE');

        $this->refuseWithH6Rows();

        $this->replaceSubjectCheck(self::H5_SUBJECT_TYPES, ifExists: true);
        foreach (array_keys(self::PARTIAL_UNIQUE_INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
        foreach ([...self::ROW_TRIGGERS, ...self::TRUNCATE_TRIGGERS] as $name => [$table]) {
            DB::statement("DROP TRIGGER IF EXISTS {$name} ON {$table}");
        }
        foreach ([self::ROW_CHECKS, self::DOMAIN_CHECKS] as $group) {
            foreach ($group as $table => $checks) {
                foreach (array_keys($checks) as $name) {
                    DB::statement("ALTER TABLE IF EXISTS {$table} DROP CONSTRAINT IF EXISTS {$name}");
                }
            }
        }
        // `ultron_studio_forbid()` es de F: se queda.
    }

    private function upSqlite(): void
    {
        $this->assertColumnsClassified();

        foreach (self::ROW_TRIGGERS as $name => [$table, $event, $columns, $when, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns)." ON {$table} "
                .'FOR EACH ROW '.self::whenClause($when)
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, $event, $table)).'); END');
        }

        $this->createPartialUniqueIndexes();
        // El libro no se toca: en SQLite no tiene CHECK que ampliar.
    }

    private function downSqlite(): void
    {
        $this->refuseWithH6Rows();

        foreach (array_keys(self::PARTIAL_UNIQUE_INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
        foreach (array_keys(self::ROW_TRIGGERS) as $name) {
            DB::statement("DROP TRIGGER IF EXISTS {$name}");
        }
    }

    /**
     * Cada columna de asociaciones y canarios es mutable o inmutable,
     * explícitamente y una sola vez. Si alguien añade una columna a la tabla
     * sin clasificarla, la lista nombra una que no existe (SQLite no lo
     * comprobaría en `UPDATE OF`) o una columna está en las dos listas, esto
     * falla antes de crear nada.
     */
    private function assertColumnsClassified(): void
    {
        // `migrate --pretend` no ejecuta nada, tampoco la lectura del esquema
        // (devuelve vacío): sin esto la vista previa del despliegue fallaría
        // aquí. En un `migrate` de verdad la comprobación siempre corre.
        if (DB::connection()->pretending()) {
            return;
        }

        foreach ([
            self::LINKS => [self::LINK_IMMUTABLE_COLUMNS, self::LINK_MUTABLE_COLUMNS],
            self::CANARIES => [self::CANARY_IMMUTABLE_COLUMNS, self::CANARY_MUTABLE_COLUMNS],
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

    /**
     * DROP + ADD en una sola sentencia: un solo cerrojo y ningún instante sin
     * CHECK. En up() sin IF EXISTS: si el CHECK de F no está, el esquema no es
     * el que esta migración espera y tiene que fallar.
     *
     * @param  list<string>  $subjects
     */
    private function replaceSubjectCheck(array $subjects, bool $ifExists): void
    {
        DB::statement('ALTER TABLE '.self::AUDIT
            .' DROP CONSTRAINT '.($ifExists ? 'IF EXISTS ' : '').self::SUBJECT_CHECK
            .', ADD CONSTRAINT '.self::SUBJECT_CHECK.' CHECK (subject_type IN ('.self::literalList($subjects).'))');
    }

    /** El mismo texto en los dos motores; SQLite lo guarda tal cual en `sqlite_master`. */
    private function createPartialUniqueIndexes(): void
    {
        foreach (self::PARTIAL_UNIQUE_INDEXES as $name => [$table, $columns, $predicate]) {
            DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} (".implode(', ', $columns).") WHERE {$predicate}");
        }
    }

    /**
     * Contrato H6, «Reversión», nivel 4: con filas del Hito 6, revertir el
     * esquema dejaría el registro sin sus guardas y el libro con sujetos que su
     * CHECK ya no admite. Se niega con un mensaje claro, antes de tocar nada.
     */
    private function refuseWithH6Rows(): void
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }
        $events = DB::table(self::AUDIT)->whereIn('subject_type', self::H6_SUBJECT_TYPES)->count();

        if (array_sum($rows) > 0 || $events > 0) {
            throw new RuntimeException('ultron_studio (Hito 6): down() se niega a revertir el Hito 6 con datos del Hito 6 '
                ."({$rows[self::LINKS]} asociaciones de campaña, {$rows[self::CANARIES]} números canarios, "
                ."{$rows[self::SNAPSHOTS]} instantáneas de integración y {$events} eventos del libro con los sujetos "
                .implode(', ', self::H6_SUBJECT_TYPES).'). Es registro de solo inserción o con historial, y revertir lo destruiría '
                .'(contrato H6, «Reversión», nivel 4). Para volver al código del Hito 5 no hace falta revertir el esquema (nivel 3).');
        }
    }

    /** @param  list<string>|null  $columns */
    private static function eventClause(string $event, ?array $columns): string
    {
        return $columns === null ? $event : $event.' OF '.implode(', ', $columns);
    }

    /** `WHEN (…) ` con su espacio final, o nada. Igual en los dos motores. */
    private static function whenClause(?string $when): string
    {
        return $when === null ? '' : "WHEN ({$when}) ";
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
        return new RuntimeException("Las guardas del Hito 6 de ULTRON Studio solo existen para pgsql y sqlite, y son obligatorias: con «{$driver}» la migración no se aplica.");
    }
};
