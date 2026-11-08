<?php

namespace Tests\Feature\Telephony\Support;

use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\Voice\Dto\VoiceCanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\UltronStudio\IntegrationFixtures;
use Tests\Feature\UltronStudio\StudioFixtures;

/**
 * El mundo de las pruebas de la telefonía (B8-b): la configuración del fixture
 * de TEST8-a (`elevenlabs-webhook-bodies.json` §world y `policy-tables.json`
 * §keys: la `APP_KEY` de bytes 00..1f, el número dedicado, los secretos de
 * iniciación y de herramientas), una publicación de voz FIRMADA de verdad (la
 * firma única de `VoicePublishedConfig`), canarios por la API del H6 (cuatro
 * ojos) y filas de llamadas que respetan los disparadores de DB8.
 *
 * Ningún dato real; ninguna red (`Http::preventStrayRequests()` en
 * {@see voiceWorld()}).
 */
trait VoiceFixtures
{
    use IntegrationFixtures;
    use StudioFixtures;

    protected const APP_KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    protected const VOICE_FROM = '+576010000090';

    protected const CANARY_PHONE = '+573000000031';

    protected const STRANGER_PHONE = '+573000000077';

    protected const INITIATION_SECRET = 'h8fixture-initiation-0000000000000000000001';

    protected const TOOL_SECRET = 'h8fixture-tool-bearer-0000000000000000000001';

    protected const WEBHOOK_SECRET = 'whsec_h8fixture_postcall_0000000000000001';

    protected const PHONE_NUMBER_ID = 'phnum_h8fx_0001';

    protected const SEALED_AGENT = 'agent_h8fx_canary_0003';

    protected const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private int $voiceSeq = 0;

    /** La configuración de las pruebas: las piezas del fixture, todo lo que enciende algo, apagado salvo lo que pida la prueba. */
    protected function voiceWorld(array $extra = []): void
    {
        Http::preventStrayRequests();
        config(array_merge([
            'app.key' => self::APP_KEY,
            'voice.twilio.from' => self::VOICE_FROM,
            'voice.elevenlabs.initiation_secret' => self::INITIATION_SECRET,
            'voice.elevenlabs.tool_secret' => self::TOOL_SECRET,
            'voice.elevenlabs.webhook_secret' => self::WEBHOOK_SECRET,
            'voice.elevenlabs.agent_phone_number_id' => self::PHONE_NUMBER_ID,
            'voice.inbound.enabled' => true,
            'voice.inbound.daily_cap' => 3,
            'voice.limits.max_concurrent_calls' => 1,
            'ultron_studio.integrations.writes_enabled' => true,
        ], $extra));
    }

    /** Las cabeceras de la iniciación con el secreto bueno. @return array<string, string> */
    protected function initiationHeaders(?string $secret = self::INITIATION_SECRET): array
    {
        return array_filter(['X-Voice-Initiation-Secret' => $secret, 'Content-Type' => 'application/json', 'Accept' => 'application/json'], static fn ($v) => $v !== null);
    }

    /** La firma de ElevenLabs de un cuerpo crudo: `t=…,v0=hex(HMAC-SHA256(t.cuerpo))`. */
    protected function elevenSignature(string $body, ?int $t = null, string $secret = self::WEBHOOK_SECRET): string
    {
        $t ??= now()->getTimestamp();

        return 't='.$t.',v0='.hash_hmac('sha256', $t.'.'.$body, $secret);
    }

    /** Un POST con el cuerpo CRUDO tal cual (los webhooks firman los bytes). @param  array<string, string>  $headers */
    protected function rawPost(string $uri, string $body, array $headers = []): TestResponse
    {
        $servidor = ['CONTENT_TYPE' => $headers['Content-Type'] ?? 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $nombre => $valor) {
            if (in_array($nombre, ['Content-Type', 'Accept'], true)) {
                continue;
            }
            $servidor['HTTP_'.strtoupper(str_replace('-', '_', $nombre))] = $valor;
        }

        return $this->call('POST', $uri, [], [], [], $servidor, $body);
    }

