<?php

namespace Tests\Feature\Telephony\Schema;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * EL ESQUEMA DE LA TELEFONÍA (Hito 8, paquete DB8; migración 2026_11_10_100002):
 * las siete tablas `voice_*` de «Contratos de datos», sus guardas, sus
 * fronteras y su vuelta atrás, en los dos motores (la suite en SQLite y la
 * puerta `docs/ultron-studio/ops/pgunit.sh` en PostgreSQL).
 *
 *  · Columnas, tipos y nulos; ninguna guarda un teléfono en claro, una
 *    transcripción ni audio (D8-17).
 *  · Recuentos y nombres: PG 27 disparadores, 51 CHECK y 3 índices únicos
 *    parciales; SQLite 21 disparadores y los mismos 3 parciales, con el mismo
 *    WHERE.
 *  · La función de guarda es PROPIA (`voice_forbid()`, «voice: …»), no la de F,
 *    y la de F no cambia; la migración no nombra el Studio ni el motor y sus
 *    FKs no salen de `voice_*` (D8-2, protección 5).
 *  · down() se niega con una fila en cualquiera de las siete tablas y, sin
 *    ellas, up() deja el mismo esquema.
 *  · En PG, con el modelo dueño/app reproducido por PRIVILEGIOS POR DEFECTO
 *    (como `iron` → `iron_app`): la aplicación pierde UPDATE y DELETE en las
 *    cinco de solo inserción; la comprobación posterior y la guarda de
 *    producción.
 *
 * Las transiciones de `voice_calls` están en VoiceCallTransitionsTest; las
 * guardas de cada tabla del registro, en VoiceLedgerSchemaTest.
 */
class VoiceSchemaTest extends TestCase
{
    use RefreshDatabase;
    use VoiceSchemaFixtures;

