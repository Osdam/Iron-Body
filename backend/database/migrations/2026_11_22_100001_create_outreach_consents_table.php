<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CIERRE DE ULTRON STUDIO, paquete C9, pieza C9-1: EL LIBRO DE AUTORIZACIONES
 * DE CONTACTO (`outreach_consents`). Contrato: ULTRON_STUDIO_H9_CONTRACT.md,
 * D9-15 (matriz finalidad × canal: se niega por defecto y toda señal negativa
 * gana), §6.2 («Siguientes») y el paquete C9. Plan:
 * plans/cierre/plan-quejas-consentimiento-cuatro-ojos.md §2.3, fila C9-1.
 * Informe: plans/informes-cierre/informe-db-consent.md.
 *
 * UNA TABLA NUEVA, ADITIVA E INERTE: sin filas no cambia nada (nadie la lee
 * todavía: el lector es C9-2). No altera ninguna tabla ni nombra el motor
 * comercial, Marketing ni la voz (la voz sigue en `voice_consents`). Socio y
 * prospecto son REFERENCIAS sin FK; la única FK es la de una resolución a su
 * petición, dentro de la tabla.
 *
 * UN LIBRO PURO, SOLO INSERCIÓN: la petición también es una fila.
 *  · requested — una cuenta del personal registra la autorización con su
 *                evidencia, el texto legal exacto que se aceptó (versión y
 *                sha256) y el seudónimo del número para el que vale
 *                (`phone_ref`: HMAC `outreach-phone/1`, el de outreach_jobs;
 *                nunca el número).
 *  · granted   — OTRA cuenta la confirma (cuatro ojos POR CUENTA,
 *                `ocon_four_eyes_chk`). Copia de su petición la persona, la
 *                finalidad, el canal, la fuente, quién la registró, el número y
 *                el texto legal; la base lo comprueba (`ocon_resolves_request`).
 *  · rejected  — la petición no se confirma (cualquier cuenta, también la que
 *                la pidió: retirarla es seguro).
 *  · revoked   — se retira: sin petición ni segunda cuenta (retirar siempre es
 *                seguro). Vale para la persona × finalidad × canal, sin número.
 *                Es lo único que puede registrar el sistema (la palabra de baja).
 * Una resolución por petición (`ocon_request_unique`). Una petición anterior a
 * una revocación de lo mismo ya no se confirma (`ocon_grant_not_superseded`):
 * hace falta otra. EL LECTOR (C9-2) ordena por `id`, y un `granted` cuenta en el
 * lugar de SU PETICIÓN: el último evento por persona, finalidad y canal, con
 * `revoked` ganando, es la consulta de la tanda del informe (§4).
 *
 * DATOS PERSONALES (Ley 1581). La persona es su `subject_key` (`m:<id>` |
 * `l:<id>`) con sus referencias; el número, su seudónimo; la evidencia, ≤ 300
 * de texto plano sin teléfonos ni correos (CHECK). Los únicos nombres son los
 * del personal. LA ÚNICA MUTACIÓN es la SUPRESIÓN 1581, una vez y entera
 * (`ocon_redaction_only`): `subject_key` = 'x:<id>', sin socio ni lead,
 * `phone_ref` a NULL, `evidence` = '[suprimido]' y `redacted_at`; y, por
 * separado, el nombre del personal a '[suprimido]'
 * (`ocon_staff_names_redaction_only`). La guarda DEJA PASAR esas dos formas y
 * nada más, así que la supresión (`ops/supresion-1581.sql`, con el rol dueño)
 * no apaga ningún disparador de esta tabla. La aplicación no puede ni eso: no
 * tiene UPDATE.
 *
 * LAS GUARDAS, con su PROPIA función `outreach_consent_forbid()` (mismo texto
 * «outreach: …» que `outreach_forbid()`, que NO se reutiliza: el down() de DB9
 * la borra y no podría con disparadores ajenos colgando de ella, y la supresión
 * apaga los disparadores de `outreach_forbid()` que saltarían, mientras que los
 * de esta tabla tienen que quedarse encendidos para validar la redacción).
 *  · PostgreSQL: SQLSTATE P0001; un CHECK violado es 23514.
 *  · SQLite: `RAISE(ABORT, …)` → SQLSTATE 23000.
 *  · Disparadores de fila en los dos motores, mismo nombre y mismo texto; PG
 *    suma uno de sentencia contra TRUNCATE.
 *  · Columnas por LISTA, clasificadas una sola vez (up() lo comprueba):
 *    inmutables, redactables de la persona y nombres del personal.
 *  · Las dos guardas sobre la propia tabla (`ocon_resolves_request` y
 *    `ocon_grant_not_superseded`): en PG una función propia por guarda
 *    (`outreach_consent_chk_*`), plpgsql, INVOKER, con `public.`; en SQLite, la
 *    misma condición en el WHEN.
 *  · Los CHECK de PG tienen en SQLite un disparador de INSERT equivalente con
 *    el mismo nombre sin `_chk` (lo que GLOB no expresa —controles, series de
 *    dígitos con separadores, correos— solo lo ve PG, y la puerta PG es
 *    obligatoria).
 *
 * EL ROL DE LA APLICACIÓN pierde UPDATE, DELETE y TRUNCATE, con la comprobación
 * posterior y la guarda de producción (`ULTRON_STUDIO_DB_APP_ROLE`, la misma
 * variable que DB7/DB8/DB9). Conserva SELECT e INSERT: `insertOrIgnore` (ON
 * CONFLICT DO NOTHING) vale; `FOR UPDATE`/`FOR SHARE` y ON CONFLICT DO UPDATE,
 * no (42501). La FK a la petición la comprueba PG con el dueño: no pide UPDATE.
 *
 * CERROJOS (PG). Si la sesión no trae `lock_timeout`, `SET LOCAL lock_timeout =
 * '5s'`. No toca ninguna tabla existente: crea una tabla vacía y sus objetos.
 *
 * SIN TOLERAR ERRORES: si algo no se puede crear o comprobar, la migración
 * falla y no queda registrada. `CREATE OR REPLACE FUNCTION`: `migrate:fresh`
 * borra tablas pero no funciones. Con `migrate --pretend` no se lee nada.
 *
 * Lo que NO cubre:
 *  · El dueño puede desactivar disparadores y devolver privilegios; el rol de
 *    la aplicación, no (42501).
 *  · Cuatro ojos por PERSONA (dos cuentas de la misma persona): hoy no hay
 *    vínculos verificados (plan §2.4); es por cuenta.
 *  · Que dos claves (`m:` y `l:`) sean la misma persona: la base no lo sabe;
 *    lo junta el lector.
 *  · Que el `phone_ref` sea el del número del socio o que el hash sea el del
 *    texto que se enseñó: lo garantiza el servicio (C9-3).
 *  · SQLite: `INSERT OR REPLACE` borra sin disparar el DELETE; Laravel no lo usa.
 *
 * down() SE NIEGA si hay alguna fila, antes de tocar nada y con el cerrojo ya
 * tomado, y NUNCA borra filas. Sin filas borra la tabla y sus funciones.
 */
