<?php

namespace Tests\Feature\UltronStudio;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Outreach\Schema\OutreachSchemaFixtures;
use Tests\TestCase;

/**
 * EL GOBIERNO DE LAS ESTRATEGIAS EN LA BASE (Hito 9, paquete DB9; migración
 * 2026_11_12_100001), en los dos motores (la suite en SQLite y la puerta
 * `docs/ultron-studio/ops/pgunit.sh` en PostgreSQL), con SQL a pelo. Lo
 * esperado sale del contrato (D9-6, D9-11, D9-12 y §6.1) y está escrito aquí.
 *
 *  · Vínculos (11.2, P9-4): las dos primeras personas por consola y solo
 *    mientras no haya dos vivas; los demás los crea una persona para OTRA
 *    cuenta y los verifica una SEGUNDA persona ya vinculada; una vez; uno
 *    vivo por cuenta; se revocan, no se borran.
 *  · Estrategias: clave y finalidad inmutables; el borrador sube de 1 en 1;
 *    se archiva una vez y sin versiones vivas.
 *  · Versiones: nacen validadas, solo avanzan por el ciclo del H5, el
 *    contenido no cambia y su huella es la de los bytes; la autora y quien
 *    aprueba son personas vinculadas y DISTINTAS (por persona y por cuenta);
 *    la simulación de la aprobación es de esa huella y sin bloqueantes; una
 *    CANARY y una PUBLISHED por estrategia.
 *  · Revisiones y simulaciones: solo inserción, de otra persona y de su huella.
 *  · Plantillas: contenido congelado, cuatro ojos, coherencia de categoría y
 *    finalidad y la línea de baja de WhatsApp.
 *  · La cadena: la siguiente a la cabeza por id y por huella, `seq >= 1`, la
 *    huella de los bytes, publica y promueve otra persona vinculada.
 *  · El libro: 11 sujetos (PG). Recuentos (para OPS9): PG 68 → 123
 *    disparadores, 84 → 137 CHECK, 6 → 9 parciales y 18 → 25 tablas
 *    `ultron_studio_%`; SQLite 55 → 108 disparadores.
 *  · El rol de la aplicación, `down()` y la ida y vuelta.
 *
 * En PostgreSQL, cada sentencia que tiene que fallar va en un SAVEPOINT.
 */
class UltronStudioStrategySchemaTest extends TestCase
{
    use OutreachSchemaFixtures;
    use RefreshDatabase;

    private const P = 'ultron_studio: ';

