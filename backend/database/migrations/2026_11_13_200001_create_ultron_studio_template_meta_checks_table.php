<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ULTRON STUDIO · HITO 9, paquete W9 (WhatsApp por plantillas): LA
 * CORRESPONDENCIA CON META. Contrato: docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md,
 * D9-6 («Plantillas versionadas»), D9-9.3, §3 protección 12 (el cuarto
 * cerrojo de un canal que gasta: la aprobación externa) y §6.1 («Sin el
 * estado de Meta en el 15-11: llega en W9, con su tabla de solo inserción
 * `ultron_studio_template_meta_checks`»). Informe:
 * plans/informes-h9/informe-w9.md.
 *
 * UNA tabla NUEVA, SOLO ADITIVA E INERTE (protección 11): crearla no enciende
 * nada, ningún código la lee con las banderas por defecto y no la escribe
 * ninguna ruta todavía (el registro lo hace el propietario, ver el informe).
 * De lo que ya existe solo REFERENCIA `ultron_studio_message_templates`, SIN
 * FK a propósito: la migración del gobierno de DB9 se tiene que poder
 * revertir sin conocer esta tabla (sus pruebas lo hacen en PostgreSQL, donde
 * una FK impediría el DROP). La existencia, el canal y la huella los
 * garantiza el disparador al insertar, y una plantilla no se borra nunca
 * (`usmt_forbid_delete` y el REVOKE de DB9). No altera ninguna tabla ni nombra
 * la cadena del H5, la de voz del H8, Marketing, el motor comercial ni n8n.
 *
 *  · ultron_studio_template_meta_checks — SOLO INSERCIÓN. Cada fila dice: «el
 *    día X, la persona Y comprobó en el administrador de WhatsApp de Meta que
 *    la versión T de una plantilla del Studio (a su huella exacta) corresponde
 *    a la plantilla de Meta N, en el idioma L y la categoría C, con este texto
 *    (su huella) y este estado». Manda la ÚLTIMA fila de cada plantilla
 *    (mayor id): un cambio de estado en Meta (pausada, rechazada, desactivada)
 *    es una fila nueva, nunca un UPDATE. El canal de WhatsApp de la
 *    automatización solo envía con la última `APPROVED` que casa con el
 *    contenido del Studio (nombre, idioma, categoría y huella del texto); si
 *    no, `failed_to_start` con `template_not_approved_by_meta`.
 *
 * DATOS PERSONALES (Ley 1581). Ninguna columna lleva teléfono, documento,
 * nombre de una persona contactada ni el TEXTO de la plantilla: del texto que
 * aprobó Meta solo su huella (sha256 de los bytes UTF-8, `meta_body_hash`); el
 * texto vive en el Studio (`bodyPreview`). Quien registra es la instantánea de
 * F (id + nombre del PERSONAL, sin FK a `admins`).
 *
 * LAS GUARDAS (tercera capa; valen aunque se escriba con SQL a mano):
 *  · Disparadores de fila en los dos motores con el mismo nombre y el mismo
 *    texto («ultron_studio: … (OPERACIÓN en tabla)»): en PG con la función de
 *    F, `ultron_studio_forbid()`, que esta migración NO redeclara (su md5 no
 *    cambia); en SQLite, `RAISE(ABORT, …)`. PG suma uno de sentencia contra
 *    TRUNCATE.
 *  · La guarda ENTRE TABLAS (la comprobación es de una plantilla de WHATSAPP
 *    y de su huella exacta): en PG una función PROPIA
 *    (`ultron_studio_chk_ustmc_template_of_whatsapp`), plpgsql, INVOKER (sin
 *    SECURITY DEFINER), con la tabla cualificada con `public.`; en SQLite, la
 *    misma condición en el WHEN.
 *  · Solo en PG, los CHECK de dominio (estado, idioma, categoría) y de forma
 *    (huellas, nombre de Meta, id de Meta, quién, cuándo). En SQLite los vigila
 *    el código y la puerta PG es obligatoria.
 *
 * EL DUEÑO Y EL ROL DE LA APLICACIÓN. Las tablas son del rol que migra (el
 * dueño); el rol de la aplicación (`iron_app`, o `ULTRON_STUDIO_DB_APP_ROLE`
 * en el entorno del proceso que migra: la variable de DB7/DB8/DB9) pierde
 * UPDATE y DELETE y después se COMPRUEBA que ya no puede (por tabla, por
 * columna y por pertenencia a otros roles). Sin el rol en el clúster no hay
 * nada que revocar, salvo en `APP_ENV=production`, donde es un error. El
 * dueño SÍ puede desactivar los disparadores y devolver privilegios: eso no lo
 * cubre ninguna migración.
 *
 * CERROJOS (PG). Si la sesión no trae `lock_timeout`, `SET LOCAL lock_timeout
 * = '5s'`: nunca espera sin límite. Sin FK, crear la tabla no pide cerrojo
 * sobre ninguna tabla existente.
 *
 * SIN TOLERAR ERRORES: si algo no se puede crear o comprobar, la migración
 * falla y no queda registrada. Con un driver que no sea pgsql ni sqlite,
 * falla. `CREATE OR REPLACE FUNCTION`: `migrate:fresh` borra tablas pero no
 * funciones. Con `migrate --pretend` no se lee nada.
 *
 * down() SE NIEGA si hay alguna fila, antes de tocar nada y con el cerrojo ya
 * tomado, y NUNCA borra filas. Sin filas borra la tabla y su función propia;
 * la de F se queda. En producción no se ejecuta nunca (protección 11).
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'ultron_studio:';

    /** La de F (2026_10_18): esta migración la usa y no la redeclara. */
    public const FUNCTION_NAME = 'ultron_studio_forbid';

    public const CHECK_FUNCTION_PREFIX = 'ultron_studio_chk_';

    public const TABLE = 'ultron_studio_template_meta_checks';

    /** Del gobierno (2026_11_12_100001): solo se referencia. */
    public const TEMPLATES = 'ultron_studio_message_templates';

    public const APP_ROLE = 'iron_app';

    public const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    public const LOCK_TIMEOUT = '5s';

    /** Los estados de una plantilla en Meta que se registran (los de su administrador). */
    public const STATUSES = ['APPROVED', 'REJECTED', 'PAUSED', 'DISABLED'];

    /** Como `StudioMessageTemplates::LANGUAGES` y `::CATEGORIES`. */
    public const LANGUAGES = ['es', 'es_CO'];

    public const CATEGORIES = ['marketing', 'utility'];

    public const REASON = 'las comprobaciones de plantillas en Meta son de solo inserción';

    /** CHECK de dominio, solo en PG: nombre → [columna, valores]. */
    public const DOMAIN_CHECKS = [
        'ustmc_status_chk' => ['status', self::STATUSES],
        'ustmc_language_chk' => ['language', self::LANGUAGES],
        'ustmc_category_chk' => ['category', self::CATEGORIES],
    ];

    /** CHECK de forma, solo en PG (ninguna expresión puede dar NULL: todas las columnas son NOT NULL salvo la protegida). */
    public const SHAPE_CHECK = 'ustmc_shape_chk';

    /** Disparadores de fila en los dos motores: nombre → [evento, motivo]. */
    public const ROW_TRIGGERS = [
        'ustmc_forbid_update' => ['UPDATE', self::REASON],
        'ustmc_forbid_delete' => ['DELETE', self::REASON],
    ];

    public const TRUNCATE_TRIGGER = 'ustmc_forbid_truncate';

    /**
     * La guarda entre tablas: [evento, violación (con `%T%` = `public.` en PG
     * y nada en SQLite), motivo]. Una comprobación nace de una plantilla de
     * WhatsApp que existe y a SU huella exacta (la versión que se revisó).
     */
    public const CROSS_TRIGGER = 'ustmc_template_of_whatsapp';

    public const CROSS_VIOLATION = 'NOT EXISTS (SELECT 1 FROM %T%'.self::TEMPLATES." AS t WHERE t.id = NEW.template_id AND t.channel = 'whatsapp' AND t.content_hash = NEW.template_content_hash)";

    public const CROSS_REASON = 'una comprobación de Meta es de una plantilla de WhatsApp del Studio y de su huella exacta';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Laravel ya la envuelve en una transacción; esta (un SAVEPOINT
            // dentro de ella) hace up() atómico también fuera del migrador.
            DB::transaction(fn () => $this->upPostgres());

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

    /** El rol de la aplicación al que se revoca (como DB9): solo minúsculas, dígitos y `_`, hasta 63. */
    public static function appRole(): string
    {
        $env = getenv(self::APP_ROLE_ENV);
        $role = is_string($env) && $env !== '' ? $env : self::APP_ROLE;
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new RuntimeException('ultron_studio (Hito 9, W9): '.self::APP_ROLE_ENV.' no es un nombre de rol válido (minúsculas, dígitos y _, hasta 63).');
        }

        return $role;
    }

    public static function crossFunction(): string
    {
        return self::CHECK_FUNCTION_PREFIX.self::CROSS_TRIGGER;
    }

    /** La expresión del CHECK de forma (solo PG). */
    public static function shapeCheck(): string
    {
        return "template_content_hash ~ '^[0-9a-f]{64}$' AND meta_body_hash ~ '^[0-9a-f]{64}$' "
            ."AND meta_name ~ '^[a-z0-9_]{1,64}$' AND (meta_template_id IS NULL OR meta_template_id ~ '^[0-9]{1,32}$') "
            .'AND recorded_by_admin_id > 0 AND length(btrim(recorded_by_name)) > 0 AND checked_at <= created_at';
    }

    private function createTable(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('ustmc_uuid_unique');
            // Idempotencia de quien registra (como el resto del gobierno).
            $table->uuid('client_request_id')->unique('ustmc_client_request_unique');
            // La versión de la plantilla del Studio y su huella exacta: referencia SIN FK (ver la cabecera); la guarda entre tablas lo comprueba.
            $table->unsignedBigInteger('template_id');
            $table->string('template_content_hash', 64);
            // Lo que dice Meta: nombre, idioma, categoría, la huella del texto aprobado, su id y su estado.
            $table->string('meta_name', 64);
            $table->string('language', 5);
            $table->string('category', 10);
            $table->string('meta_body_hash', 64);
            $table->string('meta_template_id', 32)->nullable();
            $table->string('status', 10);
            // Cuándo se comprobó en el administrador de Meta (no puede ser después de registrarlo).
            $table->timestamp('checked_at');
            // Quién lo registró: la instantánea de F (sin FK a `admins`).
            $table->unsignedBigInteger('recorded_by_admin_id');
            $table->string('recorded_by_name', 120);
            $table->timestamp('created_at');

            // La última comprobación de cada plantilla (el canal lee una fila).
            $table->index(['template_id', 'id'], 'ustmc_template_latest_idx');
        });
    }

    private function upPostgres(): void
    {
        $this->boundLockWait();
        $this->createTable();

        foreach (self::DOMAIN_CHECKS as $name => [$column, $values]) {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT {$name} CHECK ({$column} IN (".self::literalList($values).'))');
        }
        DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::SHAPE_CHECK.' CHECK ('.self::shapeCheck().')');

        foreach (self::ROW_TRIGGERS as $name => [$event, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE.' '
                .'FOR EACH ROW EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
        }
        DB::statement('CREATE TRIGGER '.self::TRUNCATE_TRIGGER.' BEFORE TRUNCATE ON '.self::TABLE.' '
            .'FOR EACH STATEMENT EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal(self::REASON).')');

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
            .'FOR EACH ROW EXECUTE FUNCTION '.self::crossFunction().'('.self::literal(self::CROSS_REASON).')');

        $this->revokeAppWrites();
    }

    private function downPostgres(): void
    {
        $this->boundLockWait();
        // El cerrojo ANTES de contar: nadie escribe entre el recuento y el DROP.
        if (DB::connection()->pretending() || Schema::hasTable(self::TABLE)) {
            DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
        }

        $this->refuseWithRows();

        // Con la tabla se van sus disparadores, CHECK, índices y privilegios; después, su función. La de F se queda.
        Schema::dropIfExists(self::TABLE);
        DB::statement('DROP FUNCTION IF EXISTS '.self::crossFunction().'()');
    }

    private function upSqlite(): void
    {
        $this->createTable();

        foreach (self::ROW_TRIGGERS as $name => [$event, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE.' FOR EACH ROW '
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, $event, self::TABLE)).'); END');
        }
        DB::statement('CREATE TRIGGER '.self::CROSS_TRIGGER.' BEFORE INSERT ON '.self::TABLE.' FOR EACH ROW '
            .'WHEN ('.self::dialect(self::CROSS_VIOLATION, 'sqlite').') '
            .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message(self::CROSS_REASON, 'INSERT', self::TABLE)).'); END');
        // Sin roles: no hay privilegios que revocar.
    }

    private function downSqlite(): void
    {
        $this->refuseWithRows();

        Schema::dropIfExists(self::TABLE);
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
     * REVOKE del rol de la aplicación (UPDATE y DELETE: la tabla es de solo
     * inserción) y la comprobación de que de verdad ya no puede. Sin el rol en
     * el clúster no hay nada que revocar, salvo en producción, donde es un
     * error.
     */
    private function revokeAppWrites(): void
    {
        $role = self::appRole();
        $sentencia = 'REVOKE UPDATE, DELETE ON '.self::TABLE.' FROM "'.$role.'"';

        if (DB::connection()->pretending()) {
            DB::statement($sentencia);

            return;
        }
        if (! self::roleExists($role)) {
            if (app()->environment('production')) {
                throw new RuntimeException("ultron_studio (Hito 9, W9): el rol de la aplicación «{$role}» no existe en este clúster y APP_ENV es production: "
                    .'el REVOKE sobre las comprobaciones de Meta no se puede aplicar. La migración no se registra; '
                    .'si el rol se llama de otra forma, '.self::APP_ROLE_ENV.' en el entorno del proceso que migra.');
            }

            return;
        }

        DB::statement($sentencia);

        $puede = DB::selectOne(
            "SELECT has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d",
            [$role, 'public.'.self::TABLE, $role, 'public.'.self::TABLE],
        );
        if ((int) $puede->u === 1 || (int) $puede->d === 1) {
            throw new RuntimeException("ultron_studio (Hito 9, W9): después del REVOKE, «{$role}» todavía puede escribir en ".self::TABLE
                .' (heredado de otro rol o de PUBLIC, o concedido por alguien que no es el rol que migra). La migración no se registra.');
        }
    }

    private static function roleExists(string $role): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]) !== null;
    }

    /**
     * Protección 11: con filas, revertir el esquema destruiría el registro de
     * lo que Meta aprobó (DROP no pasa por los disparadores de fila). Se niega
     * con un mensaje claro, antes de tocar nada, y nunca borra.
     */
    private function refuseWithRows(): void
    {
        $filas = Schema::hasTable(self::TABLE) ? DB::table(self::TABLE)->count() : 0;
        if ($filas > 0) {
            throw new RuntimeException("ultron_studio (Hito 9, W9): down() se niega a revertir las comprobaciones de plantillas en Meta con datos ({$filas} filas). "
                .'Revertirlo las destruiría y nunca borra nada. Para volver al código anterior no hace falta revertir el esquema (§9.2, nivel 3: la tabla se queda).');
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

    private static function unsupported(string $driver): RuntimeException
    {
        return new RuntimeException("Las comprobaciones de plantillas en Meta del Hito 9 (W9) solo existen para pgsql y sqlite, y sus guardas son obligatorias: con «{$driver}» la migración no se aplica.");
    }
};
