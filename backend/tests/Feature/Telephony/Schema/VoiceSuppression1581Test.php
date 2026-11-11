<?php

namespace Tests\Feature\Telephony\Schema;

use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\Telephony\VoicePhone;
use App\Services\UltronStudio\StudioVoicePublications;
use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * LA SUPRESIÓN LEY 1581 DE LA VOZ, DE VERDAD, EN POSTGRESQL 14 (B-T3 del cierre
 * integral; contrato H8 D8-17 y D8-18; H-24 de la preparación del plan de pago).
 *
 * Ejecuta `docs/ultron-studio/ops/supresion-1581.sql` TAL CUAL con `psql`, como
 * el runbook (los parámetros por la entrada estándar, delante del guion), sobre
 * filas sintéticas CONFIRMADAS en las 11 tablas de la voz, con el modelo de
 * roles de producción: las tablas son de un dueño SIN superusuario y la
 * aplicación es otro rol, al que las migraciones quitan UPDATE y DELETE en las
 * tablas de solo inserción. Una sola prueba de punta a punta (migrar cuesta
 * minuto y medio): cada paso lleva su motivo en el mensaje de sus aserciones.
 *
 *  0. En seco, las secciones del H3–H7 (texto en el libro) y del H9 (subject)
 *     siguen pasando; no cambia nada.
 *  1. En seco, por texto, teléfono y huella de voz: los recuentos por tabla y
 *     columna, lo VIVO avisado, lo que solo se informa y ninguna columna de la
 *     voz sin política; no cambia nada; la salida no lleva datos.
 *  2. Con :dry 0 y algo vivo (un contacto sin retirar, una llamada en curso):
 *     ABORTA sin tocar nada y con los disparadores como estaban.
 *  3. La aplicación (su rol) retira el contacto y cancela la llamada.
 *  4. La redacción real, con el DUEÑO conectado directamente (sin superusuario
 *     en la sesión): 0 filas con la huella, la forma suprimida que admiten los
 *     CHECK, los textos redactados, lo informado intacto, solo cambian las filas
 *     de esa persona, nada fuera de la voz salvo una fila del libro, los mismos
 *     disparadores, CHECK y privilegios, y la cadena de voz sigue verificando.
 *  5. Repetirla no encuentra nada.
 *  6. Una persona del PERSONAL (:admin_id) y su teléfono de transferencia
 *     (:voice_phone_hash), por el camino del runbook (superusuario + SET LOCAL
 *     ROLE): sus 17 columnas de autoría, su tramo de transferencia, y la
 *     configuración compilada con su huella solo informada.
 *  7. Una llamada pedida por una ESTRATEGIA (V9) no se redacta por texto: su
 *     nombre tiene su CHECK.
 *  8. Las entradas mal formadas abortan antes de nada.
 *
 * Los datos quedan CONFIRMADOS y las tablas, de otro rol: al terminar se marca
 * la base para que la siguiente prueba con RefreshDatabase migre desde cero.
 * Solo PostgreSQL (en SQLite no hay psql, roles ni DO). Teléfonos de ficción.
 * Con SUPRESION_1581_LOG_DIR, cada salida de psql se guarda allí (nunca lleva
 * datos: la prueba lo comprueba).
 */
class VoiceSuppression1581Test extends TestCase
{
    use VoiceSchemaFixtures;

    private const SUP_OWNER = 'ultron_studio_1581_owner';

    private const SUP_APP = 'ultron_studio_1581_app';

    private const SCRIPT = 'docs/ultron-studio/ops/supresion-1581.sql';

    /** La persona que pide la supresión (sintética). */
    private const PERSON_PHONE = '+573009990777';

    private const PERSON_TERM = 'Zoila Pérez';

    /** Otra persona: nada suyo cambia (salvo la autoría del personal en el paso 6). */
    private const OTHER_PHONE = '+573009990888';

    /** El teléfono de transferencia de Beto (admin 2), un fijo de ficción. */
    private const STAFF_PHONE = '+576010000099';

    private const ANA = 1;

    private const BETO = 2;

    /** Las 11 tablas de la voz. */
    private const VOICE_TABLES = [
        'ultron_studio_voice_profiles', 'ultron_studio_voice_agents', 'ultron_studio_voice_publications', 'ultron_studio_voice_lab_sessions',
        'voice_contacts', 'voice_rne_checks', 'voice_calls', 'voice_call_events', 'voice_consents', 'voice_exclusions', 'voice_brakes',
    ];

    /** Las 6 de solo inserción: la aplicación no tiene UPDATE ni DELETE (protección 10). */
    private const VOICE_INSERT_ONLY = ['ultron_studio_voice_publications', 'voice_call_events', 'voice_consents', 'voice_exclusions', 'voice_rne_checks', 'voice_brakes'];

    /** La autoría del personal en la voz: [tabla, nombre, id] (17). */
    private const VOICE_AUTHORSHIP = [
        ['ultron_studio_voice_profiles', 'created_by_name', 'created_by_admin_id'], ['ultron_studio_voice_profiles', 'submitted_by_name', 'submitted_by_admin_id'],
        ['ultron_studio_voice_profiles', 'reviewed_by_name', 'reviewed_by_admin_id'], ['ultron_studio_voice_agents', 'created_by_name', 'created_by_admin_id'],
        ['ultron_studio_voice_publications', 'actor_name', 'actor_admin_id'], ['ultron_studio_voice_lab_sessions', 'verdict_by_name', 'verdict_by_admin_id'],
        ['ultron_studio_voice_lab_sessions', 'created_by_name', 'created_by_admin_id'],
        ['voice_contacts', 'registered_by_name', 'registered_by_admin_id'], ['voice_contacts', 'consent_requested_by_name', 'consent_requested_by_admin_id'],
        ['voice_contacts', 'retired_by_name', 'retired_by_admin_id'], ['voice_rne_checks', 'actor_name', 'actor_admin_id'],
        ['voice_calls', 'requested_by_name', 'requested_by_admin_id'], ['voice_call_events', 'actor_name', 'actor_admin_id'],
        ['voice_consents', 'recorded_by_name', 'recorded_by_admin_id'], ['voice_consents', 'confirmed_by_name', 'confirmed_by_admin_id'],
        ['voice_exclusions', 'actor_name', 'actor_admin_id'], ['voice_brakes', 'actor_name', 'actor_admin_id'],
    ];

    private string $psql = '';

    private int $paso = 0;