    private const COLUMNS_PERSON_BINDINGS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'origin' => ['character varying(10)', 'varchar', false],
        'admin_id' => ['bigint', 'integer', false],
        'identity_id' => ['bigint', 'integer', false],
        'bootstrap_authorization' => ['character varying(160)', 'varchar', true],
        'created_by_admin_id' => ['bigint', 'integer', true],
        'created_by_name' => ['character varying(120)', 'varchar', false],
        'created_by_role' => ['character varying(60)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'verified_by_admin_id' => ['bigint', 'integer', true],
        'verified_by_name' => ['character varying(120)', 'varchar', true],
        'verified_by_identity_id' => ['bigint', 'integer', true],
        'verified_at' => ['timestamp(0) without time zone', 'datetime', true],
        'revoked_by_admin_id' => ['bigint', 'integer', true],
        'revoked_by_name' => ['character varying(120)', 'varchar', true],
        'revoked_at' => ['timestamp(0) without time zone', 'datetime', true],
        'revoke_reason' => ['character varying(500)', 'varchar', true],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const COLUMNS_STRATEGIES = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'key' => ['character varying(48)', 'varchar', false],
        'name' => ['character varying(80)', 'varchar', false],
        'purpose' => ['character varying(12)', 'varchar', false],
        'draft_json' => ['json', 'text', false],
        'draft_revision' => ['integer', 'integer', false],
        'draft_updated_by_admin_id' => ['bigint', 'integer', true],
        'draft_updated_by_name' => ['character varying(120)', 'varchar', true],
        'draft_updated_at' => ['timestamp(0) without time zone', 'datetime', true],
        'archived_at' => ['timestamp(0) without time zone', 'datetime', true],
        'archived_by_admin_id' => ['bigint', 'integer', true],
        'archived_by_name' => ['character varying(120)', 'varchar', true],
        'created_by_admin_id' => ['bigint', 'integer', false],
        'created_by_name' => ['character varying(120)', 'varchar', false],
        'created_by_role' => ['character varying(60)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const COLUMNS_MESSAGE_TEMPLATES = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'key' => ['character varying(48)', 'varchar', false],
        'number' => ['integer', 'integer', false],
        'channel' => ['character varying(10)', 'varchar', false],
        'purpose' => ['character varying(12)', 'varchar', false],
        'category' => ['character varying(10)', 'varchar', true],
        'content_json' => ['text', 'text', false],
        'content_hash' => ['character(64)', 'varchar', false],
        'status' => ['character varying(10)', 'varchar', false],
        'created_by_admin_id' => ['bigint', 'integer', false],
        'created_by_name' => ['character varying(120)', 'varchar', false],
        'created_by_role' => ['character varying(60)', 'varchar', true],
        'created_by_person_id' => ['bigint', 'integer', false],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'reviewed_by_admin_id' => ['bigint', 'integer', true],
        'reviewed_by_name' => ['character varying(120)', 'varchar', true],
        'reviewed_by_role' => ['character varying(60)', 'varchar', true],
        'reviewed_by_person_id' => ['bigint', 'integer', true],
        'reviewed_at' => ['timestamp(0) without time zone', 'datetime', true],
        'review_note' => ['character varying(500)', 'varchar', true],
        'status_changed_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const COLUMNS_STRATEGY_VERSIONS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'strategy_id' => ['bigint', 'integer', false],
        'number' => ['integer', 'integer', false],
        'content_json' => ['text', 'text', false],
        'content_hash' => ['character(64)', 'varchar', false],
        'catalog_version' => ['character varying(48)', 'varchar', false],
        'evaluator_version' => ['character varying(48)', 'varchar', false],
        'studio_ref_kind' => ['character varying(8)', 'varchar', false],
        'studio_version_uuid' => ['uuid', 'varchar', true],
        'studio_content_hash' => ['character(64)', 'varchar', false],
        'status' => ['character varying(20)', 'varchar', false],
        'validation_json' => ['json', 'text', false],
        'simulation_uuid' => ['uuid', 'varchar', true],
        'simulation_hash' => ['character(64)', 'varchar', true],
        'created_by_admin_id' => ['bigint', 'integer', false],
        'created_by_name' => ['character varying(120)', 'varchar', false],
        'created_by_role' => ['character varying(60)', 'varchar', true],
        'created_by_person_id' => ['bigint', 'integer', false],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'approved_by_admin_id' => ['bigint', 'integer', true],
        'approved_by_name' => ['character varying(120)', 'varchar', true],
        'approved_by_role' => ['character varying(60)', 'varchar', true],
        'approved_by_person_id' => ['bigint', 'integer', true],
        'approved_at' => ['timestamp(0) without time zone', 'datetime', true],
        'status_changed_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const COLUMNS_STRATEGY_SIMULATIONS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', true],
        'strategy_id' => ['bigint', 'integer', false],
        'version_id' => ['bigint', 'integer', true],
        'content_hash' => ['character(64)', 'varchar', false],
        'kind' => ['character varying(10)', 'varchar', false],
        'input_hash' => ['character(64)', 'varchar', false],
        'sample_size' => ['smallint', 'integer', false],
        'result_json' => ['json', 'text', false],
        'result_hash' => ['character(64)', 'varchar', false],
        'blocking_findings' => ['integer', 'integer', false],
        'catalog_version' => ['character varying(48)', 'varchar', false],
        'evaluator_version' => ['character varying(48)', 'varchar', false],
        'created_by_admin_id' => ['bigint', 'integer', false],
        'created_by_name' => ['character varying(120)', 'varchar', false],
        'created_by_role' => ['character varying(60)', 'varchar', true],
        'created_by_person_id' => ['bigint', 'integer', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    private const COLUMNS_STRATEGY_REVIEWS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'version_id' => ['bigint', 'integer', false],
        'decision' => ['character varying(10)', 'varchar', false],
        'content_hash' => ['character(64)', 'varchar', false],
        'acknowledged_warnings' => ['json', 'text', true],
        'note' => ['character varying(500)', 'varchar', true],
        'reviewer_admin_id' => ['bigint', 'integer', false],
        'reviewer_name' => ['character varying(120)', 'varchar', false],
        'reviewer_role' => ['character varying(60)', 'varchar', true],
        'reviewer_person_id' => ['bigint', 'integer', false],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    private const COLUMNS_STRATEGY_PUBLICATIONS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'seq' => ['integer', 'integer', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'action' => ['character varying(10)', 'varchar', false],
        'strategy_id' => ['bigint', 'integer', false],
        'version_id' => ['bigint', 'integer', true],
        'previous_publication_id' => ['bigint', 'integer', true],
        'prev_hash' => ['character(64)', 'varchar', false],
        'effective_json' => ['text', 'text', false],
        'effective_hash' => ['character(64)', 'varchar', false],
        'signature' => ['character(64)', 'varchar', false],
        'simulation_gate' => ['character varying(10)', 'varchar', true],
        'evidence' => ['json', 'text', true],
        'reason' => ['character varying(500)', 'varchar', true],
        'actor_admin_id' => ['bigint', 'integer', false],
        'actor_name' => ['character varying(120)', 'varchar', false],
        'actor_role' => ['character varying(60)', 'varchar', true],
        'acted_by_person_id' => ['bigint', 'integer', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    private const PG_TRIGGERS = [
        self::T_BINDINGS => ['uspb_bootstrap_only_first_two', 'uspb_forbid_delete', 'uspb_forbid_immutable_update', 'uspb_forbid_truncate', 'uspb_revoked_is_final',
            'uspb_starts_pending', 'uspb_verification_once', 'uspb_verifier_is_bound'],
        self::T_STRATEGIES => ['usst_archive_without_live_versions', 'usst_archived_is_final', 'usst_draft_revision_next', 'usst_forbid_delete', 'usst_forbid_immutable_update',
            'usst_forbid_truncate', 'usst_starts_clean'],
        self::T_TEMPLATES => ['usmt_author_is_bound', 'usmt_closed_is_final', 'usmt_forbid_delete', 'usmt_forbid_immutable_update', 'usmt_forbid_truncate', 'usmt_key_keeps_shape',
            'usmt_number_next', 'usmt_review_once', 'usmt_reviewer_is_bound', 'usmt_starts_draft', 'usmt_status_forward'],
        self::T_VERSIONS => ['ussv_approval_once', 'ussv_approver_is_bound', 'ussv_author_is_bound', 'ussv_closed_is_final', 'ussv_forbid_delete', 'ussv_forbid_immutable_update',
            'ussv_forbid_truncate', 'ussv_number_next', 'ussv_simulation_gate', 'ussv_starts_validated', 'ussv_status_forward', 'ussv_strategy_not_archived'],
        self::T_SIMULATIONS => ['usss_forbid_delete', 'usss_forbid_truncate', 'usss_forbid_update', 'usss_version_of_strategy'],
        self::T_REVIEWS => ['ussr_forbid_delete', 'ussr_forbid_truncate', 'ussr_forbid_update', 'ussr_four_eyes', 'ussr_reviewer_is_bound', 'ussr_same_hash'],
        self::T_PUBLICATIONS => ['usspub_actor_is_bound', 'usspub_chain_next', 'usspub_forbid_delete', 'usspub_forbid_truncate', 'usspub_forbid_update', 'usspub_four_eyes',
            'usspub_version_of_strategy'],
    ];

    /** Solo SQLite: los equivalentes de los CHECK de cuatro ojos y de `seq >= 1`. */
    private const SQLITE_ONLY_TRIGGERS = [
        self::T_BINDINGS => ['uspb_four_eyes_insert', 'uspb_four_eyes_update'],
        self::T_TEMPLATES => ['usmt_four_eyes'],
        self::T_VERSIONS => ['ussv_four_eyes'],
        self::T_PUBLICATIONS => ['usspub_seq_min'],
    ];

    private const PG_CHECKS = [
        self::T_BINDINGS => ['uspb_four_eyes_chk', 'uspb_origin_chk', 'uspb_origin_shape_chk', 'uspb_revocation_chk', 'uspb_verification_chk'],
        self::T_STRATEGIES => ['usst_archive_chk', 'usst_draft_author_chk', 'usst_draft_chk', 'usst_key_chk', 'usst_name_chk', 'usst_purpose_chk'],
        self::T_TEMPLATES => ['usmt_assisted_content_chk', 'usmt_category_chk', 'usmt_channel_chk', 'usmt_four_eyes_chk', 'usmt_hash_chk', 'usmt_hash_matches_chk', 'usmt_key_chk',
            'usmt_purpose_chk', 'usmt_review_chk', 'usmt_size_chk', 'usmt_status_chk', 'usmt_whatsapp_content_chk'],
        self::T_VERSIONS => ['ussv_approval_chk', 'ussv_four_eyes_chk', 'ussv_hash_matches_chk', 'ussv_hashes_chk', 'ussv_number_chk', 'ussv_simulation_chk', 'ussv_size_chk',
            'ussv_status_chk', 'ussv_studio_ref_chk', 'ussv_studio_ref_kind_chk', 'ussv_versions_chk'],
        self::T_SIMULATIONS => ['usss_hashes_chk', 'usss_kind_chk', 'usss_result_chk', 'usss_size_chk', 'usss_versions_chk'],
        self::T_REVIEWS => ['ussr_decision_chk', 'ussr_hash_chk', 'ussr_note_chk', 'ussr_warnings_chk'],
        self::T_PUBLICATIONS => ['usspub_action_chk', 'usspub_evidence_chk', 'usspub_hash_matches_chk', 'usspub_hashes_chk', 'usspub_person_chk', 'usspub_reason_chk',
            'usspub_seq_chk', 'usspub_shape_chk', 'usspub_simulation_gate_chk', 'usspub_size_chk'],
    ];

    /** Todo el Studio con el H9: H8 (68 / 84 / 55) + gobierno del H9 (55 / 53 / 53) + W9, las comprobaciones de Meta (4 / 4 / 3). */
    private const PG_STUDIO_TRIGGERS = 127;

    private const PG_STUDIO_CHECKS = 141;

    private const SQLITE_STUDIO_TRIGGERS = 111;

    /** Los parciales de todo el Studio: los 6 de H5/H6/H8 y los 3 del H9. */
    private const STUDIO_PARTIAL_INDEXES = ['uscl_one_active', 'uscn_one_live', 'uspb_one_active', 'ussv_one_canary', 'ussv_one_published', 'usv_one_canary', 'usv_one_published',
        'usva_one_canary', 'usva_one_rest'];

    private const FORBID_MD5 = '4ad748b5f17f09b398f7f641c197d7ee';

    private const H9_SUBJECTS = ['draft', 'version', 'rule', 'system', 'campaign_link', 'canary_number', 'integration', 'strategy', 'strategy_version', 'message_template', 'person_binding'];

    // Los mensajes (los mismos en los dos motores).
    private const BOOTSTRAP_TWO = self::P.'el arranque por consola solo vincula a las dos primeras personas (con dos vínculos vivos ya no cabe) (INSERT en ultron_studio_person_bindings)';

    private const BINDING_STARTS = self::P.'un vínculo de persona nace sin revocar y sin verificar, salvo el arranque por consola, que nace verificado (INSERT en ultron_studio_person_bindings)';

    private const BINDING_ONCE = self::P.'un vínculo de persona se verifica una sola vez (UPDATE en ultron_studio_person_bindings)';

    private const BINDING_VERIFIER = self::P.'un vínculo lo verifica una persona ya vinculada (su vínculo verificado y vivo) (UPDATE en ultron_studio_person_bindings)';

    private const BINDING_FINAL = self::P.'un vínculo de persona revocado es definitivo (UPDATE en ultron_studio_person_bindings)';

    private const BINDING_FROZEN = self::P.'un vínculo de persona fija al nacer la cuenta, la identidad, su origen y quién lo creó (UPDATE en ultron_studio_person_bindings)';

    private const STRATEGY_STARTS = self::P.'una estrategia nace sin archivar y con el borrador en la revisión 1 (INSERT en ultron_studio_strategies)';

    private const STRATEGY_FROZEN = self::P.'una estrategia fija al nacer su clave, su finalidad y su autoría (UPDATE en ultron_studio_strategies)';

    private const STRATEGY_DRAFT = self::P.'cada escritura del borrador de una estrategia sube draft_revision en 1 (baseRevision) (UPDATE en ultron_studio_strategies)';

    private const STRATEGY_ARCHIVE_LIVE = self::P.'una estrategia se archiva solo sin versiones en canario ni publicadas (UPDATE en ultron_studio_strategies)';

    private const STRATEGY_ARCHIVED = self::P.'una estrategia archivada es definitiva (UPDATE en ultron_studio_strategies)';

    private const VERSION_ON_ARCHIVED = self::P.'una estrategia archivada no admite versiones nuevas (INSERT en ultron_studio_strategy_versions)';

    private const VERSION_STARTS = self::P.'una versión de estrategia nace VALIDATED o REVIEW_REQUIRED, sin aprobación ni simulación (INSERT en ultron_studio_strategy_versions)';

    private const VERSION_FORWARD = self::P.'una versión de estrategia solo avanza por el ciclo del H5 (11.0) (UPDATE en ultron_studio_strategy_versions)';

    private const VERSION_FINAL = self::P.'una versión de estrategia rechazada o revertida es definitiva (UPDATE en ultron_studio_strategy_versions)';

    private const VERSION_FROZEN = self::P.'una versión de estrategia es inmutable en su contenido, su huella, su sello y su autoría (UPDATE en ultron_studio_strategy_versions)';

    private const VERSION_NUMBER = self::P.'una versión de estrategia lleva el número siguiente de su estrategia (1, 2, 3…) (INSERT en ultron_studio_strategy_versions)';

    private const VERSION_AUTHOR = self::P.'la autora de una versión de estrategia es una persona vinculada (su vínculo verificado y vivo) (INSERT en ultron_studio_strategy_versions)';

    private const VERSION_APPROVER = self::P.'una versión de estrategia la aprueba una persona vinculada (su vínculo verificado y vivo) (UPDATE en ultron_studio_strategy_versions)';

    private const VERSION_FOUR_EYES = self::P.'una versión de estrategia la aprueba otra persona y otra cuenta (cuatro ojos) (UPDATE en ultron_studio_strategy_versions)';

    private const VERSION_ONCE = self::P.'una versión de estrategia se aprueba una sola vez, con su simulación (UPDATE en ultron_studio_strategy_versions)';

    private const VERSION_GATE = self::P.'la aprobación cita una simulación de esa versión, de su huella exacta y sin bloqueantes (D9-12) (UPDATE en ultron_studio_strategy_versions)';

    private const SIMULATION_OF = self::P.'una simulación de una versión es de su estrategia y de su huella (INSERT en ultron_studio_strategy_simulations)';

    private const REVIEW_FOUR_EYES = self::P.'una versión de estrategia la revisa otra persona y otra cuenta (cuatro ojos) (INSERT en ultron_studio_strategy_reviews)';

    private const REVIEW_HASH = self::P.'una revisión es de la huella exacta de su versión (INSERT en ultron_studio_strategy_reviews)';

    private const REVIEW_BOUND = self::P.'una versión de estrategia la revisa una persona vinculada (su vínculo verificado y vivo) (INSERT en ultron_studio_strategy_reviews)';

    private const TEMPLATE_STARTS = self::P.'una plantilla nace en DRAFT, sin revisión (INSERT en ultron_studio_message_templates)';

    private const TEMPLATE_FORWARD = self::P.'una plantilla solo avanza: DRAFT → APPROVED o REJECTED; APPROVED → RETIRED (UPDATE en ultron_studio_message_templates)';

    private const TEMPLATE_FINAL = self::P.'una plantilla rechazada o retirada es definitiva (UPDATE en ultron_studio_message_templates)';

    private const TEMPLATE_FROZEN = self::P.'una plantilla congela al nacer su contenido, su huella y su autoría; cambiarla es crear la versión siguiente (UPDATE en ultron_studio_message_templates)';

    private const TEMPLATE_NUMBER = self::P.'una plantilla lleva el número siguiente de su clave (1, 2, 3…) (INSERT en ultron_studio_message_templates)';

    private const TEMPLATE_SHAPE = self::P.'las versiones de una plantilla conservan su canal y su finalidad (INSERT en ultron_studio_message_templates)';

    private const TEMPLATE_AUTHOR = self::P.'la autora de una plantilla es una persona vinculada (su vínculo verificado y vivo) (INSERT en ultron_studio_message_templates)';

    private const TEMPLATE_REVIEWER = self::P.'una plantilla la revisa una persona vinculada (su vínculo verificado y vivo) (UPDATE en ultron_studio_message_templates)';

    private const TEMPLATE_ONCE = self::P.'una plantilla se revisa una sola vez (UPDATE en ultron_studio_message_templates)';

    private const CHAIN = self::P.'una entrada nueva de la cadena de estrategias tiene que ser la siguiente a la cabeza (seq = cabeza + 1) y apuntar a ella (previous_publication_id y prev_hash) (INSERT en ultron_studio_strategy_publications)';

    private const SEQ_MIN = self::P.'seq empieza en 1 (INSERT en ultron_studio_strategy_publications)';

    private const CHAIN_FOUR_EYES = self::P.'publica y promueve una persona distinta de la autora (cuatro ojos) (INSERT en ultron_studio_strategy_publications)';

    private const CHAIN_BOUND = self::P.'publica, promueve y reanuda una persona vinculada (su vínculo verificado y vivo) (INSERT en ultron_studio_strategy_publications)';

    private const CHAIN_VERSION = self::P.'una entrada de la cadena nombra una versión de su estrategia (INSERT en ultron_studio_strategy_publications)';

    /** Protección 3 del H9: ninguna prueba sale a la red, tampoco las de esquema. */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        putenv('ULTRON_STUDIO_DB_APP_ROLE');
        parent::tearDown();
    }

    // ── Columnas, recuentos, funciones y fronteras ──────────────────────────

    public function test_columnas_tipos_y_nulos_de_las_siete_tablas(): void
    {
        $motor = $this->isPostgres() ? 0 : 1;
        foreach ([
            self::T_BINDINGS => self::COLUMNS_PERSON_BINDINGS,
            self::T_STRATEGIES => self::COLUMNS_STRATEGIES,
            self::T_TEMPLATES => self::COLUMNS_MESSAGE_TEMPLATES,
            self::T_VERSIONS => self::COLUMNS_STRATEGY_VERSIONS,
            self::T_SIMULATIONS => self::COLUMNS_STRATEGY_SIMULATIONS,
            self::T_REVIEWS => self::COLUMNS_STRATEGY_REVIEWS,
            self::T_PUBLICATIONS => self::COLUMNS_STRATEGY_PUBLICATIONS,
        ] as $tabla => $esperadas) {
            $reales = [];
            foreach (Schema::getColumns($tabla) as $c) {
                $reales[$c['name']] = [$c['type'], (bool) $c['nullable']];
            }
            $this->assertSame(array_map(static fn (array $e): array => [$e[$motor], $e[2]], $esperadas), $reales, $tabla);
            // Ningún dato personal: ni teléfono, ni documento, ni cuerpo de mensaje (protección 10).
            foreach (array_keys($reales) as $columna) {
                $this->assertDoesNotMatchRegularExpression('/phone|e164|document|email|birth|first_name|body|transcript/', $columna, "{$tabla}.{$columna}");
            }
        }
    }

    public function test_recuentos_y_nombres_de_las_guardas_del_gobierno(): void
    {
        $this->assertSame(self::STUDIO_PARTIAL_INDEXES, $this->partialIndexesLike('ultron_studio_'));
        $this->assertSame($this->isPostgres()
            ? ['uspb_one_active' => '(revoked_at IS NULL)', 'ussv_one_canary' => "((status)::text = 'CANARY'::text)", 'ussv_one_published' => "((status)::text = 'PUBLISHED'::text)"]
            : ['uspb_one_active' => 'revoked_at IS NULL', 'ussv_one_canary' => "status = 'CANARY'", 'ussv_one_published' => "status = 'PUBLISHED'"],
            $this->partialPredicates(self::GOVERNANCE_TABLES));

        if ($this->isPostgres()) {
            foreach (self::PG_TRIGGERS as $tabla => $nombres) {
                $this->assertSameSet($nombres, $this->triggersOn($tabla), $tabla);
            }
            foreach (self::PG_CHECKS as $tabla => $nombres) {
                $this->assertSameSet($nombres, $this->checksOn($tabla), $tabla);
            }
            $this->assertSame(55, $this->countTriggers(self::GOVERNANCE_TABLES), 'vínculos 8 + estrategias 7 + plantillas 11 + versiones 12 + simulaciones 4 + revisiones 6 + cadena 7');
            $this->assertSame(53, $this->countChecks(self::GOVERNANCE_TABLES), 'vínculos 5 + estrategias 6 + plantillas 12 + versiones 11 + simulaciones 5 + revisiones 4 + cadena 10');
            $this->assertSame(self::PG_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron\\_studio\\_%'), 'H8 68 + H9 55 + W9 4');
            $this->assertSame(self::PG_STUDIO_CHECKS, $this->countLike('check', 'ultron\\_studio\\_%'), 'H8 84 + H9 53 + W9 4 (usae_subject_type_chk se sustituye, no se suma)');
            $this->assertSame(26, (int) DB::scalar("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\\_studio\\_%'"));  // + W9
            $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname = ANY(?::text[]) AND t.tgenabled <> 'O'",
                ['{'.implode(',', self::GOVERNANCE_TABLES).'}']), 'ninguno apagado');

            return;
        }

        foreach (self::PG_TRIGGERS as $tabla => $nombres) {
            $this->assertSameSet([...array_filter($nombres, static fn (string $n): bool => ! str_ends_with($n, '_truncate')), ...(self::SQLITE_ONLY_TRIGGERS[$tabla] ?? [])],
                $this->triggersOn($tabla), $tabla);
        }
        $this->assertSame(53, $this->countTriggers(self::GOVERNANCE_TABLES), '55 − 7 de TRUNCATE + 5 equivalentes');
        $this->assertSame(self::SQLITE_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron_studio_%'), 'H8 55 + H9 53 + W9 3');
    }

    public function test_solo_en_postgres_las_funciones_son_propias_invoker_y_la_de_f_no_cambia(): void
    {
        $this->requirePostgres('las funciones de los disparadores solo existen en PostgreSQL.');

        $m = $this->migrationNamed(self::GOVERNANCE_MIGRATION);
        $propias = $m::ownFunctions();
        $this->assertCount(20, $propias, '19 guardas entre tablas + la cadena');
        foreach ($propias as $funcion) {
            $f = DB::selectOne('SELECT l.lanname AS lang, p.provolatile AS vol, p.prosecdef::int AS definer, pg_get_userbyid(p.proowner) AS owner, p.prosrc AS src
                FROM pg_proc p JOIN pg_language l ON l.oid = p.prolang WHERE p.proname = ?', [$funcion]);
            $this->assertNotNull($f, $funcion);
            $this->assertSame(['plpgsql', 'v', 0, (string) DB::scalar('SELECT current_user')], [$f->lang, $f->vol, (int) $f->definer, $f->owner], $funcion);
            // Toda tabla que lee va cualificada: una temporal con el mismo nombre no la suplanta.
            preg_match_all('/FROM\s+([a-z_.]+)\s+AS\s/', (string) $f->src, $tablas);
            $this->assertNotSame([], $tablas[1], $funcion);
            foreach ($tablas[1] as $tabla) {
                $this->assertStringStartsWith('public.', $tabla, "{$funcion}: {$tabla}");
            }
        }
        $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM pg_proc WHERE proname = ANY(?::text[]) AND prosecdef', ['{'.implode(',', $propias).'}']));
        // Los disparadores de fila usan la de F, que no se redeclara ni cambia.
        $this->assertSame(28, (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_proc p ON p.oid = t.tgfoid
            WHERE NOT t.tgisinternal AND c.relname = ANY(?::text[]) AND p.proname = 'ultron_studio_forbid' AND t.tgtype & 1 = 1", ['{'.implode(',', self::GOVERNANCE_TABLES).'}']));
        $this->assertSame(self::FORBID_MD5, DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
        $this->assertStringNotContainsString('FUNCTION ultron_studio_forbid', (string) file_get_contents(database_path('migrations/'.self::GOVERNANCE_MIGRATION.'.php')));
    }

    /** Protecciones 5–7: el gobierno no nombra la cadena del H5, la de voz, el motor ni Marketing; sus FKs, solo a identidades y entre ellas. */
    public function test_el_gobierno_no_nombra_lo_ajeno_y_sus_fks_son_restrict(): void
    {
        $codigo = '';
        foreach (token_get_all((string) file_get_contents(database_path('migrations/'.self::GOVERNANCE_MIGRATION.'.php'))) as $t) {
            if (! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $codigo .= is_array($t) ? $t[1] : $t;
            }
        }
        $this->assertDoesNotMatchRegularExpression('/\bultron_studio_publications\b/', $codigo);
        foreach (['ultron_studio_voice_', 'marketing_', 'commercial_', 'voice_calls', 'UltronPublishedConfig', 'outreach_'] as $nombre) {
            $this->assertStringNotContainsString($nombre, $codigo, $nombre);
        }

        $fks = [];
        foreach (self::GOVERNANCE_TABLES as $tabla) {
            foreach (Schema::getForeignKeys($tabla) as $fk) {
                $fks[] = "{$tabla}.".implode(',', $fk['columns'])." → {$fk['foreign_table']}";
                $this->assertSame('restrict', strtolower((string) $fk['on_delete']), $tabla);
            }
        }
        $this->assertSameSet([
            'ultron_studio_person_bindings.identity_id → identities',
            'ultron_studio_strategy_publications.previous_publication_id → ultron_studio_strategy_publications',
            'ultron_studio_strategy_publications.strategy_id → ultron_studio_strategies',
            'ultron_studio_strategy_publications.version_id → ultron_studio_strategy_versions',
            'ultron_studio_strategy_reviews.version_id → ultron_studio_strategy_versions',
            'ultron_studio_strategy_simulations.strategy_id → ultron_studio_strategies',
            'ultron_studio_strategy_simulations.version_id → ultron_studio_strategy_versions',
            'ultron_studio_strategy_versions.strategy_id → ultron_studio_strategies',
        ], $fks);
    }

    public function test_el_libro_admite_los_cuatro_sujetos_del_hito_9(): void
    {
        $evento = fn (string $sujeto): bool => DB::table('ultron_studio_audit_events')->insert([
            'event' => 'strategy.created', 'subject_type' => $sujeto, 'subject_uuid' => (string) Str::uuid(), 'actor_type' => 'admin',
            'actor_admin_id' => 1, 'actor_name' => 'Ana', 'summary' => 'Prueba del Hito 9', 'created_at' => now(),
        ]);
        foreach (['strategy', 'strategy_version', 'message_template', 'person_binding'] as $sujeto) {
            $this->assertAccepted(fn () => $evento($sujeto), $sujeto);
        }
        if (! $this->isPostgres()) {
            // En SQLite el libro no tiene CHECK (F lo añade solo en PG) y el Hito 9 no lo toca.
            $this->assertStringNotContainsString('strategy', (string) DB::table('sqlite_master')->where('name', 'ultron_studio_audit_events')->value('sql'));

            return;
        }
        $this->assertSame(self::H9_SUBJECTS, $this->checkValues('usae_subject_type_chk'));
        $this->assertRejected(fn () => $evento('strategies'), '23514', 'usae_subject_type_chk');
    }

    // ── Vínculos cuenta → persona (11.2) ────────────────────────────────────

    public function test_el_arranque_por_consola_solo_vincula_a_las_dos_primeras_personas(): void
    {
        [$a, $b, $c, $d] = [$this->insertIdentity(), $this->insertIdentity(), $this->insertIdentity(), $this->insertIdentity()];
        $this->insertBinding(1, $a, $this->bootstrapOverrides());
        $this->assertGuarded(fn () => $this->insertBinding(2, $b, ['origin' => 'bootstrap']), self::BINDING_STARTS, 'por consola sin verificar');
        $beto = $this->insertBinding(2, $b, $this->bootstrapOverrides());
        $this->assertGuarded(fn () => $this->insertBinding(3, $c, $this->bootstrapOverrides()), self::BOOTSTRAP_TWO, 'la tercera por consola');

        // Si una de las dos se va (revocada por otra cuenta), cabe otra por consola: el gobierno no queda bloqueado para siempre.
        $this->db()->table(self::T_BINDINGS)->where('id', $beto)->update(['revoked_at' => now(), 'revoked_by_admin_id' => 1, 'revoked_by_name' => 'Ana', 'revoke_reason' => 'Dejó la empresa']);
        $this->assertAccepted(fn () => $this->insertBinding(3, $c, $this->bootstrapOverrides()), 'la segunda viva, por consola');
        $this->assertGuarded(fn () => $this->insertBinding(4, $d, $this->bootstrapOverrides()), self::BOOTSTRAP_TWO, 'con dos vivas otra vez');
    }

    public function test_un_vinculo_lo_crea_una_persona_para_otra_cuenta_y_lo_verifica_otra_ya_vinculada_una_vez(): void
    {
        $p = $this->people();
        $dani = $this->insertIdentity();
        $ajena = $this->insertIdentity();
        foreach ([['verified_at' => now()], ['verified_by_admin_id' => 2], ['revoked_at' => now()]] as $nace) {
            $this->assertGuarded(fn () => $this->insertBinding(4, $dani, $nace), self::BINDING_STARTS, 'nace '.implode(',', array_keys($nace)));
        }
        $this->fourEyes(fn () => $this->insertBinding(4, $dani, ['created_by_admin_id' => 4, 'created_by_name' => 'Dani']), 'uspb_four_eyes_chk',
            self::P.'nadie vincula su propia cuenta (cuatro ojos) (INSERT en ultron_studio_person_bindings)', 'vincular la propia cuenta');

        $id = $this->insertBinding(4, $dani);   // lo crea Ana
        $verifica = self::P.'un vínculo lo verifica una segunda persona, distinta de la vinculada y de quien lo creó, y nadie revoca el propio (cuatro ojos) (UPDATE en ultron_studio_person_bindings)';
        $this->assertGuarded(fn () => $this->verifyBinding($id, 4, $dani), self::BINDING_VERIFIER, 'la propia cuenta, sin vínculo verificado, verifica');
        $this->fourEyes(fn () => $this->verifyBinding($id, 1, $p[1]), 'uspb_four_eyes_chk', $verifica, 'verifica quien lo creó');
        // La segunda cuenta de Ana (6) la crea Beto y Ana no la puede verificar: es la misma persona.
        $segunda = $this->insertBinding(6, $p[1], ['created_by_admin_id' => 2, 'created_by_name' => 'Beto']);
        $this->fourEyes(fn () => $this->verifyBinding($segunda, 1, $p[1]), 'uspb_four_eyes_chk', $verifica, 'la misma persona verifica su otra cuenta');
        $this->assertAccepted(fn () => $this->verifyBinding($segunda, 3, $p[3]), 'Caro verifica la segunda cuenta de Ana');
        $this->assertGuarded(fn () => $this->verifyBinding($id, 2, $dani), self::BINDING_VERIFIER, 'Beto con la identidad vinculada');
        // Quien verifica es una persona YA vinculada: su cuenta con esa identidad, verificada y viva.
        $this->assertGuarded(fn () => $this->verifyBinding($id, 5, $ajena), self::BINDING_VERIFIER, 'una cuenta sin vínculo');
        $this->assertGuarded(fn () => $this->verifyBinding($id, 2, $p[3]), self::BINDING_VERIFIER, 'Beto con la identidad de Caro');
        $pendiente = $this->insertBinding(5, $ajena);
        $this->assertGuarded(fn () => $this->verifyBinding($id, 5, $ajena), self::BINDING_VERIFIER, 'una cuenta con el vínculo sin verificar');
        $this->assertAccepted(fn () => $this->verifyBinding($id, 2, $p[2]), 'lo verifica Beto');
        $this->assertGuarded(fn () => $this->verifyBinding($id, 3, $p[3]), self::BINDING_ONCE, 'verificarlo otra vez');
        $this->assertGuarded(fn () => $this->db()->table(self::T_BINDINGS)->where('id', $id)->update(['verified_by_name' => 'Otro']), self::BINDING_ONCE, 'cambiar quién verificó');
        $this->assertSame(1, $this->db()->table(self::T_BINDINGS)->where('id', $pendiente)->whereNull('verified_at')->count());
    }

    public function test_un_vinculo_vivo_por_cuenta_la_identidad_no_cambia_y_revocar_es_definitivo(): void
    {
        $p = $this->people();
        $dani = $this->insertIdentity();
        $id = $this->insertBinding(4, $dani);
        $this->assertUniqueRejects(fn () => $this->insertBinding(4, $p[1]), 'uspb_one_active', self::T_BINDINGS, ['admin_id'], 'dos vivos de la misma cuenta');
        foreach (self::BINDING_IMMUTABLE as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_BINDINGS." SET {$columna} = {$columna} WHERE id = ?", [$id]), self::BINDING_FROZEN, $columna);
        }
        $this->fourEyes(fn () => $this->db()->table(self::T_BINDINGS)->where('id', $id)->update(['revoked_at' => now(), 'revoked_by_admin_id' => 4, 'revoked_by_name' => 'Dani', 'revoke_reason' => 'x']),
            'uspb_four_eyes_chk', self::P.'un vínculo lo verifica una segunda persona, distinta de la vinculada y de quien lo creó, y nadie revoca el propio (cuatro ojos) (UPDATE en ultron_studio_person_bindings)',
            'revocar el propio');
        $this->assertAccepted(fn () => $this->db()->table(self::T_BINDINGS)->where('id', $id)->update(['revoked_at' => now(), 'revoked_by_admin_id' => 1, 'revoked_by_name' => 'Ana', 'revoke_reason' => 'Se equivocó de persona']), 'Ana lo revoca');
        foreach ([['revoke_reason' => 'otra'], ['updated_at' => now()], ['verified_at' => now()]] as $cambio) {
            $this->assertGuarded(fn () => $this->db()->table(self::T_BINDINGS)->where('id', $id)->update($cambio), self::BINDING_FINAL, json_encode(array_keys($cambio)));
        }
        // Revocado, la cuenta puede tener otro vínculo vivo.
        $this->assertAccepted(fn () => $this->insertBinding(4, $dani), 'un vínculo nuevo tras revocar');
        $this->assertGuarded(fn () => $this->db()->table(self::T_BINDINGS)->where('id', $id)->delete(), self::P.'los vínculos de personas se revocan, no se borran (DELETE en ultron_studio_person_bindings)');
        // La identidad no se borra mientras tenga un vínculo (FK RESTRICT, §6.1).
        $this->isPostgres()
            ? $this->assertRejected(fn () => $this->db()->table('identities')->where('id', $dani)->delete(), '23503', 'uspb_identity_fk')
            : $this->assertRejected(fn () => $this->db()->table('identities')->where('id', $dani)->delete(), '23000', 'FOREIGN KEY constraint failed');
    }

    // ── Estrategias ─────────────────────────────────────────────────────────

    public function test_una_estrategia_nace_limpia_su_clave_y_finalidad_no_cambian_y_el_borrador_sube_de_uno_en_uno(): void
    {
        $this->assertGuarded(fn () => $this->insertStrategy(['draft_revision' => 2]), self::STRATEGY_STARTS, 'nace en la revisión 2');
        $this->assertGuarded(fn () => $this->insertStrategy(['archived_at' => now(), 'archived_by_admin_id' => 1, 'archived_by_name' => 'Ana']), self::STRATEGY_STARTS, 'nace archivada');
        $id = $this->insertStrategy();
        foreach (self::STRATEGY_IMMUTABLE as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_STRATEGIES." SET {$columna} = {$columna} WHERE id = ?", [$id]), self::STRATEGY_FROZEN, $columna);
        }
        $this->assertAccepted(fn () => $this->db()->table(self::T_STRATEGIES)->where('id', $id)->update(['name' => 'Renovación a 10 días']), 'el nombre cambia');

        $guardar = fn (int $revision): int => $this->db()->table(self::T_STRATEGIES)->where('id', $id)->update([
            'draft_json' => '{"goal":"renew","n":'.$revision.'}', 'draft_revision' => $revision, 'draft_updated_by_admin_id' => 2, 'draft_updated_by_name' => 'Beto', 'draft_updated_at' => now()]);
        $this->assertGuarded(fn () => $guardar(1), self::STRATEGY_DRAFT, 'el borrador sin subir la revisión');
        $this->assertGuarded(fn () => $guardar(3), self::STRATEGY_DRAFT, 'saltando una revisión');
        $this->assertAccepted(fn () => $guardar(2), 'la revisión 2');
        $this->assertGuarded(fn () => $this->db()->table(self::T_STRATEGIES)->where('id', $id)->update(['draft_json' => '{"goal":"win_back"}']), self::STRATEGY_DRAFT, 'el borrador sin tocar la revisión');
        // El UPDATE condicionado de baseRevision: con la revisión vieja no toca nada.
        $this->assertSame(0, $this->db()->table(self::T_STRATEGIES)->where('id', $id)->where('draft_revision', 1)->update(['draft_json' => '{}', 'draft_revision' => 2]));
        $this->assertSame(2, (int) $this->db()->table(self::T_STRATEGIES)->where('id', $id)->value('draft_revision'));
    }

    public function test_una_estrategia_se_archiva_una_vez_y_sin_versiones_vivas(): void
    {
        $live = $this->liveVersion();
        $archivar = fn (): int => $this->db()->table(self::T_STRATEGIES)->where('id', $live['strategy'])->update(['archived_at' => now(), 'archived_by_admin_id' => 1, 'archived_by_name' => 'Ana']);
        $this->assertGuarded($archivar, self::STRATEGY_ARCHIVE_LIVE, 'con la versión en canario');
        $this->setVersionStatus($live['version'], 'PUBLISHED');
        $this->assertGuarded($archivar, self::STRATEGY_ARCHIVE_LIVE, 'con la versión publicada');
        $this->setVersionStatus($live['version'], 'ROLLED_BACK');
        $this->assertAccepted($archivar, 'revertida, se archiva');
        $this->assertGuarded(fn () => $this->db()->table(self::T_STRATEGIES)->where('id', $live['strategy'])->update(['name' => 'Otra']), self::STRATEGY_ARCHIVED, 'archivada');
        $this->assertGuarded(fn () => $this->insertVersion($live['strategy']), self::VERSION_ON_ARCHIVED, 'una versión nueva');
        $this->assertGuarded(fn () => $this->db()->table(self::T_STRATEGIES)->where('id', $live['strategy'])->delete(), self::P.'las estrategias se archivan, no se borran (DELETE en ultron_studio_strategies)');
    }

    // ── Versiones ───────────────────────────────────────────────────────────

    public function test_una_version_nace_validada_sin_aprobacion_y_con_el_numero_siguiente(): void
    {
        $s = $this->insertStrategy();
        foreach (['DRAFT', 'APPROVED', 'CANARY', 'PUBLISHED', 'REJECTED'] as $estado) {
            $this->assertGuarded(fn () => $this->insertVersion($s, ['status' => $estado, 'number' => 1]), self::VERSION_STARTS, "nace {$estado} a secas");
        }
        $this->assertGuarded(fn () => $this->insertVersion($s, ['number' => 1, 'approved_by_admin_id' => 2, 'approved_by_name' => 'Beto', 'approved_by_person_id' => $this->people()[2], 'approved_at' => now()]),
            self::VERSION_STARTS, 'nace aprobada');
        $this->assertGuarded(fn () => $this->insertVersion($s, ['number' => 1, 'simulation_uuid' => (string) Str::uuid(), 'simulation_hash' => $this->hex('s')]), self::VERSION_STARTS, 'nace con simulación');
        $this->assertGuarded(fn () => $this->insertVersion($s, ['number' => 2]), self::VERSION_NUMBER, 'empieza en 2');
        $this->assertAccepted(fn () => $this->insertVersion($s, ['number' => 1, 'status' => 'REVIEW_REQUIRED']), 'la 1, con avisos');
        $this->assertGuarded(fn () => $this->insertVersion($s, ['number' => 1]), self::VERSION_NUMBER, 'la 1 otra vez');
        $this->assertGuarded(fn () => $this->insertVersion($s, ['number' => 3]), self::VERSION_NUMBER, 'saltando a la 3');
        $this->assertAccepted(fn () => $this->insertVersion($s, ['number' => 2]), 'la 2');
        $this->assertAccepted(fn () => $this->insertVersion($this->insertStrategy(), ['number' => 1]), 'la 1 de otra estrategia');
    }

    /** @return array<string, array{string, string, bool}> */
    public static function versionMoves(): array
    {
        $estados = ['VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED', 'CANARY', 'PUBLISHED', 'RETIRED', 'ROLLED_BACK'];
        $grafo = [
            'VALIDATED' => ['APPROVED', 'REJECTED'], 'REVIEW_REQUIRED' => ['APPROVED', 'REJECTED'], 'APPROVED' => ['CANARY'],
            'CANARY' => ['PUBLISHED', 'ROLLED_BACK'], 'PUBLISHED' => ['RETIRED', 'ROLLED_BACK'], 'RETIRED' => ['PUBLISHED'],
        ];
        $casos = [];
        foreach ($estados as $desde) {
            foreach ([...$estados, 'DRAFT', 'VALIDATING'] as $hacia) {
                $casos["{$desde} → {$hacia}"] = [$desde, $hacia, in_array($hacia, $grafo[$desde] ?? [], true)];
            }
        }

        return $casos;
    }

    #[DataProvider('versionMoves')]
    public function test_una_version_solo_avanza_por_el_ciclo_del_h5(string $desde, string $hacia, bool $admitida): void
    {
        $id = $this->versionIn($desde);
        $cambio = ['status' => $hacia, 'status_changed_at' => now()];
        $aprobacion = in_array($hacia, ['APPROVED'], true) && in_array($desde, ['VALIDATED', 'REVIEW_REQUIRED'], true);
        $mover = fn (): int => $aprobacion ? $this->approveVersion($id) : $this->db()->table(self::T_VERSIONS)->where('id', $id)->update($cambio);

        if ($admitida) {
            $this->assertAccepted($mover, "{$desde} → {$hacia}");
            $this->assertSame($hacia, $this->db()->table(self::T_VERSIONS)->where('id', $id)->value('status'));

            return;
        }
        if (in_array($desde, ['REJECTED', 'ROLLED_BACK'], true)) {
            $this->assertGuarded($mover, self::VERSION_FINAL, "{$desde} es final");
        } elseif ($desde === $hacia) {
            $this->assertAccepted($mover, 'el mismo estado no cambia nada');
        } else {
            $this->assertGuarded($mover, self::VERSION_FORWARD, "{$desde} → {$hacia}");
        }
        $this->assertSame($desde, $this->db()->table(self::T_VERSIONS)->where('id', $id)->value('status'));
    }

    public function test_el_contenido_de_una_version_no_cambia_y_es_el_de_su_huella(): void
    {
        $id = $this->insertVersion($this->insertStrategy());
        foreach (self::VERSION_IMMUTABLE as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_VERSIONS." SET {$columna} = {$columna} WHERE id = ?", [$id]), self::VERSION_FROZEN, $columna);
        }
        $this->assertGuarded(fn () => $this->db()->table(self::T_VERSIONS)->where('id', $id)->delete(), self::P.'las versiones de estrategia no se borran (DELETE en ultron_studio_strategy_versions)');
        if ($this->isPostgres()) {
            $this->assertRejected(fn () => $this->insertVersion($this->insertStrategy(), ['content_json' => '{"goal":"renew"}', 'content_hash' => $this->hex('otra')]), '23514', 'ussv_hash_matches_chk');
            $this->assertGuarded(fn () => DB::statement('TRUNCATE '.self::T_VERSIONS.' CASCADE'), self::P.'las versiones de estrategia no se borran (TRUNCATE en ultron_studio_strategy_versions)');
        }
    }

    public function test_la_autora_y_quien_aprueba_son_personas_vinculadas_y_distintas(): void
    {
        $p = $this->people();
        $s = $this->insertStrategy();
        $dani = $this->insertIdentity();
        $this->insertBinding(4, $dani);   // Dani: vínculo creado, sin verificar
        $this->assertGuarded(fn () => $this->insertVersion($s, ['created_by_admin_id' => 5, 'created_by_name' => 'Eva', 'created_by_person_id' => $dani]), self::VERSION_AUTHOR, 'una cuenta sin vínculo');
        $this->assertGuarded(fn () => $this->insertVersion($s, ['created_by_admin_id' => 4, 'created_by_name' => 'Dani', 'created_by_person_id' => $dani]), self::VERSION_AUTHOR, 'un vínculo sin verificar');
        $this->assertGuarded(fn () => $this->insertVersion($s, ['created_by_person_id' => $p[2]]), self::VERSION_AUTHOR, 'Ana con la identidad de Beto');

        $v = $this->insertVersion($s);   // Ana
        // La segunda cuenta de Ana (6), vinculada a SU identidad: es la misma persona (11.2).
        $this->verifyBinding($this->insertBinding(6, $p[1]), 2, $p[2]);
        $this->fourEyes(fn () => $this->approveVersion($v, 1), 'ussv_four_eyes_chk', self::VERSION_FOUR_EYES, 'Ana aprueba la suya');
        $this->people[6] = $p[1];
        $this->fourEyes(fn () => $this->approveVersion($v, 6), 'ussv_four_eyes_chk', self::VERSION_FOUR_EYES, 'la otra cuenta de Ana');
        $this->people[5] = $dani;
        $this->assertGuarded(fn () => $this->approveVersion($v, 5), self::VERSION_APPROVER, 'una cuenta sin vínculo');
        $this->assertSame('VALIDATED', $this->db()->table(self::T_VERSIONS)->where('id', $v)->value('status'));
        $this->assertAccepted(fn () => $this->approveVersion($v, 3), 'Caro (vinculada por el CRM) la aprueba');
        $this->assertGuarded(fn () => $this->db()->table(self::T_VERSIONS)->where('id', $v)->update(['approved_by_admin_id' => 2, 'approved_by_name' => 'Beto', 'approved_by_person_id' => $p[2]]),
            self::VERSION_ONCE, 'cambiar quién aprobó');
        $this->assertGuarded(fn () => $this->db()->table(self::T_VERSIONS)->where('id', $v)->update(['simulation_uuid' => (string) Str::uuid(), 'simulation_hash' => $this->hex('tarde')]),
            self::VERSION_ONCE, 'citar una simulación después de aprobar');
    }

    public function test_la_aprobacion_cita_una_simulacion_de_esa_version_de_su_huella_y_sin_bloqueantes(): void
    {
        $s = $this->insertStrategy();
        $v = $this->insertVersion($s);
        $otra = $this->insertVersion($s);
        $cita = fn (int $sim): array => ['simulation_uuid' => $this->db()->table(self::T_SIMULATIONS)->where('id', $sim)->value('uuid'),
            'simulation_hash' => $this->db()->table(self::T_SIMULATIONS)->where('id', $sim)->value('result_hash')];
        foreach ([
            'de otra versión' => $this->insertSimulation($s, $otra),
            'del borrador' => $this->insertSimulation($s, null, ['content_hash' => (string) $this->db()->table(self::T_VERSIONS)->where('id', $v)->value('content_hash')]),
            'con un bloqueante' => $this->insertSimulation($s, $v, ['blocking_findings' => 1]),
        ] as $que => $sim) {
            $this->assertGuarded(fn () => $this->approveVersion($v, 2, $cita($sim)), self::VERSION_GATE, $que);
        }
        $buena = $this->insertSimulation($s, $v);
        $this->assertGuarded(fn () => $this->approveVersion($v, 2, ['simulation_uuid' => $cita($buena)['simulation_uuid'], 'simulation_hash' => $this->hex('otro-resultado')]), self::VERSION_GATE, 'otra huella del resultado');
        $this->assertGuarded(fn () => $this->approveVersion($v, 2, ['simulation_uuid' => (string) Str::uuid(), 'simulation_hash' => $this->hex('x')]), self::VERSION_GATE, 'una que no existe');
        $this->assertAccepted(fn () => $this->approveVersion($v, 2, $cita($buena)), 'la de su huella, sin bloqueantes');
        // Con la puerta en `waived` (solo el propietario) se aprueba sin simulación; sin aprobación no se cita ninguna (PG).
        $this->assertAccepted(fn () => $this->approveVersion($otra), 'aprobada sin simulación');
        if ($this->isPostgres()) {
            $tercera = $this->insertVersion($s);
            DB::beginTransaction();
            try {
                DB::statement('ALTER TABLE '.self::T_VERSIONS.' DISABLE TRIGGER USER');
                $this->assertRejected(fn () => $this->db()->table(self::T_VERSIONS)->where('id', $tercera)->update($cita($buena)), '23514', 'ussv_simulation_chk');
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_una_version_en_canario_y_una_publicada_por_estrategia(): void
    {
        $s = $this->insertStrategy();
        $a = $this->versionIn('CANARY', $s);
        $b = $this->versionIn('APPROVED', $s);
        $this->assertUniqueRejects(fn () => $this->setVersionStatus($b, 'CANARY'), 'ussv_one_canary', self::T_VERSIONS, ['strategy_id'], 'dos en canario');
        $this->setVersionStatus($a, 'PUBLISHED');
        $this->assertAccepted(fn () => $this->setVersionStatus($b, 'CANARY'), 'la otra al canario');
        $this->assertUniqueRejects(fn () => $this->setVersionStatus($b, 'PUBLISHED'), 'ussv_one_published', self::T_VERSIONS, ['strategy_id'], 'dos publicadas');
        // Promover: la publicada anterior se retira en la misma transacción.
        $this->assertAccepted(function () use ($a, $b): void {
            $this->setVersionStatus($a, 'RETIRED');
            $this->setVersionStatus($b, 'PUBLISHED');
        }, 'promover');
        $this->assertAccepted(fn () => $this->versionIn('CANARY', $this->insertStrategy()), 'otra estrategia tiene su canario');
    }

    // ── Revisiones y simulaciones ───────────────────────────────────────────

    public function test_una_revision_por_version_de_otra_persona_vinculada_y_de_su_huella(): void
    {
        $p = $this->people();
        $v = $this->insertVersion($this->insertStrategy());
        $this->verifyBinding($this->insertBinding(6, $p[1]), 2, $p[2]);   // la otra cuenta de Ana
        $this->people[6] = $p[1];
        $this->people[5] = $this->insertIdentity();
        $revisar = fn (int $quien, array $o = []): bool => $this->db()->table(self::T_REVIEWS)->insert($this->reviewRow($v, $quien, $o));
        $this->assertGuarded(fn () => $revisar(1), self::REVIEW_FOUR_EYES, 'Ana revisa la suya');
        $this->assertGuarded(fn () => $revisar(6), self::REVIEW_FOUR_EYES, 'la otra cuenta de Ana');
        $this->assertGuarded(fn () => $revisar(5), self::REVIEW_BOUND, 'una cuenta sin vínculo');
        $this->assertGuarded(fn () => $revisar(2, ['content_hash' => $this->hex('otra')]), self::REVIEW_HASH, 'otra huella');
        if ($this->isPostgres()) {
            $this->assertRejected(fn () => $revisar(2, ['decision' => 'rejected']), '23514', 'ussr_note_chk');
        }
        $this->assertAccepted(fn () => $revisar(2, ['decision' => 'rejected', 'note' => 'Falta la exclusión de cartera']), 'Beto la rechaza con nota');
        $this->assertUniqueRejects(fn () => $revisar(3), 'ussr_version_unique', self::T_REVIEWS, ['version_id'], 'una segunda revisión');
        $id = (int) $this->db()->table(self::T_REVIEWS)->where('version_id', $v)->value('id');
        $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_REVIEWS.' SET note = note WHERE id = ?', [$id]), self::P.'las revisiones de estrategia son de solo inserción (UPDATE en ultron_studio_strategy_reviews)');
        $this->assertGuarded(fn () => $this->db()->table(self::T_REVIEWS)->where('id', $id)->delete(), self::P.'las revisiones de estrategia son de solo inserción (DELETE en ultron_studio_strategy_reviews)');
    }

    public function test_las_simulaciones_son_de_solo_insercion_y_de_su_version(): void
    {
        $s = $this->insertStrategy();
        $v = $this->insertVersion($s);
        $this->assertGuarded(fn () => $this->insertSimulation($this->insertStrategy(), $v), self::SIMULATION_OF, 'de otra estrategia');
        $this->assertGuarded(fn () => $this->insertSimulation($s, $v, ['content_hash' => $this->hex('otra')]), self::SIMULATION_OF, 'otra huella');
        $this->assertAccepted(fn () => $this->insertSimulation($s, null), 'del borrador');
        $id = $this->insertSimulation($s, $v, ['kind' => 'sample', 'sample_size' => 0, 'blocking_findings' => 1]);
        $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_SIMULATIONS.' SET blocking_findings = 0 WHERE id = ?', [$id]), self::P.'las simulaciones de estrategia son de solo inserción (UPDATE en ultron_studio_strategy_simulations)');
        $this->assertGuarded(fn () => $this->db()->table(self::T_SIMULATIONS)->where('id', $id)->delete(), self::P.'las simulaciones de estrategia son de solo inserción (DELETE en ultron_studio_strategy_simulations)');
    }