return new class extends Migration
{
    public const TABLE = 'outreach_consents';

    public const MESSAGE_PREFIX = 'outreach:';

    public const FUNCTION_NAME = 'outreach_consent_forbid';

    public const CHECK_FUNCTION_PREFIX = 'outreach_consent_chk_';

    public const APP_ROLE = 'iron_app';

    public const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    public const LOCK_TIMEOUT = '5s';

    public const KINDS = ['requested', 'granted', 'rejected', 'revoked'];

    /** Las finalidades de D9-15 (las de `outreach_enrollments.purpose`). */
    public const PURPOSES = ['commercial', 'collections', 'service'];

    /** La voz se queda en `voice_consents` (D8-18): aquí, solo WhatsApp. */
    public const CHANNELS = ['whatsapp'];

    /** D7 sin decidir (P9-15): el conjunto por defecto mientras el propietario decide con su asesoría. */
    public const SOURCES = ['contract_checkbox', 'reception_form', 'member_request'];

    public const ACTOR_TYPES = ['admin', 'system'];

    public const EVIDENCE_MAX = 300;

    /** La marca de la supresión, la de `ops/supresion-1581.sql`. */
    public const REDACTED = '[suprimido]';

    public const LEGAL_VERSION_PATTERN = '^[A-Za-z0-9][A-Za-z0-9._:/-]{0,47}$';

    public const HEX64_PATTERN = '^[0-9a-f]{64}$';

    // ── Clasificación de columnas (una vez cada una) ─────────────────────────

    public const IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'purpose', 'channel', 'kind', 'request_uuid', 'source', 'legal_version', 'legal_hash',
        'actor_type', 'recorded_by_admin_id', 'recorded_by_role', 'confirmed_by_admin_id', 'confirmed_by_role', 'created_at',
    ];

    /** Lo que la supresión 1581 de la PERSONA cambia, una vez y todo junto. */
    public const SUBJECT_REDACTABLE_COLUMNS = ['subject_key', 'member_id', 'lead_id', 'evidence', 'phone_ref', 'redacted_at'];

    /** Lo que la supresión 1581 de una persona del PERSONAL cambia (a la marca). */
    public const STAFF_NAME_COLUMNS = ['recorded_by_name', 'confirmed_by_name'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
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

    /** El texto del error, igual en los dos motores (y con la forma de `outreach_forbid()`). */
    public static function message(string $reason, string $operation): string
    {
        return self::MESSAGE_PREFIX.' '.$reason.' ('.$operation.' en '.self::TABLE.')';
    }

    /** El rol de la aplicación al que se revoca (la misma regla y la misma variable que DB7/DB8/DB9). */
    public static function appRole(): string
    {
        $env = getenv(self::APP_ROLE_ENV);
        $role = is_string($env) && $env !== '' ? $env : self::APP_ROLE;
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new RuntimeException('outreach_consents (C9): '.self::APP_ROLE_ENV.' no es un nombre de rol válido (minúsculas, dígitos y _, hasta 63).');
        }

        return $role;
    }

    /**
     * Los CHECK de PG y su equivalente de SQLite (un disparador de INSERT con
     * el nombre sin `_chk` que salta con `NOT (expresión)`): nombre →
     * [expresión PG, expresión SQLite, motivo]. Ninguna expresión puede dar
     * NULL (toda columna anulable va protegida en su conjunción).
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function checks(): array
    {
        $in = static fn (string $c, array $v): string => "{$c} IN (".self::literalList($v).')';
        $hexPg = static fn (string $c): string => "{$c} ~ '".self::HEX64_PATTERN."'";
        $hexLite = static fn (string $c): string => "(length({$c}) = 64 AND {$c} NOT GLOB '*[^0-9a-f]*')";
        $filled = static fn (string $c): string => "({$c} IS NOT NULL AND length(trim({$c})) >= 1)";
        $dominio = static fn (string $c, array $v): array => [$in($c, $v), $in($c, $v), "{$c} es uno de: ".implode(', ', $v)];
        // La persona: su clave con su socio o su prospecto (socio manda), o la forma suprimida (1581). Igual en los dos motores.
        $persona = "(redacted_at IS NULL AND ((member_id IS NOT NULL AND member_id > 0 AND subject_key = 'm:' || member_id) "
            ."OR (member_id IS NULL AND lead_id IS NOT NULL AND lead_id > 0 AND subject_key = 'l:' || lead_id))) "
            ."OR (redacted_at IS NOT NULL AND member_id IS NULL AND lead_id IS NULL AND subject_key = 'x:' || id)";
        // Los que dependen del tipo nombran los tipos que EXIGEN algo y dejan el resto a `NOT IN`: el conjunto cerrado es
        // de `ocon_kind_chk`, y así cada fila mal formada viola una sola regla (PG nombra la primera por orden alfabético).
        $peticion = "(kind IN ('granted', 'rejected') AND request_uuid IS NOT NULL) OR (kind NOT IN ('granted', 'rejected') AND request_uuid IS NULL)";
        // Quién registra: una cuenta del personal, o el sistema y solo para retirar (la palabra de baja).
        $actor = $filled('recorded_by_name')." AND (actor_type <> 'admin' OR recorded_by_admin_id IS NOT NULL) "
            ."AND (actor_type <> 'system' OR (recorded_by_admin_id IS NULL AND recorded_by_role IS NULL AND kind = 'revoked'))";
        // Quién resuelve: solo en una confirmación o un rechazo, y entero.
        $resuelve = "(kind IN ('granted', 'rejected') AND confirmed_by_admin_id IS NOT NULL AND ".$filled('confirmed_by_name').') '
            ."OR (kind NOT IN ('granted', 'rejected') AND confirmed_by_admin_id IS NULL AND confirmed_by_name IS NULL AND confirmed_by_role IS NULL)";
        // P-18 del H8, aquí POR CUENTA: quien confirma no es quien registró la petición (que la fila copia y la base comprueba).
        $cuatroOjos = "kind <> 'granted' OR (recorded_by_admin_id IS NOT NULL AND confirmed_by_admin_id IS NOT NULL AND confirmed_by_admin_id <> recorded_by_admin_id)";
        $legal = static fn (string $version, string $hash): string => "(kind IN ('requested', 'granted') AND legal_version IS NOT NULL AND {$version} "
            ."AND legal_hash IS NOT NULL AND {$hash}) OR (kind NOT IN ('requested', 'granted') AND legal_version IS NULL AND legal_hash IS NULL)";
        $legalLite = "(length(legal_version) BETWEEN 1 AND 48 AND substr(legal_version, 1, 1) GLOB '[A-Za-z0-9]' AND legal_version NOT GLOB '*[^A-Za-z0-9._:/-]*')";
        $telefono = static fn (string $hex): string => "(redacted_at IS NULL AND ((kind IN ('requested', 'granted') AND phone_ref IS NOT NULL AND {$hex}) "
            ."OR (kind NOT IN ('requested', 'granted') AND phone_ref IS NULL))) OR (redacted_at IS NOT NULL AND phone_ref IS NULL)";
        // Texto plano sin datos de contacto: sin controles, sin 7 dígitos seguidos, sin la serie de 8 con separadores
        // de StudioCanaryPhone::CONTACT_DIGIT_RUN y sin correos (CONTACT_EMAIL); o la marca de la supresión.
        $evidenciaPg = "(redacted_at IS NULL AND ".$filled('evidence')." AND evidence !~ '[[:cntrl:]]' AND evidence !~ '[0-9]{7,}' "
            ."AND evidence !~ '[0-9]([^[:alpha:][:digit:]]{0,3}[0-9]){7,}' AND evidence !~ '[^[:space:]@]+@[^[:space:]@]+[.][^[:space:]@]+') "
            ."OR (redacted_at IS NOT NULL AND evidence = ".self::literal(self::REDACTED).')';
        // SQLite no aplica el varchar(300): la longitud va aquí.
        $evidenciaLite = "(redacted_at IS NULL AND ".$filled('evidence').' AND length(evidence) <= '.self::EVIDENCE_MAX
            ." AND evidence NOT GLOB '*[0-9][0-9][0-9][0-9][0-9][0-9][0-9]*') "
            ."OR (redacted_at IS NOT NULL AND evidence = ".self::literal(self::REDACTED).')';

        return [
            'ocon_kind_chk' => $dominio('kind', self::KINDS),
            'ocon_purpose_chk' => $dominio('purpose', self::PURPOSES),
            'ocon_channel_chk' => $dominio('channel', self::CHANNELS),
            'ocon_source_chk' => $dominio('source', self::SOURCES),
            'ocon_actor_type_chk' => $dominio('actor_type', self::ACTOR_TYPES),
            'ocon_subject_chk' => [$persona, $persona, 'la persona es m:<socio> o l:<prospecto> con su referencia (o la forma suprimida)'],
            'ocon_request_chk' => [$peticion, $peticion, 'una confirmación o un rechazo apunta a su petición; una petición o una retirada, a ninguna'],
            'ocon_actor_chk' => [$actor, $actor, 'la registra una cuenta del personal, con su nombre; el sistema solo retira'],
            'ocon_confirmed_chk' => [$resuelve, $resuelve, 'una confirmación o un rechazo dice quién la resolvió; una petición o una retirada, no'],
            'ocon_four_eyes_chk' => [$cuatroOjos, $cuatroOjos, 'una autorización la confirma otra cuenta que la que la registró (cuatro ojos)'],
            'ocon_legal_chk' => [$legal("legal_version ~ '".self::LEGAL_VERSION_PATTERN."'", $hexPg('legal_hash')), $legal($legalLite, $hexLite('legal_hash')),
                'una petición y su confirmación llevan la versión y el sha256 del texto legal aceptado; un rechazo y una retirada, no'],
            'ocon_phone_chk' => [$telefono($hexPg('phone_ref')), $telefono($hexLite('phone_ref')),
                'una petición y su confirmación llevan el seudónimo del número (64 hex), nunca el número; un rechazo y una retirada, no'],
            'ocon_evidence_chk' => [$evidenciaPg, $evidenciaLite, 'la evidencia es texto plano de 1 a 300 sin teléfonos ni correos'],
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
        $marca = self::literal(self::REDACTED);
        $nombres = implode(' OR ', array_map(
            static fn (string $c): string => "(NEW.{$c} %DISTINCT% OLD.{$c} AND (OLD.{$c} IS NULL OR NEW.{$c} %DISTINCT% {$marca}))",
            self::STAFF_NAME_COLUMNS,
        ));

        return [
            'ocon_forbid_delete' => ['DELETE', null, null, 'las autorizaciones de contacto son de solo inserción'],
            'ocon_forbid_immutable_update' => ['UPDATE', self::IMMUTABLE_COLUMNS, null,
                'una autorización de contacto es de solo inserción (solo se redacta, Ley 1581)'],
            // La supresión de la persona: una vez, entera y a la forma suprimida; cualquier otra cosa salta.
            'ocon_redaction_only' => ['UPDATE', self::SUBJECT_REDACTABLE_COLUMNS,
                "OLD.redacted_at IS NOT NULL OR NEW.redacted_at IS NULL OR NEW.subject_key %DISTINCT% ('x:' || OLD.id) "
                ."OR NEW.member_id IS NOT NULL OR NEW.lead_id IS NOT NULL OR NEW.phone_ref IS NOT NULL OR NEW.evidence %DISTINCT% {$marca}",
                'una autorización solo se redacta una vez, entera y a la forma suprimida (Ley 1581)'],
            // La supresión de una persona del personal: su nombre, a la marca (el id se queda).
            'ocon_staff_names_redaction_only' => ['UPDATE', self::STAFF_NAME_COLUMNS, $nombres,
                'el nombre de quien registra o resuelve una autorización solo se redacta (Ley 1581)'],
            'ocon_starts_unredacted' => ['INSERT', null, 'NEW.redacted_at IS NOT NULL', 'una autorización nace sin redactar'],
        ];
    }

    /**
     * Las guardas sobre la propia tabla, en los dos motores: nombre → [evento,
     * condición WHEN sin subconsultas (o null), violación (`%T%` = `public.` en
     * PG), motivo].
     *
     * @return array<string, array{0: string, 1: string|null, 2: string, 3: string}>
     */
    public static function crossTriggers(): array
    {
        $t = '%T%'.self::TABLE;

        return [
            // La resolución copia de su petición viva lo que la hace la misma autorización.
            'ocon_resolves_request' => ['INSERT', "NEW.kind IN ('granted', 'rejected')",
                "NOT EXISTS (SELECT 1 FROM {$t} AS r WHERE r.uuid = NEW.request_uuid AND r.kind = 'requested' AND r.redacted_at IS NULL "
                .'AND r.subject_key = NEW.subject_key AND r.member_id %NOT_DISTINCT% NEW.member_id AND r.lead_id %NOT_DISTINCT% NEW.lead_id '
                .'AND r.purpose = NEW.purpose AND r.channel = NEW.channel AND r.source = NEW.source AND r.actor_type = NEW.actor_type '
                .'AND r.recorded_by_admin_id %NOT_DISTINCT% NEW.recorded_by_admin_id AND r.recorded_by_name = NEW.recorded_by_name '
                .'AND r.recorded_by_role %NOT_DISTINCT% NEW.recorded_by_role '
                ."AND (NEW.kind = 'rejected' OR (r.phone_ref = NEW.phone_ref AND r.legal_version = NEW.legal_version AND r.legal_hash = NEW.legal_hash)))",
                'una confirmación o un rechazo resuelve una petición viva de la misma persona, finalidad, canal y fuente, y copia quién la registró, su número y su texto legal'],
            // Toda señal negativa gana (D9-15): lo que se pidió antes de retirarlo no revive al confirmarlo.
            'ocon_grant_not_superseded' => ['INSERT', "NEW.kind = 'granted'",
                "EXISTS (SELECT 1 FROM {$t} AS r JOIN {$t} AS v ON v.subject_key = r.subject_key AND v.purpose = r.purpose AND v.channel = r.channel "
                ."AND v.kind = 'revoked' AND v.id > r.id WHERE r.uuid = NEW.request_uuid)",
                'una petición anterior a una retirada de lo mismo ya no se puede confirmar: hace falta otra'],
        ];
    }

    public static function crossFunction(string $trigger): string
    {
        return self::CHECK_FUNCTION_PREFIX.$trigger;
    }

    /** @return list<string> */
    public static function ownFunctions(): array
    {
        return [...array_map(self::crossFunction(...), array_keys(self::crossTriggers())), self::FUNCTION_NAME];
    }

    /** El nombre del equivalente de SQLite de un CHECK de PG. */
    public static function sqliteTriggerFor(string $check): string
    {
        return substr($check, 0, -strlen('_chk'));
    }

    private function createTable(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            // Idempotencia de la API (C9-3): la misma petición dos veces es una fila.
            $table->uuid('client_request_id');
            // La persona: su clave interna y las referencias SIN FK (socio manda).
            $table->string('subject_key', 24);
            $table->unsignedBigInteger('member_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            // commercial | collections | service (D9-15); whatsapp (la voz, en voice_consents).
            $table->string('purpose', 12);
            $table->string('channel', 16);
            // requested | granted | rejected | revoked.
            $table->string('kind', 10);
            // granted | rejected: el uuid de su petición.
            $table->uuid('request_uuid')->nullable();
            // contract_checkbox | reception_form | member_request (D7, por decidir).
            $table->string('source', 20);
            // ≤ 300, texto plano, sin teléfonos ni correos.
            $table->string('evidence', 300);
            // El texto legal exacto que se aceptó: su versión y su sha256 (requested y granted).
            $table->string('legal_version', 48)->nullable();
            $table->char('legal_hash', 64)->nullable();
            // El número para el que vale (requested y granted): su seudónimo HMAC `outreach-phone/1`, nunca el número.
            $table->char('phone_ref', 64)->nullable();
            // admin | system (el sistema solo retira).
            $table->string('actor_type', 8);
            // Quien registró la petición (en una resolución, copiado de ella) y quien la resolvió.
            $table->unsignedBigInteger('recorded_by_admin_id')->nullable();
            $table->string('recorded_by_name', 120);
            $table->string('recorded_by_role', 60)->nullable();
            $table->unsignedBigInteger('confirmed_by_admin_id')->nullable();
            $table->string('confirmed_by_name', 120)->nullable();
            $table->string('confirmed_by_role', 60)->nullable();
            // Supresión 1581, una vez (la pone ops/supresion-1581.sql con el rol dueño).
            $table->timestamp('redacted_at')->nullable();
            // Solo inserción: sin updated_at.
            $table->timestamp('created_at');

            // Explícitos y en este orden: la FK de la resolución necesita el UNIQUE de uuid antes.
            $table->unique('uuid', 'ocon_uuid_unique');
            $table->unique('client_request_id', 'ocon_client_request_unique');
            // Una resolución por petición (NULL no choca: las peticiones y las retiradas no llevan).
            $table->unique('request_uuid', 'ocon_request_unique');
            $table->foreign('request_uuid', 'ocon_request_fk')->references('uuid')->on(self::TABLE)->restrictOnDelete();
            // La lectura de la tanda (C9-2) y la supresión por persona: el último evento por persona, finalidad y canal.
            $table->index(['subject_key', 'purpose', 'channel', 'id'], 'ocon_subject_idx');
        });
    }

    private function upPostgres(): void
    {
        $this->boundLockWait();
        $this->createTable();
        $this->assertColumnsClassified();

        foreach (self::checks() as $name => [$expression]) {
            DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        // La función de guarda propia: el cuerpo de outreach_forbid() con otro nombre. Reemplazarla es idempotente
        // (`migrate:fresh` no borra funciones); si el rol no es su dueño, falla (42501).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION outreach_consent_forbid() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'outreach: % (% en %)',
                    COALESCE(TG_ARGV[0], 'operación no permitida'), TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'P0001';
            END;
            $fn$
            SQL);

        foreach (self::rowTriggers() as $name => [$event, $columns, $when, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns).' ON '.self::TABLE.' '
                .'FOR EACH ROW '.self::whenClause($when, 'pgsql')
                .'EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
        }
        DB::statement('CREATE TRIGGER ocon_forbid_truncate BEFORE TRUNCATE ON '.self::TABLE.' '
            .'FOR EACH STATEMENT EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal('las autorizaciones de contacto son de solo inserción').')');

        foreach (self::crossTriggers() as $name => [$event, $when, $violation, $reason]) {
            DB::statement(strtr(<<<'SQL'
                CREATE OR REPLACE FUNCTION {fn}() RETURNS trigger
                LANGUAGE plpgsql
                AS $fn$
                BEGIN
                    IF ({violation}) THEN
                        RAISE EXCEPTION 'outreach: % (% en %)', TG_ARGV[0], TG_OP, TG_TABLE_NAME
                            USING ERRCODE = 'P0001';
                    END IF;
                    RETURN NEW;
                END;
                $fn$
                SQL, ['{fn}' => self::crossFunction($name), '{violation}' => self::dialect($violation, 'pgsql')]));
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, null).' ON '.self::TABLE.' '
                .'FOR EACH ROW '.self::whenClause($when, 'pgsql')
                .'EXECUTE FUNCTION '.self::crossFunction($name).'('.self::literal($reason).')');
        }

        $this->revokeAppWrites();
    }

    private function downPostgres(): void
    {
        $this->boundLockWait();
        // El cerrojo ANTES de contar: nadie inserta entre el recuento y el DROP. Con `--pretend` se escribe igual.
        if (DB::connection()->pretending() || Schema::hasTable(self::TABLE)) {
            DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
        }
        $this->refuseWithRows();

        Schema::dropIfExists(self::TABLE);
        // Después de los disparadores, que dependen de ellas.
        foreach (self::ownFunctions() as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
    }

    private function upSqlite(): void
    {
        $this->createTable();
        $this->assertColumnsClassified();

        foreach (self::rowTriggers() as $name => [$event, $columns, $when, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns).' ON '.self::TABLE.' '
                .'FOR EACH ROW '.self::whenClause($when, 'sqlite')
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, $event)).'); END');
        }
        foreach (self::crossTriggers() as $name => [$event, $when, $violation, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE {$event} ON ".self::TABLE.' FOR EACH ROW '
                .'WHEN ('.($when === null ? '' : self::dialect($when, 'sqlite').') AND (').self::dialect($violation, 'sqlite').') '
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, $event)).'); END');
        }
        // Los CHECK de PG, como disparadores de INSERT (las filas solo cambian por la redacción, que tiene el suyo). Una
        // fila que nace redactada la rechaza siempre `ocon_starts_unredacted`: estos solo juzgan las que no.
        foreach (self::checks() as $name => [, $expression, $reason]) {
            DB::statement('CREATE TRIGGER '.self::sqliteTriggerFor($name).' BEFORE INSERT ON '.self::TABLE.' FOR EACH ROW '
                .'WHEN NEW.redacted_at IS NULL AND NOT ('.self::newRow($expression).') '
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, 'INSERT')).'); END');
        }
        // Sin roles: no hay privilegios que revocar.
    }

    private function downSqlite(): void
    {
        $this->refuseWithRows();

        Schema::dropIfExists(self::TABLE);
    }

    /** Sin un límite en la sesión, no se espera más de LOCK_TIMEOUT por un cerrojo (55P03 y se reintenta). */
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

    /** Cada columna es inmutable, redactable de la persona o nombre del personal, explícitamente y una sola vez. */
    private function assertColumnsClassified(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        $declared = [...self::IMMUTABLE_COLUMNS, ...self::SUBJECT_REDACTABLE_COLUMNS, ...self::STAFF_NAME_COLUMNS];
        $actual = Schema::getColumnListing(self::TABLE);
        $unclassified = array_values(array_diff($actual, $declared));
        $missing = array_values(array_diff($declared, $actual));
        $repeated = array_values(array_unique(array_diff_assoc($declared, array_unique($declared))));

        if ($unclassified !== [] || $missing !== [] || $repeated !== []) {
            throw new RuntimeException(self::TABLE.': columnas sin clasificar ['.implode(', ', $unclassified)
                .'], que no existen ['.implode(', ', $missing).'] o clasificadas dos veces ['.implode(', ', $repeated)
                .']. Cada columna es inmutable, redactable de la persona o nombre del personal, explícitamente.');
        }
    }

    /**
     * REVOKE de UPDATE, DELETE y TRUNCATE al rol de la aplicación y la
     * comprobación posterior (uno a uno: `has_table_privilege` con varios
     * privilegios dice true si tiene CUALQUIERA). Sin el rol en el clúster no
     * hay nada que revocar, salvo en producción.
     */
    private function revokeAppWrites(): void
    {
        $role = self::appRole();
        $sentencia = 'REVOKE UPDATE, DELETE, TRUNCATE ON '.self::TABLE.' FROM "'.$role.'"';

        if (DB::connection()->pretending()) {
            DB::statement($sentencia);

            return;
        }
        if (DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]) === null) {
            if (app()->environment('production')) {
                throw new RuntimeException("outreach_consents (C9): el rol de la aplicación «{$role}» no existe en este clúster y APP_ENV es production: "
                    .'el REVOKE no se puede aplicar. La migración no se registra; si el rol se llama de otra forma, '
                    .self::APP_ROLE_ENV.' en el entorno del proceso que migra.');
            }

            return;
        }

        DB::statement($sentencia);

        $puede = DB::selectOne(
            "SELECT has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d, has_table_privilege(?, ?, 'TRUNCATE')::int AS t",
            [$role, 'public.'.self::TABLE, $role, 'public.'.self::TABLE, $role, 'public.'.self::TABLE],
        );
        $todavia = array_keys(array_filter(['UPDATE' => (int) $puede->u === 1, 'DELETE' => (int) $puede->d === 1, 'TRUNCATE' => (int) $puede->t === 1]));
        if ($todavia !== []) {
            throw new RuntimeException("outreach_consents (C9): después del REVOKE, «{$role}» todavía puede ".implode(', ', $todavia).' en '.self::TABLE
                .' (heredado de otro rol o de PUBLIC, o concedido por alguien que no es el rol que migra). La migración no se registra.');
        }
    }

    /** Con filas, revertir destruiría autorizaciones (DROP no pasa por los disparadores): se niega antes de tocar nada. */
    private function refuseWithRows(): void
    {
        $rows = Schema::hasTable(self::TABLE) ? DB::table(self::TABLE)->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException("outreach_consents (C9): down() se niega a revertir el libro de autorizaciones con datos ({$rows} filas). "
                .'Revertirlo las destruiría y nunca borra nada. Para volver al código anterior no hace falta revertir el esquema: la tabla se queda.');
        }
    }

    /** En el WHEN de SQLite, las columnas de la expresión son las de `NEW`. */
    private static function newRow(string $expression): string
    {
        $columnas = [...self::IMMUTABLE_COLUMNS, ...self::SUBJECT_REDACTABLE_COLUMNS, ...self::STAFF_NAME_COLUMNS];
        usort($columnas, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return (string) preg_replace_callback(
            "/'(?:[^']|'')*'|\\b(".implode('|', $columnas).')\\b/',
            static fn (array $m): string => isset($m[1]) && $m[1] !== '' ? 'NEW.'.$m[1] : $m[0],
            $expression,
        );
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

    /** `%DISTINCT%`, `%NOT_DISTINCT%` y `%T%` en el dialecto de cada motor. */
    private static function dialect(string $sql, string $driver): string
    {
        return strtr($sql, [
            '%NOT_DISTINCT%' => $driver === 'pgsql' ? 'IS NOT DISTINCT FROM' : 'IS',
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
        return new RuntimeException("El libro de autorizaciones de contacto (C9) solo existe para pgsql y sqlite, y sus guardas son obligatorias: con «{$driver}» la migración no se aplica.");
    }
};
