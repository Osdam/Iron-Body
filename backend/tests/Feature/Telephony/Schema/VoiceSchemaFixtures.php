<?php

namespace Tests\Feature\Telephony\Schema;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ayudantes de las pruebas del esquema de la telefonía (paquete DB8 del Hito 8,
 * migración 2026_11_10_100002). Filas VÁLIDAS mínimas de cada tabla, escritas
 * con SQL a pelo (sin los modelos: lo que se prueba es la base) y sin ningún
 * teléfono: las huellas son sha256 de una etiqueta y los 4 dígitos, `0707`.
 *
 * Lo esperado sale del contrato y está escrito en cada prueba, no tomado de
 * las constantes de la migración.
 */
trait VoiceSchemaFixtures
{
    protected const MIGRATION = '2026_11_10_100002_create_voice_tables';

    protected const PREFIX = 'voice: ';

    protected const TABLES = ['voice_contacts', 'voice_rne_checks', 'voice_calls', 'voice_call_events', 'voice_consents', 'voice_exclusions', 'voice_brakes'];

    /** Las cinco de solo inserción (protección 10). */
    protected const INSERT_ONLY = ['voice_call_events', 'voice_consents', 'voice_exclusions', 'voice_rne_checks', 'voice_brakes'];

    /** El rol de prueba que hace de `iron_app` (solo PG); se crea y se deshace dentro de cada prueba. */
    protected const APP_ROLE = 'ultron_studio_h8_db_app';

    protected const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    private ?Migration $voiceMigration = null;

    private int $secuencia = 0;

    protected function voiceMigration(): Migration
    {
        return $this->voiceMigration ??= require database_path('migrations/'.self::MIGRATION.'.php');
    }

    // ── Filas válidas ───────────────────────────────────────────────────────

    protected function hex(string $etiqueta): string
    {
        return hash('sha256', 'h8-db-'.$etiqueta);
    }

    protected function callSid(string $etiqueta): string
    {
        return 'CA'.substr(hash('sha256', 'sid-'.$etiqueta), 0, 32);
    }

    /** @param  array<string, mixed>  $o */
    protected function insertContact(array $o = []): int
    {
        $n = ++$this->secuencia;

        return (int) DB::table('voice_contacts')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'phone_hash' => $this->hex("contacto-{$n}"),
            'phone_last4' => '0707',
            'phone_encrypted' => 'cifrado-de-prueba-'.$n,
            'source_type' => 'manual',
            'source_id' => null,
            'status' => 'active',
            'registered_by_admin_id' => 1,
            'registered_by_name' => 'Ana',
            'registered_by_role' => 'Super Admin',
            'status_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $o));
    }