    // ── Plantillas ──────────────────────────────────────────────────────────

    /** @return array<string, array{string, string, bool}> */
    public static function templateMoves(): array
    {
        $estados = ['DRAFT', 'APPROVED', 'REJECTED', 'RETIRED'];
        $grafo = ['DRAFT' => ['APPROVED', 'REJECTED'], 'APPROVED' => ['RETIRED']];
        $casos = [];
        foreach ($estados as $desde) {
            foreach ([...$estados, 'PUBLISHED'] as $hacia) {
                $casos["{$desde} → {$hacia}"] = [$desde, $hacia, in_array($hacia, $grafo[$desde] ?? [], true)];
            }
        }

        return $casos;
    }

    #[DataProvider('templateMoves')]
    public function test_una_plantilla_nace_en_draft_y_solo_avanza(string $desde, string $hacia, bool $admitida): void
    {
        $id = $this->insertTemplate();
        if ($desde !== 'DRAFT') {
            $this->reviewTemplate($id, $desde === 'RETIRED' ? 'APPROVED' : $desde);
            if ($desde === 'RETIRED') {
                $this->db()->table(self::T_TEMPLATES)->where('id', $id)->update(['status' => 'RETIRED']);
            }
        }
        $mover = fn (): int => $desde === 'DRAFT' && in_array($hacia, ['APPROVED', 'REJECTED'], true)
            ? $this->reviewTemplate($id, $hacia)
            : $this->db()->table(self::T_TEMPLATES)->where('id', $id)->update(['status' => $hacia, 'status_changed_at' => now()]);

        if ($admitida) {
            $this->assertAccepted($mover, "{$desde} → {$hacia}");

            return;
        }
        if (in_array($desde, ['REJECTED', 'RETIRED'], true)) {
            $this->assertGuarded($mover, self::TEMPLATE_FINAL, "{$desde} es final");
        } elseif ($desde === $hacia) {
            $this->assertAccepted($mover, 'el mismo estado');
        } else {
            $this->assertGuarded($mover, self::TEMPLATE_FORWARD, "{$desde} → {$hacia}");
        }
        $this->assertSame($desde, $this->db()->table(self::T_TEMPLATES)->where('id', $id)->value('status'));
    }

