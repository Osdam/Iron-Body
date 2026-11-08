<?php

namespace Tests\Feature\UltronStudio;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * El esquema del Hito 6 (contrato H6 §I3.1–I3.2, §I4.1–I4.2, §I5.3 y §I8): las
 * tres tablas de integraciones (2026_11_01_100001) y sus guardas
 * (2026_11_01_100002).
 *
 * Se prueba en los dos motores (la suite en SQLite y la puerta obligatoria
 * `docs/ultron-studio/ops/pgunit.sh` en PostgreSQL), con SQL a pelo y sin pasar
 * por los modelos: lo que se comprueba es la BASE. Lo esperado sale del
 * contrato, no de las constantes de la migración.
 *
 *  · Columnas y tipos de I3.1, I4.1 e I5.3; versiones y el libro no ganan
 *    columnas ni pierden guardas (en SQLite el libro ni se reconstruye).
 *  · Recuentos del Studio: PG 44 disparadores y 50 CHECK (sin el H6: 31 y 28);
 *    SQLite 32 disparadores (sin el H6: 22). Incluyen el Hito 7
 *    (2026_11_02_100001: 1 disparador y 1 CHECK en PG, 2 disparadores en
 *    SQLite, todos sobre la cadena del H5), que sigue ahí cuando el H6 hace
 *    down(). Si un hito posterior añade guardas, estos números se actualizan a
 *    conciencia, como el de rutas.
 *  · Cada disparador hace lo que dice: P0001 en PG y 23000 en SQLite, con el
 *    texto de `ultron_studio_forbid()`; cada CHECK (solo PG) da 23514.
 *  · La sustitución de una asociación (§I3.4, paso 11) pasa por la base en el
 *    orden «desactivar la vieja → insertar la nueva → anotarla» (D-1 del
 *    informe), y la sustituta se anota una sola vez.
 *  · `uscl_one_active` y `uscn_one_live` conservan su WHERE en los dos motores
 *    (memoria «SQLite reconstruye y pierde el WHERE») y hacen lo que dicen.
 *  · `usae_subject_type_chk` admite los 7 sujetos (PG).
 *  · down() se niega con cualquier dato del Hito 6 sin tocar nada, y sin ellos
 *    deja el esquema del Hito 5 y se puede volver a aplicar.
 *
 * En PostgreSQL, cada sentencia que tiene que fallar va en una transacción
 * anidada (SAVEPOINT): sin ella el error abortaría la transacción de la prueba.
 */
class UltronStudioIntegrationsSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const LINKS = 'ultron_studio_campaign_links';

    private const CANARIES = 'ultron_studio_canary_numbers';

    private const SNAPSHOTS = 'ultron_studio_integration_snapshots';

    private const AUDIT = 'ultron_studio_audit_events';

    private const VERSIONS = 'ultron_studio_versions';

    private const CREATE = '2026_11_01_100001_create_ultron_studio_integration_tables';

    private const GUARDS = '2026_11_01_100002_add_ultron_studio_integration_guards';

    /**
     * Recuentos de todo el Studio (tablas `ultron_studio_%`), con el Hito 7
     * aplicado: `*_H5` es el esquema sin el H6 (el down() del H6 no toca las
     * guardas del H7, que son de la cadena) y `*_H6`, con él.
     */
    private const PG_TRIGGERS_H5 = 55;   // F 11 + laboratorio 16 + H5 3 + H7 1 + H8 24

    private const PG_TRIGGERS_H6 = 68;   // + asociaciones 5, canarios 5, instantáneas 3

    private const PG_CHECKS_H5 = 62;     // F 11 + laboratorio 13 + H5 3 + H7 1 + H8 34

    private const PG_CHECKS_H6 = 84;     // + 4, 12 y 6 (usae_subject_type_chk se sustituye, no se suma)

    private const SQLITE_TRIGGERS_H5 = 45; // F 7 + laboratorio 11 + H5 2 + H7 2 + H8 23

    private const SQLITE_TRIGGERS_H6 = 55; // + 4, 4 y 2

    /** Contrato §I3.1, en orden: columna → [tipo en PG, tipo en SQLite, NULL-able]. */
    private const LINK_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'ad_account_id' => ['character varying(64)', 'varchar', false],
        'campaign_id' => ['character varying(64)', 'varchar', false],
        'campaign_name_at_link' => ['character varying(255)', 'varchar', true],
        'target_kind' => ['character varying(10)', 'varchar', false],
        'version_id' => ['bigint', 'integer', true],
        'version_content_hash' => ['character(64)', 'varchar', true],
        'status' => ['character varying(10)', 'varchar', false],
        'created_by_admin_id' => ['bigint', 'integer', false],
        'created_by_name' => ['character varying(120)', 'varchar', false],
        'created_by_role' => ['character varying(60)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
        'deactivated_at' => ['timestamp(0) without time zone', 'datetime', true],
        'deactivated_by_admin_id' => ['bigint', 'integer', true],
        'deactivated_by_name' => ['character varying(120)', 'varchar', true],
        'deactivated_by_role' => ['character varying(60)', 'varchar', true],
        'deactivation_reason' => ['character varying(500)', 'varchar', true],
        'replaced_by_link_id' => ['bigint', 'integer', true],
    ];

    /** Contrato §I4.1, en orden. */
    private const CANARY_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'channel' => ['character varying(10)', 'varchar', false],
        'alias' => ['character varying(40)', 'varchar', false],
        'phone_hash' => ['character(64)', 'varchar', false],
        'phone_last4' => ['character(4)', 'varchar', false],
        'country_code' => ['character varying(4)', 'varchar', false],
        'phone_encrypted' => ['text', 'text', true],
        'status' => ['character varying(12)', 'varchar', false],
        'expected_version_id' => ['bigint', 'integer', true],
        'consent_attested' => ['boolean', 'tinyint', false],
        'registered_by_admin_id' => ['bigint', 'integer', false],
        'registered_by_name' => ['character varying(120)', 'varchar', false],
        'registered_by_role' => ['character varying(60)', 'varchar', true],
        'authorized_by_admin_id' => ['bigint', 'integer', true],
        'authorized_by_name' => ['character varying(120)', 'varchar', true],
        'authorized_by_role' => ['character varying(60)', 'varchar', true],
        'authorized_at' => ['timestamp(0) without time zone', 'datetime', true],
        'activated_by_admin_id' => ['bigint', 'integer', true],
        'activated_by_name' => ['character varying(120)', 'varchar', true],
        'activated_by_role' => ['character varying(60)', 'varchar', true],
        'activated_at' => ['timestamp(0) without time zone', 'datetime', true],
        'revoked_by_admin_id' => ['bigint', 'integer', true],
        'revoked_by_name' => ['character varying(120)', 'varchar', true],
        'revoked_by_role' => ['character varying(60)', 'varchar', true],
        'revoked_at' => ['timestamp(0) without time zone', 'datetime', true],
        'revoke_reason' => ['character varying(500)', 'varchar', true],
        'status_changed_at' => ['timestamp(0) without time zone', 'datetime', false],
        'created_at' => ['timestamp(0) without time zone', 'datetime', true],
        'updated_at' => ['timestamp(0) without time zone', 'datetime', true],
    ];

    /** Contrato §I5.3, en orden. */
    private const SNAPSHOT_COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', true],
        'provider' => ['character varying(16)', 'varchar', false],
        'kind' => ['character varying(10)', 'varchar', false],
        'fingerprint' => ['character(64)', 'varchar', false],
        'facts' => ['json', 'text', false],
        'outcome' => ['character varying(16)', 'varchar', true],
        'actor_type' => ['character varying(10)', 'varchar', false],
        'actor_admin_id' => ['bigint', 'integer', true],
        'actor_name' => ['character varying(120)', 'varchar', false],
        'actor_role' => ['character varying(60)', 'varchar', true],
        'note' => ['character varying(500)', 'varchar', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    /** Contrato §I3.2: lo que fija una asociación. */
    private const LINK_IMMUTABLE = [
        'id', 'uuid', 'client_request_id', 'ad_account_id', 'campaign_id', 'campaign_name_at_link', 'target_kind',
        'version_id', 'version_content_hash', 'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    /** Contrato §I3.2: las mutables, menos la sustituta (que tiene su propia guarda). */
    private const LINK_CLOSING = ['status', 'deactivated_at', 'deactivated_by_admin_id', 'deactivated_by_name', 'deactivated_by_role', 'deactivation_reason'];

    /** Contrato §I4.2. */
    private const CANARY_IMMUTABLE = [
        'id', 'uuid', 'client_request_id', 'channel', 'alias', 'phone_hash', 'phone_last4', 'country_code',
        'consent_attested', 'registered_by_admin_id', 'registered_by_name', 'registered_by_role', 'created_at',
    ];

    /** Las de F (2026_10_18_100002): H6 no añade ninguna. */
    private const VERSION_COLUMNS = [
        'id', 'uuid', 'client_request_id', 'label', 'note', 'status', 'content', 'content_hash', 'catalog_version',
        'catalog_hash', 'source_draft_revision', 'created_by_admin_id', 'created_by_name', 'created_by_role',
        'last_validation', 'submitted_at', 'status_changed_at', 'created_at', 'updated_at',
    ];

    /** Las del libro de F (2026_10_18_100001): H6 no añade ninguna. */
    private const AUDIT_COLUMNS = [
        'id', 'event', 'subject_type', 'subject_uuid', 'actor_type', 'actor_admin_id', 'actor_name', 'actor_role',
        'from_status', 'to_status', 'content_hash', 'summary', 'diff', 'metadata', 'ip_address', 'user_agent', 'created_at',
    ];

    private const SEVEN_SUBJECTS = ['draft', 'version', 'rule', 'system', 'campaign_link', 'canary_number', 'integration'];

    /** Contrato §I8: los eventos nuevos y su sujeto (caben en `event` varchar(48) y `subject_type` varchar(16)). */
    private const H6_EVENTS = [
        'campaign_link.created' => 'campaign_link',
        'campaign_link.deactivated' => 'campaign_link',
        'canary_number.registered' => 'canary_number',
        'canary_number.authorized' => 'canary_number',
        'canary_number.four_eyes_denied' => 'canary_number',
        'canary_number.activated' => 'canary_number',
        'canary_number.deactivated' => 'canary_number',
        'canary_number.revoked' => 'canary_number',
        'canary_number.expectation_set' => 'canary_number',
        'integration.config_acknowledged' => 'integration',
        'integration.probe_run' => 'integration',
    ];

    private const PG_TRIGGERS = [
        self::LINKS => ['uscl_forbid_delete', 'uscl_forbid_immutable_update', 'uscl_forbid_truncate', 'uscl_inactive_is_final', 'uscl_replacement_once'],
        self::CANARIES => ['uscn_cipher_only_shrinks', 'uscn_forbid_delete', 'uscn_forbid_immutable_update', 'uscn_forbid_truncate', 'uscn_revoked_is_final'],
        self::SNAPSHOTS => ['usis_forbid_delete', 'usis_forbid_truncate', 'usis_forbid_update'],
    ];

    private const PG_CHECKS = [
        self::LINKS => ['uscl_deactivation_chk', 'uscl_status_chk', 'uscl_target_chk', 'uscl_target_kind_chk'],
        self::CANARIES => [
            'uscn_activation_four_eyes_chk', 'uscn_active_chk', 'uscn_authorized_chk', 'uscn_channel_chk', 'uscn_consent_chk', 'uscn_four_eyes_chk',
            'uscn_hash_chk', 'uscn_last4_chk', 'uscn_revoke_chk', 'uscn_status_chk', 'uscn_voice_cipher_chk', 'uscn_whatsapp_no_cipher_chk',
        ],
        self::SNAPSHOTS => ['usis_actor_chk', 'usis_actor_type_chk', 'usis_fingerprint_chk', 'usis_kind_chk', 'usis_outcome_chk', 'usis_provider_chk'],
    ];

    /** Los motivos de los disparadores (los del contrato donde los da). */
    private const LINK_DELETE = 'las asociaciones de campaña no se borran';

    private const LINK_IMMUTABLE_REASON = 'una asociación de campaña es inmutable; solo cambian su estado, su cierre y la asociación que la sustituye';

    private const LINK_FINAL = 'una asociación de campaña inactiva es definitiva';

    private const LINK_REPLACEMENT = 'la asociación que sustituye a otra se anota una sola vez, y solo en una asociación inactiva';

    private const CANARY_DELETE = 'los números canarios se revocan, no se borran';

    private const CANARY_IMMUTABLE_REASON = 'un número canario es inmutable en su canal, su alias, su huella, su consentimiento y su registro';

    private const CANARY_FINAL = 'un número canario revocado es definitivo';

    private const CANARY_CIPHER = 'el cifrado de un número canario solo se borra: no se escribe ni se cambia después del registro';

    private const SNAPSHOT_REASON = 'las instantáneas de integración son de solo inserción';

    /** Quien registra, quien autoriza y una tercera persona. */
    private const REGISTRANT = 7;

    private const AUTHORIZER = 8;

    private const THIRD = 9;

    /** Un cifrado de mentira: a la base solo le importa si está y si cambia. */
    private const CIPHER = 'eyJpdiI6ImNpZnJhZG8tZGUtcHJ1ZWJhIiwidmFsdWUiOiJ4In0=';

    /** @var array<string, Migration> */
    private array $migrations = [];

    private int $phones = 0;

    // ── Las tablas ──────────────────────────────────────────────────────────

    public function test_las_tres_tablas_tienen_las_columnas_y_los_tipos_del_contrato(): void
    {
        foreach ([self::LINKS => self::LINK_COLUMNS, self::CANARIES => self::CANARY_COLUMNS, self::SNAPSHOTS => self::SNAPSHOT_COLUMNS] as $tabla => $columnas) {
            $tipo = $this->isPostgres() ? 0 : 1;
            $esperado = array_map(static fn (array $c): array => [$c[$tipo], $c[2]], $columnas);

            $real = [];
            foreach (Schema::getColumns($tabla) as $c) {
                $real[$c['name']] = [$this->isPostgres() ? $c['type'] : $c['type_name'], (bool) $c['nullable']];
            }

            $this->assertSame($esperado, $real, $tabla);
        }
    }

    public function test_indices_y_claves_foraneas_de_las_tres_tablas(): void
    {
        $this->assertSame([
            'ultron_studio_campaign_links_client_request_id_unique' => [['client_request_id'], true],
            'ultron_studio_campaign_links_uuid_unique' => [['uuid'], true],
            'uscl_campaign_idx' => [['ad_account_id', 'campaign_id', 'id'], false],
            'uscl_one_active' => [['ad_account_id', 'campaign_id'], true],
        ], $this->indexesOf(self::LINKS));
        $this->assertSame([
            'ultron_studio_canary_numbers_client_request_id_unique' => [['client_request_id'], true],
            'ultron_studio_canary_numbers_uuid_unique' => [['uuid'], true],
            'uscn_one_live' => [['channel', 'phone_hash'], true],
            'uscn_status_idx' => [['status', 'channel'], false],
        ], $this->indexesOf(self::CANARIES));
        $this->assertSame([
            'ultron_studio_integration_snapshots_client_request_id_unique' => [['client_request_id'], true],
            'ultron_studio_integration_snapshots_uuid_unique' => [['uuid'], true],
            'usis_provider_idx' => [['provider', 'kind', 'id'], false],
        ], $this->indexesOf(self::SNAPSHOTS));

        $this->assertSame([
            'replaced_by_link_id' => [self::LINKS, ['id'], 'restrict'],
            'version_id' => [self::VERSIONS, ['id'], 'restrict'],
        ], $this->foreignKeysOf(self::LINKS));
        $this->assertSame(['expected_version_id' => [self::VERSIONS, ['id'], 'restrict']], $this->foreignKeysOf(self::CANARIES));
        $this->assertSame([], $this->foreignKeysOf(self::SNAPSHOTS), 'las instantáneas guardan al actor sin FK');

        // Las FKs se cumplen: una versión o una asociación que no existen no entran.
        $fk = fn (string $nombre): string => $this->isPostgres() ? $nombre : 'FOREIGN KEY constraint failed';
        $sqlstate = $this->isPostgres() ? '23503' : '23000';
        $this->assertRejected(fn () => $this->insertLink(['target_kind' => 'version', 'version_id' => 999999, 'version_content_hash' => str_repeat('a', 64)]),
            $sqlstate, $fk('ultron_studio_campaign_links_version_id_foreign'));
        $this->assertRejected(fn () => $this->insertCanary(['expected_version_id' => 999999]), $sqlstate, $fk('ultron_studio_canary_numbers_expected_version_id_foreign'));
        $vieja = $this->insertLink($this->closing());
        $this->assertRejected(fn () => DB::table(self::LINKS)->where('id', $vieja)->update(['replaced_by_link_id' => 999999]),
            $sqlstate, $fk('ultron_studio_campaign_links_replaced_by_link_id_foreign'));
    }

    public function test_versiones_y_el_libro_no_ganan_columnas_ni_pierden_sus_guardas(): void
    {
        $this->assertSame(self::VERSION_COLUMNS, Schema::getColumnListing(self::VERSIONS));
        $this->assertSame(['usv_forbid_delete', 'usv_forbid_immutable_update'], $this->triggersOn(self::VERSIONS, rowOnly: true));
        $this->assertSame(['usv_one_canary', 'usv_one_published'], $this->partialIndexesOn(self::VERSIONS));

        $this->assertSame(self::AUDIT_COLUMNS, Schema::getColumnListing(self::AUDIT));
        $this->assertSame(['usae_forbid_delete', 'usae_forbid_update'], $this->triggersOn(self::AUDIT, rowOnly: true));
        $this->assertSame([
            'usae_actor_idx' => [['actor_admin_id', 'id'], false],
            'usae_event_idx' => [['event', 'id'], false],
            'usae_subject_idx' => [['subject_type', 'subject_uuid', 'id'], false],
        ], $this->indexesOf(self::AUDIT));
        $this->assertSame([], $this->partialIndexesOn(self::AUDIT));

        if ($this->isPostgres()) {
            $this->assertSame(['usae_actor_type_chk', 'usae_subject_type_chk'], $this->checksOn(self::AUDIT));

            return;
        }
        // En SQLite el libro no tiene CHECK que ampliar: el Hito 6 no lo toca
        // (la prueba de down()/up() comprueba además que su definición no cambia).
        $this->assertStringNotContainsStringIgnoringCase('check', (string) DB::table('sqlite_master')->where('type', 'table')->where('name', self::AUDIT)->value('sql'));
    }

    // ── Recuentos y la función de F ─────────────────────────────────────────

    public function test_recuentos_de_disparadores_check_e_indices_parciales_del_studio(): void
    {
        $this->assertSame(['uscl_one_active', 'uscn_one_live', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());

        if ($this->isPostgres()) {
            foreach (self::PG_TRIGGERS as $tabla => $nombres) {
                $this->assertSame($nombres, $this->triggersOn($tabla), $tabla);
            }
            foreach (self::PG_CHECKS as $tabla => $nombres) {
                $this->assertSame($nombres, $this->checksOn($tabla), $tabla);
            }
            $this->assertSame(self::PG_TRIGGERS_H6, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24');
            $this->assertSame(self::PG_CHECKS_H6, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34');

            return;
        }

        foreach (self::PG_TRIGGERS as $tabla => $nombres) {
            $this->assertSame(array_values(array_filter($nombres, static fn (string $n): bool => ! str_ends_with($n, '_truncate'))), $this->triggersOn($tabla), $tabla);
        }
        $this->assertSame(self::SQLITE_TRIGGERS_H6, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23');
    }

    public function test_los_disparadores_reutilizan_la_funcion_de_f_con_su_mismo_cuerpo(): void
    {
        // El texto que llega a la base, igual en F, en H5 y en H6 (byte a byte).
        $f = $this->forbidFunctionSql('2026_10_18_100002_add_ultron_studio_guards');
        $this->assertSame($f, $this->forbidFunctionSql('2026_10_25_100002_add_ultron_studio_publication_guards'));
        $this->assertSame($f, $this->forbidFunctionSql(self::GUARDS));

        if (! $this->isPostgres()) {
            return;
        }
        $huella = fn (): string => (string) DB::selectOne("SELECT md5(pg_get_functiondef(oid)) AS h FROM pg_proc WHERE proname = 'ultron_studio_forbid'")->h;
        $ahora = $huella();
        DB::statement($f);
        $this->assertSame($ahora, $huella(), 'la función de la base no es la de F');

        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'ultron_studio_forbid'")->n);
        $this->assertSame(13, (int) DB::selectOne(
            "SELECT count(*) AS n FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid JOIN pg_class c ON c.oid = t.tgrelid
              WHERE NOT t.tgisinternal AND c.relname IN (?, ?, ?) AND p.proname = 'ultron_studio_forbid'", [self::LINKS, self::CANARIES, self::SNAPSHOTS])->n);
    }

    // ── Asociaciones de campaña ─────────────────────────────────────────────

    public function test_las_asociaciones_no_se_borran_ni_cambian_lo_que_las_fija(): void
    {
        $id = $this->insertLink(['target_kind' => 'version', 'version_id' => $this->insertVersion('APPROVED'), 'version_content_hash' => str_repeat('a', 64)]);

        foreach (self::LINK_IMMUTABLE as $columna) {
            // Por lista de columnas: escribir el MISMO valor también falla.
            $this->assertForbidden(fn () => DB::table(self::LINKS)->where('id', $id)->update([$columna => DB::raw($columna)]),
                self::LINK_IMMUTABLE_REASON, 'UPDATE', self::LINKS, $columna);
        }
        // ON CONFLICT DO UPDATE pasa por el disparador de UPDATE. La fila solo
        // choca por `uuid` (otra campaña y otra idempotencia).
        $fila = $this->linkRow(['uuid' => DB::table(self::LINKS)->where('id', $id)->value('uuid'), 'campaign_id' => '120200000000000999']);
        $this->assertForbidden(fn () => DB::table(self::LINKS)->upsert([$fila], ['uuid'], ['campaign_name_at_link']),
            self::LINK_IMMUTABLE_REASON, 'UPDATE', self::LINKS, 'upsert');

        $this->assertForbidden(fn () => DB::table(self::LINKS)->where('id', $id)->delete(), self::LINK_DELETE, 'DELETE', self::LINKS, 'DELETE por id');
        // En SQLite es el camino de un TRUNCATE.
        $this->assertForbidden(fn () => DB::table(self::LINKS)->delete(), self::LINK_DELETE, 'DELETE', self::LINKS, 'DELETE sin WHERE');

        // Un UPDATE que no encuentra filas no dispara nada.
        $this->assertSame(0, DB::table(self::LINKS)->where('id', -1)->update(['created_by_name' => 'nada']));
        $this->assertSame(1, DB::table(self::LINKS)->count());
    }

    public function test_una_asociacion_inactiva_es_definitiva_y_la_sustituta_se_anota_una_vez(): void
    {
        // §I3.4, paso 11, en el orden que la base admite (D-1 del informe):
        // 1) la activa pasa a inactive con su cierre (un `replaced_by_link_id`
        //    NULL explícito no cambia nada y pasa);
        $vieja = $this->insertLink();
        $this->assertSame(1, DB::table(self::LINKS)->where('id', $vieja)->where('status', 'active')
            ->update([...$this->closing('Sustituida por otra asociación'), 'replaced_by_link_id' => null]));
        // 2) se inserta la nueva (con la vieja activa, `uscl_one_active` lo impediría);
        $nueva = $this->insertLink(['target_kind' => 'version', 'version_id' => $this->insertVersion('CANARY'), 'version_content_hash' => str_repeat('b', 64)]);
        // 3) se anota la nueva en la vieja, una vez.
        $this->assertSame(1, DB::table(self::LINKS)->where('id', $vieja)->whereNull('replaced_by_link_id')->update(['replaced_by_link_id' => $nueva]));

        $this->assertSame(['inactive', $nueva], [DB::table(self::LINKS)->where('id', $vieja)->value('status'), (int) DB::table(self::LINKS)->where('id', $vieja)->value('replaced_by_link_id')]);
        $this->assertSame('active', DB::table(self::LINKS)->where('id', $nueva)->value('status'));

        // Con la fila inactiva, su cierre ya no cambia (ni con el mismo valor), y no se reactiva.
        foreach (self::LINK_CLOSING as $columna) {
            $this->assertForbidden(fn () => DB::table(self::LINKS)->where('id', $vieja)->update([$columna => DB::raw($columna)]),
                self::LINK_FINAL, 'UPDATE', self::LINKS, $columna);
        }
        $this->assertForbidden(fn () => DB::table(self::LINKS)->where('id', $vieja)->update(['status' => 'active']), self::LINK_FINAL, 'UPDATE', self::LINKS, 'reactivar');

        // La sustituta anotada no se cambia, no se borra ni se reescribe.
        $otra = $this->insertLink(['campaign_id' => '120200000000000999']);
        foreach (['cambiarla' => $otra, 'borrarla' => null, 'reescribirla' => DB::raw('replaced_by_link_id')] as $que => $valor) {
            $this->assertForbidden(fn () => DB::table(self::LINKS)->where('id', $vieja)->update(['replaced_by_link_id' => $valor]),
                self::LINK_REPLACEMENT, 'UPDATE', self::LINKS, $que);
        }
        // Una activa no lleva sustituta, ni siquiera en el mismo UPDATE que la deja activa.
        $this->assertForbidden(fn () => DB::table(self::LINKS)->where('id', $nueva)->update(['replaced_by_link_id' => $otra]),
            self::LINK_REPLACEMENT, 'UPDATE', self::LINKS, 'en una activa');
        // NULL sobre NULL en una activa no cambia nada: pasa.
        $this->assertSame(1, DB::table(self::LINKS)->where('id', $nueva)->update(['replaced_by_link_id' => null]));

        // La base no sabe si una desactivación fue por sustitución (lo sabe el
        // servicio): una inactiva sin sustituta admite anotarla UNA vez.
        $sola = $this->insertLink(['campaign_id' => '120200000000000555', ...$this->closing()]);
        $this->assertSame(1, DB::table(self::LINKS)->where('id', $sola)->update(['replaced_by_link_id' => $otra]));
        $this->assertForbidden(fn () => DB::table(self::LINKS)->where('id', $sola)->update(['replaced_by_link_id' => $nueva]),
            self::LINK_REPLACEMENT, 'UPDATE', self::LINKS, 'segunda anotación');

        // Nada se borró y nada cambió de lo que fija cada asociación.
        $this->assertSame(4, DB::table(self::LINKS)->count());
        $this->assertSame($nueva, (int) DB::table(self::LINKS)->where('id', $vieja)->value('replaced_by_link_id'));
    }

    public function test_solo_en_postgres_los_check_de_las_asociaciones(): void
    {
        $this->requirePostgres('SQLite no admite añadir CHECK a una tabla existente: lo vigila el servicio.');

        $version = $this->insertVersion('APPROVED');
        // Fuera del dominio de `status` (con cierre, para que solo falle ese CHECK).
        $this->assertRejected(fn () => $this->insertLink(['status' => 'paused', ...array_diff_key($this->closing(), ['status' => true])]), '23514', 'uscl_status_chk');
        // Un `target_kind` fuera del dominio rompe también `uscl_target_chk`: PG nombra uno de los dos.
        $this->assertRejected(fn () => $this->insertLink(['target_kind' => 'plan']), '23514', 'uscl_target');
        $this->assertSame(['baseline', 'version'], $this->checkValues('uscl_target_kind_chk', self::LINKS));
        $this->assertSame(['active', 'inactive'], $this->checkValues('uscl_status_chk', self::LINKS));
        // El destino y su versión van juntos.
        foreach ([
            'línea base con versión' => ['target_kind' => 'baseline', 'version_id' => $version, 'version_content_hash' => str_repeat('a', 64)],
            'línea base con huella' => ['target_kind' => 'baseline', 'version_content_hash' => str_repeat('a', 64)],
            'versión sin versión' => ['target_kind' => 'version', 'version_content_hash' => str_repeat('a', 64)],
            'versión sin huella' => ['target_kind' => 'version', 'version_id' => $version],
        ] as $que => $fila) {
            $this->assertRejected(fn () => $this->insertLink($fila), '23514', 'uscl_target_chk', $que);
        }
        // Inactiva exige fecha, quién y motivo.
        foreach (['deactivated_at', 'deactivated_by_admin_id', 'deactivation_reason'] as $falta) {
            $this->assertRejected(fn () => $this->insertLink([...$this->closing(), $falta => null]), '23514', 'uscl_deactivation_chk', $falta);
        }
        $activa = $this->insertLink();
        $this->assertRejected(fn () => DB::table(self::LINKS)->where('id', $activa)->update(['status' => 'inactive']), '23514', 'uscl_deactivation_chk', 'desactivar sin cierre');

        // Lo válido pasa.
        $this->insertLink(['campaign_id' => '2', 'target_kind' => 'version', 'version_id' => $version, 'version_content_hash' => str_repeat('a', 64)]);
        $this->insertLink(['campaign_id' => '3', ...$this->closing()]);
        $this->assertSame(1, DB::table(self::LINKS)->where('id', $activa)->update($this->closing()));
        $this->assertSame(3, DB::table(self::LINKS)->count());
    }

    public function test_como_mucho_una_asociacion_activa_por_campana(): void
    {
        $primera = $this->insertLink();
        $this->assertUniqueViolation(fn () => $this->insertLink(), 'uscl_one_active', self::LINKS, ['ad_account_id', 'campaign_id']);
        // La misma campaña en otra cuenta, u otra campaña de la misma cuenta: sí.
        $this->insertLink(['ad_account_id' => '9876543210']);
        $this->insertLink(['campaign_id' => '120200000000000999']);
        // Inactivas de la misma campaña, las que sean (el historial).
        $this->insertLink($this->closing());
        $this->insertLink($this->closing());
        // Cerrada la activa, cabe otra.
        DB::table(self::LINKS)->where('id', $primera)->update($this->closing());
        $this->insertLink();

        $this->assertSame(1, DB::table(self::LINKS)->where('ad_account_id', '1234567890')->where('campaign_id', '120200000000000387')->where('status', 'active')->count());
        $this->assertSame(6, DB::table(self::LINKS)->count());
    }

    // ── Números canarios ────────────────────────────────────────────────────

    public function test_los_numeros_canarios_no_se_borran_ni_cambian_su_registro(): void
    {
        $id = $this->insertCanary(['channel' => 'voice', 'phone_encrypted' => self::CIPHER]);

        foreach (self::CANARY_IMMUTABLE as $columna) {
            $this->assertForbidden(fn () => DB::table(self::CANARIES)->where('id', $id)->update([$columna => DB::raw($columna)]),
                self::CANARY_IMMUTABLE_REASON, 'UPDATE', self::CANARIES, $columna);
        }
        $this->assertForbidden(fn () => DB::table(self::CANARIES)->where('id', $id)->delete(), self::CANARY_DELETE, 'DELETE', self::CANARIES, 'DELETE por id');
        $this->assertForbidden(fn () => DB::table(self::CANARIES)->delete(), self::CANARY_DELETE, 'DELETE', self::CANARIES, 'DELETE sin WHERE');

        $this->assertSame(1, DB::table(self::CANARIES)->count());
        $this->assertSame(self::CIPHER, DB::table(self::CANARIES)->where('id', $id)->value('phone_encrypted'));
    }

    public function test_la_maquina_de_estados_de_un_canario_pasa_por_la_base_y_revocado_es_definitivo(): void
    {
        // §I4.4, con UPDATE condicionados como los del servicio: registrar →
        // autorizar (otra persona) → activar (≠ quien registró) → desactivar →
        // activar → versión esperada → revocar.
        $w = $this->insertCanary();
        $this->assertSame(1, $this->authorize($w, self::AUTHORIZER));
        $this->assertSame(1, $this->activate($w, self::THIRD));
        $this->assertSame(1, $this->deactivate($w));
        $this->assertSame(1, $this->activate($w, self::AUTHORIZER));
        $this->assertSame(1, DB::table(self::CANARIES)->where('id', $w)->where('status', '<>', 'revoked')->update(['expected_version_id' => $this->insertVersion('APPROVED')]));
        $this->assertSame(1, DB::table(self::CANARIES)->where('id', $w)->where('status', '<>', 'revoked')->update(['expected_version_id' => null]));
        $this->assertSame(1, $this->revoke($w));

        // Un UPDATE condicionado que no encuentra la fila en su estado no dispara nada.
        $this->assertSame(0, $this->authorize($w, self::AUTHORIZER));
        $this->assertSame(0, $this->revoke($w));

        // Revocado es definitivo: NINGÚN UPDATE.
        foreach ([
            'volver a pendiente' => ['status' => 'pending'],
            'versión esperada' => ['expected_version_id' => null],
            'el motivo' => ['revoke_reason' => 'Otro motivo'],
            'updated_at' => ['updated_at' => now()],
        ] as $que => $cambio) {
            $this->assertForbidden(fn () => DB::table(self::CANARIES)->where('id', $w)->update($cambio), self::CANARY_FINAL, 'UPDATE', self::CANARIES, $que);
        }

        // Voz: revocar borra el cifrado en la MISMA sentencia.
        $v = $this->insertCanary(['channel' => 'voice', 'phone_encrypted' => self::CIPHER]);
        $this->assertSame(1, $this->authorize($v, self::AUTHORIZER));
        $this->assertSame(1, $this->revoke($v));
        $this->assertNull(DB::table(self::CANARIES)->where('id', $v)->value('phone_encrypted'));
        $this->assertSame(['revoked', 'revoked'], DB::table(self::CANARIES)->orderBy('id')->pluck('status')->all());
    }

    public function test_el_cifrado_de_un_canario_solo_se_borra(): void
    {
        $v = $this->insertCanary(['channel' => 'voice', 'phone_encrypted' => self::CIPHER]);
        $w = $this->insertCanary();

        // Cambiarlo o escribirlo donde no lo había: no (el cast `encrypted` no vuelve a cifrar).
        $this->assertForbidden(fn () => DB::table(self::CANARIES)->where('id', $v)->update(['phone_encrypted' => self::CIPHER.'x']),
            self::CANARY_CIPHER, 'UPDATE', self::CANARIES, 'cambiar el cifrado');
        $this->assertForbidden(fn () => DB::table(self::CANARIES)->where('id', $w)->update(['phone_encrypted' => self::CIPHER]),
            self::CANARY_CIPHER, 'UPDATE', self::CANARIES, 'cifrar un WhatsApp');
        // El mismo valor, o NULL sobre NULL, no cambia nada: pasa.
        $this->assertSame(1, DB::table(self::CANARIES)->where('id', $v)->update(['phone_encrypted' => DB::raw('phone_encrypted')]));
        $this->assertSame(1, DB::table(self::CANARIES)->where('id', $w)->update(['phone_encrypted' => null]));

        // Borrarlo sin revocar: el disparador lo deja (solo encoge), pero en PG
        // `uscn_voice_cipher_chk` exige el cifrado a un número de voz vivo.
        $borrar = fn () => DB::table(self::CANARIES)->where('id', $v)->update(['phone_encrypted' => null]);
        if ($this->isPostgres()) {
            $this->assertRejected($borrar, '23514', 'uscn_voice_cipher_chk');
            $this->assertSame(self::CIPHER, DB::table(self::CANARIES)->where('id', $v)->value('phone_encrypted'));

            return;
        }
        $this->assertSame(1, DB::transaction($borrar), 'en SQLite (sin CHECK) lo vigila el servicio');
        $this->assertNull(DB::table(self::CANARIES)->where('id', $v)->value('phone_encrypted'));
    }

    public function test_solo_en_postgres_los_check_de_los_canarios_y_los_dos_cuatro_ojos(): void
    {
        $this->requirePostgres('SQLite no admite añadir CHECK a una tabla existente: lo vigila el servicio.');

        $this->assertSame(['whatsapp', 'voice'], $this->checkValues('uscn_channel_chk', self::CANARIES));
        $this->assertSame(['pending', 'authorized', 'active', 'revoked'], $this->checkValues('uscn_status_chk', self::CANARIES));

        // Cada fila rompe UN solo CHECK, salvo el canal inventado, que rompe
        // también `uscn_voice_cipher_chk` (no es WhatsApp y no lleva cifrado):
        // PG nombra el de dominio, primero por nombre y por creación.
        foreach ([
            'uscn_channel_chk' => ['channel' => 'sms'],
            'uscn_status_chk' => ['status' => 'paused', 'authorized_by_admin_id' => self::AUTHORIZER],
            'uscn_consent_chk' => ['consent_attested' => false],
            'uscn_hash_chk' => ['phone_hash' => str_repeat('A', 64)],
            'uscn_last4_chk' => ['phone_last4' => '48a1'],
            'uscn_whatsapp_no_cipher_chk' => ['phone_encrypted' => self::CIPHER],
            'uscn_voice_cipher_chk' => ['channel' => 'voice', 'phone_encrypted' => null],
            'uscn_authorized_chk' => ['status' => 'authorized'],
            'uscn_active_chk' => ['status' => 'active', 'authorized_by_admin_id' => self::AUTHORIZER],
            'uscn_revoke_chk' => ['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_admin_id' => self::THIRD],
        ] as $check => $fila) {
            $this->assertRejected(fn () => $this->insertCanary($fila), '23514', $check);
        }
        $this->assertRejected(fn () => $this->insertCanary(['phone_hash' => substr($this->phoneHash(), 0, 63)]), '23514', 'uscn_hash_chk', '63 caracteres');
        // Revocado conserva su cifrado: no (el cifrado, DESPUÉS de la revocación, que lo pone a NULL).
        $this->assertRejected(fn () => $this->insertCanary([...$this->revocation(), 'channel' => 'voice', 'phone_encrypted' => self::CIPHER]), '23514', 'uscn_revoke_chk', 'revocado con cifrado');

        // Los dos cuatro ojos, con un UPDATE crudo (el servicio no los salta, y la base tampoco).
        $n = $this->insertCanary();
        $this->assertRejected(fn () => $this->authorize($n, self::REGISTRANT), '23514', 'uscn_four_eyes_chk');
        $this->assertSame(1, $this->authorize($n, self::AUTHORIZER));
        $this->assertRejected(fn () => $this->activate($n, self::REGISTRANT), '23514', 'uscn_activation_four_eyes_chk');
        // Activar puede hacerlo quien autorizó (≠ quien registró).
        $this->assertSame(1, $this->activate($n, self::AUTHORIZER));
        $this->assertRejected(fn () => DB::table(self::CANARIES)->where('id', $n)->update(['activated_by_admin_id' => self::REGISTRANT]), '23514', 'uscn_activation_four_eyes_chk', 'reescribir quien activó');
        $this->assertRejected(fn () => DB::table(self::CANARIES)->where('id', $n)->update(['authorized_by_admin_id' => self::REGISTRANT]), '23514', 'uscn_four_eyes_chk', 'reescribir quien autorizó');

        // Lo válido pasa: un WhatsApp, una voz, y una voz revocada sin cifrado.
        $this->insertCanary();
        $this->insertCanary(['channel' => 'voice', 'phone_encrypted' => self::CIPHER]);
        $this->insertCanary(['channel' => 'voice', ...$this->revocation()]);
        $this->assertSame(4, DB::table(self::CANARIES)->count());
    }

    public function test_una_fila_viva_por_canal_y_telefono(): void
    {
        $telefono = $this->phoneHash();
        $primera = $this->insertCanary(['phone_hash' => $telefono]);
        $this->assertUniqueViolation(fn () => $this->insertCanary(['phone_hash' => $telefono]), 'uscn_one_live', self::CANARIES, ['channel', 'phone_hash']);
        // WhatsApp y voz del mismo teléfono son dos registros.
        $this->insertCanary(['phone_hash' => $telefono, 'channel' => 'voice', 'phone_encrypted' => self::CIPHER]);
        // Autorizado o activo sigue vivo.
        $this->authorize($primera, self::AUTHORIZER);
        $this->assertUniqueViolation(fn () => $this->insertCanary(['phone_hash' => $telefono]), 'uscn_one_live', self::CANARIES, ['channel', 'phone_hash']);
        $this->activate($primera, self::AUTHORIZER);
        $this->assertUniqueViolation(fn () => $this->insertCanary(['phone_hash' => $telefono]), 'uscn_one_live', self::CANARIES, ['channel', 'phone_hash']);
        // Revocado sale del índice: el teléfono se puede volver a registrar.
        $this->revoke($primera);
        $segunda = $this->insertCanary(['phone_hash' => $telefono]);
        $this->revoke($segunda);
        $this->insertCanary(['phone_hash' => $telefono]);

        $this->assertSame(1, DB::table(self::CANARIES)->where('channel', 'whatsapp')->where('phone_hash', $telefono)->where('status', '<>', 'revoked')->count());
        $this->assertSame(4, DB::table(self::CANARIES)->count());
    }

    // ── Instantáneas de integración ─────────────────────────────────────────

    public function test_las_instantaneas_son_de_solo_insercion(): void
    {
        $id = $this->insertSnapshot();
        $this->insertSnapshot(['kind' => 'probe', 'outcome' => 'ok', 'actor_type' => 'admin', 'actor_admin_id' => self::THIRD, 'client_request_id' => (string) Str::uuid()]);

        foreach ([
            'UPDATE' => [
                fn () => DB::table(self::SNAPSHOTS)->where('id', $id)->update(['note' => 'reescrita']),
                fn () => DB::table(self::SNAPSHOTS)->where('id', $id)->update(['facts' => DB::raw('facts')]),
                fn () => DB::table(self::SNAPSHOTS)->update(['actor_name' => 'todas']),
                fn () => DB::table(self::SNAPSHOTS)->upsert([$this->snapshotRow(['uuid' => DB::table(self::SNAPSHOTS)->where('id', $id)->value('uuid')])], ['uuid'], ['note']),
            ],
            'DELETE' => [
                fn () => DB::table(self::SNAPSHOTS)->where('id', $id)->delete(),
                fn () => DB::table(self::SNAPSHOTS)->delete(),
            ],
        ] as $operacion => $intentos) {
            foreach ($intentos as $i => $intento) {
                $this->assertForbidden($intento, self::SNAPSHOT_REASON, $operacion, self::SNAPSHOTS, "{$operacion} #{$i}");
            }
        }
        $this->assertSame(0, DB::table(self::SNAPSHOTS)->where('id', -1)->update(['note' => 'nada']));

        // Las del comando van sin `client_request_id` (varias NULL caben); la de una persona, una vez.
        $this->insertSnapshot();
        $crid = (string) Str::uuid();
        $this->insertSnapshot(['kind' => 'probe', 'outcome' => 'degraded', 'actor_type' => 'admin', 'actor_admin_id' => self::THIRD, 'client_request_id' => $crid]);
        $this->assertUniqueViolation(fn () => $this->insertSnapshot(['client_request_id' => $crid]), 'ultron_studio_integration_snapshots_client_request_id_unique', self::SNAPSHOTS, ['client_request_id']);
        $this->assertSame(4, DB::table(self::SNAPSHOTS)->count());
    }

    public function test_solo_en_postgres_los_check_de_las_instantaneas(): void
    {
        $this->requirePostgres('SQLite no admite añadir CHECK a una tabla existente: lo vigila el servicio.');

        $this->assertSame(['meta_ads', 'twilio', 'voice'], $this->checkValues('usis_provider_chk', self::SNAPSHOTS));
        $this->assertSame(['config', 'probe'], $this->checkValues('usis_kind_chk', self::SNAPSHOTS));
        $this->assertSame(['admin', 'system'], $this->checkValues('usis_actor_type_chk', self::SNAPSHOTS));

        foreach ([
            'usis_provider_chk' => ['provider' => 'meta'],
            'usis_kind_chk' => ['kind' => 'other'],
            'usis_actor_type_chk' => ['actor_type' => 'robot', 'actor_admin_id' => self::THIRD],
            'usis_fingerprint_chk' => ['fingerprint' => strtoupper(hash('sha256', 'x'))],
            'usis_actor_chk' => ['actor_type' => 'admin', 'actor_admin_id' => null],
        ] as $check => $fila) {
            $this->assertRejected(fn () => $this->insertSnapshot($fila), '23514', $check);
        }
        foreach ([
            'sonda con un resultado fuera de la lista' => ['kind' => 'probe', 'outcome' => 'timeout'],
            'configuración con resultado' => ['kind' => 'config', 'outcome' => 'ok'],
            // D-2: el texto del contrato lo dejaba pasar (NULL OR FALSE es NULL).
            'sonda sin resultado' => ['kind' => 'probe', 'outcome' => null],
        ] as $que => $fila) {
            $this->assertRejected(fn () => $this->insertSnapshot($fila), '23514', 'usis_outcome_chk', $que);
        }

        foreach (['ok', 'degraded', 'auth_failed', 'unreachable', 'error'] as $resultado) {
            $this->insertSnapshot(['kind' => 'probe', 'outcome' => $resultado]);
        }
        foreach (['meta_ads', 'twilio', 'voice'] as $proveedor) {
            $this->insertSnapshot(['provider' => $proveedor, 'actor_type' => 'admin', 'actor_admin_id' => self::THIRD]);
        }
        $this->assertSame(8, DB::table(self::SNAPSHOTS)->count());
    }

    public function test_solo_en_postgres_truncate_de_las_tres_tablas(): void
    {
        $this->requirePostgres('TRUNCATE no existe en SQLite; allí `DELETE FROM` sin WHERE pasa por el disparador de DELETE (cubierto arriba).');

        $this->insertLink();
        $this->insertCanary();
        $this->insertSnapshot();
        foreach ([self::LINKS => self::LINK_DELETE, self::CANARIES => self::CANARY_DELETE, self::SNAPSHOTS => self::SNAPSHOT_REASON] as $tabla => $motivo) {
            $this->assertForbidden(fn () => DB::statement("TRUNCATE {$tabla} CASCADE"), $motivo, 'TRUNCATE', $tabla, "TRUNCATE {$tabla}");
            $this->assertSame(1, DB::table($tabla)->count(), $tabla);
        }
    }

    // ── El libro ────────────────────────────────────────────────────────────

    public function test_solo_en_postgres_el_libro_admite_los_siete_sujetos_y_ninguno_mas(): void
    {
        $this->requirePostgres('El CHECK de sujetos solo existe en PostgreSQL (F); en SQLite lo vigila `StudioAuditEvent::SUBJECT_TYPES`.');

        $this->assertSame(self::SEVEN_SUBJECTS, $this->checkValues('usae_subject_type_chk', self::AUDIT));
        foreach (self::SEVEN_SUBJECTS as $sujeto) {
            $this->insertEvent($sujeto, "{$sujeto}.prueba");
        }
        // Los nombres de §I8 caben en sus columnas.
        foreach (self::H6_EVENTS as $evento => $sujeto) {
            $this->insertEvent($sujeto, $evento, ['from_status' => 'authorized', 'to_status' => 'inactive']);
        }
        foreach (['campaign', 'canary', 'integrations', 'campaign_links', ''] as $inventado) {
            $this->assertRejected(fn () => $this->insertEvent($inventado, 'x.y'), '23514', 'usae_subject_type_chk', "«{$inventado}»");
        }
        $this->assertSame(7 + count(self::H6_EVENTS), DB::table(self::AUDIT)->count());
    }

    // ── Índices parciales ───────────────────────────────────────────────────

    public function test_los_indices_parciales_conservan_su_where_en_los_dos_motores(): void
    {
        foreach ([
            'uscl_one_active' => [self::LINKS, '(ad_account_id, campaign_id)', "status = 'active'", "((status)::text = 'active'::text)"],
            'uscn_one_live' => [self::CANARIES, '(channel, phone_hash)', "status <> 'revoked'", "((status)::text <> 'revoked'::text)"],
            // Los del Hito 5 siguen como estaban (el Hito 6 no reconstruye versiones).
            'usv_one_canary' => [self::VERSIONS, '(status)', "status = 'CANARY'", "((status)::text = 'CANARY'::text)"],
            'usv_one_published' => [self::VERSIONS, '(status)', "status = 'PUBLISHED'", "((status)::text = 'PUBLISHED'::text)"],
        ] as $indice => [$tabla, $columnas, $where, $predicadoPg]) {
            if ($this->isPostgres()) {
                $fila = DB::selectOne(
                    'SELECT i.indisunique AS unico, pg_get_expr(i.indpred, i.indrelid) AS predicado, pg_get_indexdef(i.indexrelid) AS def
                       FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND i.indrelid = ?::regclass', [$indice, $tabla]);
                $this->assertNotNull($fila, $indice);
                $this->assertTrue((bool) $fila->unico, $indice);
                $this->assertSame($predicadoPg, $fila->predicado, $indice);
                $this->assertSame("CREATE UNIQUE INDEX {$indice} ON public.{$tabla} USING btree {$columnas} WHERE {$predicadoPg}", $fila->def, $indice);

                continue;
            }

            $this->assertSame("CREATE UNIQUE INDEX {$indice} ON {$tabla} {$columnas} WHERE {$where}",
                DB::table('sqlite_master')->where('type', 'index')->where('name', $indice)->value('sql'));
            $lista = collect(DB::select("PRAGMA index_list('{$tabla}')"))->keyBy('name');
            $this->assertSame(1, (int) $lista[$indice]->unique, $indice);
            $this->assertSame(1, (int) $lista[$indice]->partial, $indice);
        }
    }

    // ── down() (contrato, «Reversión», nivel 4) ─────────────────────────────

    public function test_down_se_niega_con_cualquier_dato_del_hito_6_y_no_toca_nada(): void
    {
        $soloUnEvento = '(0 asociaciones de campaña, 0 números canarios, 0 instantáneas de integración y 1 eventos del libro';
        foreach ([
            'una asociación' => ['(1 asociaciones de campaña, 0 números canarios, 0 instantáneas de integración y 0 eventos', fn () => $this->insertLink()],
            'un número canario' => ['(0 asociaciones de campaña, 1 números canarios, 0 instantáneas de integración y 0 eventos', fn () => $this->insertCanary()],
            'una instantánea' => ['(0 asociaciones de campaña, 0 números canarios, 1 instantáneas de integración y 0 eventos', fn () => $this->insertSnapshot()],
            // Un evento con un sujeto nuevo, aunque las tablas estén vacías.
            'un evento campaign_link' => [$soloUnEvento, fn () => $this->insertEvent('campaign_link', 'campaign_link.created')],
            'un evento canary_number' => [$soloUnEvento, fn () => $this->insertEvent('canary_number', 'canary_number.registered')],
            'un evento integration' => [$soloUnEvento, fn () => $this->insertEvent('integration', 'integration.probe_run')],
        ] as $que => [$recuento, $siembra]) {
            // Cada caso en su SAVEPOINT: la fila sembrada no se puede borrar (para eso está la guarda).
            DB::beginTransaction();
            try {
                $siembra();
                foreach ([self::GUARDS, self::CREATE] as $migracion) {
                    $this->assertDownRefused($migracion, $recuento, $que);
                }
                $this->assertH6SchemaPresent();
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_down_sin_datos_del_hito_6_deja_el_esquema_del_hito_5_y_se_puede_volver_a_aplicar(): void
    {
        // Datos de F y H5 (una versión, eventos con sujetos de F): el CHECK de 4 sujetos los vuelve a validar.
        $this->insertVersion('APPROVED');
        $this->insertVersion('CANARY');
        $this->insertEvent('version', 'version.created');
        $this->insertEvent('system', 'system.baseline_imported');
        $libro = $this->sqliteObjectsOf(self::AUDIT);

        $this->migration(self::GUARDS)->down();
        $this->migration(self::CREATE)->down();

        foreach ([self::LINKS, self::CANARIES, self::SNAPSHOTS] as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), $tabla);
        }
        $this->assertSame(['usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());
        $this->assertSame($libro, $this->sqliteObjectsOf(self::AUDIT), 'el libro cambió en SQLite');
        if ($this->isPostgres()) {
            $this->assertSame(['draft', 'version', 'rule', 'system'], $this->checkValues('usae_subject_type_chk', self::AUDIT));
            $this->assertSame(self::PG_TRIGGERS_H5, $this->studioTriggerCount());
            $this->assertSame(self::PG_CHECKS_H5, $this->studioCheckCount());
            $this->assertRejected(fn () => $this->insertEvent('integration', 'integration.probe_run'), '23514', 'usae_subject_type_chk');
            // La función es de F: down() no la toca.
            $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'ultron_studio_forbid'")->n);
        } else {
            $this->assertSame(self::SQLITE_TRIGGERS_H5, $this->studioTriggerCount());
        }
        $this->assertSame(2, DB::table(self::VERSIONS)->count());
        $this->assertSame(2, DB::table(self::AUDIT)->count());

        $this->migration(self::CREATE)->up();
        $this->migration(self::GUARDS)->up();

        $this->assertH6SchemaPresent();
        $this->assertSame($libro, $this->sqliteObjectsOf(self::AUDIT), 'el libro cambió en SQLite');
        $this->assertSame($this->isPostgres() ? self::PG_TRIGGERS_H6 : self::SQLITE_TRIGGERS_H6, $this->studioTriggerCount());
        if ($this->isPostgres()) {
            $this->assertSame(self::PG_CHECKS_H6, $this->studioCheckCount());
        }
    }

    public function test_up_exige_que_cada_columna_este_clasificada(): void
    {
        foreach ([self::LINKS, self::CANARIES] as $tabla) {
            DB::beginTransaction();
            try {
                $this->migration(self::GUARDS)->down();
                DB::statement("ALTER TABLE {$tabla} ADD COLUMN columna_nueva text");
                try {
                    $this->migration(self::GUARDS)->up();
                    $this->fail("up() aplicó las guardas con una columna sin clasificar en {$tabla}");
                } catch (RuntimeException $e) {
                    $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                    $this->assertStringContainsString("{$tabla}: columnas sin clasificar [columna_nueva]", $e->getMessage());
                }
                // No creó nada antes de fallar.
                $this->assertSame([], $this->triggersOn(self::LINKS));
                $this->assertSame([], $this->triggersOn(self::CANARIES));
                $this->assertSame($this->isPostgres() ? self::PG_TRIGGERS_H5 : self::SQLITE_TRIGGERS_H5, $this->studioTriggerCount());
            } finally {
                DB::rollBack();
            }
        }
        $this->assertH6SchemaPresent();
    }

    // ────────────────────────────────────────────────────────────────────────

    private function migration(string $name): Migration
    {
        return $this->migrations[$name] ??= require database_path("migrations/{$name}.php");
    }

    private function assertDownRefused(string $migracion, string $recuento, string $que): void
    {
        try {
            $this->migration($migracion)->down();
            $this->fail("{$migracion}::down() no se negó con {$que}");
        } catch (RuntimeException $e) {
            $contexto = "{$migracion} con {$que}";
            $this->assertNotInstanceOf(QueryException::class, $e, $contexto.': '.$e->getMessage());
            $this->assertStringContainsString('down() se niega a revertir el Hito 6', $e->getMessage(), $contexto);
            $this->assertStringContainsString($recuento, $e->getMessage(), $contexto);
            $this->assertStringContainsString('«Reversión», nivel 4', $e->getMessage(), $contexto);
        }
    }

    /** Tablas, disparadores, parciales y (en PG) CHECK y 7 sujetos: nada se deshizo. */
    private function assertH6SchemaPresent(): void
    {
        foreach (self::PG_TRIGGERS as $tabla => $nombres) {
            $this->assertTrue(Schema::hasTable($tabla), $tabla);
            $this->assertSame($this->isPostgres() ? $nombres : array_values(array_filter($nombres, static fn (string $n): bool => ! str_ends_with($n, '_truncate'))),
                $this->triggersOn($tabla), $tabla);
        }
        $this->assertSame(['uscl_one_active', 'uscn_one_live', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());
        if ($this->isPostgres()) {
            foreach (self::PG_CHECKS as $tabla => $nombres) {
                $this->assertSame($nombres, $this->checksOn($tabla), $tabla);
            }
            $this->assertSame(self::SEVEN_SUBJECTS, $this->checkValues('usae_subject_type_chk', self::AUDIT));
        }
    }

    /**
     * La sentencia tiene que fallar en la BASE por un disparador, con el texto
     * de `ultron_studio_forbid()`. En un SAVEPOINT.
     */
    private function assertForbidden(Closure $sentencia, string $motivo, string $operacion, string $tabla, string $que): void
    {
        try {
            DB::transaction(fn () => $sentencia());
            $this->fail("La base dejó pasar: {$que}");
        } catch (QueryException $e) {
            $this->assertSame($this->isPostgres() ? 'P0001' : '23000', (string) $e->getCode(), $que.': '.$e->getMessage());
            $this->assertStringContainsString("ultron_studio: {$motivo} ({$operacion} en {$tabla})", $e->getMessage(), $que);
        }
    }

    private function assertRejected(Closure $sentencia, string $sqlstate, string $fragmento, string $que = ''): void
    {
        try {
            DB::transaction(fn () => $sentencia());
            $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento} {$que}).");
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, (string) $e->getCode(), $que.': '.$e->getMessage());
            $this->assertStringContainsString($fragmento, $e->getMessage(), $que);
        }
    }

    /** @param  list<string>  $columnas */
    private function assertUniqueViolation(Closure $sentencia, string $indice, string $tabla, array $columnas): void
    {
        $this->isPostgres()
            ? $this->assertRejected($sentencia, '23505', $indice)
            : $this->assertRejected($sentencia, '23000', 'UNIQUE constraint failed: '.implode(', ', array_map(static fn (string $c): string => "{$tabla}.{$c}", $columnas)));
    }

    private function insertVersion(string $status): int
    {
        return (int) DB::table(self::VERSIONS)->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'label' => 'Sembrada '.$status,
            'status' => $status,
            'content' => '{}',
            'content_hash' => str_repeat('a', 64),
            'catalog_version' => '2026-10-19.1',
            'catalog_hash' => str_repeat('b', 64),
            'source_draft_revision' => 1,
            'created_by_admin_id' => 1,
            'created_by_name' => 'Prueba',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Una asociación válida y activa a la línea base, salvo lo que se sobrescriba.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertLink(array $overrides = []): int
    {
        return (int) DB::table(self::LINKS)->insertGetId($this->linkRow($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function linkRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'ad_account_id' => '1234567890',
            'campaign_id' => '120200000000000387',
            'campaign_name_at_link' => 'Pauta octubre ***4821',
            'target_kind' => 'baseline',
            'version_id' => null,
            'version_content_hash' => null,
            'status' => 'active',
            'created_by_admin_id' => self::REGISTRANT,
            'created_by_name' => 'Asocia',
            'created_by_role' => 'Administrador',
            'created_at' => now(),
        ], $overrides);
    }

    /** @return array<string, mixed> el cierre completo de un periodo */
    private function closing(string $motivo = 'Ya no se usa en esta pauta'): array
    {
        return [
            'status' => 'inactive',
            'deactivated_at' => now(),
            'deactivated_by_admin_id' => self::AUTHORIZER,
            'deactivated_by_name' => 'Cierra',
            'deactivated_by_role' => 'Super Admin',
            'deactivation_reason' => $motivo,
        ];
    }

    /**
     * Un número de WhatsApp válido y pendiente, salvo lo que se sobrescriba.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertCanary(array $overrides = []): int
    {
        return (int) DB::table(self::CANARIES)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'channel' => 'whatsapp',
            'alias' => 'Móvil de pruebas 1',
            'phone_hash' => $this->phoneHash(),
            'phone_last4' => '4821',
            'country_code' => '57',
            'phone_encrypted' => null,
            'status' => 'pending',
            'expected_version_id' => null,
            'consent_attested' => true,
            'registered_by_admin_id' => self::REGISTRANT,
            'registered_by_name' => 'Registra',
            'registered_by_role' => 'Administrador',
            'status_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** Una huella distinta cada vez, con la forma de `PhoneFingerprint` (64 hex en minúsculas). */
    private function phoneHash(): string
    {
        return hash('sha256', 'telefono-de-prueba-'.(++$this->phones));
    }

    /** @return array<string, mixed> */
    private function revocation(): array
    {
        return [
            'status' => 'revoked',
            'phone_encrypted' => null,
            'revoked_by_admin_id' => self::THIRD,
            'revoked_by_name' => 'Revoca',
            'revoked_by_role' => 'Super Admin',
            'revoked_at' => now(),
            'revoke_reason' => 'Ya no se usa para pruebas',
        ];
    }

    private function authorize(int $id, int $por): int
    {
        return DB::table(self::CANARIES)->where('id', $id)->where('status', 'pending')->update([
            'status' => 'authorized', 'authorized_by_admin_id' => $por, 'authorized_by_name' => 'Autoriza', 'authorized_by_role' => 'Super Admin',
            'authorized_at' => now(), 'status_changed_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function activate(int $id, int $por): int
    {
        return DB::table(self::CANARIES)->where('id', $id)->where('status', 'authorized')->update([
            'status' => 'active', 'activated_by_admin_id' => $por, 'activated_by_name' => 'Activa', 'activated_by_role' => 'Super Admin',
            'activated_at' => now(), 'status_changed_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function deactivate(int $id): int
    {
        return DB::table(self::CANARIES)->where('id', $id)->where('status', 'active')
            ->update(['status' => 'authorized', 'status_changed_at' => now(), 'updated_at' => now()]);
    }

    /** Revocar borra el cifrado en la MISMA sentencia (§I4.4). */
    private function revoke(int $id): int
    {
        return DB::table(self::CANARIES)->where('id', $id)->whereIn('status', ['pending', 'authorized', 'active'])
            ->update([...$this->revocation(), 'status_changed_at' => now(), 'updated_at' => now()]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function insertSnapshot(array $overrides = []): int
    {
        return (int) DB::table(self::SNAPSHOTS)->insertGetId($this->snapshotRow($overrides));
    }

    /**
     * Una instantánea `config` del comando, salvo lo que se sobrescriba.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function snapshotRow(array $overrides = []): array
    {
        $facts = '{"otpDriver":"twilio"}';

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => null,
            'provider' => 'twilio',
            'kind' => 'config',
            'fingerprint' => hash('sha256', $facts),
            'facts' => $facts,
            'outcome' => null,
            'actor_type' => 'system',
            'actor_admin_id' => null,
            'actor_name' => 'ultron-studio:integrations-snapshot',
            'actor_role' => null,
            'note' => null,
            'created_at' => now(),
        ], $overrides);
    }

    /** @param  array<string, mixed>  $extra */
    private function insertEvent(string $sujeto, string $evento, array $extra = []): int
    {
        return (int) DB::table(self::AUDIT)->insertGetId(array_merge([
            'event' => $evento,
            'subject_type' => $sujeto,
            'subject_uuid' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_name' => 'prueba',
            'summary' => 'Sembrado por la prueba de la migración',
            'created_at' => now(),
        ], $extra));
    }

    /**
     * El SQL de `ultron_studio_forbid()` tal como lo recibe la base: el heredoc
     * de la migración, sin la sangría de su marca de cierre (como hace PHP).
     */
    private function forbidFunctionSql(string $migracion): string
    {
        $fuente = (string) file_get_contents(database_path("migrations/{$migracion}.php"));
        preg_match_all("/<<<'SQL'\\n(.*?)\\n([ \\t]*)SQL\\);/s", $fuente, $bloques, PREG_SET_ORDER);
        foreach ($bloques as [, $cuerpo, $sangria]) {
            if (str_contains($cuerpo, 'FUNCTION ultron_studio_forbid()')) {
                return (string) preg_replace('/^'.preg_quote($sangria, '/').'/m', '', $cuerpo);
            }
        }
        $this->fail("{$migracion} no declara ultron_studio_forbid()");
    }

    /** @return array<string, array{0: list<string>, 1: bool}> índices no primarios: nombre → [columnas, único] */
    private function indexesOf(string $tabla): array
    {
        $indices = [];
        foreach (Schema::getIndexes($tabla) as $i) {
            if (! $i['primary']) {
                $indices[$i['name']] = [$i['columns'], (bool) $i['unique']];
            }
        }
        ksort($indices);

        return $indices;
    }

    /** @return array<string, array{0: string, 1: list<string>, 2: string}> columnas → [tabla, columnas, on_delete] */
    private function foreignKeysOf(string $tabla): array
    {
        $fks = [];
        foreach (Schema::getForeignKeys($tabla) as $fk) {
            $fks[implode(',', $fk['columns'])] = [$fk['foreign_table'], $fk['foreign_columns'], $fk['on_delete']];
        }
        ksort($fks);

        return $fks;
    }

    /** @return list<string> ordenados por bytes (no por la colación de la base) */
    private function triggersOn(string $tabla, bool $rowOnly = false): array
    {
        $nombres = $this->isPostgres()
            ? array_map(static fn (object $f): string => $f->tgname, DB::select(
                'SELECT t.tgname FROM pg_trigger t WHERE NOT t.tgisinternal AND t.tgrelid = ?::regclass'.($rowOnly ? ' AND (t.tgtype & 1) = 1' : ''), [$tabla]))
            : DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $tabla)->pluck('name')->all();
        sort($nombres, SORT_STRING);

        return $nombres;
    }

    private function studioTriggerCount(): int
    {
        return $this->isPostgres()
            ? (int) DB::selectOne("SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%'")->n
            : DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'like', 'ultron_studio_%')->count();
    }

    /** @return list<string> */
    private function checksOn(string $tabla): array
    {
        $nombres = array_map(static fn (object $f): string => $f->conname, DB::select(
            "SELECT conname FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass", [$tabla]));
        sort($nombres, SORT_STRING);

        return $nombres;
    }

    private function studioCheckCount(): int
    {
        return (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%'")->n;
    }

    /** @return list<string> los valores de un CHECK de dominio (`col IN (…)`), en su orden */
    private function checkValues(string $check, string $tabla): array
    {
        $def = (string) DB::selectOne('SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [$check, $tabla])->def;
        preg_match_all("/'([A-Za-z_]+)'::character varying/", $def, $m);

        return $m[1];
    }

    /** @return list<string> */
    private function partialIndexesOn(string $tabla): array
    {
        if ($this->isPostgres()) {
            $nombres = array_map(static fn (object $f): string => $f->relname, DB::select(
                'SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = ?::regclass AND i.indpred IS NOT NULL', [$tabla]));
        } else {
            $nombres = collect(DB::select("PRAGMA index_list('{$tabla}')"))
                ->filter(static fn (object $i): bool => (int) $i->partial === 1)->pluck('name')->all();
        }
        sort($nombres, SORT_STRING);

        return $nombres;
    }

    /** @return list<string> todos los índices parciales de las tablas del Studio */
    private function studioPartialIndexes(): array
    {
        if ($this->isPostgres()) {
            $nombres = array_map(static fn (object $f): string => $f->relname, DB::select(
                "SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_class t ON t.oid = i.indrelid
                  WHERE t.relname LIKE 'ultron_studio_%' AND i.indpred IS NOT NULL"));
        } else {
            $nombres = [];
            foreach (DB::table('sqlite_master')->where('type', 'table')->where('name', 'like', 'ultron_studio_%')->pluck('name') as $tabla) {
                array_push($nombres, ...$this->partialIndexesOn($tabla));
            }
        }
        sort($nombres, SORT_STRING);

        return $nombres;
    }

    /** @return list<string> en SQLite, la definición de la tabla y de sus índices y disparadores; en PG, [] */
    private function sqliteObjectsOf(string $tabla): array
    {
        if ($this->isPostgres()) {
            return [];
        }

        return DB::table('sqlite_master')->where('tbl_name', $tabla)->orderBy('type')->orderBy('name')
            ->get(['type', 'name', 'sql'])->map(static fn (object $o): string => "{$o->type} {$o->name}: {$o->sql}")->all();
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function requirePostgres(string $porQue): void
    {
        if (! $this->isPostgres()) {
            // OMITIDA, no verde: el hueco tiene que verse en la suite.
            $this->markTestSkipped('Solo PostgreSQL: '.$porQue.' Ejecuta el grupo con docs/ultron-studio/ops/pgunit.sh.');
        }
    }
}
