<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ULTRON STUDIO · HITO 8 (voz y telefonía), paquete DB8: EL GOBIERNO DE LA VOZ.
 * Contrato: docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md, D8-6, D8-7, D8-8,
 * D8-19, D8-23, «Contratos de datos» y la Enmienda 1 (E1-4: el mensaje firmado
 * de la cadena). Informe: plans/informes-h8/informe-db.md.
 *
 * EDITADA EN SITIO el 08-11-2026 por la Enmienda 5 (E5-1, paquete S8-c): una
 * columna más en `ultron_studio_voice_agents`, `transfer_destination_hashes`
 * (json, nullable, inmutable). Se edita aquí y no en otra migración porque esta
 * no ha corrido en ningún entorno.
 *
 * Cuatro tablas NUEVAS, SOLO ADITIVAS E INERTES: crearlas no enciende nada,
 * ningún código las lee con las banderas por defecto y no alteran ninguna tabla
 * existente (solo REFERENCIAN `ultron_studio_versions` con una FK). La voz no
 * lee ni escribe la cadena del Hito 5 (`ultron_studio_publications`): lleva la
 * suya (protección 7 y auditoría §b.1, fila 15).
 *
 *  · ultron_studio_voice_profiles     — el perfil de voz, una fila por revisión
 *                                       (D8-6). Nace `draft` con su contenido
 *                                       CONGELADO (la API no tiene ruta de
 *                                       edición: cambiarlo es otra revisión) y
 *                                       solo avanza: draft → submitted →
 *                                       approved | rejected. Lo revisa OTRA
 *                                       identidad (`usvp_four_eyes_chk`).
 *  · ultron_studio_voice_agents       — el artefacto compilado y desplegado
 *                                       (D8-7): huellas de la compilación, el id
 *                                       del proveedor (una vez) y la
 *                                       verificación de la proyección. Nace
 *                                       `pending` y solo avanza a `deployed`,
 *                                       `rejected` o `failed`. `serves_canary` y
 *                                       `serves_rest` dicen qué agente atiende
 *                                       cada parte de la cadena: A LO SUMO UNO
 *                                       POR PARTE (`usva_one_canary`,
 *                                       `usva_one_rest`), como `usv_one_canary` y
 *                                       `usv_one_published` del H5.
 *  · ultron_studio_voice_publications — la CADENA DE VOZ (D8-8), de solo
 *                                       inserción: copia el patrón del H5 sin
 *                                       compartir su tabla. Cada entrada fija
 *                                       UNA parte (`scope`: canary | rest) con
 *                                       UN agente, como el mensaje que firma
 *                                       (E1-4); lo vigente de cada parte es su
 *                                       entrada de mayor seq. Encadenada por id
 *                                       (`previous_publication_id`) y por
 *                                       huella (`prev_hash` = la de la cabeza,
 *                                       64 ceros en la primera):
 *                                       `usvpub_chain_next` (como
 *                                       `uspub_chain_next` de DB7),
 *                                       `CHECK (seq >= 1)`, la huella es la de
 *                                       los bytes guardados
 *                                       (`usvpub_hash_matches_chk`, sha256 en
 *                                       PG) y REVOKE UPDATE, DELETE del rol de
 *                                       la aplicación. Una cabeza: la de
 *                                       max(seq).
 *  · ultron_studio_voice_lab_sessions — las sesiones del laboratorio hablado
 *                                       (D8-23): reserva, duración, coste,
 *                                       códigos de la guarda y el veredicto de
 *                                       OTRA identidad, que es definitivo.
 *
 * DATOS PERSONALES (Ley 1581, D8-17). Ningún teléfono en claro: los destinos de
 * transferencia del perfil (como mucho 3) van CIFRADOS
 * (`transfer_destinations_encrypted`, cast `encrypted` del modelo); en `content`
 * solo su alias (y, si el servicio quiere, su huella y sus 4 últimos dígitos).
 * Los agentes guardan HUELLAS de la compilación, nunca la carga: la carga lleva
 * los destinos en claro (D8-14) y se reconstruye en memoria. De los destinos,
 * el agente guarda solo su alias y su huella `voice-phone`
 * (`transfer_destination_hashes`, E5-1): los aprobados para sus llamadas son los
 * compilados, no los del perfil. Quien actúa se
 * guarda como INSTANTÁNEA (id + nombre + rol) SIN FK a `admins`, como en F; la
 * supresión 1581 (OPS8) tiene que cubrir también estas cuatro tablas (nombres,
 * notas y el cifrado de los destinos; `effective_json` está FIRMADO y, como el
 * del H5, no se redacta).
 *
 * LAS GUARDAS (tercera capa: ninguna ruta borra ni reescribe; los modelos lo
 * impiden; esto, que vale aunque se escriba con SQL a mano):
 *  · Disparadores de fila en los dos motores con el mismo nombre y el mismo
 *    texto de error («ultron_studio: … (OPERACIÓN en tabla)»): en PG con la
 *    función de F, `ultron_studio_forbid()`, que esta migración NO redeclara
 *    (su md5 no cambia; si no existiera, CREATE TRIGGER falla y la migración
 *    no se registra); en SQLite, `RAISE(ABORT, …)`. PG suma uno de sentencia
 *    contra TRUNCATE por tabla.
 *  · Las columnas que se congelan van por LISTA (`BEFORE UPDATE OF …`): saltan
 *    en cuanto una aparece en el SET, aunque el valor no cambie. up() comprueba
 *    que cada columna está clasificada, mutable o inmutable, una sola vez (en
 *    SQLite un `UPDATE OF` con una columna que no existe no da error y el
 *    disparador no saltaría nunca).
 *  · «Solo hacia delante»: un disparador `BEFORE UPDATE OF status … WHEN (el
 *    par OLD → NEW no está en el grafo)`, y otro de INSERT que obliga al estado
 *    inicial. Los estados finales lo son por su disparador.
 *  · «Una vez»: el id del proveedor y la observación de la proyección de un
 *    agente se escriben una vez y no cambian (`usva_bindings_once`).
 *  · Solo en PG, los CHECK de dominio y de forma (huellas de 64 hex, límites
 *    del perfil, verificación del agente…) y los de CUATRO OJOS. En SQLite, que
 *    no admite añadir un CHECK a una tabla existente, los cuatro ojos y
 *    `seq >= 1` tienen su disparador equivalente (`usvp_review_four_eyes`,
 *    `usvl_verdict_rules`, `usvpub_seq_min`); los de forma los vigila el
 *    servicio y la puerta PG es obligatoria.
 *  · La cadena, en PG con funciones PROPIAS (INVOKER, VOLATILE y la tabla
 *    CUALIFICADA con `public.`: una tabla temporal con el mismo nombre no
 *    falsea la cabeza, D-2 de DB7):
 *     — `usvpub_chain_next` → `ultron_studio_voice_publication_chain()`: por
 *       ENCIMA de la cabeza solo cabe la siguiente (seq = cabeza + 1) y
 *       apuntando a ella, por su id y por su huella (`prev_hash` = el
 *       `effective_hash` de la cabeza, o 64 ceros). Lo que no pasa de la cabeza lo deciden el UNIQUE de
 *       `seq` (23505, la carrera perdida que el servicio traduce a 409
 *       `publication_moved`) y `usvpub_seq_chk` (23514), como D-1 de DB7.
 *     — `usvpub_agents_for_calls` → `ultron_studio_voice_publication_agents()`:
 *       una entrada solo puede nombrar un agente de PUBLICACIÓN ya DESPLEGADO
 *       (verificado). Un agente de laboratorio o uno sin verificar nunca entra
 *       en la cadena, y por tanto nunca sella una llamada (D8-7). Un agente que
 *       no existe lo para la FK (23503), no esto.
 *    En SQLite, la misma regla con subconsultas en el WHEN.
 *  · Índices únicos PARCIALES con SQL crudo y el MISMO texto en los dos motores
 *    (SQLite lo guarda tal cual en `sqlite_master`).
 *
 * EL ROL DE LA APLICACIÓN pierde UPDATE y DELETE en la cadena (`REVOKE … FROM
 * iron_app`, protección 10 y DB7 b): lo ejecuta el rol que migra, el DUEÑO, y
 * después se COMPRUEBA (por tabla, por columna y por pertenencia a otros roles)
 * que ya no puede; si aún puede, la migración falla. Para ensayar con otro rol,
 * `ULTRON_STUDIO_DB_APP_ROLE` en el ENTORNO del proceso que migra (la misma
 * variable que DB7: un solo ajuste para el banco). Sin el rol en el clúster
 * (local, CI, la suite) no hay nada que revocar, salvo en `APP_ENV=production`,
 * donde es un error. En SQLite no hay roles. Perfiles, agentes y sesiones
 * conservan UPDATE (su máquina de estados) y DELETE (lo para su disparador).
 *
 * Compatibilidad PostgreSQL 14 + SQLite (la suite), como F, H4, H5 y H6:
 *  · `json`, no `jsonb`; `effective_json` es `text` (los BYTES EXACTOS que se
 *    firman, como en el H5).
 *  · Todas las FKs nacen con su tabla, todas RESTRICT. Ninguna toca la
 *    definición de `ultron_studio_versions` (memoria «SQLite reconstruye y
 *    pierde el WHERE»): solo la referencian.
 *  · Sin índice para las FKs propias: las filas no se borran ni cambian de id.
 *  · `unsigned*` es solo documental en PG.
 *
 * PostgreSQL, cerrojos: las tablas son nuevas y nadie las usa. La FK de los
 * agentes a versiones pide SHARE ROW EXCLUSIVE sobre `ultron_studio_versions`:
 * no choca con las lecturas, sí con una transacción abierta que haya ESCRITO en
 * versiones; con el `lock_timeout` de `ironbody-migrate` (5 s) la migración
 * falla limpia (55P03), no queda registrada y se reintenta. El REVOKE no toma
 * cerrojo de tabla. Ningún objeto del motor (`marketing_*`) se nombra ni se
 * toca.
 *
 * SIN TOLERAR ERRORES: si algo no se puede crear o comprobar, la migración
 * falla y no queda registrada. En PG Laravel la envuelve en una transacción; en
 * SQLite no la abre, así que la abre esta migración. Con un driver que no sea
 * pgsql ni sqlite, falla. Con `migrate --pretend` no se lee nada y la vista
 * previa enseña las sentencias.
 *
 * Lo que NO cubre:
 *  · El dueño de las tablas puede desactivar o borrar los disparadores y
 *    conceder otra vez UPDATE/DELETE; el rol de la aplicación, no (42501). La
 *    supresión 1581 (OPS8) desactiva SOLO los de UPDATE con
 *    `ultron_studio_forbid()`, en su transacción.
 *  · Que `serves_canary`/`serves_rest` coincidan con lo vigente de cada parte,
 *    que el perfil y la versión de un agente de publicación estén aprobados,
 *    que el agente de una sesión sea de laboratorio, que nadie publique ni
 *    promueva lo que creó, o que un rechazo lo firme quien tiene
 *    `ultron.approve`: lo garantiza el servicio (S8, LAB8) en la misma
 *    transacción.
 *  · SQLite: `INSERT OR REPLACE` borra la fila en conflicto sin disparar el
 *    DELETE; Laravel no lo usa.
 *  · Una restauración de SOLO DATOS pasa por los disparadores fila a fila (R-4
 *    de DB7): los de «nace en su estado inicial» rechazan perfiles, agentes y
 *    sesiones en estados posteriores (hace falta `--disable-triggers`,
 *    superusuario); la completa crea disparadores y CHECK después de los datos.
 *
 * down() SE NIEGA si hay alguna fila en cualquiera de las cuatro tablas, antes
 * de tocar nada y con los cerrojos ya tomados, y NUNCA borra filas. Sin filas
 * borra las tablas (con ellas sus disparadores, CHECK, índices y privilegios) y
 * sus dos funciones; la de F se queda. En producción no se ejecuta nunca
 * (protección 10).
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'ultron_studio:';

    /** La de F (2026_10_18_100002). No se redeclara. */
    public const FUNCTION_NAME = 'ultron_studio_forbid';

    public const PROFILES = 'ultron_studio_voice_profiles';

    public const AGENTS = 'ultron_studio_voice_agents';

    public const PUBLICATIONS = 'ultron_studio_voice_publications';

    public const LAB_SESSIONS = 'ultron_studio_voice_lab_sessions';

    /** En orden de creación; down() las borra al revés. */
    public const TABLES = [self::PROFILES, self::AGENTS, self::PUBLICATIONS, self::LAB_SESSIONS];

    /** La única de solo inserción de este paquete: la cadena (protección 10). */
    public const INSERT_ONLY_TABLES = [self::PUBLICATIONS];

    public const REVOKED_PRIVILEGES = ['UPDATE', 'DELETE'];

    public const APP_ROLE = 'iron_app';

    public const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    /** E1-4: `prev_hash` de la primera entrada. */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    // ── Clasificación de columnas (una vez cada una) ─────────────────────────

    /** Lo que un perfil congela al nacer. Incluye `id`: nadie cambia una clave primaria. */
    public const PROFILE_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'revision', 'based_on_profile_id', 'label', 'language',
        'max_duration_seconds', 'retention_days', 'record_calls', 'record_voice',
        'transfer_count', 'transfer_destinations_encrypted', 'content', 'content_hash',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    /** Lo que mueve su máquina de estados: el envío y la revisión. */
    public const PROFILE_MUTABLE_COLUMNS = [
        'status', 'submitted_by_admin_id', 'submitted_by_name', 'submitted_by_role', 'submitted_at',
        'reviewed_by_admin_id', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_at', 'review_note',
        'status_changed_at', 'updated_at',
    ];

    /** Lo que un agente congela al compilarse (con sus destinos compilados, E5-1). */
    public const AGENT_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'purpose', 'studio_version_id', 'studio_content_hash',
        'voice_profile_id', 'voice_profile_hash', 'compiler_version', 'spec_hash', 'payload_hash',
        'projection_hash', 'transfer_destination_hashes', 'provider', 'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    /** El despliegue (el id y la observación, una vez), su estado y las partes que atiende. */
    public const AGENT_MUTABLE_COLUMNS = [
        'provider_agent_id', 'observed_hash', 'observed_at', 'status', 'failure_code',
        'serves_canary', 'serves_rest', 'status_changed_at', 'updated_at',
    ];

    /** Lo que una sesión congela al abrirse: su agente, su reserva y su autoría. */
    public const LAB_SESSION_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'agent_id', 'budget_day', 'reserved_micros', 'max_seconds',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    public const LAB_SESSION_MUTABLE_COLUMNS = [
        'status', 'cost_micros', 'duration_seconds', 'url_expires_at', 'started_at', 'ended_at', 'end_reason',
        'guard_codes', 'verdict', 'verdict_note', 'verdict_by_admin_id', 'verdict_by_name', 'verdict_by_role',
        'verdict_at', 'status_changed_at', 'updated_at',
    ];

    // ── Grafos de estado (solo hacia delante) ────────────────────────────────

    /** @var array<string, list<string>> */
    public const PROFILE_TRANSITIONS = ['draft' => ['submitted'], 'submitted' => ['approved', 'rejected']];

    /** @var array<string, list<string>> */
    public const AGENT_TRANSITIONS = ['pending' => ['deployed', 'rejected', 'failed']];

    /** @var array<string, list<string>> */
    public const LAB_SESSION_TRANSITIONS = ['open' => ['ended', 'failed']];

    // ── La cadena ────────────────────────────────────────────────────────────

    public const CHAIN_TRIGGER = 'usvpub_chain_next';

    public const CHAIN_FUNCTION = 'ultron_studio_voice_publication_chain';

    public const CHAIN_REASON = 'una entrada nueva de la cadena de voz tiene que ser la siguiente a la cabeza (seq = cabeza + 1) y apuntar a ella (previous_publication_id y prev_hash)';

    public const AGENTS_TRIGGER = 'usvpub_agents_for_calls';

    public const AGENTS_FUNCTION = 'ultron_studio_voice_publication_agents';

    public const AGENTS_REASON = 'una entrada de la cadena de voz solo nombra un agente de publicación ya desplegado (nunca uno de laboratorio ni uno sin verificar)';

    /** Solo SQLite: el equivalente de `usvpub_seq_chk`. */
    public const SEQ_TRIGGER = 'usvpub_seq_min';

    public const SEQ_REASON = 'seq empieza en 1';

    /** Disparadores de sentencia contra TRUNCATE, solo en PG: nombre → [tabla, motivo]. */
    public const TRUNCATE_TRIGGERS = [
        'usvp_forbid_truncate' => [self::PROFILES, 'los perfiles de voz no se borran'],
        'usva_forbid_truncate' => [self::AGENTS, 'los agentes de voz no se borran'],
        'usvpub_forbid_truncate' => [self::PUBLICATIONS, 'la cadena de voz es de solo inserción'],
        'usvl_forbid_truncate' => [self::LAB_SESSIONS, 'las sesiones del laboratorio hablado no se borran'],
    ];

    /** CHECK de dominio, solo en PG: tabla → [nombre → [columna, valores]]. */
    public const DOMAIN_CHECKS = [
        self::PROFILES => [
            'usvp_status_chk' => ['status', ['draft', 'submitted', 'approved', 'rejected']],
            'usvp_language_chk' => ['language', ['es']],
        ],
        self::AGENTS => [
            'usva_purpose_chk' => ['purpose', ['lab', 'publication']],
            'usva_provider_chk' => ['provider', ['elevenlabs', 'fake']],
            'usva_status_chk' => ['status', ['pending', 'deployed', 'rejected', 'failed']],
        ],
        self::PUBLICATIONS => [
            'usvpub_action_chk' => ['action', ['publish', 'promote', 'rollback', 'off']],
            'usvpub_scope_chk' => ['scope', ['canary', 'rest']],
        ],
        self::LAB_SESSIONS => [
            'usvl_status_chk' => ['status', ['open', 'ended', 'failed']],
        ],
    ];

    /**
     * CHECK entre columnas, solo en PG: tabla → [nombre → expresión]. Toda
     * columna que se compara sin `IS [NOT] NULL` delante es NOT NULL o va
     * protegida por un `IS NOT NULL` en la misma conjunción: ninguna expresión
     * puede dar NULL (que un CHECK aceptaría).
     */
    public const ROW_CHECKS = [
        self::PROFILES => [
            'usvp_revision_chk' => 'revision >= 1',
            'usvp_hash_chk' => "content_hash ~ '^[0-9a-f]{64}$'",
            'usvp_duration_chk' => 'max_duration_seconds BETWEEN 60 AND 600',
            'usvp_retention_chk' => 'retention_days BETWEEN 0 AND 30',
            'usvp_privacy_chk' => 'record_calls = false AND record_voice = false',
            'usvp_transfer_chk' => 'transfer_count BETWEEN 0 AND 3 AND ((transfer_count = 0 AND transfer_destinations_encrypted IS NULL) OR (transfer_count > 0 AND transfer_destinations_encrypted IS NOT NULL))',
            'usvp_submitted_chk' => "(status = 'draft' AND submitted_at IS NULL AND submitted_by_admin_id IS NULL) OR (status <> 'draft' AND submitted_at IS NOT NULL AND submitted_by_admin_id IS NOT NULL)",
            'usvp_review_chk' => "(status IN ('draft', 'submitted') AND reviewed_at IS NULL AND reviewed_by_admin_id IS NULL AND review_note IS NULL) OR (status = 'approved' AND reviewed_at IS NOT NULL AND reviewed_by_admin_id IS NOT NULL) OR (status = 'rejected' AND reviewed_at IS NOT NULL AND reviewed_by_admin_id IS NOT NULL AND review_note IS NOT NULL)",
            'usvp_four_eyes_chk' => 'reviewed_by_admin_id IS NULL OR reviewed_by_admin_id <> created_by_admin_id',
        ],
        self::AGENTS => [
            'usva_fake_is_lab_chk' => "purpose = 'lab' OR provider = 'elevenlabs'",
            'usva_hashes_chk' => "studio_content_hash ~ '^[0-9a-f]{64}$' AND voice_profile_hash ~ '^[0-9a-f]{64}$' AND spec_hash ~ '^[0-9a-f]{64}$' AND payload_hash ~ '^[0-9a-f]{64}$' AND projection_hash ~ '^[0-9a-f]{64}$' AND (observed_hash IS NULL OR observed_hash ~ '^[0-9a-f]{64}$')",
            'usva_compiler_chk' => "compiler_version ~ '^ultron-voice-compiler/[1-9][0-9]{0,5}$'",
            'usva_agent_id_chk' => "provider_agent_id IS NULL OR provider_agent_id ~ '^[A-Za-z0-9_-]{1,128}$'",
            'usva_verification_chk' => "(status = 'pending' AND observed_hash IS NULL AND observed_at IS NULL) OR (status = 'deployed' AND provider_agent_id IS NOT NULL AND observed_hash IS NOT NULL AND observed_at IS NOT NULL AND observed_hash = projection_hash) OR (status = 'rejected' AND provider_agent_id IS NOT NULL AND observed_hash IS NOT NULL AND observed_at IS NOT NULL AND observed_hash <> projection_hash) OR status = 'failed'",
            'usva_failure_chk' => "(status = 'failed' AND failure_code IS NOT NULL AND failure_code ~ '^[a-z][a-z0-9_]{0,47}$') OR (status <> 'failed' AND failure_code IS NULL)",
            'usva_serving_chk' => "(serves_canary = false AND serves_rest = false) OR (purpose = 'publication' AND status = 'deployed')",
        ],
        self::PUBLICATIONS => [
            'usvpub_seq_chk' => 'seq >= 1',
            'usvpub_hashes_chk' => "effective_hash ~ '^[0-9a-f]{64}$' AND signature ~ '^[0-9a-f]{64}$' AND prev_hash ~ '^[0-9a-f]{64}$'",
            // La huella es la de los BYTES guardados: es lo que encadena la siguiente (E1-4).
            'usvpub_hash_matches_chk' => "effective_hash = encode(sha256(convert_to(effective_json, 'UTF8')), 'hex')",
            'usvpub_size_chk' => 'char_length(effective_json) BETWEEN 2 AND 16384',
            'usvpub_reason_chk' => "action NOT IN ('rollback', 'off') OR reason IS NOT NULL",
            // Publicar empieza en el canario (D8-19); promover lleva al resto; revertir vuelve a un agente anterior; apagar deja la parte sin agente (D8-20).
            'usvpub_shape_chk' => "(action <> 'publish' OR (scope = 'canary' AND agent_id IS NOT NULL)) AND (action <> 'promote' OR (scope = 'rest' AND agent_id IS NOT NULL)) "
                ."AND (action <> 'off' OR agent_id IS NULL) AND ((action = 'rollback' AND restores_publication_id IS NOT NULL AND agent_id IS NOT NULL) OR (action <> 'rollback' AND restores_publication_id IS NULL))",
        ],
        self::LAB_SESSIONS => [
            'usvl_limits_chk' => 'max_seconds BETWEEN 60 AND 300 AND reserved_micros >= 0 AND (cost_micros IS NULL OR cost_micros >= 0) AND (duration_seconds IS NULL OR duration_seconds >= 0)',
            'usvl_closed_chk' => "(status = 'open' AND ended_at IS NULL AND end_reason IS NULL) OR (status IN ('ended', 'failed') AND ended_at IS NOT NULL AND end_reason IS NOT NULL AND end_reason ~ '^[a-z][a-z0-9_]{0,31}$')",
            'usvl_verdict_chk' => "(verdict IS NULL AND verdict_note IS NULL AND verdict_by_admin_id IS NULL AND verdict_at IS NULL) OR (verdict IS NOT NULL AND verdict IN ('approved', 'rejected') AND status = 'ended' AND verdict_by_admin_id IS NOT NULL AND verdict_at IS NOT NULL AND (verdict = 'approved' OR verdict_note IS NOT NULL))",
            'usvl_four_eyes_chk' => 'verdict_by_admin_id IS NULL OR verdict_by_admin_id <> created_by_admin_id',
        ],
    ];

    /** Índices únicos parciales, en los dos motores: nombre → [tabla, columnas, predicado]. */
    public const PARTIAL_UNIQUE_INDEXES = [
        'usva_one_canary' => [self::AGENTS, ['serves_canary'], 'serves_canary = true'],
        'usva_one_rest' => [self::AGENTS, ['serves_rest'], 'serves_rest = true'],
    ];

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

    /**
     * El rol de la aplicación al que se revoca: `iron_app`, o el de
     * `ULTRON_STUDIO_DB_APP_ROLE` en el entorno. Va en el SQL como
     * identificador (un REVOKE no admite parámetros): solo minúsculas, dígitos
     * y `_`, empezando por letra o `_`, hasta 63.
     */
    public static function appRole(): string
    {
        $env = getenv(self::APP_ROLE_ENV);
        $role = is_string($env) && $env !== '' ? $env : self::APP_ROLE;
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new RuntimeException('ultron_studio (Hito 8): '.self::APP_ROLE_ENV.' no es un nombre de rol válido (minúsculas, dígitos y _, hasta 63).');
        }

        return $role;
    }

    /**
     * Disparadores de fila en los dos motores: nombre → [tabla, evento, columnas
     * de `UPDATE OF` (o null), condición WHEN (o null), motivo]. La condición es
     * la misma en los dos motores salvo `%DISTINCT%` (`IS DISTINCT FROM` en PG,
     * `IS NOT` en SQLite: los dos comparan con NULL sin dar NULL).
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>|null, 3: string|null, 4: string}>
     */
    public static function rowTriggers(): array
    {
        return [
            // Perfiles.
            'usvp_forbid_delete' => [self::PROFILES, 'DELETE', null, null, 'los perfiles de voz no se borran'],
            'usvp_forbid_immutable_update' => [self::PROFILES, 'UPDATE', self::PROFILE_IMMUTABLE_COLUMNS, null,
                'un perfil de voz congela al nacer su contenido, su huella y su autoría; cambiarlo es otra revisión'],
            'usvp_reviewed_is_final' => [self::PROFILES, 'UPDATE', null, "OLD.status IN ('approved', 'rejected')",
                'un perfil de voz aprobado o rechazado es definitivo'],
            'usvp_status_forward' => [self::PROFILES, 'UPDATE', ['status'], self::forwardOnly(self::PROFILE_TRANSITIONS, ['approved', 'rejected']),
                'un perfil de voz solo avanza: draft → submitted → approved o rejected'],
            'usvp_starts_draft' => [self::PROFILES, 'INSERT', null,
                "NEW.status <> 'draft' OR NEW.submitted_at IS NOT NULL OR NEW.submitted_by_admin_id IS NOT NULL OR NEW.reviewed_at IS NOT NULL OR NEW.reviewed_by_admin_id IS NOT NULL",
                'un perfil de voz nace en draft, sin envío ni revisión'],
            // Agentes.
            'usva_forbid_delete' => [self::AGENTS, 'DELETE', null, null, 'los agentes de voz no se borran'],
            'usva_forbid_immutable_update' => [self::AGENTS, 'UPDATE', self::AGENT_IMMUTABLE_COLUMNS, null,
                'un agente de voz congela al compilarse su origen, sus huellas y su autoría'],
            'usva_bindings_once' => [self::AGENTS, 'UPDATE', ['provider_agent_id', 'observed_hash', 'observed_at'],
                '(OLD.provider_agent_id IS NOT NULL AND NEW.provider_agent_id %DISTINCT% OLD.provider_agent_id) OR (OLD.observed_hash IS NOT NULL AND NEW.observed_hash %DISTINCT% OLD.observed_hash) OR (OLD.observed_at IS NOT NULL AND NEW.observed_at %DISTINCT% OLD.observed_at)',
                'un agente de voz fija una sola vez su id del proveedor y la observación de su proyección'],
            'usva_status_forward' => [self::AGENTS, 'UPDATE', ['status'], self::forwardOnly(self::AGENT_TRANSITIONS, ['rejected', 'failed']),
                'un agente de voz solo avanza: pending → deployed, rejected o failed'],
            'usva_closed_is_final' => [self::AGENTS, 'UPDATE', null, "OLD.status IN ('rejected', 'failed')",
                'un agente de voz rechazado o fallido es definitivo'],
            'usva_starts_pending' => [self::AGENTS, 'INSERT', null,
                "NEW.status <> 'pending' OR NEW.observed_hash IS NOT NULL OR NEW.observed_at IS NOT NULL OR NEW.serves_canary <> false OR NEW.serves_rest <> false",
                'un agente de voz nace pending, sin verificar y sin atender ninguna parte'],
            // La cadena.
            'usvpub_forbid_update' => [self::PUBLICATIONS, 'UPDATE', null, null, 'la cadena de voz es de solo inserción'],
            'usvpub_forbid_delete' => [self::PUBLICATIONS, 'DELETE', null, null, 'la cadena de voz es de solo inserción'],
            // Sesiones del laboratorio hablado.
            'usvl_forbid_delete' => [self::LAB_SESSIONS, 'DELETE', null, null, 'las sesiones del laboratorio hablado no se borran'],
            'usvl_forbid_immutable_update' => [self::LAB_SESSIONS, 'UPDATE', self::LAB_SESSION_IMMUTABLE_COLUMNS, null,
                'una sesión del laboratorio hablado congela al abrirse su agente, su reserva y su autoría'],
            'usvl_status_forward' => [self::LAB_SESSIONS, 'UPDATE', ['status'], self::forwardOnly(self::LAB_SESSION_TRANSITIONS, ['failed'], 'OLD.verdict IS NULL'),
                'una sesión del laboratorio hablado solo avanza: open → ended o failed'],
            'usvl_closed_is_final' => [self::LAB_SESSIONS, 'UPDATE', null, "OLD.verdict IS NOT NULL OR OLD.status = 'failed'",
                'una sesión del laboratorio hablado fallida o con veredicto es definitiva'],
            'usvl_starts_open' => [self::LAB_SESSIONS, 'INSERT', null,
                "NEW.status <> 'open' OR NEW.ended_at IS NOT NULL OR NEW.verdict IS NOT NULL OR NEW.verdict_by_admin_id IS NOT NULL OR NEW.verdict_at IS NOT NULL",
                'una sesión del laboratorio hablado nace open y sin veredicto'],
        ];
    }

    /**
     * Solo SQLite: los equivalentes de los CHECK de cuatro ojos (en PG,
     * `usvp_four_eyes_chk` y `usvl_four_eyes_chk` + `usvl_verdict_chk`). Misma
     * forma que rowTriggers().
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>|null, 3: string|null, 4: string}>
     */
    public static function sqliteOnlyRowTriggers(): array
    {
        return [
            'usvp_review_four_eyes' => [self::PROFILES, 'UPDATE', null,
                'NEW.reviewed_by_admin_id IS NOT NULL AND NEW.reviewed_by_admin_id = NEW.created_by_admin_id',
                'un perfil de voz lo revisa otra identidad (cuatro ojos)'],
            'usvl_verdict_rules' => [self::LAB_SESSIONS, 'UPDATE', null,
                "NEW.verdict IS NOT NULL AND (NEW.status <> 'ended' OR NEW.verdict_by_admin_id IS NULL OR NEW.verdict_by_admin_id = NEW.created_by_admin_id)",
                'el veredicto de una sesión del laboratorio hablado lo da otra identidad, sobre una sesión terminada (cuatro ojos)'],
        ];
    }

    /**
     * «Solo hacia delante»: el WHEN de un `BEFORE UPDATE OF status` que salta
     * si el estado cambia a algo que el grafo no admite (también a un valor que
     * no es un estado). Escribir el mismo estado no cambia nada y pasa. Las
     * filas CERRADAS (`$closed`, y las que cumplan `$closedWhen`) no las mira:
     * son de su disparador de «definitivo», que las para entero. Así una misma
     * violación da el mismo motivo en los dos motores (PG dispara por orden
     * alfabético y SQLite por orden inverso de creación). El texto vale para
     * los dos motores.
     *
     * @param  array<string, list<string>>  $graph
     * @param  list<string>  $closed
     */
    public static function forwardOnly(array $graph, array $closed = [], ?string $openWhen = null): string
    {
        $pares = [];
        foreach ($graph as $from => $to) {
            $pares[] = '(OLD.status = '.self::literal($from).' AND NEW.status IN ('.self::literalList($to).'))';
        }

        return 'OLD.status <> NEW.status'
            .($closed === [] ? '' : ' AND OLD.status NOT IN ('.self::literalList($closed).')')
            .($openWhen === null ? '' : " AND {$openWhen}")
            .' AND NOT ('.implode(' OR ', $pares).')';
    }

    private function createTables(): void
    {
        Schema::create(self::PROFILES, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Idempotencia de la creación: misma persona y mismo cuerpo → la revisión de la primera vez.
            $table->uuid('client_request_id')->unique();
            // 1, 2, 3…: cambiar un perfil es insertar otra revisión.
            $table->unsignedInteger('revision');
            $table->foreignId('based_on_profile_id')->nullable()->constrained(self::PROFILES)->restrictOnDelete();
            $table->string('label', 80);
            // `es` (D8-6).
            $table->string('language', 8);
            // 60–600 (D8-15: el agente admite 60–7200; el techo del H8 es 600).
            $table->unsignedSmallInteger('max_duration_seconds');
            // [P-8]: 0–30 días en ElevenLabs.
            $table->unsignedSmallInteger('retention_days');
            // Banderas, nunca contenido, y las dos apagadas (`usvp_privacy_chk`): la
            // grabación de la llamada [P-7] y el audio que guarda ElevenLabs
            // (`privacy.record_voice`) [P-8]. Laravel no guarda audio ni transcripciones.
            $table->boolean('record_calls');
            $table->boolean('record_voice');
            // Como mucho 3 destinos (D8-14), CIFRADOS juntos (cast `encrypted`). NULL sin destinos.
            $table->unsignedTinyInteger('transfer_count');
            $table->text('transfer_destinations_encrypted')->nullable();
            // El conjunto cerrado de D8-6 en JSON canónico, sin teléfonos en claro.
            $table->json('content');
            // `StudioContentHasher` del contenido: 64 hex.
            $table->char('content_hash', 64);
            // draft → submitted → approved | rejected.
            $table->string('status', 10);
            $table->unsignedBigInteger('created_by_admin_id');
            $table->string('created_by_name', 120);
            $table->string('created_by_role', 60)->nullable();
            $table->timestamp('created_at');
            $table->unsignedBigInteger('submitted_by_admin_id')->nullable();
            $table->string('submitted_by_name', 120)->nullable();
            $table->string('submitted_by_role', 60)->nullable();
            $table->timestamp('submitted_at')->nullable();
            // Aprueba o rechaza OTRA identidad (`usvp_four_eyes_chk`); el rechazo lleva nota.
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->string('reviewed_by_name', 120)->nullable();
            $table->string('reviewed_by_role', 60)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('status_changed_at');
            $table->timestamp('updated_at')->nullable();

            $table->index(['status', 'id'], 'usvp_status_idx');
        });

        Schema::create(self::AGENTS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // La petición que lo compiló (publicar o abrir una sesión): un reintento encuentra el suyo.
            $table->uuid('client_request_id')->nullable()->unique();
            // lab | publication. Uno de laboratorio nunca entra en la cadena (`usvpub_agents_for_calls`).
            $table->string('purpose', 12);
            $table->foreignId('studio_version_id')->constrained('ultron_studio_versions')->restrictOnDelete();
            $table->char('studio_content_hash', 64);
            $table->foreignId('voice_profile_id')->constrained(self::PROFILES)->restrictOnDelete();
            $table->char('voice_profile_hash', 64);
            // `ultron-voice-compiler/N`, la constante del compilador.
            $table->string('compiler_version', 48);
            // Las huellas, nunca la carga (lleva los destinos de transferencia en claro).
            $table->char('spec_hash', 64);
            $table->char('payload_hash', 64);
            // La proyección compilada: lo que se compara con la observada (deriva).
            $table->char('projection_hash', 64);
            // E5-1 (Enmienda 5): los destinos de transferencia COMPILADOS en el agente, los
            // únicos aprobados para sus llamadas (el perfil puede cambiar; el agente no). Lista
            // de {alias, hash}: el alias de la especificación y la huella `voice-phone` del
            // número, NUNCA el número; `[]` sin destinos; NULL solo en agentes anteriores a la
            // columna. La escribe S8 al compilar y la lee la telefonía (`VoicePublishedConfig`)
            // sin descifrar nada. Se congela con el resto de la compilación.
            $table->json('transfer_destination_hashes')->nullable();
            // elevenlabs | fake (el simulador del laboratorio; nunca en publicación).
            $table->string('provider', 16);
            // El `agent_id` de ElevenLabs: una vez (`usva_bindings_once`) y único.
            $table->string('provider_agent_id', 128)->nullable()->unique();
            // La proyección observada al desplegar (describeAgent), una vez.
            $table->char('observed_hash', 64)->nullable();
            $table->timestamp('observed_at')->nullable();
            // pending → deployed | rejected (no cuadra) | failed (el proveedor no lo creó).
            $table->string('status', 10);
            $table->string('failure_code', 48)->nullable();
            // Las partes de la cadena que atiende ahora: como mucho un agente por parte.
            $table->boolean('serves_canary')->default(false);
            $table->boolean('serves_rest')->default(false);
            $table->unsignedBigInteger('created_by_admin_id');
            $table->string('created_by_name', 120);
            $table->string('created_by_role', 60)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('status_changed_at');
            $table->timestamp('updated_at')->nullable();

            $table->index(['purpose', 'status', 'id'], 'usva_purpose_idx');
        });

        Schema::create(self::PUBLICATIONS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // 1, 2, 3…; la cabeza es max(seq). La carrera la gana uno (23505 → 409).
            $table->unsignedInteger('seq')->unique();
            $table->uuid('client_request_id')->unique();
            // publish | promote | rollback | off (D8-19, D8-20).
            $table->string('action', 10);
            // E1-4: la parte que fija esta entrada (canary | rest). Lo vigente de
            // cada parte es su entrada de mayor seq.
            $table->string('scope', 8);
            // La cabeza anterior (NULL solo en seq = 1) y su huella (64 ceros en
            // seq = 1): `usvpub_chain_next` exige las dos.
            $table->foreignId('previous_publication_id')->nullable()->constrained(self::PUBLICATIONS)->restrictOnDelete();
            $table->char('prev_hash', 64);
            // En rollback, la entrada cuyo agente vuelve.
            $table->foreignId('restores_publication_id')->nullable()->constrained(self::PUBLICATIONS)->restrictOnDelete();
            // El agente que atiende la parte (NULL en off): de publicación y desplegado (`usvpub_agents_for_calls`).
            $table->foreignId('agent_id')->nullable()->constrained(self::AGENTS)->restrictOnDelete();
            // E1-4: los BYTES EXACTOS del mensaje firmado (JSON canónico de {seq,
            // prev_hash, version_uuid, profile_uuid, spec_hash, agent_config_hash,
            // provider_agent_id, scope, actor_id, published_at}): text, nunca
            // json/jsonb. ≤ 16 384 caracteres.
            $table->text('effective_json');
            // sha256(effective_json) en hex: la huella que encadena la siguiente.
            $table->char('effective_hash', 64);
            // hex(HMAC-SHA256) con la subclave `ultron-studio-voice-chain` (E1-4).
            $table->char('signature', 64);
            // {mode: required|waived, …} [P-19].
            $table->json('lab_gate');
            // Recuentos de la evidencia del canario (promote), sin ids ni textos.
            $table->json('evidence')->nullable();
            // Obligatorio en rollback y off.
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('actor_admin_id');
            $table->string('actor_name', 120);
            $table->string('actor_role', 60)->nullable();
            // Solo inserción: sin updated_at.
            $table->timestamp('created_at');

            // Lo vigente de cada parte: su entrada de mayor seq.
            $table->index(['scope', 'seq'], 'usvpub_scope_idx');
        });

        Schema::create(self::LAB_SESSIONS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('client_request_id')->unique();
            // El agente de laboratorio (su versión y su perfil, por él).
            $table->foreignId('agent_id')->constrained(self::AGENTS)->restrictOnDelete();
            // open → ended | failed.
            $table->string('status', 10);
            // La reserva atómica del día de Bogotá (como el H4).
            $table->date('budget_day');
            $table->unsignedBigInteger('reserved_micros');
            $table->unsignedBigInteger('cost_micros')->nullable();
            // Como mucho 5 minutos (D8-23).
            $table->unsignedSmallInteger('max_seconds');
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            // La URL firmada caduca a los 15 minutos (la URL no se guarda).
            $table->timestamp('url_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();
            // Solo códigos de la guarda y números de turno, nunca texto.
            $table->json('guard_codes')->nullable();
            // El veredicto de OTRA identidad, una vez y definitivo.
            $table->string('verdict', 10)->nullable();
            $table->string('verdict_note', 500)->nullable();
            $table->unsignedBigInteger('verdict_by_admin_id')->nullable();
            $table->string('verdict_by_name', 120)->nullable();
            $table->string('verdict_by_role', 60)->nullable();
            $table->timestamp('verdict_at')->nullable();
            $table->unsignedBigInteger('created_by_admin_id');
            $table->string('created_by_name', 120);
            $table->string('created_by_role', 60)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('status_changed_at');
            $table->timestamp('updated_at')->nullable();

            $table->index(['budget_day', 'status'], 'usvl_budget_idx');
            $table->index(['created_by_admin_id', 'budget_day'], 'usvl_person_day_idx');
            $table->index(['agent_id', 'id'], 'usvl_agent_idx');
        });
    }

    private function upPostgres(): void
    {
        $this->createTables();
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

        foreach (self::rowTriggers() as $name => [$table, $event, $columns, $when, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns)." ON {$table} "
                .'FOR EACH ROW '.self::whenClause($when, 'pgsql')
                .'EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
        }
        foreach (self::TRUNCATE_TRIGGERS as $name => [$table, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE TRUNCATE ON {$table} "
                .'FOR EACH STATEMENT EXECUTE FUNCTION '.self::FUNCTION_NAME.'('.self::literal($reason).')');
        }

        // La cabeza, CONFIRMADA y con la tabla cualificada. Por encima de ella
        // solo la siguiente, apuntando a ella por su id y por su huella (E1-4);
        // lo que no pasa de la cabeza lo deciden el UNIQUE de seq (23505) y
        // usvpub_seq_chk (23514).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ultron_studio_voice_publication_chain() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            DECLARE
                cabeza_id bigint;
                cabeza_seq bigint;
                cabeza_hash text;
            BEGIN
                SELECT p.id, p.seq, p.effective_hash INTO cabeza_id, cabeza_seq, cabeza_hash
                  FROM public.ultron_studio_voice_publications AS p
                 ORDER BY p.seq DESC
                 LIMIT 1;
                cabeza_seq := COALESCE(cabeza_seq, 0);
                cabeza_hash := COALESCE(cabeza_hash, repeat('0', 64));
                IF NEW.seq > cabeza_seq
                   AND (NEW.seq <> cabeza_seq + 1 OR NEW.previous_publication_id IS DISTINCT FROM cabeza_id
                        OR NEW.prev_hash IS DISTINCT FROM cabeza_hash) THEN
                    RAISE EXCEPTION 'ultron_studio: % (% en %)', TG_ARGV[0], TG_OP, TG_TABLE_NAME
                        USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END;
            $fn$
            SQL);
        DB::statement('CREATE TRIGGER '.self::CHAIN_TRIGGER.' BEFORE INSERT ON '.self::PUBLICATIONS.' '
            .'FOR EACH ROW EXECUTE FUNCTION '.self::CHAIN_FUNCTION.'('.self::literal(self::CHAIN_REASON).')');

        // Un agente que existe y no es de publicación desplegado no entra en la
        // cadena. Uno que no existe lo para la FK (23503), que se comprueba
        // después de este disparador.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ultron_studio_voice_publication_agents() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                IF EXISTS (
                    SELECT 1
                      FROM public.ultron_studio_voice_agents AS a
                     WHERE a.id = NEW.agent_id
                       AND (a.purpose <> 'publication' OR a.status <> 'deployed')
                ) THEN
                    RAISE EXCEPTION 'ultron_studio: % (% en %)', TG_ARGV[0], TG_OP, TG_TABLE_NAME
                        USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END;
            $fn$
            SQL);
        DB::statement('CREATE TRIGGER '.self::AGENTS_TRIGGER.' BEFORE INSERT ON '.self::PUBLICATIONS.' '
            .'FOR EACH ROW EXECUTE FUNCTION '.self::AGENTS_FUNCTION.'('.self::literal(self::AGENTS_REASON).')');

        $this->createPartialUniqueIndexes();

        $this->revokeAppWrites();
    }

    private function downPostgres(): void
    {
        // Los cerrojos ANTES de contar: nadie inserta entre el recuento y el
        // DROP. Las tablas y después versiones (la FK de los agentes), el orden
        // de up(). Con `--pretend` las lecturas devuelven vacío: se escribe
        // igual, para que la vista previa enseñe el cerrojo.
        $pretending = DB::connection()->pretending();
        $tables = array_values(array_filter(self::TABLES, static fn (string $table): bool => $pretending || Schema::hasTable($table)));
        if ($tables !== []) {
            DB::statement('LOCK TABLE '.implode(', ', [...$tables, 'ultron_studio_versions']).' IN ACCESS EXCLUSIVE MODE');
        }
        $this->refuseWithRows();

        // Con la tabla se van sus disparadores, CHECK, índices y privilegios.
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        // Después de los disparadores, que dependen de ellas. La de F se queda.
        DB::statement('DROP FUNCTION IF EXISTS '.self::AGENTS_FUNCTION.'()');
        DB::statement('DROP FUNCTION IF EXISTS '.self::CHAIN_FUNCTION.'()');
    }

    private function upSqlite(): void
    {
        $this->createTables();
        $this->assertColumnsClassified();

        foreach ([...self::rowTriggers(), ...self::sqliteOnlyRowTriggers()] as $name => [$table, $event, $columns, $when, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns)." ON {$table} "
                .'FOR EACH ROW '.self::whenClause($when, 'sqlite')
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, $event, $table)).'); END');
        }

        $cabezaSeq = 'COALESCE((SELECT max(seq) FROM '.self::PUBLICATIONS.'), 0)';
        $cabezaId = '(SELECT id FROM '.self::PUBLICATIONS.' ORDER BY seq DESC LIMIT 1)';
        $cabezaHash = 'COALESCE((SELECT effective_hash FROM '.self::PUBLICATIONS.' ORDER BY seq DESC LIMIT 1), '.self::literal(self::GENESIS_HASH).')';
        DB::statement('CREATE TRIGGER '.self::CHAIN_TRIGGER.' BEFORE INSERT ON '.self::PUBLICATIONS.' FOR EACH ROW '
            ."WHEN (NEW.seq > {$cabezaSeq} AND (NEW.seq <> {$cabezaSeq} + 1 OR NEW.previous_publication_id IS NOT {$cabezaId} OR NEW.prev_hash IS NOT {$cabezaHash})) "
            .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message(self::CHAIN_REASON, 'INSERT', self::PUBLICATIONS)).'); END');
        DB::statement('CREATE TRIGGER '.self::SEQ_TRIGGER.' BEFORE INSERT ON '.self::PUBLICATIONS.' FOR EACH ROW '
            .'WHEN (NEW.seq < 1) '
            .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message(self::SEQ_REASON, 'INSERT', self::PUBLICATIONS)).'); END');
        DB::statement('CREATE TRIGGER '.self::AGENTS_TRIGGER.' BEFORE INSERT ON '.self::PUBLICATIONS.' FOR EACH ROW '
            .'WHEN (EXISTS (SELECT 1 FROM '.self::AGENTS.' AS a WHERE a.id = NEW.agent_id '
            ."AND (a.purpose <> 'publication' OR a.status <> 'deployed'))) "
            .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message(self::AGENTS_REASON, 'INSERT', self::PUBLICATIONS)).'); END');

        $this->createPartialUniqueIndexes();
        // Sin roles: no hay privilegios que revocar.
    }

    private function downSqlite(): void
    {
        $this->refuseWithRows();

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Cada columna de perfiles, agentes y sesiones es mutable o inmutable,
     * explícitamente y una sola vez; la cadena es de solo inserción entera. Si
     * una lista nombra una columna que no existe, falta una o se repite, esto
     * falla antes de crear ninguna guarda. Con `--pretend` no se lee nada.
     */
    private function assertColumnsClassified(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        foreach ([
            self::PROFILES => [self::PROFILE_IMMUTABLE_COLUMNS, self::PROFILE_MUTABLE_COLUMNS],
            self::AGENTS => [self::AGENT_IMMUTABLE_COLUMNS, self::AGENT_MUTABLE_COLUMNS],
            self::LAB_SESSIONS => [self::LAB_SESSION_IMMUTABLE_COLUMNS, self::LAB_SESSION_MUTABLE_COLUMNS],
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

    /** El mismo texto en los dos motores; SQLite lo guarda tal cual en `sqlite_master`. */
    private function createPartialUniqueIndexes(): void
    {
        foreach (self::PARTIAL_UNIQUE_INDEXES as $name => [$table, $columns, $predicate]) {
            DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} (".implode(', ', $columns).") WHERE {$predicate}");
        }
    }

    /**
     * REVOKE UPDATE, DELETE del rol de la aplicación sobre la cadena, y la
     * comprobación de que de verdad ya no puede (por tabla, por columna y por
     * pertenencia a otros roles: `has_any_column_privilege` y
     * `has_table_privilege` miran todo eso). Sin el rol en el clúster no hay
     * nada que revocar, salvo en producción, donde es un error.
     */
    private function revokeAppWrites(): void
    {
        $role = self::appRole();
        $revoke = 'REVOKE '.implode(', ', self::REVOKED_PRIVILEGES).' ON '.implode(', ', self::INSERT_ONLY_TABLES).' FROM "'.$role.'"';

        if (DB::connection()->pretending()) {
            DB::statement($revoke);

            return;
        }
        if (! self::roleExists($role)) {
            if (app()->environment('production')) {
                throw new RuntimeException("ultron_studio (Hito 8): el rol de la aplicación «{$role}» no existe en este clúster y APP_ENV es production: "
                    .'el REVOKE de UPDATE y DELETE sobre la cadena de voz no se puede aplicar. La migración no se registra; '
                    .'si el rol se llama de otra forma, '.self::APP_ROLE_ENV.' en el entorno del proceso que migra.');
            }

            return;
        }

        DB::statement($revoke);

        $todavia = [];
        foreach (self::INSERT_ONLY_TABLES as $table) {
            $puede = DB::selectOne(
                "SELECT has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d",
                [$role, 'public.'.$table, $role, 'public.'.$table],
            );
            foreach (['UPDATE' => $puede->u, 'DELETE' => $puede->d] as $privilegio => $tiene) {
                if ((int) $tiene === 1) {
                    $todavia[] = "{$privilegio} en {$table}";
                }
            }
        }
        if ($todavia !== []) {
            throw new RuntimeException("ultron_studio (Hito 8): después del REVOKE, «{$role}» todavía puede ".implode(', ', $todavia)
                .' (heredado de otro rol o de PUBLIC, o concedido por alguien que no es el rol que migra). La migración no se registra.');
        }
    }

    private static function roleExists(string $role): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]) !== null;
    }

    /**
     * Protección 10: con filas, revertir el esquema destruiría el gobierno de
     * la voz (DROP no pasa por los disparadores de fila). Se niega con un
     * mensaje claro, antes de tocar nada, y nunca borra.
     */
    private function refuseWithRows(): void
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        if (array_sum($rows) > 0) {
            throw new RuntimeException('ultron_studio (Hito 8): down() se niega a revertir el gobierno de la voz con datos '
                ."({$rows[self::PROFILES]} perfiles de voz, {$rows[self::AGENTS]} agentes, {$rows[self::PUBLICATIONS]} entradas de la cadena de voz "
                ."y {$rows[self::LAB_SESSIONS]} sesiones del laboratorio hablado). Revertirlo los destruiría y nunca borra nada. "
                .'Para volver al código anterior no hace falta revertir el esquema (D8-20, nivel 4: las tablas se quedan).');
        }
    }

    /** @param  list<string>|null  $columns */
    private static function eventClause(string $event, ?array $columns): string
    {
        return $columns === null ? $event : $event.' OF '.implode(', ', $columns);
    }

    /** `WHEN (…) ` con su espacio final, o nada. */
    private static function whenClause(?string $when, string $driver): string
    {
        if ($when === null) {
            return '';
        }

        return 'WHEN ('.str_replace('%DISTINCT%', $driver === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT', $when).') ';
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
        return new RuntimeException("El gobierno de la voz del Hito 8 de ULTRON Studio solo existe para pgsql y sqlite, y sus guardas son obligatorias: con «{$driver}» la migración no se aplica.");
    }
};