    // ── La cadena de voz ────────────────────────────────────────────────────

    /**
     * Una versión aprobada, un perfil aprobado por otra persona, un agente de
     * publicación desplegado con `$providerAgentId` y su entrada FIRMADA en la
     * cadena para `$part` (encadenada a la cabeza actual).
     *
     * @return array{seq: int, agentRowId: int, projectionHash: string, payloadHash: string, specHash: string, versionUuid: string}
     */
    protected function publishVoice(string $providerAgentId = self::SEALED_AGENT, string $part = VoicePublishedConfig::PART_CANARY, array $agent = []): array
    {
        $n = ++$this->voiceSeq;
        $versionUuid = (string) Str::uuid();
        $version = (int) DB::table('ultron_studio_versions')->insertGetId([
            'uuid' => $versionUuid, 'client_request_id' => (string) Str::uuid(), 'label' => "Versión de voz {$n}", 'status' => 'APPROVED',
            'content' => '{}', 'content_hash' => str_repeat('a', 64), 'catalog_version' => '2026-10-19.1', 'catalog_hash' => str_repeat('b', 64),
            'source_draft_revision' => 1, 'created_by_admin_id' => 3, 'created_by_name' => 'Caro', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $perfilUuid = (string) Str::uuid();
        $perfil = (int) DB::table('ultron_studio_voice_profiles')->insertGetId([
            'uuid' => $perfilUuid, 'client_request_id' => (string) Str::uuid(), 'revision' => 1, 'based_on_profile_id' => null, 'label' => "Perfil {$n}",
            'language' => 'es', 'max_duration_seconds' => 300, 'retention_days' => 30, 'record_calls' => false, 'record_voice' => false,
            'transfer_count' => 0, 'transfer_destinations_encrypted' => null,
            'content' => '{"voiceId":"voz_prueba","slots":{"saludo":"Hola"},"tools":["consultar_planes"],"transfers":[]}',
            'content_hash' => hash('sha256', "perfil-{$n}"), 'status' => 'draft', 'created_by_admin_id' => 1, 'created_by_name' => 'Ana',
            'created_by_role' => 'Super Admin', 'created_at' => now(), 'status_changed_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ultron_studio_voice_profiles')->where('id', $perfil)->update(['status' => 'submitted', 'submitted_by_admin_id' => 1, 'submitted_by_name' => 'Ana', 'submitted_at' => now(), 'status_changed_at' => now()]);
        DB::table('ultron_studio_voice_profiles')->where('id', $perfil)->update(['status' => 'approved', 'reviewed_by_admin_id' => 2, 'reviewed_by_name' => 'Beto', 'reviewed_at' => now(), 'status_changed_at' => now()]);

        $proyeccion = $agent['projection_hash'] ?? hash('sha256', "proyeccion-{$n}");
        $carga = $agent['payload_hash'] ?? hash('sha256', "carga-{$n}");
        $spec = $agent['spec_hash'] ?? hash('sha256', "spec-{$n}");
        $agente = (int) DB::table('ultron_studio_voice_agents')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'purpose' => 'publication', 'studio_version_id' => $version,
            'studio_content_hash' => str_repeat('a', 64), 'voice_profile_id' => $perfil, 'voice_profile_hash' => hash('sha256', "perfil-{$n}"),
            'compiler_version' => 'ultron-voice-compiler/1', 'spec_hash' => $spec, 'payload_hash' => $carga, 'projection_hash' => $proyeccion,
            'provider' => 'elevenlabs', 'provider_agent_id' => null, 'observed_hash' => null, 'observed_at' => null, 'status' => 'pending',
            'failure_code' => null, 'serves_canary' => false, 'serves_rest' => false, 'created_by_admin_id' => 2, 'created_by_name' => 'Beto',
            'created_by_role' => 'Super Admin', 'created_at' => now(), 'status_changed_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ultron_studio_voice_agents')->where('id', $agente)->update([
            'status' => 'deployed', 'provider_agent_id' => $providerAgentId, 'observed_hash' => $proyeccion, 'observed_at' => now(), 'status_changed_at' => now(),
        ]);

        $cabeza = DB::table('ultron_studio_voice_publications')->orderByDesc('seq')->first(['id', 'seq', 'effective_hash']);
        $seq = $cabeza === null ? 1 : (int) $cabeza->seq + 1;
        $mensaje = VoicePublishedConfig::message([
            'actor_id' => 2, 'agent_config_hash' => $carga, 'prev_hash' => $cabeza === null ? self::GENESIS : (string) $cabeza->effective_hash,
            'profile_uuid' => $perfilUuid, 'provider_agent_id' => $providerAgentId, 'published_at' => '2026-11-04T15:00:00Z', 'scope' => $part,
            'seq' => $seq, 'spec_hash' => $spec, 'version_uuid' => $versionUuid,
        ]);
        DB::table('ultron_studio_voice_publications')->insert([
            'uuid' => (string) Str::uuid(), 'seq' => $seq, 'client_request_id' => (string) Str::uuid(), 'action' => 'publish', 'scope' => $part,
            'previous_publication_id' => $cabeza?->id, 'prev_hash' => $cabeza === null ? self::GENESIS : (string) $cabeza->effective_hash,
            'restores_publication_id' => null, 'agent_id' => $agente, 'effective_json' => $mensaje, 'effective_hash' => hash('sha256', $mensaje),
            'signature' => VoicePublishedConfig::signatureFor($mensaje), 'lab_gate' => '{"mode":"required"}', 'evidence' => null, 'reason' => null,
            'actor_admin_id' => 2, 'actor_name' => 'Beto', 'actor_role' => 'Super Admin', 'created_at' => now(),
        ]);

        return ['seq' => $seq, 'agentRowId' => $agente, 'projectionHash' => $proyeccion, 'payloadHash' => $carga, 'specHash' => $spec, 'versionUuid' => $versionUuid];
    }

    // ── Canarios (la API del H6) ────────────────────────────────────────────

    /** Un canario de VOZ activo (lo registra una persona y lo autoriza y activa otra). Devuelve su uuid. */
    protected function activeVoiceCanary(string $phone = self::CANARY_PHONE): string
    {
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Registro '.Str::random(4));
        $beto = $this->person(Admin::ROLE_SUPER_ADMIN, 'Beto Autoriza '.Str::random(4));

        return $this->activeCanary($ana, $beto, $phone, 'voice', 'Recepción de prueba');
    }

    // ── Llamadas ────────────────────────────────────────────────────────────

    /**
     * Una llamada en el estado pedido, por el camino que admiten los
     * disparadores de DB8: una entrante nace `active` o `denied`; una saliente
     * nace `requested` y avanza.
     *
     * @param  array<string, mixed>  $o
     */
    protected function voiceCall(string $direction, string $status, array $o = []): VoiceCall
    {
        $n = ++$this->voiceSeq;
        $remoto = $o['remote'] ?? self::CANARY_PHONE;
        unset($o['remote']);
        $sello = ['voice_publication_seq' => 1, 'part' => 'canary', 'stamp_state' => 'applied', 'studio_version_uuid' => (string) Str::uuid(),
            'studio_content_hash' => str_repeat('a', 64), 'voice_profile_hash' => hash('sha256', 'p'), 'spec_hash' => hash('sha256', 's'),
            'payload_hash' => hash('sha256', 'c'), 'compiler_version' => 'ultron-voice-compiler/1', 'provider' => 'elevenlabs', 'provider_agent_id' => self::SEALED_AGENT];
        $base = [
            'uuid' => (string) Str::uuid(), 'call_ref' => (string) Str::uuid(), 'direction' => $direction,
            'remote_hash' => $remoto === null ? null : VoicePhone::hash($remoto), 'remote_last4' => $remoto === null ? null : substr($remoto, -4),
            'attempt' => 1, 'budget_day' => now()->setTimezone('America/Bogota')->toDateString(), 'reserved_micros' => 610500, 'tool_calls' => 0,
            'status_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ] + $sello;

        if ($direction === 'inbound') {
            $autorizada = $status !== 'denied';
            $id = (int) DB::table('voice_calls')->insertGetId(array_merge($base, [
                'status' => $autorizada ? 'active' : 'denied', 'caller_authorized' => $autorizada, 'policy_reason' => $autorizada ? null : 'not_an_active_voice_canary',
                'provider_conversation_id' => $o['provider_conversation_id'] ?? "conv_test_{$n}", 'provider_call_sid' => $o['provider_call_sid'] ?? 'CA'.substr(hash('sha256', "sid{$n}"), 0, 32),
                'started_at' => $autorizada ? now() : null,
            ], array_diff_key($o, ['provider_conversation_id' => 1, 'provider_call_sid' => 1, 'tool_calls' => 1])));
            if (! in_array($status, ['active', 'denied'], true)) {
                DB::table('voice_calls')->where('id', $id)->update(['status' => $status, 'ended_at' => now(), 'status_changed_at' => now()]);
            }
            if (($o['tool_calls'] ?? 0) > 0) {
                DB::table('voice_calls')->where('id', $id)->update(['tool_calls' => $o['tool_calls']]);
            }

            return VoiceCall::query()->findOrFail($id);
        }

        $id = (int) DB::table('voice_calls')->insertGetId(array_merge($base, [
            'status' => 'requested', 'target_source' => 'canary_registry', 'canary_number_uuid' => $o['canary_number_uuid'] ?? (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(), 'requested_by_type' => 'admin', 'requested_by_admin_id' => $o['requested_by_admin_id'] ?? 1,
            'requested_by_name' => 'Ana', 'requested_by_role' => 'Super Admin',
        ], array_diff_key($o, ['provider_conversation_id' => 1, 'provider_call_sid' => 1, 'canary_number_uuid' => 1, 'requested_by_admin_id' => 1, 'tool_calls' => 1])));
        $ids = ['provider_conversation_id' => $o['provider_conversation_id'] ?? "conv_test_{$n}", 'provider_call_sid' => $o['provider_call_sid'] ?? 'CA'.substr(hash('sha256', "sid{$n}"), 0, 32)];
        $camino = match ($status) {
            'requested' => [],
            'ambiguous' => [['status' => 'ambiguous', 'failure_code' => 'connection']],
            'canceled' => [['status' => 'canceled', 'policy_reason' => 'outbound_disabled', 'ended_at' => now()]],
            'failed_to_start' => [['status' => 'failed_to_start', 'failure_code' => 'client_error', 'ended_at' => now()]],
            'dialing' => [['status' => 'dialing', 'started_at' => now()] + $ids],
            'active' => [['status' => 'dialing', 'started_at' => now()] + $ids, ['status' => 'active']],
            default => [['status' => 'dialing', 'started_at' => now()] + $ids, ['status' => $status, 'ended_at' => now()]],
        };
        foreach ($camino as $paso) {
            DB::table('voice_calls')->where('id', $id)->update($paso + ['status_changed_at' => now()]);
        }
        if (($o['tool_calls'] ?? 0) > 0) {
            DB::table('voice_calls')->where('id', $id)->update(['tool_calls' => $o['tool_calls']]);
        }

        return VoiceCall::query()->findOrFail($id);
    }

    /** El JSON canónico de una entrada (para comprobar lo que guarda la cadena). */
    protected function canonical(array $entry): string
    {
        return VoiceCanonicalJson::encode($entry);
    }
}