    /** @var array<string, mixed> ids y huellas de lo sembrado */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Protección 3 de la voz (VoiceIsolationTest): una prueba de voz que ejecuta órdenes cierra la red.
        Http::preventStrayRequests();
        $this->requirePostgres('la supresión 1581 es SQL de PostgreSQL (psql, DO, roles y disparadores)');
        $psql = getenv('PSQL_BIN') ?: (new ExecutableFinder)->find('psql', null, ['/opt/homebrew/bin', '/opt/homebrew/opt/postgresql@14/bin', '/usr/local/bin', '/usr/bin']);
        // En PostgreSQL esta prueba es la puerta de la supresión de la voz: sin psql falla, no se salta.
        $this->assertIsString($psql, 'Sin psql no se puede ejecutar supresion-1581.sql (PSQL_BIN=<ruta> para indicarlo).');
        $this->psql = $psql;
    }

    protected function tearDown(): void
    {
        putenv(self::APP_ROLE_ENV);
        // Filas confirmadas y tablas de otro rol: la siguiente prueba con RefreshDatabase migra desde cero.
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_la_supresion_de_la_voz_redacta_por_texto_huella_y_personal_sin_tocar_nada_mas(): void
    {
        $this->prepareProductionLikeDatabase();
        $this->s = $this->seedVoice();

        $this->stepDryRegressionOfH3H7AndH9();
        $this->stepVoiceDryRun();
        $this->stepRealRunAbortsWhileSomethingIsLive();
        $this->stepTheApplicationStopsWhatIsLive();
        $this->stepRealRedactionAsTheOwner();
        $this->stepRepeatingFindsNothing();
        $this->stepStaffMemberAndTransferPhone();
        $this->stepStrategyCallsAreNotSearched();
        $this->stepMalformedInputsAbort();
    }

    // ── 0 · H3–H7 y H9 en seco ──────────────────────────────────────────────

    private function stepDryRegressionOfH3H7AndH9(): void
    {
        $antes = $this->digests();
        [$rc, $out] = $this->runScript('00-h3h9-seco', [
            'radicado' => 'T1581-H3H9', 'term' => 'Quintín Sintético', 'subject' => 'm:777', 'owner' => self::SUP_OWNER, 'dry' => '1',
        ]);
        $this->assertSame(0, $rc, "0 · el guion en seco con term y subject: {$out}");
        $this->assertStringContainsString('ultron_studio_audit_events.summary · 1 fila(s) · se redactaría', $out, '0 · la sección del H3–H7 sigue contando el libro');
        $this->assertStringContainsString('voice_consents.evidence · 1 fila(s) · se redactaría', $out, '0 · y ya ve la voz');
        $this->assertStringContainsString('Hito 9 · radicado T1581-H3H9', $out, '0 · la sección del H9 corre');
        $this->assertStringContainsString('outreach_enrollments · 0 fila(s)', $out);
        $this->assertStringContainsString('EN SECO (H9): nada ha cambiado.', $out);
        $this->assertStringContainsString('== EN SECO: ROLLBACK, nada ha cambiado.', $out);
        $this->assertSame($antes, $this->digests(), '0 · en seco no cambia ninguna tabla');
        $this->assertNoPersonalData($out, '0');
    }

    // ── 1 · la voz en seco ──────────────────────────────────────────────────

    private function stepVoiceDryRun(): void
    {
        $antes = $this->digests();
        $disparadores = $this->triggerStates();
        // El camino del runbook: el superusuario del clúster y SET LOCAL ROLE al dueño.
        [$rc, $out] = $this->runScript('01-voz-seco', $this->personParams('1'));
        $this->assertSame(0, $rc, "1 · en seco: {$out}");

        foreach (['voice_', 'ultron_studio_voice_'] as $prefijo) {
            $this->assertDoesNotMatchRegularExpression('/columna sin política[^\n]*: '.$prefijo.'/', $out, "1 · toda columna de texto de la voz tiene política ({$prefijo}*)");
        }
        $esperado = [
            'búsquedas: term, phone, voice_phone_hash',
            'voice_contacts por huella de voz · 2 fila(s), 1 sin retirar',
            'VIVO: 1 contacto(s) de voz sin retirar con esa huella',
            'voice_calls.remote_hash por huella de voz · 3 fila(s), 1 viva(s)',
            'VIVO: 1 llamada(s) en curso con esa huella',
            'voice_exclusions por huella de voz · 2 fila(s) · se redactaría (la exclusión dejaría de aplicarse a ese número: D8-18)',
            'voice_rne_checks por huella de voz · 1 fila(s)',
            'voice_contacts.retire_reason · 1 fila(s) · se redactaría',
            'voice_contacts.consent_request_evidence · 1 fila(s) · se redactaría',
            'voice_consents.evidence · 1 fila(s) · se redactaría',
            'voice_exclusions.note · 1 fila(s) · se redactaría',
            'voice_brakes.note · 1 fila(s) · se redactaría',
            'ultron_studio_voice_profiles.review_note · 1 fila(s) · se redactaría',
            'ultron_studio_voice_lab_sessions.verdict_note · 1 fila(s) · se redactaría',
            'ultron_studio_voice_publications.reason · 1 fila(s) · se redactaría',
            'ultron_studio_voice_publications.lab_gate · 1 fila(s) · se redactaría',
            '"voice_contacts.source_id (enlace al motor; se conserva)": 2',
            '"textos de esa persona que no casan con term/phone": 2',
            '"voice_calls.provider_call_sid (Twilio)": 2',
            '"voice_calls.provider_conversation_id (ElevenLabs)": 2',
            '(1 perfil(es) de voz con destinos cifrados: SQL no ve dentro',
            // Por huella: 1 contacto retirado + 2 llamadas terminadas + 2 exclusiones + 1 consulta al RNE; por texto: 9.
            'total: 15 campo(s) que redactar · 0 en configuración firmada o con huella (no se tocan)',
            '== EN SECO: ROLLBACK, nada ha cambiado.',
        ];
        foreach ($esperado as $linea) {
            $this->assertStringContainsString($linea, $out, "1 · en seco falta «{$linea}»");
        }
        $this->assertStringNotContainsString('voice_calls.requested_by_name', $out, '1 · ninguna llamada la pidió alguien llamado así');
        $this->assertSame($antes, $this->digests(), '1 · en seco no cambia ninguna tabla');
        $this->assertSame($disparadores, $this->triggerStates(), '1 · ni ningún disparador');
        $this->assertNoPersonalData($out, '1');
    }

    // ── 2 · con algo vivo, aborta ───────────────────────────────────────────

    private function stepRealRunAbortsWhileSomethingIsLive(): void
    {
        $antes = $this->digests();
        $disparadores = $this->triggerStates();
        [$rc, $out] = $this->runScript('02-voz-vivo-aborta', $this->personParams('0'), asOwner: true);
        $this->assertNotSame(0, $rc, "2 · con un contacto sin retirar y una llamada viva NO se redacta: {$out}");
        $this->assertStringContainsString('2 fila(s) VIVAS de la voz con esa huella', $out, '2 · dice qué lo impide');
        $this->assertStringNotContainsString('SUPRESIÓN CONFIRMADA', $out);
        $this->assertSame($antes, $this->digests(), '2 · el aborto no cambia ninguna tabla');
        $this->assertSame($disparadores, $this->triggerStates(), '2 · ni deja un disparador apagado');
        $this->assertNoPersonalData($out, '2');
    }

    // ── 3 · la aplicación para lo vivo ──────────────────────────────────────

    private function stepTheApplicationStopsWhatIsLive(): void
    {
        $ahora = now();
        DB::transaction(function () use ($ahora): void {
            DB::statement('SET LOCAL ROLE '.self::SUP_APP);
            $this->assertSame(self::SUP_APP, DB::scalar('SELECT current_user'));
            // Retirar un contacto: el cifrado a NULL y la petición de consentimiento pendiente, fuera (vct_cipher_chk, vct_consent_request_chk).
            DB::table('voice_contacts')->where('id', $this->s['c3'])->update([
                'status' => 'retired', 'phone_encrypted' => null,
                'consent_request_source' => null, 'consent_request_evidence' => null, 'consent_requested_by_admin_id' => null,
                'consent_requested_by_name' => null, 'consent_requested_by_role' => null, 'consent_requested_at' => null,
                'retired_at' => $ahora, 'retired_by_admin_id' => self::ANA, 'retired_by_name' => 'Ana Admin', 'retired_by_role' => 'Super Admin',
                'retire_reason' => 'Supresión de datos solicitada', 'status_changed_at' => $ahora, 'updated_at' => $ahora,
            ]);
            // Cancelar la saliente pedida (requested → canceled, con su motivo, una vez).
            DB::table('voice_calls')->where('id', $this->s['call3'])->update([
                'status' => 'canceled', 'policy_reason' => 'suppression_requested', 'ended_at' => $ahora, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
            ]);
        });
        $this->assertSame('retired', DB::table('voice_contacts')->where('id', $this->s['c3'])->value('status'));
        $this->assertSame('canceled', DB::table('voice_calls')->where('id', $this->s['call3'])->value('status'));
    }

    // ── 4 · la redacción real, con el dueño ─────────────────────────────────

    private function stepRealRedactionAsTheOwner(): void
    {
        $hp = $this->s['hp'];
        $antes = $this->digests();
        $filas = $this->voiceRows();
        $disparadores = $this->triggerStates();
        $restricciones = $this->constraintDefinitions();
        $privilegios = $this->aclState();
        $libro = (int) DB::scalar('SELECT coalesce(max(id), 0) FROM ultron_studio_audit_events');
        $this->assertSame('ok', $this->chainVerifies(), '4 · la cadena de voz verifica antes');

        [$rc, $out] = $this->runScript('04-voz-real', $this->personParams('0'), asOwner: true);
        $this->assertSame(0, $rc, "4 · la redacción real como el dueño (sin superusuario en la sesión): {$out}");
        $this->assertStringContainsString('SUPRESIÓN CONFIRMADA (COMMIT)', $out);
        $this->assertNoPersonalData($out, '4');
        // Solo los disparadores de UPDATE que saltarían, y solo de las guardas conocidas.
        $this->assertStringContainsString('voice_contacts · columnas [phone_hash, phone_last4, retire_reason] · disparadores de UPDATE que saltarían, apagados en esta transacción: vct_forbid_immutable_update, vct_retired_is_final', $out, '4 · contactos: no se apaga vct_cipher_only_shrinks (el cifrado no se nombra)');
        $this->assertStringContainsString('voice_calls · columnas [remote_hash, remote_last4] · disparadores de UPDATE que saltarían, apagados en esta transacción: vc_forbid_immutable_update', $out, '4 · llamadas: ni vc_status_forward ni vc_bindings_once');
        $this->assertStringContainsString('ultron_studio_voice_publications · columnas [lab_gate, reason] · disparadores de UPDATE que saltarían, apagados en esta transacción: usvpub_forbid_update', $out, '4 · la cadena: solo su guarda de UPDATE, nunca las de INSERT');

        // a) Ni una fila con la huella; la forma suprimida que admiten los CHECK; una huella distinta por fila.
        foreach ([['voice_contacts', 'phone_hash'], ['voice_exclusions', 'phone_hash'], ['voice_rne_checks', 'phone_hash'], ['voice_calls', 'remote_hash']] as [$tabla, $col]) {
            $this->assertSame(0, (int) DB::table($tabla)->where($col, $hp)->count(), "4a · {$tabla}.{$col} sin la huella de la persona");
        }
        $huellas = [];
        foreach (['voice_contacts' => ['c1', 'c3'], 'voice_exclusions' => ['ex1', 'ex2'], 'voice_rne_checks' => ['rne1'], 'voice_calls' => ['call1', 'denied', 'call3']] as $tabla => $claves) {
            foreach ($claves as $clave) {
                $f = DB::table($tabla)->where('id', $this->s[$clave])->first();
                $h = $tabla === 'voice_calls' ? $f->remote_hash : $f->phone_hash;
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h, "4a · {$tabla} {$clave}: 64 hex (su CHECK)");
                $huellas[] = $h;
                if ($tabla !== 'voice_rne_checks') {
                    $this->assertSame('0000', $tabla === 'voice_calls' ? $f->remote_last4 : $f->phone_last4, "4a · {$tabla} {$clave}: 4 dígitos 0000");
                }
                if ($tabla === 'voice_contacts') {
                    $this->assertSame('retired', $f->status);
                    $this->assertNull($f->phone_encrypted, '4a · el contacto retirado sin cifrado (vct_cipher_chk)');
                }
            }
        }
        $this->assertCount(8, array_unique($huellas), '4a · una huella aleatoria por fila: no se pueden volver a juntar');

        // b) Los textos: lo que casa, redactado; lo que no, intacto (y contado en el libro como informado).
        $this->assertSame('[suprimido] pidió no recibir más llamadas', DB::table('voice_contacts')->where('id', $this->s['c1'])->value('retire_reason'));
        $this->assertSame('Formulario firmado por [suprimido]', DB::table('voice_consents')->where('id', $this->s['consent1'])->value('evidence'));
        $this->assertSame('Formulario F-123 en el archivo de recepción', DB::table('voice_consents')->where('id', $this->s['consent2'])->value('evidence'), '4b · la evidencia que no casa se informa, no se toca');
        $this->assertSame('CRC-2026-11-01-0042', DB::table('voice_rne_checks')->where('id', $this->s['rne1'])->value('evidence_ref'));
        $this->assertSame('[suprimido] no quiere más llamadas', DB::table('voice_exclusions')->where('id', $this->s['ex1'])->value('note'));
        $this->assertSame('Revisado: [suprimido] no recibió dos llamadas el mismo día', DB::table('voice_brakes')->where('id', $this->s['release'])->value('note'));
        $this->assertSame('El saludo nombra a [suprimido]', DB::table('ultron_studio_voice_profiles')->where('id', $this->s['profileB'])->value('review_note'));
        $this->assertSame('[suprimido] no oyó el aviso completo', DB::table('ultron_studio_voice_lab_sessions')->where('id', $this->s['session'])->value('verdict_note'));
        $pub = DB::table('ultron_studio_voice_publications')->where('id', $this->s['pub'])->first();
        $this->assertSame('Publicado tras la prueba con [suprimido]', $pub->reason);
        $this->assertSame(['mode' => 'waived', 'note' => 'Lo autorizó [suprimido]'], json_decode($pub->lab_gate, true), '4b · el JSON vuelve a ser JSON');
        foreach (self::VOICE_TABLES as $tabla) {
            $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM public.\"{$tabla}\" x WHERE x::text ~* 'zoila|9990777'"), "4b · {$tabla}: ni el nombre ni el número en ninguna columna");
        }

        // c) Solo cambian las filas de esa persona; nada fuera de la voz salvo UNA fila del libro.
        $this->assertChangedRows($filas, [
            'voice_contacts' => ['c1', 'c3'], 'voice_consents' => ['consent1'], 'voice_rne_checks' => ['rne1'], 'voice_exclusions' => ['ex1', 'ex2'],
            'voice_calls' => ['call1', 'denied', 'call3'], 'voice_brakes' => ['release'], 'ultron_studio_voice_profiles' => ['profileB'],
            'ultron_studio_voice_publications' => ['pub'], 'ultron_studio_voice_lab_sessions' => ['session'],
        ], '4c');
        $this->assertOnlyVoiceAndOneLedgerRowChanged($antes, $libro, 1, '4c');

        // d) Los mismos disparadores (los 51 de la voz, activos), CHECK y privilegios; la aplicación sigue sin UPDATE ni DELETE.
        $this->assertSame($disparadores, $this->triggerStates(), '4d · los mismos disparadores, en el mismo estado');
        $this->assertSame(51, (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND t.tgenabled = 'O' AND (c.relname LIKE 'voice\\_%' OR c.relname LIKE 'ultron\\_studio\\_voice\\_%')"), '4d · los 51 de la voz activos');
        $this->assertSame($restricciones, $this->constraintDefinitions(), '4d · los mismos CHECK, UNIQUE y FK');
        $this->assertSame($privilegios, $this->aclState(), '4d · el mismo dueño y los mismos privilegios');
        $this->assertAppCannotRewriteInsertOnlyTables('4d');

        // e) La cadena de voz sigue verificando (la firma no cubre reason, lab_gate ni el actor).
        $this->assertSame('ok', $this->chainVerifies(), '4e · la cadena de voz verifica después');

        // f) Una fila en el libro, con recuentos y el radicado, nunca el dato.
        $fila = DB::table('ultron_studio_audit_events')->where('id', '>', $libro)->sole();
        $this->assertSame('system.personal_data_redacted', $fila->event);
        $this->assertSame('supresion-1581', $fila->actor_name);
        $this->assertStringContainsString('radicado T1581-VOZ', $fila->summary);
        $meta = json_decode($fila->metadata, true);
        $this->assertSame(['term', 'phone', 'voice_phone_hash'], $meta['searches']);
        foreach ([
            'voice_contacts.phone_hash+phone_last4' => 2, 'voice_exclusions.phone_hash+phone_last4' => 2, 'voice_rne_checks.phone_hash' => 1,
            'voice_calls.remote_hash+remote_last4' => 3, 'voice_contacts.retire_reason' => 1, 'voice_consents.evidence' => 1, 'voice_exclusions.note' => 1,
            'voice_brakes.note' => 1, 'ultron_studio_voice_profiles.review_note' => 1, 'ultron_studio_voice_lab_sessions.verdict_note' => 1,
            'ultron_studio_voice_publications.reason' => 1, 'ultron_studio_voice_publications.lab_gate' => 1,
        ] as $campo => $n) {
            $this->assertSame($n, $meta['fields'][$campo] ?? null, "4f · el libro cuenta {$campo}");
        }
        // Textos sin casar: la evidencia F-123, la referencia del RNE y el motivo con que la aplicación retiró el contacto vivo.
        $this->assertSame([
            'textos de esa persona que no casan con term/phone' => 3,
            'voice_calls.provider_call_sid (Twilio)' => 2,
            'voice_calls.provider_conversation_id (ElevenLabs)' => 2,
            'voice_contacts.source_id (enlace al motor; se conserva)' => 2,
        ], $this->sorted($meta['voice_informed']), '4f · lo informado, en recuentos');
        $this->assertNoPersonalData($fila->summary.' '.$fila->metadata, '4f · la fila del libro');
    }

    // ── 5 · repetir no encuentra nada ───────────────────────────────────────

    private function stepRepeatingFindsNothing(): void
    {
        $antes = $this->digests();
        $libro = (int) DB::scalar('SELECT coalesce(max(id), 0) FROM ultron_studio_audit_events');
        [$rc, $out] = $this->runScript('05-voz-repetir', $this->personParams('0'), asOwner: true);
        $this->assertSame(0, $rc, "5 · repetir la misma solicitud: {$out}");
        $this->assertStringContainsString('total: 0 campo(s) que redactar', $out);
        $this->assertNoPersonalData($out, '5');
        // Solo su fila del libro (con 0 campos): ni la voz ni nada más cambia.
        $this->assertOnlyVoiceAndOneLedgerRowChanged($antes, $libro, 1, '5');
        $ahora = $this->digests();
        foreach (self::VOICE_TABLES as $tabla) {
            $this->assertSame($antes[$tabla], $ahora[$tabla], "5 · {$tabla} no cambia al repetir");
        }
    }

    // ── 6 · una persona del personal y su teléfono de transferencia ─────────

    private function stepStaffMemberAndTransferPhone(): void
    {
        $hs = $this->s['hs'];
        $antes = $this->digests();
        $filas = $this->voiceRows();
        $disparadores = $this->triggerStates();
        $libro = (int) DB::scalar('SELECT coalesce(max(id), 0) FROM ultron_studio_audit_events');
        $anaAntes = $this->authorshipCount(self::ANA, 'Ana Admin');
        $this->assertGreaterThan(10, $anaAntes);

        // El camino del runbook en real: superusuario del clúster + SET LOCAL ROLE al dueño.
        [$rc, $out] = $this->runScript('06-personal-real', [
            'radicado' => 'T1581-VOZ-PERSONAL', 'admin_id' => (string) self::BETO, 'voice_phone_hash' => $hs, 'owner' => self::SUP_OWNER, 'dry' => '0',
        ]);
        $this->assertSame(0, $rc, "6 · personal + teléfono de transferencia: {$out}");
        $this->assertNoPersonalData($out, '6');

        $vistas = 0;
        foreach (self::VOICE_AUTHORSHIP as [$tabla, $nombre, $id]) {
            $vistas += (int) DB::table($tabla)->where($id, self::BETO)->count();
            $this->assertSame(0, (int) DB::table($tabla)->where($id, self::BETO)->where($nombre, '<>', '[suprimido]')->count(), "6 · {$tabla}.{$nombre} de Beto, suprimido");
        }
        $this->assertSame(10, $vistas, '6 · las 10 instantáneas de Beto sembradas en la voz');
        $this->assertSame($anaAntes, $this->authorshipCount(self::ANA, 'Ana Admin'), '6 · las de Ana no se tocan');

        $tramo = DB::table('voice_calls')->where('id', $this->s['leg'])->first();
        $this->assertNotSame($hs, $tramo->remote_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tramo->remote_hash);
        $this->assertSame('0000', $tramo->remote_last4);
        // La configuración compilada con su huella: se informa, no se toca.
        $this->assertStringContainsString($hs, (string) DB::table('ultron_studio_voice_profiles')->where('id', $this->s['profileA'])->value('content'));
        $this->assertStringContainsString($hs, (string) DB::table('ultron_studio_voice_agents')->where('id', $this->s['agent'])->value('transfer_destination_hashes'));
        $this->assertStringContainsString('"ultron_studio_voice_profiles.content (destino de transferencia)": 1', $out);
        $this->assertStringContainsString('"ultron_studio_voice_agents.transfer_destination_hashes": 1', $out);

        $this->assertChangedRows($filas, [
            'voice_contacts' => ['c1'], 'voice_consents' => ['consent1', 'consent2', 'consentQ'], 'voice_calls' => ['leg'],
            'voice_call_events' => ['hangup'], 'voice_brakes' => ['release'], 'ultron_studio_voice_profiles' => ['profileA', 'profileB'],
            'ultron_studio_voice_publications' => ['pub'], 'ultron_studio_voice_lab_sessions' => ['session'],
        ], '6');
        $this->assertOnlyVoiceAndOneLedgerRowChanged($antes, $libro, 1, '6');
        $this->assertSame($disparadores, $this->triggerStates(), '6 · los mismos disparadores');
        $this->assertSame('ok', $this->chainVerifies(), '6 · la cadena verifica con el actor suprimido');
        $meta = json_decode((string) DB::table('ultron_studio_audit_events')->where('id', '>', $libro)->value('metadata'), true);
        $this->assertSame(['voice_phone_hash', 'admin_id'], $meta['searches']);
        $this->assertSame(1, $meta['voice_informed']['ultron_studio_voice_agents.transfer_destination_hashes'] ?? null);
    }

    // ── 7 · las llamadas de una estrategia (V9) no se buscan por texto ──────

    private function stepStrategyCallsAreNotSearched(): void
    {
        $chk = (string) DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'vc_requester_chk'");
        if (! str_contains($chk, "'strategy'")) {
            // Sin la Enmienda 17 (V9) una estrategia no pide llamadas: el CHECK de DB8 lo rechaza y el filtro no hace falta.
            $this->assertCheckRejects(fn () => $this->insertOutbound(['requested_by_type' => 'strategy', 'requested_by_admin_id' => null, 'requested_by_role' => null, 'requested_by_name' => 'Estrategia zoila-reactivacion']), 'vc_requester_chk', '7');

            return;
        }
        $ahora = now();
        $id = $this->insertOutbound(['requested_by_type' => 'strategy', 'requested_by_admin_id' => null, 'requested_by_role' => null, 'requested_by_name' => 'Estrategia zoila-reactivacion']);
        DB::table('voice_calls')->where('id', $id)->update(['status' => 'canceled', 'policy_reason' => 'strategy_test', 'ended_at' => $ahora, 'status_changed_at' => $ahora]);
        $fila = DB::table('voice_calls')->where('id', $id)->first();

        [$rc, $out] = $this->runScript('07-estrategia-real', ['radicado' => 'T1581-V9', 'term' => 'zoila', 'owner' => self::SUP_OWNER, 'dry' => '0'], asOwner: true);
        $this->assertSame(0, $rc, "7 · un término que casa con la clave de una estrategia no rompe vc_requester_chk: {$out}");
        $this->assertStringNotContainsString('voice_calls.requested_by_name', $out, '7 · las llamadas de una estrategia no se buscan');
        $this->assertNoPersonalData($out, '7');
        $this->assertEquals($fila, DB::table('voice_calls')->where('id', $id)->first(), '7 · la llamada de la estrategia, intacta');
    }

    // ── 8 · entradas mal formadas ───────────────────────────────────────────

    private function stepMalformedInputsAbort(): void
    {
        $antes = $this->digests();
        foreach ([
            ['08-huella-mala', ['voice_phone_hash' => 'zz'], ':voice_phone_hash no son 64 hexadecimales'],
            ['08-huellas-iguales', ['voice_phone_hash' => str_repeat('c', 64), 'phone_hash' => str_repeat('c', 64)], 'son la misma huella'],
        ] as [$nombre, $params, $mensaje]) {
            [$rc, $out] = $this->runScript($nombre, $params + ['radicado' => 'T1581-MAL', 'owner' => self::SUP_OWNER, 'dry' => '1']);
            $this->assertNotSame(0, $rc, "8 · {$nombre}");
            $this->assertStringContainsString($mensaje, $out, "8 · {$nombre}");
        }
        $this->assertSame($antes, $this->digests(), '8 · nada cambia');
    }

    // ── La base: el modelo de roles de producción ───────────────────────────

    /**
     * Dos roles sin superusuario (el dueño y la aplicación), los privilegios
     * por defecto del rol que migra para la aplicación (como `iron` →
     * `iron_app`), las migraciones con el REVOKE de verdad, y las tablas
     * pasadas al dueño.
     */
    private function prepareProductionLikeDatabase(): void
    {
        DB::statement(sprintf(
            "DO \$\$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '%1\$s') THEN CREATE ROLE %1\$s LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS; END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '%2\$s') THEN CREATE ROLE %2\$s LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT; END IF;
            END \$\$",
            self::SUP_OWNER, self::SUP_APP,
        ));
        foreach ([self::SUP_OWNER, self::SUP_APP] as $rol) {
            $r = DB::selectOne('SELECT rolsuper::int AS su, rolbypassrls::int AS rls, rolcreaterole::int AS cr FROM pg_roles WHERE rolname = ?', [$rol]);
            $this->assertSame([0, 0, 0], [(int) $r->su, (int) $r->rls, (int) $r->cr], "{$rol} no es superusuario");
        }
        DB::statement('GRANT USAGE ON SCHEMA public TO '.self::SUP_APP);
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO '.self::SUP_APP);
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO '.self::SUP_APP);

        putenv(self::APP_ROLE_ENV.'='.self::SUP_APP);
        $this->assertSame(0, Artisan::call('migrate:fresh'), 'migrate:fresh con el rol de la aplicación');
        putenv(self::APP_ROLE_ENV);

        DB::statement(sprintf("DO \$\$ DECLARE t text; BEGIN
                FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
                    EXECUTE format('ALTER TABLE public.%%I OWNER TO %s', t);
                END LOOP;
            END \$\$", self::SUP_OWNER));
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tableowner <> ?", [self::SUP_OWNER]), 'todas las tablas son del dueño');
        $this->assertAppCannotRewriteInsertOnlyTables('preparación');
    }

    /** @return array<string, mixed> */
    private function seedVoice(): array
    {
        $hp = VoicePhone::hash(self::PERSON_PHONE);
        $hq = VoicePhone::hash(self::OTHER_PHONE);
        $hs = VoicePhone::hash(self::STAFF_PHONE);
        $this->assertNotSame(PhoneFingerprint::of(self::PERSON_PHONE), $hp, 'la huella de voz no es la del canario (otra etiqueta)');
        $ahora = now();
        $s = compact('hp', 'hq', 'hs');

        // ── El gobierno de la voz (las 4 del Studio) ──
        $version = strtolower((string) Str::uuid());
        $s['version'] = (int) DB::table('ultron_studio_versions')->insertGetId([
            'uuid' => $version, 'client_request_id' => (string) Str::uuid(), 'label' => 'Versión de prueba', 'status' => 'APPROVED',
            'content' => '{}', 'content_hash' => $this->hex('version-1581'), 'catalog_version' => 'catalogo/1', 'catalog_hash' => $this->hex('catalogo-1581'),
            'source_draft_revision' => 1, 'created_by_admin_id' => self::ANA, 'created_by_name' => 'Ana Admin', 'created_by_role' => 'Super Admin',
            'status_changed_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $contenidoA = ['format' => 'voice-profile/1', 'greetings' => ['inbound' => 'Hola, habla Iron Body'], 'transfer' => ['destinations' => [['alias' => 'Recepción', 'phoneHash' => $hs]]]];
        $s['profileA'] = $this->insertProfile('Voz recepción', $contenidoA, 'approved', null);
        $s['profileB'] = $this->insertProfile('Voz de pruebas', ['format' => 'voice-profile/1', 'transfer' => ['destinations' => []]], 'rejected', 'El saludo nombra a '.self::PERSON_TERM);
        $perfilA = (string) DB::table('ultron_studio_voice_profiles')->where('id', $s['profileA'])->value('uuid');
        $s['agent'] = $this->insertAgent($s['version'], $s['profileA'], 'publication', 'elevenlabs', [['alias' => 'Recepción', 'hash' => $hs]]);
        $agentePub = DB::table('ultron_studio_voice_agents')->where('id', $s['agent'])->first();
        $s['labAgent'] = $this->insertAgent($s['version'], $s['profileA'], 'lab', 'fake', []);
        $s['session'] = $this->insertLabSession($s['labAgent'], self::PERSON_TERM.' no oyó el aviso completo');
        $s['pub'] = $this->insertChainHead($agentePub, $version, $perfilA, 'Publicado tras la prueba con '.self::PERSON_TERM, ['mode' => 'waived', 'note' => 'Lo autorizó '.self::PERSON_TERM]);

        // ── La telefonía (las 7) · la persona: un contacto ya retirado con su historia ──
        $s['c1'] = $this->insertContact(['phone_hash' => $hp, 'phone_last4' => '0777', 'source_type' => 'member', 'source_id' => 501, 'registered_by_admin_id' => self::ANA, 'registered_by_name' => 'Ana Admin']);
        $s['consent1'] = $this->insertConsent($s['c1'], ['evidence' => 'Formulario firmado por '.self::PERSON_TERM, 'recorded_by_name' => 'Ana Admin', 'confirmed_by_name' => 'Beto Revisor']);
        $s['consent2'] = $this->insertConsent($s['c1'], ['source' => 'written_form', 'evidence' => 'Formulario F-123 en el archivo de recepción', 'recorded_by_name' => 'Ana Admin', 'confirmed_by_name' => 'Beto Revisor']);
        $s['rne1'] = $this->insertRneCheck(['phone_hash' => $hp, 'contact_id' => $s['c1'], 'evidence_ref' => 'CRC-2026-11-01-0042', 'actor_name' => 'Ana Admin']);
        $s['call1'] = $this->insertOutbound(['target_source' => 'voice_contacts', 'canary_number_uuid' => null, 'voice_contact_id' => $s['c1'], 'remote_hash' => $hp, 'remote_last4' => '0777', 'requested_by_name' => 'Ana Admin']);
        DB::table('voice_calls')->where('id', $s['call1'])->update(['status' => 'dialing', 'provider_call_sid' => $this->callSid('p1-1581'), 'provider_conversation_id' => 'conv_p1_1581', 'started_at' => $ahora, 'status_changed_at' => $ahora]);
        DB::table('voice_calls')->where('id', $s['call1'])->update(['status' => 'completed', 'ended_at' => $ahora, 'duration_seconds' => 95, 'termination_reason' => 'completed', 'status_changed_at' => $ahora]);
        $s['leg'] = $this->insertLeg($s['call1'], ['remote_hash' => $hs, 'remote_last4' => '0099']);
        $s['tool'] = $this->insertEvent(['call_id' => $s['call1'], 'kind' => 'tool_call', 'tool' => 'no_volver_a_llamar', 'outcome' => 'accepted', 'codes' => '{"turn":2,"codes":["R-01"]}', 'provider_event_key' => 'conv_p1_1581:t2']);
        $s['hangup'] = $this->insertEvent(['call_id' => $s['call1'], 'provider' => 'laravel', 'kind' => 'hangup_requested', 'provider_event_key' => null, 'actor_type' => 'admin', 'actor_admin_id' => self::BETO, 'actor_name' => 'Beto Revisor', 'actor_role' => 'Super Admin']);
        $s['ex1'] = $this->insertExclusion(['phone_hash' => $hp, 'phone_last4' => '0777', 'contact_id' => $s['c1'], 'note' => self::PERSON_TERM.' no quiere más llamadas', 'actor_name' => 'Ana Admin']);
        $s['ex2'] = $this->insertExclusion(['phone_hash' => $hp, 'phone_last4' => '0777', 'source' => 'spoken_opt_out', 'call_id' => $s['call1'], 'actor_type' => 'system', 'actor_admin_id' => null, 'actor_name' => 'voice:tool', 'actor_role' => null]);
        $s['open'] = $this->insertBrakeOpen(['call_id' => $s['call1'], 'event_id' => $s['tool']]);
        $s['release'] = $this->insertBrakeRelease($s['open'], ['note' => 'Revisado: '.self::PERSON_TERM.' no recibió dos llamadas el mismo día', 'actor_admin_id' => self::BETO, 'actor_name' => 'Beto Revisor']);
        DB::table('voice_contacts')->where('id', $s['c1'])->update([
            'status' => 'retired', 'phone_encrypted' => null, 'retired_at' => $ahora, 'retired_by_admin_id' => self::BETO, 'retired_by_name' => 'Beto Revisor',
            'retired_by_role' => 'Super Admin', 'retire_reason' => self::PERSON_TERM.' pidió no recibir más llamadas', 'status_changed_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $s['denied'] = $this->insertDenied(['remote_hash' => $hp, 'remote_last4' => '0777']);
        // Lo VIVO: el mismo número otra vez como contacto activo (con su petición de consentimiento pendiente) y una saliente pedida.
        $s['c3'] = $this->insertContact(['phone_hash' => $hp, 'phone_last4' => '0777', 'source_type' => 'lead', 'source_id' => 9001,
            'consent_request_source' => 'written_form', 'consent_request_evidence' => 'Formulario de '.self::PERSON_TERM.' por confirmar',
            'consent_requested_by_admin_id' => self::ANA, 'consent_requested_by_name' => 'Ana Admin', 'consent_requested_by_role' => 'Super Admin', 'consent_requested_at' => $ahora,
            'registered_by_admin_id' => self::ANA, 'registered_by_name' => 'Ana Admin']);
        $s['call3'] = $this->insertOutbound(['target_source' => 'voice_contacts', 'canary_number_uuid' => null, 'voice_contact_id' => $s['c3'], 'remote_hash' => $hp, 'remote_last4' => '0777', 'requested_by_name' => 'Ana Admin']);

        // ── Otra persona (Q): nada suyo cambia por la solicitud de la primera ──
        $s['cq'] = $this->insertContact(['phone_hash' => $hq, 'phone_last4' => '0888', 'registered_by_name' => 'Ana Admin']);
        $s['consentQ'] = $this->insertConsent($s['cq'], ['evidence' => 'Formulario firmado por Quintín Sintético', 'recorded_by_name' => 'Ana Admin', 'confirmed_by_name' => 'Beto Revisor']);
        $s['callQ'] = $this->insertOutbound(['target_source' => 'voice_contacts', 'canary_number_uuid' => null, 'voice_contact_id' => $s['cq'], 'remote_hash' => $hq, 'remote_last4' => '0888', 'requested_by_name' => 'Ana Admin']);
        DB::table('voice_calls')->where('id', $s['callQ'])->update(['status' => 'canceled', 'policy_reason' => 'outside_hours', 'ended_at' => $ahora, 'status_changed_at' => $ahora]);
        DB::table('voice_contacts')->where('id', $s['cq'])->update(['status' => 'retired', 'phone_encrypted' => null, 'retired_at' => $ahora, 'retired_by_admin_id' => self::ANA,
            'retired_by_name' => 'Ana Admin', 'retire_reason' => 'Número equivocado', 'status_changed_at' => $ahora, 'updated_at' => $ahora]);
        $s['exQ'] = $this->insertExclusion(['phone_hash' => $hq, 'phone_last4' => '0888', 'note' => 'Pidió no ser llamado', 'actor_name' => 'Ana Admin']);
        $s['rneQ'] = $this->insertRneCheck(['phone_hash' => $hq, 'evidence_ref' => 'CRC-2026-11-02-0007', 'actor_name' => 'Ana Admin']);

        // ── Fuera de la voz: una fila del libro de otra persona (la sección del H3–H7 la ve en el paso 0) ──
        DB::table('ultron_studio_audit_events')->insert([
            'event' => 'version.created', 'subject_type' => 'version', 'actor_type' => 'admin', 'actor_admin_id' => self::ANA, 'actor_name' => 'Ana Admin',
            'summary' => 'Revisión con Quintín Sintético', 'created_at' => $ahora,
        ]);

        foreach (self::VOICE_TABLES as $tabla) {
            $this->assertGreaterThan(0, (int) DB::table($tabla)->count(), "sembrado: {$tabla} tiene filas");
        }

        return $s;
    }

    /** @param  array<string, mixed>  $contenido */
    private function insertProfile(string $label, array $contenido, string $final, ?string $nota): int
    {
        $ahora = now();
        $destinos = count($contenido['transfer']['destinations'] ?? []);
        $id = (int) DB::table('ultron_studio_voice_profiles')->insertGetId([
            'uuid' => strtolower((string) Str::uuid()), 'client_request_id' => (string) Str::uuid(), 'revision' => 1, 'label' => $label, 'language' => 'es',
            'max_duration_seconds' => 300, 'retention_days' => 30, 'record_calls' => false, 'record_voice' => false, 'transfer_count' => $destinos,
            'transfer_destinations_encrypted' => $destinos > 0 ? 'eyJpdiI6ImNpZnJhZG8tZGUtcHJ1ZWJhIn0=' : null,
            'content' => json_encode($contenido), 'content_hash' => $this->hex('perfil-'.$label), 'status' => 'draft',
            'created_by_admin_id' => self::ANA, 'created_by_name' => 'Ana Admin', 'created_by_role' => 'Super Admin',
            'created_at' => $ahora, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('ultron_studio_voice_profiles')->where('id', $id)->update(['status' => 'submitted', 'submitted_by_admin_id' => self::ANA, 'submitted_by_name' => 'Ana Admin',
            'submitted_by_role' => 'Super Admin', 'submitted_at' => $ahora, 'status_changed_at' => $ahora]);
        DB::table('ultron_studio_voice_profiles')->where('id', $id)->update(['status' => $final, 'reviewed_by_admin_id' => self::BETO, 'reviewed_by_name' => 'Beto Revisor',
            'reviewed_by_role' => 'Super Admin', 'reviewed_at' => $ahora, 'review_note' => $nota, 'status_changed_at' => $ahora]);

        return $id;
    }

    /** @param  list<array{alias:string,hash:string}>  $destinos */
    private function insertAgent(int $version, int $perfil, string $proposito, string $proveedor, array $destinos): int
    {
        $ahora = now();
        $proyeccion = $this->hex("proyeccion-{$proposito}");
        $id = (int) DB::table('ultron_studio_voice_agents')->insertGetId([
            'uuid' => strtolower((string) Str::uuid()), 'client_request_id' => (string) Str::uuid(), 'purpose' => $proposito, 'studio_version_id' => $version,
            'studio_content_hash' => $this->hex('version-1581'), 'voice_profile_id' => $perfil, 'voice_profile_hash' => $this->hex('perfil-1581'),
            'compiler_version' => 'ultron-voice-compiler/1', 'spec_hash' => $this->hex("spec-{$proposito}"), 'payload_hash' => $this->hex("payload-{$proposito}"),
            'projection_hash' => $proyeccion, 'transfer_destination_hashes' => json_encode($destinos), 'provider' => $proveedor, 'status' => 'pending',
            'serves_canary' => false, 'serves_rest' => false, 'created_by_admin_id' => self::ANA, 'created_by_name' => 'Ana Admin', 'created_by_role' => 'Super Admin',
            'created_at' => $ahora, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
        ]);
        if ($proposito === 'publication') {
            DB::table('ultron_studio_voice_agents')->where('id', $id)->update(['status' => 'deployed', 'provider_agent_id' => 'agent_pub_1581',
                'observed_hash' => $proyeccion, 'observed_at' => $ahora, 'serves_canary' => true, 'status_changed_at' => $ahora]);
        }

        return $id;
    }

    private function insertLabSession(int $agente, string $nota): int
    {
        $ahora = now();
        $id = (int) DB::table('ultron_studio_voice_lab_sessions')->insertGetId([
            'uuid' => strtolower((string) Str::uuid()), 'client_request_id' => (string) Str::uuid(), 'agent_id' => $agente, 'status' => 'open',
            'budget_day' => $ahora->toDateString(), 'reserved_micros' => 100000, 'max_seconds' => 300,
            'created_by_admin_id' => self::ANA, 'created_by_name' => 'Ana Admin', 'created_by_role' => 'Super Admin',
            'created_at' => $ahora, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('ultron_studio_voice_lab_sessions')->where('id', $id)->update(['status' => 'ended', 'ended_at' => $ahora, 'end_reason' => 'completed',
            'duration_seconds' => 120, 'guard_codes' => '{"codes":["R-02"],"turns":[3]}', 'status_changed_at' => $ahora]);
        DB::table('ultron_studio_voice_lab_sessions')->where('id', $id)->update(['verdict' => 'rejected', 'verdict_note' => $nota, 'verdict_by_admin_id' => self::BETO,
            'verdict_by_name' => 'Beto Revisor', 'verdict_by_role' => 'Super Admin', 'verdict_at' => $ahora]);

        return $id;
    }

    /**
     * La primera entrada de la cadena de voz, FIRMADA con el código real
     * (E1-4: el mensaje canónico y la firma de la telefonía) y la APP_KEY de
     * las pruebas: `verifyRow()` la da por buena.
     *
     * @param  array<string, mixed>  $puerta
     */
    private function insertChainHead(object $agente, string $version, string $perfil, string $motivo, array $puerta): int
    {
        $ahora = now();
        $mensaje = StudioVoicePublications::message([
            'seq' => 1, 'prev_hash' => StudioVoicePublications::GENESIS_HASH, 'version_uuid' => $version, 'profile_uuid' => $perfil,
            'spec_hash' => $agente->spec_hash, 'agent_config_hash' => $agente->payload_hash, 'provider_agent_id' => $agente->provider_agent_id,
            'scope' => 'canary', 'actor_id' => self::BETO, 'published_at' => StudioVoicePublications::publishedAt($ahora),
        ]);

        return (int) DB::table('ultron_studio_voice_publications')->insertGetId([
            'uuid' => strtolower((string) Str::uuid()), 'seq' => 1, 'client_request_id' => (string) Str::uuid(), 'action' => 'publish', 'scope' => 'canary',
            'previous_publication_id' => null, 'prev_hash' => StudioVoicePublications::GENESIS_HASH, 'restores_publication_id' => null, 'agent_id' => $agente->id,
            'effective_json' => $mensaje, 'effective_hash' => StudioVoicePublications::entryHash($mensaje), 'signature' => (StudioVoicePublications::signer())($mensaje),
            'lab_gate' => json_encode($puerta), 'evidence' => null, 'reason' => $motivo,
            'actor_admin_id' => self::BETO, 'actor_name' => 'Beto Revisor', 'actor_role' => 'Super Admin', 'created_at' => $ahora,
        ]);
    }

    // ── Ejecutar el guion ───────────────────────────────────────────────────

    /** @return array<string, string> */
    private function personParams(string $dry): array
    {
        return [
            'radicado' => 'T1581-VOZ-'.(++$this->paso), 'term' => self::PERSON_TERM, 'phone' => self::PERSON_PHONE,
            'voice_phone_hash' => $this->s['hp'], 'owner' => self::SUP_OWNER, 'dry' => $dry,
        ];
    }

    /**
     * `cat <parámetros> supresion-1581.sql | psql -X -q -d <base>`, como el
     * runbook. Por defecto con el usuario de la conexión de las pruebas (el
     * superusuario del clúster, que hace SET LOCAL ROLE al dueño); con
     * `$asOwner`, conectado COMO el dueño, que no es superusuario.
     *
     * @param  array<string, string>  $params
     * @return array{0:int,1:string}
     */
    private function runScript(string $nombre, array $params, bool $asOwner = false): array
    {
        $lineas = [];
        foreach ($params as $clave => $valor) {
            $this->assertDoesNotMatchRegularExpression('/[\\\\\n\r]/', $valor, "parámetro {$clave}");
            $lineas[] = '\\set '.$clave." '".str_replace("'", "''", $valor)."'";
        }
        $c = config('database.connections.'.config('database.default'));
        $proceso = new Process(
            [$this->psql, '-X', '-q', '-h', (string) $c['host'], '-p', (string) $c['port'], '-U', $asOwner ? self::SUP_OWNER : (string) $c['username'], '-d', (string) $c['database']],
            null,
            ['PGGSSENCMODE' => 'disable', 'PGPASSWORD' => (string) ($c['password'] ?? ''), 'PGOPTIONS' => '', 'PGAPPNAME' => 'supresion-1581-prueba'],
        );
        $proceso->setInput(implode("\n", $lineas)."\n".file_get_contents(base_path(self::SCRIPT)));
        $proceso->setTimeout(180);
        $proceso->run();
        $salida = $proceso->getOutput().$proceso->getErrorOutput();
        $dir = getenv('SUPRESION_1581_LOG_DIR');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents($dir.'/'.$nombre.'.log', "rc={$proceso->getExitCode()}\n".$salida);
        }

        return [(int) $proceso->getExitCode(), $salida];
    }

    // ── Fotos y comprobaciones ──────────────────────────────────────────────

    /** @return array<string, string> tabla → md5 de todas sus filas */
    private function digests(): array
    {
        $out = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY 1") as $t) {
            $out[$t->tablename] = (string) DB::scalar("SELECT md5(coalesce(string_agg(x::text, '|' ORDER BY x::text), '')) FROM public.\"{$t->tablename}\" x");
        }

        return $out;
    }

    /** @return array<string, array<int, string>> tabla de la voz → id → la fila en texto */
    private function voiceRows(): array
    {
        $out = [];
        foreach (self::VOICE_TABLES as $tabla) {
            foreach (DB::select("SELECT x.id, x::text AS fila FROM public.\"{$tabla}\" x ORDER BY x.id") as $f) {
                $out[$tabla][(int) $f->id] = $f->fila;
            }
        }

        return $out;
    }

    /**
     * Entre la foto y ahora cambiaron EXACTAMENTE esas filas (ninguna se creó
     * ni se borró).
     *
     * @param  array<string, array<int, string>>  $antes
     * @param  array<string, list<string>>  $esperadas  tabla → claves de $this->s
     */
    private function assertChangedRows(array $antes, array $esperadas, string $paso): void
    {
        $ahora = $this->voiceRows();
        foreach (self::VOICE_TABLES as $tabla) {
            $this->assertSame(array_keys($antes[$tabla] ?? []), array_keys($ahora[$tabla] ?? []), "{$paso} · {$tabla}: ni una fila más ni una menos");
            $cambiadas = array_keys(array_diff_assoc($ahora[$tabla] ?? [], $antes[$tabla] ?? []));
            sort($cambiadas);
            $ids = array_map(fn (string $k): int => (int) $this->s[$k], $esperadas[$tabla] ?? []);
            sort($ids);
            $this->assertSame($ids, $cambiadas, "{$paso} · {$tabla}: cambian solo las filas esperadas");
        }
    }

    /** Fuera de las 11 tablas de la voz no cambia nada, salvo `$nuevas` filas del libro después de `$libroHasta`. */
    private function assertOnlyVoiceAndOneLedgerRowChanged(array $antes, int $libroHasta, int $nuevas, string $paso): void
    {
        $ahora = $this->digests();
        foreach ($antes as $tabla => $md5) {
            if (in_array($tabla, self::VOICE_TABLES, true) || $tabla === 'ultron_studio_audit_events') {
                continue;
            }
            $this->assertSame($md5, $ahora[$tabla] ?? null, "{$paso} · {$tabla} (fuera de la voz) no cambia");
        }
        $this->assertSame($nuevas, (int) DB::table('ultron_studio_audit_events')->where('id', '>', $libroHasta)->count(), "{$paso} · {$nuevas} fila(s) nueva(s) en el libro");
        $this->assertSame(0, (int) DB::table('ultron_studio_audit_events')->where('id', '>', $libroHasta)->where('event', '<>', 'system.personal_data_redacted')->count());
    }

    /** @return list<string> tabla.disparador=estado de todo el esquema */
    private function triggerStates(): array
    {
        return array_map(static fn (object $f): string => "{$f->t}.{$f->n}={$f->e}", DB::select(
            "SELECT c.relname AS t, tg.tgname AS n, tg.tgenabled::text AS e FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid
               JOIN pg_namespace s ON s.oid = c.relnamespace WHERE s.nspname = 'public' AND NOT tg.tgisinternal ORDER BY 1, 2"));
    }

    /** @return list<string> las restricciones de las 11 tablas, con su definición y si están validadas */
    private function constraintDefinitions(): array
    {
        return array_map(static fn (object $f): string => "{$f->conname} {$f->convalidated} {$f->def}", DB::select(
            "SELECT k.conname, k.convalidated::text AS convalidated, pg_get_constraintdef(k.oid) AS def FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
              WHERE c.relname LIKE 'voice\\_%' OR c.relname LIKE 'ultron\\_studio\\_voice\\_%' ORDER BY 1"));
    }

    /** @return list<string> dueño y privilegios de las 11 tablas */
    private function aclState(): array
    {
        return array_map(static fn (object $f): string => "{$f->relname} {$f->dueno} {$f->acl}", DB::select(
            "SELECT c.relname, pg_get_userbyid(c.relowner) AS dueno, coalesce(c.relacl::text, '') AS acl FROM pg_class c JOIN pg_namespace s ON s.oid = c.relnamespace
              WHERE s.nspname = 'public' AND c.relkind = 'r' AND (c.relname LIKE 'voice\\_%' OR c.relname LIKE 'ultron\\_studio\\_voice\\_%') ORDER BY 1"));
    }

    private function assertAppCannotRewriteInsertOnlyTables(string $paso): void
    {
        foreach (self::VOICE_INSERT_ONLY as $tabla) {
            $p = DB::selectOne("SELECT has_table_privilege(?, ?, 'SELECT')::int AS s, has_table_privilege(?, ?, 'INSERT')::int AS i,
                    has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d",
                array_merge(...array_fill(0, 4, [self::SUP_APP, "public.{$tabla}"])));
            $this->assertSame([1, 1, 0, 0], [(int) $p->s, (int) $p->i, (int) $p->u, (int) $p->d], "{$paso} · la aplicación lee e inserta en {$tabla}, pero no la reescribe ni la borra");
        }
    }

    private function chainVerifies(): string
    {
        return StudioVoicePublications::verifyRow(StudioVoicePublication::query()->where('seq', 1)->sole());
    }

    private function authorshipCount(int $admin, string $nombre): int
    {
        $n = 0;
        foreach (self::VOICE_AUTHORSHIP as [$tabla, $col, $id]) {
            $n += (int) DB::table($tabla)->where($id, $admin)->where($col, $nombre)->count();
        }

        return $n;
    }

    private function assertNoPersonalData(string $texto, string $paso): void
    {
        foreach (['Zoila', 'zoila', 'Pérez', 'Quintín', '9990777', '9990888', '0000099', 'Ana Admin', 'Beto Revisor', $this->s['hp'], $this->s['hq'], $this->s['hs']] as $dato) {
            $this->assertStringNotContainsString($dato, $texto, "{$paso} · la salida no lleva datos personales ni huellas");
        }
    }

    /** @param  array<string, mixed>  $m */
    private function sorted(array $m): array
    {
        ksort($m);

        return $m;
    }
}