    private const VOICE_CONTACTS_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'phone_hash' => ['character(64)', 'varchar', false],
        'phone_last4' => ['character(4)', 'varchar', false],
        'phone_encrypted' => ['text', 'text', true],
        'source_type' => ['character varying(8)', 'varchar', false],
        'source_id' => ['bigint', 'integer', true],
        'status' => ['character varying(10)', 'varchar', false],
        'consent_request_source' => ['character varying(32)', 'varchar', true],
        'consent_request_evidence' => ['character varying(300)', 'varchar', true],
        'consent_requested_by_admin_id' => ['bigint', 'integer', true],
        'consent_requested_by_name' => ['character varying(120)', 'varchar', true],
        'consent_requested_by_role' => ['character varying(60)', 'varchar', true],
        'consent_requested_at' => ['timestamp(0) without time zone', 'datetime', true],
        'registered_by_admin_id' => ['bigint', 'integer', false],
        'registered_by_name' => ['character varying(120)', 'varchar', false],
        'registered_by_role' => ['character varying(60)', 'varchar', true],
        'retired_by_admin_id' => ['bigint', 'integer', true],
        'retired_by_name' => ['character varying(120)', 'varchar', true],
        'retired_by_role' => ['character varying(60)', 'varchar', true],
        'retired_at' => ['timestamp(0) without time zone', 'datetime', true],
        'retire_reason' => ['character varying(500)', 'varchar', true],
        'status_changed_at' => ['timestamp(0) without time zone', 'datetime', false],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const VOICE_RNE_CHECKS_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', true],
        'phone_hash' => ['character(64)', 'varchar', false],
        'contact_id' => ['bigint', 'integer', true],
        'checked_at' => ['timestamp(0) without time zone', 'datetime', false],
        'result' => ['character varying(12)', 'varchar', false],
        'method' => ['character varying(16)', 'varchar', false],
        'evidence_ref' => ['character varying(120)', 'varchar', false],
        'actor_type' => ['character varying(8)', 'varchar', false],
        'actor_admin_id' => ['bigint', 'integer', true],
        'actor_name' => ['character varying(120)', 'varchar', false],
        'actor_role' => ['character varying(60)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    private const VOICE_CALLS_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'call_ref' => ['uuid', 'varchar', false],
        'direction' => ['character varying(8)', 'varchar', false],
        'status' => ['character varying(16)', 'varchar', false],
        'target_source' => ['character varying(24)', 'varchar', true],
        'canary_number_uuid' => ['uuid', 'varchar', true],
        'voice_contact_id' => ['bigint', 'integer', true],
        'remote_hash' => ['character(64)', 'varchar', true],
        'remote_last4' => ['character(4)', 'varchar', true],
        'caller_authorized' => ['boolean', 'tinyint(1)', true],
        'policy_reason' => ['character varying(48)', 'varchar', true],
        'client_request_id' => ['uuid', 'varchar', true],
        'requested_by_type' => ['character varying(8)', 'varchar', true],
        'requested_by_admin_id' => ['bigint', 'integer', true],
        'requested_by_name' => ['character varying(120)', 'varchar', true],
        'requested_by_role' => ['character varying(60)', 'varchar', true],
        'attempt' => ['smallint', 'integer', false],
        'parent_call_id' => ['bigint', 'integer', true],
        'transfer_of_call_id' => ['bigint', 'integer', true],
        'voice_publication_seq' => ['integer', 'integer', true],
        'part' => ['character varying(8)', 'varchar', true],
        'stamp_state' => ['character varying(10)', 'varchar', false],
        'studio_version_uuid' => ['uuid', 'varchar', true],
        'studio_content_hash' => ['character(64)', 'varchar', true],
        'voice_profile_hash' => ['character(64)', 'varchar', true],
        'spec_hash' => ['character(64)', 'varchar', true],
        'payload_hash' => ['character(64)', 'varchar', true],
        'compiler_version' => ['character varying(48)', 'varchar', true],
        'provider' => ['character varying(16)', 'varchar', false],
        'provider_agent_id' => ['character varying(128)', 'varchar', true],
        'provider_conversation_id' => ['character varying(128)', 'varchar', true],
        'provider_call_sid' => ['character varying(34)', 'varchar', true],
        'observed_agent_id' => ['character varying(128)', 'varchar', true],
        'observed_version_id' => ['character varying(128)', 'varchar', true],
        'observed_branch_id' => ['character varying(128)', 'varchar', true],
        'seal_verified' => ['boolean', 'tinyint(1)', true],
        'budget_day' => ['date', 'date', false],
        'reserved_micros' => ['bigint', 'integer', false],
        'provider_cost_micros' => ['bigint', 'integer', true],
        'carrier_cost_micros' => ['bigint', 'integer', true],
        'duration_seconds' => ['integer', 'integer', true],
        'termination_reason' => ['character varying(48)', 'varchar', true],
        'failure_code' => ['character varying(48)', 'varchar', true],
        'tool_calls' => ['smallint', 'integer', false],
        'started_at' => ['timestamp(0) without time zone', 'datetime', true],
        'ended_at' => ['timestamp(0) without time zone', 'datetime', true],
        'status_changed_at' => ['timestamp(0) without time zone', 'datetime', false],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const VOICE_CALL_EVENTS_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'call_id' => ['bigint', 'integer', true],
        'provider' => ['character varying(16)', 'varchar', false],
        'kind' => ['character varying(32)', 'varchar', false],
        'provider_event_key' => ['character varying(128)', 'varchar', true],
        'tool' => ['character varying(32)', 'varchar', true],
        'outcome' => ['character varying(32)', 'varchar', true],
        'codes' => ['json', 'text', true],
        'actor_type' => ['character varying(8)', 'varchar', false],
        'actor_admin_id' => ['bigint', 'integer', true],
        'actor_name' => ['character varying(120)', 'varchar', true],
        'actor_role' => ['character varying(60)', 'varchar', true],
        'occurred_at' => ['timestamp(0) without time zone', 'datetime', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    private const VOICE_CONSENTS_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', true],
        'contact_id' => ['bigint', 'integer', false],
        'kind' => ['character varying(8)', 'varchar', false],
        'channel' => ['character varying(16)', 'varchar', false],
        'purpose' => ['character varying(16)', 'varchar', false],
        'source' => ['character varying(32)', 'varchar', false],
        'evidence' => ['character varying(300)', 'varchar', false],
        'actor_type' => ['character varying(8)', 'varchar', false],
        'recorded_by_admin_id' => ['bigint', 'integer', true],
        'recorded_by_name' => ['character varying(120)', 'varchar', false],
        'recorded_by_role' => ['character varying(60)', 'varchar', true],
        'recorded_at' => ['timestamp(0) without time zone', 'datetime', false],
        'confirmed_by_admin_id' => ['bigint', 'integer', true],
        'confirmed_by_name' => ['character varying(120)', 'varchar', true],
        'confirmed_by_role' => ['character varying(60)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    private const VOICE_EXCLUSIONS_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', true],
        'phone_hash' => ['character(64)', 'varchar', false],
        'phone_last4' => ['character(4)', 'varchar', false],
        'source' => ['character varying(24)', 'varchar', false],
        'call_id' => ['bigint', 'integer', true],
        'contact_id' => ['bigint', 'integer', true],
        'rne_check_id' => ['bigint', 'integer', true],
        'note' => ['character varying(300)', 'varchar', true],
        'actor_type' => ['character varying(8)', 'varchar', false],
        'actor_admin_id' => ['bigint', 'integer', true],
        'actor_name' => ['character varying(120)', 'varchar', false],
        'actor_role' => ['character varying(60)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    private const VOICE_BRAKES_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'kind' => ['character varying(8)', 'varchar', false],
        'brake_id' => ['bigint', 'integer', true],
        'reason' => ['character varying(32)', 'varchar', true],
        'open_key' => ['character varying(128)', 'varchar', true],
        'call_id' => ['bigint', 'integer', true],
        'event_id' => ['bigint', 'integer', true],
        'evidence' => ['json', 'text', true],
        'note' => ['character varying(500)', 'varchar', true],
        'client_request_id' => ['uuid', 'varchar', true],
        'actor_type' => ['character varying(8)', 'varchar', false],
        'actor_admin_id' => ['bigint', 'integer', true],
        'actor_name' => ['character varying(120)', 'varchar', false],
        'actor_role' => ['character varying(60)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    /** Los disparadores de cada tabla en PG; en SQLite, los mismos sin los de TRUNCATE, más los de SQLITE_ONLY. */
    private const PG_TRIGGERS = [
        'voice_contacts' => ['vct_cipher_only_shrinks', 'vct_forbid_delete', 'vct_forbid_immutable_update', 'vct_forbid_truncate', 'vct_retired_is_final'],
        'voice_rne_checks' => ['vrne_forbid_delete', 'vrne_forbid_truncate', 'vrne_forbid_update'],
        'voice_calls' => ['vc_bindings_once', 'vc_forbid_delete', 'vc_forbid_immutable_update', 'vc_forbid_truncate', 'vc_starts_live', 'vc_status_forward'],
        'voice_call_events' => ['vce_forbid_delete', 'vce_forbid_truncate', 'vce_forbid_update'],
        'voice_consents' => ['vcs_forbid_delete', 'vcs_forbid_truncate', 'vcs_forbid_update'],
        'voice_exclusions' => ['vex_forbid_delete', 'vex_forbid_truncate', 'vex_forbid_update'],
        'voice_brakes' => ['vbr_forbid_delete', 'vbr_forbid_truncate', 'vbr_forbid_update', 'vbr_release_targets_open'],
    ];

    /** Solo SQLite: el equivalente del CHECK de cuatro ojos. */
    private const SQLITE_ONLY_TRIGGERS = ['voice_consents' => ['vcs_four_eyes']];

    private const PG_CHECKS = [
        'voice_contacts' => ['vct_cipher_chk', 'vct_consent_request_chk', 'vct_phone_chk', 'vct_retire_chk', 'vct_source_chk', 'vct_status_chk'],
        'voice_rne_checks' => ['vrne_actor_chk', 'vrne_method_chk', 'vrne_phone_chk', 'vrne_result_chk', 'vrne_time_chk'],
        'voice_calls' => ['vc_attempt_chk', 'vc_authorization_chk', 'vc_codes_chk', 'vc_counters_chk', 'vc_direction_chk', 'vc_direction_status_chk',
            'vc_ended_chk', 'vc_hashes_chk', 'vc_provider_binding_chk', 'vc_provider_chk', 'vc_provider_ids_chk', 'vc_remote_chk', 'vc_requester_chk',
            'vc_seal_chk', 'vc_stamp_chk', 'vc_status_chk', 'vc_target_chk', 'vc_transfer_leg_chk'],
        'voice_call_events' => ['vce_actor_chk', 'vce_call_chk', 'vce_codes_chk', 'vce_kind_chk', 'vce_provider_chk', 'vce_tool_chk'],
        'voice_consents' => ['vcs_actor_chk', 'vcs_channel_chk', 'vcs_four_eyes_chk', 'vcs_kind_chk', 'vcs_purpose_chk', 'vcs_source_chk', 'vcs_time_chk'],
        'voice_exclusions' => ['vex_actor_chk', 'vex_phone_chk', 'vex_source_chk', 'vex_source_shape_chk'],
        'voice_brakes' => ['vbr_actor_chk', 'vbr_codes_chk', 'vbr_kind_chk', 'vbr_reason_chk', 'vbr_shape_chk'],
    ];

    /** Los índices únicos parciales y su WHERE, en cada motor (PG lo reescribe; SQLite lo guarda tal cual). */
    private const PARTIAL_INDEXES_PG = [
        'vc_one_live_outbound' => "(((direction)::text = 'outbound'::text) AND (transfer_of_call_id IS NULL) AND ((status)::text = ANY ((ARRAY['requested'::character varying, 'ambiguous'::character varying, 'dialing'::character varying, 'active'::character varying])::text[])))",
        'vct_one_live' => "((status)::text <> 'retired'::text)",
        'vex_one_opt_out_per_call' => "((source)::text = 'spoken_opt_out'::text)",
    ];

    private const PARTIAL_INDEXES_SQLITE = [
        'vc_one_live_outbound' => "direction = 'outbound' AND transfer_of_call_id IS NULL AND status IN ('requested', 'ambiguous', 'dialing', 'active')",
        'vct_one_live' => "status <> 'retired'",
        'vex_one_opt_out_per_call' => "source = 'spoken_opt_out'",
    ];

    /** md5 de `pg_get_functiondef` de la función de F: el mismo desde el H5. */
    private const FORBID_MD5 = '4ad748b5f17f09b398f7f641c197d7ee';

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

    // ── Columnas ────────────────────────────────────────────────────────────

    public function test_columnas_tipos_y_nulos_de_las_siete_tablas(): void
    {
        $motor = $this->isPostgres() ? 0 : 1;
        foreach ([
            'voice_contacts' => self::VOICE_CONTACTS_COLUMNS,
            'voice_rne_checks' => self::VOICE_RNE_CHECKS_COLUMNS,
            'voice_calls' => self::VOICE_CALLS_COLUMNS,
            'voice_call_events' => self::VOICE_CALL_EVENTS_COLUMNS,
            'voice_consents' => self::VOICE_CONSENTS_COLUMNS,
            'voice_exclusions' => self::VOICE_EXCLUSIONS_COLUMNS,
            'voice_brakes' => self::VOICE_BRAKES_COLUMNS,
        ] as $tabla => $esperadas) {
            $reales = [];
            foreach (Schema::getColumns($tabla) as $c) {
                $reales[$c['name']] = [$c['type'], (bool) $c['nullable']];
            }
            $this->assertSame(array_map(static fn (array $e): array => [$e[$motor], $e[2]], $esperadas), $reales, $tabla);
        }
        $voz = array_values(array_filter(Schema::getTableListing(schemaQualified: false), static fn (string $t): bool => str_starts_with($t, 'voice_')));
        sort($voz);
        $this->assertSame(self::sortedTables(), $voz, 'las tablas voice_* son exactamente las siete');
    }

    /**
     * D8-17: ningún teléfono en claro. Lo único que habla de un teléfono son
     * huellas de 64 hex, 4 últimos dígitos, el cifrado de un contacto y el uuid
     * del canario; no hay columnas de transcripción, audio ni grabación.
     */
    public function test_ninguna_columna_guarda_un_telefono_en_claro_ni_una_transcripcion(): void
    {
        $telefonos = [];
        $prohibidas = [];
        foreach (self::TABLES as $tabla) {
            foreach (Schema::getColumnListing($tabla) as $columna) {
                if (preg_match('/phone|remote|number|e164|msisdn|caller_id|to_number|from_number/', $columna) === 1) {
                    $telefonos[] = "{$tabla}.{$columna}";
                }
                if (preg_match('/transcript|audio|recording|text_|_text$|message/', $columna) === 1) {
                    $prohibidas[] = "{$tabla}.{$columna}";
                }
            }
        }
        sort($telefonos);
        $this->assertSame([
            'voice_calls.canary_number_uuid', 'voice_calls.remote_hash', 'voice_calls.remote_last4',
            'voice_contacts.phone_encrypted', 'voice_contacts.phone_hash', 'voice_contacts.phone_last4',
            'voice_exclusions.phone_hash', 'voice_exclusions.phone_last4',
            'voice_rne_checks.phone_hash',
        ], $telefonos);
        $this->assertSame([], $prohibidas);
    }

    // ── Recuentos, nombres y la función propia ──────────────────────────────

    public function test_recuentos_y_nombres_de_disparadores_check_e_indices_parciales(): void
    {
        $this->assertSame($this->isPostgres() ? self::PARTIAL_INDEXES_PG : self::PARTIAL_INDEXES_SQLITE, $this->voicePartialIndexes());

        if ($this->isPostgres()) {
            foreach (self::PG_TRIGGERS as $tabla => $nombres) {
                $this->assertSame($nombres, $this->triggersOn($tabla), $tabla);
            }
            foreach (self::PG_CHECKS as $tabla => $nombres) {
                $this->assertSame($nombres, $this->checksOn($tabla), $tabla);
            }
            $this->assertSame(27, $this->voiceTriggerCount(), 'contactos 5 + RNE 3 + llamadas 6 + eventos 3 + consentimientos 3 + exclusiones 3 + freno 4');
            $this->assertSame(51, $this->voiceCheckCount(), 'contactos 6 + RNE 5 + llamadas 18 + eventos 6 + consentimientos 7 + exclusiones 4 + freno 5');

            return;
        }

        foreach (self::PG_TRIGGERS as $tabla => $nombres) {
            $esperados = [...array_filter($nombres, static fn (string $n): bool => ! str_ends_with($n, '_truncate')), ...(self::SQLITE_ONLY_TRIGGERS[$tabla] ?? [])];
            sort($esperados);
            $this->assertSame($esperados, $this->triggersOn($tabla), $tabla);
        }
        $this->assertSame(21, $this->voiceTriggerCount(), 'contactos 4 + RNE 2 + llamadas 5 + eventos 2 + consentimientos 3 + exclusiones 2 + freno 3');
    }

    public function test_solo_en_postgres_la_funcion_de_guarda_es_propia_y_la_de_f_no_cambia(): void
    {
        $this->requirePostgres('las funciones de los disparadores solo existen en PostgreSQL.');

        foreach (['voice_forbid', 'voice_brake_release'] as $funcion) {
            $f = DB::selectOne('SELECT l.lanname AS lang, p.provolatile AS vol, p.prosecdef::int AS definer, pg_get_userbyid(p.proowner) AS owner, p.prosrc AS src
                FROM pg_proc p JOIN pg_language l ON l.oid = p.prolang WHERE p.proname = ?', [$funcion]);
            $this->assertSame(['plpgsql', 'v', 0, (string) DB::scalar('SELECT current_user')], [$f->lang, $f->vol, (int) $f->definer, $f->owner], $funcion);
            $this->assertStringContainsString("RAISE EXCEPTION 'voice: % (% en %)'", $f->src, $funcion);
        }
        $this->assertStringContainsString('FROM public.voice_brakes AS b', (string) DB::scalar("SELECT prosrc FROM pg_proc WHERE proname = 'voice_brake_release'"));

        // Cada disparador de `voice_*` usa una de las dos; ninguno, la de F.
        $funciones = DB::select("SELECT DISTINCT p.proname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_proc p ON p.oid = t.tgfoid
            WHERE NOT t.tgisinternal AND c.relname LIKE 'voice\\_%' ORDER BY 1");
        $this->assertSame(['voice_brake_release', 'voice_forbid'], array_map(static fn (object $f): string => $f->proname, $funciones));

        $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
        $this->assertSame(self::FORBID_MD5, DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
    }

    /**
     * D8-2 y protección 5: la telefonía no nombra el Studio ni el motor (en el
     * CÓDIGO de la migración: los comentarios no cuentan) y sus FKs no salen de
     * `voice_*`. El registro de canarios se nombra por su uuid, sin FK.
     */
    public function test_la_migracion_no_nombra_el_studio_ni_el_motor_y_sus_fk_no_salen_de_la_voz(): void
    {
        $codigo = '';
        foreach (token_get_all((string) file_get_contents(database_path('migrations/'.self::MIGRATION.'.php'))) as $t) {
            if (! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $codigo .= is_array($t) ? $t[1] : $t;
            }
        }
        foreach (['ultron_studio_', 'UltronStudio', 'marketing_', 'ultron_aborts', 'meta_', 'admins'] as $nombre) {
            $this->assertStringNotContainsString($nombre, $codigo, "la migración de la telefonía nombra {$nombre}");
        }

        $fuera = [];
        foreach (self::TABLES as $tabla) {
            foreach (Schema::getForeignKeys($tabla) as $fk) {
                if (! str_starts_with((string) $fk['foreign_table'], 'voice_')) {
                    $fuera[] = "{$tabla} → {$fk['foreign_table']}";
                }
                $this->assertSame('restrict', strtolower((string) $fk['on_delete']), "{$tabla}: ".implode(',', $fk['columns']));
            }
        }
        $this->assertSame([], $fuera);
    }

    // ── down() y la ida y vuelta ────────────────────────────────────────────

    public function test_down_se_niega_con_una_fila_en_cualquiera_de_las_siete_tablas_y_no_toca_nada(): void
    {
        $m = $this->voiceMigration();
        $antes = $this->voiceFootprint();

        foreach ([
            'un contacto' => ['(1 contactos, 0 consultas al RNE, 0 llamadas, 0 eventos, 0 consentimientos, 0 exclusiones y 0 filas del freno)', fn () => $this->insertContact()],
            'una consulta al RNE' => ['(0 contactos, 1 consultas al RNE, 0 llamadas', fn () => $this->insertRneCheck()],
            'una llamada' => ['(0 contactos, 0 consultas al RNE, 1 llamadas, 0 eventos', fn () => $this->insertOutbound()],
            'un evento' => ['0 llamadas, 1 eventos, 0 consentimientos', fn () => $this->insertEvent()],
            'un consentimiento' => ['(1 contactos, 0 consultas al RNE, 0 llamadas, 0 eventos, 1 consentimientos', fn () => $this->insertConsent($this->insertContact())],
            'una exclusión' => ['0 consentimientos, 1 exclusiones y 0 filas del freno', fn () => $this->insertExclusion()],
            'una apertura del freno' => ['0 exclusiones y 1 filas del freno)', fn () => $this->insertBrakeOpen()],
        ] as $que => [$recuento, $siembra]) {
            DB::beginTransaction();
            try {
                $siembra();
                try {
                    $m->down();
                    $this->fail("down() no se negó con {$que}");
                } catch (RuntimeException $e) {
                    $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                    $this->assertStringContainsString('voice (Hito 8): down() se niega a revertir la telefonía con datos', $e->getMessage(), $que);
                    $this->assertStringContainsString($recuento, $e->getMessage(), $que);
                }
                $this->assertSame($antes, $this->voiceFootprint(), "down() tocó el esquema con {$que}");
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_down_sin_filas_borra_las_siete_tablas_y_sus_funciones_y_up_deja_el_mismo_esquema(): void
    {
        $m = $this->voiceMigration();
        $h8 = $this->voiceFootprint();

        DB::beginTransaction();
        try {
            $m->down();
            foreach (self::TABLES as $tabla) {
                $this->assertFalse(Schema::hasTable($tabla), $tabla);
            }
            if ($this->isPostgres()) {
                $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname IN ('voice_forbid', 'voice_brake_release')"));
                $this->assertSame(self::FORBID_MD5, DB::scalar("SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid'"));
            }

            $m->up();
            $this->assertSame($h8, $this->voiceFootprint(), 'up() no dejó lo mismo que la primera vez');
            // Y las guardas vuelven a funcionar.
            $id = $this->insertOutbound();
            $this->assertGuarded(fn () => DB::table('voice_calls')->where('id', $id)->delete(), 'las llamadas no se borran (DELETE en voice_calls)');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($h8, $this->voiceFootprint());
    }

    // ── El rol de la aplicación (solo PG) ───────────────────────────────────

    public function test_el_rol_de_la_aplicacion_es_iron_app_salvo_que_el_entorno_diga_otro(): void
    {
        $m = $this->voiceMigration();
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

    public function test_solo_en_postgres_la_aplicacion_pierde_update_y_delete_en_las_cinco_de_solo_insercion(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');

        $this->withAppRole(function (Migration $m): void {
            $m->down();
            $m->up();   // las tablas nacen con arwd por los privilegios por defecto y el REVOKE se lo quita a las cinco

            foreach (self::INSERT_ONLY as $t) {
                // SELECT, INSERT, UPDATE (también por columna), DELETE, TRUNCATE.
                $this->assertSame([true, true, false, false, false], $this->privileges($t), $t);
                $this->assertTrue((bool) DB::scalar("SELECT has_sequence_privilege(?, ?, 'USAGE')::int", [self::APP_ROLE, "public.{$t}_id_seq"]), "{$t}: secuencia");
            }
            foreach (['voice_contacts', 'voice_calls'] as $t) {
                $this->assertSame([true, true, true, true, false], $this->privileges($t), "{$t}: conserva UPDATE (su máquina de estados) y DELETE (lo para el disparador)");
            }

            // Inserta en las siete y mueve contactos y llamadas.
            $this->assertNull($this->asApp(function (): void {
                $ids = $this->seedOneOfEach();
                $this->insertBrakeRelease($ids['freno']);
                DB::table('voice_calls')->where('id', $ids['llamada'])->update(['status' => 'canceled', 'policy_reason' => 'publication_moved', 'ended_at' => now()]);
                DB::table('voice_contacts')->where('id', $ids['contacto'])->update(['consent_request_source' => 'written_form', 'consent_request_evidence' => 'Formulario de prueba',
                    'consent_requested_by_admin_id' => 1, 'consent_requested_by_name' => 'Ana', 'consent_requested_at' => now()]);
                $this->assertSame([1, 1, 1, 1, 1, 1, 2], array_map(static fn (string $t): int => DB::table($t)->count(), self::TABLES));
            })[0], 'el rol de la aplicación ya no inserta');

            // Ni modifica ni borra (42501, antes que los disparadores), ni bloquea filas.
            foreach (self::INSERT_ONLY as $t) {
                foreach ([
                    "UPDATE {$t} SET created_at = created_at WHERE id = -1",
                    "DELETE FROM {$t} WHERE id = -1",
                    "SELECT id FROM {$t} WHERE id = -1 FOR UPDATE",
                    "SELECT id FROM {$t} WHERE id = -1 FOR SHARE",
                    "TRUNCATE {$t}",
                ] as $sql) {
                    [$sqlstate, $mensaje] = $this->asApp(fn () => DB::statement($sql));
                    $this->assertSame('42501', $sqlstate, "{$sql}: {$mensaje}");
                    $this->assertStringContainsString("permission denied for table {$t}", $mensaje, $sql);
                }
            }
            // En contactos y llamadas el DELETE lo para el disparador, y TRUNCATE no lo tiene.
            foreach (['voice_contacts', 'voice_calls'] as $t) {
                [$sqlstate, $mensaje] = $this->asApp(function () use ($t): void {
                    $this->insertContact();
                    $this->insertOutbound();
                    DB::statement("DELETE FROM {$t}");
                });
                $this->assertSame('P0001', $sqlstate, "{$t}: {$mensaje}");
                $this->assertSame('42501', $this->asApp(fn () => DB::statement("TRUNCATE {$t}"))[0], $t);
            }
            // Ni toca las guardas.
            foreach ([
                'ALTER TABLE voice_calls DISABLE TRIGGER vc_status_forward',
                'DROP TRIGGER vbr_release_targets_open ON voice_brakes',
                'ALTER TABLE voice_consents DROP CONSTRAINT vcs_four_eyes_chk',
                'DROP INDEX vc_one_live_outbound',
                'CREATE OR REPLACE FUNCTION voice_forbid() RETURNS trigger LANGUAGE plpgsql AS $fn$ BEGIN RETURN NEW; END; $fn$',
                'GRANT UPDATE ON voice_call_events TO '.self::APP_ROLE,
            ] as $sql) {
                if (str_starts_with($sql, 'GRANT')) {
                    // Sin GRANT OPTION, PG avisa (01007) y no concede nada.
                    $this->assertNull($this->asApp(function () use ($sql): void {
                        DB::statement($sql);
                        $this->assertSame([true, true, false, false, false], $this->privileges('voice_call_events'), $sql);
                    })[0], $sql);

                    continue;
                }
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
            // UPDATE por PUBLIC en cada tabla nueva: el REVOKE al rol no lo quita.
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT UPDATE ON TABLES TO PUBLIC');

            try {
                $m->up();
                $this->fail('up() dio por buena una aplicación que todavía puede modificar los eventos');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('todavía puede UPDATE en voice_call_events, UPDATE en voice_consents, UPDATE en voice_exclusions, UPDATE en voice_rne_checks, UPDATE en voice_brakes', $e->getMessage());
            }
            // up() es atómico: ni tablas ni funciones.
            foreach (self::TABLES as $tabla) {
                $this->assertFalse(Schema::hasTable($tabla), "up() dejó {$tabla}");
            }
            $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname IN ('voice_forbid', 'voice_brake_release')"));
        });
    }

    public function test_solo_en_postgres_sin_el_rol_no_hay_nada_que_revocar_salvo_en_produccion(): void
    {
        $this->requirePostgres('los roles solo existen en PostgreSQL.');

        $m = $this->voiceMigration();
        $nadie = 'ultron_studio_h8_rol_inexistente';
        $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM pg_roles WHERE rolname = ?', [$nadie]));
        putenv(self::APP_ROLE_ENV.'='.$nadie);

        DB::beginTransaction();
        try {
            $m->down();
            $m->up();   // en «testing»: nada que revocar, las guardas sí
            $this->assertContains('vc_status_forward', $this->triggersOn('voice_calls'));

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
            $this->assertFalse(Schema::hasTable('voice_calls'), 'up() dejó la mitad');
        } finally {
            DB::rollBack();
        }
        $this->assertContains('vc_status_forward', $this->triggersOn('voice_calls'));
    }

    /** @return list<string> */
    private static function sortedTables(): array
    {
        $t = self::TABLES;
        sort($t);

        return $t;
    }
}
