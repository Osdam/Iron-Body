<?php

namespace Tests\Feature\UltronStudio;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * EL GOBIERNO DE LA VOZ EN LA BASE (Hito 8, paquete DB8; migración
 * 2026_11_10_100001): perfiles, agentes, la CADENA DE VOZ y las sesiones del
 * laboratorio hablado, en los dos motores (la suite en SQLite y la puerta
 * `docs/ultron-studio/ops/pgunit.sh` en PostgreSQL), con SQL a pelo. Lo
 * esperado sale del contrato (D8-6, D8-7, D8-8, D8-19, D8-23 y «Contratos de
 * datos») y está escrito aquí, no tomado de las constantes de la migración.
 *
 *  · Perfil: nace `draft` con el contenido congelado y solo avanza (draft →
 *    submitted → approved | rejected); lo revisa OTRA identidad.
 *  · Agente: nace `pending` y solo avanza a deployed (la proyección observada
 *    = la compilada), rejected (no cuadra) o failed; su id del proveedor y su
 *    observación, una vez; a lo sumo uno atiende cada parte.
 *  · La cadena (E1-4: una parte y un agente por entrada): solo la siguiente
 *    a la cabeza, encadenada por id y por huella (`prev_hash`, 64 ceros en la
 *    primera); `seq >= 1`; un `seq` repetido sigue siendo el UNIQUE (la
 *    carrera perdida → 409); en PG la huella es la de los bytes guardados; una
 *    entrada solo nombra un agente de PUBLICACIÓN DESPLEGADO; solo inserción
 *    y, en PG, sin UPDATE ni DELETE para la aplicación.
 *  · Sesión del laboratorio: nace `open`; el veredicto lo da otra identidad,
 *    sobre una sesión terminada, y es definitivo.
 *  · Recuentos del Studio (para OPS8): PG 44 → 68 disparadores y 50 → 84
 *    CHECK; SQLite 32 → 55; índices parciales 4 → 6. Si un hito posterior
 *    añade guardas, se actualizan a conciencia.
 *  · down() se niega con datos y la ida y vuelta deja el mismo esquema.
 *
 * En PostgreSQL, cada sentencia que tiene que fallar va en un SAVEPOINT.
 */
class UltronStudioVoiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_10_100001_create_ultron_studio_voice_tables';

    private const PROFILES = 'ultron_studio_voice_profiles';

    private const AGENTS = 'ultron_studio_voice_agents';

    private const PUBLICATIONS = 'ultron_studio_voice_publications';

    private const SESSIONS = 'ultron_studio_voice_lab_sessions';

    private const TABLES = [self::PROFILES, self::AGENTS, self::PUBLICATIONS, self::SESSIONS];

    private const PREFIX = 'ultron_studio: ';

    private const STUDIO_VOICE_PROFILES_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'revision' => ['integer', 'integer', false],
        'based_on_profile_id' => ['bigint', 'integer', true],
        'label' => ['character varying(80)', 'varchar', false],
        'language' => ['character varying(8)', 'varchar', false],
        'max_duration_seconds' => ['smallint', 'integer', false],
        'retention_days' => ['smallint', 'integer', false],
        'record_calls' => ['boolean', 'tinyint(1)', false],
        'record_voice' => ['boolean', 'tinyint(1)', false],
        'transfer_count' => ['smallint', 'integer', false],
        'transfer_destinations_encrypted' => ['text', 'text', true],
        'content' => ['json', 'text', false],
        'content_hash' => ['character(64)', 'varchar', false],
        'status' => ['character varying(10)', 'varchar', false],
        'created_by_admin_id' => ['bigint', 'integer', false],
        'created_by_name' => ['character varying(120)', 'varchar', false],
        'created_by_role' => ['character varying(60)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'submitted_by_admin_id' => ['bigint', 'integer', true],
        'submitted_by_name' => ['character varying(120)', 'varchar', true],
        'submitted_by_role' => ['character varying(60)', 'varchar', true],
        'submitted_at' => ['timestamp(0) without time zone', 'datetime', true],
        'reviewed_by_admin_id' => ['bigint', 'integer', true],
        'reviewed_by_name' => ['character varying(120)', 'varchar', true],
        'reviewed_by_role' => ['character varying(60)', 'varchar', true],
        'reviewed_at' => ['timestamp(0) without time zone', 'datetime', true],
        'review_note' => ['character varying(500)', 'varchar', true],
        'status_changed_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const STUDIO_VOICE_AGENTS_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', true],
        'purpose' => ['character varying(12)', 'varchar', false],
        'studio_version_id' => ['bigint', 'integer', false],
        'studio_content_hash' => ['character(64)', 'varchar', false],
        'voice_profile_id' => ['bigint', 'integer', false],
        'voice_profile_hash' => ['character(64)', 'varchar', false],
        'compiler_version' => ['character varying(48)', 'varchar', false],
        'spec_hash' => ['character(64)', 'varchar', false],
        'payload_hash' => ['character(64)', 'varchar', false],
        'projection_hash' => ['character(64)', 'varchar', false],
        // E5-1 (S8-c): los destinos compilados, alias y huella `voice-phone`.
        'transfer_destination_hashes' => ['json', 'text', true],
        'provider' => ['character varying(16)', 'varchar', false],
        'provider_agent_id' => ['character varying(128)', 'varchar', true],
        'observed_hash' => ['character(64)', 'varchar', true],
        'observed_at' => ['timestamp(0) without time zone', 'datetime', true],
        'status' => ['character varying(10)', 'varchar', false],
        'failure_code' => ['character varying(48)', 'varchar', true],
        'serves_canary' => ['boolean', 'tinyint(1)', false],
        'serves_rest' => ['boolean', 'tinyint(1)', false],
        'created_by_admin_id' => ['bigint', 'integer', false],
        'created_by_name' => ['character varying(120)', 'varchar', false],
        'created_by_role' => ['character varying(60)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'status_changed_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const STUDIO_VOICE_PUBLICATIONS_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'seq' => ['integer', 'integer', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'action' => ['character varying(10)', 'varchar', false],
        'scope' => ['character varying(8)', 'varchar', false],
        'previous_publication_id' => ['bigint', 'integer', true],
        'prev_hash' => ['character(64)', 'varchar', false],
        'restores_publication_id' => ['bigint', 'integer', true],
        'agent_id' => ['bigint', 'integer', true],
        'effective_json' => ['text', 'text', false],
        'effective_hash' => ['character(64)', 'varchar', false],
        'signature' => ['character(64)', 'varchar', false],
        'lab_gate' => ['json', 'text', false],
        'evidence' => ['json', 'text', true],
        'reason' => ['character varying(500)', 'varchar', true],
        'actor_admin_id' => ['bigint', 'integer', false],
        'actor_name' => ['character varying(120)', 'varchar', false],
        'actor_role' => ['character varying(60)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    private const STUDIO_VOICE_LAB_SESSIONS_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'agent_id' => ['bigint', 'integer', false],
        'status' => ['character varying(10)', 'varchar', false],
        'budget_day' => ['date', 'date', false],
        'reserved_micros' => ['bigint', 'integer', false],
        'cost_micros' => ['bigint', 'integer', true],
        'max_seconds' => ['smallint', 'integer', false],
        'duration_seconds' => ['smallint', 'integer', true],
        'url_expires_at' => ['timestamp(0) without time zone', 'datetime', true],
        'started_at' => ['timestamp(0) without time zone', 'datetime', true],
        'ended_at' => ['timestamp(0) without time zone', 'datetime', true],
        'end_reason' => ['character varying(32)', 'varchar', true],
        'guard_codes' => ['json', 'text', true],
        'verdict' => ['character varying(10)', 'varchar', true],
        'verdict_note' => ['character varying(500)', 'varchar', true],
        'verdict_by_admin_id' => ['bigint', 'integer', true],
        'verdict_by_name' => ['character varying(120)', 'varchar', true],
        'verdict_by_role' => ['character varying(60)', 'varchar', true],
        'verdict_at' => ['timestamp(0) without time zone', 'datetime', true],
        'created_by_admin_id' => ['bigint', 'integer', false],
        'created_by_name' => ['character varying(120)', 'varchar', false],
        'created_by_role' => ['character varying(60)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'status_changed_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const PG_TRIGGERS = [
        self::PROFILES => ['usvp_forbid_delete', 'usvp_forbid_immutable_update', 'usvp_forbid_truncate', 'usvp_reviewed_is_final', 'usvp_starts_draft', 'usvp_status_forward'],
        self::AGENTS => ['usva_bindings_once', 'usva_closed_is_final', 'usva_forbid_delete', 'usva_forbid_immutable_update', 'usva_forbid_truncate', 'usva_starts_pending', 'usva_status_forward'],
        self::PUBLICATIONS => ['usvpub_agents_for_calls', 'usvpub_chain_next', 'usvpub_forbid_delete', 'usvpub_forbid_truncate', 'usvpub_forbid_update'],
        self::SESSIONS => ['usvl_closed_is_final', 'usvl_forbid_delete', 'usvl_forbid_immutable_update', 'usvl_forbid_truncate', 'usvl_starts_open', 'usvl_status_forward'],
    ];

    /** Solo SQLite: los equivalentes de `CHECK (seq >= 1)` y de los cuatro ojos. */
    private const SQLITE_ONLY_TRIGGERS = [
        self::PROFILES => ['usvp_review_four_eyes'],
        self::PUBLICATIONS => ['usvpub_seq_min'],
        self::SESSIONS => ['usvl_verdict_rules'],
    ];

    private const PG_CHECKS = [
        self::PROFILES => ['usvp_duration_chk', 'usvp_four_eyes_chk', 'usvp_hash_chk', 'usvp_language_chk', 'usvp_privacy_chk', 'usvp_retention_chk',
            'usvp_review_chk', 'usvp_revision_chk', 'usvp_status_chk', 'usvp_submitted_chk', 'usvp_transfer_chk'],
        self::AGENTS => ['usva_agent_id_chk', 'usva_compiler_chk', 'usva_failure_chk', 'usva_fake_is_lab_chk', 'usva_hashes_chk', 'usva_provider_chk',
            'usva_purpose_chk', 'usva_serving_chk', 'usva_status_chk', 'usva_verification_chk'],
        self::PUBLICATIONS => ['usvpub_action_chk', 'usvpub_hash_matches_chk', 'usvpub_hashes_chk', 'usvpub_reason_chk', 'usvpub_scope_chk', 'usvpub_seq_chk', 'usvpub_shape_chk', 'usvpub_size_chk'],
        self::SESSIONS => ['usvl_closed_chk', 'usvl_four_eyes_chk', 'usvl_limits_chk', 'usvl_status_chk', 'usvl_verdict_chk'],
    ];

    /** Todos los del Studio, con el H8: 44 + 24 y 50 + 34 en PG; 32 + 23 en SQLite. */
    private const PG_STUDIO_TRIGGERS = 68;

    private const PG_STUDIO_CHECKS = 84;

    private const SQLITE_STUDIO_TRIGGERS = 55;

    /** Los parciales de todo el Studio: los 4 de H5/H6 y los 2 de los agentes. */
    private const STUDIO_PARTIAL_INDEXES = ['uscl_one_active', 'uscn_one_live', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'];

    private const FORBID_MD5 = '4ad748b5f17f09b398f7f641c197d7ee';

    private const APP_ROLE = 'ultron_studio_h8_db_app';

    private const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    // Los mensajes (los mismos en los dos motores).
    private const PROFILE_STARTS = 'un perfil de voz nace en draft, sin envío ni revisión (INSERT en ultron_studio_voice_profiles)';

    private const PROFILE_FORWARD = 'un perfil de voz solo avanza: draft → submitted → approved o rejected (UPDATE en ultron_studio_voice_profiles)';

    private const PROFILE_FINAL = 'un perfil de voz aprobado o rechazado es definitivo (UPDATE en ultron_studio_voice_profiles)';

    private const PROFILE_FROZEN = 'un perfil de voz congela al nacer su contenido, su huella y su autoría; cambiarlo es otra revisión (UPDATE en ultron_studio_voice_profiles)';

    private const PROFILE_FOUR_EYES = 'un perfil de voz lo revisa otra identidad (cuatro ojos) (UPDATE en ultron_studio_voice_profiles)';

    private const AGENT_STARTS = 'un agente de voz nace pending, sin verificar y sin atender ninguna parte (INSERT en ultron_studio_voice_agents)';

    private const AGENT_FORWARD = 'un agente de voz solo avanza: pending → deployed, rejected o failed (UPDATE en ultron_studio_voice_agents)';

    private const AGENT_FINAL = 'un agente de voz rechazado o fallido es definitivo (UPDATE en ultron_studio_voice_agents)';

    private const AGENT_FROZEN = 'un agente de voz congela al compilarse su origen, sus huellas y su autoría (UPDATE en ultron_studio_voice_agents)';

    private const AGENT_ONCE = 'un agente de voz fija una sola vez su id del proveedor y la observación de su proyección (UPDATE en ultron_studio_voice_agents)';

    private const CHAIN = 'una entrada nueva de la cadena de voz tiene que ser la siguiente a la cabeza (seq = cabeza + 1) y apuntar a ella (previous_publication_id y prev_hash) (INSERT en ultron_studio_voice_publications)';

    private const CHAIN_AGENTS = 'una entrada de la cadena de voz solo nombra un agente de publicación ya desplegado (nunca uno de laboratorio ni uno sin verificar) (INSERT en ultron_studio_voice_publications)';

    /** E1-4: el `prev_hash` de la primera entrada. */
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const SEQ_MIN = 'seq empieza en 1 (INSERT en ultron_studio_voice_publications)';

    private const SESSION_STARTS = 'una sesión del laboratorio hablado nace open y sin veredicto (INSERT en ultron_studio_voice_lab_sessions)';

    private const SESSION_FORWARD = 'una sesión del laboratorio hablado solo avanza: open → ended o failed (UPDATE en ultron_studio_voice_lab_sessions)';

    private const SESSION_FINAL = 'una sesión del laboratorio hablado fallida o con veredicto es definitiva (UPDATE en ultron_studio_voice_lab_sessions)';

    private const SESSION_FROZEN = 'una sesión del laboratorio hablado congela al abrirse su agente, su reserva y su autoría (UPDATE en ultron_studio_voice_lab_sessions)';

    private const SESSION_VERDICT = 'el veredicto de una sesión del laboratorio hablado lo da otra identidad, sobre una sesión terminada (cuatro ojos) (UPDATE en ultron_studio_voice_lab_sessions)';

    private ?Migration $migration = null;

    private int $n = 0;

    /** Protección 3 del H8: ninguna prueba de voz sale a la red, tampoco las de esquema (`VoiceIsolationTest`). */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        putenv(self::APP_ROLE_ENV);
        parent::tearDown();
    }

    // ── Columnas, recuentos, funciones y fronteras ──────────────────────────

    public function test_columnas_tipos_y_nulos_de_las_cuatro_tablas(): void
    {
        $motor = $this->isPostgres() ? 0 : 1;
        foreach ([
            self::PROFILES => self::STUDIO_VOICE_PROFILES_COLUMNS,
            self::AGENTS => self::STUDIO_VOICE_AGENTS_COLUMNS,
            self::PUBLICATIONS => self::STUDIO_VOICE_PUBLICATIONS_COLUMNS,
            self::SESSIONS => self::STUDIO_VOICE_LAB_SESSIONS_COLUMNS,
        ] as $tabla => $esperadas) {
            $reales = [];
            foreach (Schema::getColumns($tabla) as $c) {
                $reales[$c['name']] = [$c['type'], (bool) $c['nullable']];
            }
            $this->assertSame(array_map(static fn (array $e): array => [$e[$motor], $e[2]], $esperadas), $reales, $tabla);
        }
        // Ningún teléfono en claro: los destinos de transferencia solo cifrados (D8-14, D8-17).
        foreach (self::TABLES as $tabla) {
            foreach (Schema::getColumnListing($tabla) as $columna) {
                $this->assertDoesNotMatchRegularExpression('/phone|number|e164|payload_json|spec_json|transcript|audio_url|recording_url/', $columna, "{$tabla}.{$columna}");
            }
        }
    }

    public function test_recuentos_y_nombres_de_las_guardas_del_gobierno_de_la_voz(): void
    {
        $this->assertSame(self::STUDIO_PARTIAL_INDEXES, $this->studioPartialIndexes());
        $this->assertSame($this->isPostgres()
            ? ['usva_one_canary' => '(serves_canary = true)', 'usva_one_rest' => '(serves_rest = true)']
            : ['usva_one_canary' => 'serves_canary = true', 'usva_one_rest' => 'serves_rest = true'], $this->partialPredicates(self::AGENTS));

        if ($this->isPostgres()) {
            foreach (self::PG_TRIGGERS as $tabla => $nombres) {
                $this->assertSame($nombres, $this->triggersOn($tabla), $tabla);
            }
            foreach (self::PG_CHECKS as $tabla => $nombres) {
                $this->assertSame($nombres, $this->checksOn($tabla), $tabla);
            }
            $this->assertSame(24, $this->countLike('trigger', 'ultron\\_studio\\_voice\\_%'), 'perfiles 6 + agentes 7 + cadena 5 + sesiones 6');
            $this->assertSame(34, $this->countLike('check', 'ultron\\_studio\\_voice\\_%'), 'perfiles 11 + agentes 10 + cadena 8 + sesiones 5');
            $this->assertSame(self::PG_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron\\_studio\\_%'), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24');
            $this->assertSame(self::PG_STUDIO_CHECKS, $this->countLike('check', 'ultron\\_studio\\_%'), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34');

            return;
        }

        foreach (self::PG_TRIGGERS as $tabla => $nombres) {
            $esperados = [...array_filter($nombres, static fn (string $n): bool => ! str_ends_with($n, '_truncate')), ...(self::SQLITE_ONLY_TRIGGERS[$tabla] ?? [])];
            sort($esperados);
            $this->assertSame($esperados, $this->triggersOn($tabla), $tabla);
        }
        $this->assertSame(23, $this->countLike('trigger', 'ultron_studio_voice_%'), 'perfiles 6 + agentes 6 + cadena 5 + sesiones 6');
        $this->assertSame(self::SQLITE_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron_studio_%'), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23');
    }

    public function test_solo_en_postgres_las_funciones_de_la_cadena_son_propias_y_la_de_f_no_cambia(): void
    {
        $this->requirePostgres('las funciones de los disparadores solo existen en PostgreSQL.');

        foreach ([
            'ultron_studio_voice_publication_chain' => 'FROM public.ultron_studio_voice_publications AS p',
            'ultron_studio_voice_publication_agents' => 'FROM public.ultron_studio_voice_agents AS a',
        ] as $funcion => $tablaCualificada) {
            $f = DB::selectOne('SELECT l.lanname AS lang, p.provolatile AS vol, p.prosecdef::int AS definer, pg_get_userbyid(p.proowner) AS owner, p.prosrc AS src
                FROM pg_proc p JOIN pg_language l ON l.oid = p.prolang WHERE p.proname = ?', [$funcion]);
            $this->assertSame(['plpgsql', 'v', 0, (string) DB::scalar('SELECT current_user')], [$f->lang, $f->vol, (int) $f->definer, $f->owner], $funcion);
            $this->assertStringContainsString($tablaCualificada, $f->src, $funcion);
        }
        $this->assertStringContainsString('BEFORE INSERT ON public.ultron_studio_voice_publications FOR EACH ROW EXECUTE FUNCTION ultron_studio_voice_publication_chain(',
            (string) DB::scalar("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgname = 'usvpub_chain_next'"));
        $this->assertSame('CHECK ((seq >= 1))', DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'usvpub_seq_chk'"));

        // Los demás disparadores de las cuatro usan la de F, que no se redeclara ni cambia.
        $this->assertSame(22, (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_proc p ON p.oid = t.tgfoid
            WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\\_studio\\_voice\\_%' AND p.proname = 'ultron_studio_forbid'"));
        $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
        $this->assertSame(self::FORBID_MD5, DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
        $this->assertStringNotContainsString('FUNCTION ultron_studio_forbid', (string) file_get_contents(database_path('migrations/'.self::MIGRATION.'.php')));
    }

    /**
     * Protección 7 y auditoría §b.1, fila 15: la voz no lee ni escribe la
     * cadena del H5 ni nombra el motor; sus FKs solo van a versiones (de F) y a
     * sus propias tablas.
     */
    public function test_la_voz_no_nombra_la_cadena_del_h5_ni_el_motor(): void
    {
        $codigo = '';
        foreach (token_get_all((string) file_get_contents(database_path('migrations/'.self::MIGRATION.'.php'))) as $t) {
            if (! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $codigo .= is_array($t) ? $t[1] : $t;
            }
        }
        $this->assertDoesNotMatchRegularExpression('/\bultron_studio_publications\b/', $codigo);
        foreach (['marketing_', 'UltronPublishedConfig', 'ultron_aborts', 'voice_calls'] as $nombre) {
            $this->assertStringNotContainsString($nombre, $codigo, $nombre);
        }

        $fks = [];
        foreach (self::TABLES as $tabla) {
            foreach (Schema::getForeignKeys($tabla) as $fk) {
                $fks[] = "{$tabla}.".implode(',', $fk['columns'])." → {$fk['foreign_table']}";
                $this->assertSame('restrict', strtolower((string) $fk['on_delete']), $tabla);
            }
        }
        sort($fks);
        $this->assertSame([
            'ultron_studio_voice_agents.studio_version_id → ultron_studio_versions',
            'ultron_studio_voice_agents.voice_profile_id → ultron_studio_voice_profiles',
            'ultron_studio_voice_lab_sessions.agent_id → ultron_studio_voice_agents',
            'ultron_studio_voice_profiles.based_on_profile_id → ultron_studio_voice_profiles',
            'ultron_studio_voice_publications.agent_id → ultron_studio_voice_agents',
            'ultron_studio_voice_publications.previous_publication_id → ultron_studio_voice_publications',
            'ultron_studio_voice_publications.restores_publication_id → ultron_studio_voice_publications',
        ], $fks);
    }

    // ── Perfiles ────────────────────────────────────────────────────────────

    public function test_un_perfil_nace_en_draft_y_solo_avanza(): void
    {
        $this->assertGuarded(fn () => $this->insertProfile(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by_admin_id' => 1, 'submitted_by_name' => 'Ana']), self::PROFILE_STARTS, 'nace enviado');
        $this->assertGuarded(fn () => $this->insertProfile(['reviewed_by_admin_id' => 2, 'reviewed_by_name' => 'Beto', 'reviewed_at' => now()]), self::PROFILE_STARTS, 'nace revisado');
        // El estado solo, sin más columnas (en PG lo pararía también un CHECK, pero el disparador va antes).
        foreach (['submitted', 'approved', 'rejected'] as $estado) {
            $this->assertGuarded(fn () => $this->insertProfile(['status' => $estado]), self::PROFILE_STARTS, "nace {$estado} a secas");
        }

        $id = $this->insertProfile();
        $this->assertGuarded(fn () => $this->approve($id, 2), self::PROFILE_FORWARD, 'draft → approved sin enviar');
        $this->assertGuarded(fn () => $this->reject($id, 2), self::PROFILE_FORWARD, 'draft → rejected');
        $this->assertAccepted(fn () => $this->submit($id), 'draft → submitted');
        $this->assertGuarded(fn () => DB::table(self::PROFILES)->where('id', $id)->update(['status' => 'draft']), self::PROFILE_FORWARD, 'submitted → draft');
        $this->assertAccepted(fn () => $this->approve($id, 2), 'submitted → approved (Beto)');
        foreach ([['status' => 'rejected'], ['status' => 'submitted'], ['review_note' => 'Otra nota'], ['updated_at' => now()]] as $cambio) {
            $this->assertGuarded(fn () => DB::table(self::PROFILES)->where('id', $id)->update($cambio), self::PROFILE_FINAL, 'aprobado: '.json_encode($cambio));
        }

        $otro = $this->insertProfile();
        $this->submit($otro);
        $this->assertAccepted(fn () => $this->reject($otro, 2), 'submitted → rejected (Beto, con nota)');
        $this->assertGuarded(fn () => DB::table(self::PROFILES)->where('id', $otro)->update(['status' => 'approved']), self::PROFILE_FINAL, 'rechazado → approved');
    }

    public function test_el_contenido_de_un_perfil_no_cambia_ni_en_draft(): void
    {
        $id = $this->insertProfile();
        foreach (['id', 'uuid', 'client_request_id', 'revision', 'based_on_profile_id', 'label', 'language', 'max_duration_seconds', 'retention_days',
            'record_calls', 'record_voice', 'transfer_count', 'transfer_destinations_encrypted', 'content', 'content_hash',
            'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at'] as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::PROFILES." SET {$columna} = {$columna} WHERE id = ?", [$id]), self::PROFILE_FROZEN, $columna);
        }
        // Cambiarlo es otra revisión.
        $this->assertAccepted(fn () => $this->insertProfile(['revision' => 2, 'based_on_profile_id' => $id]), 'la revisión 2');
    }

    public function test_un_perfil_lo_revisa_otra_identidad(): void
    {
        foreach (['aprobarlo' => fn (int $id) => $this->approve($id, 1), 'rechazarlo' => fn (int $id) => $this->reject($id, 1)] as $que => $revisar) {
            $id = $this->insertProfile();   // lo crea Ana (1)
            $this->submit($id);
            $this->isPostgres()
                ? $this->assertRejected(fn () => $revisar($id), '23514', 'usvp_four_eyes_chk', "Ana: {$que}")
                : $this->assertRejected(fn () => $revisar($id), '23000', self::PREFIX.self::PROFILE_FOUR_EYES, "Ana: {$que}");
            $this->assertSame('submitted', DB::table(self::PROFILES)->where('id', $id)->value('status'));
        }
    }

    public function test_los_perfiles_los_agentes_y_las_sesiones_no_se_borran(): void
    {
        $agente = $this->deployedAgent();
        $sesion = $this->insertSession($this->deployedAgent(['purpose' => 'lab', 'provider' => 'fake']));
        $perfil = (int) DB::table(self::AGENTS)->where('id', $agente)->value('voice_profile_id');
        foreach ([
            self::PROFILES => [$perfil, 'los perfiles de voz no se borran'],
            self::AGENTS => [$agente, 'los agentes de voz no se borran'],
            self::SESSIONS => [$sesion, 'las sesiones del laboratorio hablado no se borran'],
        ] as $tabla => [$id, $motivo]) {
            $this->assertGuarded(fn () => DB::table($tabla)->where('id', $id)->delete(), "{$motivo} (DELETE en {$tabla})", $tabla);
            if ($this->isPostgres()) {
                $this->assertGuarded(fn () => DB::statement("TRUNCATE {$tabla} CASCADE"), "{$motivo} (TRUNCATE en {$tabla})", "{$tabla}: TRUNCATE");
            }
        }
    }

    // ── Agentes ─────────────────────────────────────────────────────────────

    public function test_un_agente_nace_pending_y_solo_avanza(): void
    {
        $this->assertGuarded(fn () => $this->insertAgent(['status' => 'deployed']), self::AGENT_STARTS, 'nace desplegado');
        $this->assertGuarded(fn () => $this->insertAgent(['observed_hash' => $this->hex('proyeccion'), 'observed_at' => now()]), self::AGENT_STARTS, 'nace observado');
        $this->assertGuarded(fn () => $this->insertAgent(['serves_canary' => true]), self::AGENT_STARTS, 'nace atendiendo el canario');

        $id = $this->insertAgent();
        $this->assertAccepted(fn () => $this->deploy($id), 'pending → deployed');
        foreach (['pending', 'rejected', 'failed'] as $hacia) {
            $this->assertGuarded(fn () => DB::table(self::AGENTS)->where('id', $id)->update(['status' => $hacia]), self::AGENT_FORWARD, "deployed → {$hacia}");
        }
        $this->assertAccepted(fn () => DB::table(self::AGENTS)->where('id', $id)->update(['serves_canary' => true, 'updated_at' => now()]), 'desplegado: atiende el canario');

        $rechazado = $this->insertAgent();
        $this->assertAccepted(fn () => DB::table(self::AGENTS)->where('id', $rechazado)->update(['status' => 'rejected', 'provider_agent_id' => 'agent_rechazado',
            'observed_hash' => $this->hex('otra-proyeccion'), 'observed_at' => now(), 'status_changed_at' => now()]), 'pending → rejected (no cuadra)');
        $fallido = $this->insertAgent();
        $this->assertAccepted(fn () => DB::table(self::AGENTS)->where('id', $fallido)->update(['status' => 'failed', 'failure_code' => 'provider_rejected']), 'pending → failed');
        foreach ([$rechazado, $fallido] as $cerrado) {
            foreach ([['status' => 'deployed'], ['updated_at' => now()], ['serves_rest' => true]] as $cambio) {
                $this->assertGuarded(fn () => DB::table(self::AGENTS)->where('id', $cerrado)->update($cambio), self::AGENT_FINAL, json_encode($cambio));
            }
        }
    }

    public function test_lo_que_un_agente_compila_no_cambia_y_su_despliegue_se_fija_una_vez(): void
    {
        $id = $this->insertAgent();
        foreach (['id', 'uuid', 'client_request_id', 'purpose', 'studio_version_id', 'studio_content_hash', 'voice_profile_id', 'voice_profile_hash',
            'compiler_version', 'spec_hash', 'payload_hash', 'projection_hash', 'transfer_destination_hashes', 'provider', 'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at'] as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::AGENTS." SET {$columna} = {$columna} WHERE id = ?", [$id]), self::AGENT_FROZEN, $columna);
        }

        $this->assertAccepted(fn () => DB::table(self::AGENTS)->where('id', $id)->update(['provider_agent_id' => 'agent_publicado_1']), 'el id, la primera vez');
        $this->assertGuarded(fn () => DB::table(self::AGENTS)->where('id', $id)->update(['provider_agent_id' => 'agent_otro']), self::AGENT_ONCE, 'el id, cambiado');
        $this->assertAccepted(fn () => DB::table(self::AGENTS)->where('id', $id)->update(['provider_agent_id' => 'agent_publicado_1']), 'el id, el mismo');
        $this->deploy($id);
        $this->assertGuarded(fn () => DB::table(self::AGENTS)->where('id', $id)->update(['observed_hash' => $this->hex('deriva')]), self::AGENT_ONCE, 'la observación, cambiada (la deriva va al freno, no aquí)');
        $this->assertGuarded(fn () => DB::table(self::AGENTS)->where('id', $id)->update(['observed_at' => now()->addMinute()]), self::AGENT_ONCE, 'la fecha de la observación');
        $this->assertUniqueRejects(fn () => $this->insertAgent(['provider_agent_id' => 'agent_publicado_1']), 'ultron_studio_voice_agents_provider_agent_id_unique', self::AGENTS, ['provider_agent_id'], 'el mismo agente en dos filas');
    }

    public function test_a_lo_sumo_un_agente_atiende_cada_parte(): void
    {
        $a = $this->deployedAgent();
        $b = $this->deployedAgent();
        foreach (['serves_canary' => 'usva_one_canary', 'serves_rest' => 'usva_one_rest'] as $parte => $indice) {
            DB::table(self::AGENTS)->where('id', $a)->update([$parte => true]);
            $this->assertUniqueRejects(fn () => DB::table(self::AGENTS)->where('id', $b)->update([$parte => true]), $indice, self::AGENTS, [$parte], $parte);
            // Pasar la parte de uno a otro: primero se suelta, después se toma (en la misma transacción).
            $this->assertAccepted(function () use ($a, $b, $parte): void {
                DB::table(self::AGENTS)->where('id', $a)->update([$parte => false]);
                DB::table(self::AGENTS)->where('id', $b)->update([$parte => true]);
            }, "{$parte}: de A a B");
        }
        // Tras promover, el mismo agente puede atender las dos.
        $this->assertAccepted(fn () => DB::table(self::AGENTS)->where('id', $b)->update(['serves_canary' => true, 'serves_rest' => true]), 'B atiende las dos');
        $this->assertSame([[$b], [$b]], [
            DB::table(self::AGENTS)->where('serves_canary', true)->pluck('id')->map(static fn ($v): int => (int) $v)->all(),
            DB::table(self::AGENTS)->where('serves_rest', true)->pluck('id')->map(static fn ($v): int => (int) $v)->all(),
        ]);
    }

    // ── La cadena de voz ────────────────────────────────────────────────────

    public function test_la_cadena_admite_la_siguiente_encadenada(): void
    {
        $agente = $this->deployedAgent();
        $uno = $this->insertPublication(1, null, ['agent_id' => $agente]);
        $dos = $this->insertPublication(2, $uno, ['action' => 'promote', 'scope' => 'rest', 'agent_id' => $agente]);
        $tres = $this->insertPublication(3, $dos, ['action' => 'off', 'agent_id' => null, 'reason' => 'Canario apagado por la prueba']);

        $filas = DB::table(self::PUBLICATIONS)->orderBy('seq')->get(['id', 'seq', 'previous_publication_id', 'prev_hash', 'effective_hash']);
        $this->assertSame([[1, null], [2, $uno], [3, $dos]], $filas->map(static fn (object $f): array => [(int) $f->seq, $f->previous_publication_id === null ? null : (int) $f->previous_publication_id])->all());
        // Encadenada por huella: cada prev_hash es la huella de la anterior (64 ceros la primera).
        $this->assertSame([self::GENESIS, $filas[0]->effective_hash, $filas[1]->effective_hash], $filas->pluck('prev_hash')->all());
        $this->assertSame($tres, (int) DB::table(self::PUBLICATIONS)->orderByDesc('seq')->value('id'));
        // Lo vigente de cada parte: su entrada de mayor seq (el canario, apagado; el resto, el agente).
        $vigente = fn (string $parte): ?int => ($id = DB::table(self::PUBLICATIONS)->where('scope', $parte)->orderByDesc('seq')->value('agent_id')) === null ? null : (int) $id;
        $this->assertSame([null, $agente], [$vigente('canary'), $vigente('rest')]);
    }

    /** @return array<string, array{0: int, 1: Closure(array<int, int>): array{0: int, 1: ?int, 2?: string}}> */
    public static function brokenEntries(): array
    {
        // [entradas a sembrar, la nueva: [seq, a quién apunta, y su prev_hash si no es el de esa entrada]].
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
            'la siguiente apuntando a sí misma por adelantado' => [2, static fn (array $ids): array => [3, 999999]],
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
            $o['prev_hash'] = str_starts_with($caso[2], 'de:') ? (string) DB::table(self::PUBLICATIONS)->where('id', $ids[(int) substr($caso[2], 3)])->value('effective_hash') : $caso[2];
        }

        $this->assertGuarded(fn () => $this->insertPublication($seq, $previa, $o), self::CHAIN);
        $this->assertSame($sembradas, DB::table(self::PUBLICATIONS)->count());
        $this->insertPublication($sembradas + 1, $ids[$sembradas] ?? null);
    }

    public function test_seq_cero_o_negativo_y_seq_repetido(): void
    {
        $uno = $this->insertPublication(1, null);
        foreach ([0, -1] as $seq) {
            $this->isPostgres()
                ? $this->assertRejected(fn () => $this->insertPublication($seq, $uno), '23514', 'usvpub_seq_chk', "seq {$seq}")
                : $this->assertRejected(fn () => $this->insertPublication($seq, $uno), '23000', self::PREFIX.self::SEQ_MIN, "seq {$seq}");
        }
        $dos = $this->insertPublication(2, $uno);
        // La carrera perdida sigue siendo el UNIQUE de seq: el servicio la traduce a 409 `publication_moved`.
        foreach ([[2, $uno], [1, null], [2, null], [1, $dos]] as [$seq, $previa]) {
            $this->assertUniqueRejects(fn () => $this->insertPublication($seq, $previa), 'ultron_studio_voice_publications_seq_unique', self::PUBLICATIONS, ['seq'], "seq {$seq} repetido");
        }
        $this->assertSame(2, DB::table(self::PUBLICATIONS)->count());
    }

    public function test_solo_en_postgres_una_tabla_temporal_no_suplanta_a_la_cadena_ni_a_los_agentes(): void
    {
        $this->requirePostgres('pg_temp solo existe en PostgreSQL.');

        $uno = $this->insertPublication(1, null);
        $lab = $this->deployedAgent(['purpose' => 'lab', 'provider' => 'fake']);
        // Las filas, ANTES de las tablas temporales: con ellas, lo que no se cualifica va a parar a ellas.
        $filaCabeza = $this->publicationRow(2147483647, $uno);
        $filaLab = $this->publicationRow(2, $uno, ['agent_id' => $lab]);
        DB::statement('CREATE TEMP TABLE ultron_studio_voice_publications (id bigint, seq bigint) ON COMMIT DROP');
        DB::statement('INSERT INTO pg_temp.ultron_studio_voice_publications (id, seq) VALUES (?, 2147483646)', [$uno]);
        DB::statement('CREATE TEMP TABLE ultron_studio_voice_agents (id bigint, purpose text, status text) ON COMMIT DROP');
        DB::statement("INSERT INTO pg_temp.ultron_studio_voice_agents (id, purpose, status) VALUES (?, 'publication', 'deployed')", [$lab]);

        $this->assertGuarded(fn () => DB::table('public.'.self::PUBLICATIONS)->insert($filaCabeza), self::CHAIN, 'cabeza falsa');
        $this->assertGuarded(fn () => DB::table('public.'.self::PUBLICATIONS)->insert($filaLab), self::CHAIN_AGENTS, 'agente falso');
        DB::statement('DROP TABLE pg_temp.ultron_studio_voice_publications');
        DB::statement('DROP TABLE pg_temp.ultron_studio_voice_agents');
        $this->assertSame(1, (int) DB::table(self::PUBLICATIONS)->count());
    }

    public function test_una_entrada_solo_nombra_un_agente_de_publicacion_desplegado(): void
    {
        $bueno = $this->deployedAgent();
        $laboratorio = $this->deployedAgent(['purpose' => 'lab', 'provider' => 'fake']);
        $pendiente = $this->insertAgent();
        $rechazado = $this->insertAgent();
        DB::table(self::AGENTS)->where('id', $rechazado)->update(['status' => 'rejected', 'provider_agent_id' => 'agent_r', 'observed_hash' => $this->hex('x'), 'observed_at' => now()]);
        $fallido = $this->insertAgent();
        DB::table(self::AGENTS)->where('id', $fallido)->update(['status' => 'failed', 'failure_code' => 'timeout']);

        foreach (['de laboratorio' => $laboratorio, 'pendiente' => $pendiente, 'rechazado' => $rechazado, 'fallido' => $fallido] as $que => $agente) {
            $this->assertGuarded(fn () => $this->insertPublication(1, null, ['agent_id' => $agente]), self::CHAIN_AGENTS, "publicar uno {$que}");
            $this->assertGuarded(fn () => $this->insertPublication(1, null, ['action' => 'promote', 'scope' => 'rest', 'agent_id' => $agente]), self::CHAIN_AGENTS, "promover uno {$que}");
        }
        $this->isPostgres()
            ? $this->assertRejected(fn () => $this->insertPublication(1, null, ['agent_id' => 999999]), '23503', 'ultron_studio_voice_publications_agent_id_foreign', 'un agente que no existe')
            : $this->assertRejected(fn () => $this->insertPublication(1, null, ['agent_id' => 999999]), '23000', 'FOREIGN KEY constraint failed', 'un agente que no existe');

        $uno = $this->insertPublication(1, null, ['agent_id' => $bueno]);
        $this->assertAccepted(fn () => $this->insertPublication(2, $uno, ['action' => 'off', 'agent_id' => null, 'reason' => 'Apagada']), 'apagar: sin agente');
    }

    public function test_la_cadena_de_voz_es_de_solo_insercion(): void
    {
        $id = $this->insertPublication(1, null);
        $this->assertGuarded(fn () => DB::statement('UPDATE '.self::PUBLICATIONS.' SET reason = reason WHERE id = ?', [$id]), 'la cadena de voz es de solo inserción (UPDATE en ultron_studio_voice_publications)');
        $this->assertGuarded(fn () => DB::table(self::PUBLICATIONS)->where('id', $id)->delete(), 'la cadena de voz es de solo inserción (DELETE en ultron_studio_voice_publications)');
        if ($this->isPostgres()) {
            $this->assertGuarded(fn () => DB::statement('TRUNCATE '.self::PUBLICATIONS.' CASCADE'), 'la cadena de voz es de solo inserción (TRUNCATE en ultron_studio_voice_publications)');
        }
        $this->assertSame(1, DB::table(self::PUBLICATIONS)->count());
    }

    // ── Sesiones del laboratorio hablado ────────────────────────────────────

    public function test_una_sesion_nace_open_y_solo_avanza(): void
    {
        $lab = $this->deployedAgent(['purpose' => 'lab', 'provider' => 'fake']);
        $this->assertGuarded(fn () => $this->insertSession($lab, ['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'user_ended']), self::SESSION_STARTS, 'nace terminada');
        $this->assertGuarded(fn () => $this->insertSession($lab, ['verdict' => 'approved', 'verdict_by_admin_id' => 2, 'verdict_by_name' => 'Beto', 'verdict_at' => now()]), self::SESSION_STARTS, 'nace juzgada');
        foreach (['ended', 'failed'] as $estado) {
            $this->assertGuarded(fn () => $this->insertSession($lab, ['status' => $estado]), self::SESSION_STARTS, "nace {$estado} a secas");
        }

        $id = $this->insertSession($lab);
        $this->assertAccepted(fn () => $this->endSession($id), 'open → ended');
        $this->assertGuarded(fn () => DB::table(self::SESSIONS)->where('id', $id)->update(['status' => 'open']), self::SESSION_FORWARD, 'ended → open');
        $this->assertGuarded(fn () => DB::table(self::SESSIONS)->where('id', $id)->update(['status' => 'failed']), self::SESSION_FORWARD, 'ended → failed');
        $this->assertAccepted(fn () => DB::table(self::SESSIONS)->where('id', $id)->update(['cost_micros' => 400000]), 'el coste llega después');

        $fallida = $this->insertSession($lab);
        $this->assertAccepted(fn () => DB::table(self::SESSIONS)->where('id', $fallida)->update(['status' => 'failed', 'ended_at' => now(), 'end_reason' => 'signed_url_failed']), 'open → failed');
        $this->assertGuarded(fn () => DB::table(self::SESSIONS)->where('id', $fallida)->update(['cost_micros' => 1]), self::SESSION_FINAL, 'fallida: nada más');

        foreach (['id', 'uuid', 'client_request_id', 'agent_id', 'budget_day', 'reserved_micros', 'max_seconds', 'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at'] as $columna) {
            $this->assertGuarded(fn () => DB::statement('UPDATE '.self::SESSIONS." SET {$columna} = {$columna} WHERE id = ?", [$id]), self::SESSION_FROZEN, $columna);
        }
    }

    public function test_el_veredicto_lo_da_otra_identidad_sobre_una_sesion_terminada_y_es_definitivo(): void
    {
        $lab = $this->deployedAgent(['purpose' => 'lab', 'provider' => 'fake']);
        $abierta = $this->insertSession($lab);   // la abre Ana (1)
        $veredicto = static fn (int $quien, string $decision = 'approved'): array => ['verdict' => $decision, 'verdict_note' => $decision === 'rejected' ? 'Suena robótico' : null,
            'verdict_by_admin_id' => $quien, 'verdict_by_name' => $quien === 1 ? 'Ana' : 'Beto', 'verdict_at' => now()];

        $this->isPostgres()
            ? $this->assertRejected(fn () => DB::table(self::SESSIONS)->where('id', $abierta)->update($veredicto(2)), '23514', 'usvl_verdict_chk', 'sobre una abierta')
            : $this->assertRejected(fn () => DB::table(self::SESSIONS)->where('id', $abierta)->update($veredicto(2)), '23000', self::PREFIX.self::SESSION_VERDICT, 'sobre una abierta');

        $this->endSession($abierta);
        $this->isPostgres()
            ? $this->assertRejected(fn () => DB::table(self::SESSIONS)->where('id', $abierta)->update($veredicto(1)), '23514', 'usvl_four_eyes_chk', 'Ana juzga la suya')
            : $this->assertRejected(fn () => DB::table(self::SESSIONS)->where('id', $abierta)->update($veredicto(1)), '23000', self::PREFIX.self::SESSION_VERDICT, 'Ana juzga la suya');
        $this->assertAccepted(fn () => DB::table(self::SESSIONS)->where('id', $abierta)->update($veredicto(2)), 'Beto la juzga');
        foreach ([$veredicto(2, 'rejected'), ['verdict_note' => 'otra'], ['cost_micros' => 5]] as $cambio) {
            $this->assertGuarded(fn () => DB::table(self::SESSIONS)->where('id', $abierta)->update($cambio), self::SESSION_FINAL, 'juzgada: '.json_encode(array_keys($cambio)));
        }
        $this->assertSame('approved', DB::table(self::SESSIONS)->where('id', $abierta)->value('verdict'));
    }

    // ── Los CHECK de forma (solo PG) ────────────────────────────────────────

    /** @return array<string, array{string, array<string, mixed>, list<string>}> */
    public static function badRows(): array
    {
        $hex = hash('sha256', 'x');
        $otro = hash('sha256', 'y');

        return [
            // Perfiles (la base: un draft válido de Ana).
            'perfil: otro idioma' => [self::PROFILES, ['language' => 'en'], ['usvp_language_chk']],
            'perfil: 59 s' => [self::PROFILES, ['max_duration_seconds' => 59], ['usvp_duration_chk']],
            'perfil: 601 s' => [self::PROFILES, ['max_duration_seconds' => 601], ['usvp_duration_chk']],
            'perfil: 31 días' => [self::PROFILES, ['retention_days' => 31], ['usvp_retention_chk']],
            'perfil: grabación encendida' => [self::PROFILES, ['record_calls' => true], ['usvp_privacy_chk']],
            'perfil: audio guardado' => [self::PROFILES, ['record_voice' => true], ['usvp_privacy_chk']],
            'perfil: 4 destinos' => [self::PROFILES, ['transfer_count' => 4], ['usvp_transfer_chk']],
            'perfil: destinos sin cifrado' => [self::PROFILES, ['transfer_destinations_encrypted' => null], ['usvp_transfer_chk']],
            'perfil: cifrado sin destinos' => [self::PROFILES, ['transfer_count' => 0], ['usvp_transfer_chk']],
            'perfil: huella en mayúsculas' => [self::PROFILES, ['content_hash' => strtoupper($hex)], ['usvp_hash_chk']],
            'perfil: revisión 0' => [self::PROFILES, ['revision' => 0], ['usvp_revision_chk']],
            'perfil: estado desconocido' => [self::PROFILES, ['status' => 'published'], ['usvp_review_chk', 'usvp_status_chk', 'usvp_submitted_chk']],
            'perfil: enviado sin envío' => [self::PROFILES, ['status' => 'submitted'], ['usvp_submitted_chk']],
            'perfil: draft con envío' => [self::PROFILES, ['submitted_at' => '2026-11-03 12:00:00', 'submitted_by_admin_id' => 1], ['usvp_submitted_chk']],
            'perfil: aprobado sin revisor' => [self::PROFILES, ['status' => 'approved', 'submitted_at' => '2026-11-03 12:00:00', 'submitted_by_admin_id' => 1], ['usvp_review_chk']],
            'perfil: rechazado sin nota' => [self::PROFILES, ['status' => 'rejected', 'submitted_at' => '2026-11-03 12:00:00', 'submitted_by_admin_id' => 1,
                'reviewed_at' => '2026-11-03 12:00:00', 'reviewed_by_admin_id' => 2], ['usvp_review_chk']],
            'perfil: draft con nota de revisión' => [self::PROFILES, ['review_note' => 'x'], ['usvp_review_chk']],
            'perfil: lo aprueba quien lo creó' => [self::PROFILES, ['status' => 'approved', 'submitted_at' => '2026-11-03 12:00:00', 'submitted_by_admin_id' => 1,
                'reviewed_at' => '2026-11-03 12:00:00', 'reviewed_by_admin_id' => 1], ['usvp_four_eyes_chk']],
            // Agentes (la base: uno de publicación pending).
            'agente: otro propósito' => [self::AGENTS, ['purpose' => 'demo'], ['usva_purpose_chk']],
            'agente: otro proveedor' => [self::AGENTS, ['provider' => 'openai'], ['usva_fake_is_lab_chk', 'usva_provider_chk']],
            'agente: el simulador en publicación' => [self::AGENTS, ['provider' => 'fake'], ['usva_fake_is_lab_chk']],
            'agente: estado desconocido' => [self::AGENTS, ['status' => 'live'], ['usva_status_chk', 'usva_verification_chk']],
            'agente: huella de la proyección corta' => [self::AGENTS, ['projection_hash' => substr($hex, 1)], ['usva_hashes_chk']],
            'agente: compilador ajeno' => [self::AGENTS, ['compiler_version' => 'ultron-voice-compiler/v2'], ['usva_compiler_chk']],
            'agente: id con espacios' => [self::AGENTS, ['provider_agent_id' => 'agent 1'], ['usva_agent_id_chk']],
            'agente: pendiente observado' => [self::AGENTS, ['observed_hash' => $hex, 'observed_at' => '2026-11-03 12:00:00'], ['usva_verification_chk']],
            'agente: desplegado sin observar' => [self::AGENTS, ['status' => 'deployed', 'provider_agent_id' => 'agent_1'], ['usva_verification_chk']],
            'agente: desplegado que no cuadra' => [self::AGENTS, ['status' => 'deployed', 'provider_agent_id' => 'agent_1', 'observed_hash' => $otro, 'observed_at' => '2026-11-03 12:00:00'], ['usva_verification_chk']],
            'agente: rechazado que cuadra' => [self::AGENTS, ['status' => 'rejected', 'provider_agent_id' => 'agent_1', 'observed_hash' => $hex, 'observed_at' => '2026-11-03 12:00:00'], ['usva_verification_chk']],
            'agente: fallido sin código' => [self::AGENTS, ['status' => 'failed'], ['usva_failure_chk']],
            'agente: código en texto libre' => [self::AGENTS, ['status' => 'failed', 'failure_code' => 'Timeout de 10 s'], ['usva_failure_chk']],
            'agente: pendiente con código' => [self::AGENTS, ['failure_code' => 'timeout'], ['usva_failure_chk']],
            'agente: pendiente atendiendo' => [self::AGENTS, ['serves_canary' => true], ['usva_serving_chk']],
            'agente: de laboratorio atendiendo' => [self::AGENTS, ['purpose' => 'lab', 'provider' => 'fake', 'status' => 'deployed', 'provider_agent_id' => 'fake_1',
                'observed_hash' => $hex, 'observed_at' => '2026-11-03 12:00:00', 'serves_rest' => true], ['usva_serving_chk']],
            // La cadena (la base: la primera entrada, un publish del canario con su agente desplegado).
            'cadena: acción desconocida' => [self::PUBLICATIONS, ['action' => 'legacy'], ['usvpub_action_chk']],
            'cadena: parte desconocida' => [self::PUBLICATIONS, ['scope' => 'all'], ['usvpub_scope_chk', 'usvpub_shape_chk']],
            'cadena: seq 0' => [self::PUBLICATIONS, ['seq' => 0], ['usvpub_seq_chk']],
            'cadena: firma corta' => [self::PUBLICATIONS, ['signature' => substr($hex, 2)], ['usvpub_hashes_chk']],
            'cadena: prev_hash en mayúsculas' => [self::PUBLICATIONS, ['prev_hash' => strtoupper($hex)], ['usvpub_hashes_chk']],
            'cadena: la huella no es la de los bytes' => [self::PUBLICATIONS, ['effective_hash' => $otro], ['usvpub_hash_matches_chk']],
            'cadena: los bytes cambiados' => [self::PUBLICATIONS, ['effective_json' => '{"seq":1}'], ['usvpub_hash_matches_chk']],
            'cadena: 16 385 caracteres' => [self::PUBLICATIONS, ['effective_json' => str_repeat('a', 16385), 'effective_hash' => hash('sha256', str_repeat('a', 16385))], ['usvpub_size_chk']],
            'cadena: rollback sin motivo' => [self::PUBLICATIONS, ['action' => 'rollback', 'restores_publication_id' => 1, 'reason' => null], ['usvpub_reason_chk']],
            'cadena: off sin motivo' => [self::PUBLICATIONS, ['action' => 'off', 'agent_id' => null, 'reason' => null], ['usvpub_reason_chk']],
            'cadena: publish sin agente' => [self::PUBLICATIONS, ['agent_id' => null], ['usvpub_shape_chk']],
            'cadena: publish en el resto' => [self::PUBLICATIONS, ['scope' => 'rest'], ['usvpub_shape_chk']],
            'cadena: promote en el canario' => [self::PUBLICATIONS, ['action' => 'promote'], ['usvpub_shape_chk']],
            'cadena: off con agente' => [self::PUBLICATIONS, ['action' => 'off', 'reason' => 'x'], ['usvpub_shape_chk']],
            'cadena: rollback sin la que vuelve' => [self::PUBLICATIONS, ['action' => 'rollback', 'reason' => 'x'], ['usvpub_shape_chk']],
            'cadena: rollback sin agente' => [self::PUBLICATIONS, ['action' => 'rollback', 'restores_publication_id' => 1, 'agent_id' => null, 'reason' => 'x'], ['usvpub_shape_chk']],
            'cadena: publish con la que vuelve' => [self::PUBLICATIONS, ['restores_publication_id' => 1], ['usvpub_shape_chk']],
            // Sesiones (la base: una abierta de Ana).
            'sesión: estado desconocido' => [self::SESSIONS, ['status' => 'paused'], ['usvl_closed_chk', 'usvl_status_chk']],
            'sesión: 301 s' => [self::SESSIONS, ['max_seconds' => 301], ['usvl_limits_chk']],
            'sesión: 59 s' => [self::SESSIONS, ['max_seconds' => 59], ['usvl_limits_chk']],
            'sesión: reserva negativa' => [self::SESSIONS, ['reserved_micros' => -1], ['usvl_limits_chk']],
            'sesión: terminada sin fin' => [self::SESSIONS, ['status' => 'ended', 'end_reason' => 'user_ended'], ['usvl_closed_chk']],
            'sesión: abierta con motivo' => [self::SESSIONS, ['end_reason' => 'user_ended'], ['usvl_closed_chk']],
            'sesión: motivo en texto libre' => [self::SESSIONS, ['status' => 'ended', 'ended_at' => '2026-11-03 12:00:00', 'end_reason' => 'Se cortó'], ['usvl_closed_chk']],
            'sesión: veredicto desconocido' => [self::SESSIONS, ['status' => 'ended', 'ended_at' => '2026-11-03 12:00:00', 'end_reason' => 'user_ended',
                'verdict' => 'maybe', 'verdict_by_admin_id' => 2, 'verdict_at' => '2026-11-03 12:00:00'], ['usvl_verdict_chk']],
            'sesión: veredicto sin quién' => [self::SESSIONS, ['status' => 'ended', 'ended_at' => '2026-11-03 12:00:00', 'end_reason' => 'user_ended',
                'verdict' => 'approved', 'verdict_at' => '2026-11-03 12:00:00'], ['usvl_verdict_chk']],
            'sesión: quién sin veredicto' => [self::SESSIONS, ['verdict_by_admin_id' => 2], ['usvl_verdict_chk']],
            'sesión: rechazo sin nota' => [self::SESSIONS, ['status' => 'ended', 'ended_at' => '2026-11-03 12:00:00', 'end_reason' => 'user_ended',
                'verdict' => 'rejected', 'verdict_by_admin_id' => 2, 'verdict_at' => '2026-11-03 12:00:00'], ['usvl_verdict_chk']],
            'sesión: la juzga quien la abrió' => [self::SESSIONS, ['status' => 'ended', 'ended_at' => '2026-11-03 12:00:00', 'end_reason' => 'user_ended',
                'verdict' => 'approved', 'verdict_by_admin_id' => 1, 'verdict_at' => '2026-11-03 12:00:00'], ['usvl_four_eyes_chk']],
        ];
    }

    /** @param  array<string, mixed>  $cambio  @param  list<string>  $esperados */
    #[DataProvider('badRows')]
    public function test_solo_en_postgres_cada_check_de_forma(string $tabla, array $cambio, array $esperados): void
    {
        $this->requirePostgres('los CHECK solo existen en PostgreSQL (en SQLite los vigila el servicio, salvo los cuatro ojos y seq >= 1).');

        $base = $this->validRow($tabla);
        $this->assertSame([], $this->violatedChecks($tabla, $base), "{$tabla}: la fila de partida es válida");
        $fila = array_merge($base, $cambio);
        $this->assertSame($esperados, $this->violatedChecks($tabla, $fila));

        DB::beginTransaction();
        try {
            DB::statement("ALTER TABLE {$tabla} DISABLE TRIGGER USER");
            $this->assertRejected(fn () => DB::table($tabla)->insert($fila), '23514', $esperados[0]);
        } finally {
            DB::rollBack();
        }
    }

    // ── El rol de la aplicación (solo PG) ───────────────────────────────────

    public function test_el_rol_de_la_aplicacion_es_iron_app_salvo_que_el_entorno_diga_otro(): void
    {
        $m = $this->migration();
        putenv(self::APP_ROLE_ENV);
        $this->assertSame('iron_app', $m::appRole());
        putenv(self::APP_ROLE_ENV.'=h5_app');
        $this->assertSame('h5_app', $m::appRole());
        foreach (['Iron_App', 'iron-app', 'iron_app"; DROP TABLE x; --', '1iron', str_repeat('a', 64)] as $malo) {
            putenv(self::APP_ROLE_ENV.'='.$malo);
            try {
                $m::appRole();
                $this->fail("Aceptó un nombre de rol inválido: {$malo}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no es un nombre de rol válido', $e->getMessage());
            }
        }
    }

    public function test_solo_en_postgres_la_aplicacion_pierde_update_y_delete_en_la_cadena_de_voz(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');

        $version = $this->insertVersion();   // la versión es de F: la inserta el dueño, como en producción
        $this->withAppRole(function (Migration $m) use ($version): void {
            $m->down();
            $m->up();

            $this->assertSame([true, true, false, false, false], $this->privileges(self::PUBLICATIONS), 'la cadena');
            $this->assertTrue((bool) DB::scalar("SELECT has_sequence_privilege(?, ?, 'USAGE')::int", [self::APP_ROLE, 'public.'.self::PUBLICATIONS.'_id_seq']));
            foreach ([self::PROFILES, self::AGENTS, self::SESSIONS] as $t) {
                $this->assertSame([true, true, true, true, false], $this->privileges($t), "{$t}: conserva UPDATE (su máquina de estados)");
            }

            $this->assertNull($this->asApp(function () use ($version): void {
                $perfil = $this->insertProfile();
                $this->submit($perfil);
                $this->approve($perfil, 2);
                $agente = $this->insertAgent(['voice_profile_id' => $perfil, 'studio_version_id' => $version]);
                $this->deploy($agente);
                DB::table(self::AGENTS)->where('id', $agente)->update(['serves_canary' => true]);
                $uno = $this->insertPublication(1, null, ['agent_id' => $agente]);
                $this->insertPublication(2, $uno, ['action' => 'off', 'agent_id' => null, 'reason' => 'Apagada']);
                $lab = $this->insertAgent(['purpose' => 'lab', 'provider' => 'fake', 'voice_profile_id' => $perfil, 'studio_version_id' => $version]);
                $this->deploy($lab);
                $this->endSession($this->insertSession($lab));
                $this->assertSame([1, 2, 2, 1], array_map(static fn (string $t): int => DB::table($t)->count(), self::TABLES));
            })[0], 'el rol de la aplicación ya no inserta');

            foreach ([
                'UPDATE '.self::PUBLICATIONS.' SET reason = reason WHERE id = -1',
                'DELETE FROM '.self::PUBLICATIONS.' WHERE id = -1',
                'SELECT id FROM '.self::PUBLICATIONS.' WHERE id = -1 FOR UPDATE',
                'SELECT id FROM '.self::PUBLICATIONS.' WHERE id = -1 FOR SHARE',
                'TRUNCATE '.self::PUBLICATIONS,
                'TRUNCATE '.self::PROFILES,
                'ALTER TABLE '.self::PUBLICATIONS.' DISABLE TRIGGER usvpub_chain_next',
                'DROP TRIGGER usvpub_agents_for_calls ON '.self::PUBLICATIONS,
                'ALTER TABLE '.self::PROFILES.' DROP CONSTRAINT usvp_four_eyes_chk',
                'DROP INDEX usva_one_canary',
                'CREATE OR REPLACE FUNCTION ultron_studio_voice_publication_chain() RETURNS trigger LANGUAGE plpgsql AS $fn$ BEGIN RETURN NEW; END; $fn$',
            ] as $sql) {
                [$sqlstate, $mensaje] = $this->asApp(fn () => DB::statement($sql));
                $this->assertSame('42501', $sqlstate, "{$sql}: {$mensaje}");
            }
        });
    }

    public function test_solo_en_postgres_up_comprueba_que_el_rol_ya_no_puede_y_si_no_falla_sin_dejar_nada(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');

        $this->withAppRole(function (Migration $m): void {
            $m->down();
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT UPDATE ON TABLES TO PUBLIC');
            try {
                $m->up();
                $this->fail('up() dio por buena una aplicación que todavía puede modificar la cadena de voz');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('todavía puede UPDATE en ultron_studio_voice_publications', $e->getMessage());
            }
            foreach (self::TABLES as $tabla) {
                $this->assertFalse(Schema::hasTable($tabla), "up() dejó {$tabla}");
            }
            $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname IN ('ultron_studio_voice_publication_chain', 'ultron_studio_voice_publication_agents')"));
        });
    }

    public function test_solo_en_postgres_sin_el_rol_no_hay_nada_que_revocar_salvo_en_produccion(): void
    {
        $this->requirePostgres('los roles solo existen en PostgreSQL.');

        $m = $this->migration();
        $nadie = 'ultron_studio_h8_rol_inexistente';
        putenv(self::APP_ROLE_ENV.'='.$nadie);
        DB::beginTransaction();
        try {
            $m->down();
            $m->up();
            $this->assertContains('usvpub_chain_next', $this->triggersOn(self::PUBLICATIONS));
            $m->down();
            $this->app['env'] = 'production';
            try {
                $m->up();
                $this->fail('En producción, up() se calló un REVOKE que no podía aplicar');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString("el rol de la aplicación «{$nadie}» no existe en este clúster y APP_ENV es production", $e->getMessage());
            } finally {
                $this->app['env'] = 'testing';
            }
            $this->assertFalse(Schema::hasTable(self::PUBLICATIONS), 'up() dejó la mitad');
        } finally {
            DB::rollBack();
        }
        $this->assertContains('usvpub_chain_next', $this->triggersOn(self::PUBLICATIONS));
    }

    // ── down() ──────────────────────────────────────────────────────────────

    public function test_down_se_niega_con_una_fila_en_cualquiera_de_las_cuatro_tablas(): void
    {
        $m = $this->migration();
        $antes = $this->footprint();
        foreach ([
            'un perfil' => ['(1 perfiles de voz, 0 agentes, 0 entradas de la cadena de voz y 0 sesiones del laboratorio hablado)', fn () => $this->insertProfile()],
            'un agente' => ['(1 perfiles de voz, 1 agentes, 0 entradas', fn () => $this->insertAgent()],
            'una entrada de la cadena' => ['1 agentes, 1 entradas de la cadena de voz y 0 sesiones', fn () => $this->insertPublication(1, null)],
            'una sesión' => ['0 entradas de la cadena de voz y 1 sesiones del laboratorio hablado)', fn () => $this->insertSession($this->deployedAgent(['purpose' => 'lab', 'provider' => 'fake']))],
        ] as $que => [$recuento, $siembra]) {
            DB::beginTransaction();
            try {
                $siembra();
                try {
                    $m->down();
                    $this->fail("down() no se negó con {$que}");
                } catch (RuntimeException $e) {
                    $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                    $this->assertStringContainsString('ultron_studio (Hito 8): down() se niega a revertir el gobierno de la voz con datos', $e->getMessage(), $que);
                    $this->assertStringContainsString($recuento, $e->getMessage(), $que);
                }
                $this->assertSame($antes, $this->footprint(), "down() tocó el esquema con {$que}");
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_down_sin_filas_y_up_dejan_el_mismo_esquema_y_no_tocan_versiones(): void
    {
        $m = $this->migration();
        $h8 = $this->footprint();
        $versiones = $this->tableDefinition('ultron_studio_versions');

        DB::beginTransaction();
        try {
            $m->down();
            foreach (self::TABLES as $tabla) {
                $this->assertFalse(Schema::hasTable($tabla), $tabla);
            }
            $this->assertSame($versiones, $this->tableDefinition('ultron_studio_versions'), 'down() tocó versiones');
            if ($this->isPostgres()) {
                $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname IN ('ultron_studio_voice_publication_chain', 'ultron_studio_voice_publication_agents')"));
                $this->assertSame(self::FORBID_MD5, DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
            }
            $m->up();
            $this->assertSame($h8, $this->footprint(), 'up() no dejó lo mismo que la primera vez');
            $this->assertSame($versiones, $this->tableDefinition('ultron_studio_versions'), 'up() tocó versiones (SQLite no la reconstruye)');
            $this->assertGuarded(fn () => $this->insertPublication(2, null), self::CHAIN, 'las guardas vuelven');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($h8, $this->footprint());
    }

    // ── Filas ───────────────────────────────────────────────────────────────

    private function migration(): Migration
    {
        return $this->migration ??= require database_path('migrations/'.self::MIGRATION.'.php');
    }

    private function hex(string $etiqueta): string
    {
        return hash('sha256', 'h8-studio-'.$etiqueta);
    }

    private function insertVersion(): int
    {
        return (int) DB::table('ultron_studio_versions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'label' => 'Sembrada por la prueba del Hito 8',
            'status' => 'APPROVED',
            'content' => '{}',
            'content_hash' => str_repeat('a', 64),
            'catalog_version' => '2026-10-19.1',
            'catalog_hash' => str_repeat('b', 64),
            'source_draft_revision' => 1,
            'created_by_admin_id' => 3,
            'created_by_name' => 'Caro',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Un perfil `draft` de Ana (1), con un destino de transferencia cifrado. @param  array<string, mixed>  $o */
    private function insertProfile(array $o = []): int
    {
        $n = ++$this->n;

        return (int) DB::table(self::PROFILES)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'revision' => 1,
            'based_on_profile_id' => null,
            'label' => "Perfil {$n}",
            'language' => 'es',
            'max_duration_seconds' => 300,
            'retention_days' => 30,
            'record_calls' => false,
            'record_voice' => false,
            'transfer_count' => 1,
            'transfer_destinations_encrypted' => 'cifrado-de-prueba',
            'content' => '{"voiceId":"voz_prueba","slots":{"saludo":"Hola"},"tools":["consultar_planes"],"transfers":[{"alias":"Recepción"}]}',
            'content_hash' => $this->hex("perfil-{$n}"),
            'status' => 'draft',
            'created_by_admin_id' => 1,
            'created_by_name' => 'Ana',
            'created_by_role' => 'Super Admin',
            'created_at' => now(),
            'status_changed_at' => now(),
            'updated_at' => now(),
        ], $o));
    }

    private function submit(int $id): int
    {
        return DB::table(self::PROFILES)->where('id', $id)->update(['status' => 'submitted', 'submitted_by_admin_id' => 1, 'submitted_by_name' => 'Ana', 'submitted_at' => now(), 'status_changed_at' => now()]);
    }

    private function approve(int $id, int $quien): int
    {
        return DB::table(self::PROFILES)->where('id', $id)->update(['status' => 'approved', 'reviewed_by_admin_id' => $quien, 'reviewed_by_name' => $quien === 1 ? 'Ana' : 'Beto', 'reviewed_at' => now(), 'status_changed_at' => now()]);
    }

    private function reject(int $id, int $quien): int
    {
        return DB::table(self::PROFILES)->where('id', $id)->update(['status' => 'rejected', 'reviewed_by_admin_id' => $quien, 'reviewed_by_name' => $quien === 1 ? 'Ana' : 'Beto',
            'reviewed_at' => now(), 'review_note' => 'Falta el aviso', 'status_changed_at' => now()]);
    }

    /** Un agente de publicación `pending` compilado de una versión y un perfil nuevos. @param  array<string, mixed>  $o */
    private function insertAgent(array $o = []): int
    {
        $n = ++$this->n;

        return (int) DB::table(self::AGENTS)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'purpose' => 'publication',
            'studio_version_id' => $o['studio_version_id'] ?? $this->insertVersion(),
            'studio_content_hash' => str_repeat('a', 64),
            'voice_profile_id' => $o['voice_profile_id'] ?? $this->insertProfile(),
            'voice_profile_hash' => $this->hex("perfil-del-agente-{$n}"),
            'compiler_version' => 'ultron-voice-compiler/1',
            'spec_hash' => $this->hex("spec-{$n}"),
            'payload_hash' => $this->hex("payload-{$n}"),
            'projection_hash' => hash('sha256', 'x'),
            'provider' => 'elevenlabs',
            'provider_agent_id' => null,
            'observed_hash' => null,
            'observed_at' => null,
            'status' => 'pending',
            'failure_code' => null,
            'serves_canary' => false,
            'serves_rest' => false,
            'created_by_admin_id' => 2,
            'created_by_name' => 'Beto',
            'created_by_role' => 'Super Admin',
            'created_at' => now(),
            'status_changed_at' => now(),
            'updated_at' => now(),
        ], $o));
    }

    /** pending → deployed: el id del proveedor y la proyección observada = la compilada. */
    private function deploy(int $id): int
    {
        $agente = DB::table(self::AGENTS)->where('id', $id)->first();

        return DB::table(self::AGENTS)->where('id', $id)->update(array_filter([
            'status' => 'deployed',
            'provider_agent_id' => $agente->provider_agent_id === null ? 'agent_'.$agente->uuid : null,
            'observed_hash' => $agente->projection_hash,
            'observed_at' => now(),
            'status_changed_at' => now(),
        ], static fn ($v): bool => $v !== null));
    }

    /** @param  array<string, mixed>  $o */
    private function deployedAgent(array $o = []): int
    {
        $id = $this->insertAgent($o);
        $this->deploy($id);

        return $id;
    }

    /**
     * Una entrada de la cadena, por defecto un `publish` del canario con un
     * agente de publicación desplegado nuevo. El mensaje es el de E1-4 (JSON
     * canónico: claves ordenadas, sin espacios), `prev_hash` la huella de la
     * entrada `$previous` (64 ceros sin ella) y `effective_hash` el sha256 de
     * los bytes. La firma es basura: aquí se prueba la base, no la firma.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function publicationRow(int $seq, ?int $previous, array $o = []): array
    {
        $agente = array_key_exists('agent_id', $o) ? $o['agent_id'] : $this->deployedAgent();
        $prev = $o['prev_hash'] ?? ($previous === null ? self::GENESIS : (string) (DB::table(self::PUBLICATIONS)->where('id', $previous)->value('effective_hash') ?? self::GENESIS));
        $mensaje = [
            'actor_id' => 2,
            'agent_config_hash' => $this->hex("config-{$seq}"),
            'prev_hash' => $prev,
            'profile_uuid' => $agente === null ? null : '00000000-0000-4000-8000-'.str_pad((string) $agente, 12, '0', STR_PAD_LEFT),
            'provider_agent_id' => $agente === null ? null : DB::table(self::AGENTS)->where('id', $agente)->value('provider_agent_id'),
            'published_at' => '2026-11-03T23:00:00Z',
            'scope' => $o['scope'] ?? 'canary',
            'seq' => $seq,
            'spec_hash' => $agente === null ? null : DB::table(self::AGENTS)->where('id', $agente)->value('spec_hash'),
            'version_uuid' => null,
        ];
        $json = (string) json_encode($mensaje, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'seq' => $seq,
            'client_request_id' => (string) Str::uuid(),
            'action' => 'publish',
            'scope' => 'canary',
            'previous_publication_id' => $previous,
            'prev_hash' => $prev,
            'restores_publication_id' => null,
            'agent_id' => $agente,
            'effective_json' => $json,
            'effective_hash' => hash('sha256', $json),
            'signature' => str_repeat('c', 64),
            'lab_gate' => '{"mode":"required"}',
            'evidence' => null,
            'reason' => null,
            'actor_admin_id' => 2,
            'actor_name' => 'Beto',
            'actor_role' => 'Super Admin',
            'created_at' => now(),
        ], array_diff_key($o, ['prev_hash' => true]), ['prev_hash' => $prev]);
    }

    /** @param  array<string, mixed>  $o */
    private function insertPublication(int $seq, ?int $previous, array $o = []): int
    {
        return (int) DB::table(self::PUBLICATIONS)->insertGetId($this->publicationRow($seq, $previous, $o));
    }

    /** Una sesión `open` de Ana (1) sobre `$agentId`. @param  array<string, mixed>  $o */
    private function insertSession(int $agentId, array $o = []): int
    {
        return (int) DB::table(self::SESSIONS)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'agent_id' => $agentId,
            'status' => 'open',
            'budget_day' => '2026-11-03',
            'reserved_micros' => 400000,
            'max_seconds' => 300,
            'url_expires_at' => now()->addMinutes(15),
            'created_by_admin_id' => 1,
            'created_by_name' => 'Ana',
            'created_by_role' => 'Super Admin',
            'created_at' => now(),
            'status_changed_at' => now(),
            'updated_at' => now(),
        ], $o));
    }

    private function endSession(int $id): int
    {
        return DB::table(self::SESSIONS)->where('id', $id)->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'user_ended', 'duration_seconds' => 120, 'status_changed_at' => now()]);
    }

    /** @return array<string, mixed> La fila válida de cada tabla, sin insertarla. */
    private function validRow(string $tabla): array
    {
        DB::beginTransaction();
        try {
            $id = match ($tabla) {
                self::PROFILES => $this->insertProfile(),
                self::AGENTS => $this->insertAgent(),
                self::PUBLICATIONS => $this->insertPublication(1, null),
                self::SESSIONS => $this->insertSession($this->deployedAgent(['purpose' => 'lab', 'provider' => 'fake'])),
            };
            $fila = (array) DB::table($tabla)->where('id', $id)->first();
        } finally {
            DB::rollBack();
        }
        unset($fila['id']);

        return $fila;
    }

    // ── Aserciones ──────────────────────────────────────────────────────────

    private function assertGuarded(Closure $sentencia, string $mensaje, string $que = ''): void
    {
        $this->assertRejected($sentencia, $this->isPostgres() ? 'P0001' : '23000', self::PREFIX.$mensaje, $que);
    }

    /** @param  list<string>  $columnas */
    private function assertUniqueRejects(Closure $sentencia, string $indice, string $tabla, array $columnas, string $que = ''): void
    {
        $this->isPostgres()
            ? $this->assertRejected($sentencia, '23505', $indice, $que)
            : $this->assertRejected($sentencia, '23000', 'UNIQUE constraint failed: '.implode(', ', array_map(static fn (string $c): string => "{$tabla}.{$c}", $columnas)), $que);
    }

    private function assertRejected(Closure $sentencia, string $sqlstate, string $fragmento, string $que = ''): void
    {
        try {
            DB::transaction(fn () => $sentencia());
            $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}) {$que}");
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, (string) $e->getCode(), $que.': '.$e->getMessage());
            $this->assertStringContainsString($fragmento, $e->getMessage(), $que);
        }
    }

    private function assertAccepted(Closure $sentencia, string $que = ''): void
    {
        try {
            DB::transaction(fn () => $sentencia());
        } catch (QueryException $e) {
            $this->fail("La base rechazó lo que tenía que aceptar {$que}: ".$e->getMessage());
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Solo PG: qué CHECK de `$table` rechazan `$row`, evaluando la expresión
     * real de cada uno sobre una copia de las columnas sin restricciones.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function violatedChecks(string $table, array $row): array
    {
        DB::statement("CREATE TEMP TABLE h8_probe (LIKE {$table})");
        try {
            DB::table('h8_probe')->insert(['id' => 999999] + $row);
            $out = [];
            foreach (DB::select("SELECT conname, pg_get_expr(conbin, conrelid) AS expr FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass ORDER BY 1", [$table]) as $c) {
                if (DB::selectOne("SELECT ({$c->expr}) AS ok FROM h8_probe")->ok === false) {
                    $out[] = $c->conname;
                }
            }

            return $out;
        } finally {
            DB::statement('DROP TABLE pg_temp.h8_probe');
        }
    }

    // ── Catálogo ────────────────────────────────────────────────────────────

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function requirePostgres(string $porQue): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Solo PostgreSQL: '.$porQue);
        }
    }

    /** @return list<string> */
    private function triggersOn(string $table): array
    {
        if ($this->isPostgres()) {
            return array_map(static fn (object $f): string => $f->tgname, DB::select(
                'SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass ORDER BY 1', [$table]));
        }

        return DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->orderBy('name')->pluck('name')->all();
    }

    /** @return list<string> */
    private function checksOn(string $table): array
    {
        return array_map(static fn (object $f): string => $f->conname, DB::select(
            "SELECT conname FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass ORDER BY 1", [$table]));
    }

    private function countLike(string $que, string $patron): int
    {
        if (! $this->isPostgres()) {
            return DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'like', $patron)->count();
        }

        return $que === 'trigger'
            ? (int) DB::scalar('SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE ?', [$patron])
            : (int) DB::scalar("SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE ?", [$patron]);
    }

    /** @return list<string> todos los índices parciales de las tablas del Studio */
    private function studioPartialIndexes(): array
    {
        if ($this->isPostgres()) {
            $nombres = array_map(static fn (object $f): string => $f->relname, DB::select(
                "SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron\\_studio\\_%' AND i.indpred IS NOT NULL"));
        } else {
            $nombres = DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', 'like', 'ultron_studio_%')->where('sql', 'like', '% WHERE %')->pluck('name')->all();
        }
        sort($nombres, SORT_STRING);

        return $nombres;
    }

    /** @return array<string, string> índice parcial → predicado */
    private function partialPredicates(string $table): array
    {
        if ($this->isPostgres()) {
            $out = [];
            foreach (DB::select('SELECT c.relname AS nombre, pg_get_expr(i.indpred, i.indrelid) AS predicado, i.indisunique::int AS unico FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
                WHERE i.indrelid = ?::regclass AND i.indpred IS NOT NULL ORDER BY 1', [$table]) as $f) {
                $this->assertSame(1, (int) $f->unico, $f->nombre);
                $out[$f->nombre] = $f->predicado;
            }

            return $out;
        }
        $out = [];
        foreach (DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', $table)->orderBy('name')->get(['name', 'sql']) as $f) {
            if (is_string($f->sql) && str_contains($f->sql, ' WHERE ')) {
                $this->assertStringStartsWith('CREATE UNIQUE INDEX', $f->sql);
                $out[$f->name] = substr($f->sql, strpos($f->sql, ' WHERE ') + 7);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> todo lo de las cuatro tablas y sus funciones, para comparar antes y después */
    private function footprint(): array
    {
        if (! $this->isPostgres()) {
            return DB::table('sqlite_master')->where('tbl_name', 'like', 'ultron_studio_voice_%')->orderBy('type')->orderBy('name')
                ->get(['type', 'name', 'sql'])->map(static fn (object $o): string => "{$o->type} {$o->name}: {$o->sql}")->all();
        }

        $like = 'ultron\\_studio\\_voice\\_%';

        return [
            'columnas' => array_map(static fn (object $f): string => "{$f->table_name}.{$f->column_name} {$f->data_type} {$f->is_nullable} {$f->column_default}", DB::select(
                "SELECT table_name, column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name LIKE ? ORDER BY table_name, ordinal_position", [$like])),
            'indices' => array_map(static fn (object $f): string => $f->indexdef, DB::select("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename LIKE ? ORDER BY indexname", [$like])),
            'restricciones' => array_map(static fn (object $f): string => "{$f->conname} {$f->def}", DB::select(
                'SELECT k.conname, pg_get_constraintdef(k.oid) AS def FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE c.relname LIKE ? ORDER BY 1', [$like])),
            'disparadores' => array_map(static fn (object $f): string => $f->def, DB::select(
                'SELECT pg_get_triggerdef(t.oid) AS def FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE ? ORDER BY t.tgname', [$like])),
            'funciones' => array_map(static fn (object $f): string => "{$f->proname} ".md5($f->def), DB::select(
                "SELECT proname, pg_get_functiondef(oid) AS def FROM pg_proc WHERE proname IN ('ultron_studio_voice_publication_chain', 'ultron_studio_voice_publication_agents', 'ultron_studio_forbid') ORDER BY 1")),
            'acl' => array_map(static fn (object $f): string => "{$f->relname} {$f->acl}", DB::select(
                "SELECT relname, coalesce(relacl::text, '') AS acl FROM pg_class WHERE relname LIKE ? AND relkind = 'r' ORDER BY 1", [$like])),
        ];
    }

    /** @return array<string, mixed> una tabla sin sus disparadores: columnas e índices (SQLite: su `sqlite_master`) */
    private function tableDefinition(string $table): array
    {
        if (! $this->isPostgres()) {
            return DB::table('sqlite_master')->where('tbl_name', $table)->orderBy('type')->orderBy('name')->pluck('sql', 'name')->all();
        }

        return [
            'columnas' => array_map(static fn (array $c): string => $c['name'].' '.$c['type'].($c['nullable'] ? ' null' : ''), Schema::getColumns($table)),
            'indices' => array_map(static fn (object $f): string => $f->indexdef, DB::select('SELECT indexdef FROM pg_indexes WHERE tablename = ? ORDER BY indexname', [$table])),
            'disparadores' => array_map(static fn (object $f): string => $f->tgname, DB::select('SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass ORDER BY 1', [$table])),
        ];
    }

    // ── El modelo dueño/app (solo PG) ───────────────────────────────────────

    /** @param  Closure(Migration): void  $fn */
    private function withAppRole(Closure $fn): void
    {
        DB::beginTransaction();
        try {
            DB::statement('DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = \''.self::APP_ROLE.'\') THEN CREATE ROLE '.self::APP_ROLE.' NOLOGIN NOSUPERUSER NOINHERIT; END IF; END $$');
            DB::statement('GRANT USAGE ON SCHEMA public TO '.self::APP_ROLE);
            // Versiones es de F: la aplicación la lee y escribe con los privilegios por defecto (arwd).
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ultron_studio_versions TO '.self::APP_ROLE);
            DB::statement('GRANT USAGE, SELECT, UPDATE ON ultron_studio_versions_id_seq TO '.self::APP_ROLE);
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO '.self::APP_ROLE);
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO '.self::APP_ROLE);
            putenv(self::APP_ROLE_ENV.'='.self::APP_ROLE);
            $fn($this->migration());
        } finally {
            putenv(self::APP_ROLE_ENV);
            DB::rollBack();
        }
    }

    /** @return list<bool> SELECT, INSERT, UPDATE (tabla o columna), DELETE y TRUNCATE del rol de prueba. */
    private function privileges(string $table): array
    {
        $p = DB::selectOne("SELECT has_table_privilege(?, ?, 'SELECT')::int AS s, has_table_privilege(?, ?, 'INSERT')::int AS i,
                has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d, has_table_privilege(?, ?, 'TRUNCATE')::int AS t",
            array_merge(...array_fill(0, 5, [self::APP_ROLE, "public.{$table}"])));

        return [(int) $p->s === 1, (int) $p->i === 1, (int) $p->u === 1, (int) $p->d === 1, (int) $p->t === 1];
    }

    /** @return array{0:?string,1:string} */
    private function asApp(Closure $fn): array
    {
        $deshacer = new RuntimeException('deshecho');
        try {
            DB::transaction(function () use ($fn, $deshacer): void {
                DB::statement('SET LOCAL ROLE '.self::APP_ROLE);
                $this->assertSame(self::APP_ROLE, DB::scalar('SELECT current_user'));
                $fn();

                throw $deshacer;
            });
        } catch (QueryException $e) {
            $this->assertNotSame(self::APP_ROLE, DB::scalar('SELECT current_user'), 'el rol no se deshizo');

            return [(string) ($e->getPrevious()?->errorInfo[0] ?? $e->getCode()), $e->getMessage()];
        } catch (RuntimeException $e) {
            if ($e === $deshacer) {
                return [null, 'deshecho'];
            }

            throw $e;
        }

        return [null, 'sin deshacer'];
    }
}
