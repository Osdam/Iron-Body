<?php

namespace Tests\Feature\Outreach\V9\Support;

use App\Services\Outreach\Facts\SubjectRef;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoiceProviderState;
use App\Services\Telephony\VoicePublishedConfig;
use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;

/**
 * El mundo de las pruebas de V9 (la voz por estrategia): el del H9
 * (`RunWorld`: estrategias con su cadena FIRMADA de verdad, socios y
 * prospectos de `FactsWorld`, las filas de DB9) y una telefonía del H8 con
 * TODO en regla salvo las constantes de código (que en el árbol compartido
 * siguen cerradas).
 *
 * La telefonía no se compone con `VoiceFixtures` del H8: esa y
 * `OutreachSchemaFixtures` definen las dos `GENESIS` con distinta visibilidad
 * (PHP lo rechaza al componer). Lo de abajo es lo MÍNIMO de
 * `VoiceFixtures::voiceWorld()` y `::publishVoice()` (mismas claves de
 * fixture, la misma firma ÚNICA de `VoicePublishedConfig`), sin cambiarlos.
 *
 * Ningún dato real; ninguna red (la prueba llama a `Http::preventStrayRequests()`).
 */
trait V9World
{
    use RunWorld;

    protected const V9_APP_KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    protected const V9_VOICE_FROM = '+576010000090';

    protected const V9_PHONE_NUMBER_ID = 'phnum_h8fx_0001';

    protected const V9_CANARY_AGENT = 'agent_v9_canary_0001';

    protected const V9_REST_AGENT = 'agent_v9_rest_0001';

    protected const V9_GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** El número del contacto de voz de la persona de las pruebas (de ficción). */
    protected const V9_CONTACT_PHONE = '3001239001';

    private int $v9Seq = 0;

