<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ULTRON STUDIO · HITO 9 (autonomía comercial), paquete DB9: EL GOBIERNO DE
 * LAS ESTRATEGIAS. Contrato: docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md,
 * D9-6, D9-11 (11.0–11.5), D9-12, §6.1 y la «Congelación» (P9-4: cuatro ojos
 * POR PERSONA verificada). Informe: plans/informes-h9/informe-db.md.
 *
 * Siete tablas NUEVAS, SOLO ADITIVAS E INERTES (protección 11): crearlas no
 * enciende nada y ningún código las lee con las banderas por defecto. De lo
 * que ya existe solo REFERENCIAN `identities` (FK RESTRICT del vínculo,
 * §6.1) y AMPLÍAN el CHECK del libro (`usae_subject_type_chk`, 7 → 11
 * sujetos, §6.1 y D9-11.5). No nombran la cadena del H5 ni la de voz del H8.
 *
 *  · ultron_studio_person_bindings      — el vínculo cuenta → persona (11.2).
 *                                         `crm`: lo crea una persona para OTRA
 *                                         cuenta y lo verifica una SEGUNDA
 *                                         persona ya vinculada, distinta de la
 *                                         vinculada y de quien lo creó;
 *                                         `bootstrap`: las dos primeras, por
 *                                         consola, verificadas al nacer y solo
 *                                         mientras no haya dos vínculos vivos.
 *                                         Se verifica una vez, se revoca una
 *                                         vez, no se borra; uno sin revocar por
 *                                         cuenta (`uspb_one_active`).
 *  · ultron_studio_strategies           — identidad + borrador compartido:
 *                                         `key` y `purpose` inmutables; cada
 *                                         escritura del borrador sube
 *                                         `draft_revision` en 1 (el UPDATE
 *                                         condicionado de `baseRevision`); se
 *                                         archiva una vez y sin versiones vivas.
 *  · ultron_studio_message_templates    — una fila por versión de plantilla:
 *                                         contenido canónico CONGELADO desde
 *                                         que nace (D9-6: no se edita, se crea
 *                                         la siguiente) y comprobado contra su
 *                                         huella; DRAFT → APPROVED | REJECTED;
 *                                         APPROVED → RETIRED; la revisa OTRA
 *                                         persona vinculada. WhatsApp: la
 *                                         categoría casa con la finalidad
 *                                         (cobranza solo `utility`, comercial
 *                                         solo `marketing`) y lleva la línea de
 *                                         baja (`optOutLine: true`, Ley 2300).
 *  · ultron_studio_strategy_versions    — contenido inmutable (texto canónico,
 *                                         nunca json/jsonb, huella de los
 *                                         bytes) con el ciclo del H5 por
 *                                         disparador; nace VALIDATED o
 *                                         REVIEW_REQUIRED; la aprueba otra
 *                                         persona Y otra cuenta, vinculadas; la
 *                                         simulación que cita la aprobación es
 *                                         de esa versión, de esa huella y sin
 *                                         bloqueantes; una CANARY y una
 *                                         PUBLISHED por estrategia.
 *  · ultron_studio_strategy_simulations — solo inserción: recuentos y
 *                                         hallazgos, nunca trazas por persona.
 *  · ultron_studio_strategy_reviews     — solo inserción, una por versión: de
 *                                         otra persona vinculada y de la huella
 *                                         exacta.
 *  · ultron_studio_strategy_publications — la CADENA firmada (11.3), de solo
 *                                         inserción: la siguiente a la cabeza,
 *                                         encadenada por id y por huella
 *                                         (`prev_hash`, 64 ceros la primera),
 *                                         `seq >= 1` y la huella de los bytes
 *                                         firmados. Publica y promueve una
 *                                         persona vinculada distinta de la
 *                                         autora; reanuda una vinculada.
 *
 * DATOS PERSONALES (Ley 1581). Ninguna columna lleva teléfono, documento ni
 * texto de un mensaje a una persona: la persona es el id de `identities`
 * (nunca su documento, R9-6) y quien actúa, la instantánea de F (id + nombre
 * + rol, SIN FK a `admins`). La supresión 1581 (OPS9) cubre los nombres del
 * personal, las notas y los motivos; `effective_json` está FIRMADO y, como el
 * del H5 y el del H8, no se redacta.
 *
 * LAS GUARDAS (tercera capa; valen aunque se escriba con SQL a mano):
 *  · Disparadores de fila en los dos motores con el mismo nombre y el mismo
 *    texto («ultron_studio: … (OPERACIÓN en tabla)»): en PG con la función de
 *    F, `ultron_studio_forbid()`, que esta migración NO redeclara (su md5 no
 *    cambia); en SQLite, `RAISE(ABORT, …)`. PG suma uno de sentencia contra
 *    TRUNCATE por tabla.
 *  · Columnas congeladas por LISTA (`BEFORE UPDATE OF …`), clasificadas una
 *    sola vez (up() lo comprueba). «Solo hacia delante» con el grafo del H5
 *    (StudioPublications) y «una vez» para la aprobación, la revisión y la
 *    verificación.
 *  · Las guardas ENTRE TABLAS (el vínculo verificado de quien aprueba, revisa,
 *    publica o es autor; la simulación de la aprobación; la cadena; archivar
 *    sin versiones vivas; números seguidos): en PG, una función PROPIA por
 *    guarda (`ultron_studio_chk_*` y `ultron_studio_strategy_publication_chain`),
 *    plpgsql, INVOKER y VOLATILE, con las tablas CUALIFICADAS con `public.`
 *    (una tabla temporal con el mismo nombre no las falsea, D-2 de DB7); en
 *    SQLite, la misma condición en el WHEN.
 *  · Solo en PG, los CHECK de dominio y de forma (huellas, tamaños, la forma
 *    de cada acción de la cadena, la categoría de WhatsApp…) y los de CUATRO
 *    OJOS (`ussv_four_eyes_chk`, `usmt_four_eyes_chk`, `uspb_four_eyes_chk`).
 *    En SQLite, los de cuatro ojos y `seq >= 1` tienen su disparador
 *    equivalente; los de forma los vigila el servicio y la puerta PG es
 *    obligatoria.
 *  · Índices únicos PARCIALES con el MISMO texto en los dos motores.
 *
 * EL ROL DE LA APLICACIÓN (`iron_app`, o `ULTRON_STUDIO_DB_APP_ROLE` en el
 * entorno del proceso que migra: la variable de DB7/DB8) pierde UPDATE y
 * DELETE en las tres de solo inserción (cadena, revisiones y simulaciones) y
 * DELETE en las cuatro con máquina de estados (ninguna fila de gobierno se
 * borra nunca); después se COMPRUEBA que ya no puede, por tabla, por columna
 * y por pertenencia a otros roles. Sin el rol en el clúster no hay nada que
 * revocar, salvo en `APP_ENV=production`, donde es un error.
 *
 * Compatibilidad PostgreSQL 14 + SQLite (la suite): `json`, no `jsonb`; el
 * contenido canónico y los bytes firmados, `text`; FKs RESTRICT nacidas con su
 * tabla y con nombre corto explícito; `unsigned*` solo documental en PG.
 *
 * CERROJOS (PG). Si la sesión no trae `lock_timeout` (el de `ironbody-migrate`
 * es de 5 s), la migración pone `SET LOCAL lock_timeout = '5s'`: nunca espera
 * sin límite. La FK del vínculo pide SHARE ROW EXCLUSIVE sobre `identities`
 * (no choca con lectores; sí con una transacción abierta que haya ESCRITO en
 * identidades: falla limpia con 55P03 y se reintenta). El libro va AL FINAL:
 * `ALTER TABLE … DROP/ADD CONSTRAINT` en UNA sentencia pide ACCESS EXCLUSIVE
 * sobre `ultron_studio_audit_events` y choca con cualquier transacción que lo
 * haya tocado, también leyéndolo. El REVOKE no toma cerrojo de tabla.
 *
 * SIN TOLERAR ERRORES: si algo no se puede crear o comprobar, la migración
 * falla y no queda registrada (en PG, la transacción de Laravel y un SAVEPOINT
 * propio; en SQLite, la transacción que abre esta migración). Con un driver
 * que no sea pgsql ni sqlite, falla. Con `migrate --pretend` no se lee nada.
 *
 * Lo que NO cubre:
 *  · El dueño de las tablas puede desactivar o borrar los disparadores y
 *    conceder otra vez privilegios; el rol de la aplicación, no (42501).
 *  · Que lo que dice `content_json` sea una estrategia válida, que la firma
 *    sea buena, que la cabeza de la cadena coincida con el estado de las
 *    versiones o que la versión de un `publish` esté APPROVED: lo garantiza el
 *    servicio (S9) en la misma transacción, y el lector de la cadena verifica
 *    la firma (11.3).
 *  · SQLite: `INSERT OR REPLACE` borra sin disparar el DELETE; Laravel no lo usa.
 *  · Una restauración de SOLO DATOS pasa por los disparadores fila a fila: los
 *    de «nace en su estado inicial» rechazan versiones y plantillas en estados
 *    posteriores (`--disable-triggers`, superusuario); la completa crea los
 *    disparadores después de los datos.
 *
 * down() SE NIEGA si hay alguna fila en las siete tablas o algún evento del
 * libro con un sujeto del Hito 9, antes de tocar nada y con los cerrojos ya
 * tomados, y NUNCA borra filas. Sin filas devuelve el CHECK del libro a los 7
 * sujetos del H6 y borra las tablas y las funciones propias; la de F se queda.
 * En producción no se ejecuta nunca (protección 11).
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'ultron_studio:';

    /** La de F (2026_10_18_100002). No se redeclara. */
    public const FUNCTION_NAME = 'ultron_studio_forbid';

    /** Prefijo de las funciones propias de las guardas entre tablas. */
    public const CHECK_FUNCTION_PREFIX = 'ultron_studio_chk_';

    public const BINDINGS = 'ultron_studio_person_bindings';

    public const STRATEGIES = 'ultron_studio_strategies';

    public const TEMPLATES = 'ultron_studio_message_templates';

    public const VERSIONS = 'ultron_studio_strategy_versions';

    public const SIMULATIONS = 'ultron_studio_strategy_simulations';

    public const REVIEWS = 'ultron_studio_strategy_reviews';

    public const PUBLICATIONS = 'ultron_studio_strategy_publications';

    public const AUDIT = 'ultron_studio_audit_events';

    /** En orden de creación (cada una después de las que referencia); down() las borra al revés. */
    public const TABLES = [self::BINDINGS, self::STRATEGIES, self::TEMPLATES, self::VERSIONS, self::SIMULATIONS, self::REVIEWS, self::PUBLICATIONS];

    /** Solo inserción (§6.1): sin UPDATE ni DELETE para la aplicación. */
    public const INSERT_ONLY_TABLES = [self::SIMULATIONS, self::REVIEWS, self::PUBLICATIONS];

    /** Con máquina de estados: conservan UPDATE, pierden DELETE (ninguna fila de gobierno se borra). */
    public const STATEFUL_TABLES = [self::BINDINGS, self::STRATEGIES, self::TEMPLATES, self::VERSIONS];

    public const APP_ROLE = 'iron_app';

    public const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    /** Lo que espera la migración como mucho por un cerrojo si la sesión no trae límite. */
    public const LOCK_TIMEOUT = '5s';

    /** E1-4 del H8 y 11.3: `prev_hash` de la primera entrada. */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public const SUBJECT_CHECK = 'usae_subject_type_chk';

    /** Lo que admite el libro desde el H6; a esto vuelve down(). */
    public const H6_SUBJECT_TYPES = ['draft', 'version', 'rule', 'system', 'campaign_link', 'canary_number', 'integration'];

    /** D9-11.5: los cuatro sujetos del Hito 9 (caben en `varchar(16)`: `strategy_version` y `message_template` tienen 16). */
    public const H9_SUBJECT_TYPES = ['strategy', 'strategy_version', 'message_template', 'person_binding'];

    public const PURPOSES = ['commercial', 'collections', 'service'];

    /** Los 9 alcanzables de `StudioVersionStatus` (11.1; VALIDATING sigue fuera). */
    public const VERSION_STATUSES = ['DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED', 'CANARY', 'PUBLISHED', 'RETIRED', 'ROLLED_BACK'];

    /** Los que llevan una aprobación (de otra persona) detrás. */
    public const APPROVED_STATUSES = ['APPROVED', 'CANARY', 'PUBLISHED', 'RETIRED', 'ROLLED_BACK'];

    /**
     * El ciclo del H5 (StudioPublications), copiado: congelar → VALIDATED |
     * REVIEW_REQUIRED (11.0); aprobar o rechazar; publicar APPROVED → CANARY;
     * promover CANARY → PUBLISHED (la PUBLISHED anterior → RETIRED); revertir
     * CANARY | PUBLISHED → ROLLED_BACK (y una RETIRED íntegra → PUBLISHED);
     * apagar CANARY → ROLLED_BACK y PUBLISHED → RETIRED. REJECTED y
     * ROLLED_BACK son finales; DRAFT no nace (una versión nace validada).
     *
     * @var array<string, list<string>>
     */
    public const VERSION_TRANSITIONS = [
        'VALIDATED' => ['APPROVED', 'REJECTED'],
        'REVIEW_REQUIRED' => ['APPROVED', 'REJECTED'],
        'APPROVED' => ['CANARY'],
        'CANARY' => ['PUBLISHED', 'ROLLED_BACK'],
        'PUBLISHED' => ['RETIRED', 'ROLLED_BACK'],
        'RETIRED' => ['PUBLISHED'],
    ];

    public const VERSION_FINAL_STATUSES = ['REJECTED', 'ROLLED_BACK'];

    public const TEMPLATE_STATUSES = ['DRAFT', 'APPROVED', 'REJECTED', 'RETIRED'];

    /** @var array<string, list<string>> */
    public const TEMPLATE_TRANSITIONS = ['DRAFT' => ['APPROVED', 'REJECTED'], 'APPROVED' => ['RETIRED']];

    public const TEMPLATE_FINAL_STATUSES = ['REJECTED', 'RETIRED'];

    public const PUBLICATION_ACTIONS = ['publish', 'promote', 'pause', 'resume', 'rollback', 'retire'];

    /** `key` de una estrategia y de una plantilla (§6.1). */
    public const KEY_PATTERN = '^[a-z0-9][a-z0-9_-]{2,47}$';

    /** `OutreachCatalog::VERSION` (D9-3): `outreach-catalog/2026-11-10.1`. */
    public const CATALOG_VERSION_PATTERN = '^outreach-catalog/[0-9]{4}-[0-9]{2}-[0-9]{2}\.[1-9][0-9]{0,3}$';

    /** La constante del evaluador (D9-12): un código con versión, sin espacios. */
    public const EVALUATOR_VERSION_PATTERN = '^[a-z][a-z0-9-]{1,30}/[0-9A-Za-z._-]{1,16}$';

    // ── Clasificación de columnas (una vez cada una) ─────────────────────────

    public const BINDING_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'origin', 'admin_id', 'identity_id', 'bootstrap_authorization',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    public const BINDING_MUTABLE_COLUMNS = [
        'verified_by_admin_id', 'verified_by_name', 'verified_by_identity_id', 'verified_at',
        'revoked_by_admin_id', 'revoked_by_name', 'revoked_at', 'revoke_reason', 'updated_at',
    ];

    public const STRATEGY_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'key', 'purpose', 'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    public const STRATEGY_MUTABLE_COLUMNS = [
        'name', 'draft_json', 'draft_revision', 'draft_updated_by_admin_id', 'draft_updated_by_name', 'draft_updated_at',
        'archived_at', 'archived_by_admin_id', 'archived_by_name', 'updated_at',
    ];

    public const TEMPLATE_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'key', 'number', 'channel', 'purpose', 'category', 'content_json', 'content_hash',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_by_person_id', 'created_at',
    ];

    public const TEMPLATE_MUTABLE_COLUMNS = [
        'status', 'reviewed_by_admin_id', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_by_person_id', 'reviewed_at',
        'review_note', 'status_changed_at', 'updated_at',
    ];

    public const VERSION_IMMUTABLE_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'strategy_id', 'number', 'content_json', 'content_hash', 'catalog_version',
        'evaluator_version', 'studio_ref_kind', 'studio_version_uuid', 'studio_content_hash', 'validation_json',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_by_person_id', 'created_at',
    ];

    public const VERSION_MUTABLE_COLUMNS = [
        'status', 'simulation_uuid', 'simulation_hash', 'approved_by_admin_id', 'approved_by_name', 'approved_by_role',
        'approved_by_person_id', 'approved_at', 'status_changed_at', 'updated_at',
    ];

    // ── La cadena ────────────────────────────────────────────────────────────

    public const CHAIN_TRIGGER = 'usspub_chain_next';

    public const CHAIN_FUNCTION = 'ultron_studio_strategy_publication_chain';

    public const CHAIN_REASON = 'una entrada nueva de la cadena de estrategias tiene que ser la siguiente a la cabeza (seq = cabeza + 1) y apuntar a ella (previous_publication_id y prev_hash)';

    /** Solo SQLite: el equivalente de `usspub_seq_chk`. */
    public const SEQ_TRIGGER = 'usspub_seq_min';

    public const SEQ_REASON = 'seq empieza en 1';

    /** Disparadores de sentencia contra TRUNCATE, solo en PG: nombre → [tabla, motivo]. */
    public const TRUNCATE_TRIGGERS = [
        'uspb_forbid_truncate' => [self::BINDINGS, 'los vínculos de personas se revocan, no se borran'],
        'usst_forbid_truncate' => [self::STRATEGIES, 'las estrategias se archivan, no se borran'],
        'usmt_forbid_truncate' => [self::TEMPLATES, 'las plantillas se retiran, no se borran'],
        'ussv_forbid_truncate' => [self::VERSIONS, 'las versiones de estrategia no se borran'],
        'usss_forbid_truncate' => [self::SIMULATIONS, 'las simulaciones de estrategia son de solo inserción'],
        'ussr_forbid_truncate' => [self::REVIEWS, 'las revisiones de estrategia son de solo inserción'],
        'usspub_forbid_truncate' => [self::PUBLICATIONS, 'la cadena de estrategias es de solo inserción'],
    ];

    /** CHECK de dominio, solo en PG: tabla → [nombre → [columna, valores]]. */
    public const DOMAIN_CHECKS = [
        self::BINDINGS => [
            'uspb_origin_chk' => ['origin', ['crm', 'bootstrap']],
        ],
        self::STRATEGIES => [
            'usst_purpose_chk' => ['purpose', self::PURPOSES],
        ],
        self::TEMPLATES => [
            'usmt_channel_chk' => ['channel', ['whatsapp', 'assisted', 'voice']],
            'usmt_purpose_chk' => ['purpose', self::PURPOSES],
            'usmt_status_chk' => ['status', self::TEMPLATE_STATUSES],
        ],
        self::VERSIONS => [
            'ussv_status_chk' => ['status', self::VERSION_STATUSES],
            'ussv_studio_ref_kind_chk' => ['studio_ref_kind', ['version', 'baseline']],
        ],
        self::SIMULATIONS => [
            'usss_kind_chk' => ['kind', ['synthetic', 'sample']],
        ],
        self::REVIEWS => [
            'ussr_decision_chk' => ['decision', ['approved', 'rejected']],
        ],
        self::PUBLICATIONS => [
            'usspub_action_chk' => ['action', self::PUBLICATION_ACTIONS],
            'usspub_simulation_gate_chk' => ['simulation_gate', ['required', 'waived']],
        ],
    ];

    /** Índices únicos parciales, en los dos motores: nombre → [tabla, columnas, predicado]. */
    public const PARTIAL_UNIQUE_INDEXES = [
        'uspb_one_active' => [self::BINDINGS, ['admin_id'], 'revoked_at IS NULL'],
        'ussv_one_canary' => [self::VERSIONS, ['strategy_id'], "status = 'CANARY'"],
        'ussv_one_published' => [self::VERSIONS, ['strategy_id'], "status = 'PUBLISHED'"],
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

    /** Los 11 sujetos que admite el libro con el Hito 9. @return list<string> */
    public static function h9SubjectTypes(): array
    {
        return [...self::H6_SUBJECT_TYPES, ...self::H9_SUBJECT_TYPES];
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
            throw new RuntimeException('ultron_studio (Hito 9): '.self::APP_ROLE_ENV.' no es un nombre de rol válido (minúsculas, dígitos y _, hasta 63).');
        }

        return $role;
    }

    /**
     * CHECK entre columnas, solo en PG: tabla → [nombre → expresión]. Toda
     * columna que se compara sin `IS [NOT] NULL` delante es NOT NULL o va
     * protegida por un `IS NOT NULL` (o un COALESCE) en la misma conjunción:
     * ninguna expresión puede dar NULL, que un CHECK aceptaría.
     *
     * @return array<string, array<string, string>>
     */
    public static function rowChecks(): array
    {
        $hex = static fn (string $c): string => "{$c} ~ '^[0-9a-f]{64}$'";
        $hexOrNull = static fn (string $c): string => "({$c} IS NULL OR {$c} ~ '^[0-9a-f]{64}$')";
        $bytes = static fn (string $c): string => "encode(sha256(convert_to({$c}, 'UTF8')), 'hex')";
        $filled = static fn (string $c): string => "({$c} IS NOT NULL AND char_length(btrim({$c})) >= 1)";
        $plainLine = static fn (string $c, int $max): string => "(char_length(btrim({$c})) BETWEEN 1 AND {$max} AND {$c} !~ '[[:cntrl:]]')";
        $json = static fn (string $c, string $type, int $max): string => "(json_typeof({$c}) = '{$type}' AND octet_length({$c}::text) <= {$max})";
        $versions = "catalog_version ~ '".self::CATALOG_VERSION_PATTERN."' AND evaluator_version ~ '".self::EVALUATOR_VERSION_PATTERN."'";
        $approved = self::literalList(self::APPROVED_STATUSES);
        $notApproved = self::literalList(array_values(array_diff(self::VERSION_STATUSES, self::APPROVED_STATUSES)));
        $content = static fn (string $key): string => "COALESCE(content_json::json ->> '{$key}', '')";

        return [
            self::BINDINGS => [
                // crm: lo crea una persona para OTRA cuenta; bootstrap: la consola, verificado al nacer y con la autorización escrita.
                'uspb_origin_shape_chk' => "(origin = 'crm' AND created_by_admin_id IS NOT NULL AND bootstrap_authorization IS NULL) "
                    ."OR (origin = 'bootstrap' AND created_by_admin_id IS NULL AND ".$filled('bootstrap_authorization')
                    .' AND verified_at IS NOT NULL AND verified_by_admin_id IS NULL AND verified_by_identity_id IS NULL AND verified_by_name IS NULL)',
                'uspb_verification_chk' => '(verified_at IS NULL AND verified_by_admin_id IS NULL AND verified_by_identity_id IS NULL AND verified_by_name IS NULL) '
                    ."OR (origin = 'crm' AND verified_at IS NOT NULL AND verified_by_admin_id IS NOT NULL AND verified_by_identity_id IS NOT NULL AND verified_by_name IS NOT NULL) "
                    ."OR (origin = 'bootstrap' AND verified_at IS NOT NULL AND verified_by_admin_id IS NULL AND verified_by_identity_id IS NULL AND verified_by_name IS NULL)",
                'uspb_revocation_chk' => '(revoked_at IS NULL AND revoked_by_admin_id IS NULL AND revoked_by_name IS NULL AND revoke_reason IS NULL) '
                    .'OR (revoked_at IS NOT NULL AND revoked_by_admin_id IS NOT NULL AND revoked_by_name IS NOT NULL AND '.$filled('revoke_reason').')',
                // 11.2: nadie vincula su propia cuenta; verifica una segunda persona, distinta de la vinculada y de quien lo creó; nadie revoca el propio.
                'uspb_four_eyes_chk' => '(created_by_admin_id IS NULL OR created_by_admin_id <> admin_id) '
                    .'AND (verified_by_admin_id IS NULL OR (verified_by_admin_id <> admin_id AND (created_by_admin_id IS NULL OR verified_by_admin_id <> created_by_admin_id))) '
                    .'AND (verified_by_identity_id IS NULL OR verified_by_identity_id <> identity_id) '
                    .'AND (revoked_by_admin_id IS NULL OR revoked_by_admin_id <> admin_id)',
            ],
            self::STRATEGIES => [
                'usst_key_chk' => "key ~ '".self::KEY_PATTERN."'",
                'usst_name_chk' => $plainLine('name', 80),
                'usst_draft_chk' => $json('draft_json', 'object', 32768).' AND draft_revision >= 1',
                'usst_draft_author_chk' => '(draft_updated_at IS NULL AND draft_updated_by_admin_id IS NULL AND draft_updated_by_name IS NULL) '
                    .'OR (draft_updated_at IS NOT NULL AND draft_updated_by_admin_id IS NOT NULL AND draft_updated_by_name IS NOT NULL)',
                'usst_archive_chk' => '(archived_at IS NULL AND archived_by_admin_id IS NULL AND archived_by_name IS NULL) '
                    .'OR (archived_at IS NOT NULL AND archived_by_admin_id IS NOT NULL AND archived_by_name IS NOT NULL)',
            ],
            self::TEMPLATES => [
                'usmt_key_chk' => "key ~ '".self::KEY_PATTERN."' AND number >= 1",
                'usmt_hash_chk' => $hex('content_hash'),
                // La huella es la de los BYTES guardados (como E3-2 del H8).
                'usmt_hash_matches_chk' => 'content_hash = '.$bytes('content_json'),
                'usmt_size_chk' => "octet_length(content_json) BETWEEN 2 AND 8192 AND json_typeof(content_json::json) = 'object'",
                // D9-6 y D9-15: cobranza solo `utility` y comercial solo `marketing`; la categoría, solo en WhatsApp.
                'usmt_category_chk' => "(channel = 'whatsapp' AND category IS NOT NULL AND category IN ('utility', 'marketing') "
                    ."AND (purpose <> 'collections' OR category = 'utility') AND (purpose <> 'commercial' OR category = 'marketing')) "
                    ."OR (channel <> 'whatsapp' AND category IS NULL)",
                // La columna es la del contenido, y toda plantilla de WhatsApp lleva la línea de baja (Ley 2300, art. 5, par. 2).
                'usmt_whatsapp_content_chk' => "channel <> 'whatsapp' OR (category IS NOT NULL AND ".$content('category').' = category AND '.$content('optOutLine')." = 'true')",
                // El guion del asistido: el medio y como mucho 600 caracteres.
                'usmt_assisted_content_chk' => "channel <> 'assisted' OR (".$content('medium')." IN ('whatsapp', 'voice') AND char_length(".$content('script').') BETWEEN 1 AND 600)',
                'usmt_review_chk' => "(status = 'DRAFT' AND reviewed_by_admin_id IS NULL AND reviewed_by_person_id IS NULL AND reviewed_by_name IS NULL AND reviewed_at IS NULL AND review_note IS NULL) "
                    ."OR (status IN ('APPROVED', 'RETIRED') AND reviewed_by_admin_id IS NOT NULL AND reviewed_by_person_id IS NOT NULL AND reviewed_by_name IS NOT NULL AND reviewed_at IS NOT NULL) "
                    ."OR (status = 'REJECTED' AND reviewed_by_admin_id IS NOT NULL AND reviewed_by_person_id IS NOT NULL AND reviewed_by_name IS NOT NULL AND reviewed_at IS NOT NULL AND ".$filled('review_note').')',
                'usmt_four_eyes_chk' => '(reviewed_by_admin_id IS NULL OR reviewed_by_admin_id <> created_by_admin_id) AND (reviewed_by_person_id IS NULL OR reviewed_by_person_id <> created_by_person_id)',
            ],
            self::VERSIONS => [
                'ussv_number_chk' => 'number >= 1',
                'ussv_hashes_chk' => $hex('content_hash').' AND '.$hex('studio_content_hash').' AND '.$hexOrNull('simulation_hash'),
                'ussv_hash_matches_chk' => 'content_hash = '.$bytes('content_json'),
                'ussv_size_chk' => "octet_length(content_json) BETWEEN 2 AND 32768 AND json_typeof(content_json::json) = 'object' AND ".$json('validation_json', 'object', 16384),
                'ussv_versions_chk' => $versions,
                'ussv_studio_ref_chk' => "(studio_ref_kind = 'version' AND studio_version_uuid IS NOT NULL) OR (studio_ref_kind = 'baseline' AND studio_version_uuid IS NULL)",
                'ussv_approval_chk' => "(status IN ({$notApproved}) AND approved_by_admin_id IS NULL AND approved_by_person_id IS NULL AND approved_by_name IS NULL AND approved_at IS NULL) "
                    ."OR (status IN ({$approved}) AND approved_by_admin_id IS NOT NULL AND approved_by_person_id IS NOT NULL AND approved_by_name IS NOT NULL AND approved_at IS NOT NULL)",
                // La simulación que cita la aprobación (D9-12), solo con la aprobación; sin ella, la puerta estuvo en `waived`.
                'ussv_simulation_chk' => '(simulation_uuid IS NULL AND simulation_hash IS NULL) OR (simulation_uuid IS NOT NULL AND simulation_hash IS NOT NULL AND approved_at IS NOT NULL)',
                // 11.2: otra persona Y otra cuenta.
                'ussv_four_eyes_chk' => '(approved_by_person_id IS NULL OR approved_by_person_id <> created_by_person_id) AND (approved_by_admin_id IS NULL OR approved_by_admin_id <> created_by_admin_id)',
            ],
            self::SIMULATIONS => [
                // D9-12: hasta 50 personas sintéticas; una muestra real de hasta 200 (puede salir vacía: es un bloqueante, no un error).
                'usss_size_chk' => "(kind = 'synthetic' AND sample_size BETWEEN 1 AND 50) OR (kind = 'sample' AND sample_size BETWEEN 0 AND 200)",
                'usss_hashes_chk' => $hex('content_hash').' AND '.$hex('input_hash').' AND '.$hex('result_hash'),
                'usss_result_chk' => $json('result_json', 'object', 16384).' AND blocking_findings >= 0',
                'usss_versions_chk' => $versions,
            ],
            self::REVIEWS => [
                'ussr_hash_chk' => $hex('content_hash'),
                'ussr_note_chk' => "decision = 'approved' OR ".$filled('note'),
                'ussr_warnings_chk' => 'acknowledged_warnings IS NULL OR '.$json('acknowledged_warnings', 'array', 4096),
            ],
            self::PUBLICATIONS => [
                'usspub_seq_chk' => 'seq >= 1',
                'usspub_hashes_chk' => $hex('effective_hash').' AND '.$hex('signature').' AND '.$hex('prev_hash'),
                // La huella es la de los BYTES firmados: es lo que encadena la siguiente (11.3).
                'usspub_hash_matches_chk' => 'effective_hash = '.$bytes('effective_json'),
                'usspub_size_chk' => 'char_length(effective_json) BETWEEN 2 AND 16384',
                'usspub_reason_chk' => "action NOT IN ('pause', 'rollback') OR ".$filled('reason'),
                // publish/promote/rollback nombran su versión; pause/resume, no; solo promote lleva evidencia (11.0) y solo publish/promote, la puerta de la simulación.
                'usspub_shape_chk' => "((action IN ('publish', 'promote', 'rollback') AND version_id IS NOT NULL) OR (action IN ('pause', 'resume') AND version_id IS NULL) OR action = 'retire') "
                    ."AND ((action = 'promote' AND evidence IS NOT NULL) OR (action <> 'promote' AND evidence IS NULL)) "
                    ."AND ((action IN ('publish', 'promote') AND simulation_gate IS NOT NULL) OR (action NOT IN ('publish', 'promote') AND simulation_gate IS NULL))",
                // Publicar, promover y reanudar exigen una persona vinculada (11.2); pausar y revertir, no (el sentido seguro).
                'usspub_person_chk' => "action NOT IN ('publish', 'promote', 'resume') OR acted_by_person_id IS NOT NULL",
                'usspub_evidence_chk' => 'evidence IS NULL OR '.$json('evidence', 'object', 4096),
            ],
        ];
    }

    /**
     * Disparadores de fila en los dos motores: nombre → [tabla, evento, columnas
     * de `UPDATE OF` (o null), condición WHEN (o null), motivo]. `%DISTINCT%`
     * es `IS DISTINCT FROM` en PG e `IS NOT` en SQLite.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>|null, 3: string|null, 4: string}>
     */
    public static function rowTriggers(): array
    {
        $cambia = static fn (array $columns): string => implode(' OR ', array_map(static fn (string $c): string => "NEW.{$c} %DISTINCT% OLD.{$c}", $columns));

        return [
            // Vínculos.
            'uspb_forbid_delete' => [self::BINDINGS, 'DELETE', null, null, 'los vínculos de personas se revocan, no se borran'],
            'uspb_forbid_immutable_update' => [self::BINDINGS, 'UPDATE', self::BINDING_IMMUTABLE_COLUMNS, null,
                'un vínculo de persona fija al nacer la cuenta, la identidad, su origen y quién lo creó'],
            'uspb_revoked_is_final' => [self::BINDINGS, 'UPDATE', null, 'OLD.revoked_at IS NOT NULL', 'un vínculo de persona revocado es definitivo'],
            'uspb_verification_once' => [self::BINDINGS, 'UPDATE', ['verified_at', 'verified_by_admin_id', 'verified_by_identity_id', 'verified_by_name'],
                'OLD.verified_at IS NOT NULL AND OLD.revoked_at IS NULL AND ('.$cambia(['verified_at', 'verified_by_admin_id', 'verified_by_identity_id', 'verified_by_name']).')',
                'un vínculo de persona se verifica una sola vez'],
            'uspb_starts_pending' => [self::BINDINGS, 'INSERT', null,
                "NEW.revoked_at IS NOT NULL OR NEW.revoked_by_admin_id IS NOT NULL OR (NEW.origin = 'bootstrap' AND NEW.verified_at IS NULL) "
                ."OR (NEW.origin <> 'bootstrap' AND (NEW.verified_at IS NOT NULL OR NEW.verified_by_admin_id IS NOT NULL OR NEW.verified_by_identity_id IS NOT NULL))",
                'un vínculo de persona nace sin revocar y sin verificar, salvo el arranque por consola, que nace verificado'],
            // Estrategias.
            'usst_forbid_delete' => [self::STRATEGIES, 'DELETE', null, null, 'las estrategias se archivan, no se borran'],
            'usst_forbid_immutable_update' => [self::STRATEGIES, 'UPDATE', self::STRATEGY_IMMUTABLE_COLUMNS, null,
                'una estrategia fija al nacer su clave, su finalidad y su autoría'],
            'usst_archived_is_final' => [self::STRATEGIES, 'UPDATE', null, 'OLD.archived_at IS NOT NULL', 'una estrategia archivada es definitiva'],
            'usst_draft_revision_next' => [self::STRATEGIES, 'UPDATE', ['draft_json', 'draft_revision'], 'OLD.archived_at IS NULL AND NEW.draft_revision <> OLD.draft_revision + 1',
                'cada escritura del borrador de una estrategia sube draft_revision en 1 (baseRevision)'],
            'usst_starts_clean' => [self::STRATEGIES, 'INSERT', null,
                'NEW.draft_revision <> 1 OR NEW.archived_at IS NOT NULL OR NEW.draft_updated_at IS NOT NULL',
                'una estrategia nace sin archivar y con el borrador en la revisión 1'],
            // Plantillas.
            'usmt_forbid_delete' => [self::TEMPLATES, 'DELETE', null, null, 'las plantillas se retiran, no se borran'],
            'usmt_forbid_immutable_update' => [self::TEMPLATES, 'UPDATE', self::TEMPLATE_IMMUTABLE_COLUMNS, null,
                'una plantilla congela al nacer su contenido, su huella y su autoría; cambiarla es crear la versión siguiente'],
            'usmt_closed_is_final' => [self::TEMPLATES, 'UPDATE', null, 'OLD.status IN ('.self::literalList(self::TEMPLATE_FINAL_STATUSES).')',
                'una plantilla rechazada o retirada es definitiva'],
            'usmt_status_forward' => [self::TEMPLATES, 'UPDATE', ['status'], self::forwardOnly(self::TEMPLATE_TRANSITIONS, self::TEMPLATE_FINAL_STATUSES),
                'una plantilla solo avanza: DRAFT → APPROVED o REJECTED; APPROVED → RETIRED'],
            'usmt_review_once' => [self::TEMPLATES, 'UPDATE', ['reviewed_by_admin_id', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_by_person_id', 'reviewed_at', 'review_note'],
                'OLD.reviewed_at IS NOT NULL AND OLD.status NOT IN ('.self::literalList(self::TEMPLATE_FINAL_STATUSES).') AND ('
                .$cambia(['reviewed_by_admin_id', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_by_person_id', 'reviewed_at', 'review_note']).')',
                'una plantilla se revisa una sola vez'],
            'usmt_starts_draft' => [self::TEMPLATES, 'INSERT', null,
                "NEW.status <> 'DRAFT' OR NEW.reviewed_by_admin_id IS NOT NULL OR NEW.reviewed_by_person_id IS NOT NULL OR NEW.reviewed_at IS NOT NULL",
                'una plantilla nace en DRAFT, sin revisión'],
            // Versiones.
            'ussv_forbid_delete' => [self::VERSIONS, 'DELETE', null, null, 'las versiones de estrategia no se borran'],
            'ussv_forbid_immutable_update' => [self::VERSIONS, 'UPDATE', self::VERSION_IMMUTABLE_COLUMNS, null,
                'una versión de estrategia es inmutable en su contenido, su huella, su sello y su autoría'],
            'ussv_closed_is_final' => [self::VERSIONS, 'UPDATE', null, 'OLD.status IN ('.self::literalList(self::VERSION_FINAL_STATUSES).')',
                'una versión de estrategia rechazada o revertida es definitiva'],
            'ussv_status_forward' => [self::VERSIONS, 'UPDATE', ['status'], self::forwardOnly(self::VERSION_TRANSITIONS, self::VERSION_FINAL_STATUSES),
                'una versión de estrategia solo avanza por el ciclo del H5 (11.0)'],
            'ussv_approval_once' => [self::VERSIONS, 'UPDATE',
                ['approved_by_admin_id', 'approved_by_name', 'approved_by_role', 'approved_by_person_id', 'approved_at', 'simulation_uuid', 'simulation_hash'],
                'OLD.approved_at IS NOT NULL AND OLD.status NOT IN ('.self::literalList(self::VERSION_FINAL_STATUSES).') AND ('
                .$cambia(['approved_by_admin_id', 'approved_by_name', 'approved_by_role', 'approved_by_person_id', 'approved_at', 'simulation_uuid', 'simulation_hash']).')',
                'una versión de estrategia se aprueba una sola vez, con su simulación'],
            'ussv_starts_validated' => [self::VERSIONS, 'INSERT', null,
                "NEW.status NOT IN ('VALIDATED', 'REVIEW_REQUIRED') OR NEW.approved_by_admin_id IS NOT NULL OR NEW.approved_by_person_id IS NOT NULL "
                .'OR NEW.approved_at IS NOT NULL OR NEW.simulation_uuid IS NOT NULL OR NEW.simulation_hash IS NOT NULL',
                'una versión de estrategia nace VALIDATED o REVIEW_REQUIRED, sin aprobación ni simulación'],
            // Solo inserción.
            'usss_forbid_update' => [self::SIMULATIONS, 'UPDATE', null, null, 'las simulaciones de estrategia son de solo inserción'],
            'usss_forbid_delete' => [self::SIMULATIONS, 'DELETE', null, null, 'las simulaciones de estrategia son de solo inserción'],
            'ussr_forbid_update' => [self::REVIEWS, 'UPDATE', null, null, 'las revisiones de estrategia son de solo inserción'],
            'ussr_forbid_delete' => [self::REVIEWS, 'DELETE', null, null, 'las revisiones de estrategia son de solo inserción'],
            'usspub_forbid_update' => [self::PUBLICATIONS, 'UPDATE', null, null, 'la cadena de estrategias es de solo inserción'],
            'usspub_forbid_delete' => [self::PUBLICATIONS, 'DELETE', null, null, 'la cadena de estrategias es de solo inserción'],
        ];
    }

    /**
     * Solo SQLite: los equivalentes de los CHECK de cuatro ojos (en PG,
     * `uspb_four_eyes_chk`, `usmt_four_eyes_chk` y `ussv_four_eyes_chk`). Misma
     * forma que rowTriggers().
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>|null, 3: string|null, 4: string}>
     */
    public static function sqliteOnlyRowTriggers(): array
    {
        return [
            'uspb_four_eyes_insert' => [self::BINDINGS, 'INSERT', null, 'NEW.created_by_admin_id = NEW.admin_id',
                'nadie vincula su propia cuenta (cuatro ojos)'],
            'uspb_four_eyes_update' => [self::BINDINGS, 'UPDATE', null,
                'NEW.verified_by_admin_id = NEW.admin_id OR NEW.verified_by_admin_id = NEW.created_by_admin_id OR NEW.verified_by_identity_id = NEW.identity_id OR NEW.revoked_by_admin_id = NEW.admin_id',
                'un vínculo lo verifica una segunda persona, distinta de la vinculada y de quien lo creó, y nadie revoca el propio (cuatro ojos)'],
            'usmt_four_eyes' => [self::TEMPLATES, 'UPDATE', null,
                'NEW.reviewed_by_admin_id = NEW.created_by_admin_id OR NEW.reviewed_by_person_id = NEW.created_by_person_id',
                'una plantilla la revisa otra persona y otra cuenta (cuatro ojos)'],
            'ussv_four_eyes' => [self::VERSIONS, 'UPDATE', null,
                'NEW.approved_by_admin_id = NEW.created_by_admin_id OR NEW.approved_by_person_id = NEW.created_by_person_id',
                'una versión de estrategia la aprueba otra persona y otra cuenta (cuatro ojos)'],
        ];
    }

    /**
     * Guardas ENTRE TABLAS, en los dos motores: nombre → [tabla, evento,
     * columnas de `UPDATE OF` (o null), condición WHEN sin subconsultas (o
     * null), violación (con subconsultas; `%T%` es `public.` en PG y nada en
     * SQLite), motivo]. En PG, la violación va en una función propia
     * (`ultron_studio_chk_<nombre>`); en SQLite, en el WHEN.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>|null, 3: string|null, 4: string, 5: string}>
     */
    public static function crossTriggers(): array
    {
        $vinculada = static fn (string $admin, string $person): string => 'EXISTS (SELECT 1 FROM %T%'.self::BINDINGS.' AS b WHERE b.admin_id = '.$admin
            .' AND b.identity_id = '.$person.' AND b.verified_at IS NOT NULL AND b.revoked_at IS NULL)';

        return [
            'uspb_bootstrap_only_first_two' => [self::BINDINGS, 'INSERT', null, "NEW.origin = 'bootstrap'",
                '(SELECT count(*) FROM %T%'.self::BINDINGS.' AS b WHERE b.verified_at IS NOT NULL AND b.revoked_at IS NULL) >= 2',
                'el arranque por consola solo vincula a las dos primeras personas (con dos vínculos vivos ya no cabe)'],
            'uspb_verifier_is_bound' => [self::BINDINGS, 'UPDATE', ['verified_at', 'verified_by_admin_id', 'verified_by_identity_id'],
                'NEW.verified_at IS NOT NULL AND OLD.verified_at IS NULL AND OLD.revoked_at IS NULL',
                'NOT EXISTS (SELECT 1 FROM %T%'.self::BINDINGS.' AS b WHERE b.admin_id = NEW.verified_by_admin_id AND b.identity_id = NEW.verified_by_identity_id '
                .'AND b.verified_at IS NOT NULL AND b.revoked_at IS NULL AND b.id <> NEW.id)',
                'un vínculo lo verifica una persona ya vinculada (su vínculo verificado y vivo)'],
            'usst_archive_without_live_versions' => [self::STRATEGIES, 'UPDATE', ['archived_at'], 'NEW.archived_at IS NOT NULL AND OLD.archived_at IS NULL',
                'EXISTS (SELECT 1 FROM %T%'.self::VERSIONS." AS v WHERE v.strategy_id = NEW.id AND v.status IN ('CANARY', 'PUBLISHED'))",
                'una estrategia se archiva solo sin versiones en canario ni publicadas'],
            'usmt_number_next' => [self::TEMPLATES, 'INSERT', null, null,
                'NEW.number <> COALESCE((SELECT max(t.number) FROM %T%'.self::TEMPLATES.' AS t WHERE t.key = NEW.key), 0) + 1',
                'una plantilla lleva el número siguiente de su clave (1, 2, 3…)'],
            'usmt_key_keeps_shape' => [self::TEMPLATES, 'INSERT', null, null,
                'EXISTS (SELECT 1 FROM %T%'.self::TEMPLATES.' AS t WHERE t.key = NEW.key AND (t.channel <> NEW.channel OR t.purpose <> NEW.purpose))',
                'las versiones de una plantilla conservan su canal y su finalidad'],
            'usmt_author_is_bound' => [self::TEMPLATES, 'INSERT', null, null,
                'NOT '.$vinculada('NEW.created_by_admin_id', 'NEW.created_by_person_id'),
                'la autora de una plantilla es una persona vinculada (su vínculo verificado y vivo)'],
            'usmt_reviewer_is_bound' => [self::TEMPLATES, 'UPDATE', ['reviewed_by_admin_id', 'reviewed_by_person_id'],
                "NEW.reviewed_by_admin_id IS NOT NULL AND OLD.reviewed_by_admin_id IS NULL AND OLD.status NOT IN ('REJECTED', 'RETIRED')",
                'NOT '.$vinculada('NEW.reviewed_by_admin_id', 'NEW.reviewed_by_person_id'),
                'una plantilla la revisa una persona vinculada (su vínculo verificado y vivo)'],
            'ussv_number_next' => [self::VERSIONS, 'INSERT', null, null,
                'NEW.number <> COALESCE((SELECT max(v.number) FROM %T%'.self::VERSIONS.' AS v WHERE v.strategy_id = NEW.strategy_id), 0) + 1',
                'una versión de estrategia lleva el número siguiente de su estrategia (1, 2, 3…)'],
            'ussv_strategy_not_archived' => [self::VERSIONS, 'INSERT', null, null,
                'EXISTS (SELECT 1 FROM %T%'.self::STRATEGIES.' AS s WHERE s.id = NEW.strategy_id AND s.archived_at IS NOT NULL)',
                'una estrategia archivada no admite versiones nuevas'],
            'ussv_author_is_bound' => [self::VERSIONS, 'INSERT', null, null,
                'NOT '.$vinculada('NEW.created_by_admin_id', 'NEW.created_by_person_id'),
                'la autora de una versión de estrategia es una persona vinculada (su vínculo verificado y vivo)'],
            'ussv_approver_is_bound' => [self::VERSIONS, 'UPDATE', ['approved_by_admin_id', 'approved_by_person_id'],
                "NEW.approved_by_admin_id IS NOT NULL AND OLD.approved_by_admin_id IS NULL AND OLD.status NOT IN ('REJECTED', 'ROLLED_BACK')",
                'NOT '.$vinculada('NEW.approved_by_admin_id', 'NEW.approved_by_person_id'),
                'una versión de estrategia la aprueba una persona vinculada (su vínculo verificado y vivo)'],
            'ussv_simulation_gate' => [self::VERSIONS, 'UPDATE', ['simulation_uuid', 'simulation_hash'],
                "NEW.simulation_uuid IS NOT NULL AND OLD.simulation_uuid IS NULL AND OLD.approved_at IS NULL AND OLD.status NOT IN ('REJECTED', 'ROLLED_BACK')",
                'NOT EXISTS (SELECT 1 FROM %T%'.self::SIMULATIONS.' AS s WHERE s.uuid = NEW.simulation_uuid AND s.version_id = NEW.id '
                .'AND s.content_hash = NEW.content_hash AND s.result_hash = NEW.simulation_hash AND s.blocking_findings = 0)',
                'la aprobación cita una simulación de esa versión, de su huella exacta y sin bloqueantes (D9-12)'],
            'usss_version_of_strategy' => [self::SIMULATIONS, 'INSERT', null, 'NEW.version_id IS NOT NULL',
                'NOT EXISTS (SELECT 1 FROM %T%'.self::VERSIONS.' AS v WHERE v.id = NEW.version_id AND v.strategy_id = NEW.strategy_id AND v.content_hash = NEW.content_hash)',
                'una simulación de una versión es de su estrategia y de su huella'],
            'ussr_four_eyes' => [self::REVIEWS, 'INSERT', null, null,
                'EXISTS (SELECT 1 FROM %T%'.self::VERSIONS.' AS v WHERE v.id = NEW.version_id AND (v.created_by_admin_id = NEW.reviewer_admin_id OR v.created_by_person_id = NEW.reviewer_person_id))',
                'una versión de estrategia la revisa otra persona y otra cuenta (cuatro ojos)'],
            'ussr_same_hash' => [self::REVIEWS, 'INSERT', null, null,
                'NOT EXISTS (SELECT 1 FROM %T%'.self::VERSIONS.' AS v WHERE v.id = NEW.version_id AND v.content_hash = NEW.content_hash)',
                'una revisión es de la huella exacta de su versión'],
            'ussr_reviewer_is_bound' => [self::REVIEWS, 'INSERT', null, null,
                'NOT '.$vinculada('NEW.reviewer_admin_id', 'NEW.reviewer_person_id'),
                'una versión de estrategia la revisa una persona vinculada (su vínculo verificado y vivo)'],
            'usspub_version_of_strategy' => [self::PUBLICATIONS, 'INSERT', null, 'NEW.version_id IS NOT NULL',
                'NOT EXISTS (SELECT 1 FROM %T%'.self::VERSIONS.' AS v WHERE v.id = NEW.version_id AND v.strategy_id = NEW.strategy_id)',
                'una entrada de la cadena nombra una versión de su estrategia'],
            'usspub_four_eyes' => [self::PUBLICATIONS, 'INSERT', null, "NEW.action IN ('publish', 'promote')",
                'EXISTS (SELECT 1 FROM %T%'.self::VERSIONS.' AS v WHERE v.id = NEW.version_id AND (v.created_by_admin_id = NEW.actor_admin_id OR v.created_by_person_id = NEW.acted_by_person_id))',
                'publica y promueve una persona distinta de la autora (cuatro ojos)'],
            'usspub_actor_is_bound' => [self::PUBLICATIONS, 'INSERT', null, "NEW.action IN ('publish', 'promote', 'resume')",
                'NOT '.$vinculada('NEW.actor_admin_id', 'NEW.acted_by_person_id'),
                'publica, promueve y reanuda una persona vinculada (su vínculo verificado y vivo)'],
        ];
    }

    /**
     * «Solo hacia delante»: el WHEN de un `BEFORE UPDATE OF status` que salta
     * si el estado cambia a algo que el grafo no admite (también a un valor que
     * no es un estado). Escribir el mismo estado no cambia nada y pasa. Las
     * filas cerradas no las mira: son de su disparador de «definitivo», que
     * las para entero (así una violación da el mismo motivo en los dos
     * motores, D-18 de DB8).
     *
     * @param  array<string, list<string>>  $graph
     * @param  list<string>  $closed
     */
    public static function forwardOnly(array $graph, array $closed = []): string
    {
        $pares = [];
        foreach ($graph as $from => $to) {
            $pares[] = '(OLD.status = '.self::literal($from).' AND NEW.status IN ('.self::literalList($to).'))';
        }

        return 'OLD.status <> NEW.status'
            .($closed === [] ? '' : ' AND OLD.status NOT IN ('.self::literalList($closed).')')
            .' AND NOT ('.implode(' OR ', $pares).')';
    }

    public static function crossFunction(string $trigger): string
    {
        return self::CHECK_FUNCTION_PREFIX.$trigger;
    }

    /** Las funciones propias de esta migración (las de las guardas entre tablas y la de la cadena). @return list<string> */
    public static function ownFunctions(): array
    {
        return [...array_map(self::crossFunction(...), array_keys(self::crossTriggers())), self::CHAIN_FUNCTION];
    }

    private function createTables(): void
    {
        Schema::create(self::BINDINGS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('uspb_uuid_unique');
            $table->uuid('client_request_id')->unique('uspb_client_request_unique');
            // crm | bootstrap (las dos primeras personas, por consola).
            $table->string('origin', 10);
            // La cuenta, sin FK (como F); la persona, con FK RESTRICT (§6.1): solo su id, nunca su documento (R9-6).
            $table->unsignedBigInteger('admin_id');
            $table->foreignId('identity_id')->constrained('identities', 'id', 'uspb_identity_fk')->restrictOnDelete();
            // bootstrap: la referencia de la autorización escrita del propietario (como el T1 del H6).
            $table->string('bootstrap_authorization', 160)->nullable();
            // NULL solo en bootstrap (la consola).
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->string('created_by_name', 120);
            $table->string('created_by_role', 60)->nullable();
            $table->timestamp('created_at');
            // La segunda persona ya vinculada: su cuenta y su identidad (una vez).
            $table->unsignedBigInteger('verified_by_admin_id')->nullable();
            $table->string('verified_by_name', 120)->nullable();
            $table->unsignedBigInteger('verified_by_identity_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            // Se revoca una vez, con motivo, y nunca la propia cuenta.
            $table->unsignedBigInteger('revoked_by_admin_id')->nullable();
            $table->string('revoked_by_name', 120)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamp('updated_at')->nullable();

            // Las cuentas de una persona (la comparación de cuatro ojos).
            $table->index(['identity_id', 'id'], 'uspb_identity_idx');
        });

        Schema::create(self::STRATEGIES, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('usst_uuid_unique');
            // POST strategies es idempotente por `clientRequestId` (§7, ruta 3).
            $table->uuid('client_request_id')->unique('usst_client_request_unique');
            // `^[a-z0-9][a-z0-9_-]{2,47}$`, inmutable.
            $table->string('key', 48)->unique('usst_key_unique');
            // ≤ 80, PlainText estricto (el servicio); en PG, sin caracteres de control.
            $table->string('name', 80);
            // commercial | collections | service: inmutable (D9-6).
            $table->string('purpose', 12);
            // El borrador compartido (≤ 32 KB) y su revisión (`baseRevision`, sube de 1 en 1).
            $table->json('draft_json');
            $table->unsignedInteger('draft_revision');
            $table->unsignedBigInteger('draft_updated_by_admin_id')->nullable();
            $table->string('draft_updated_by_name', 120)->nullable();
            $table->timestamp('draft_updated_at')->nullable();
            // Una vez, y solo sin versiones vivas.
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_admin_id')->nullable();
            $table->string('archived_by_name', 120)->nullable();
            $table->unsignedBigInteger('created_by_admin_id');
            $table->string('created_by_name', 120);
            $table->string('created_by_role', 60)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create(self::TEMPLATES, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('usmt_uuid_unique');
            $table->uuid('client_request_id')->unique('usmt_client_request_unique');
            // La clave y su versión (1, 2, 3…, seguidas).
            $table->string('key', 48);
            $table->unsignedInteger('number');
            // whatsapp | assisted | voice (V9).
            $table->string('channel', 10);
            $table->string('purpose', 12);
            // utility | marketing, solo en WhatsApp: la del contenido.
            $table->string('category', 10)->nullable();
            // El contenido canónico de D9-6 (≤ 8 KB) y la huella de sus bytes.
            $table->text('content_json');
            $table->char('content_hash', 64);
            // DRAFT → APPROVED | REJECTED; APPROVED → RETIRED.
            $table->string('status', 10);
            $table->unsignedBigInteger('created_by_admin_id');
            $table->string('created_by_name', 120);
            $table->string('created_by_role', 60)->nullable();
            $table->unsignedBigInteger('created_by_person_id');
            $table->timestamp('created_at');
            // Aprueba o rechaza OTRA persona vinculada; el rechazo lleva nota.
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->string('reviewed_by_name', 120)->nullable();
            $table->string('reviewed_by_role', 60)->nullable();
            $table->unsignedBigInteger('reviewed_by_person_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('status_changed_at');
            $table->timestamp('updated_at')->nullable();

            $table->unique(['key', 'number'], 'usmt_key_number_unique');
            $table->index(['status', 'id'], 'usmt_status_idx');
        });

        Schema::create(self::VERSIONS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('ussv_uuid_unique');
            $table->uuid('client_request_id')->unique('ussv_client_request_unique');
            $table->foreignId('strategy_id')->constrained(self::STRATEGIES, 'id', 'ussv_strategy_fk')->restrictOnDelete();
            // 1, 2, 3…, seguidas por estrategia.
            $table->unsignedInteger('number');
            // `strategy-content/1`: texto canónico (≤ 32 KB), NUNCA json/jsonb; la huella, la de sus bytes.
            $table->text('content_json');
            $table->char('content_hash', 64);
            $table->string('catalog_version', 48);
            $table->string('evaluator_version', 48);
            // La «versión comercial aprobada» (11.4): version (uuid + huella) | baseline (LEGACY_BASELINE_V1, la huella del catálogo).
            $table->string('studio_ref_kind', 8);
            $table->uuid('studio_version_uuid')->nullable();
            $table->char('studio_content_hash', 64);
            // Los 9 alcanzables de StudioVersionStatus, por el ciclo del H5.
            $table->string('status', 20);
            // El resultado de la validación pura (bloqueantes y avisos).
            $table->json('validation_json');
            // La simulación que cita la aprobación (una vez, con ella).
            $table->uuid('simulation_uuid')->nullable();
            $table->char('simulation_hash', 64)->nullable();
            $table->unsignedBigInteger('created_by_admin_id');
            $table->string('created_by_name', 120);
            $table->string('created_by_role', 60)->nullable();
            $table->unsignedBigInteger('created_by_person_id');
            $table->timestamp('created_at');
            $table->unsignedBigInteger('approved_by_admin_id')->nullable();
            $table->string('approved_by_name', 120)->nullable();
            $table->string('approved_by_role', 60)->nullable();
            $table->unsignedBigInteger('approved_by_person_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('status_changed_at');
            $table->timestamp('updated_at')->nullable();

            $table->unique(['strategy_id', 'number'], 'ussv_number_unique');
            $table->index(['status', 'id'], 'ussv_status_idx');
        });

        Schema::create(self::SIMULATIONS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('usss_uuid_unique');
            $table->uuid('client_request_id')->nullable()->unique('usss_client_request_unique');
            $table->foreignId('strategy_id')->constrained(self::STRATEGIES, 'id', 'usss_strategy_fk')->restrictOnDelete();
            // NULL: la del borrador.
            $table->foreignId('version_id')->nullable()->constrained(self::VERSIONS, 'id', 'usss_version_fk')->restrictOnDelete();
            $table->char('content_hash', 64);
            // synthetic | sample (D9-12).
            $table->string('kind', 10);
            $table->char('input_hash', 64);
            $table->unsignedSmallInteger('sample_size');
            // Recuentos y hallazgos (≤ 16 KB), NUNCA trazas por persona.
            $table->json('result_json');
            $table->char('result_hash', 64);
            $table->unsignedInteger('blocking_findings');
            $table->string('catalog_version', 48);
            $table->string('evaluator_version', 48);
            $table->unsignedBigInteger('created_by_admin_id');
            $table->string('created_by_name', 120);
            $table->string('created_by_role', 60)->nullable();
            $table->unsignedBigInteger('created_by_person_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['strategy_id', 'id'], 'usss_strategy_idx');
            $table->index(['version_id', 'id'], 'usss_version_idx');
        });

        Schema::create(self::REVIEWS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('ussr_uuid_unique');
            // Una decisión por versión.
            $table->foreignId('version_id')->unique('ussr_version_unique')->constrained(self::VERSIONS, 'id', 'ussr_version_fk')->restrictOnDelete();
            // approved | rejected (el rechazo, con nota).
            $table->string('decision', 10);
            // La huella exacta que vio quien revisa.
            $table->char('content_hash', 64);
            $table->json('acknowledged_warnings')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('reviewer_admin_id');
            $table->string('reviewer_name', 120);
            $table->string('reviewer_role', 60)->nullable();
            $table->unsignedBigInteger('reviewer_person_id');
            // Solo inserción: sin updated_at.
            $table->timestamp('created_at');
        });

        Schema::create(self::PUBLICATIONS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique('usspub_uuid_unique');
            // Global: 1, 2, 3…; la cabeza es max(seq). La carrera la gana uno (23505 → 409).
            $table->unsignedInteger('seq')->unique('usspub_seq_unique');
            $table->uuid('client_request_id')->unique('usspub_client_request_unique');
            // publish | promote | pause | resume | rollback | retire (11.3).
            $table->string('action', 10);
            $table->foreignId('strategy_id')->constrained(self::STRATEGIES, 'id', 'usspub_strategy_fk')->restrictOnDelete();
            $table->foreignId('version_id')->nullable()->constrained(self::VERSIONS, 'id', 'usspub_version_fk')->restrictOnDelete();
            // La cabeza anterior (NULL solo en seq = 1) y su huella (64 ceros en seq = 1).
            $table->foreignId('previous_publication_id')->nullable()->constrained(self::PUBLICATIONS, 'id', 'usspub_previous_fk')->restrictOnDelete();
            $table->char('prev_hash', 64);
            // Los BYTES EXACTOS del mensaje firmado de 11.3 (text, nunca json/jsonb), su huella y su firma.
            $table->text('effective_json');
            $table->char('effective_hash', 64);
            $table->char('signature', 64);
            // required | waived (solo publish/promote; `waived` solo lo pone el propietario).
            $table->string('simulation_gate', 10)->nullable();
            // Recuentos de la evidencia del canario (promote), sin ids ni textos.
            $table->json('evidence')->nullable();
            // Obligatorio en pause y rollback.
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('actor_admin_id');
            $table->string('actor_name', 120);
            $table->string('actor_role', 60)->nullable();
            // La persona (identities.id) de quien actúa; obligatoria al publicar, promover y reanudar.
            $table->unsignedBigInteger('acted_by_person_id')->nullable();
            // Solo inserción: sin updated_at.
            $table->timestamp('created_at');

            // La última entrada de cada estrategia (el lector de 11.3).
            $table->index(['strategy_id', 'seq'], 'usspub_strategy_idx');
        });
    }

    private function upPostgres(): void
    {
        $this->boundLockWait();
        $this->createTables();
        $this->assertColumnsClassified();

        foreach (self::DOMAIN_CHECKS as $table => $checks) {
            foreach ($checks as $name => [$column, $values]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IN (".self::literalList($values).'))');
            }
        }
        foreach (self::rowChecks() as $table => $checks) {
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
        // solo la siguiente, apuntando a ella por su id y por su huella; lo que
        // no pasa de la cabeza lo deciden el UNIQUE de seq (23505) y
        // usspub_seq_chk (23514), como en el H7 y el H8.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ultron_studio_strategy_publication_chain() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            DECLARE
                cabeza_id bigint;
                cabeza_seq bigint;
                cabeza_hash text;
            BEGIN
                SELECT p.id, p.seq, p.effective_hash INTO cabeza_id, cabeza_seq, cabeza_hash
                  FROM public.ultron_studio_strategy_publications AS p
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

        foreach (self::crossTriggers() as $name => [$table, $event, $columns, $when, $violation, $reason]) {
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
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns)." ON {$table} "
                .'FOR EACH ROW '.self::whenClause($when, 'pgsql')
                .'EXECUTE FUNCTION '.self::crossFunction($name).'('.self::literal($reason).')');
        }

        $this->createPartialUniqueIndexes();

        $this->revokeAppWrites();

        // El libro, AL FINAL: su ACCESS EXCLUSIVE dura lo menos posible.
        $this->replaceSubjectCheck(self::h9SubjectTypes(), ifExists: false);
    }

    private function downPostgres(): void
    {
        $this->boundLockWait();
        // Los cerrojos ANTES de contar: nadie escribe entre el recuento y el
        // primer DROP. Con `--pretend` las lecturas devuelven vacío: se
        // escribe igual, para que la vista previa enseñe el cerrojo.
        $pretending = DB::connection()->pretending();
        $tables = array_values(array_filter(self::TABLES, static fn (string $table): bool => $pretending || Schema::hasTable($table)));
        DB::statement('LOCK TABLE '.implode(', ', [...$tables, self::AUDIT]).' IN ACCESS EXCLUSIVE MODE');

        $this->refuseWithRows();

        $this->replaceSubjectCheck(self::H6_SUBJECT_TYPES, ifExists: true);
        // Con la tabla se van sus disparadores, CHECK, índices y privilegios.
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        // Después de los disparadores, que dependen de ellas. La de F se queda.
        foreach (self::ownFunctions() as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
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

        foreach (self::crossTriggers() as $name => [$table, $event, $columns, $when, $violation, $reason]) {
            DB::statement("CREATE TRIGGER {$name} BEFORE ".self::eventClause($event, $columns)." ON {$table} FOR EACH ROW "
                .'WHEN ('.($when === null ? '' : self::dialect($when, 'sqlite').') AND (').self::dialect($violation, 'sqlite').') '
                .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message($reason, $event, $table)).'); END');
        }

        $this->createPartialUniqueIndexes();
        // Sin roles: no hay privilegios que revocar. El libro no se toca: en
        // SQLite no tiene CHECK que ampliar (F lo añade solo en PG).
    }

    private function downSqlite(): void
    {
        $this->refuseWithRows();

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
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
     * Cada columna de las cuatro tablas con máquina de estados es mutable o
     * inmutable, explícitamente y una sola vez; las de solo inserción lo son
     * enteras. Si una lista nombra una columna que no existe, falta una o se
     * repite, esto falla antes de crear ninguna guarda (en SQLite un `UPDATE
     * OF` con una columna que no existe no da error y el disparador no
     * saltaría nunca). Con `--pretend` no se lee nada.
     */
    private function assertColumnsClassified(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        foreach ([
            self::BINDINGS => [self::BINDING_IMMUTABLE_COLUMNS, self::BINDING_MUTABLE_COLUMNS],
            self::STRATEGIES => [self::STRATEGY_IMMUTABLE_COLUMNS, self::STRATEGY_MUTABLE_COLUMNS],
            self::TEMPLATES => [self::TEMPLATE_IMMUTABLE_COLUMNS, self::TEMPLATE_MUTABLE_COLUMNS],
            self::VERSIONS => [self::VERSION_IMMUTABLE_COLUMNS, self::VERSION_MUTABLE_COLUMNS],
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
     * DROP + ADD en una sola sentencia: un solo cerrojo y ningún instante sin
     * CHECK (el patrón del H6). En up() sin IF EXISTS: si el CHECK no está, el
     * esquema no es el que esta migración espera y tiene que fallar.
     *
     * @param  list<string>  $subjects
     */
    private function replaceSubjectCheck(array $subjects, bool $ifExists): void
    {
        DB::statement('ALTER TABLE '.self::AUDIT
            .' DROP CONSTRAINT '.($ifExists ? 'IF EXISTS ' : '').self::SUBJECT_CHECK
            .', ADD CONSTRAINT '.self::SUBJECT_CHECK.' CHECK (subject_type IN ('.self::literalList($subjects).'))');
    }

    /**
     * REVOKE del rol de la aplicación (UPDATE y DELETE en las de solo
     * inserción; DELETE en las de estados) y la comprobación de que de verdad
     * ya no puede (por tabla, por columna y por pertenencia a otros roles:
     * `has_any_column_privilege` y `has_table_privilege` miran todo eso). Sin
     * el rol en el clúster no hay nada que revocar, salvo en producción, donde
     * es un error.
     */
    private function revokeAppWrites(): void
    {
        $role = self::appRole();
        $sentencias = [
            'REVOKE UPDATE, DELETE ON '.implode(', ', self::INSERT_ONLY_TABLES).' FROM "'.$role.'"',
            'REVOKE DELETE ON '.implode(', ', self::STATEFUL_TABLES).' FROM "'.$role.'"',
        ];

        if (DB::connection()->pretending()) {
            foreach ($sentencias as $sentencia) {
                DB::statement($sentencia);
            }

            return;
        }
        if (! self::roleExists($role)) {
            if (app()->environment('production')) {
                throw new RuntimeException("ultron_studio (Hito 9): el rol de la aplicación «{$role}» no existe en este clúster y APP_ENV es production: "
                    .'el REVOKE sobre el gobierno de las estrategias no se puede aplicar. La migración no se registra; '
                    .'si el rol se llama de otra forma, '.self::APP_ROLE_ENV.' en el entorno del proceso que migra.');
            }

            return;
        }

        foreach ($sentencias as $sentencia) {
            DB::statement($sentencia);
        }

        $todavia = [];
        foreach (self::TABLES as $table) {
            $puede = DB::selectOne(
                "SELECT has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d",
                [$role, 'public.'.$table, $role, 'public.'.$table],
            );
            if (in_array($table, self::INSERT_ONLY_TABLES, true) && (int) $puede->u === 1) {
                $todavia[] = "UPDATE en {$table}";
            }
            if ((int) $puede->d === 1) {
                $todavia[] = "DELETE en {$table}";
            }
        }
        if ($todavia !== []) {
            throw new RuntimeException("ultron_studio (Hito 9): después del REVOKE, «{$role}» todavía puede ".implode(', ', $todavia)
                .' (heredado de otro rol o de PUBLIC, o concedido por alguien que no es el rol que migra). La migración no se registra.');
        }
    }

    private static function roleExists(string $role): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]) !== null;
    }

    /**
     * Protección 11: con filas, revertir el esquema destruiría el gobierno de
     * las estrategias (DROP no pasa por los disparadores de fila) y dejaría el
     * libro con sujetos que su CHECK ya no admite. Se niega con un mensaje
     * claro, antes de tocar nada, y nunca borra.
     */
    private function refuseWithRows(): void
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }
        $events = DB::table(self::AUDIT)->whereIn('subject_type', self::H9_SUBJECT_TYPES)->count();

        if (array_sum($rows) > 0 || $events > 0) {
            throw new RuntimeException('ultron_studio (Hito 9): down() se niega a revertir el gobierno de las estrategias con datos '
                ."({$rows[self::BINDINGS]} vínculos de personas, {$rows[self::STRATEGIES]} estrategias, {$rows[self::TEMPLATES]} plantillas, "
                ."{$rows[self::VERSIONS]} versiones, {$rows[self::SIMULATIONS]} simulaciones, {$rows[self::REVIEWS]} revisiones, "
                ."{$rows[self::PUBLICATIONS]} entradas de la cadena y {$events} eventos del libro con los sujetos "
                .implode(', ', self::H9_SUBJECT_TYPES).'). Revertirlo los destruiría y nunca borra nada. '
                .'Para volver al código anterior no hace falta revertir el esquema (§9.2, nivel 3: las tablas se quedan).');
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
        return $when === null ? '' : 'WHEN ('.self::dialect($when, $driver).') ';
    }

    /** `%DISTINCT%` y `%T%` en el dialecto de cada motor. */
    private static function dialect(string $sql, string $driver): string
    {
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
        return new RuntimeException("El gobierno de las estrategias del Hito 9 de ULTRON Studio solo existe para pgsql y sqlite, y sus guardas son obligatorias: con «{$driver}» la migración no se aplica.");
    }
};
