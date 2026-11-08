<?php

namespace Tests\Feature\Telephony\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El registro de la telefonía (Hito 8, DB8), en los dos motores y con SQL a
 * pelo: eventos, consentimientos, exclusiones, consultas al RNE y el freno, de
 * SOLO INSERCIÓN; y los contactos, que se retiran y nunca se borran.
 *
 *  · Solo inserción: UPDATE y DELETE fallan en los dos motores (P0001 / 23000)
 *    y TRUNCATE en PG.
 *  · Eventos: deduplicados por (provider, kind, provider_event_key).
 *  · Consentimientos: los que carga una persona los confirma OTRA (cuatro ojos
 *    [P-18]): CHECK en PG y su disparador equivalente en SQLite.
 *  · Exclusiones: una revocación oral por llamada.
 *  · Freno: abrir y soltar son filas; una apertura se suelta UNA vez y lo que
 *    se suelta es una apertura.
 *  · Contactos: un vivo por teléfono; lo de su registro no cambia; retirado es
 *    definitivo; el cifrado solo se borra.
 *  · Solo en PG, los CHECK de forma de cada tabla, atribuidos a su nombre.
 */
class VoiceLedgerSchemaTest extends TestCase
{
    use RefreshDatabase;
    use VoiceSchemaFixtures;

    /** tabla → motivo del disparador de solo inserción (el texto del contrato). */
    private const INSERT_ONLY_REASONS = [
        'voice_call_events' => 'los eventos de llamada son de solo inserción',
        'voice_consents' => 'los consentimientos de voz son de solo inserción',
        'voice_exclusions' => 'las exclusiones de voz son de solo inserción',
        'voice_rne_checks' => 'las consultas al RNE son de solo inserción',
        'voice_brakes' => 'el freno de voz es de solo inserción',
    ];

    private const FOUR_EYES = 'un consentimiento de voz cargado por una persona lo confirma otra (cuatro ojos) (INSERT en voice_consents)';

    private const RELEASE = 'un freno se suelta una sola vez y lo que se suelta es una apertura (una fila release apunta a una open) (INSERT en voice_brakes)';

    /** Protección 3 del H8: ninguna prueba de voz sale a la red, tampoco las de esquema (`VoiceIsolationTest`). */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // ── Solo inserción ──────────────────────────────────────────────────────

    public function test_las_cinco_de_solo_insercion_no_se_modifican_ni_se_borran(): void
    {
        $ids = $this->seedOneOfEach();
        $porTabla = ['voice_call_events' => $ids['evento'], 'voice_consents' => $ids['consentimiento'], 'voice_exclusions' => $ids['exclusion'],
            'voice_rne_checks' => $ids['rne'], 'voice_brakes' => $ids['freno']];

        foreach (self::INSERT_ONLY_REASONS as $tabla => $motivo) {
            $id = $porTabla[$tabla];
            $this->assertGuarded(fn () => DB::statement("UPDATE {$tabla} SET created_at = created_at WHERE id = ?", [$id]), "{$motivo} (UPDATE en {$tabla})", "{$tabla}: UPDATE");
            $this->assertGuarded(fn () => DB::table($tabla)->where('id', $id)->delete(), "{$motivo} (DELETE en {$tabla})", "{$tabla}: DELETE");
            $this->assertGuarded(fn () => DB::table($tabla)->delete(), "{$motivo} (DELETE en {$tabla})", "{$tabla}: DELETE sin WHERE");
            if ($this->isPostgres()) {
                $this->assertGuarded(fn () => DB::statement("TRUNCATE {$tabla} CASCADE"), "{$motivo} (TRUNCATE en {$tabla})", "{$tabla}: TRUNCATE");
            }
            $this->assertSame(1, DB::table($tabla)->count(), $tabla);
        }
    }

    // ── Eventos ─────────────────────────────────────────────────────────────

