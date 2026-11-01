<?php

namespace Tests\Feature\UltronStudio;

use App\Models\UltronStudio\StudioCampaignLink;
use App\Models\UltronStudio\StudioCanaryNumber;
use App\Models\UltronStudio\StudioIntegrationSnapshot;
use App\Services\UltronStudio\StudioIntegrationConfig;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * LAS GUARDAS DE LA BASE CONTRA EL ROL DE LA APLICACIÓN (contrato H6 §I3.2,
 * §I4.2, §I5.3, §I8; Enmienda 1 D-1 y D-2; §I11 fila «PostgreSQL»).
 *
 * En producción la aplicación entra con un rol SIN superusuario y con solo DML
 * (`iron_app`); las tablas, los disparadores y la función son del dueño
 * (`iron`). Aquí se modela igual: un rol `ultron_studio_h6_t_app` con SELECT,
 * INSERT, UPDATE y DELETE sobre las tablas (los privilegios por defecto de
 * producción), USAGE en las secuencias y sin TRUNCATE. Con ese rol y sobre
 * filas que dejó LA API (no sembradas a mano), cada intento de saltarse una
 * regla por SQL directo tiene que caer en la base:
 *  - borrar, vaciar o cambiar lo inmutable → P0001 (disparador);
 *  - los dos cuatro ojos, el cifrado de voz, el consentimiento, el paso que
 *    falta y lo que exige cada estado → 23514 (CHECK);
 *  - la segunda activa de una campaña y la segunda viva de un teléfono → 23505;
 *  - apagar una guarda (disparador, CHECK, índice, función o réplica) → 42501.
 * Cada intento se deshace (punto de guardado) y no deja nada.
 *
 * Y `down()` se niega con las filas que deja la API, sin tocar nada (en la base
 * desechable de la prueba; nunca contra nada compartido).
 */