    public function test_una_plantilla_congela_su_contenido_lleva_el_numero_siguiente_y_conserva_su_forma(): void
    {
        $this->assertGuarded(fn () => $this->insertTemplate(['status' => 'APPROVED']), self::TEMPLATE_STARTS, 'nace aprobada');
        $this->assertGuarded(fn () => $this->insertTemplate(['reviewed_by_admin_id' => 2]), self::TEMPLATE_STARTS, 'nace revisada');
        $id = $this->insertTemplate(['key' => 'renovacion']);
        foreach (self::TEMPLATE_IMMUTABLE as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_TEMPLATES." SET {$columna} = {$columna} WHERE id = ?", [$id]), self::TEMPLATE_FROZEN, $columna);
        }
        $this->assertGuarded(fn () => $this->insertTemplate(['key' => 'renovacion', 'number' => 3]), self::TEMPLATE_NUMBER, 'saltando a la 3');
        $this->assertGuarded(fn () => $this->insertTemplate(['key' => 'renovacion', 'channel' => 'assisted']), self::TEMPLATE_SHAPE, 'la 2 por otro canal');
        $this->assertGuarded(fn () => $this->insertTemplate(['key' => 'renovacion', 'purpose' => 'service']), self::TEMPLATE_SHAPE, 'la 2 con otra finalidad');
        $this->assertAccepted(fn () => $this->insertTemplate(['key' => 'renovacion']), 'la 2');
        $this->assertGuarded(fn () => $this->db()->table(self::T_TEMPLATES)->where('id', $id)->delete(), self::P.'las plantillas se retiran, no se borran (DELETE en ultron_studio_message_templates)');
    }

    public function test_una_plantilla_la_revisa_otra_persona_vinculada_una_vez(): void
    {
        $p = $this->people();
        $this->people[5] = $this->insertIdentity();
        $this->assertGuarded(fn () => $this->insertTemplate(['created_by_admin_id' => 5, 'created_by_person_id' => $this->people[5]]), self::TEMPLATE_AUTHOR, 'una autora sin vínculo');
        $id = $this->insertTemplate();   // Ana
        $this->verifyBinding($this->insertBinding(6, $p[1]), 2, $p[2]);
        $this->people[6] = $p[1];
        $cuatro = self::P.'una plantilla la revisa otra persona y otra cuenta (cuatro ojos) (UPDATE en ultron_studio_message_templates)';
        $this->fourEyes(fn () => $this->reviewTemplate($id, 'APPROVED', 1), 'usmt_four_eyes_chk', $cuatro, 'Ana aprueba la suya');
        $this->fourEyes(fn () => $this->reviewTemplate($id, 'APPROVED', 6), 'usmt_four_eyes_chk', $cuatro, 'la otra cuenta de Ana');
        $this->assertGuarded(fn () => $this->reviewTemplate($id, 'APPROVED', 5), self::TEMPLATE_REVIEWER, 'una cuenta sin vínculo');
        $this->assertAccepted(fn () => $this->reviewTemplate($id, 'APPROVED', 2), 'la aprueba Beto');
        $this->assertGuarded(fn () => $this->db()->table(self::T_TEMPLATES)->where('id', $id)->update(['status' => 'RETIRED', 'reviewed_by_admin_id' => 3]), self::TEMPLATE_ONCE, 'retirarla cambiando quién la aprobó');
        $this->assertAccepted(fn () => $this->db()->table(self::T_TEMPLATES)->where('id', $id)->update(['status' => 'RETIRED', 'status_changed_at' => now()]), 'retirarla');
    }

    // ── La cadena ───────────────────────────────────────────────────────────

    public function test_la_cadena_admite_la_siguiente_encadenada_y_es_de_solo_insercion(): void
    {
        $uno = $this->insertPublication(1, null);
        $dos = $this->insertPublication(2, $uno, ['action' => 'pause', 'strategy_id' => (int) $this->db()->table(self::T_PUBLICATIONS)->where('id', $uno)->value('strategy_id'), 'version_id' => null]);
        $this->insertPublication(3, $dos, ['action' => 'resume', 'strategy_id' => (int) $this->db()->table(self::T_PUBLICATIONS)->where('id', $uno)->value('strategy_id'), 'version_id' => null]);

        $filas = $this->db()->table(self::T_PUBLICATIONS)->orderBy('seq')->get(['id', 'seq', 'previous_publication_id', 'prev_hash', 'effective_hash']);
        $this->assertSame([[1, null], [2, $uno], [3, $dos]], $filas->map(static fn (object $f): array => [(int) $f->seq, $f->previous_publication_id === null ? null : (int) $f->previous_publication_id])->all());
        $this->assertSame([self::GENESIS, $filas[0]->effective_hash, $filas[1]->effective_hash], $filas->pluck('prev_hash')->all());

        $this->assertGuarded(fn () => DB::statement('UPDATE '.self::T_PUBLICATIONS.' SET reason = reason WHERE id = ?', [$uno]), self::P.'la cadena de estrategias es de solo inserción (UPDATE en ultron_studio_strategy_publications)');
        $this->assertGuarded(fn () => $this->db()->table(self::T_PUBLICATIONS)->where('id', $uno)->delete(), self::P.'la cadena de estrategias es de solo inserción (DELETE en ultron_studio_strategy_publications)');
        if ($this->isPostgres()) {
            $this->assertGuarded(fn () => DB::statement('TRUNCATE '.self::T_PUBLICATIONS.' CASCADE'), self::P.'la cadena de estrategias es de solo inserción (TRUNCATE en ultron_studio_strategy_publications)');
        }
        $this->assertSame(3, $this->db()->table(self::T_PUBLICATIONS)->count());
    }

    /** @return array<string, array{0: int, 1: Closure(array<int, int>): array{0: int, 1: ?int, 2?: string}}> */
    public static function brokenEntries(): array
    {
        return [
            'cadena vacía: seq 2' => [0, static fn (array $ids): array => [2, null]],
            'cadena vacía: seq 1 apuntando a algo' => [0, static fn (array $ids): array => [1, 999999]],
            'cadena vacía: seq 1 sin los 64 ceros' => [0, static fn (array $ids): array => [1, null, hash('sha256', 'otra')]],
            'seq saltado (3 tras 1)' => [1, static fn (array $ids): array => [3, $ids[1]]],
            'la siguiente sin encadenar (NULL)' => [1, static fn (array $ids): array => [2, null]],
            'la siguiente con la huella de otra' => [1, static fn (array $ids): array => [2, $ids[1], hash('sha256', 'otra')]],
            'la siguiente con los 64 ceros' => [1, static fn (array $ids): array => [2, $ids[1], self::GENESIS]],
            'el seq envenenado' => [1, static fn (array $ids): array => [2147483647, $ids[1]]],
            'la siguiente apuntando a la anterior de la cabeza' => [2, static fn (array $ids): array => [3, $ids[1]]],
            'la siguiente con la huella de la anterior de la cabeza' => [2, static fn (array $ids): array => [3, $ids[2], 'de:1']],
        ];
    }

    /** @param  Closure(array<int, int>): array{0: int, 1: ?int, 2?: string}  $nueva */
    #[DataProvider('brokenEntries')]
    public function test_un_seq_saltado_o_una_entrada_sin_encadenar_la_para_el_disparador(int $sembradas, Closure $nueva): void
    {
        $ids = [];
        for ($seq = 1; $seq <= $sembradas; $seq++) {
            $ids[$seq] = $this->insertPublication($seq, $ids[$seq - 1] ?? null);
        }
        $caso = $nueva($ids);
        [$seq, $previa] = $caso;
        $o = [];
        if (isset($caso[2])) {
            $o['prev_hash'] = str_starts_with($caso[2], 'de:') ? (string) $this->db()->table(self::T_PUBLICATIONS)->where('id', $ids[(int) substr($caso[2], 3)])->value('effective_hash') : $caso[2];
        }

        $this->assertGuarded(fn () => $this->insertPublication($seq, $previa, $o), self::CHAIN);
        $this->assertSame($sembradas, $this->db()->table(self::T_PUBLICATIONS)->count());
        $this->insertPublication($sembradas + 1, $ids[$sembradas] ?? null);
    }

    public function test_seq_cero_o_negativo_y_seq_repetido(): void
    {
        $uno = $this->insertPublication(1, null);
        foreach ([0, -1] as $seq) {
            $this->isPostgres()
                ? $this->assertRejected(fn () => $this->insertPublication($seq, $uno), '23514', 'usspub_seq_chk', "seq {$seq}")
                : $this->assertRejected(fn () => $this->insertPublication($seq, $uno), '23000', self::SEQ_MIN, "seq {$seq}");
        }
        $dos = $this->insertPublication(2, $uno);
        // La carrera perdida es el UNIQUE de seq: el servicio la traduce a 409.
        foreach ([[2, $uno], [1, null], [1, $dos]] as [$seq, $previa]) {
            $this->assertUniqueRejects(fn () => $this->insertPublication($seq, $previa), 'usspub_seq_unique', self::T_PUBLICATIONS, ['seq'], "seq {$seq} repetido");
        }
        $this->assertSame(2, $this->db()->table(self::T_PUBLICATIONS)->count());
    }

    public function test_publica_y_promueve_otra_persona_vinculada_y_reanuda_una_vinculada(): void
    {
        $p = $this->people();
        [$s, $v] = $this->chainTarget();   // autora: Ana
        $this->verifyBinding($this->insertBinding(6, $p[1]), 2, $p[2]);   // la otra cuenta de Ana
        $sinVinculo = $this->insertIdentity();
        $entrada = fn (array $o): int => $this->appendPublication($o + ['strategy_id' => $s, 'version_id' => $v]);
        foreach (['publish', 'promote'] as $accion) {
            $this->assertGuarded(fn () => $entrada(['action' => $accion, 'actor_admin_id' => 1, 'actor_name' => 'Ana', 'acted_by_person_id' => $p[1]]), self::CHAIN_FOUR_EYES, "{$accion}: la autora");
            $this->assertGuarded(fn () => $entrada(['action' => $accion, 'actor_admin_id' => 6, 'actor_name' => 'Ana', 'acted_by_person_id' => $p[1]]), self::CHAIN_FOUR_EYES, "{$accion}: la otra cuenta de la autora");
            $this->assertGuarded(fn () => $entrada(['action' => $accion, 'actor_admin_id' => 5, 'actor_name' => 'Eva', 'acted_by_person_id' => $sinVinculo]), self::CHAIN_BOUND, "{$accion}: sin vínculo");
        }
        $this->assertGuarded(fn () => $entrada(['action' => 'resume', 'version_id' => null, 'actor_admin_id' => 5, 'acted_by_person_id' => $sinVinculo]), self::CHAIN_BOUND, 'reanudar sin vínculo');
        $this->assertGuarded(fn () => $entrada(['version_id' => $this->insertVersion($this->insertStrategy())]), self::CHAIN_VERSION, 'la versión de otra estrategia');
        $this->assertAccepted(fn () => $entrada([]), 'Beto publica');
        // Pausar y revertir son el sentido seguro: cualquiera con el permiso, sin vínculo (11.2).
        $this->assertAccepted(fn () => $entrada(['action' => 'pause', 'version_id' => null, 'actor_admin_id' => 5, 'actor_name' => 'Eva', 'acted_by_person_id' => null]), 'Eva pausa sin vínculo');
        $this->assertAccepted(fn () => $entrada(['action' => 'rollback', 'actor_admin_id' => 1, 'actor_name' => 'Ana', 'acted_by_person_id' => $p[1]]), 'la autora revierte');
    }

    public function test_solo_en_postgres_una_tabla_temporal_no_suplanta_la_cabeza_ni_los_vinculos(): void
    {
        $this->requirePostgres('pg_temp solo existe en PostgreSQL.');

        $uno = $this->insertPublication(1, null);
        $filaCabeza = $this->publicationRow(2147483647, $uno);
        $s = $this->insertStrategy();
        $sinVinculo = $this->insertIdentity();
        $filaVersion = $this->versionRow($s, ['created_by_admin_id' => 9, 'created_by_person_id' => $sinVinculo]);
        DB::statement('CREATE TEMP TABLE ultron_studio_strategy_publications (id bigint, seq bigint, effective_hash text) ON COMMIT DROP');
        DB::statement('INSERT INTO pg_temp.ultron_studio_strategy_publications (id, seq) VALUES (?, 2147483646)', [$uno]);
        DB::statement('CREATE TEMP TABLE ultron_studio_person_bindings (admin_id bigint, identity_id bigint, verified_at timestamp, revoked_at timestamp) ON COMMIT DROP');
        DB::statement('INSERT INTO pg_temp.ultron_studio_person_bindings VALUES (9, ?, now(), NULL)', [$sinVinculo]);

        $this->assertGuarded(fn () => DB::table('public.'.self::T_PUBLICATIONS)->insert($filaCabeza), self::CHAIN, 'cabeza falsa');
        $this->assertGuarded(fn () => DB::table('public.'.self::T_VERSIONS)->insert($filaVersion), self::VERSION_AUTHOR, 'vínculo falso');
        DB::statement('DROP TABLE pg_temp.ultron_studio_strategy_publications');
        DB::statement('DROP TABLE pg_temp.ultron_studio_person_bindings');
        $this->assertSame(1, (int) DB::table(self::T_PUBLICATIONS)->count());
    }

    // ── Los CHECK de forma (solo PG) ────────────────────────────────────────

    /** @return array<string, array{string, array<string, mixed>, list<string>}> */
    public static function badRows(): array
    {
        $hex = hash('sha256', 'x');

        return [
            // Vínculos (la base: uno `crm` sin verificar de la cuenta 4).
            'vínculo: origen desconocido' => [self::T_BINDINGS, ['origin' => 'api'], ['uspb_origin_chk', 'uspb_origin_shape_chk']],
            'vínculo: crm sin quién lo creó' => [self::T_BINDINGS, ['created_by_admin_id' => null], ['uspb_origin_shape_chk']],
            'vínculo: crm con autorización de consola' => [self::T_BINDINGS, ['bootstrap_authorization' => 'x'], ['uspb_origin_shape_chk']],
            'vínculo: consola sin autorización' => [self::T_BINDINGS, ['origin' => 'bootstrap', 'created_by_admin_id' => null, 'verified_at' => '2026-11-12 15:00:00'], ['uspb_origin_shape_chk']],
            'vínculo: consola con quién verificó' => [self::T_BINDINGS, ['origin' => 'bootstrap', 'created_by_admin_id' => null, 'bootstrap_authorization' => 'Orden',
                'verified_at' => '2026-11-12 15:00:00', 'verified_by_admin_id' => 2, 'verified_by_identity_id' => 999999, 'verified_by_name' => 'Beto'], ['uspb_origin_shape_chk', 'uspb_verification_chk']],
            'vínculo: verificado a medias' => [self::T_BINDINGS, ['verified_at' => '2026-11-12 15:00:00'], ['uspb_verification_chk']],
            'vínculo: revocado sin motivo' => [self::T_BINDINGS, ['revoked_at' => '2026-11-12 15:00:00', 'revoked_by_admin_id' => 2, 'revoked_by_name' => 'Beto'], ['uspb_revocation_chk']],
            'vínculo: revocado con motivo en blanco' => [self::T_BINDINGS, ['revoked_at' => '2026-11-12 15:00:00', 'revoked_by_admin_id' => 2, 'revoked_by_name' => 'Beto', 'revoke_reason' => '  '], ['uspb_revocation_chk']],
            'vínculo: lo crea la propia cuenta' => [self::T_BINDINGS, ['created_by_admin_id' => 4], ['uspb_four_eyes_chk']],
            // Estrategias.
            'estrategia: clave con mayúsculas' => [self::T_STRATEGIES, ['key' => 'Renovacion'], ['usst_key_chk']],
            'estrategia: clave corta' => [self::T_STRATEGIES, ['key' => 'ab'], ['usst_key_chk']],
            'estrategia: finalidad desconocida' => [self::T_STRATEGIES, ['purpose' => 'marketing'], ['usst_purpose_chk']],
            'estrategia: nombre en blanco' => [self::T_STRATEGIES, ['name' => '   '], ['usst_name_chk']],
            'estrategia: nombre con salto de línea' => [self::T_STRATEGIES, ['name' => "Renovación\n7 días"], ['usst_name_chk']],
            'estrategia: borrador que no es objeto' => [self::T_STRATEGIES, ['draft_json' => '[]'], ['usst_draft_chk']],
            'estrategia: borrador de más de 32 KB' => [self::T_STRATEGIES, ['draft_json' => '{"x":"'.str_repeat('a', 32768).'"}'], ['usst_draft_chk']],
            'estrategia: revisión 0' => [self::T_STRATEGIES, ['draft_revision' => 0], ['usst_draft_chk']],
            'estrategia: escrita sin quién' => [self::T_STRATEGIES, ['draft_updated_at' => '2026-11-12 15:00:00'], ['usst_draft_author_chk']],
            'estrategia: archivada sin quién' => [self::T_STRATEGIES, ['archived_at' => '2026-11-12 15:00:00'], ['usst_archive_chk']],
            // Plantillas (la base: una de WhatsApp comercial `marketing`).
            'plantilla: canal desconocido' => [self::T_TEMPLATES, ['channel' => 'sms'], ['usmt_category_chk', 'usmt_channel_chk']],
            'plantilla: cobranza de marketing' => [self::T_TEMPLATES, ['purpose' => 'collections'], ['usmt_category_chk']],
            'plantilla: categoría que no es la del contenido' => [self::T_TEMPLATES, ['category' => 'utility', 'purpose' => 'service'], ['usmt_whatsapp_content_chk']],
            'plantilla: WhatsApp sin categoría' => [self::T_TEMPLATES, ['category' => null], ['usmt_category_chk', 'usmt_whatsapp_content_chk']],
            'plantilla: WhatsApp sin línea de baja' => [self::T_TEMPLATES, ['content' => ['category' => 'marketing', 'metaName' => 'x', 'optOutLine' => false]], ['usmt_whatsapp_content_chk']],
            'plantilla: asistido con categoría' => [self::T_TEMPLATES, ['channel' => 'assisted', 'content' => ['medium' => 'voice', 'script' => 'Hola'], 'category' => 'utility'], ['usmt_category_chk']],
            'plantilla: asistido por SMS' => [self::T_TEMPLATES, ['channel' => 'assisted', 'content' => ['medium' => 'sms', 'script' => 'Hola'], 'category' => null], ['usmt_assisted_content_chk']],
            'plantilla: guion de 601' => [self::T_TEMPLATES, ['channel' => 'assisted', 'content' => ['medium' => 'voice', 'script' => str_repeat('a', 601)], 'category' => null], ['usmt_assisted_content_chk']],
            'plantilla: guion vacío' => [self::T_TEMPLATES, ['channel' => 'assisted', 'content' => ['medium' => 'voice'], 'category' => null], ['usmt_assisted_content_chk']],
            'plantilla: la huella no es la de los bytes' => [self::T_TEMPLATES, ['content_hash' => $hex], ['usmt_hash_matches_chk']],
            'plantilla: contenido que no es objeto' => [self::T_TEMPLATES, ['channel' => 'voice', 'category' => null, 'content' => ['renewal']], ['usmt_size_chk']],
            'plantilla: estado desconocido' => [self::T_TEMPLATES, ['status' => 'LIVE'], ['usmt_review_chk', 'usmt_status_chk']],
            'plantilla: rechazada sin nota' => [self::T_TEMPLATES, ['status' => 'REJECTED', 'reviewed_by_admin_id' => 2, 'reviewed_by_person_id' => 999999, 'reviewed_by_name' => 'Beto',
                'reviewed_at' => '2026-11-12 15:00:00'], ['usmt_review_chk']],
            'plantilla: clave con espacios' => [self::T_TEMPLATES, ['key' => 'plantilla 1'], ['usmt_key_chk']],
            // Versiones (la base: una VALIDATED de Ana).
            'versión: estado VALIDATING' => [self::T_VERSIONS, ['status' => 'VALIDATING'], ['ussv_approval_chk', 'ussv_status_chk']],
            'versión: aprobada sin quién' => [self::T_VERSIONS, ['status' => 'APPROVED'], ['ussv_approval_chk']],
            'versión: validada con aprobación' => [self::T_VERSIONS, ['approved_by_admin_id' => 2, 'approved_by_person_id' => 999999, 'approved_by_name' => 'Beto', 'approved_at' => '2026-11-12 15:00:00'], ['ussv_approval_chk']],
            'versión: referencia de versión sin uuid' => [self::T_VERSIONS, ['studio_ref_kind' => 'version'], ['ussv_studio_ref_chk']],
            'versión: línea base con uuid' => [self::T_VERSIONS, ['studio_version_uuid' => '00000000-0000-4000-8000-000000000001'], ['ussv_studio_ref_chk']],
            'versión: tipo de referencia desconocido' => [self::T_VERSIONS, ['studio_ref_kind' => 'legacy'], ['ussv_studio_ref_chk', 'ussv_studio_ref_kind_chk']],
            'versión: catálogo ajeno' => [self::T_VERSIONS, ['catalog_version' => '2026-10-19.1'], ['ussv_versions_chk']],
            'versión: evaluador con espacios' => [self::T_VERSIONS, ['evaluator_version' => 'evaluador 1'], ['ussv_versions_chk']],
            'versión: contenido de más de 32 KB' => [self::T_VERSIONS, ['content_json' => '{"x":"'.str_repeat('a', 32768).'"}', 'content_hash' => hash('sha256', '{"x":"'.str_repeat('a', 32768).'"}')], ['ussv_size_chk']],
            'versión: contenido que no es objeto' => [self::T_VERSIONS, ['content_json' => '["renew"]', 'content_hash' => hash('sha256', '["renew"]')], ['ussv_size_chk']],
            'versión: validación que no es objeto' => [self::T_VERSIONS, ['validation_json' => '[]'], ['ussv_size_chk']],
            'versión: número 0' => [self::T_VERSIONS, ['number' => 0], ['ussv_number_chk']],
            'versión: huella de la referencia corta' => [self::T_VERSIONS, ['studio_content_hash' => substr($hex, 1)], ['ussv_hashes_chk']],
            'versión: la aprueba la autora' => [self::T_VERSIONS, ['status' => 'APPROVED', 'approved_by_admin_id' => 1, 'approved_by_person_id' => 99, 'approved_by_name' => 'Ana',
                'approved_at' => '2026-11-12 15:00:00'], ['ussv_four_eyes_chk']],
            // Simulaciones.
            'simulación: tipo desconocido' => [self::T_SIMULATIONS, ['kind' => 'replay'], ['usss_kind_chk', 'usss_size_chk']],
            'simulación: 51 sintéticas' => [self::T_SIMULATIONS, ['sample_size' => 51], ['usss_size_chk']],
            'simulación: muestra de 201' => [self::T_SIMULATIONS, ['kind' => 'sample', 'sample_size' => 201], ['usss_size_chk']],
            'simulación: resultado que no es objeto' => [self::T_SIMULATIONS, ['result_json' => '[]'], ['usss_result_chk']],
            'simulación: resultado de más de 16 KB' => [self::T_SIMULATIONS, ['result_json' => '{"x":"'.str_repeat('a', 16384).'"}'], ['usss_result_chk']],
            'simulación: huella de la entrada corta' => [self::T_SIMULATIONS, ['input_hash' => 'abc'], ['usss_hashes_chk']],
            // Revisiones.
            'revisión: decisión desconocida' => [self::T_REVIEWS, ['decision' => 'maybe'], ['ussr_decision_chk', 'ussr_note_chk']],
            'revisión: avisos que no son lista' => [self::T_REVIEWS, ['acknowledged_warnings' => '{"w":1}'], ['ussr_warnings_chk']],
            // La cadena (la base: un publish de Beto).
            'cadena: acción desconocida' => [self::T_PUBLICATIONS, ['action' => 'legacy'], ['usspub_action_chk', 'usspub_shape_chk']],
            'cadena: seq 0' => [self::T_PUBLICATIONS, ['seq' => 0], ['usspub_seq_chk']],
            'cadena: firma corta' => [self::T_PUBLICATIONS, ['signature' => substr($hex, 2)], ['usspub_hashes_chk']],
            'cadena: prev_hash en mayúsculas' => [self::T_PUBLICATIONS, ['prev_hash' => strtoupper($hex)], ['usspub_hashes_chk']],
            'cadena: la huella no es la de los bytes' => [self::T_PUBLICATIONS, ['effective_hash' => $hex], ['usspub_hash_matches_chk']],
            'cadena: los bytes cambiados' => [self::T_PUBLICATIONS, ['effective_json' => '{"seq":1}'], ['usspub_hash_matches_chk']],
            'cadena: 16 385 caracteres' => [self::T_PUBLICATIONS, ['effective_json' => str_repeat('a', 16385), 'effective_hash' => hash('sha256', str_repeat('a', 16385))], ['usspub_size_chk']],
            'cadena: pausa sin motivo' => [self::T_PUBLICATIONS, ['action' => 'pause', 'version_id' => null, 'simulation_gate' => null], ['usspub_reason_chk']],
            'cadena: reversión sin motivo' => [self::T_PUBLICATIONS, ['action' => 'rollback', 'simulation_gate' => null], ['usspub_reason_chk']],
            'cadena: publish sin versión' => [self::T_PUBLICATIONS, ['version_id' => null], ['usspub_shape_chk']],
            'cadena: pausa con versión' => [self::T_PUBLICATIONS, ['action' => 'pause', 'simulation_gate' => null, 'reason' => 'x'], ['usspub_shape_chk']],
            'cadena: promote sin evidencia' => [self::T_PUBLICATIONS, ['action' => 'promote'], ['usspub_shape_chk']],
            'cadena: publish con evidencia' => [self::T_PUBLICATIONS, ['evidence' => '{"hours":24}'], ['usspub_shape_chk']],
            'cadena: publish sin la puerta de la simulación' => [self::T_PUBLICATIONS, ['simulation_gate' => null], ['usspub_shape_chk']],
            'cadena: puerta desconocida' => [self::T_PUBLICATIONS, ['simulation_gate' => 'skipped'], ['usspub_simulation_gate_chk']],
            'cadena: publish sin persona' => [self::T_PUBLICATIONS, ['acted_by_person_id' => null], ['usspub_person_chk']],
            'cadena: evidencia que no es objeto' => [self::T_PUBLICATIONS, ['action' => 'promote', 'evidence' => '[24]'], ['usspub_evidence_chk']],
        ];
    }

    /** @param  array<string, mixed>  $cambio  @param  list<string>  $esperados */
    #[DataProvider('badRows')]
    public function test_solo_en_postgres_cada_check_de_forma(string $tabla, array $cambio, array $esperados): void
    {
        $this->requirePostgres('los CHECK solo existen en PostgreSQL (en SQLite los vigila el servicio, salvo los cuatro ojos y seq >= 1).');

        $base = $this->validRow($tabla);
        if ($tabla === self::T_TEMPLATES && array_key_exists('content', $cambio)) {
            $json = (string) json_encode($cambio['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $cambio += ['content_json' => $json, 'content_hash' => hash('sha256', $json)];
            unset($cambio['content']);
        }
        $this->assertCheckShape($tabla, $base, $cambio, $esperados);
    }

    // ── El rol de la aplicación (solo PG) ───────────────────────────────────

    public function test_el_rol_de_la_aplicacion_es_iron_app_salvo_que_el_entorno_diga_otro(): void
    {
        $m = $this->migrationNamed(self::GOVERNANCE_MIGRATION);
        putenv('ULTRON_STUDIO_DB_APP_ROLE');
        $this->assertSame('iron_app', $m::appRole());
        putenv('ULTRON_STUDIO_DB_APP_ROLE=h9_app');
        $this->assertSame('h9_app', $m::appRole());
        foreach (['Iron_App', 'iron-app', 'iron_app"; DROP TABLE x; --', '1iron', str_repeat('a', 64)] as $malo) {
            putenv('ULTRON_STUDIO_DB_APP_ROLE='.$malo);
            try {
                $m::appRole();
                $this->fail("Aceptó un nombre de rol inválido: {$malo}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no es un nombre de rol válido', $e->getMessage());
            }
        }
    }

    public function test_solo_en_postgres_la_aplicacion_pierde_update_y_delete_donde_toca(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');

        $this->withAppRole(function (): void {
            $this->migrationNamed(self::EXECUTION_MIGRATION)->down();
            $this->migrationNamed(self::GOVERNANCE_MIGRATION)->down();
            $this->migrationNamed(self::GOVERNANCE_MIGRATION)->up();
            $this->migrationNamed(self::EXECUTION_MIGRATION)->up();

            foreach ([self::T_SIMULATIONS, self::T_REVIEWS, self::T_PUBLICATIONS] as $t) {
                $this->assertSame([true, true, false, false, false], $this->privileges($t), "{$t}: solo inserción");
            }
            foreach ([self::T_BINDINGS, self::T_STRATEGIES, self::T_TEMPLATES, self::T_VERSIONS] as $t) {
                $this->assertSame([true, true, true, false, false], $this->privileges($t), "{$t}: conserva UPDATE (su máquina de estados), sin DELETE");
            }

            // Como la aplicación: todo el ciclo pasa.
            $this->people = [];
            $this->assertNull($this->asApp(function (): void {
                $live = $this->liveVersion();
                $this->approvedTemplate();
                $v2 = $this->insertVersion($live['strategy']);
                $this->db()->table(self::T_REVIEWS)->insert($this->reviewRow($v2));
                $sim = $this->insertSimulation($live['strategy'], $v2);
                $this->approveVersion($v2, 2, ['simulation_uuid' => $this->db()->table(self::T_SIMULATIONS)->where('id', $sim)->value('uuid'),
                    'simulation_hash' => $this->db()->table(self::T_SIMULATIONS)->where('id', $sim)->value('result_hash')]);
                $this->appendPublication(['action' => 'pause', 'strategy_id' => $live['strategy'], 'version_id' => null, 'actor_admin_id' => 1, 'acted_by_person_id' => null]);
                $this->assertSame([3, 1, 1, 2, 1, 1, 2], array_map(fn (string $t): int => $this->db()->table($t)->count(), self::GOVERNANCE_TABLES));
            })[0], 'el rol de la aplicación ya no inserta');

            foreach ([
                'UPDATE '.self::T_PUBLICATIONS.' SET reason = reason WHERE id = -1',
                'DELETE FROM '.self::T_PUBLICATIONS.' WHERE id = -1',
                'SELECT id FROM '.self::T_PUBLICATIONS.' WHERE id = -1 FOR UPDATE',
                'UPDATE '.self::T_REVIEWS.' SET note = note WHERE id = -1',
                'DELETE FROM '.self::T_SIMULATIONS.' WHERE id = -1',
                'DELETE FROM '.self::T_VERSIONS.' WHERE id = -1',
                'DELETE FROM '.self::T_BINDINGS.' WHERE id = -1',
                'DELETE FROM '.self::T_STRATEGIES.' WHERE id = -1',
                'DELETE FROM '.self::T_TEMPLATES.' WHERE id = -1',
                'TRUNCATE '.self::T_VERSIONS,
                'ALTER TABLE '.self::T_PUBLICATIONS.' DISABLE TRIGGER usspub_chain_next',
                'DROP TRIGGER ussv_status_forward ON '.self::T_VERSIONS,
                'ALTER TABLE '.self::T_VERSIONS.' DROP CONSTRAINT ussv_four_eyes_chk',
                'DROP INDEX uspb_one_active',
                'CREATE OR REPLACE FUNCTION ultron_studio_chk_ussv_approver_is_bound() RETURNS trigger LANGUAGE plpgsql AS $fn$ BEGIN RETURN NEW; END; $fn$',
            ] as $sql) {
                [$sqlstate, $mensaje] = $this->asApp(fn () => DB::statement($sql));
                $this->assertSame('42501', $sqlstate, "{$sql}: {$mensaje}");
            }
        });
    }

    public function test_solo_en_postgres_up_comprueba_que_el_rol_ya_no_puede_y_si_no_falla_sin_dejar_nada(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');

        $this->withAppRole(function (): void {
            $this->migrationNamed(self::EXECUTION_MIGRATION)->down();
            $this->migrationNamed(self::GOVERNANCE_MIGRATION)->down();
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT DELETE ON TABLES TO PUBLIC');
            try {
                $this->migrationNamed(self::GOVERNANCE_MIGRATION)->up();
                $this->fail('up() dio por buena una aplicación que todavía puede borrar el gobierno de las estrategias');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('todavía puede DELETE en ultron_studio_person_bindings', $e->getMessage());
            }
            foreach (self::GOVERNANCE_TABLES as $tabla) {
                $this->assertFalse(Schema::hasTable($tabla), "up() dejó {$tabla}");
            }
            $this->assertSame(['draft', 'version', 'rule', 'system', 'campaign_link', 'canary_number', 'integration'], $this->checkValues('usae_subject_type_chk'), 'up() dejó el libro a medias');
        });
    }

    public function test_solo_en_postgres_sin_el_rol_no_hay_nada_que_revocar_salvo_en_produccion(): void
    {
        $this->requirePostgres('los roles solo existen en PostgreSQL.');

        $gobierno = $this->migrationNamed(self::GOVERNANCE_MIGRATION);
        $ejecucion = $this->migrationNamed(self::EXECUTION_MIGRATION);
        $nadie = 'ultron_studio_h9_rol_inexistente';
        putenv('ULTRON_STUDIO_DB_APP_ROLE='.$nadie);
        DB::beginTransaction();
        try {
            $ejecucion->down();
            $gobierno->down();
            $gobierno->up();
            $this->assertContains('usspub_chain_next', $this->triggersOn(self::T_PUBLICATIONS));
            $gobierno->down();
            $this->app['env'] = 'production';
            try {
                $gobierno->up();
                $this->fail('En producción, up() se calló un REVOKE que no podía aplicar');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString("el rol de la aplicación «{$nadie}» no existe en este clúster y APP_ENV es production", $e->getMessage());
            } finally {
                $this->app['env'] = 'testing';
            }
            $this->assertFalse(Schema::hasTable(self::T_PUBLICATIONS), 'up() dejó la mitad');
        } finally {
            DB::rollBack();
        }
        $this->assertContains('usspub_chain_next', $this->triggersOn(self::T_PUBLICATIONS));
    }

    public function test_solo_en_postgres_sin_lock_timeout_en_la_sesion_cada_migracion_pone_cinco_segundos_y_si_lo_hay_lo_respeta(): void
    {
        $this->requirePostgres('lock_timeout solo existe en PostgreSQL.');

        $gobierno = $this->migrationNamed(self::GOVERNANCE_MIGRATION);
        $ejecucion = $this->migrationNamed(self::EXECUTION_MIGRATION);
        $sentencias = [];
        DB::listen(function ($q) use (&$sentencias): void {
            $sentencias[] = $q->sql;
        });
        foreach (['0' => "SET LOCAL lock_timeout = '5s'", '2s' => null] as $previo => $esperada) {
            DB::beginTransaction();
            try {
                DB::statement("SET LOCAL lock_timeout = '{$previo}'");
                $ejecucion->down();
                $gobierno->down();
                // Cada una POR SEPARADO (con el migrador, cada una va en su transacción): si una dejara de acotar su
                // espera, la otra no lo taparía. Antes de cada una, el valor de partida (SET LOCAL dura hasta el final).
                foreach (['gobierno' => $gobierno, 'ejecución' => $ejecucion] as $cual => $m) {
                    DB::statement("SET LOCAL lock_timeout = '{$previo}'");
                    $sentencias = [];
                    $m->up();
                    $puestas = array_values(array_filter($sentencias, static fn (string $s): bool => str_starts_with($s, 'SET LOCAL lock_timeout')));
                    $this->assertSame($esperada === null ? [] : [$esperada], $puestas, "{$cual}, con lock_timeout = {$previo}");
                    $this->assertSame((string) $previo === '0' ? '5s' : '2s', DB::scalar("SELECT current_setting('lock_timeout')"), "{$cual}, después, con lock_timeout = {$previo}");
                }
            } finally {
                DB::rollBack();
            }
        }
    }

    // ── down() ──────────────────────────────────────────────────────────────

    public function test_down_se_niega_con_una_fila_en_cualquiera_de_las_siete_tablas_o_un_evento_del_libro(): void
    {
        $ejecucion = $this->migrationNamed(self::EXECUTION_MIGRATION);
        $gobierno = $this->migrationNamed(self::GOVERNANCE_MIGRATION);
        $antes = $this->footprint(self::GOVERNANCE_TABLES, $gobierno::ownFunctions());
        foreach ([
            'un vínculo' => ['(1 vínculos de personas, 0 estrategias', fn () => $this->insertBinding(1, $this->insertIdentity(), $this->bootstrapOverrides())],
            'una estrategia' => [', 1 estrategias, 0 plantillas', fn () => $this->insertStrategy()],
            'una plantilla' => ['1 plantillas, 0 versiones', fn () => $this->insertTemplate()],
            'una versión' => ['1 versiones, 0 simulaciones', fn () => $this->insertVersion($this->insertStrategy())],
            'una simulación' => ['1 simulaciones, 0 revisiones', fn () => $this->insertSimulation($this->insertStrategy(), null)],
            'una revisión' => ['1 revisiones, 0 entradas', fn () => $this->db()->table(self::T_REVIEWS)->insert($this->reviewRow($this->insertVersion($this->insertStrategy())))],
            'una entrada de la cadena' => ['1 entradas de la cadena y 0 eventos', fn () => $this->insertPublication(1, null)],
            'un evento del libro' => ['0 entradas de la cadena y 1 eventos del libro', fn () => DB::table('ultron_studio_audit_events')->insert([
                'event' => 'strategy.created', 'subject_type' => 'strategy', 'actor_type' => 'admin', 'actor_admin_id' => 1, 'actor_name' => 'Ana', 'summary' => 'x', 'created_at' => now()])],
        ] as $que => [$recuento, $siembra]) {
            DB::beginTransaction();
            try {
                $this->people = [];
                $ejecucion->down();
                $siembra();
                try {
                    $gobierno->down();
                    $this->fail("down() no se negó con {$que}");
                } catch (RuntimeException $e) {
                    $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                    $this->assertStringContainsString('ultron_studio (Hito 9): down() se niega a revertir el gobierno de las estrategias con datos', $e->getMessage(), $que);
                    $this->assertStringContainsString($recuento, $e->getMessage(), $que);
                }
                $this->assertSame($antes, $this->footprint(self::GOVERNANCE_TABLES, $gobierno::ownFunctions()), "down() tocó el esquema con {$que}");
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_down_sin_filas_y_up_dejan_el_mismo_esquema_y_el_libro_vuelve_a_siete_sujetos(): void
    {
        $ejecucion = $this->migrationNamed(self::EXECUTION_MIGRATION);
        $gobierno = $this->migrationNamed(self::GOVERNANCE_MIGRATION);
        $h9 = $this->footprint(self::GOVERNANCE_TABLES, $gobierno::ownFunctions());
        $libro = $this->footprint(['ultron_studio_audit_events', 'identities']);

        DB::beginTransaction();
        try {
            $ejecucion->down();
            $gobierno->down();
            foreach (self::GOVERNANCE_TABLES as $tabla) {
                $this->assertFalse(Schema::hasTable($tabla), $tabla);
            }
            if ($this->isPostgres()) {
                $this->assertSame(['draft', 'version', 'rule', 'system', 'campaign_link', 'canary_number', 'integration'], $this->checkValues('usae_subject_type_chk'));
                $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM pg_proc WHERE proname = ANY(?::text[])', ['{'.implode(',', $gobierno::ownFunctions()).'}']));
                $this->assertSame(self::FORBID_MD5, DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
            }
            $gobierno->up();
            $ejecucion->up();
            $this->assertSame($h9, $this->footprint(self::GOVERNANCE_TABLES, $gobierno::ownFunctions()), 'up() no dejó lo mismo que la primera vez');
            $this->assertSame($libro, $this->footprint(['ultron_studio_audit_events', 'identities']), 'el libro o las identidades cambiaron');
            $this->assertGuarded(fn () => $this->insertPublication(2, null), self::CHAIN, 'las guardas vuelven');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($h9, $this->footprint(self::GOVERNANCE_TABLES, $gobierno::ownFunctions()));
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────

    private const BINDING_IMMUTABLE = ['id', 'uuid', 'client_request_id', 'origin', 'admin_id', 'identity_id', 'bootstrap_authorization', 'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at'];

    private const STRATEGY_IMMUTABLE = ['id', 'uuid', 'client_request_id', 'key', 'purpose', 'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at'];

    private const VERSION_IMMUTABLE = ['id', 'uuid', 'client_request_id', 'strategy_id', 'number', 'content_json', 'content_hash', 'catalog_version', 'evaluator_version',
        'studio_ref_kind', 'studio_version_uuid', 'studio_content_hash', 'validation_json', 'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_by_person_id', 'created_at'];

    private const TEMPLATE_IMMUTABLE = ['id', 'uuid', 'client_request_id', 'key', 'number', 'channel', 'purpose', 'category', 'content_json', 'content_hash',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_by_person_id', 'created_at'];

    /** Una versión nueva (de `$strategy` o de una estrategia nueva) llevada a `$status` por el ciclo. */
    private function versionIn(string $status, ?int $strategy = null): int
    {
        $s = $strategy ?? $this->insertStrategy();
        if ($status === 'REVIEW_REQUIRED') {
            return $this->insertVersion($s, ['status' => 'REVIEW_REQUIRED']);
        }
        $id = $this->insertVersion($s);
        $camino = match ($status) {
            'VALIDATED' => [],
            'REJECTED' => ['REJECTED'],
            'APPROVED' => ['APPROVED'],
            'CANARY' => ['APPROVED', 'CANARY'],
            'PUBLISHED' => ['APPROVED', 'CANARY', 'PUBLISHED'],
            'RETIRED' => ['APPROVED', 'CANARY', 'PUBLISHED', 'RETIRED'],
            'ROLLED_BACK' => ['APPROVED', 'CANARY', 'ROLLED_BACK'],
        };
        foreach ($camino as $paso) {
            $paso === 'APPROVED' ? $this->approveVersion($id) : $this->setVersionStatus($id, $paso);
        }

        return $id;
    }

    /** Cuatro ojos: en PG lo para su CHECK (23514); en SQLite, su disparador equivalente. */
    private function fourEyes(Closure $sentencia, string $check, string $mensajeSqlite, string $que): void
    {
        $this->isPostgres()
            ? $this->assertRejected($sentencia, '23514', $check, $que)
            : $this->assertRejected($sentencia, '23000', $mensajeSqlite, $que);
    }

    /** @param  list<string>  $esperados  @param  list<string>  $reales */
    private function assertSameSet(array $esperados, array $reales, string $que = ''): void
    {
        sort($esperados, SORT_STRING);
        sort($reales, SORT_STRING);
        $this->assertSame($esperados, $reales, $que);
    }

    /** @return list<string> los valores que admite un CHECK de dominio `col IN (…)` (PG) */
    private function checkValues(string $check): array
    {
        $def = (string) DB::scalar('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', [$check]);
        preg_match_all("/'([^']+)'::/", $def, $m);

        return $m[1];
    }

    /** @return array<string, mixed> la fila válida de cada tabla, sin insertarla */
    private function validRow(string $tabla): array
    {
        DB::beginTransaction();
        try {
            $id = match ($tabla) {
                self::T_BINDINGS => $this->insertBinding(4, $this->insertIdentity()),
                self::T_STRATEGIES => $this->insertStrategy(),
                self::T_TEMPLATES => $this->insertTemplate(),
                self::T_VERSIONS => $this->insertVersion($this->insertStrategy()),
                self::T_SIMULATIONS => $this->insertSimulation($this->insertStrategy(), null),
                self::T_REVIEWS => (int) DB::table(self::T_REVIEWS)->insertGetId($this->reviewRow($this->insertVersion($this->insertStrategy()))),
                self::T_PUBLICATIONS => $this->insertPublication(1, null),
            };
            $fila = (array) DB::table($tabla)->where('id', $id)->first();
        } finally {
            DB::rollBack();
            $this->people = [];
        }
        unset($fila['id']);

        return $fila;
    }
}