    public function test_los_eventos_se_deduplican_por_proveedor_tipo_y_clave(): void
    {
        $this->insertEvent(['provider_event_key' => 'conv_123']);
        $this->assertUniqueRejects(fn () => $this->insertEvent(['provider_event_key' => 'conv_123']),
            'vce_dedupe_unique', 'voice_call_events', ['provider', 'kind', 'provider_event_key'], 'el mismo post-llamada otra vez');
        $this->assertAccepted(fn () => $this->insertEvent(['provider_event_key' => 'conv_123', 'kind' => 'call_initiation_failure']), 'otro tipo, la misma conversación');
        $this->assertAccepted(fn () => $this->insertEvent(['provider_event_key' => 'conv_123', 'provider' => 'twilio', 'kind' => 'cost_recorded']), 'otro proveedor');
        // Los internos sin clave no se deduplican entre sí.
        $llamada = $this->insertOutbound();
        foreach ([1, 2] as $vez) {
            $this->assertAccepted(fn () => $this->insertEvent(['call_id' => $llamada, 'provider' => 'laravel', 'kind' => 'tool_call', 'tool' => 'consultar_horarios',
                'outcome' => 'ok', 'provider_event_key' => null, 'actor_type' => 'system', 'actor_name' => 'voice:tools']), "herramienta {$vez}");
        }
        $this->assertSame(5, DB::table('voice_call_events')->count(), 'conv_123 dos veces por tipo y por proveedor, y las dos herramientas');
    }

    // ── Consentimientos: cuatro ojos ────────────────────────────────────────

    public function test_un_consentimiento_cargado_por_una_persona_lo_confirma_otra(): void
    {
        $contacto = $this->insertContact();
        $this->assertAccepted(fn () => $this->insertConsent($contacto), 'Ana carga, Beto confirma');

        foreach (['admin_with_evidence', 'written_form'] as $origen) {
            foreach ([
                'la misma persona confirma' => ['confirmed_by_admin_id' => 1, 'confirmed_by_name' => 'Ana'],
                'nadie confirma' => ['confirmed_by_admin_id' => null, 'confirmed_by_name' => null, 'confirmed_by_role' => null],
            ] as $que => $o) {
                $this->isPostgres()
                    ? $this->assertCheckRejects(fn () => $this->insertConsent($contacto, ['source' => $origen] + $o), 'vcs_four_eyes_chk', "{$origen}: {$que}")
                    : $this->assertRejected(fn () => $this->insertConsent($contacto, ['source' => $origen] + $o), '23000', self::PREFIX.self::FOUR_EYES, "{$origen}: {$que}");
            }
        }
        // La revocación no espera a nadie, y la petición grabada de una entrante la registra el sistema.
        $this->assertAccepted(fn () => $this->insertConsent($contacto, ['kind' => 'revoked', 'confirmed_by_admin_id' => null, 'confirmed_by_name' => null, 'confirmed_by_role' => null]), 'revocación');
        $this->assertAccepted(fn () => $this->insertConsent($contacto, ['source' => 'recorded_inbound_request', 'actor_type' => 'system', 'recorded_by_admin_id' => null,
            'recorded_by_name' => 'voice:inbound', 'confirmed_by_admin_id' => null, 'confirmed_by_name' => null, 'confirmed_by_role' => null]), 'petición grabada');
        $this->assertSame(3, DB::table('voice_consents')->count());
    }

    // ── Exclusiones ─────────────────────────────────────────────────────────

    public function test_una_revocacion_oral_por_llamada(): void
    {
        $llamada = $this->insertInbound();
        $oral = ['source' => 'spoken_opt_out', 'call_id' => $llamada, 'actor_type' => 'system', 'actor_admin_id' => null, 'actor_name' => 'voice:tools'];
        $this->assertAccepted(fn () => $this->insertExclusion($oral));
        $this->assertUniqueRejects(fn () => $this->insertExclusion($oral), 'vex_one_opt_out_per_call', 'voice_exclusions', ['call_id'], 'la herramienta repetida en la misma llamada');
        $this->assertAccepted(fn () => $this->insertExclusion(['call_id' => $llamada]), 'otra exclusión (admin) que nombra la misma llamada');
        $this->assertAccepted(fn () => $this->insertExclusion(['source' => 'spoken_opt_out', 'call_id' => $this->insertInbound(), 'actor_type' => 'system', 'actor_admin_id' => null, 'actor_name' => 'voice:tools']), 'otra llamada');
    }

