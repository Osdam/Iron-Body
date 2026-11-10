<?php

namespace Tests\Feature\Outreach\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * LA EJECUCIÓN DEL HITO 9 EN LA BASE (paquete DB9; migración
 * 2026_11_12_100002, `outreach_*`): columnas, recuentos, funciones, fronteras,
 * el rol de la aplicación, la guarda de producción y `down()`. Los estados y
 * las guardas de cada tabla, en `OutreachLifecycleTest` y
 * `OutreachLedgerSchemaTest`; las carreras, en `OutreachConcurrencyTest`.
 *
 * Recuentos (para OPS9): PG 51 disparadores, 75 CHECK, 3 parciales, 8 tablas y
 * 13 funciones `outreach_%` (ninguna SECURITY DEFINER); SQLite 46 disparadores
 * y 3 parciales.
 */
class OutreachSchemaTest extends TestCase
{
    use OutreachSchemaFixtures;
    use RefreshDatabase;

    private const COLUMNS_RUNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'kind' => ['character varying(10)', 'varchar', false],
        'mode' => ['character varying(8)', 'varchar', false],
        'strategy_id' => ['bigint', 'integer', true],
        'trigger_key' => ['character varying(64)', 'varchar', true],
        'status' => ['character varying(10)', 'varchar', false],
        'started_at' => ['timestamp(0) without time zone', 'datetime', false],
        'finished_at' => ['timestamp(0) without time zone', 'datetime', true],
        'counts' => ['json', 'text', true],
        'error_code' => ['character varying(48)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const COLUMNS_CURSORS = [
        'source' => ['character varying(96)', 'varchar', false],
        'last_id' => ['bigint', 'integer', false],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const COLUMNS_ENROLLMENTS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'strategy_id' => ['bigint', 'integer', false],
        'strategy_version_id' => ['bigint', 'integer', false],
        'content_hash' => ['character(64)', 'varchar', false],
        'publication_seq' => ['integer', 'integer', false],
        'part' => ['character varying(8)', 'varchar', false],
        'studio_ref_kind' => ['character varying(8)', 'varchar', false],
        'studio_version_uuid' => ['uuid', 'varchar', true],
        'studio_content_hash' => ['character(64)', 'varchar', false],
        'catalog_version' => ['character varying(48)', 'varchar', false],
        'evaluator_version' => ['character varying(48)', 'varchar', false],
        'mode' => ['character varying(8)', 'varchar', false],
        'subject_key' => ['character varying(24)', 'varchar', false],
        'subject_ref' => ['character(64)', 'varchar', false],
        'member_id' => ['bigint', 'integer', true],
        'lead_id' => ['bigint', 'integer', true],
        'cycle_key' => ['character varying(96)', 'varchar', false],
        'purpose' => ['character varying(12)', 'varchar', false],
        'status' => ['character varying(10)', 'varchar', false],
        'step_index' => ['smallint', 'integer', false],
        'touches' => ['smallint', 'integer', false],
        'next_due_at' => ['timestamp(0) without time zone', 'datetime', true],
        'enrolled_at' => ['timestamp(0) without time zone', 'datetime', false],
        'window_ends_at' => ['timestamp(0) without time zone', 'datetime', false],
        'last_outcome' => ['character varying(24)', 'varchar', true],
        'exit_reason' => ['character varying(48)', 'varchar', true],
        'ended_at' => ['timestamp(0) without time zone', 'datetime', true],
        'redacted_at' => ['timestamp(0) without time zone', 'datetime', true],
        'status_changed_at' => ['timestamp(0) without time zone', 'datetime', false],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const COLUMNS_JOBS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'enrollment_id' => ['bigint', 'integer', false],
        'strategy_id' => ['bigint', 'integer', false],
        'strategy_version_id' => ['bigint', 'integer', false],
        'content_hash' => ['character(64)', 'varchar', false],
        'publication_seq' => ['integer', 'integer', false],
        'part' => ['character varying(8)', 'varchar', false],
        'studio_ref_kind' => ['character varying(8)', 'varchar', false],
        'studio_version_uuid' => ['uuid', 'varchar', true],
        'studio_content_hash' => ['character(64)', 'varchar', false],
        'catalog_version' => ['character varying(48)', 'varchar', false],
        'evaluator_version' => ['character varying(48)', 'varchar', false],
        'mode' => ['character varying(8)', 'varchar', false],
        'subject_key' => ['character varying(24)', 'varchar', false],
        'subject_ref' => ['character(64)', 'varchar', false],
        'phone_ref' => ['character(64)', 'varchar', true],
        'step_index' => ['smallint', 'integer', false],
        'attempt' => ['smallint', 'integer', false],
        'channel' => ['character varying(20)', 'varchar', false],
        'medium' => ['character varying(10)', 'varchar', true],
        'template_version_id' => ['bigint', 'integer', true],
        'template_hash' => ['character(64)', 'varchar', true],
        'idempotency_key' => ['character(64)', 'varchar', false],
        'status' => ['character varying(16)', 'varchar', false],
        'not_before' => ['timestamp(0) without time zone', 'datetime', false],
        'expires_at' => ['timestamp(0) without time zone', 'datetime', false],
        'budget_day' => ['date', 'date', false],
        'reserved_units' => ['smallint', 'integer', false],
        'reserved_micros' => ['bigint', 'integer', false],
        'budget_released_at' => ['timestamp(0) without time zone', 'datetime', true],
        'cancel_reason' => ['character varying(48)', 'varchar', true],
        'claimed_at' => ['timestamp(0) without time zone', 'datetime', true],
        'marketing_message_id' => ['bigint', 'integer', true],
        'voice_call_id' => ['bigint', 'integer', true],
        'assisted_claimed_by_admin_id' => ['bigint', 'integer', true],
        'assisted_claimed_by_name' => ['character varying(120)', 'varchar', true],
        'assisted_claimed_at' => ['timestamp(0) without time zone', 'datetime', true],
        'redacted_at' => ['timestamp(0) without time zone', 'datetime', true],
        'status_changed_at' => ['timestamp(0) without time zone', 'datetime', false],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const COLUMNS_DECISIONS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'run_id' => ['bigint', 'integer', true],
        'enrollment_id' => ['bigint', 'integer', true],
        'job_id' => ['bigint', 'integer', true],
        'strategy_id' => ['bigint', 'integer', false],
        'strategy_version_id' => ['bigint', 'integer', false],
        'content_hash' => ['character(64)', 'varchar', false],
        'publication_seq' => ['integer', 'integer', false],
        'part' => ['character varying(8)', 'varchar', false],
        'studio_ref_kind' => ['character varying(8)', 'varchar', false],
        'studio_version_uuid' => ['uuid', 'varchar', true],
        'studio_content_hash' => ['character(64)', 'varchar', false],
        'catalog_version' => ['character varying(48)', 'varchar', false],
        'evaluator_version' => ['character varying(48)', 'varchar', false],
        'mode' => ['character varying(8)', 'varchar', false],
        'subject_key' => ['character varying(24)', 'varchar', false],
        'subject_ref' => ['character(64)', 'varchar', false],
        'kind' => ['character varying(10)', 'varchar', false],
        'level' => ['character varying(2)', 'varchar', true],
        'reasons' => ['json', 'text', false],
        'retry_at' => ['timestamp(0) without time zone', 'datetime', true],
        'facts_json' => ['json', 'text', false],
        'facts_hash' => ['character(64)', 'varchar', false],
        'trace_json' => ['json', 'text', false],
        'trace_hash' => ['character(64)', 'varchar', false],
        'template_version_id' => ['bigint', 'integer', true],
        'template_hash' => ['character(64)', 'varchar', true],
        'evaluated_at' => ['timestamp(0) without time zone', 'datetime', false],
        'redacted_at' => ['timestamp(0) without time zone', 'datetime', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    private const COLUMNS_OUTCOMES = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'job_id' => ['bigint', 'integer', true],
        'enrollment_id' => ['bigint', 'integer', false],
        'strategy_id' => ['bigint', 'integer', false],
        'subject_key' => ['character varying(24)', 'varchar', false],
        'subject_ref' => ['character(64)', 'varchar', false],
        'channel' => ['character varying(20)', 'varchar', true],
        'outcome' => ['character varying(24)', 'varchar', false],
        'source' => ['character varying(16)', 'varchar', false],
        'source_key' => ['character varying(96)', 'varchar', false],
        'deterministic' => ['boolean', 'tinyint(1)', false],
        'confidence' => ['numeric(4,3)', 'numeric', true],
        'detail_code' => ['character varying(48)', 'varchar', true],
        'reason_code' => ['character varying(64)', 'varchar', true],
        'next_action' => ['character varying(16)', 'varchar', true],
        'next_action_at' => ['timestamp(0) without time zone', 'datetime', true],
        'recorded_by_admin_id' => ['bigint', 'integer', true],
        'recorded_by_name' => ['character varying(120)', 'varchar', true],
        'occurred_at' => ['timestamp(0) without time zone', 'datetime', false],
        'redacted_at' => ['timestamp(0) without time zone', 'datetime', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    private const COLUMNS_BRAKES = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', true],
        'scope' => ['character varying(10)', 'varchar', false],
        'scope_ref' => ['character varying(36)', 'varchar', true],
        'reason' => ['character varying(20)', 'varchar', false],
        'note' => ['character varying(500)', 'varchar', true],
        'opened_by_type' => ['character varying(8)', 'varchar', false],
        'opened_by_admin_id' => ['bigint', 'integer', true],
        'opened_by_name' => ['character varying(120)', 'varchar', false],
        'opened_by_person_id' => ['bigint', 'integer', true],
        'opened_at' => ['timestamp(0) without time zone', 'datetime', false],
        'released_at' => ['timestamp(0) without time zone', 'datetime', true],
        'released_by_admin_id' => ['bigint', 'integer', true],
        'released_by_name' => ['character varying(120)', 'varchar', true],
        'released_by_person_id' => ['bigint', 'integer', true],
        'release_note' => ['character varying(500)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const COLUMNS_BUDGET_COUNTERS = [
        'id' => ['bigint', 'integer', false],
        'budget_day' => ['date', 'date', false],
        'scope' => ['character varying(80)', 'varchar', false],
        'mode' => ['character varying(8)', 'varchar', false],
        'cap_units' => ['integer', 'integer', false],
        'cap_micros' => ['bigint', 'integer', false],
        'reserved_units' => ['integer', 'integer', false],
        'reserved_micros' => ['bigint', 'integer', false],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    private const PG_TRIGGERS = [
        self::T_RUNS => ['orun_closed_is_final', 'orun_forbid_delete', 'orun_forbid_immutable_update', 'orun_forbid_truncate', 'orun_starts_running', 'orun_status_forward'],
        self::T_CURSORS => ['ocur_forbid_delete', 'ocur_forbid_immutable_update', 'ocur_forbid_truncate', 'ocur_last_id_forward'],
        self::T_ENROLLMENTS => ['oenr_closed_is_final', 'oenr_counters_forward', 'oenr_forbid_delete', 'oenr_forbid_immutable_update', 'oenr_forbid_truncate',
            'oenr_publication_of_strategy', 'oenr_purpose_matches_strategy', 'oenr_seal_matches_version', 'oenr_starts_open', 'oenr_status_forward'],
        self::T_JOBS => ['ojob_closed_is_final', 'ojob_forbid_delete', 'ojob_forbid_immutable_update', 'ojob_forbid_truncate', 'ojob_not_before_forward',
            'ojob_publication_of_strategy', 'ojob_refs_once', 'ojob_seal_matches_enrollment', 'ojob_starts_planned', 'ojob_status_forward', 'ojob_template_matches'],
        self::T_DECISIONS => ['odec_forbid_delete', 'odec_forbid_truncate', 'odec_forbid_update', 'odec_job_of_enrollment', 'odec_publication_of_strategy', 'odec_seal_matches',
            'odec_template_matches'],
        self::T_OUTCOMES => ['oout_forbid_delete', 'oout_forbid_truncate', 'oout_forbid_update', 'oout_job_of_enrollment', 'oout_of_enrollment'],
        self::T_BRAKES => ['obrk_forbid_delete', 'obrk_forbid_immutable_update', 'obrk_forbid_truncate', 'obrk_released_is_final', 'obrk_starts_open'],
        self::T_BUDGETS => ['obud_forbid_delete', 'obud_forbid_immutable_update', 'obud_forbid_truncate'],
    ];

    /** Solo SQLite: los equivalentes de los cuatro ojos del freno y del techo de la reserva. */
    private const SQLITE_ONLY_TRIGGERS = [
        self::T_BRAKES => ['obrk_four_eyes'],
        self::T_BUDGETS => ['obud_within_cap_insert', 'obud_within_cap_update'],
    ];

    private const PG_CHECKS = [
        self::T_RUNS => ['orun_closed_chk', 'orun_counts_chk', 'orun_error_chk', 'orun_kind_chk', 'orun_mode_chk', 'orun_status_chk', 'orun_trigger_key_chk'],
        self::T_CURSORS => ['ocur_last_id_chk', 'ocur_source_chk'],
        self::T_ENROLLMENTS => ['oenr_closed_chk', 'oenr_counters_chk', 'oenr_cycle_chk', 'oenr_last_outcome_chk', 'oenr_mode_chk', 'oenr_part_chk', 'oenr_purpose_chk',
            'oenr_seal_chk', 'oenr_status_chk', 'oenr_studio_ref_kind_chk', 'oenr_subject_chk', 'oenr_window_chk'],
        self::T_JOBS => ['ojob_attempt_chk', 'ojob_budget_chk', 'ojob_cancel_chk', 'ojob_channel_chk', 'ojob_claimed_chk', 'ojob_idempotency_chk', 'ojob_medium_chk',
            'ojob_mode_chk', 'ojob_part_chk', 'ojob_phone_chk', 'ojob_refs_chk', 'ojob_release_chk', 'ojob_seal_chk', 'ojob_shadow_chk', 'ojob_status_chk', 'ojob_studio_ref_kind_chk',
            'ojob_subject_chk', 'ojob_template_chk', 'ojob_window_chk'],
        self::T_DECISIONS => ['odec_facts_chk', 'odec_kind_chk', 'odec_level_chk', 'odec_level_rule_chk', 'odec_mode_chk', 'odec_part_chk', 'odec_reasons_chk', 'odec_retry_chk',
            'odec_seal_chk', 'odec_studio_ref_kind_chk', 'odec_subject_chk', 'odec_template_chk', 'odec_trace_chk'],
        self::T_OUTCOMES => ['oout_channel_chk', 'oout_classifier_brakes_chk', 'oout_classifier_chk', 'oout_codes_chk', 'oout_links_chk', 'oout_next_action_chk', 'oout_outcome_chk',
            'oout_source_chk', 'oout_source_outcome_chk', 'oout_staff_chk', 'oout_subject_chk'],
        self::T_BRAKES => ['obrk_cause_scope_chk', 'obrk_four_eyes_chk', 'obrk_opened_by_type_chk', 'obrk_opener_chk', 'obrk_reason_chk', 'obrk_release_chk', 'obrk_scope_chk',
            'obrk_scope_ref_chk'],
        self::T_BUDGETS => ['obud_limits_chk', 'obud_mode_chk', 'obud_scope_chk'],
    ];

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

    public function test_columnas_tipos_y_nulos_de_las_ocho_tablas_y_ningun_dato_personal(): void
    {
        $motor = $this->isPostgres() ? 0 : 1;
        foreach ([
            self::T_RUNS => self::COLUMNS_RUNS,
            self::T_CURSORS => self::COLUMNS_CURSORS,
            self::T_ENROLLMENTS => self::COLUMNS_ENROLLMENTS,
            self::T_JOBS => self::COLUMNS_JOBS,
            self::T_DECISIONS => self::COLUMNS_DECISIONS,
            self::T_OUTCOMES => self::COLUMNS_OUTCOMES,
            self::T_BRAKES => self::COLUMNS_BRAKES,
            self::T_BUDGETS => self::COLUMNS_BUDGET_COUNTERS,
        ] as $tabla => $esperadas) {
            $reales = [];
            foreach (Schema::getColumns($tabla) as $c) {
                $reales[$c['name']] = [$c['type'], (bool) $c['nullable']];
            }
            $this->assertSame(array_map(static fn (array $e): array => [$e[$motor], $e[2]], $esperadas), $reales, $tabla);
            // Protección 10 y P9-9: ni teléfono, ni nombre de la persona, ni documento, ni cuerpo, ni importe.
            foreach (array_keys($reales) as $columna) {
                if ($tabla === self::T_JOBS && $columna === 'phone_ref') {
                    continue;   // L9-30: el seudónimo HMAC (`outreach-phone/1`), 64 hex por CHECK; nunca el número.
                }
                $this->assertDoesNotMatchRegularExpression('/phone|e164|document|email|birth|first_name|body|text|amount|price|debt|transcript/', $columna, "{$tabla}.{$columna}");
            }
        }
    }

    public function test_recuentos_y_nombres_de_las_guardas_de_la_ejecucion(): void
    {
        $this->assertSame($this->isPostgres()
            ? [
                'obrk_one_open' => '(released_at IS NULL)',
                'oenr_one_open' => "((status)::text = ANY ((ARRAY['active'::character varying, 'waiting'::character varying])::text[]))",
                'ojob_one_live' => "((status)::text = ANY ((ARRAY['planned'::character varying, 'claimed'::character varying, 'dispatching'::character varying])::text[]))",
            ]
            : [
                'obrk_one_open' => 'released_at IS NULL',
                'oenr_one_open' => "status IN ('active', 'waiting')",
                'ojob_one_live' => "status IN ('planned', 'claimed', 'dispatching')",
            ], $this->partialPredicates(self::EXECUTION_TABLES));

        if ($this->isPostgres()) {
            foreach (self::PG_TRIGGERS as $tabla => $nombres) {
                $this->assertSameSet($nombres, $this->triggersOn($tabla), $tabla);
            }
            foreach (self::PG_CHECKS as $tabla => $nombres) {
                $this->assertSameSet($nombres, $this->checksOn($tabla), $tabla);
            }
            $this->assertSame(51, $this->countLike('trigger', 'outreach\\_%'), 'pasadas 6 + cursores 4 + inscripciones 10 + trabajos 11 + decisiones 7 + resultados 5 + frenos 5 + reservas 3');
            $this->assertSame(75, $this->countLike('check', 'outreach\\_%'), 'pasadas 7 + cursores 2 + inscripciones 12 + trabajos 19 + decisiones 13 + resultados 11 + frenos 8 + reservas 3');
            $this->assertSame(8, (int) DB::scalar("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'outreach\\_%'"));
            $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'outreach\\_%' AND t.tgenabled <> 'O'"));

            return;
        }

        foreach (self::PG_TRIGGERS as $tabla => $nombres) {
            $this->assertSameSet([...array_filter($nombres, static fn (string $n): bool => ! str_ends_with($n, '_truncate')), ...(self::SQLITE_ONLY_TRIGGERS[$tabla] ?? [])],
                $this->triggersOn($tabla), $tabla);
        }
        $this->assertSame(46, $this->countLike('trigger', 'outreach_%'), '51 − 8 de TRUNCATE + 3 equivalentes');
    }

    public function test_solo_en_postgres_las_funciones_son_propias_invoker_y_cualificadas(): void
    {
        $this->requirePostgres('las funciones de los disparadores solo existen en PostgreSQL.');

        $m = $this->migrationNamed(self::EXECUTION_MIGRATION);
        $propias = $m::ownFunctions();
        $this->assertCount(13, $propias, '12 guardas entre tablas + outreach_forbid()');
        $this->assertSame(13, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname LIKE 'outreach\\_%'"));
        foreach ($propias as $funcion) {
            $f = DB::selectOne('SELECT l.lanname AS lang, p.provolatile AS vol, p.prosecdef::int AS definer, pg_get_userbyid(p.proowner) AS owner, p.prosrc AS src
                FROM pg_proc p JOIN pg_language l ON l.oid = p.prolang WHERE p.proname = ?', [$funcion]);
            $this->assertSame(['plpgsql', 'v', 0, (string) DB::scalar('SELECT current_user')], [$f->lang, $f->vol, (int) $f->definer, $f->owner], $funcion);
            preg_match_all('/FROM\s+([a-z_.]+)\s+AS\s/', (string) $f->src, $tablas);
            foreach ($tablas[1] as $tabla) {
                $this->assertStringStartsWith('public.', $tabla, "{$funcion}: {$tabla}");
            }
            $this->assertStringContainsString('outreach: %', (string) $f->src, $funcion);
        }
        // Los de fila usan outreach_forbid(); las funciones del Studio no se usan aquí.
        $this->assertSame(31, (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_proc p ON p.oid = t.tgfoid
            WHERE NOT t.tgisinternal AND c.relname LIKE 'outreach\\_%' AND p.proname = 'outreach_forbid' AND t.tgtype & 1 = 1"));
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_proc p ON p.oid = t.tgfoid
            WHERE NOT t.tgisinternal AND c.relname LIKE 'outreach\\_%' AND p.proname LIKE 'ultron\\_studio\\_%'"));
    }

    /** Protecciones 5–9: la ejecución no nombra el motor, Marketing, la voz, n8n ni la cadena del H5; sus FKs, solo al gobierno del H9 y entre ellas. */
    public function test_la_ejecucion_no_nombra_lo_ajeno_y_sus_fks_son_restrict(): void
    {
        $codigo = '';
        foreach (token_get_all((string) file_get_contents(database_path('migrations/'.self::EXECUTION_MIGRATION.'.php'))) as $t) {
            if (! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $codigo .= is_array($t) ? $t[1] : $t;
            }
        }
        $this->assertDoesNotMatchRegularExpression('/\bultron_studio_publications\b|\bvoice_calls\b|\bmarketing_messages\b|\bmarketing_leads\b/', $codigo);
        foreach (['commercial_', 'automation_events', 'n8n', 'UltronAbortLatch', 'ultron_studio_voice_', 'ultron_studio_forbid', 'identities'] as $nombre) {
            $this->assertStringNotContainsString($nombre, $codigo, $nombre);
        }

        $fks = [];
        foreach (self::EXECUTION_TABLES as $tabla) {
            foreach (Schema::getForeignKeys($tabla) as $fk) {
                $fks[] = "{$tabla}.".implode(',', $fk['columns'])." → {$fk['foreign_table']}.".implode(',', $fk['foreign_columns']);
                $this->assertSame('restrict', strtolower((string) $fk['on_delete']), $tabla);
            }
        }
        $sello = static fn (string $t): array => ["{$t}.strategy_id → ultron_studio_strategies.id", "{$t}.strategy_version_id → ultron_studio_strategy_versions.id",
            "{$t}.publication_seq → ultron_studio_strategy_publications.seq"];
        $this->assertSameSet([
            'outreach_runs.strategy_id → ultron_studio_strategies.id',
            ...$sello('outreach_enrollments'),
            ...$sello('outreach_jobs'), 'outreach_jobs.enrollment_id → outreach_enrollments.id', 'outreach_jobs.template_version_id → ultron_studio_message_templates.id',
            ...$sello('outreach_decisions'), 'outreach_decisions.run_id → outreach_runs.id', 'outreach_decisions.enrollment_id → outreach_enrollments.id',
            'outreach_decisions.job_id → outreach_jobs.id', 'outreach_decisions.template_version_id → ultron_studio_message_templates.id',
            'outreach_outcomes.job_id → outreach_jobs.id', 'outreach_outcomes.enrollment_id → outreach_enrollments.id', 'outreach_outcomes.strategy_id → ultron_studio_strategies.id',
        ], $fks);
    }

    // ── El rol de la aplicación (solo PG) ───────────────────────────────────

    public function test_el_rol_de_la_aplicacion_es_iron_app_salvo_que_el_entorno_diga_otro(): void
    {
        $m = $this->migrationNamed(self::EXECUTION_MIGRATION);
        putenv('ULTRON_STUDIO_DB_APP_ROLE');
        $this->assertSame('iron_app', $m::appRole());
        putenv('ULTRON_STUDIO_DB_APP_ROLE=h9_app');
        $this->assertSame('h9_app', $m::appRole());
        putenv('ULTRON_STUDIO_DB_APP_ROLE=iron_app; DROP TABLE outreach_jobs');
        $this->expectException(RuntimeException::class);
        $m::appRole();
    }

    public function test_solo_en_postgres_la_aplicacion_trabaja_todo_el_ciclo_y_pierde_lo_que_no_es_suyo(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');

        $this->withAppRole(function (): void {
            $this->migrationNamed(self::EXECUTION_MIGRATION)->down();
            $this->migrationNamed(self::GOVERNANCE_MIGRATION)->down();
            $this->migrationNamed(self::GOVERNANCE_MIGRATION)->up();
            $this->migrationNamed(self::EXECUTION_MIGRATION)->up();

            foreach ([self::T_DECISIONS, self::T_OUTCOMES] as $t) {
                $this->assertSame([true, true, false, false, false], $this->privileges($t), "{$t}: solo inserción");
            }
            foreach ([self::T_RUNS, self::T_CURSORS, self::T_ENROLLMENTS, self::T_JOBS, self::T_BRAKES, self::T_BUDGETS] as $t) {
                $this->assertSame([true, true, true, false, false], $this->privileges($t), "{$t}: conserva UPDATE, sin DELETE");
            }

            $this->people = [];
            $this->assertNull($this->asApp(function (): void {
                $live = $this->liveVersion();
                $run = $this->insertRun(['strategy_id' => $live['strategy']]);
                $sombra = $this->insertEnrollment($live);
                $this->insertDecision($sombra, ['run_id' => $run]);
                $simulado = $this->insertJob($sombra);
                $this->walkJob($simulado, 'simulated');
                $resultado = $this->simulatedOutcomeRow($sombra, $simulado);
                $this->db()->table(self::T_OUTCOMES)->insert($resultado);
                // El idempotente de la ingesta: ON CONFLICT DO NOTHING solo pide INSERT.
                $this->assertSame(0, $this->db()->table(self::T_OUTCOMES)->insertOrIgnore(['uuid' => (string) Str::uuid()] + $resultado));
                $this->db()->table(self::T_RUNS)->where('id', $run)->update(['status' => 'completed', 'finished_at' => '2026-11-12 15:01:00', 'counts' => '{"evaluated":1}']);
                $this->db()->table(self::T_CURSORS)->insert(['source' => 'commercial_events', 'last_id' => 10, 'created_at' => now()]);
                $this->db()->table(self::T_CURSORS)->where('source', 'commercial_events')->update(['last_id' => 25, 'updated_at' => now()]);
                // El asistido en live: reserva, sale, se reclama una vez.
                $vivo = $this->insertEnrollment($live, ['mode' => 'live']);
                $contador = $this->insertBudget();
                $this->assertSame(1, $this->reserve($contador, 1));
                $asistido = $this->insertAssistedJob($vivo);
                $this->walkJob($asistido, 'started');
                // El despachador reclama con FOR UPDATE SKIP LOCKED: los trabajos conservan UPDATE.
                DB::select('SELECT id FROM '.self::T_JOBS." WHERE status = 'planned' FOR UPDATE SKIP LOCKED");
                $this->db()->table(self::T_JOBS)->where('id', $asistido)->update(['assisted_claimed_by_admin_id' => 3, 'assisted_claimed_by_name' => 'Caro', 'assisted_claimed_at' => now()]);
                $freno = $this->insertBrake();
                $this->releaseBrake($freno);
                $this->assertSame([1, 1, 2, 2, 1, 1, 1, 1], array_map(fn (string $t): int => $this->db()->table($t)->count(), self::EXECUTION_TABLES));
            })[0], 'el rol de la aplicación no completa el ciclo');

            foreach ([
                'UPDATE '.self::T_DECISIONS.' SET level = level WHERE id = -1',
                'DELETE FROM '.self::T_DECISIONS.' WHERE id = -1',
                'SELECT id FROM '.self::T_DECISIONS.' WHERE id = -1 FOR UPDATE',
                'UPDATE '.self::T_OUTCOMES.' SET detail_code = detail_code WHERE id = -1',
                'DELETE FROM '.self::T_OUTCOMES.' WHERE id = -1',
                'SELECT id FROM '.self::T_OUTCOMES.' WHERE id = -1 FOR SHARE',
                'INSERT INTO '.self::T_OUTCOMES." (uuid, enrollment_id, outcome, source, source_key, deterministic, occurred_at, created_at) VALUES ('".Str::uuid()."', -1, 'blocked', 'system', 'x', true, now(), now()) "
                    .'ON CONFLICT (enrollment_id, source, source_key) DO UPDATE SET detail_code = EXCLUDED.detail_code',
                'DELETE FROM '.self::T_RUNS.' WHERE id = -1',
                'DELETE FROM '.self::T_CURSORS." WHERE source = 'x'",
                'DELETE FROM '.self::T_ENROLLMENTS.' WHERE id = -1',
                'DELETE FROM '.self::T_JOBS.' WHERE id = -1',
                'DELETE FROM '.self::T_BRAKES.' WHERE id = -1',
                'DELETE FROM '.self::T_BUDGETS.' WHERE id = -1',
                'TRUNCATE '.self::T_JOBS,
                'ALTER TABLE '.self::T_JOBS.' DISABLE TRIGGER ojob_status_forward',
                'ALTER TABLE '.self::T_BUDGETS.' DROP CONSTRAINT obud_limits_chk',
                'DROP INDEX ojob_one_live',
                'CREATE OR REPLACE FUNCTION outreach_forbid() RETURNS trigger LANGUAGE plpgsql AS $fn$ BEGIN RETURN NEW; END; $fn$',
                'SET session_replication_role = replica',
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
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT DELETE ON TABLES TO PUBLIC');
            try {
                $this->migrationNamed(self::EXECUTION_MIGRATION)->up();
                $this->fail('up() dio por buena una aplicación que todavía puede borrar la ejecución');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('todavía puede DELETE en outreach_runs', $e->getMessage());
                $this->assertStringContainsString('DELETE en outreach_decisions', $e->getMessage());
            }
            foreach (self::EXECUTION_TABLES as $tabla) {
                $this->assertFalse(Schema::hasTable($tabla), "up() dejó {$tabla}");
            }
            $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname LIKE 'outreach\\_%'"), 'up() dejó funciones');
        });
    }

    public function test_solo_en_postgres_sin_el_rol_no_hay_nada_que_revocar_salvo_en_produccion(): void
    {
        $this->requirePostgres('los roles solo existen en PostgreSQL.');

        $m = $this->migrationNamed(self::EXECUTION_MIGRATION);
        $nadie = 'ultron_studio_h9_rol_inexistente';
        putenv('ULTRON_STUDIO_DB_APP_ROLE='.$nadie);
        DB::beginTransaction();
        try {
            $m->down();
            $m->up();
            $this->assertContains('ojob_status_forward', $this->triggersOn(self::T_JOBS));
            $m->down();
            $this->app['env'] = 'production';
            try {
                $m->up();
                $this->fail('En producción, up() se calló un REVOKE que no podía aplicar');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString("outreach (Hito 9): el rol de la aplicación «{$nadie}» no existe en este clúster y APP_ENV es production", $e->getMessage());
            } finally {
                $this->app['env'] = 'testing';
            }
            $this->assertFalse(Schema::hasTable(self::T_JOBS), 'up() dejó la mitad');
        } finally {
            DB::rollBack();
        }
        $this->assertContains('ojob_status_forward', $this->triggersOn(self::T_JOBS));
    }

    // ── down() ──────────────────────────────────────────────────────────────

    public function test_down_se_niega_con_una_fila_en_cualquiera_de_las_ocho_tablas(): void
    {
        $m = $this->migrationNamed(self::EXECUTION_MIGRATION);
        $antes = $this->footprint(self::EXECUTION_TABLES, $m::ownFunctions());
        foreach ([
            'una pasada' => ['(1 pasadas, 0 cursores', fn () => $this->insertRun()],
            'un cursor' => ['1 cursores, 0 inscripciones', fn () => $this->db()->table(self::T_CURSORS)->insert(['source' => 'commercial_events', 'last_id' => 0, 'created_at' => now()])],
            'una inscripción' => ['1 inscripciones, 0 trabajos', fn () => $this->insertEnrollment($this->liveVersion())],
            'un trabajo' => ['1 trabajos, 0 decisiones', fn () => $this->insertJob($this->insertEnrollment($this->liveVersion()))],
            'una decisión' => ['1 decisiones, 0 resultados', fn () => $this->insertDecision($this->insertEnrollment($this->liveVersion()))],
            'un resultado' => ['1 resultados, 0 frenos', fn () => $this->insertOutcome($this->insertEnrollment($this->liveVersion()))],
            'un freno' => ['1 frenos y 0 reservas', fn () => $this->insertBrake()],
            'una reserva' => ['0 frenos y 1 reservas del día', fn () => $this->insertBudget()],
        ] as $que => [$recuento, $siembra]) {
            DB::beginTransaction();
            try {
                $this->people = [];
                $siembra();
                try {
                    $m->down();
                    $this->fail("down() no se negó con {$que}");
                } catch (RuntimeException $e) {
                    $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                    $this->assertStringContainsString('outreach (Hito 9): down() se niega a revertir la ejecución con datos', $e->getMessage(), $que);
                    $this->assertStringContainsString($recuento, $e->getMessage(), $que);
                }
                $this->assertSame($antes, $this->footprint(self::EXECUTION_TABLES, $m::ownFunctions()), "down() tocó el esquema con {$que}");
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_down_sin_filas_y_up_dejan_el_mismo_esquema_y_no_tocan_el_gobierno(): void
    {
        $m = $this->migrationNamed(self::EXECUTION_MIGRATION);
        $h9 = $this->footprint(self::EXECUTION_TABLES, $m::ownFunctions());
        $gobierno = $this->footprint(self::GOVERNANCE_TABLES);

        DB::beginTransaction();
        try {
            $m->down();
            foreach (self::EXECUTION_TABLES as $tabla) {
                $this->assertFalse(Schema::hasTable($tabla), $tabla);
            }
            if ($this->isPostgres()) {
                $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname LIKE 'outreach\\_%'"));
            }
            $this->assertSame($gobierno, $this->footprint(self::GOVERNANCE_TABLES), 'down() tocó el gobierno');
            $m->up();
            $this->assertSame($h9, $this->footprint(self::EXECUTION_TABLES, $m::ownFunctions()), 'up() no dejó lo mismo que la primera vez');
            $this->assertSame($gobierno, $this->footprint(self::GOVERNANCE_TABLES), 'up() tocó el gobierno');
            $this->assertGuarded(fn () => $this->db()->table(self::T_RUNS)->insert($this->runRow(['status' => 'completed', 'finished_at' => now()])),
                'outreach: una pasada nace running, sin cerrar (INSERT en outreach_runs)', 'las guardas vuelven');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($h9, $this->footprint(self::EXECUTION_TABLES, $m::ownFunctions()));
    }

    /** @param  list<string>  $esperados  @param  list<string>  $reales */
    private function assertSameSet(array $esperados, array $reales, string $que = ''): void
    {
        sort($esperados, SORT_STRING);
        sort($reales, SORT_STRING);
        $this->assertSame($esperados, $reales, $que);
    }
}