class UltronStudioIntegrationsGuardsAdversarialTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** El rol de la aplicación de esta prueba (de clúster; se crea y se deshace dentro de la transacción de la prueba). */
    private const APP_ROLE = 'ultron_studio_h6_t_app';

    private const H6_TABLES = ['ultron_studio_campaign_links', 'ultron_studio_canary_numbers', 'ultron_studio_integration_snapshots'];

    private const CAMPAIGN = '120000000000000387';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        Http::fake(static fn () => Http::response(['sid' => 'AC0123456789abcdef0123456789abcdef', 'status' => 'active', 'type' => 'Full'], 200));
        $this->integrationsFlags(writes: true, probe: true);
        config([
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'AC0123456789abcdef0123456789abcdef',
            'otp.twilio.token' => 'token-de-las-guardas',
            'otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'9f0e',
        ]);
    }

    public function test_como_rol_de_aplicacion_ninguna_tabla_del_hito_admite_borrar_vaciar_ni_cambiar_lo_inmutable(): void
    {
        $this->requirePostgres();
        $f = $this->flowsThroughTheApi();
        $this->grantAppRole();

        $casos = [
            // asociaciones
            ['DELETE FROM ultron_studio_campaign_links WHERE id = ?', [$f['l2']], 'P0001', 'las asociaciones de campaña no se borran (DELETE en ultron_studio_campaign_links)'],
            ['UPDATE ultron_studio_campaign_links SET campaign_id = campaign_id WHERE id = ?', [$f['l2']], 'P0001', 'una asociación de campaña es inmutable'],
            ['UPDATE ultron_studio_campaign_links SET target_kind = target_kind WHERE id = ?', [$f['l1']], 'P0001', 'una asociación de campaña es inmutable'],
            ["UPDATE ultron_studio_campaign_links SET status = 'active' WHERE id = ?", [$f['l1']], 'P0001', 'una asociación de campaña inactiva es definitiva'],
            ['UPDATE ultron_studio_campaign_links SET deactivation_reason = ? WHERE id = ?', ['Otro motivo', $f['l1']], 'P0001', 'una asociación de campaña inactiva es definitiva'],
            // números canarios
            ['DELETE FROM ultron_studio_canary_numbers WHERE id = ?', [$f['w']], 'P0001', 'los números canarios se revocan, no se borran (DELETE en ultron_studio_canary_numbers)'],
            ['UPDATE ultron_studio_canary_numbers SET alias = alias WHERE id = ?', [$f['w']], 'P0001', 'un número canario es inmutable'],
            ['UPDATE ultron_studio_canary_numbers SET phone_hash = ? WHERE id = ?', [str_repeat('a', 64), $f['w']], 'P0001', 'un número canario es inmutable'],
            ['UPDATE ultron_studio_canary_numbers SET registered_by_admin_id = ? WHERE id = ?', [$f['root'], $f['w']], 'P0001', 'un número canario es inmutable'],
            ['UPDATE ultron_studio_canary_numbers SET status_changed_at = now() WHERE id = ?', [$f['r']], 'P0001', 'un número canario revocado es definitivo'],
            ['UPDATE ultron_studio_canary_numbers SET phone_encrypted = ? WHERE id = ?', ['otro-cifrado', $f['w']], 'P0001', 'el cifrado de un número canario solo se borra'],
            ['UPDATE ultron_studio_canary_numbers SET phone_encrypted = ? WHERE id = ?', ['otro-cifrado', $f['v']], 'P0001', 'el cifrado de un número canario solo se borra'],
            // instantáneas
            ['UPDATE ultron_studio_integration_snapshots SET note = ? WHERE id = ?', ['nota', $f['ack']], 'P0001', 'las instantáneas de integración son de solo inserción (UPDATE en ultron_studio_integration_snapshots)'],
            ["UPDATE ultron_studio_integration_snapshots SET outcome = 'ok' WHERE id = ?", [$f['probe']], 'P0001', 'las instantáneas de integración son de solo inserción'],
            ['DELETE FROM ultron_studio_integration_snapshots WHERE id = ?', [$f['ack']], 'P0001', 'las instantáneas de integración son de solo inserción (DELETE en ultron_studio_integration_snapshots)'],
            // el libro (guardas de F) con los sujetos nuevos
            ["UPDATE ultron_studio_audit_events SET summary = 'otro' WHERE subject_type = 'canary_number'", [], 'P0001', null],
            ["DELETE FROM ultron_studio_audit_events WHERE subject_type = 'integration'", [], 'P0001', null],
        ];
        foreach ($casos as [$sql, $bindings, $estado, $motivo]) {
            [$sqlstate, $mensaje] = $this->asApp(fn () => DB::statement($sql, $bindings));
            $this->assertSame($estado, $sqlstate, "{$sql}: {$mensaje}");
            if ($motivo !== null) {
                $this->assertStringContainsString($motivo, $mensaje, $sql);
            }
        }

        // TRUNCATE: sin el privilegio (como en producción) ni se intenta; con él, lo para el disparador.
        foreach (self::H6_TABLES as $tabla) {
            $this->assertSame('42501', $this->asApp(fn () => DB::statement("TRUNCATE {$tabla}"))[0], "TRUNCATE {$tabla} sin privilegio");
            [$sqlstate, $mensaje] = $this->asApp(fn () => DB::statement("TRUNCATE {$tabla}"), grantTruncate: true);
            $this->assertSame('P0001', $sqlstate, "TRUNCATE {$tabla} con privilegio: {$mensaje}");
            $this->assertStringContainsString("(TRUNCATE en {$tabla})", $mensaje);
        }

        $this->assertNothingChanged($f);
    }

    public function test_como_rol_de_aplicacion_los_cuatro_ojos_el_cifrado_y_cada_estado_se_cumplen_en_la_base(): void
    {
        $this->requirePostgres();
        $f = $this->flowsThroughTheApi();
        $this->grantAppRole();
        $ahora = now()->utc()->format('Y-m-d H:i:s');

        $casos = [
            // Los dos cuatro ojos, con DML directo y la MISMA identidad que registró.
            ["UPDATE ultron_studio_canary_numbers SET status = 'authorized', authorized_by_admin_id = registered_by_admin_id, authorized_by_name = 'Yo misma', authorized_at = ?, status_changed_at = ? WHERE id = ?", [$ahora, $ahora, $f['w']], 'uscn_four_eyes_chk'],
            ["UPDATE ultron_studio_canary_numbers SET status = 'active', activated_by_admin_id = registered_by_admin_id, activated_by_name = 'Yo misma', activated_at = ?, status_changed_at = ? WHERE id = ?", [$ahora, $ahora, $f['a']], 'uscn_activation_four_eyes_chk'],
            // Reescribir quién lo hizo con quien registró.
            ['UPDATE ultron_studio_canary_numbers SET authorized_by_admin_id = registered_by_admin_id WHERE id = ?', [$f['a']], 'uscn_four_eyes_chk'],
            // Saltarse autorizar: activo sin autorización.
            ["UPDATE ultron_studio_canary_numbers SET status = 'active', activated_by_admin_id = ?, activated_by_name = 'Root', activated_at = ?, status_changed_at = ? WHERE id = ?", [$f['root'], $ahora, $ahora, $f['w']], 'uscn_authorized_chk'],
            ["UPDATE ultron_studio_canary_numbers SET status = 'active' WHERE id = ?", [$f['a']], 'uscn_active_chk'],
            // Voz: revocar sin borrar el cifrado, y borrar el cifrado sin revocar.
            ["UPDATE ultron_studio_canary_numbers SET status = 'revoked', revoked_by_admin_id = ?, revoked_by_name = 'Root', revoked_at = ?, revoke_reason = 'Sin borrar el cifrado', status_changed_at = ? WHERE id = ?", [$f['root'], $ahora, $ahora, $f['v']], 'uscn_revoke_chk'],
            ['UPDATE ultron_studio_canary_numbers SET phone_encrypted = NULL WHERE id = ?', [$f['v']], 'uscn_voice_cipher_chk'],
            // Revocar sin motivo ni quién.
            ["UPDATE ultron_studio_canary_numbers SET status = 'revoked', status_changed_at = ? WHERE id = ?", [$ahora, $f['w']], 'uscn_revoke_chk'],
            // Un estado fuera del dominio.
            ["UPDATE ultron_studio_canary_numbers SET status = 'paused' WHERE id = ?", [$f['a']], 'uscn_status_chk'],
            // Una asociación cerrada sin quién ni por qué.
            ["UPDATE ultron_studio_campaign_links SET status = 'inactive' WHERE id = ?", [$f['l2']], 'uscl_deactivation_chk'],
        ];
        foreach ($casos as [$sql, $bindings, $check]) {
            [$sqlstate, $mensaje] = $this->asApp(fn () => DB::statement($sql, $bindings));
            $this->assertSame('23514', $sqlstate, "{$sql}: {$mensaje}");
            $this->assertStringContainsString($check, $mensaje, $sql);
        }

        // Inserciones a mano que la API nunca haría.
        $numero = fn (array $cambios): array => $cambios + [
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'channel' => 'whatsapp', 'alias' => 'Insertado a mano',
            'phone_hash' => str_repeat('b', 64), 'phone_last4' => '0002', 'country_code' => '57', 'status' => 'pending', 'consent_attested' => true,
            'registered_by_admin_id' => $f['root'], 'registered_by_name' => 'Root', 'status_changed_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
        ];
        $foto = fn (array $cambios): array => $cambios + [
            'uuid' => (string) Str::uuid(), 'provider' => 'twilio', 'kind' => 'probe', 'fingerprint' => str_repeat('c', 64), 'facts' => '{}', 'outcome' => 'ok',
            'actor_type' => 'admin', 'actor_admin_id' => $f['root'], 'actor_name' => 'Root', 'created_at' => $ahora,
        ];
        $inserciones = [
            ['ultron_studio_canary_numbers', $numero(['consent_attested' => false]), '23514', 'uscn_consent_chk'],
            ['ultron_studio_canary_numbers', $numero(['phone_encrypted' => 'cifrado-en-whatsapp']), '23514', 'uscn_whatsapp_no_cipher_chk'],
            ['ultron_studio_canary_numbers', $numero(['channel' => 'voice']), '23514', 'uscn_voice_cipher_chk'],
            ['ultron_studio_canary_numbers', $numero(['phone_hash' => '+573001112233'.str_repeat('0', 51)]), '23514', 'uscn_hash_chk'],
            ['ultron_studio_canary_numbers', $numero(['phone_last4' => '12345']), '22001', null],
            ['ultron_studio_canary_numbers', $numero(['phone_last4' => '12a4']), '23514', 'uscn_last4_chk'],
            // La misma viva por canal y teléfono que la API ya registró.
            ['ultron_studio_canary_numbers', $numero(['phone_hash' => $f['wHash']]), '23505', 'uscn_one_live'],
            // La segunda activa de la campaña.
            ['ultron_studio_campaign_links', [
                'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'ad_account_id' => self::ACCOUNTS[0], 'campaign_id' => self::CAMPAIGN,
                'target_kind' => 'baseline', 'status' => 'active', 'created_by_admin_id' => $f['root'], 'created_by_name' => 'Root', 'created_at' => $ahora,
            ], '23505', 'uscl_one_active'],
            ['ultron_studio_integration_snapshots', $foto(['outcome' => null]), '23514', 'usis_outcome_chk'],
            ['ultron_studio_integration_snapshots', $foto(['kind' => 'config']), '23514', 'usis_outcome_chk'],
            ['ultron_studio_integration_snapshots', $foto(['actor_admin_id' => null]), '23514', 'usis_actor_chk'],
            ['ultron_studio_integration_snapshots', $foto(['fingerprint' => strtoupper(str_repeat('c', 64))]), '23514', 'usis_fingerprint_chk'],
            ['ultron_studio_integration_snapshots', $foto(['provider' => 'elevenlabs']), '23514', 'usis_provider_chk'],
            ['ultron_studio_audit_events', [
                'event' => 'canary_number.registered', 'subject_type' => 'phone', 'subject_uuid' => (string) Str::uuid(), 'actor_type' => 'admin',
                'actor_admin_id' => $f['root'], 'actor_name' => 'Root', 'summary' => 'Sujeto fuera de los siete', 'created_at' => $ahora,
            ], '23514', 'usae_subject_type_chk'],
        ];
        foreach ($inserciones as [$tabla, $fila, $estado, $nombre]) {
            [$sqlstate, $mensaje] = $this->asApp(fn () => DB::table($tabla)->insert($fila));
            $this->assertSame($estado, $sqlstate, "INSERT {$tabla} ".json_encode(array_intersect_key($fila, array_flip(['channel', 'consent_attested', 'kind', 'outcome', 'subject_type']))).": {$mensaje}");
            if ($nombre !== null) {
                $this->assertStringContainsString($nombre, $mensaje);
            }
        }

        // D-1: la sustituta se anota UNA vez y solo en una inactiva.
        foreach ([
            ['UPDATE ultron_studio_campaign_links SET replaced_by_link_id = replaced_by_link_id WHERE id = ?', [$f['l1']]],
            ['UPDATE ultron_studio_campaign_links SET replaced_by_link_id = ? WHERE id = ?', [$f['l1'], $f['l2']]],
        ] as [$sql, $bindings]) {
            [$sqlstate, $mensaje] = $this->asApp(fn () => DB::statement($sql, $bindings));
            $this->assertSame('P0001', $sqlstate, "{$sql}: {$mensaje}");
            $this->assertStringContainsString('la asociación que sustituye a otra se anota una sola vez', $mensaje);
        }

        // Lo que SÍ puede hacer el rol (la API lo hace así): un UPDATE condicionado válido.
        $this->assertSame([null, 'deshecho'], $this->asApp(fn () => $this->assertSame(1, DB::update(
            "UPDATE ultron_studio_canary_numbers SET status = 'authorized', authorized_by_admin_id = ?, authorized_by_name = 'Root', authorized_at = ?, status_changed_at = ? WHERE id = ? AND status = 'pending'",
            [$f['root'], $ahora, $ahora, $f['w']],
        ))), 'el rol de la aplicación no puede hacer lo que hace la API: el modelo de privilegios no es el de producción');

        $this->assertNothingChanged($f);
    }

    public function test_como_rol_de_aplicacion_no_se_puede_apagar_ninguna_guarda(): void
    {
        $this->requirePostgres();
        $this->flowsThroughTheApi();
        $this->grantAppRole();

        foreach ([
            'ALTER TABLE ultron_studio_canary_numbers DISABLE TRIGGER uscn_forbid_delete',
            'ALTER TABLE ultron_studio_campaign_links DISABLE TRIGGER ALL',
            'DROP TRIGGER uscn_revoked_is_final ON ultron_studio_canary_numbers',
            'ALTER TABLE ultron_studio_canary_numbers DROP CONSTRAINT uscn_four_eyes_chk',
            'ALTER TABLE ultron_studio_canary_numbers DROP CONSTRAINT uscn_activation_four_eyes_chk',
            'ALTER TABLE ultron_studio_integration_snapshots DROP CONSTRAINT usis_outcome_chk',
            'DROP INDEX uscn_one_live',
            'DROP INDEX uscl_one_active',
            'ALTER TABLE ultron_studio_audit_events DROP CONSTRAINT usae_subject_type_chk',
            "SET session_replication_role = 'replica'",
            'CREATE OR REPLACE FUNCTION ultron_studio_forbid() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$',
        ] as $sql) {
            [$sqlstate, $mensaje] = $this->asApp(fn () => DB::statement($sql));
            $this->assertSame('42501', $sqlstate, "{$sql}: {$mensaje}");
        }
    }

    /**
     * `down()` (las dos migraciones del H6) se niega con lo que deja la API —
     * asociaciones, números, instantáneas y sus eventos— antes de tocar nada:
     * las tablas, sus guardas y sus filas siguen ahí. En los dos motores.
     */
    public function test_down_se_niega_con_las_filas_que_deja_la_api_y_no_toca_nada(): void
    {
        $f = $this->flowsThroughTheApi();
        $antes = $this->schemaFootprint();

        foreach (['2026_11_01_100002_add_ultron_studio_integration_guards', '2026_11_01_100001_create_ultron_studio_integration_tables'] as $migracion) {
            $m = require database_path("migrations/{$migracion}.php");
            try {
                DB::transaction(fn () => $m->down());
                $this->fail("{$migracion}: down() no se negó con datos del Hito 6");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('down() se niega a revertir el Hito 6 con datos del Hito 6', $e->getMessage());
                $this->assertMatchesRegularExpression('/\(2 asociaciones de campaña, 4 números canarios, 2 instantáneas de integración y \d+ eventos del libro/', $e->getMessage());
            }
        }

        $this->assertSame($antes, $this->schemaFootprint(), 'down() tocó el esquema antes de negarse');
        $this->assertNothingChanged($f);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    private function requirePostgres(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('El rol de la aplicación y los CHECK son de PostgreSQL (en SQLite los disparadores los cubre UltronStudioIntegrationsSchemaTest).');
        }
    }

    /**
     * Lo que deja la API con las escrituras y la sonda encendidas: una
     * asociación sustituida por otra, un número de WhatsApp pendiente, uno
     * autorizado, uno de voz (cifrado) pendiente y uno revocado, un
     * reconocimiento y una sonda.
     *
     * @return array<string, mixed>
     */
    private function flowsThroughTheApi(): array
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana Registra');
        $root = $this->personWith(self::SUPER_KEYS, 'Root Autoriza');
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], self::CAMPAIGN, 'Pauta octubre');
        $campana = self::campaignId(self::CAMPAIGN);

        $l1 = (string) $this->integrations('POST', 'meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => $campana, 'target' => ['kind' => 'baseline']])->assertCreated()->json('data.link.uuid');
        $version = $this->versionIn($root, 'APPROVED');
        $l2 = (string) $this->integrations('POST', 'meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => $campana, 'target' => ['kind' => 'version', 'versionUuid' => $version->uuid], 'replaces' => $l1])->assertCreated()->json('data.link.uuid');

        $w = (string) $this->registerCanary($ana, '+573001110101')->assertCreated()->json('data.number.uuid');
        $a = (string) $this->registerCanary($ana, '+573001110102')->assertCreated()->json('data.number.uuid');
        $this->integrations('POST', "canaries/{$a}/authorize", $root)->assertOk();
        $v = (string) $this->registerCanary($ana, '+576015550101', 'voice', 'Fijo de recepción')->assertCreated()->json('data.number.uuid');
        $r = (string) $this->registerCanary($ana, '+573001110103')->assertCreated()->json('data.number.uuid');
        $this->integrations('POST', "canaries/{$r}/revoke", $ana, ['reason' => 'Ya no se usa'])->assertOk();

        $huella = StudioIntegrationConfig::fingerprintOf(StudioIntegrationConfig::facts('twilio'));
        $this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => $huella])->assertCreated();
        $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertCreated();

        $id = static fn (string $modelo, string $uuid): int => (int) $modelo::query()->where('uuid', $uuid)->value('id');
        $vozCifrada = DB::table('ultron_studio_canary_numbers')->where('uuid', $v)->value('phone_encrypted');
        $this->assertNotNull($vozCifrada, 'el número de voz tiene que llegar cifrado');

        return [
            'root' => $root->id,
            'l1' => $id(StudioCampaignLink::class, $l1), 'l2' => $id(StudioCampaignLink::class, $l2),
            'w' => $id(StudioCanaryNumber::class, $w), 'a' => $id(StudioCanaryNumber::class, $a), 'v' => $id(StudioCanaryNumber::class, $v), 'r' => $id(StudioCanaryNumber::class, $r),
            'wHash' => (string) DB::table('ultron_studio_canary_numbers')->where('uuid', $w)->value('phone_hash'),
            'ack' => (int) StudioIntegrationSnapshot::query()->where('kind', 'config')->value('id'),
            'probe' => (int) StudioIntegrationSnapshot::query()->where('kind', 'probe')->value('id'),
            'rows' => $this->rowsFootprint(),
        ];
    }

    /** El rol de la aplicación con los privilegios de producción (§7.4 del informe de datos): DML y secuencias; sin TRUNCATE. */
    private function grantAppRole(): void
    {
        DB::statement('DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = \''.self::APP_ROLE.'\') THEN CREATE ROLE '.self::APP_ROLE.' NOLOGIN NOSUPERUSER NOINHERIT; END IF; END $$');
        DB::statement('GRANT USAGE ON SCHEMA public TO '.self::APP_ROLE);
        $tablas = [...self::H6_TABLES, 'ultron_studio_audit_events', 'ultron_studio_versions'];
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON '.implode(', ', $tablas).' TO '.self::APP_ROLE);
        DB::statement('GRANT USAGE, SELECT, UPDATE ON '.implode(', ', array_map(static fn (string $t): string => "{$t}_id_seq", $tablas)).' TO '.self::APP_ROLE);
        $rol = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = ?', [self::APP_ROLE]);
        $this->assertFalse((bool) $rol->rolsuper);
        $this->assertFalse((bool) $rol->rolbypassrls);
        foreach (self::H6_TABLES as $t) {
            $this->assertFalse((bool) DB::scalar('select has_table_privilege(?, ?, \'TRUNCATE\')', [self::APP_ROLE, "public.{$t}"]), "{$t}: el rol no debe poder TRUNCATE");
            $this->assertNotSame(self::APP_ROLE, DB::scalar('select tableowner from pg_tables where tablename = ?', [$t]));
        }
    }

    /**
     * Ejecuta `$fn` como el rol de la aplicación dentro de un punto de guardado
     * que SIEMPRE se deshace. Devuelve [SQLSTATE o null, mensaje].
     *
     * @return array{0:?string,1:string}
     */
    private function asApp(Closure $fn, bool $grantTruncate = false): array
    {
        $deshacer = new RuntimeException('deshecho');
        try {
            DB::transaction(function () use ($fn, $grantTruncate, $deshacer): void {
                if ($grantTruncate) {
                    DB::statement('GRANT TRUNCATE ON '.implode(', ', self::H6_TABLES).' TO '.self::APP_ROLE);
                }
                DB::statement('SET LOCAL ROLE '.self::APP_ROLE);
                $this->assertSame(self::APP_ROLE, DB::scalar('select current_user'));
                $fn();

                throw $deshacer;
            });
        } catch (QueryException $e) {
            return [(string) ($e->getPrevious()?->errorInfo[0] ?? $e->getCode()), $e->getMessage()];
        } catch (RuntimeException $e) {
            if ($e === $deshacer) {
                $this->assertNotSame(self::APP_ROLE, DB::scalar('select current_user'), 'el rol no se deshizo');

                return [null, 'deshecho'];
            }

            throw $e;
        }

        return [null, 'sin deshacer'];
    }

    /** @return array<string, mixed> Las filas del hito, enteras, para ver que nada cambió. */
    private function rowsFootprint(): array
    {
        $out = [];
        foreach ([...self::H6_TABLES, 'ultron_studio_audit_events'] as $t) {
            $out[$t] = hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()));
        }

        return $out;
    }

    /** @return array<string, mixed> Tablas, disparadores, CHECK e índices del Studio. */
    private function schemaFootprint(): array
    {
        $tablas = array_values(array_filter(Schema::getTableListing(schemaQualified: false), static fn (string $t): bool => str_starts_with($t, 'ultron_studio_')));
        sort($tablas);
        if (! $this->isPostgres()) {
            return ['tablas' => $tablas, 'objetos' => json_decode((string) json_encode(DB::table('sqlite_master')->where('tbl_name', 'like', 'ultron_studio_%')->orderBy('name')->get(['type', 'name', 'sql'])), true)];
        }

        return [
            'tablas' => $tablas,
            'disparadores' => (int) DB::scalar("select count(*) from pg_trigger t join pg_class c on c.oid = t.tgrelid where not t.tgisinternal and c.relname like 'ultron_studio_%'"),
            'check' => (int) DB::scalar("select count(*) from pg_constraint k join pg_class c on c.oid = k.conrelid where k.contype = 'c' and c.relname like 'ultron_studio_%'"),
            'indices' => json_decode((string) json_encode(DB::select("select indexname, indexdef from pg_indexes where tablename like 'ultron_studio_%' order by indexname")), true),
        ];
    }

    /** @param  array<string, mixed>  $f */
    private function assertNothingChanged(array $f): void
    {
        $this->assertSame($f['rows'], $this->rowsFootprint(), 'un intento dejó algo en las tablas del hito o en el libro');
    }
}