    // ── El freno ────────────────────────────────────────────────────────────

    public function test_el_freno_se_abre_solo_y_se_suelta_una_vez_y_solo_una_apertura(): void
    {
        $abierto = $this->insertBrakeOpen();
        $this->assertSame(1, $this->openBrakes());

        $suelta = $this->insertBrakeRelease($abierto);
        $this->assertSame(0, $this->openBrakes());
        $this->assertUniqueRejects(fn () => $this->insertBrakeRelease($abierto, ['actor_admin_id' => 2, 'actor_name' => 'Beto']), 'vbr_release_once', 'voice_brakes', ['brake_id'], 'soltarlo otra vez');
        $this->assertRejected(fn () => $this->insertBrakeRelease($suelta), $this->isPostgres() ? 'P0001' : '23000', self::PREFIX.self::RELEASE, 'soltar una suelta');
        $this->assertGuarded(fn () => $this->insertBrakeOpen(['brake_id' => $abierto]), self::RELEASE, 'una apertura que apunta a otra');
        $this->assertGuarded(fn () => $this->insertBrakeRelease($abierto, ['brake_id' => null]), self::RELEASE, 'una suelta sin apertura');
        $this->isPostgres()
            ? $this->assertRejected(fn () => $this->insertBrakeRelease(999999), '23503', 'voice_brakes_brake_id_foreign', 'una apertura que no existe')
            : $this->assertRejected(fn () => $this->insertBrakeRelease(999999), '23000', 'FOREIGN KEY constraint failed', 'una apertura que no existe');

        // Abrir es idempotente por su clave; otra causa es otra apertura.
        $clave = DB::table('voice_brakes')->where('id', $abierto)->value('open_key');
        $this->assertUniqueRejects(fn () => $this->insertBrakeOpen(['open_key' => $clave]), 'voice_brakes_open_key_unique', 'voice_brakes', ['open_key'], 'la misma causa');
        $this->insertBrakeOpen(['reason' => 'seal_mismatch']);
        $this->assertSame(1, $this->openBrakes());
        $this->assertSame(3, DB::table('voice_brakes')->count());
    }

    // ── Contactos ───────────────────────────────────────────────────────────

    public function test_un_contacto_vivo_por_telefono_y_retirado_es_definitivo(): void
    {
        $hash = $this->hex('un-telefono');
        $id = $this->insertContact(['phone_hash' => $hash]);
        $this->assertUniqueRejects(fn () => $this->insertContact(['phone_hash' => $hash]), 'vct_one_live', 'voice_contacts', ['phone_hash'], 'el mismo teléfono vivo');

        $this->assertAccepted(fn () => DB::table('voice_contacts')->where('id', $id)->update(['consent_request_source' => 'written_form', 'consent_request_evidence' => 'Formulario de prueba',
            'consent_requested_by_admin_id' => 1, 'consent_requested_by_name' => 'Ana', 'consent_requested_at' => now()]), 'la petición de consentimiento');
        $this->assertAccepted(fn () => DB::table('voice_contacts')->where('id', $id)->update(['consent_request_source' => null, 'consent_request_evidence' => null,
            'consent_requested_by_admin_id' => null, 'consent_requested_by_name' => null, 'consent_requested_at' => null]), 'confirmada, se limpia');
        $this->assertAccepted(fn () => DB::table('voice_contacts')->where('id', $id)->update(['status' => 'retired', 'phone_encrypted' => null, 'retired_by_admin_id' => 1,
            'retired_by_name' => 'Ana', 'retired_at' => now(), 'retire_reason' => 'Lo pidió la persona', 'status_changed_at' => now()]), 'retirarlo');

        $this->assertGuarded(fn () => DB::table('voice_contacts')->where('id', $id)->update(['status' => 'active']), 'un contacto de voz retirado es definitivo (UPDATE en voice_contacts)', 'reactivarlo');
        $this->assertGuarded(fn () => DB::table('voice_contacts')->where('id', $id)->update(['retire_reason' => 'otro']), 'un contacto de voz retirado es definitivo (UPDATE en voice_contacts)', 'tocar su cierre');
        $this->assertAccepted(fn () => $this->insertContact(['phone_hash' => $hash]), 'retirado, se puede volver a registrar');
    }