    /** @param  array<string, mixed>  $o */
    protected function insertRneCheck(array $o = []): int
    {
        $n = ++$this->secuencia;

        return (int) DB::table('voice_rne_checks')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'phone_hash' => $this->hex("rne-{$n}"),
            'contact_id' => null,
            'checked_at' => now()->subMinute(),
            'result' => 'not_listed',
            'method' => 'manual_portal',
            'evidence_ref' => 'Consulta CRC de prueba',
            'actor_type' => 'admin',
            'actor_admin_id' => 1,
            'actor_name' => 'Ana',
            'actor_role' => 'Super Admin',
            'created_at' => now(),
        ], $o));
    }

    /**
     * Una saliente a un canario, recién pedida (`requested`), con su sello
     * aplicado. Sin ids del proveedor (llegan al marcar).
     *
     * @return array<string, mixed>
     */
    protected function outboundRow(array $o = []): array
    {
        $n = ++$this->secuencia;

        return array_merge([
            'uuid' => (string) Str::uuid(),
            'call_ref' => (string) Str::uuid(),
            'direction' => 'outbound',
            'status' => 'requested',
            'target_source' => 'canary_registry',
            'canary_number_uuid' => (string) Str::uuid(),
            'voice_contact_id' => null,
            'remote_hash' => $this->hex("remoto-{$n}"),
            'remote_last4' => '0707',
            'caller_authorized' => null,
            'policy_reason' => null,
            'client_request_id' => (string) Str::uuid(),
            'requested_by_type' => 'admin',
            'requested_by_admin_id' => 1,
            'requested_by_name' => 'Ana',
            'requested_by_role' => 'Super Admin',
            'attempt' => 1,
            'parent_call_id' => null,
            'transfer_of_call_id' => null,
        ], $this->appliedStamp(), [
            'provider_conversation_id' => null,
            'provider_call_sid' => null,
            'observed_agent_id' => null,
            'observed_version_id' => null,
            'observed_branch_id' => null,
            'seal_verified' => null,
            'budget_day' => '2026-11-03',
            'reserved_micros' => 450000,
            'provider_cost_micros' => null,
            'carrier_cost_micros' => null,
            'duration_seconds' => null,
            'termination_reason' => null,
            'failure_code' => null,
            'tool_calls' => 0,
            'started_at' => null,
            'ended_at' => null,
            'status_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $o);
    }

    /**
     * Una entrante de un canario autorizado, aceptada en la iniciación
     * (`active`), con la conversación y la llamada de Twilio.
     *
     * @return array<string, mixed>
     */
    protected function inboundRow(array $o = []): array
    {
        $n = ++$this->secuencia;

        return array_merge($this->outboundRow(), [
            'direction' => 'inbound',
            'status' => 'active',
            'target_source' => null,
            'canary_number_uuid' => (string) Str::uuid(),
            'remote_hash' => $this->hex("llama-{$n}"),
            'caller_authorized' => true,
            'client_request_id' => null,
            'requested_by_type' => null,
            'requested_by_admin_id' => null,
            'requested_by_name' => null,
            'requested_by_role' => null,
            'provider_conversation_id' => 'conv_entrante_'.$n,
            'provider_call_sid' => $this->callSid("entrante-{$n}"),
            'started_at' => now(),
        ], $o);
    }

    /**
     * Una entrante DENEGADA en la iniciación (E1-2): su fila nace `denied`,
     * con la decisión y su motivo; el agente dice «línea no disponible».
     *
     * @return array<string, mixed>
     */
    protected function deniedRow(array $o = []): array
    {
        return array_merge($this->inboundRow(), ['status' => 'denied', 'caller_authorized' => false, 'policy_reason' => 'not_an_active_voice_canary', 'canary_number_uuid' => null], $o);
    }

    /**
     * Un TRAMO DE TRANSFERENCIA (E1-2) de la llamada `$madre`: la saliente de
     * Twilio al destino del perfil, sin sello propio (`inherited`), sin
     * conversación (es la de la madre), sin herramientas ni reserva propia, y
     * nacido en el estado en que lo ve el proveedor.
     *
     * @return array<string, mixed>
     */
    protected function legRow(int $madre, array $o = []): array
    {
        $n = ++$this->secuencia;

        return array_merge($this->outboundRow(), [
            'status' => 'completed',
            'target_source' => 'transfer_destination',
            'canary_number_uuid' => null,
            'remote_hash' => $this->hex("recepcion-{$n}"),
            'client_request_id' => null,
            'requested_by_type' => null,
            'requested_by_admin_id' => null,
            'requested_by_name' => null,
            'requested_by_role' => null,
            'transfer_of_call_id' => $madre,
            'voice_publication_seq' => null,
            'part' => null,
            'stamp_state' => 'inherited',
            'studio_version_uuid' => null,
            'studio_content_hash' => null,
            'voice_profile_hash' => null,
            'spec_hash' => null,
            'payload_hash' => null,
            'compiler_version' => null,
            'provider_agent_id' => null,
            'provider_call_sid' => $this->callSid("tramo-{$n}"),
            'reserved_micros' => 0,
            'carrier_cost_micros' => 140000,
            'duration_seconds' => 120,
            'started_at' => now()->subMinutes(2),
            'ended_at' => now(),
        ], $o);
    }

    /** @param  array<string, mixed>  $o */
    protected function insertLeg(int $madre, array $o = []): int
    {
        return (int) DB::table('voice_calls')->insertGetId($this->legRow($madre, $o));
    }

    /** @param  array<string, mixed>  $o */
    protected function insertDenied(array $o = []): int
    {
        return (int) DB::table('voice_calls')->insertGetId($this->deniedRow($o));
    }

    /** @return array<string, mixed> */
    protected function appliedStamp(): array
    {
        return [
            'voice_publication_seq' => 1,
            'part' => 'canary',
            'stamp_state' => 'applied',
            'studio_version_uuid' => (string) Str::uuid(),
            'studio_content_hash' => $this->hex('version'),
            'voice_profile_hash' => $this->hex('perfil'),
            'spec_hash' => $this->hex('spec'),
            'payload_hash' => $this->hex('payload'),
            'compiler_version' => 'ultron-voice-compiler/1',
            'provider' => 'elevenlabs',
            'provider_agent_id' => 'agent_canary_1',
        ];
    }

    /** @param  array<string, mixed>  $o */
    protected function insertOutbound(array $o = []): int
    {
        return (int) DB::table('voice_calls')->insertGetId($this->outboundRow($o));
    }

    /** @param  array<string, mixed>  $o */
    protected function insertInbound(array $o = []): int
    {
        return (int) DB::table('voice_calls')->insertGetId($this->inboundRow($o));
    }

    /** @param  array<string, mixed>  $o */
    protected function insertEvent(array $o = []): int
    {
        $n = ++$this->secuencia;

        return (int) DB::table('voice_call_events')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'call_id' => null,
            'provider' => 'elevenlabs',
            'kind' => 'post_call_transcription',
            'provider_event_key' => 'conv_evento_'.$n,
            'tool' => null,
            'outcome' => null,
            'codes' => null,
            'actor_type' => 'provider',
            'actor_admin_id' => null,
            'actor_name' => null,
            'actor_role' => null,
            'occurred_at' => now(),
            'created_at' => now(),
        ], $o));
    }

    /** Un consentimiento cargado por Ana (1) y confirmado por Beto (2). @param  array<string, mixed>  $o */
    protected function insertConsent(int $contactId, array $o = []): int
    {
        return (int) DB::table('voice_consents')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'contact_id' => $contactId,
            'kind' => 'granted',
            'channel' => 'voice_call',
            'purpose' => 'commercial',
            'source' => 'admin_with_evidence',
            'evidence' => 'Formulario firmado de prueba',
            'actor_type' => 'admin',
            'recorded_by_admin_id' => 1,
            'recorded_by_name' => 'Ana',
            'recorded_by_role' => 'Super Admin',
            'recorded_at' => now()->subMinute(),
            'confirmed_by_admin_id' => 2,
            'confirmed_by_name' => 'Beto',
            'confirmed_by_role' => 'Super Admin',
            'created_at' => now(),
        ], $o));
    }

    /** @param  array<string, mixed>  $o */
    protected function insertExclusion(array $o = []): int
    {
        $n = ++$this->secuencia;

        return (int) DB::table('voice_exclusions')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'phone_hash' => $this->hex("excluido-{$n}"),
            'phone_last4' => '0707',
            'source' => 'admin',
            'call_id' => null,
            'contact_id' => null,
            'rne_check_id' => null,
            'note' => null,
            'actor_type' => 'admin',
            'actor_admin_id' => 1,
            'actor_name' => 'Ana',
            'actor_role' => 'Super Admin',
            'created_at' => now(),
        ], $o));
    }

    /** Una apertura automática del freno. @param  array<string, mixed>  $o */
    protected function insertBrakeOpen(array $o = []): int
    {
        return (int) DB::table('voice_brakes')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'kind' => 'open',
            'brake_id' => null,
            'reason' => 'guard_violation',
            'open_key' => 'event:'.Str::uuid(),
            'call_id' => null,
            'event_id' => null,
            'evidence' => '{"codes":["R-02"],"turn":3}',
            'note' => null,
            'client_request_id' => null,
            'actor_type' => 'system',
            'actor_admin_id' => null,
            'actor_name' => 'voice:guard',
            'actor_role' => null,
            'created_at' => now(),
        ], $o));
    }

    /** Una persona suelta la apertura `$brakeId`. @param  array<string, mixed>  $o */
    protected function insertBrakeRelease(int $brakeId, array $o = []): int
    {
        return (int) DB::table('voice_brakes')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'kind' => 'release',
            'brake_id' => $brakeId,
            'reason' => null,
            'open_key' => null,
            'call_id' => null,
            'event_id' => null,
            'evidence' => null,
            'note' => 'Revisado con la grabación del panel: falso positivo',
            'client_request_id' => (string) Str::uuid(),
            'actor_type' => 'admin',
            'actor_admin_id' => 1,
            'actor_name' => 'Ana',
            'actor_role' => 'Super Admin',
            'created_at' => now(),
        ], $o));
    }

    /** Una fila en cada tabla, encadenadas (para down() y para el rol de la aplicación). @return array<string, int> */
    protected function seedOneOfEach(): array
    {
        $contacto = $this->insertContact();
        $rne = $this->insertRneCheck(['contact_id' => $contacto]);
        $llamada = $this->insertOutbound(['target_source' => 'voice_contacts', 'canary_number_uuid' => null, 'voice_contact_id' => $contacto]);
        $evento = $this->insertEvent(['call_id' => $llamada, 'provider' => 'laravel', 'kind' => 'outbound_requested', 'provider_event_key' => null, 'actor_type' => 'system', 'actor_name' => 'voice:gate']);
        $consentimiento = $this->insertConsent($contacto);
        $exclusion = $this->insertExclusion(['source' => 'spoken_opt_out', 'call_id' => $llamada, 'actor_type' => 'system', 'actor_admin_id' => null, 'actor_name' => 'voice:tool']);
        $freno = $this->insertBrakeOpen(['call_id' => $llamada, 'event_id' => $evento]);

        return compact('contacto', 'rne', 'llamada', 'evento', 'consentimiento', 'exclusion', 'freno');
    }

    // ── Aserciones ──────────────────────────────────────────────────────────

    /** Lo rechaza un disparador: P0001 en PG, 23000 en SQLite, con el mismo texto. */
    protected function assertGuarded(Closure $sentencia, string $mensaje, string $que = ''): void
    {
        $this->assertRejected($sentencia, $this->isPostgres() ? 'P0001' : '23000', self::PREFIX.$mensaje, $que);
    }

    /** Solo PG: lo rechaza un CHECK (23514) con ese nombre. */
    protected function assertCheckRejects(Closure $sentencia, string $check, string $que = ''): void
    {
        $this->assertRejected($sentencia, '23514', $check, $que);
    }

    /** Lo rechaza un UNIQUE: el índice en PG (23505), las columnas en SQLite (23000). @param  list<string>  $columnas */
    protected function assertUniqueRejects(Closure $sentencia, string $indice, string $tabla, array $columnas, string $que = ''): void
    {
        $this->isPostgres()
            ? $this->assertRejected($sentencia, '23505', $indice, $que)
            : $this->assertRejected($sentencia, '23000', 'UNIQUE constraint failed: '.implode(', ', array_map(static fn (string $c): string => "{$tabla}.{$c}", $columnas)), $que);
    }

    protected function assertRejected(Closure $sentencia, string $sqlstate, string $fragmento, string $que = ''): void
    {
        try {
            DB::transaction(fn () => $sentencia());
            $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}) {$que}");
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, (string) $e->getCode(), $que.': '.$e->getMessage());
            $this->assertStringContainsString($fragmento, $e->getMessage(), $que);
        }
    }

    /** La sentencia pasa (en su SAVEPOINT, que se confirma). */
    protected function assertAccepted(Closure $sentencia, string $que = ''): void
    {
        try {
            DB::transaction(fn () => $sentencia());
        } catch (QueryException $e) {
            $this->fail("La base rechazó lo que tenía que aceptar {$que}: ".$e->getMessage());
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Solo PG: qué CHECK de `$table` rechazan la fila `$row`, evaluando la
     * EXPRESIÓN REAL de cada uno (`pg_get_expr` de `pg_constraint`) sobre una
     * copia de las columnas sin restricciones (`LIKE`, en una tabla temporal
     * que se borra). Un CHECK solo rechaza FALSE: NULL cuenta como aceptado,
     * como en la base. Así cada caso se atribuye a su CHECK aunque viole varios
     * (PG solo nombra el primero por orden alfabético).
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    protected function violatedChecks(string $table, array $row): array
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

    // ── Catálogo del esquema ────────────────────────────────────────────────

    protected function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    protected function requirePostgres(string $porQue): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Solo PostgreSQL: '.$porQue);
        }
    }

    /** @return list<string> */
    protected function triggersOn(string $table): array
    {
        if ($this->isPostgres()) {
            return array_map(static fn (object $f): string => $f->tgname, DB::select(
                'SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = ?::regclass ORDER BY 1', [$table]));
        }

        return DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->orderBy('name')->pluck('name')->all();
    }

    /** @return list<string> */
    protected function checksOn(string $table): array
    {
        return array_map(static fn (object $f): string => $f->conname, DB::select(
            "SELECT conname FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass ORDER BY 1", [$table]));
    }

    /** @return array<string, string> índice parcial → predicado, en las tablas `voice_*` */
    protected function voicePartialIndexes(): array
    {
        if ($this->isPostgres()) {
            $out = [];
            foreach (DB::select("SELECT c.relname AS nombre, pg_get_expr(i.indpred, i.indrelid) AS predicado, i.indisunique::int AS unico
                    FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_class t ON t.oid = i.indrelid
                   WHERE t.relname LIKE 'voice\\_%' AND i.indpred IS NOT NULL ORDER BY 1") as $f) {
                $this->assertSame(1, (int) $f->unico, $f->nombre);
                $out[$f->nombre] = $f->predicado;
            }

            return $out;
        }
        $out = [];
        foreach (DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', 'like', 'voice_%')->orderBy('name')->get(['name', 'sql']) as $f) {
            if (is_string($f->sql) && str_contains($f->sql, ' WHERE ')) {
                $out[$f->name] = substr($f->sql, strpos($f->sql, ' WHERE ') + 7);
            }
        }

        return $out;
    }

    protected function voiceTriggerCount(): int
    {
        return $this->isPostgres()
            ? (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'voice\\_%'")
            : DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'like', 'voice_%')->count();
    }

    protected function voiceCheckCount(): int
    {
        return (int) DB::scalar("SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'voice\\_%'");
    }

    /** @return array<string, mixed> todo lo de las tablas de la voz, para comparar antes y después */
    protected function voiceFootprint(): array
    {
        if (! $this->isPostgres()) {
            return DB::table('sqlite_master')->where('tbl_name', 'like', 'voice_%')->orderBy('type')->orderBy('name')
                ->get(['type', 'name', 'sql'])->map(static fn (object $o): string => "{$o->type} {$o->name}: {$o->sql}")->all();
        }

        return [
            'tablas' => array_map(static fn (object $f): string => $f->tablename, DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'voice\\_%' ORDER BY 1")),
            'columnas' => array_map(static fn (object $f): string => "{$f->table_name}.{$f->column_name} {$f->data_type} {$f->is_nullable} {$f->column_default}", DB::select(
                "SELECT table_name, column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name LIKE 'voice\\_%' ORDER BY table_name, ordinal_position")),
            'indices' => array_map(static fn (object $f): string => $f->indexdef, DB::select("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename LIKE 'voice\\_%' ORDER BY indexname")),
            'restricciones' => array_map(static fn (object $f): string => "{$f->conname} {$f->def}", DB::select(
                "SELECT k.conname, pg_get_constraintdef(k.oid) AS def FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE c.relname LIKE 'voice\\_%' ORDER BY 1")),
            'disparadores' => array_map(static fn (object $f): string => $f->def, DB::select(
                "SELECT pg_get_triggerdef(t.oid) AS def FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'voice\\_%' ORDER BY t.tgname")),
            'funciones' => array_map(static fn (object $f): string => "{$f->proname} ".md5($f->def), DB::select(
                "SELECT proname, pg_get_functiondef(oid) AS def FROM pg_proc WHERE proname IN ('voice_forbid', 'voice_brake_release') ORDER BY 1")),
        ];
    }

    // ── El modelo dueño/app (solo PG) ───────────────────────────────────────

    /**
     * El modelo de producción dentro de un SAVEPOINT que SIEMPRE se deshace (el
     * rol de prueba incluido): el rol que migra concede por sus PRIVILEGIOS
     * POR DEFECTO `arwd` en tablas y `rwU` en secuencias a la aplicación, como
     * `iron` a `iron_app`; `iron_app` se ensaya con su variable de entorno.
     * down() + up() recrean las tablas con esos privilegios y el REVOKE.
     *
     * @param  Closure(Migration): void  $fn
     */
    protected function withAppRole(Closure $fn): void
    {
        DB::beginTransaction();
        try {
            DB::statement('DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = \''.self::APP_ROLE.'\') THEN CREATE ROLE '.self::APP_ROLE.' NOLOGIN NOSUPERUSER NOINHERIT; END IF; END $$');
            $rol = DB::selectOne('SELECT rolsuper::int AS su, rolbypassrls::int AS rls, rolinherit::int AS inh FROM pg_roles WHERE rolname = ?', [self::APP_ROLE]);
            $this->assertSame([0, 0, 0], [(int) $rol->su, (int) $rol->rls, (int) $rol->inh]);
            DB::statement('GRANT USAGE ON SCHEMA public TO '.self::APP_ROLE);
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO '.self::APP_ROLE);
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO '.self::APP_ROLE);
            putenv(self::APP_ROLE_ENV.'='.self::APP_ROLE);
            $fn($this->voiceMigration());
        } finally {
            putenv(self::APP_ROLE_ENV);
            DB::rollBack();
        }
    }

    /** @return list<bool> SELECT, INSERT, UPDATE (tabla o columna), DELETE y TRUNCATE del rol de prueba. */
    protected function privileges(string $table): array
    {
        $p = DB::selectOne("SELECT has_table_privilege(?, ?, 'SELECT')::int AS s, has_table_privilege(?, ?, 'INSERT')::int AS i,
                has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d, has_table_privilege(?, ?, 'TRUNCATE')::int AS t",
            array_merge(...array_fill(0, 5, [self::APP_ROLE, "public.{$table}"])));

        return [(int) $p->s === 1, (int) $p->i === 1, (int) $p->u === 1, (int) $p->d === 1, (int) $p->t === 1];
    }

    /**
     * Ejecuta `$fn` como el rol de prueba dentro de un SAVEPOINT que SIEMPRE se
     * deshace. Devuelve [SQLSTATE o null, mensaje].
     *
     * @return array{0:?string,1:string}
     */
    protected function asApp(Closure $fn): array
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
                $this->assertNotSame(self::APP_ROLE, DB::scalar('SELECT current_user'), 'el rol no se deshizo');

                return [null, 'deshecho'];
            }

            throw $e;
        }

        return [null, 'sin deshacer'];
    }
}
