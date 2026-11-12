<?php

namespace Tests\Feature\Telephony\Support;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\Voice\Dto\VoiceCanonicalJson;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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
        $this->ensureCompiledDestinationsColumn();
    }

    /**
     * E5-1: la columna de los destinos COMPILADOS en el agente
     * (`ultron_studio_voice_agents.transfer_destination_hashes`, json nullable).
     * La añaden DB8/S8-c en la migración `2026_11_10_100001`; mientras no esté,
     * la prueba la crea DENTRO de su transacción (se va con ella) para leerla
     * como la leerá producción. Cuando llegue la migración, esto no hace nada y
     * se quita.
     */
    protected function ensureCompiledDestinationsColumn(): void
    {
        if (! Schema::hasColumn('ultron_studio_voice_agents', 'transfer_destination_hashes')) {
            Schema::table('ultron_studio_voice_agents', static function (Blueprint $t): void {
                $t->json('transfer_destination_hashes')->nullable();
            });
        }
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
     * cadena para `$part` (encadenada a la cabeza actual). Con `$agent['row']`,
     * una VUELTA ATRÁS: la entrada apunta a ese agente, que ya existe.
     *
     * @param  array<string, mixed>  $agent  huellas que la prueba fija, `row` (vuelta atrás) o `transfer_destination_hashes` (la columna tal cual)
     * @param  list<array{0: string, 1: string}>  $transfers  los destinos compilados en el agente (alias, E.164), como los escribe S8 al desplegar (E5-1)
     * @param  array<string, mixed>|null  $evidence  la `evidence` de la entrada (PT-14: `{catalog: …}`, {@see voiceCatalogEvidence()}); null = anterior a PT-14
     * @return array{seq: int, agentRowId: int, projectionHash: string, payloadHash: string, specHash: string, versionUuid: string, profileUuid: string}
     */
    protected function publishVoice(string $providerAgentId = self::SEALED_AGENT, string $part = VoicePublishedConfig::PART_CANARY, array $agent = [],
        array $transfers = [], ?array $evidence = null): array
    {
        $n = ++$this->voiceSeq;
        if (isset($agent['row'])) {
            // Una VUELTA ATRÁS (D8-20 nivel 1): una entrada nueva que apunta a un agente que ya existe, con su versión y su perfil.
            $fila = DB::table('ultron_studio_voice_agents')->where('id', $agent['row'])->first();
            $agente = (int) $fila->id;
            $providerAgentId = (string) $fila->provider_agent_id;
            [$proyeccion, $carga, $spec] = [(string) $fila->projection_hash, (string) $fila->payload_hash, (string) $fila->spec_hash];
            $perfilUuid = (string) DB::table('ultron_studio_voice_profiles')->where('id', $fila->voice_profile_id)->value('uuid');
            $versionUuid = (string) DB::table('ultron_studio_versions')->where('id', $fila->studio_version_id)->value('uuid');
        } else {
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
            // E5-1: los destinos COMPILADOS en el agente, como los escribe S8 al desplegar: alias y huella, por alias, sin números.
            $destinos = array_map(static fn (array $d): array => ['alias' => $d[0], 'hash' => VoicePhone::hash($d[1])], $transfers);
            usort($destinos, static fn (array $a, array $b): int => strcmp($a['alias'], $b['alias']));
            $columna = array_key_exists('transfer_destination_hashes', $agent) ? $agent['transfer_destination_hashes'] : ($destinos === [] ? null : json_encode($destinos));

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
                'transfer_destination_hashes' => $columna,
            ]);
            DB::table('ultron_studio_voice_agents')->where('id', $agente)->update([
                'status' => 'deployed', 'provider_agent_id' => $providerAgentId, 'observed_hash' => $proyeccion, 'observed_at' => now(), 'status_changed_at' => now(),
            ]);
        }

        $cabeza = DB::table('ultron_studio_voice_publications')->orderByDesc('seq')->first(['id', 'seq', 'effective_hash']);
        $seq = $cabeza === null ? 1 : (int) $cabeza->seq + 1;
        $mensaje = VoicePublishedConfig::message([
            'actor_id' => 2, 'agent_config_hash' => $carga, 'prev_hash' => $cabeza === null ? self::GENESIS : (string) $cabeza->effective_hash,
            'profile_uuid' => $perfilUuid, 'provider_agent_id' => $providerAgentId, 'published_at' => '2026-11-04T15:00:00Z', 'scope' => $part,
            'seq' => $seq, 'spec_hash' => $spec, 'version_uuid' => $versionUuid,
        ]);
        DB::table('ultron_studio_voice_publications')->insert([
            // DB8 (`usvpub_shape_chk`): `publish` solo en la parte canaria; la de `rest` es una promoción.
            'uuid' => (string) Str::uuid(), 'seq' => $seq, 'client_request_id' => (string) Str::uuid(), 'action' => $part === 'rest' ? 'promote' : 'publish', 'scope' => $part,
            'previous_publication_id' => $cabeza?->id, 'prev_hash' => $cabeza === null ? self::GENESIS : (string) $cabeza->effective_hash,
            'restores_publication_id' => null, 'agent_id' => $agente, 'effective_json' => $mensaje, 'effective_hash' => hash('sha256', $mensaje),
            'signature' => VoicePublishedConfig::signatureFor($mensaje), 'lab_gate' => '{"mode":"required"}',
            'evidence' => $evidence === null ? null : json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'reason' => null,
            'actor_admin_id' => 2, 'actor_name' => 'Beto', 'actor_role' => 'Super Admin', 'created_at' => now(),
        ]);

        return ['seq' => $seq, 'agentRowId' => $agente, 'projectionHash' => $proyeccion, 'payloadHash' => $carga, 'specHash' => $spec, 'versionUuid' => $versionUuid, 'profileUuid' => $perfilUuid];
    }

    /**
     * PT-14 · B: la `evidence` de una publicación con su catálogo de planes
     * (`ultron-voice-catalog/1`), como la escribe el Studio al publicar: el
     * catálogo de AHORA (`Plan::commercialCatalogSnapshot()`) o la lista dada,
     * con su huella y sus autores (quien escribió el texto, `by`, y quien lo
     * puso a la venta, `soldBy`: B-1 de la revisión de security).
     *
     * @param  list<array{id: int, name: string, benefits: list<string>}>|null  $plans
     * @return array{catalog: array{format: string, hash: string, plans: list<array<string, mixed>>, authors: list<array{planId: int, by: int|null, soldBy: int|null}>}}
     */
    protected function voiceCatalogEvidence(?array $plans = null): array
    {
        $captura = Plan::commercialCatalogSnapshot();
        $planes = $plans ?? $captura['plans'];
        $autores = $plans === null ? $captura['authors'] : array_map(static fn (array $p): array => ['planId' => $p['id'], 'by' => null, 'soldBy' => null], $plans);

        return ['catalog' => ['format' => Plan::COMMERCIAL_CATALOG_FORMAT, 'hash' => Plan::commercialCatalogHash($planes), 'plans' => $planes, 'authors' => $autores]];
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
            // Un contacto (`target_source` = voice_contacts) lleva `canary_number_uuid` nulo explícito (DB8, `vc_target_chk`).
            'status' => 'requested', 'target_source' => 'canary_registry', 'canary_number_uuid' => array_key_exists('canary_number_uuid', $o) ? $o['canary_number_uuid'] : (string) Str::uuid(),
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

    // ── C-4: la puerta de salientes con las llamadas abiertas en el código ──

    /** La reserva de la puerta para un móvil colombiano (+573…) con los precios por defecto: 0,9605 US$ (runbook, paso 10). */
    protected const MOBILE_RESERVATION_MICROS = 960500;

    /**
     * C-4 (`voice(calls-open)`): la puerta ACEPTÓ (202) una saliente al canario
     * `$canario` (el número de `self::CANARY_PHONE`). Exactamente UNA fila nueva:
     * `requested`, sellada con la parte canaria vigente (su entrada y su
     * agente), con la huella y los 4 dígitos (nunca el número) y la reserva de
     * un móvil en el día de Bogotá, que cuenta como comprometida; su evento
     * `outbound_requested`, y el job en la cola `voice` para ESA fila. Devuelve
     * la fila.
     */
    protected function assertOutboundAccepted(TestResponse $r, string $canario, int $filasAntes, int $seq = 1, string $agente = self::SEALED_AGENT): VoiceCall
    {
        $r->assertStatus(202);
        $this->assertTrue($r->json('ok'));
        $this->assertSame($filasAntes + 1, VoiceCall::query()->count(), 'la puerta crea UNA fila');
        $fila = VoiceCall::query()->where('uuid', (string) $r->json('call.uuid'))->sole();
        $this->assertSame(['outbound', 'requested', 'canary_registry', $canario, null, null],
            [$fila->direction, $fila->status, $fila->target_source, $fila->canary_number_uuid, $fila->caller_authorized, $fila->policy_reason]);
        $this->assertSame(['applied', 'canary', $seq, $agente], [$fila->stamp_state, $fila->part, $fila->voice_publication_seq, $fila->provider_agent_id], 'el sello de la parte vigente');
        $this->assertSame([VoicePhone::hash(self::CANARY_PHONE), substr(self::CANARY_PHONE, -4)], [$fila->remote_hash, $fila->remote_last4]);
        $dia = now()->setTimezone('America/Bogota')->toDateString();
        $this->assertSame([self::MOBILE_RESERVATION_MICROS, $dia], [(int) $fila->reserved_micros, (string) $fila->budget_day], 'la reserva, en el día de Bogotá');
        $this->assertGreaterThanOrEqual(self::MOBILE_RESERVATION_MICROS, VoiceCostGuard::committedMicros($dia), 'la reserva cuenta como comprometida');
        $this->assertSame(['outbound_requested'], VoiceCallEvent::query()->where('call_id', $fila->id)->pluck('kind')->all());
        // UN job para ESA fila, y en el carril de la voz: dos jobs serían dos intentos de marcar a la misma persona
        // (revisión de `T`, I-1: una puerta que encolara dos veces dejaba en verde todas las aceptaciones).
        $this->assertCount(1, Queue::pushed(PlaceOutboundVoiceCall::class, static fn (PlaceOutboundVoiceCall $j): bool => $j->callId === (int) $fila->id
            && $j->connection === 'voice' && $j->queue === 'voice'), 'UN job para esa fila, en la cola `voice`');
        $this->assertCount(1, Queue::pushed(PlaceOutboundVoiceCall::class, static fn (PlaceOutboundVoiceCall $j): bool => $j->callId === (int) $fila->id), 'y ninguno más para ella en otra cola');
        $this->assertStringNotContainsString(substr(self::CANARY_PHONE, 1), (string) $r->getContent(), 'la respuesta no lleva el número');

        return $fila;
    }

    /**
     * La saliente aceptada, cancelada como la cancela el job sin petición: deja
     * de contar para la concurrencia, el cupo, la frecuencia y el presupuesto,
     * y la siguiente mirada de la puerta parte de cero.
     */
    protected function releaseAccepted(VoiceCall $llamada): void
    {
        $this->assertTrue(VoiceCall::transition((int) $llamada->id, [VoiceCall::STATUS_REQUESTED], VoiceCall::STATUS_CANCELED, ['policy_reason' => 'outbound_disabled', 'ended_at' => now()]));
    }

    /**
     * Una saliente en curso (`dialing` o `active`) con UN SOLO id del proveedor, el que el job guardó porque fue el
     * único que devolvió el proveedor (DB8, `vc_provider_binding_chk`: una saliente que marcó lleva al menos uno). Los
     * disparadores fijan cada id una sola vez, así que se escribe solo ese al marcar, y el cupo cuando ya está en curso.
     */
    protected function outboundWithOneProviderId(string $status, string $column, string $value, int $toolCalls = 0, array $o = []): VoiceCall
    {
        $id = (int) $this->voiceCall('outbound', 'requested', $o)->id;
        DB::table('voice_calls')->where('id', $id)->update(['status' => 'dialing', 'started_at' => now(), $column => $value, 'status_changed_at' => now()]);
        if ($status === 'active') {
            DB::table('voice_calls')->where('id', $id)->update(['status' => 'active', 'status_changed_at' => now()]);
        }
        if ($toolCalls > 0) {
            DB::table('voice_calls')->where('id', $id)->update(['tool_calls' => $toolCalls]);
        }

        return VoiceCall::query()->findOrFail($id);
    }

    /** El JSON canónico de una entrada (para comprobar lo que guarda la cadena). */
    protected function canonical(array $entry): string
    {
        return VoiceCanonicalJson::encode($entry);
    }
}