    public function test_lo_del_registro_de_un_contacto_no_cambia_y_su_cifrado_solo_se_borra(): void
    {
        $id = $this->insertContact();
        foreach (['id', 'uuid', 'client_request_id', 'phone_hash', 'phone_last4', 'source_type', 'source_id', 'registered_by_admin_id', 'registered_by_name', 'registered_by_role', 'created_at'] as $columna) {
            $this->assertGuarded(fn () => DB::statement("UPDATE voice_contacts SET {$columna} = {$columna} WHERE id = ?", [$id]),
                'un contacto de voz es inmutable en su huella, sus 4 dígitos, su origen y su registro (UPDATE en voice_contacts)', $columna);
        }

        $cifrado = DB::table('voice_contacts')->where('id', $id)->value('phone_encrypted');
        $mensaje = 'el cifrado del teléfono de un contacto solo se borra: no se escribe ni se cambia después del registro (UPDATE en voice_contacts)';
        $this->assertGuarded(fn () => DB::table('voice_contacts')->where('id', $id)->update(['phone_encrypted' => 'otro-cifrado']), $mensaje, 'volver a cifrar (un save() del cast encrypted)');
        $this->assertAccepted(fn () => DB::table('voice_contacts')->where('id', $id)->update(['phone_encrypted' => $cifrado]), 'el mismo valor');
        $this->assertGuarded(fn () => DB::table('voice_contacts')->where('id', $id)->delete(), 'los contactos de voz se retiran, no se borran (DELETE en voice_contacts)');

        $this->isPostgres()
            ? $this->assertCheckRejects(fn () => DB::table('voice_contacts')->where('id', $id)->update(['phone_encrypted' => null]), 'vct_cipher_chk', 'borrarlo sin retirarlo')
            : $this->assertAccepted(fn () => DB::table('voice_contacts')->where('id', $id)->update(['phone_encrypted' => null]), 'en SQLite lo vigila el servicio');
    }

    // ── Los CHECK de forma (solo PG) ────────────────────────────────────────

