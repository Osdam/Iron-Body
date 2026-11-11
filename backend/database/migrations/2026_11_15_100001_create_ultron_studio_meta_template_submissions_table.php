<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ULTRON STUDIO · PLANTILLAS DE WHATSAPP DE META DESDE EL CRM, paquete 1a
 * (`database-specialist`): LOS ENVÍOS A META. Plan:
 * docs/ultron-studio/plans/PLANTILLAS_META_PLAN.md, §5.4 (ciclo de vida),
 * §5.6 (quién da fe) y §5.7.1; contrato H9, fila L9-96 (enmienda de §6.1).
 * Informe: plans/informes-plantillas/informe-db.md.
 *
 * UNA tabla NUEVA, SOLO ADITIVA E INERTE (protección 11): crearla no enciende
 * nada y ningún código la escribe con las banderas por defecto
 * (`ULTRON_STUDIO_META_TEMPLATES_*` apagadas). De lo que ya existe solo
 * REFERENCIA, SIN FK y a propósito (como W9, `2026_11_13_200001`), dos tablas
 * del gobierno de DB9: `ultron_studio_message_templates` (la versión enviada)
 * y `ultron_studio_person_bindings` (quién la envió); DB9 se tiene que poder
 * revertir sin conocer esta tabla. No altera ninguna tabla ni nombra la cadena
 * del H5, la voz del H8, Marketing, el motor comercial, n8n ni el webhook.
 *
 *  · ultron_studio_meta_template_submissions — una fila por cada versión de
 *    plantilla del Studio enviada a Meta. Nace SENDING, sin nada observado de
 *    Meta, justo antes del POST (fuera de cualquier transacción con la red);
 *    después solo avanza: SENDING → SUBMITTED | FAILED | UNKNOWN y UNKNOWN →
 *    SUBMITTED | FAILED. FAILED es definitivo y libera la versión (y su nombre)
 *    para volver a enviarla en una fila nueva. Lo que Meta dice de ella
 *    (estado, categoría, motivo, calidad, huellas, si casa con el Studio) es
 *    lo ÚLTIMO observado y se sobrescribe; el historial va al libro
 *    (`ultron_studio_audit_events`, sujeto `message_template`). Lo que manda
 *    para el canal de W9 NO está aquí: está en `ultron_studio_template_meta_checks`.
 *
 * DATOS PERSONALES (Ley 1581). Ninguna columna lleva teléfono, documento,
 * nombre de un socio ni texto de la plantilla: de lo enviado, el nombre en
 * Meta, el idioma, la categoría y la huella del cuerpo canónico
 * (`request_hash`); de lo observado, códigos y huellas. Quien envía es la
 * instantánea de F (id + nombre + rol del PERSONAL, sin FK a `admins`) y su
 * persona (`identities.id`, nunca su documento). `error_user_message` es el
 * `error_user_msg` de Meta saneado por el servicio (una línea, ≤ 300), nunca el
 * cuerpo crudo de la respuesta.
 *
 * LAS GUARDAS (tercera capa; valen aunque se escriba con SQL a mano):
 *  · Disparadores de fila en los dos motores con el mismo nombre y el mismo
 *    texto («ultron_studio: … (OPERACIÓN en tabla)»): en PG con la función de
 *    F, `ultron_studio_forbid()`, que esta migración NO redeclara (su md5 no
 *    cambia); en SQLite, `RAISE(ABORT, …)`. PG suma uno de sentencia contra
 *    TRUNCATE. Columnas inmutables por LISTA (`BEFORE UPDATE OF …`),
 *    clasificadas una sola vez (up() lo comprueba); «solo hacia delante» para
 *    `state`, «definitivo» para FAILED y «una vez» para `meta_template_id`.
 *  · Las guardas ENTRE TABLAS: la versión enviada es una plantilla de WhatsApp
 *    del Studio APROBADA, de su huella exacta y con su nombre en Meta, su
 *    idioma y su categoría; quien envía es una persona vinculada (vínculo
 *    verificado y vivo). En PG, una función PROPIA por guarda
 *    (`ultron_studio_chk_*`), plpgsql, INVOKER (sin SECURITY DEFINER) y con las
 *    tablas CUALIFICADAS con `public.`; en SQLite, la misma condición en el
 *    WHEN (el contenido, con `json_extract`).
 *  · Solo en PG, los CHECK de dominio (estado, idioma, categoría) y de forma
 *    (huellas, nombre e id de Meta, lo observado en `[A-Z_]`, la coherencia del
 *    estado con lo observado, «casa / no casa y por qué», el error y quién).
 *    En SQLite los vigila el servicio y la puerta PG es obligatoria.
 *  · Índices únicos PARCIALES con el MISMO texto en los dos motores: un envío
 *    vivo (no FAILED) por versión y un nombre de Meta + idioma vivo por versión.
 *
 * EL DUEÑO Y EL ROL DE LA APLICACIÓN. La tabla es del rol que migra; el rol de
 * la aplicación (`iron_app`, o `ULTRON_STUDIO_DB_APP_ROLE` en el entorno del
 * proceso que migra: la variable de DB7/DB8/DB9/W9) pierde DELETE (ninguna
 * fila de envío se borra; conserva UPDATE: la sincronización la avanza) y
 * después se COMPRUEBA que ya no puede (por tabla y por pertenencia a otros
 * roles). Sin el rol en el clúster no hay nada que revocar, salvo en
 * `APP_ENV=production`, donde es un error. El dueño SÍ puede desactivar los
 * disparadores y devolver privilegios: eso no lo cubre ninguna migración.
 *
 * CERROJOS (PG). Si la sesión no trae `lock_timeout` (el de `ironbody-migrate`
 * es de 5 s), `SET LOCAL lock_timeout = '5s'`: nunca espera sin límite. Sin FK,
 * crear la tabla no pide cerrojo sobre ninguna tabla existente.
 *
 * RECUENTOS: antes y después, en `ultron_studio_%`, exactamente +1 tabla, +2
 * parciales, +9 disparadores en PG (+8 en SQLite) y +10 CHECK en PG, y sobre
 * la tabla exactamente los nombres declarados aquí; si no, falla y no se
 * registra.
 *
 * SIN TOLERAR ERRORES: si algo no se puede crear o comprobar, la migración
 * falla y no queda registrada. Con un driver que no sea pgsql ni sqlite,
 * falla. `CREATE OR REPLACE FUNCTION`: `migrate:fresh` borra tablas pero no
 * funciones. Con `migrate --pretend` no se lee nada.
 *
 * down() SE NIEGA, antes de tocar nada y con el cerrojo ya tomado, si hay
 * alguna fila o si la ampliación de las comprobaciones (`2026_11_15_100002`,
 * que la cita) sigue aplicada; NUNCA borra filas. Sin filas borra la tabla y
 * sus dos funciones; la de F se queda. En producción no se ejecuta nunca
 * (protección 11).
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'ultron_studio:';

    /** La de F (2026_10_18): esta migración la usa y no la redeclara. */
    public const FUNCTION_NAME = 'ultron_studio_forbid';

    public const CHECK_FUNCTION_PREFIX = 'ultron_studio_chk_';

    public const TABLE = 'ultron_studio_meta_template_submissions';

    /** Del gobierno de DB9 (2026_11_12_100001): solo se referencian. */
    public const TEMPLATES = 'ultron_studio_message_templates';

    public const BINDINGS = 'ultron_studio_person_bindings';

    /** La ampliación de W9 que cita esta tabla (2026_11_15_100002): con ella aplicada, down() se niega. */
    public const META_CHECKS = 'ultron_studio_template_meta_checks';

    public const META_CHECKS_LINK_COLUMN = 'submission_id';

    public const APP_ROLE = 'iron_app';

    public const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    public const LOCK_TIMEOUT = '5s';

    /** El estado del ENVÍO (§5.4 del plan). Lo que dice Meta va en `meta_status`. */
    public const STATES = ['SENDING', 'SUBMITTED', 'FAILED', 'UNKNOWN'];

    /** @var array<string, list<string>> solo hacia delante */
    public const STATE_TRANSITIONS = ['SENDING' => ['SUBMITTED', 'FAILED', 'UNKNOWN'], 'UNKNOWN' => ['SUBMITTED', 'FAILED']];

    /** Definitivo: libera la versión para un envío nuevo (otra fila). */
    public const FINAL_STATES = ['FAILED'];

    /** Como `StudioMessageTemplates::LANGUAGES` y `::CATEGORIES` (lo que se envió; AUTHENTICATION no existe en el Studio). */
    public const LANGUAGES = ['es', 'es_CO'];

    public const CATEGORIES = ['marketing', 'utility'];

    // ── Clasificación de columnas (una vez cada una) ─────────────────────────

    /** Lo que fija el envío al nacer: la versión, lo que se envió y quién. */
    public const IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'template_id', 'template_content_hash', 'meta_name', 'language', 'category', 'request_hash',
        'submitted_by_admin_id', 'submitted_by_person_id', 'submitted_by_name', 'submitted_by_role', 'submitted_at', 'created_at',
    ];

    /** Lo que avanza o se observa (§5.7.1: `state` hacia delante, `meta_*`, la coincidencia, `attester_unavailable`, `error_*`, `last_*`). */
    public const MUTABLE_COLUMNS = [
        'state', 'meta_template_id', 'meta_status', 'meta_category', 'meta_correct_category', 'meta_rejected_reason', 'meta_quality',
        'meta_body_hash', 'meta_components_hash', 'matches_studio', 'mismatch_codes', 'attester_unavailable',
        'error_code', 'error_subcode', 'error_user_message', 'last_checked_at', 'last_changed_at', 'updated_at',
    ];

    /** Lo observado en Meta: solo existe con el envío SUBMITTED (Meta tiene la plantilla). */
    public const OBSERVED_COLUMNS = [
        'meta_template_id', 'meta_status', 'meta_category', 'meta_correct_category', 'meta_rejected_reason', 'meta_quality',
        'meta_body_hash', 'meta_components_hash', 'matches_studio', 'mismatch_codes',
    ];

    /** Un envío nace sin nada de esto (además de lo observado y de `attester_unavailable`). */
    public const UNSET_AT_BIRTH = ['error_code', 'error_subcode', 'error_user_message', 'last_checked_at', 'last_changed_at'];

    /** `meta_*` de lo observado: columna → largo de `[A-Z_]{1,n}` (tal cual lo dice Meta, saneado). */
    public const META_CODE_COLUMNS = ['meta_status' => 24, 'meta_category' => 16, 'meta_correct_category' => 16, 'meta_rejected_reason' => 32, 'meta_quality' => 8];

    /** Un código de la lista cerrada de «no casa» (los de `compare()`): minúsculas, dígitos y `_`. */
    public const MISMATCH_CODE = '[a-z][a-z0-9_]{0,47}';

    public const TRUNCATE_TRIGGER = 'usmsub_forbid_truncate';

    public const REASON_DELETE = 'los envíos de plantillas a Meta no se borran';

    /** CHECK de dominio, solo en PG: nombre → [columna, valores]. */
    public const DOMAIN_CHECKS = [
        'usmsub_state_chk' => ['state', self::STATES],
        'usmsub_language_chk' => ['language', self::LANGUAGES],
        'usmsub_category_chk' => ['category', self::CATEGORIES],
    ];

    /** Índices únicos parciales, en los dos motores: nombre → [columnas, predicado]. */
    public const PARTIAL_UNIQUE_INDEXES = [
        // Un envío vivo por versión (§5.7.1).
        'usmsub_one_live_per_template' => [['template_id'], "state <> 'FAILED'"],
        // Un nombre de Meta + idioma vivo por versión: dos versiones no reclaman la misma plantilla de Meta.
        'usmsub_one_live_per_meta_name' => [['meta_name', 'language'], "state <> 'FAILED'"],
    ];

    /** Lo que suma a `ultron_studio_%` (los recuentos de antes y después). */
    public const FOOTPRINT_DELTA = ['tables' => 1, 'partial' => 2, 'pg_triggers' => 9, 'sqlite_triggers' => 8, 'pg_checks' => 10];

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

    /** El rol de la aplicación al que se revoca (como DB9 y W9): solo minúsculas, dígitos y `_`, hasta 63. */
    public static function appRole(): string
    {
        $env = getenv(self::APP_ROLE_ENV);
        $role = is_string($env) && $env !== '' ? $env : self::APP_ROLE;
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new RuntimeException('ultron_studio (plantillas de Meta): '.self::APP_ROLE_ENV.' no es un nombre de rol válido (minúsculas, dígitos y _, hasta 63).');
        }

        return $role;
    }

    /**
     * CHECK entre columnas, solo en PG: nombre → expresión. Toda columna que se
     * compara sin `IS [NOT] NULL` delante es NOT NULL o va protegida por un
     * `IS [NOT] NULL` en la misma conjunción: ninguna expresión puede dar NULL,
     * que un CHECK aceptaría.
     *
     * @return array<string, string>
     */
    public static function rowChecks(): array
    {
        $hex = static fn (string $c): string => "{$c} ~ '^[0-9a-f]{64}$'";
        $hexOrNull = static fn (string $c): string => "({$c} IS NULL OR {$c} ~ '^[0-9a-f]{64}$')";
        $codes = [];
        foreach (self::META_CODE_COLUMNS as $columna => $largo) {
            $codes[] = "({$columna} IS NULL OR {$columna} ~ '^[A-Z_]{1,{$largo}}$')";
        }
        $sinObservar = implode(' AND ', array_map(static fn (string $c): string => "{$c} IS NULL", self::OBSERVED_COLUMNS));

        return [
            'usmsub_hashes_chk' => $hex('template_content_hash').' AND '.$hex('request_hash').' AND '.$hexOrNull('meta_body_hash').' AND '.$hexOrNull('meta_components_hash'),
            'usmsub_meta_name_chk' => "meta_name ~ '^[a-z0-9_]{1,64}$'",
            // Lo observado, tal cual lo dice Meta y saneado: el id solo dígitos; estados, categorías, motivo y calidad en [A-Z_].
            'usmsub_observed_chk' => "(meta_template_id IS NULL OR meta_template_id ~ '^[0-9]{1,32}$') AND ".implode(' AND ', $codes),
            // SUBMITTED ⇔ Meta dio un id (el POST lo devuelve; la reconciliación de UNKNOWN lo encuentra). Sin él no hay nada observado ni a quién dar fe.
            'usmsub_state_shape_chk' => "(state = 'SUBMITTED' AND meta_template_id IS NOT NULL) "
                ."OR (state <> 'SUBMITTED' AND {$sinObservar} AND attester_unavailable = false)",
            // Casa, no casa (y por qué: códigos de la lista cerrada, separados por comas) o aún no se sabe.
            'usmsub_match_chk' => '(matches_studio IS NULL AND mismatch_codes IS NULL) OR (matches_studio IS TRUE AND mismatch_codes IS NULL) '
                ."OR (matches_studio IS FALSE AND mismatch_codes IS NOT NULL AND mismatch_codes ~ '^".self::MISMATCH_CODE.'(,'.self::MISMATCH_CODE.")*$')",
            // El POST fallido: códigos de Meta positivos (el subcódigo, con su código) y el mensaje para el usuario en una línea.
            'usmsub_error_chk' => '(error_code IS NULL OR error_code > 0) AND (error_subcode IS NULL OR (error_code IS NOT NULL AND error_subcode > 0)) '
                ."AND (error_user_message IS NULL OR (char_length(btrim(error_user_message)) BETWEEN 1 AND 300 AND error_user_message !~ '[[:cntrl:]]'))",
            'usmsub_submitter_chk' => 'submitted_by_admin_id > 0 AND submitted_by_person_id > 0 AND char_length(btrim(submitted_by_name)) >= 1',
        ];
    }

    /**
     * Disparadores de fila en los dos motores: nombre → [evento, columnas de
     * `UPDATE OF` (o null), condición WHEN (o null), motivo]. `%DISTINCT%` es
     * `IS DISTINCT FROM` en PG e `IS NOT` en SQLite.
     *
     * @return array<string, array{0: string, 1: list<string>|null, 2: string|null, 3: string}>
     */
    public static function rowTriggers(): array
    {
        $final = self::literalList(self::FINAL_STATES);
        $alNacer = ['NEW.state <> '.self::literal('SENDING'), 'NEW.attester_unavailable'];
        foreach ([...self::OBSERVED_COLUMNS, ...self::UNSET_AT_BIRTH] as $c) {
            $alNacer[] = "NEW.{$c} IS NOT NULL";
        }

        return [
            'usmsub_forbid_delete' => ['DELETE', null, null, self::REASON_DELETE],
            'usmsub_forbid_immutable_update' => ['UPDATE', self::IMMUTABLE_COLUMNS, null,
                'un envío a Meta fija al nacer la versión, su huella, lo que se envió y quién lo envió'],
            'usmsub_failed_is_final' => ['UPDATE', null, "OLD.state IN ({$final})",
                'un envío a Meta fallido es definitivo (la versión queda libre para un envío nuevo)'],
            'usmsub_state_forward' => ['UPDATE', ['state'], self::forwardOnly(),
                'un envío a Meta solo avanza: SENDING → SUBMITTED, FAILED o UNKNOWN; UNKNOWN → SUBMITTED o FAILED'],
            'usmsub_meta_template_id_once' => ['UPDATE', ['meta_template_id'],
                "OLD.state NOT IN ({$final}) AND OLD.meta_template_id IS NOT NULL AND NEW.meta_template_id %DISTINCT% OLD.meta_template_id",
                'el id de Meta de un envío se fija una sola vez'],
            'usmsub_starts_sending' => ['INSERT', null, implode(' OR ', $alNacer),
                'un envío a Meta nace SENDING, sin nada observado de Meta, sin error y sin comprobaciones'],
        ];
    }

    /**
     * Guardas ENTRE TABLAS, en los dos motores: nombre → [evento, violación
     * (con subconsultas; `%T%` es `public.` en PG y nada en SQLite; `%JSON:k%`,
     * la clave `k` del contenido canónico de la plantilla `t`), motivo]. En PG,
     * la violación va en una función propia (`ultron_studio_chk_<nombre>`); en
     * SQLite, en el WHEN.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function crossTriggers(): array
    {
        return [
            'usmsub_template_approved_whatsapp' => ['INSERT',
                'NOT EXISTS (SELECT 1 FROM %T%'.self::TEMPLATES." AS t WHERE t.id = NEW.template_id AND t.channel = 'whatsapp' AND t.status = 'APPROVED' "
                .'AND t.content_hash = NEW.template_content_hash AND t.category = NEW.category '
                .'AND %JSON:metaName% = NEW.meta_name AND %JSON:language% = NEW.language)',
                'un envío a Meta es de una plantilla de WhatsApp del Studio APROBADA, de su huella exacta y con su nombre en Meta, su idioma y su categoría'],
            'usmsub_submitter_is_bound' => ['INSERT',
                'NOT EXISTS (SELECT 1 FROM %T%'.self::BINDINGS.' AS b WHERE b.admin_id = NEW.submitted_by_admin_id '
                .'AND b.identity_id = NEW.submitted_by_person_id AND b.verified_at IS NOT NULL AND b.revoked_at IS NULL)',
                'quien envía una plantilla a Meta es una persona vinculada (su vínculo verificado y vivo)'],
        ];
    }

    /**
     * «Solo hacia delante»: el WHEN de un `BEFORE UPDATE OF state` que salta
     * si el estado cambia a algo que el grafo no admite (también a un valor que
     * no es un estado). Escribir el mismo estado no cambia nada y pasa. Las
     * filas FAILED no las mira: son de `usmsub_failed_is_final`, que las para
     * enteras (así una violación da el mismo motivo en los dos motores).
     */
    public static function forwardOnly(): string
    {
        $pares = [];
        foreach (self::STATE_TRANSITIONS as $from => $to) {
            $pares[] = '(OLD.state = '.self::literal($from).' AND NEW.state IN ('.self::literalList($to).'))';
        }

        return 'OLD.state <> NEW.state AND OLD.state NOT IN ('.self::literalList(self::FINAL_STATES).') AND NOT ('.implode(' OR ', $pares).')';
    }

    public static function crossFunction(string $trigger): string
    {
        return self::CHECK_FUNCTION_PREFIX.$trigger;
    }

    /** @return list<string> */
    public static function ownFunctions(): array
    {
        return array_map(self::crossFunction(...), array_keys(self::crossTriggers()));
    }

    /** @return list<string> los disparadores de la tabla en ese motor */
    public static function triggerNames(string $driver): array
    {
        $nombres = [...array_keys(self::rowTriggers()), ...array_keys(self::crossTriggers())];
        if ($driver === 'pgsql') {
            $nombres[] = self::TRUNCATE_TRIGGER;
        }
        sort($nombres, SORT_STRING);

        return $nombres;
    }

    /** @return list<string> los CHECK de la tabla (solo PG) */
    public static function checkNames(): array
    {
        $nombres = [...array_keys(self::DOMAIN_CHECKS), ...array_keys(self::rowChecks())];
        sort($nombres, SORT_STRING);

        return $nombres;
    }

    private function createTable(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('usmsub_uuid_unique');
            // Idempotencia del acto «Enviar a Meta» (`clientRequestId`).
            $table->uuid('client_request_id')->unique('usmsub_client_request_unique');
            // La versión del Studio y su huella exacta: referencia SIN FK (ver la cabecera); la guarda entre tablas lo comprueba.
            $table->unsignedBigInteger('template_id');
            $table->char('template_content_hash', 64);
            // Lo que se envió: el nombre en Meta, el idioma y la categoría del Studio (en minúsculas), y la huella del cuerpo canónico.
            $table->string('meta_name', 64);
            $table->string('language', 5);
            $table->string('category', 10);
            $table->char('request_hash', 64);
            // SENDING → SUBMITTED | FAILED | UNKNOWN; UNKNOWN → SUBMITTED | FAILED. FAILED, definitivo.
            $table->string('state', 10);
            // Lo último observado en Meta: el id (una vez, solo dígitos) y, tal cual en [A-Z_], estado, categorías, motivo y calidad.
            $table->string('meta_template_id', 32)->nullable();
            $table->string('meta_status', 24)->nullable();
            $table->string('meta_category', 16)->nullable();
            $table->string('meta_correct_category', 16)->nullable();
            $table->string('meta_rejected_reason', 32)->nullable();
            $table->string('meta_quality', 8)->nullable();
            // Las dos huellas de lo que Meta enseña (cuerpo y componentes, §5.3).
            $table->char('meta_body_hash', 64)->nullable();
            $table->char('meta_components_hash', 64)->nullable();
            // ¿Casa con el Studio? Si no, por qué (códigos de la lista cerrada, separados por comas).
            $table->boolean('matches_studio')->nullable();
            $table->string('mismatch_codes', 200)->nullable();
            // §5.6: quien la envió ya no puede dar fe; no se escribe el APPROVED hasta que otra persona pulse «Comprobar ahora».
            $table->boolean('attester_unavailable')->default(false);
            // Del POST fallido: código, subcódigo y el mensaje para el usuario, saneado (nunca el cuerpo crudo).
            $table->integer('error_code')->nullable();
            $table->integer('error_subcode')->nullable();
            $table->string('error_user_message', 300)->nullable();
            // Quién lo envió: la instantánea de F (sin FK a `admins`) y su persona (identities.id).
            $table->unsignedBigInteger('submitted_by_admin_id');
            $table->unsignedBigInteger('submitted_by_person_id');
            $table->string('submitted_by_name', 120);
            $table->string('submitted_by_role', 60)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function upPostgres(): void
    {
        $this->boundLockWait();
        $antes = $this->footprint('pgsql');
        if ($antes !== null && $antes['table']) {
            throw new RuntimeException('ultron_studio (plantillas de Meta): '.self::TABLE.' ya existe: el esquema no es el que esta migración espera.');
        }

        $this->createTable();
        $this->assertColumnsClassified();

        foreach (self::DOMAIN_CHECKS as $name => [$column, $values]) {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT {$name} CHECK ({$column} IN (".self::literalList($values).'))');
        }
        foreach (self::rowChecks() as $name => $expression) {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        foreach (self::rowTriggers() as $name => [$event, $columns, $when, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns).' ON '.self::TABLE.' '
                .'FOR EACH ROW '.self::whenClause($when, 'pgsql')
                .'EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
        }
        DB::statement('CREATE TRIGGER '.self::TRUNCATE_TRIGGER.' BEFORE TRUNCATE ON '.self::TABLE.' '
            .'FOR EACH STATEMENT EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal(self::REASON_DELETE).')');

        foreach (self::crossTriggers() as $name => [$event, $violation, $reason]) {
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
                SQL, ['{fn}' => self::crossFunction($name), '{violation}' => self::dialect($violation, 'pgsql')]));
            DB::statement("CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE.' '
                .'FOR EACH ROW EXECUTE FUNCTION '.self::crossFunction($name).'('.self::literal($reason).')');
        }

        $this->createPartialUniqueIndexes();
        $this->revokeAppDeletes();

        $this->assertFootprintAfterUp('pgsql', $antes);
    }

    private function downPostgres(): void
    {
        $this->boundLockWait();
        // El cerrojo ANTES de contar: nadie escribe entre el recuento y el DROP.
        if (DB::connection()->pretending() || Schema::hasTable(self::TABLE)) {
            DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
        }

        $this->refuseWhileCited();
        $this->refuseWithRows();
        $antes = $this->footprint('pgsql');

        // Con la tabla se van sus disparadores, CHECK, índices y privilegios; después, sus funciones. La de F se queda.
        Schema::dropIfExists(self::TABLE);
        foreach (self::ownFunctions() as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }

        $this->assertFootprintAfterDown('pgsql', $antes);
    }

    private function upSqlite(): void
    {
        $antes = $this->footprint('sqlite');
        if ($antes !== null && $antes['table']) {
            throw new RuntimeException('ultron_studio (plantillas de Meta): '.self::TABLE.' ya existe: el esquema no es el que esta migración espera.');
        }

        $this->createTable();
        $this->assertColumnsClassified();

        foreach (self::rowTriggers() as $name => [$event, $columns, $when, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns).' ON '.self::TABLE.' '
                .'FOR EACH ROW '.self::whenClause($when, 'sqlite')
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, $event, self::TABLE)).'); END');
        }
        foreach (self::crossTriggers() as $name => [$event, $violation, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE.' FOR EACH ROW '
                .'WHEN ('.self::dialect($violation, 'sqlite').') '
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, $event, self::TABLE)).'); END');
        }

        $this->createPartialUniqueIndexes();
        // Sin roles: no hay privilegios que revocar.

        $this->assertFootprintAfterUp('sqlite', $antes);
    }

    private function downSqlite(): void
    {
        $this->refuseWhileCited();
        $this->refuseWithRows();
        $antes = $this->footprint('sqlite');

        Schema::dropIfExists(self::TABLE);

        $this->assertFootprintAfterDown('sqlite', $antes);
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
     * Cada columna es mutable o inmutable, explícitamente y una sola vez. Si
     * una lista nombra una columna que no existe, falta una o se repite, esto
     * falla antes de crear ninguna guarda (en SQLite un `UPDATE OF` con una
     * columna que no existe no da error y el disparador no saltaría nunca).
     * Con `--pretend` no se lee nada.
     */
    private function assertColumnsClassified(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }
        $declared = [...self::IMMUTABLE_COLUMNS, ...self::MUTABLE_COLUMNS];
        $actual = Schema::getColumnListing(self::TABLE);
        $unclassified = array_values(array_diff($actual, $declared));
        $missing = array_values(array_diff($declared, $actual));
        $repeated = array_values(array_unique(array_diff_assoc($declared, array_unique($declared))));
        $observadas = array_values(array_diff([...self::OBSERVED_COLUMNS, ...self::UNSET_AT_BIRTH], self::MUTABLE_COLUMNS));

        if ($unclassified !== [] || $missing !== [] || $repeated !== [] || $observadas !== []) {
            throw new RuntimeException(self::TABLE.': columnas sin clasificar ['.implode(', ', $unclassified)
                .'], que no existen ['.implode(', ', $missing).'], clasificadas dos veces ['.implode(', ', $repeated)
                .'] u observadas que no son mutables ['.implode(', ', $observadas).']. Cada columna es mutable o inmutable, explícitamente.');
        }
    }

    /** El mismo texto en los dos motores; SQLite lo guarda tal cual en `sqlite_master`. */
    private function createPartialUniqueIndexes(): void
    {
        foreach (self::PARTIAL_UNIQUE_INDEXES as $name => [$columns, $predicate]) {
            DB::statement("CREATE UNIQUE INDEX {$name} ON ".self::TABLE.' ('.implode(', ', $columns).") WHERE {$predicate}");
        }
    }

    /**
     * REVOKE del rol de la aplicación (DELETE: un envío no se borra; conserva
     * UPDATE, que es como avanza) y la comprobación de que de verdad ya no
     * puede (`has_table_privilege` mira también la pertenencia a otros roles y
     * PUBLIC). Sin el rol en el clúster no hay nada que revocar, salvo en
     * producción, donde es un error.
     */
    private function revokeAppDeletes(): void
    {
        $role = self::appRole();
        $sentencia = 'REVOKE DELETE ON '.self::TABLE.' FROM "'.$role.'"';

        if (DB::connection()->pretending()) {
            DB::statement($sentencia);

            return;
        }
        if (! self::roleExists($role)) {
            if (app()->environment('production')) {
                throw new RuntimeException("ultron_studio (plantillas de Meta): el rol de la aplicación «{$role}» no existe en este clúster y APP_ENV es production: "
                    .'el REVOKE sobre los envíos a Meta no se puede aplicar. La migración no se registra; '
                    .'si el rol se llama de otra forma, '.self::APP_ROLE_ENV.' en el entorno del proceso que migra.');
            }

            return;
        }

        DB::statement($sentencia);

        $puede = DB::selectOne("SELECT has_table_privilege(?, ?, 'DELETE')::int AS d", [$role, 'public.'.self::TABLE]);
        if ((int) $puede->d === 1) {
            throw new RuntimeException("ultron_studio (plantillas de Meta): después del REVOKE, «{$role}» todavía puede borrar en ".self::TABLE
                .' (heredado de otro rol o de PUBLIC, o concedido por alguien que no es el rol que migra). La migración no se registra.');
        }
    }

    private static function roleExists(string $role): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]) !== null;
    }

    /**
     * La ampliación de las comprobaciones (`2026_11_15_100002`) cita esta
     * tabla (`submission_id` y su guarda): mientras siga aplicada, quitar la
     * tabla dejaría esa guarda apuntando a nada (en SQLite, cualquier INSERT en
     * las comprobaciones fallaría). Se revierte primero esa; el orden normal de
     * `migrate:rollback` ya lo hace.
     */
    private function refuseWhileCited(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }
        if (Schema::hasTable(self::META_CHECKS) && Schema::hasColumn(self::META_CHECKS, self::META_CHECKS_LINK_COLUMN)) {
            throw new RuntimeException('ultron_studio (plantillas de Meta): down() se niega a quitar '.self::TABLE.' mientras '
                .self::META_CHECKS.'.'.self::META_CHECKS_LINK_COLUMN.' exista (la ampliación 2026_11_15_100002 sigue aplicada): revierte primero esa.');
        }
    }

    /**
     * Protección 11: con filas, revertir el esquema destruiría el registro de
     * lo que se envió a Meta (DROP no pasa por los disparadores de fila). Se
     * niega con un mensaje claro, antes de tocar nada, y nunca borra.
     */
    private function refuseWithRows(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }
        $filas = Schema::hasTable(self::TABLE) ? DB::table(self::TABLE)->count() : 0;
        if ($filas > 0) {
            throw new RuntimeException("ultron_studio (plantillas de Meta): down() se niega a revertir los envíos de plantillas a Meta con datos ({$filas} filas). "
                .'Revertirlo los destruiría y nunca borra nada. Para volver al código anterior no hace falta revertir el esquema (la tabla se queda).');
        }
    }

    /**
     * Los recuentos de `ultron_studio_%` (y si la tabla existe). null con
     * `--pretend`. En SQLite no hay CHECK (`checks` es 0).
     *
     * @return array{table: bool, tables: int, triggers: int, checks: int, partial: int, own_triggers: list<string>, own_checks: list<string>, own_partial: list<string>}|null
     */
    private function footprint(string $driver): ?array
    {
        if (DB::connection()->pretending()) {
            return null;
        }
        // Los nombres se ordenan aquí, byte a byte: el ORDER BY de PG seguiría la collation de la base.
        $ordenados = static function (array $nombres): array {
            $nombres = array_map(static fn (mixed $n): string => (string) $n, $nombres);
            sort($nombres, SORT_STRING);

            return $nombres;
        };
        if ($driver === 'pgsql') {
            $propios = static fn (string $sql): array => $ordenados(array_map(static fn (object $f): string => (string) $f->n, DB::select($sql, [self::TABLE])));

            return [
                'table' => (bool) DB::selectOne("SELECT to_regclass('public.".self::TABLE."') IS NOT NULL AS x")->x,
                'tables' => (int) DB::scalar("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\\_studio\\_%'"),
                'triggers' => (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\\_studio\\_%'"),
                'checks' => (int) DB::scalar("SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron\\_studio\\_%'"),
                'partial' => (int) DB::scalar("SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron\\_studio\\_%' AND i.indpred IS NOT NULL"),
                'own_triggers' => $propios('SELECT t.tgname AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname = ?'),
                'own_checks' => $propios("SELECT k.conname AS n FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname = ?"),
                'own_partial' => $propios('SELECT ic.relname AS n FROM pg_index i JOIN pg_class ic ON ic.oid = i.indexrelid JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname = ? AND i.indpred IS NOT NULL'),
            ];
        }

        $nombres = static fn (string $type) => DB::table('sqlite_master')->where('type', $type)->where('tbl_name', self::TABLE);

        return [
            'table' => DB::table('sqlite_master')->where('type', 'table')->where('name', self::TABLE)->exists(),
            'tables' => DB::table('sqlite_master')->where('type', 'table')->where('name', 'like', 'ultron_studio_%')->count(),
            'triggers' => DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'like', 'ultron_studio_%')->count(),
            'checks' => 0,
            'partial' => DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', 'like', 'ultron_studio_%')->where('sql', 'like', '% WHERE %')->count(),
            'own_triggers' => $ordenados($nombres('trigger')->pluck('name')->all()),
            'own_checks' => [],
            'own_partial' => $ordenados($nombres('index')->where('sql', 'like', '% WHERE %')->pluck('name')->all()),
        ];
    }

    /** @param  array<string, mixed>|null  $antes */
    private function assertFootprintAfterUp(string $driver, ?array $antes): void
    {
        $despues = $this->footprint($driver);
        if ($antes === null || $despues === null) {
            return;
        }
        $pg = $driver === 'pgsql';
        $esperado = [
            'tables' => $antes['tables'] + self::FOOTPRINT_DELTA['tables'],
            'triggers' => $antes['triggers'] + self::FOOTPRINT_DELTA[$pg ? 'pg_triggers' : 'sqlite_triggers'],
            'checks' => $antes['checks'] + ($pg ? self::FOOTPRINT_DELTA['pg_checks'] : 0),
            'partial' => $antes['partial'] + self::FOOTPRINT_DELTA['partial'],
            'own_triggers' => self::triggerNames($driver),
            'own_checks' => $pg ? self::checkNames() : [],
            'own_partial' => self::sortedKeys(self::PARTIAL_UNIQUE_INDEXES),
        ];
        $real = array_intersect_key($despues, $esperado);
        if ($real != $esperado) {
            throw new RuntimeException('ultron_studio (plantillas de Meta): el recuento no cuadra tras crear '.self::TABLE
                .' (esperado '.json_encode($esperado).'; real '.json_encode($real).'). La migración no se registra.');
        }
    }

    /** @param  array<string, mixed>|null  $antes */
    private function assertFootprintAfterDown(string $driver, ?array $antes): void
    {
        $despues = $this->footprint($driver);
        if ($antes === null || $despues === null || ! $antes['table']) {
            return;
        }
        $pg = $driver === 'pgsql';
        $esperado = [
            'table' => false,
            'tables' => $antes['tables'] - self::FOOTPRINT_DELTA['tables'],
            'triggers' => $antes['triggers'] - self::FOOTPRINT_DELTA[$pg ? 'pg_triggers' : 'sqlite_triggers'],
            'checks' => $antes['checks'] - ($pg ? self::FOOTPRINT_DELTA['pg_checks'] : 0),
            'partial' => $antes['partial'] - self::FOOTPRINT_DELTA['partial'],
        ];
        $real = array_intersect_key($despues, $esperado);
        if ($real != $esperado) {
            throw new RuntimeException('ultron_studio (plantillas de Meta): el recuento no cuadra tras quitar '.self::TABLE
                .' (esperado '.json_encode($esperado).'; real '.json_encode($real).'). No se revierte.');
        }
    }

    /** @param  array<string, mixed>  $map @return list<string> */
    private static function sortedKeys(array $map): array
    {
        $keys = array_keys($map);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @param  list<string>|null  $columns */
    private static function eventClause(string $event, ?array $columns): string
    {
        return $columns === null ? $event : $event.' OF '.implode(', ', $columns);
    }

    /** `WHEN (…) ` con su espacio final, o nada. */
    private static function whenClause(?string $when, string $driver): string
    {
        return $when === null ? '' : 'WHEN ('.self::dialect($when, $driver).') ';
    }

    /** `%DISTINCT%`, `%T%` y `%JSON:clave%` (del contenido canónico de la plantilla `t`) en el dialecto de cada motor. */
    private static function dialect(string $sql, string $driver): string
    {
        $sql = (string) preg_replace_callback('/%JSON:([A-Za-z]+)%/', static fn (array $m): string => $driver === 'pgsql'
            ? "(t.content_json::json ->> '{$m[1]}')"
            : "json_extract(t.content_json, '$.{$m[1]}')", $sql);

        return strtr($sql, [
            '%DISTINCT%' => $driver === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT',
            '%T%' => $driver === 'pgsql' ? 'public.' : '',
        ]);
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
        return new RuntimeException("Los envíos de plantillas a Meta solo existen para pgsql y sqlite, y sus guardas son obligatorias: con «{$driver}» la migración no se aplica.");
    }
};