    /**
     * La configuración de la voz del H8 con TODO lo que la puerta pide
     * encendido (salientes, Twilio Voice, número, ElevenLabs, cupo, presupuesto
     * y la vigencia del RNE), en el alcance `contacts` (4b) salvo que la prueba
     * pida otro. La `APP_KEY` del fixture del H8 (las huellas y las firmas la
     * leen al calcular): se fija ANTES de crear filas firmadas o con huella.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function v9VoiceConfig(array $extra = []): void
    {
        config(array_merge([
            'app.key' => self::V9_APP_KEY,
            'voice.twilio.from' => self::V9_VOICE_FROM,
            'voice.twilio.enabled' => true,
            'voice.twilio.daily_call_cap' => 3,
            'voice.outbound.enabled' => true,
            'voice.outbound.scope' => 'contacts',
            'voice.limits.daily_budget_usd' => '5',
            'voice.limits.max_concurrent_calls' => 1,
            'voice.contacts.rne_validity_days' => 30,
            'voice.elevenlabs.enabled' => true,
            'voice.elevenlabs.api_key' => 'xi-centinela-V9-0123456789',
            'voice.elevenlabs.agent_id' => 'agent_verify_0001',
            'voice.elevenlabs.agent_phone_number_id' => self::V9_PHONE_NUMBER_ID,
            'voice.elevenlabs.webhook_secret' => 'whsec_v9fixture_postcall_0000000000000001',
            'voice.elevenlabs.initiation_secret' => 'v9fixture-initiation-0000000000000000000001',
            'voice.elevenlabs.tool_secret' => 'v9fixture-tool-bearer-0000000000000000000001',
            'voice.elevenlabs.base_url' => 'http://127.0.0.1:8096',
            'voice.elevenlabs.region' => 'default',
            'meta.whatsapp_display_phone' => null,
            'meta.protected_numbers' => [],
        ], $extra));
        if (! Schema::hasColumn('ultron_studio_voice_agents', 'transfer_destination_hashes')) {
            Schema::table('ultron_studio_voice_agents', static function (Blueprint $t): void {
                $t->json('transfer_destination_hashes')->nullable();
            });
        }
    }

    /**
     * La telefonía en regla: el proveedor verificado (la marca de E4-6), el
     * número vigilado hace nada (B8-g), las dos partes de la cadena de voz
     * publicadas y firmadas y la deriva ya comprobada (sin deriva).
     */
    protected function v9GreenVoice(): void
    {
        VoiceProviderState::recordStudioVerification(true, now());
        VoiceNumberGuard::rememberObservation(self::V9_PHONE_NUMBER_ID, true, false);
        $this->v9PublishVoice(self::V9_CANARY_AGENT, VoicePublishedConfig::PART_CANARY);
        $this->v9PublishVoice(self::V9_REST_AGENT, VoicePublishedConfig::PART_REST);
        foreach ([self::V9_CANARY_AGENT, self::V9_REST_AGENT] as $agente) {
            Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.$agente, false, OutboundGate::DRIFT_CACHE_SECONDS);
        }
    }

    /**
     * Una entrada FIRMADA de la cadena de voz para `$part`, con su versión,
     * perfil aprobado y agente desplegado (lo mínimo de
     * `VoiceFixtures::publishVoice()`).
     */
    protected function v9PublishVoice(string $providerAgentId, string $part): int
    {
        $n = ++$this->v9Seq;
        $versionUuid = (string) Str::uuid();
        $version = (int) DB::table('ultron_studio_versions')->insertGetId([
            'uuid' => $versionUuid, 'client_request_id' => (string) Str::uuid(), 'label' => "Versión de voz V9 {$n}", 'status' => 'APPROVED',
            'content' => '{}', 'content_hash' => str_repeat('a', 64), 'catalog_version' => '2026-10-19.1', 'catalog_hash' => str_repeat('b', 64),
            'source_draft_revision' => 1, 'created_by_admin_id' => 3, 'created_by_name' => 'Caro', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $perfilUuid = (string) Str::uuid();
        $perfil = (int) DB::table('ultron_studio_voice_profiles')->insertGetId([
            'uuid' => $perfilUuid, 'client_request_id' => (string) Str::uuid(), 'revision' => 1, 'based_on_profile_id' => null, 'label' => "Perfil V9 {$n}",
            'language' => 'es', 'max_duration_seconds' => 300, 'retention_days' => 30, 'record_calls' => false, 'record_voice' => false,
            'transfer_count' => 0, 'transfer_destinations_encrypted' => null,
            'content' => '{"voiceId":"voz_prueba","slots":{"saludo":"Hola"},"tools":["consultar_planes"],"transfers":[]}',
            'content_hash' => hash('sha256', "perfil-v9-{$n}"), 'status' => 'draft', 'created_by_admin_id' => 1, 'created_by_name' => 'Ana',
            'created_by_role' => 'Super Admin', 'created_at' => now(), 'status_changed_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ultron_studio_voice_profiles')->where('id', $perfil)->update(['status' => 'submitted', 'submitted_by_admin_id' => 1, 'submitted_by_name' => 'Ana', 'submitted_at' => now(), 'status_changed_at' => now()]);
        DB::table('ultron_studio_voice_profiles')->where('id', $perfil)->update(['status' => 'approved', 'reviewed_by_admin_id' => 2, 'reviewed_by_name' => 'Beto', 'reviewed_at' => now(), 'status_changed_at' => now()]);
        $proyeccion = hash('sha256', "proyeccion-v9-{$n}");
        $carga = hash('sha256', "carga-v9-{$n}");
        $spec = hash('sha256', "spec-v9-{$n}");
        $agente = (int) DB::table('ultron_studio_voice_agents')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'purpose' => 'publication', 'studio_version_id' => $version,
            'studio_content_hash' => str_repeat('a', 64), 'voice_profile_id' => $perfil, 'voice_profile_hash' => hash('sha256', "perfil-v9-{$n}"),
            'compiler_version' => 'ultron-voice-compiler/1', 'spec_hash' => $spec, 'payload_hash' => $carga, 'projection_hash' => $proyeccion,
            'provider' => 'elevenlabs', 'provider_agent_id' => null, 'observed_hash' => null, 'observed_at' => null, 'status' => 'pending',
            'failure_code' => null, 'serves_canary' => false, 'serves_rest' => false, 'created_by_admin_id' => 2, 'created_by_name' => 'Beto',
            'created_by_role' => 'Super Admin', 'created_at' => now(), 'status_changed_at' => now(), 'updated_at' => now(),
            'transfer_destination_hashes' => null,
        ]);
        DB::table('ultron_studio_voice_agents')->where('id', $agente)->update([
            'status' => 'deployed', 'provider_agent_id' => $providerAgentId, 'observed_hash' => $proyeccion, 'observed_at' => now(), 'status_changed_at' => now(),
        ]);
        $cabeza = DB::table('ultron_studio_voice_publications')->orderByDesc('seq')->first(['id', 'seq', 'effective_hash']);
        $seq = $cabeza === null ? 1 : (int) $cabeza->seq + 1;
        $mensaje = VoicePublishedConfig::message([
            'actor_id' => 2, 'agent_config_hash' => $carga, 'prev_hash' => $cabeza === null ? self::V9_GENESIS : (string) $cabeza->effective_hash,
            'profile_uuid' => $perfilUuid, 'provider_agent_id' => $providerAgentId, 'published_at' => '2026-11-04T15:00:00Z', 'scope' => $part,
            'seq' => $seq, 'spec_hash' => $spec, 'version_uuid' => $versionUuid,
        ]);
        DB::table('ultron_studio_voice_publications')->insert([
            'uuid' => (string) Str::uuid(), 'seq' => $seq, 'client_request_id' => (string) Str::uuid(), 'action' => $part === 'rest' ? 'promote' : 'publish', 'scope' => $part,
            'previous_publication_id' => $cabeza?->id, 'prev_hash' => $cabeza === null ? self::V9_GENESIS : (string) $cabeza->effective_hash,
            'restores_publication_id' => null, 'agent_id' => $agente, 'effective_json' => $mensaje, 'effective_hash' => hash('sha256', $mensaje),
            'signature' => VoicePublishedConfig::signatureFor($mensaje), 'lab_gate' => '{"mode":"required"}', 'evidence' => null, 'reason' => null,
            'actor_admin_id' => 2, 'actor_name' => 'Beto', 'actor_role' => 'Super Admin', 'created_at' => now(),
        ]);

        return $seq;
    }

    /**
     * Una entrada FALSIFICADA en la cabeza de la cadena de voz (la parte
     * `rest`, encadenada pero con una firma que no es la de la `APP_KEY`): la
     * lectura de la cadena falla cerrada (`publication_unreadable`).
     */
    protected function v9ForgeVoiceHead(): void
    {
        $cabeza = DB::table('ultron_studio_voice_publications')->orderByDesc('seq')->first(['id', 'seq', 'effective_hash', 'agent_id']);
        $mensaje = (string) json_encode(['contract' => 'ultron-voice-config/1', 'forjada' => true, 'seq' => (int) $cabeza->seq + 1]);
        DB::table('ultron_studio_voice_publications')->insert([
            'uuid' => (string) Str::uuid(), 'seq' => (int) $cabeza->seq + 1, 'client_request_id' => (string) Str::uuid(), 'action' => 'promote', 'scope' => 'rest',
            'previous_publication_id' => $cabeza->id, 'prev_hash' => (string) $cabeza->effective_hash, 'restores_publication_id' => null, 'agent_id' => $cabeza->agent_id,
            'effective_json' => $mensaje, 'effective_hash' => hash('sha256', $mensaje), 'signature' => str_repeat('f', 64), 'lab_gate' => '{"mode":"required"}',
            'evidence' => null, 'reason' => null, 'actor_admin_id' => 2, 'actor_name' => 'Beto', 'actor_role' => 'Super Admin', 'created_at' => now(),
        ]);
    }

    /** La plantilla de VOZ del H9 aprobada a cuatro ojos (Ana la crea, Beto la aprueba). */
    protected function v9VoiceTemplate(): int
    {
        $this->threePeople();

        return $this->approvedTemplate(['channel' => 'voice', 'content' => ['purposeKey' => 'renew']]);
    }

    /**
     * Una estrategia viva con un paso de voz (`voice_call`).
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    protected function v9VoiceStrategy(string $status = 'PUBLISHED', array $override = []): array
    {
        return $this->liveStrategy($this->validContent($this->v9VoiceTemplate(), $override), $status);
    }

    /**
     * Una persona socia que vence en 5 días y lo tiene TODO para la voz: su
     * prospecto de WhatsApp autorizado (las señales de baja en falso), su
     * contacto de voz ACTIVO con consentimiento comercial confirmado por otra
     * persona y una consulta al RNE vigente que no la lista; y, si se pide, su
     * número como canario de voz activo del H6.
     *
     * @return array{user: int, member: int, key: string, lead: int, contact: int, contactHash: string, contactUuid: string, e164: string}
     */
    protected function v9Member(bool $consent = true, bool $voiceCanary = true, string $phone = self::V9_CONTACT_PHONE): array
    {
        $r = $this->expiringMember(self::utcAt(now()->utc()->format('Y-m-d H:i:s')), 5);
        $lead = $this->lead(['member_id' => $r['member'], 'phone' => $phone, 'consent_status' => 'granted']);
        $this->conversation($lead);
        $e164 = '+57'.$phone;
        $contacto = $this->v9VoiceContact('member', $r['member'], $e164);
        if ($consent) {
            $this->voiceConsent($contacto['id'], 'granted', 2, now()->utc()->subDays(10)->format('Y-m-d H:i'), 1);
        }
        $this->rneCheck($contacto['hash'], now()->utc()->subDays(2)->format('Y-m-d H:i'));
        if ($voiceCanary) {
            $this->v9VoiceCanary($e164);
        }

        return $r + ['lead' => $lead, 'contact' => $contacto['id'], 'contactHash' => $contacto['hash'], 'contactUuid' => $contacto['uuid'], 'e164' => $e164];
    }

    /**
     * Un contacto de voz ACTIVO con el número CIFRADO como lo escribe la
     * telefonía (`encrypted` de Eloquent: `encryptString`, sin serializar; el
     * `encrypt()` de `FactsWorld` serializa y la puerta no lo leería como un
     * E.164). Los hechos del H9 no lo descifran nunca.
     *
     * @return array{id: int, hash: string, uuid: string}
     */
    protected function v9VoiceContact(string $sourceType, int $sourceId, string $e164): array
    {
        $huella = VoicePhone::hash($e164);
        $uuid = (string) Str::uuid();
        $id = (int) DB::table('voice_contacts')->insertGetId([
            'uuid' => $uuid, 'client_request_id' => (string) Str::uuid(), 'phone_hash' => $huella, 'phone_last4' => substr($e164, -4),
            'phone_encrypted' => Crypt::encryptString($e164), 'source_type' => $sourceType, 'source_id' => $sourceId, 'status' => 'active',
            'registered_by_admin_id' => 1, 'registered_by_name' => 'Ana', 'status_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'hash' => $huella, 'uuid' => $uuid];
    }

    /** Un canario de VOZ activo del H6 (registrado por Ana, autorizado y activado por Beto), con el número cifrado como lo escribe el modelo. */
    protected function v9VoiceCanary(string $e164): string
    {
        $uuid = (string) Str::uuid();
        DB::table('ultron_studio_canary_numbers')->insert([
            'uuid' => $uuid, 'client_request_id' => (string) Str::uuid(), 'channel' => 'voice', 'alias' => 'Canario de voz V9',
            'phone_hash' => PhoneFingerprint::of($e164), 'phone_last4' => substr($e164, -4), 'country_code' => 'CO',
            'phone_encrypted' => Crypt::encryptString($e164), 'status' => 'active', 'consent_attested' => true,
            'registered_by_admin_id' => 1, 'registered_by_name' => 'Ana', 'authorized_by_admin_id' => 2, 'authorized_by_name' => 'Beto',
            'authorized_at' => now(), 'activated_by_admin_id' => 2, 'activated_by_name' => 'Beto',
            'activated_at' => now(), 'status_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $uuid;
    }

    /**
     * Una inscripción VIVA de la persona en la estrategia y su trabajo de voz
     * con una unidad reservada, llevado hasta `$status` (`planned`, `claimed`
     * o `dispatching`).
     *
     * @param  array<string, mixed>  $live
     * @param  array{member: int, key: string}  $member
     * @param  array<string, mixed>  $job
     * @return array{enrollment: object, job: object}
     */
    protected function v9VoiceJob(array $live, array $member, string $status = 'dispatching', array $job = []): array
    {
        $plantilla = (int) DB::table('ultron_studio_message_templates')->where('channel', 'voice')->orderByDesc('id')->value('id');
        $inscripcion = $this->insertEnrollment($live, [
            'mode' => 'live', 'part' => $live['part'], 'publication_seq' => $live['seq'],
            'subject_key' => $member['key'], 'subject_ref' => SubjectRef::of($member['key']), 'member_id' => $member['member'],
            'enrolled_at' => now()->utc()->subHour()->format('Y-m-d H:i:s'), 'window_ends_at' => now()->utc()->addDays(10)->format('Y-m-d H:i:s'),
        ]);
        $dia = now()->setTimezone('America/Bogota')->toDateString();
        $id = $this->insertJob($inscripcion, array_merge([
            'channel' => VoiceStrategyChannel::NAME, 'template_version_id' => $plantilla,
            'template_hash' => (string) DB::table('ultron_studio_message_templates')->where('id', $plantilla)->value('content_hash'),
            'reserved_units' => 1, 'budget_day' => $dia,
            'not_before' => now()->utc()->subMinute()->format('Y-m-d H:i:s'), 'expires_at' => now()->utc()->addHours(3)->format('Y-m-d H:i:s'),
        ], $job));
        if (in_array($status, ['claimed', 'dispatching'], true)) {
            DB::table('outreach_jobs')->where('id', $id)->update(['status' => 'claimed', 'claimed_at' => now(), 'status_changed_at' => now()]);
        }
        if ($status === 'dispatching') {
            DB::table('outreach_jobs')->where('id', $id)->update(['status' => 'dispatching', 'status_changed_at' => now()]);
        }

        return ['enrollment' => DB::table('outreach_enrollments')->where('id', $inscripcion)->first(), 'job' => DB::table('outreach_jobs')->where('id', $id)->first()];
    }

    /**
     * Una saliente PEDIDA POR UNA ESTRATEGIA (la forma que escribe
     * `OutboundGate::requestForStrategy()`), llevada hasta `$status` por el
     * camino que admiten los disparadores de DB8 (como `VoiceFixtures::voiceCall()`):
     * nace `requested` y avanza. `$o` sobrescribe columnas de la fila.
     *
     * @param  array<string, mixed>  $o
     */
    protected function v9StrategyCall(string $jobUuid, int $contactId, string $e164, string $status, array $o = []): int
    {
        $n = ++$this->v9Seq;
        $id = (int) DB::table('voice_calls')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'call_ref' => (string) Str::uuid(), 'direction' => 'outbound', 'status' => 'requested',
            'target_source' => 'voice_contacts', 'canary_number_uuid' => null, 'voice_contact_id' => $contactId,
            'remote_hash' => VoicePhone::hash($e164), 'remote_last4' => substr($e164, -4),
            'client_request_id' => strtolower($jobUuid), 'requested_by_type' => 'strategy', 'requested_by_admin_id' => null,
            'requested_by_name' => 'Estrategia renovacion-voz', 'requested_by_role' => null, 'attempt' => 1,
            'voice_publication_seq' => 2, 'part' => 'rest', 'stamp_state' => 'applied', 'studio_version_uuid' => (string) Str::uuid(),
            'studio_content_hash' => str_repeat('a', 64), 'voice_profile_hash' => hash('sha256', 'p'), 'spec_hash' => hash('sha256', 's'),
            'payload_hash' => hash('sha256', 'c'), 'compiler_version' => 'ultron-voice-compiler/1', 'provider' => 'elevenlabs', 'provider_agent_id' => self::V9_REST_AGENT,
            'budget_day' => now()->setTimezone('America/Bogota')->toDateString(), 'reserved_micros' => 610500, 'tool_calls' => 0,
            'status_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], array_diff_key($o, ['provider_conversation_id' => 1, 'provider_call_sid' => 1, 'failure_code' => 1, 'policy_reason' => 1, 'duration_seconds' => 1,
            'provider_cost_micros' => 1, 'carrier_cost_micros' => 1])));
        $ids = ['provider_conversation_id' => $o['provider_conversation_id'] ?? "conv_v9_{$n}", 'provider_call_sid' => $o['provider_call_sid'] ?? 'CA'.substr(hash('sha256', "v9sid{$n}"), 0, 32)];
        $final = array_intersect_key($o, ['duration_seconds' => 1, 'provider_cost_micros' => 1, 'carrier_cost_micros' => 1]);
        $camino = match ($status) {
            'requested' => [],
            'ambiguous' => [['status' => 'ambiguous', 'failure_code' => $o['failure_code'] ?? 'connection']],
            'canceled' => [['status' => 'canceled', 'policy_reason' => $o['policy_reason'] ?? 'publication_moved', 'ended_at' => now()]],
            'failed_to_start' => [['status' => 'failed_to_start', 'failure_code' => $o['failure_code'] ?? 'client_error', 'ended_at' => now()]],
            'dialing' => [['status' => 'dialing', 'started_at' => now()] + $ids],
            'active' => [['status' => 'dialing', 'started_at' => now()] + $ids, ['status' => 'active']],
            default => [['status' => 'dialing', 'started_at' => now()] + $ids, ['status' => $status, 'ended_at' => now()]
                + ($status === 'failed' ? ['failure_code' => $o['failure_code'] ?? 'unknown'] : []) + $final],
        };
        foreach ($camino as $paso) {
            DB::table('voice_calls')->where('id', $id)->update($paso + ['status_changed_at' => now()]);
        }

        return $id;
    }

    /** Un evento de la llamada como lo deja la telefonía (solo códigos). @param  array<string, mixed>  $o */
    protected function v9CallEvent(int $callId, string $kind, array $o = []): void
    {
        DB::table('voice_call_events')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'call_id' => $callId, 'provider' => 'elevenlabs', 'kind' => $kind, 'provider_event_key' => $kind.':'.Str::random(12),
            'tool' => null, 'outcome' => null, 'codes' => null, 'actor_type' => 'provider', 'actor_admin_id' => null, 'actor_name' => null,
            'actor_role' => null, 'occurred_at' => null, 'created_at' => now(),
        ], $o));
    }

    /** Las filas de la voz del H8 (que nada del H9 puede crear salvo por la puerta). @return array<string, int> */
    protected function v9VoiceRows(): array
    {
        return [
            'voice_calls' => DB::table('voice_calls')->count(),
            'voice_call_events' => DB::table('voice_call_events')->count(),
        ];
    }

    /** La huella de un número del contacto (la de la telefonía). */
    protected static function v9Hash(string $e164): string
    {
        return VoicePhone::hash($e164);
    }
}