    /** @return array<string, array{string, array<string, mixed>, list<string>}> */
    public static function badLedgerRows(): array
    {
        $hex = hash('sha256', 'x');

        return [
            // Contactos.
            'contacto: lead sin id' => ['voice_contacts', ['source_type' => 'lead'], ['vct_source_chk']],
            'contacto: manual con id' => ['voice_contacts', ['source_id' => 9], ['vct_source_chk']],
            'contacto: origen del motor' => ['voice_contacts', ['source_type' => 'calls', 'source_id' => 9], ['vct_source_chk']],
            'contacto: huella en mayúsculas' => ['voice_contacts', ['phone_hash' => strtoupper($hex)], ['vct_phone_chk']],
            'contacto: 4 dígitos con letras' => ['voice_contacts', ['phone_last4' => '07x7'], ['vct_phone_chk']],
            'contacto: activo sin cifrado' => ['voice_contacts', ['phone_encrypted' => null], ['vct_cipher_chk']],
            'contacto: retirado con cifrado' => ['voice_contacts', ['status' => 'retired', 'retired_at' => '2026-11-03 12:00:00', 'retired_by_admin_id' => 1, 'retire_reason' => 'x'], ['vct_cipher_chk']],
            'contacto: retirado sin motivo' => ['voice_contacts', ['status' => 'retired', 'phone_encrypted' => null, 'retired_at' => '2026-11-03 12:00:00', 'retired_by_admin_id' => 1], ['vct_retire_chk']],
            'contacto: activo con retirada' => ['voice_contacts', ['retired_at' => '2026-11-03 12:00:00'], ['vct_retire_chk']],
            'contacto: estado desconocido' => ['voice_contacts', ['status' => 'paused'], ['vct_cipher_chk', 'vct_retire_chk', 'vct_status_chk']],
            'contacto: petición a medias' => ['voice_contacts', ['consent_request_source' => 'written_form'], ['vct_consent_request_chk']],
            'contacto: petición de origen sin persona' => ['voice_contacts', ['consent_request_source' => 'recorded_inbound_request', 'consent_request_evidence' => 'x',
                'consent_requested_by_admin_id' => 1, 'consent_requested_by_name' => 'Ana', 'consent_requested_at' => '2026-11-03 12:00:00'], ['vct_consent_request_chk']],
            'contacto: petición sin quién' => ['voice_contacts', ['consent_request_source' => 'written_form', 'consent_request_evidence' => 'x', 'consent_requested_at' => '2026-11-03 12:00:00'], ['vct_consent_request_chk']],
            // RNE.
            'rne: resultado desconocido' => ['voice_rne_checks', ['result' => 'maybe'], ['vrne_result_chk']],
            'rne: método desconocido' => ['voice_rne_checks', ['method' => 'scraping'], ['vrne_method_chk']],
            'rne: el portal consultado por el sistema' => ['voice_rne_checks', ['actor_type' => 'system', 'actor_admin_id' => null], ['vrne_actor_chk']],
            'rne: persona sin id' => ['voice_rne_checks', ['actor_admin_id' => null], ['vrne_actor_chk']],
            'rne: consultado en el futuro' => ['voice_rne_checks', ['checked_at' => '2099-01-01 00:00:00'], ['vrne_time_chk']],
            'rne: huella corta' => ['voice_rne_checks', ['phone_hash' => substr($hex, 0, 63).'g'], ['vrne_phone_chk']],
            // Eventos.
            'evento: tipo desconocido' => ['voice_call_events', ['kind' => 'transcript_saved'], ['vce_kind_chk']],
            'evento: proveedor desconocido' => ['voice_call_events', ['provider' => 'openai'], ['vce_provider_chk']],
            'evento: herramienta fuera del catálogo' => ['voice_call_events', ['kind' => 'tool_call', 'tool' => 'cobrar', 'call_id' => 1], ['vce_tool_chk']],
            'evento: herramienta sin nombre' => ['voice_call_events', ['kind' => 'tool_call', 'call_id' => 1], ['vce_tool_chk']],
            'evento: nombre de herramienta en otro tipo' => ['voice_call_events', ['tool' => 'consultar_planes'], ['vce_tool_chk']],
            'evento: veredicto de la guarda sin llamada' => ['voice_call_events', ['kind' => 'guard_verdict'], ['vce_call_chk']],
            'evento: llamada desconocida con llamada' => ['voice_call_events', ['kind' => 'unknown_provider_call', 'call_id' => 1], ['vce_call_chk']],
            'evento: persona sin id' => ['voice_call_events', ['actor_type' => 'admin', 'actor_name' => 'Ana'], ['vce_actor_chk']],
            'evento: clave con un teléfono' => ['voice_call_events', ['provider_event_key' => '+573000000099'], ['vce_codes_chk']],
            'evento: resultado en texto libre' => ['voice_call_events', ['outcome' => 'Dijo que sí'], ['vce_codes_chk']],
            'evento: códigos de más de 4 KB' => ['voice_call_events', ['codes' => '{"t":"'.str_repeat('a', 4100).'"}'], ['vce_codes_chk']],
            // Consentimientos.
            'consentimiento: el de WhatsApp' => ['voice_consents', ['channel' => 'whatsapp'], ['vcs_channel_chk']],
            'consentimiento: otra finalidad' => ['voice_consents', ['purpose' => 'billing'], ['vcs_purpose_chk']],
            'consentimiento: tipo desconocido' => ['voice_consents', ['kind' => 'maybe'], ['vcs_kind_chk']],
            'consentimiento: origen desconocido' => ['voice_consents', ['source' => 'whatsapp_optin'], ['vcs_source_chk']],
            'consentimiento: el sistema carga un formulario' => ['voice_consents', ['actor_type' => 'system', 'recorded_by_admin_id' => null, 'source' => 'written_form'], ['vcs_actor_chk', 'vcs_four_eyes_chk']],
            'consentimiento: persona sin id' => ['voice_consents', ['recorded_by_admin_id' => null], ['vcs_actor_chk', 'vcs_four_eyes_chk']],
            'consentimiento: cargado después de guardarlo' => ['voice_consents', ['recorded_at' => '2099-01-01 00:00:00'], ['vcs_time_chk']],
            // Exclusiones.
            'exclusión: origen desconocido' => ['voice_exclusions', ['source' => 'whatsapp'], ['vex_source_chk']],
            'exclusión: revocación oral de una persona' => ['voice_exclusions', ['source' => 'spoken_opt_out', 'call_id' => 1], ['vex_source_shape_chk']],
            'exclusión: revocación oral sin llamada' => ['voice_exclusions', ['source' => 'spoken_opt_out', 'actor_type' => 'system', 'actor_admin_id' => null], ['vex_source_shape_chk']],
            'exclusión: del RNE sin consulta' => ['voice_exclusions', ['source' => 'rne', 'actor_type' => 'system', 'actor_admin_id' => null], ['vex_source_shape_chk']],
            'exclusión: consulta en otro origen' => ['voice_exclusions', ['rne_check_id' => 1], ['vex_source_shape_chk']],
            'exclusión: señal del lead sin contacto' => ['voice_exclusions', ['source' => 'lead_do_not_contact', 'actor_type' => 'system', 'actor_admin_id' => null], ['vex_source_shape_chk']],
            'exclusión: admin del sistema' => ['voice_exclusions', ['actor_type' => 'system', 'actor_admin_id' => null], ['vex_source_shape_chk']],
            'exclusión: persona sin id' => ['voice_exclusions', ['actor_admin_id' => null], ['vex_actor_chk']],
            'exclusión: 4 dígitos de más' => ['voice_exclusions', ['phone_last4' => '070'], ['vex_phone_chk']],
            // El freno.
            'freno: tipo desconocido' => ['voice_brakes', ['kind' => 'pause'], ['vbr_kind_chk', 'vbr_shape_chk']],
            'freno: causa desconocida' => ['voice_brakes', ['reason' => 'manual'], ['vbr_reason_chk']],
            'freno: lo abre una persona' => ['voice_brakes', ['actor_type' => 'admin', 'actor_admin_id' => 1, 'actor_name' => 'Ana'], ['vbr_shape_chk']],
            'freno: apertura sin clave' => ['voice_brakes', ['open_key' => null], ['vbr_shape_chk']],
            'freno: apertura con nota' => ['voice_brakes', ['note' => 'x'], ['vbr_shape_chk']],
            'freno: sistema con id' => ['voice_brakes', ['actor_admin_id' => 1], ['vbr_actor_chk']],
            'freno: clave con espacios' => ['voice_brakes', ['open_key' => 'event: 1'], ['vbr_codes_chk']],
            'freno: evidencia de más de 4 KB' => ['voice_brakes', ['evidence' => '{"t":"'.str_repeat('a', 4100).'"}'], ['vbr_codes_chk']],
        ];
    }

    /** @param  array<string, mixed>  $cambio  @param  list<string>  $esperados */
    #[DataProvider('badLedgerRows')]
    public function test_solo_en_postgres_cada_check_de_forma_del_registro(string $tabla, array $cambio, array $esperados): void
    {
        $this->requirePostgres('los CHECK de la telefonía solo existen en PostgreSQL (en SQLite los vigila el servicio).');

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

    public function test_solo_en_postgres_las_formas_validas_del_registro_pasan_todos_los_check(): void
    {
        $this->requirePostgres('los CHECK de la telefonía solo existen en PostgreSQL.');

        foreach ([
            'voice_rne_checks' => [['method' => 'api', 'actor_type' => 'system', 'actor_admin_id' => null, 'actor_name' => 'voice:rne'], ['result' => 'listed']],
            'voice_call_events' => [
                ['kind' => 'tool_call', 'tool' => 'no_volver_a_llamar', 'call_id' => 1, 'outcome' => 'excluded', 'provider' => 'laravel', 'provider_event_key' => null, 'actor_type' => 'system', 'actor_name' => 'voice:tools'],
                ['kind' => 'unknown_provider_call', 'provider' => 'twilio', 'provider_event_key' => $this->callSid('x'), 'codes' => '{"sid":"..."}'],
                ['kind' => 'hangup_requested', 'call_id' => 1, 'provider' => 'laravel', 'provider_event_key' => null, 'actor_type' => 'admin', 'actor_admin_id' => 1, 'actor_name' => 'Ana'],
            ],
            'voice_consents' => [['kind' => 'revoked', 'confirmed_by_admin_id' => null, 'confirmed_by_name' => null], ['source' => 'written_form']],
            'voice_exclusions' => [
                ['source' => 'rne', 'rne_check_id' => 1, 'actor_type' => 'system', 'actor_admin_id' => null],
                ['source' => 'lead_consent_denied', 'contact_id' => 1, 'actor_type' => 'system', 'actor_admin_id' => null],
            ],
            'voice_brakes' => [['kind' => 'release', 'brake_id' => 1, 'reason' => null, 'open_key' => null, 'evidence' => null, 'note' => 'Revisado', 'client_request_id' => (string) Str::uuid(), 'actor_type' => 'admin', 'actor_admin_id' => 1]],
            'voice_contacts' => [['source_type' => 'lead', 'source_id' => 9], ['status' => 'retired', 'phone_encrypted' => null, 'retired_at' => '2026-11-03 12:00:00', 'retired_by_admin_id' => 1, 'retire_reason' => 'x']],
        ] as $tabla => $formas) {
            foreach ($formas as $i => $forma) {
                $this->assertSame([], $this->violatedChecks($tabla, array_merge($this->validRow($tabla), $forma)), "{$tabla} #{$i}");
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────

    /** Aperturas sin su suelta: «hay freno». */
    private function openBrakes(): int
    {
        return (int) DB::scalar("SELECT count(*) FROM voice_brakes o WHERE o.kind = 'open' AND NOT EXISTS (SELECT 1 FROM voice_brakes r WHERE r.brake_id = o.id)");
    }

    /**
     * La fila válida de cada tabla, como la siembran los ayudantes, sin
     * insertarla (para la sonda de los CHECK).
     *
     * @return array<string, mixed>
     */
    private function validRow(string $tabla): array
    {
        DB::beginTransaction();
        try {
            $id = match ($tabla) {
                'voice_contacts' => $this->insertContact(),
                'voice_rne_checks' => $this->insertRneCheck(),
                'voice_call_events' => $this->insertEvent(),
                'voice_consents' => $this->insertConsent($this->insertContact()),
                'voice_exclusions' => $this->insertExclusion(),
                'voice_brakes' => $this->insertBrakeOpen(),
            };
            $fila = (array) DB::table($tabla)->where('id', $id)->first();
        } finally {
            DB::rollBack();
        }
        unset($fila['id']);

        return $fila;
    }
}
