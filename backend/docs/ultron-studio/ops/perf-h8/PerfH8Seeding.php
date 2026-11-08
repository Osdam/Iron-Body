<?php

namespace Tests\PerfH8;

use App\Models\Admin;
use App\Models\MarketingLead;
use App\Models\Plan;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceContacts;
use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoicePublishedConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PERF8 (Hito 8): la SIEMBRA A ESCALA del arnés de rendimiento de la voz.
 *
 * Volúmenes (pedidos por el lead): 5 000 llamadas, 200 contactos, 50 entradas
 * en la cadena de voz, 300 sesiones del laboratorio hablado y 20 000 eventos.
 * Todo por los caminos que admiten las CHECK y los disparadores de DB8:
 *  - una entrante nace `active` o `denied`; una saliente nace `requested` y
 *    avanza (requested → dialing → final); el cupo de herramientas solo crece
 *    con la llamada en curso; las columnas «una vez» se fijan una vez;
 *  - una saliente viva por número (`vc_one_live_outbound`): las salientes se
 *    siembran en tandas de números distintos y se cierran antes de la siguiente;
 *  - la cadena de voz va FIRMADA con la firma única de `VoicePublishedConfig`
 *    (la `APP_KEY` la fija `run-queries.sh`, la misma en la siembra y en la
 *    medida), encadenada por `prev_hash` = sha256 del mensaje anterior;
 *  - contactos, consentimientos (cuatro ojos), exclusiones y consultas al RNE
 *    por el servicio de la telefonía (`VoiceContacts`), y los frenos por
 *    `VoiceBrake::open/release` (todos sueltos: el freno queda SUELTO);
 *  - la versión del Studio y los perfiles reales (para `compile-preview` y
 *    `publish`) por la API del Studio, con su veredicto del laboratorio.
 *
 * Reparto temporal: 125 días × 40 llamadas (20 entrantes y 20 salientes), que
 * es el máximo de producción (cupo de salientes 0–20 y de entrantes 0–20 al
 * día). 25 agentes de publicación, uno cada 5 días: el canario (`seq` impar)
 * al empezar su periodo y el resto (`seq` par, promoción) dos días después.
 *
 * Ningún dato real: números de ficción, sin red.
 */
trait PerfH8Seeding
{
    protected const VOICE_FROM = '+576010000090';

    protected const INIT_SECRET = 'perf-h8-initiation-00000000000000000000000001';

    protected const TOOL_BEARER = 'perf-h8-tool-bearer-0000000000000000000000001';

    protected const WEBHOOK_SIG = 'whsec_perf_h8_postcall_00000000000000000001';

    protected const TWILIO_SIGNING = 'perfh8subaccountsigning000000001';

    protected const WEBHOOK_BASE = 'https://crm.ironbody.test';

    protected const PERF_NUMBER_ID = 'phnum_perf_h8_0001';

    protected const PERF_DESTINATION = '+576010000099';

    /** Los tres canarios de voz (ficción). */
    protected const CANARY_PHONES = ['+573000000031', '+573000000032', '+573000000033'];

    protected const DAYS = 125;

    protected const CALLS_PER_DAY = 40;

    protected const AGENTS = 25;

    protected const CONTACTS = 200;

    protected const LAB_SESSIONS = 300;

    protected const EVENTS_TARGET = 20000;

    /** Perfiles reales aprobados (cada uno con su veredicto) para medir `publish` sin repetir la huella. */
    protected const REAL_PROFILES = 25;

    /** Los nombres de las personas sembradas (la medida las busca por nombre). */
    protected const P_ANA = 'Ana Perf Telefonía';

    protected const P_BETO = 'Beto Perf Revisa';

    protected const P_LUIS = 'Luis Perf Administrador';

    protected const P_AUTORA = 'Autora Perf Voz';

    protected const P_ROOT = 'Root Perf Aprueba';

    protected const P_PABLO = 'Pablo Perf Publica';

    /** La configuración del mundo de la medida (la misma en la siembra y en cada familia). */
    protected function mundoPerf(array $extra = []): void
    {
        config(array_merge([
            'voice.twilio.from' => self::VOICE_FROM,
            'voice.elevenlabs.initiation_secret' => self::INIT_SECRET,
            'voice.elevenlabs.tool_secret' => self::TOOL_BEARER,
            'voice.elevenlabs.webhook_secret' => self::WEBHOOK_SIG,
            'voice.elevenlabs.agent_phone_number_id' => self::PERF_NUMBER_ID,
            'voice.twilio.signing_token' => self::TWILIO_SIGNING,
            'voice.webhook_base_url' => self::WEBHOOK_BASE,
            'voice.inbound.enabled' => true,
            // DESVIACIÓN DOCUMENTADA: producción recorta el cupo a 0–20 y la concurrencia a 1–4. Para repetir la
            // iniciación AUTORIZADA ≥ 20 veces el cupo se sube en memoria; la sentencia que lo cuenta y su plan no
            // dependen del valor (cada llamada medida se cierra fuera del cronómetro: la concurrencia viva queda en 3–4).
            'voice.inbound.daily_cap' => 1000000,
            'voice.limits.max_concurrent_calls' => 4,
            'voice.limits.per_call_max_seconds' => 300,
            // Presupuesto del día holgado (US$): lo comprometido de hoy (~40 llamadas) cabe y la conciliación no echa el freno por coste.
            'voice.limits.daily_budget_usd' => '500',
            'voice.contacts.rne_validity_days' => 90,
            'voice.facts.site' => 'Sede Neiva centro, calle de ficción 1',
            'voice.facts.hours' => 'De lunes a viernes de 5:00 a 22:00 y sábados de 6:00 a 14:00',
            'voice.transfer.staff_hours' => ['weekdays' => '00:00-23:59', 'saturday' => '00:00-23:59'],
            'ultron_studio.integrations.writes_enabled' => true,
            // El gobierno (S8): publicar encendido, sin ventana, con la puerta del laboratorio `required`.
            'ultron_studio.voice.publishing_enabled' => true,
            'ultron_studio.voice.lab_gate' => 'required',
            'ultron_studio.publishing.window' => '',
            'voice.elevenlabs.tool_secret_ref' => 'secref_tools_perf_h8',
            'voice.elevenlabs.initiation_secret_ref' => 'secref_init_perf_h8',
            'voice.elevenlabs.post_call_webhook_ref' => null,
            'voice.twilio.daily_call_cap' => 3,
        ], $extra));
    }

    protected function persona(string $nombre): Admin
    {
        return Admin::query()->where('name', $nombre)->firstOrFail();
    }

    /** @return array<string, int> recuentos por tabla tras sembrar */
    protected function sembrarTodo(): array
    {
        $t0 = hrtime(true);
        $this->importBaseline();
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, self::P_ANA);
        $beto = $this->person(Admin::ROLE_SUPER_ADMIN, self::P_BETO);
        $this->person(Admin::ROLE_ADMINISTRADOR, self::P_LUIS);
        $autora = $this->personWith([...self::VOICE_MANAGER, 'ultron.approve', 'ultron.edit_draft'], self::P_AUTORA);
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, self::P_ROOT);
        $this->personWith(self::VOICE_PUBLISHER, self::P_PABLO);

        $this->sembrarPlanes();
        $canarios = [];
        foreach (self::CANARY_PHONES as $i => $tel) {
            $canarios[] = ['uuid' => $this->activeCanary($ana, $beto, $tel, 'voice', 'Canario de voz '.($i + 1)), 'phone' => $tel];
        }
        $contactos = $this->sembrarContactos($ana, $beto);
        $agentes = $this->sembrarCadena($beto);
        $llamadas = $this->sembrarLlamadas($ana, $agentes, $canarios, $contactos);
        $this->sembrarEventos($ana, $llamadas);
        $this->sembrarFrenos($beto);
        $this->sembrarStudioReal($autora, $root);
        $this->sembrarLaboratorio($ana, $beto, $agentes, self::LAB_SESSIONS - self::REAL_PROFILES);

        $tablas = ['voice_calls', 'voice_call_events', 'voice_contacts', 'voice_consents', 'voice_exclusions', 'voice_rne_checks', 'voice_brakes',
            'ultron_studio_voice_publications', 'ultron_studio_voice_agents', 'ultron_studio_voice_profiles', 'ultron_studio_voice_lab_sessions',
            'ultron_studio_canary_numbers', 'ultron_studio_versions', 'plans', 'marketing_leads', 'cache', 'incidents'];
        $out = [];
        foreach ($tablas as $t) {
            $out[$t] = (int) DB::table($t)->count();
        }
        $out['_seconds'] = round((hrtime(true) - $t0) / 1e9, 1);

        return $out;
    }

    protected function sembrarPlanes(): void
    {
        $planes = [['Mensual', 89000, 30], ['Trimestral', 240000, 90], ['Semestral', 450000, 180], ['Anual', 820000, 365],
            ['Quincenal', 55000, 15], ['Día', 12000, 1], ['Estudiante mensual', 70000, 30], ['Pareja mensual', 160000, 30]];
        foreach ($planes as $i => [$nombre, $precio, $dias]) {
            Plan::create(['name' => $nombre, 'price' => $precio, 'duration_days' => $dias, 'active' => true, 'benefits' => json_encode(['Acceso completo'])]);
        }
        // Que sean vendibles (la columna puede no estar en `fillable`).
        DB::table('plans')->update(['sellable' => true]);
    }

    /**
     * 200 contactos (150 manuales y 50 de un lead), 170 con consentimiento de
     * cuatro ojos, 15 de ellos revocados, 20 excluidos y una consulta al RNE
     * cada uno (10 en el registro).
     *
     * @return list<array{id:int, uuid:string, hash:string, phone:string}>
     */
    protected function sembrarContactos(Admin $ana, Admin $beto): array
    {
        $svc = app(VoiceContacts::class);
        $out = [];
        for ($i = 0; $i < self::CONTACTS; $i++) {
            $tel = '+5730010'.str_pad((string) $i, 5, '0', STR_PAD_LEFT);
            $fuente = $i < 150 ? 'manual' : 'lead';
            $lead = null;
            if ($fuente === 'lead') {
                $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => $tel, 'name' => 'Prospecto perf '.$i]);
            }
            $r = $svc->register($ana, ['clientRequestId' => (string) Str::uuid(), 'number' => $tel, 'sourceType' => $fuente, 'sourceId' => $lead?->id]);
            $uuid = (string) $r['contact']['uuid'];
            if ($i < 170) {
                $svc->requestConsent($ana, $uuid, ['clientRequestId' => (string) Str::uuid(), 'source' => 'written_form', 'evidence' => 'Formulario firmado en recepción']);
                $svc->confirmConsent($beto, $uuid, (string) Str::uuid());
            }
            if ($i >= 155 && $i < 170) {
                $svc->revoke($ana, $uuid, ['clientRequestId' => (string) Str::uuid(), 'source' => 'written_form', 'evidence' => 'Pidió no recibir más llamadas']);
            }
            if ($i >= 170 && $i < 190) {
                $svc->exclude($ana, ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => $uuid, 'number' => null, 'note' => 'Exclusión manual de prueba']);
            }
            $svc->rneCheck($ana, ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => $uuid, 'checkedAt' => now()->subDays(10 + ($i % 30))->toDateTimeString(),
                'result' => $i % 20 === 7 ? 'listed' : 'not_listed', 'evidenceRef' => 'CRC-PERF-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
            $id = (int) DB::table('voice_contacts')->where('uuid', $uuid)->value('id');
            $out[] = ['id' => $id, 'uuid' => $uuid, 'hash' => VoicePhone::hash($tel), 'phone' => $tel];
        }

        return $out;
    }

    /**
     * La cadena de voz: 25 agentes de publicación (versión y perfil propios,
     * como `VoiceFixtures::publishVoice`), cada uno con su entrada `canary`
     * (publicar) y su entrada `rest` (promover): 50 entradas FIRMADAS.
     *
     * @return list<array<string, mixed>> por agente: id, providerAgentId, seqCanary, seqRest, desde y el sello
     */
    protected function sembrarCadena(Admin $actor): array
    {
        $inicio = CarbonImmutable::now()->startOfDay()->subDays(self::DAYS - 1);
        $destinos = json_encode([['alias' => 'Recepción', 'hash' => VoicePhone::hash(self::PERF_DESTINATION)]]);
        $agentes = [];
        for ($k = 0; $k < self::AGENTS; $k++) {
            $desde = $inicio->addDays(5 * $k)->addHours(13);
            $versionUuid = (string) Str::uuid();
            $version = (int) DB::table('ultron_studio_versions')->insertGetId([
                'uuid' => $versionUuid, 'client_request_id' => (string) Str::uuid(), 'label' => 'Versión de voz perf '.($k + 1), 'status' => 'APPROVED',
                'content' => '{}', 'content_hash' => hash('sha256', "perf-version-{$k}"), 'catalog_version' => '2026-10-19.1', 'catalog_hash' => str_repeat('b', 64),
                'source_draft_revision' => 1, 'created_by_admin_id' => $actor->id, 'created_by_name' => (string) $actor->name, 'created_at' => $desde, 'updated_at' => $desde,
            ]);
            $perfilUuid = (string) Str::uuid();
            $perfilHash = hash('sha256', "perf-perfil-{$k}");
            $perfil = (int) DB::table('ultron_studio_voice_profiles')->insertGetId([
                'uuid' => $perfilUuid, 'client_request_id' => (string) Str::uuid(), 'revision' => 1, 'based_on_profile_id' => null, 'label' => 'Perfil perf '.($k + 1),
                'language' => 'es', 'max_duration_seconds' => 300, 'retention_days' => 30, 'record_calls' => false, 'record_voice' => false,
                'transfer_count' => 0, 'transfer_destinations_encrypted' => null,
                'content' => '{"voiceId":"voz_perf","slots":{"saludo":"Hola"},"tools":["consultar_planes"],"transfers":[]}',
                'content_hash' => $perfilHash, 'status' => 'draft', 'created_by_admin_id' => $actor->id, 'created_by_name' => (string) $actor->name,
                'created_by_role' => 'Super Admin', 'created_at' => $desde, 'status_changed_at' => $desde, 'updated_at' => $desde,
            ]);
            DB::table('ultron_studio_voice_profiles')->where('id', $perfil)->update(['status' => 'submitted', 'submitted_by_admin_id' => $actor->id, 'submitted_by_name' => (string) $actor->name, 'submitted_at' => $desde, 'status_changed_at' => $desde]);
            DB::table('ultron_studio_voice_profiles')->where('id', $perfil)->update(['status' => 'approved', 'reviewed_by_admin_id' => 1, 'reviewed_by_name' => 'Revisión perf', 'reviewed_at' => $desde, 'status_changed_at' => $desde]);

            $proveedor = 'agent_perf_'.str_pad((string) ($k + 1), 4, '0', STR_PAD_LEFT);
            $proyeccion = hash('sha256', "perf-proyeccion-{$k}");
            $carga = hash('sha256', "perf-carga-{$k}");
            $spec = hash('sha256', "perf-spec-{$k}");
            $agente = (int) DB::table('ultron_studio_voice_agents')->insertGetId([
                'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'purpose' => 'publication', 'studio_version_id' => $version,
                'studio_content_hash' => hash('sha256', "perf-version-{$k}"), 'voice_profile_id' => $perfil, 'voice_profile_hash' => $perfilHash,
                'compiler_version' => 'ultron-voice-compiler/1', 'spec_hash' => $spec, 'payload_hash' => $carga, 'projection_hash' => $proyeccion,
                'provider' => 'elevenlabs', 'provider_agent_id' => null, 'observed_hash' => null, 'observed_at' => null, 'status' => 'pending',
                'failure_code' => null, 'serves_canary' => false, 'serves_rest' => false, 'created_by_admin_id' => $actor->id, 'created_by_name' => (string) $actor->name,
                'created_by_role' => 'Super Admin', 'created_at' => $desde, 'status_changed_at' => $desde, 'updated_at' => $desde,
                'transfer_destination_hashes' => $destinos,
            ]);
            DB::table('ultron_studio_voice_agents')->where('id', $agente)->update([
                'status' => 'deployed', 'provider_agent_id' => $proveedor, 'observed_hash' => $proyeccion, 'observed_at' => $desde, 'status_changed_at' => $desde,
            ]);

            $sello = [
                'studio_version_uuid' => $versionUuid, 'studio_content_hash' => hash('sha256', "perf-version-{$k}"), 'voice_profile_hash' => $perfilHash,
                'spec_hash' => $spec, 'payload_hash' => $carga, 'compiler_version' => 'ultron-voice-compiler/1', 'provider' => 'elevenlabs', 'provider_agent_id' => $proveedor,
            ];
            $seqC = $this->entradaDeVoz($actor, 'canary', $agente, $proveedor, $versionUuid, $perfilUuid, $spec, $carga, $desde);
            $seqR = $this->entradaDeVoz($actor, 'rest', $agente, $proveedor, $versionUuid, $perfilUuid, $spec, $carga, $desde->addDays(2));
            $agentes[] = ['id' => $agente, 'providerAgentId' => $proveedor, 'seqCanary' => $seqC, 'seqRest' => $seqR, 'desde' => $desde, 'sello' => $sello,
                'versionId' => $version, 'profileId' => $perfil, 'projectionHash' => $proyeccion];
        }

        return $agentes;
    }

    /** Una entrada FIRMADA de la cadena de voz, encadenada a la cabeza. Devuelve su `seq`. */
    protected function entradaDeVoz(Admin $actor, string $parte, int $agente, string $proveedor, string $versionUuid, string $perfilUuid, string $spec, string $carga, CarbonImmutable $cuando): int
    {
        $cabeza = DB::table('ultron_studio_voice_publications')->orderByDesc('seq')->first(['id', 'seq', 'effective_hash']);
        $seq = $cabeza === null ? 1 : (int) $cabeza->seq + 1;
        $prev = $cabeza === null ? VoicePublishedConfig::GENESIS_PREV_HASH : (string) $cabeza->effective_hash;
        $mensaje = VoicePublishedConfig::message([
            'actor_id' => (int) $actor->id, 'agent_config_hash' => $carga, 'prev_hash' => $prev, 'profile_uuid' => $perfilUuid,
            'provider_agent_id' => $proveedor, 'published_at' => $cuando->utc()->format('Y-m-d\TH:i:s\Z'), 'scope' => $parte,
            'seq' => $seq, 'spec_hash' => $spec, 'version_uuid' => $versionUuid,
        ]);
        DB::table('ultron_studio_voice_publications')->insert([
            'uuid' => (string) Str::uuid(), 'seq' => $seq, 'client_request_id' => (string) Str::uuid(), 'action' => $parte === 'rest' ? 'promote' : 'publish', 'scope' => $parte,
            'previous_publication_id' => $cabeza?->id, 'prev_hash' => $prev, 'restores_publication_id' => null, 'agent_id' => $agente,
            'effective_json' => $mensaje, 'effective_hash' => hash('sha256', $mensaje), 'signature' => VoicePublishedConfig::signatureFor($mensaje),
            'lab_gate' => '{"mode":"required"}', 'evidence' => $parte === 'rest' ? '{"calls":12,"guardViolations":0}' : null, 'reason' => null,
            'actor_admin_id' => $actor->id, 'actor_name' => (string) $actor->name, 'actor_role' => 'Super Admin', 'created_at' => $cuando,
        ]);

        return $seq;
    }

    /**
     * 5 000 llamadas en 125 días (20 entrantes y 20 salientes al día) más los
     * tramos de transferencia (3 % de las completadas), selladas con la entrada
     * vigente de su parte en su día. Hoy deja 1 entrante `active` y, de días
     * anteriores, 2 salientes `ambiguous` (vivas, como las que resuelve la
     * conciliación).
     *
     * @param  list<array<string, mixed>>  $agentes
     * @param  list<array{uuid:string, phone:string}>  $canarios
     * @param  list<array{id:int, uuid:string, hash:string, phone:string}>  $contactos
     * @return list<array{id:int, direction:string, status:string, created:CarbonImmutable, sid:?string, conv:?string, uuid:string, tools:int}>
     */
    protected function sembrarLlamadas(Admin $ana, array $agentes, array $canarios, array $contactos): array
    {
        $inicio = CarbonImmutable::now()->startOfDay()->subDays(self::DAYS - 1);
        $extranos = array_map(static fn (int $i): string => '+5731000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), range(0, 49));
        $n = 0;
        $todas = [];
        $base = static fn (CarbonImmutable $cuando, array $sello, int $seq, string $parte): array => [
            'uuid' => (string) Str::uuid(), 'call_ref' => (string) Str::uuid(), 'attempt' => 1,
            'budget_day' => $cuando->setTimezone('America/Bogota')->toDateString(), 'tool_calls' => 0,
            'status_changed_at' => $cuando, 'created_at' => $cuando, 'updated_at' => $cuando,
            'voice_publication_seq' => $seq, 'part' => $parte, 'stamp_state' => 'applied',
        ] + $sello;

        // ── Entrantes: insertadas en bloque (active / denied) y cerradas en bloque ──
        $entrantes = [];
        for ($d = 0; $d < self::DAYS; $d++) {
            $k = min(self::AGENTS - 1, intdiv($d, 5));
            $a = $agentes[$k];
            for ($j = 0; $j < 20; $j++) {
                $n++;
                $cuando = $inicio->addDays($d)->addMinutes(7 * 60 + 30 * $j + ($n % 17));
                $autorizada = $j % 5 !== 4;   // 16 de 20 autorizadas, 4 denegadas
                $tel = $autorizada ? $canarios[$n % 3]['phone'] : $extranos[$n % 50];
                $fila = $base($cuando, $a['sello'], (int) $a['seqCanary'], 'canary') + [
                    'direction' => 'inbound', 'status' => $autorizada ? 'active' : 'denied', 'target_source' => null,
                    'canary_number_uuid' => $autorizada ? $canarios[$n % 3]['uuid'] : null, 'voice_contact_id' => null,
                    'remote_hash' => VoicePhone::hash($tel), 'remote_last4' => substr($tel, -4),
                    'caller_authorized' => $autorizada, 'policy_reason' => $autorizada ? null : 'not_an_active_voice_canary',
                    'provider_conversation_id' => 'conv_perf_'.$n, 'provider_call_sid' => 'CA'.md5('perf-sid-'.$n),
                    'reserved_micros' => $autorizada ? 610500 : 18400, 'started_at' => $autorizada ? $cuando : null,
                ];
                $entrantes[] = $fila;
            }
        }
        foreach (array_chunk($entrantes, 400) as $tanda) {
            DB::table('voice_calls')->insert($tanda);
        }
        // Las autorizadas, a su final (todas menos la última de hoy, que queda `active`).
        $vivaDeHoy = (int) DB::table('voice_calls')->where('direction', 'inbound')->where('status', 'active')->max('id');
        $autorizadas = DB::table('voice_calls')->where('direction', 'inbound')->where('status', 'active')->where('id', '<>', $vivaDeHoy)->orderBy('id')->pluck('id')->all();
        foreach (array_chunk($autorizadas, 1000) as $ids) {
            $fallidas = array_values(array_filter($ids, static fn (int $id): bool => $id % 33 === 0));
            $colgadas = array_values(array_filter($ids, static fn (int $id): bool => $id % 33 === 1));
            $completas = array_values(array_diff($ids, $fallidas, $colgadas));
            DB::table('voice_calls')->whereIn('id', $completas)->update([
                'status' => 'completed', 'tool_calls' => DB::raw('(id % 4)'), 'duration_seconds' => DB::raw('(30 + id % 240)'),
                'ended_at' => DB::raw("created_at + ((30 + id % 240) || ' seconds')::interval"), 'status_changed_at' => DB::raw("created_at + ((30 + id % 240) || ' seconds')::interval"),
                'observed_agent_id' => DB::raw('provider_agent_id'), 'observed_version_id' => 'ver_perf_1', 'observed_branch_id' => 'branch_perf_1', 'seal_verified' => true,
                'provider_cost_micros' => DB::raw('(100000 + id % 400000)'), 'termination_reason' => 'end_call',
            ]);
            DB::table('voice_calls')->whereIn('id', $fallidas)->update(['status' => 'failed', 'failure_code' => 'unknown', 'ended_at' => DB::raw("created_at + interval '20 seconds'"), 'status_changed_at' => DB::raw("created_at + interval '20 seconds'")]);
            DB::table('voice_calls')->whereIn('id', $colgadas)->update(['status' => 'hung_up', 'ended_at' => DB::raw("created_at + interval '90 seconds'"), 'status_changed_at' => DB::raw("created_at + interval '90 seconds'")]);
        }

        // ── Salientes: por tandas de números DISTINTOS (una viva por número) ──
        $destinosSalientes = [];
        for ($d = 0; $d < self::DAYS; $d++) {
            $k = min(self::AGENTS - 1, intdiv($d, 5));
            for ($j = 0; $j < 20; $j++) {
                $n++;
                $cuando = $inicio->addDays($d)->addMinutes(9 * 60 + 25 * $j + ($n % 13));
                $aCanario = $j % 10 < 3;   // 6 a canarios, 14 a contactos
                if ($aCanario) {
                    $c = $canarios[$n % 3];
                    $a = $agentes[$k];
                    $seq = (int) $a['seqCanary'];
                    $parte = 'canary';
                    $destino = ['target_source' => 'canary_registry', 'canary_number_uuid' => $c['uuid'], 'voice_contact_id' => null, 'remote_hash' => VoicePhone::hash($c['phone']), 'remote_last4' => substr($c['phone'], -4)];
                } else {
                    $c = $contactos[$n % 155];   // los que tienen consentimiento vigente (0–154)
                    // El resto atiende con la promoción del periodo (dos días después de publicar) o con la anterior.
                    $enPeriodo = $d % 5 >= 2;
                    $a = $enPeriodo || $k === 0 ? $agentes[$k] : $agentes[$k - 1];
                    $seq = $enPeriodo || $k > 0 ? (int) $a['seqRest'] : (int) $a['seqCanary'];
                    $parte = $enPeriodo || $k > 0 ? 'rest' : 'canary';
                    $destino = ['target_source' => 'voice_contacts', 'canary_number_uuid' => null, 'voice_contact_id' => $c['id'], 'remote_hash' => $c['hash'], 'remote_last4' => substr($c['phone'], -4)];
                }
                $destinosSalientes[] = $base($cuando, $a['sello'], $seq, $parte) + $destino + [
                    'direction' => 'outbound', 'status' => 'requested', 'caller_authorized' => null, 'policy_reason' => null,
                    'client_request_id' => (string) Str::uuid(), 'requested_by_type' => 'admin', 'requested_by_admin_id' => $ana->id,
                    'requested_by_name' => (string) $ana->name, 'requested_by_role' => $ana->role, 'reserved_micros' => 742000,
                    '_n' => $n,
                ];
            }
        }
        // Tandas con huellas distintas: se reparten en orden y se abre una nueva al repetir número.
        $tanda = [];
        $vistas = [];
        $cerrar = function (array $tanda): void {
            if ($tanda === []) {
                return;
            }
            $filas = array_map(static function (array $f): array {
                unset($f['_n']);

                return $f;
            }, $tanda);
            DB::table('voice_calls')->insert($filas);
            $uuids = array_column($filas, 'uuid');
            $ids = DB::table('voice_calls')->whereIn('uuid', $uuids)->orderBy('id')->pluck('id')->all();
            $porDestino = ['canceled' => [], 'failed_to_start' => [], 'dialing' => []];
            foreach ($ids as $id) {
                $m = $id % 20;
                $porDestino[$m < 3 ? 'canceled' : ($m === 3 ? 'failed_to_start' : 'dialing')][] = $id;
            }
            DB::table('voice_calls')->whereIn('id', $porDestino['canceled'])->update(['status' => 'canceled', 'policy_reason' => 'outside_hours', 'ended_at' => DB::raw("created_at + interval '1 second'"), 'status_changed_at' => DB::raw("created_at + interval '1 second'")]);
            DB::table('voice_calls')->whereIn('id', $porDestino['failed_to_start'])->update(['status' => 'failed_to_start', 'failure_code' => 'client_error', 'ended_at' => DB::raw("created_at + interval '2 seconds'"), 'status_changed_at' => DB::raw("created_at + interval '2 seconds'")]);
            DB::table('voice_calls')->whereIn('id', $porDestino['dialing'])->update([
                'status' => 'dialing', 'started_at' => DB::raw("created_at + interval '2 seconds'"), 'status_changed_at' => DB::raw("created_at + interval '2 seconds'"),
                'provider_conversation_id' => DB::raw("'conv_perf_o' || id"), 'provider_call_sid' => DB::raw("'CA' || md5('perf-osid-' || id)"),
            ]);
            $marcadas = $porDestino['dialing'];
            $ocupado = array_values(array_filter($marcadas, static fn (int $id): bool => $id % 10 === 4));
            $sinRespuesta = array_values(array_filter($marcadas, static fn (int $id): bool => in_array($id % 10, [5, 6], true)));
            $fallo = array_values(array_filter($marcadas, static fn (int $id): bool => $id % 10 === 7 && $id % 3 === 0));
            $completa = array_values(array_diff($marcadas, $ocupado, $sinRespuesta, $fallo));
            foreach ([['busy', $ocupado], ['no_answer', $sinRespuesta], ['failed', $fallo]] as [$estado, $lista]) {
                DB::table('voice_calls')->whereIn('id', $lista)->update(['status' => $estado, 'failure_code' => $estado === 'failed' ? 'unknown' : $estado,
                    'ended_at' => DB::raw("created_at + interval '35 seconds'"), 'status_changed_at' => DB::raw("created_at + interval '35 seconds'")]);
            }
            DB::table('voice_calls')->whereIn('id', $completa)->update([
                'status' => 'completed', 'tool_calls' => DB::raw('(id % 5)'), 'duration_seconds' => DB::raw('(40 + id % 250)'),
                'ended_at' => DB::raw("created_at + ((42 + id % 250) || ' seconds')::interval"), 'status_changed_at' => DB::raw("created_at + ((42 + id % 250) || ' seconds')::interval"),
                'observed_agent_id' => DB::raw('provider_agent_id'), 'observed_version_id' => 'ver_perf_1', 'observed_branch_id' => 'branch_perf_1', 'seal_verified' => true,
                'provider_cost_micros' => DB::raw('(150000 + id % 500000)'), 'carrier_cost_micros' => DB::raw('(20000 + id % 90000)'), 'termination_reason' => 'end_call',
            ]);
        };
        foreach ($destinosSalientes as $f) {
            if (isset($vistas[$f['remote_hash']])) {
                $cerrar($tanda);
                $tanda = [];
                $vistas = [];
            }
            $vistas[$f['remote_hash']] = true;
            $tanda[] = $f;
        }
        $cerrar($tanda);

        // Dos salientes AMBIGUAS vivas (de días anteriores, a contactos distintos): las que resuelve la conciliación.
        $amb = 0;
        foreach (array_slice($contactos, 100, 2) as $i => $c) {
            $a = $agentes[self::AGENTS - 1];
            $cuando = CarbonImmutable::now()->subDays(2)->addMinutes($i);
            $fila = $base($cuando, $a['sello'], (int) $a['seqRest'], 'rest') + [
                'direction' => 'outbound', 'status' => 'requested', 'target_source' => 'voice_contacts', 'canary_number_uuid' => null, 'voice_contact_id' => $c['id'],
                'remote_hash' => $c['hash'], 'remote_last4' => substr($c['phone'], -4), 'caller_authorized' => null, 'policy_reason' => null,
                'client_request_id' => (string) Str::uuid(), 'requested_by_type' => 'admin', 'requested_by_admin_id' => $ana->id,
                'requested_by_name' => (string) $ana->name, 'requested_by_role' => $ana->role, 'reserved_micros' => 742000,
            ];
            $id = (int) DB::table('voice_calls')->insertGetId($fila);
            DB::table('voice_calls')->where('id', $id)->update(['status' => 'ambiguous', 'failure_code' => 'connection', 'status_changed_at' => $cuando->addSeconds(30)]);
            $amb++;
        }

        // Tramos de transferencia: el 3 % de las completadas (salientes `completed` nacidos así, sin sello propio).
        $madres = DB::table('voice_calls')->where('status', 'completed')->whereNull('transfer_of_call_id')->whereRaw('id % 33 = 5')->get(['id', 'created_at', 'budget_day']);
        $tramos = [];
        foreach ($madres as $m) {
            $cuando = CarbonImmutable::parse($m->created_at)->addSeconds(60);
            $tramos[] = [
                'uuid' => (string) Str::uuid(), 'call_ref' => (string) Str::uuid(), 'direction' => 'outbound', 'status' => 'completed', 'target_source' => 'transfer_destination',
                'canary_number_uuid' => null, 'voice_contact_id' => null, 'remote_hash' => VoicePhone::hash(self::PERF_DESTINATION), 'remote_last4' => '0099',
                'caller_authorized' => null, 'policy_reason' => null, 'client_request_id' => null, 'requested_by_type' => null, 'requested_by_admin_id' => null,
                'requested_by_name' => null, 'requested_by_role' => null, 'attempt' => 1, 'parent_call_id' => null, 'transfer_of_call_id' => (int) $m->id,
                'voice_publication_seq' => null, 'part' => null, 'stamp_state' => 'inherited', 'studio_version_uuid' => null, 'studio_content_hash' => null,
                'voice_profile_hash' => null, 'spec_hash' => null, 'payload_hash' => null, 'compiler_version' => null, 'provider' => 'elevenlabs', 'provider_agent_id' => null,
                'provider_conversation_id' => null, 'provider_call_sid' => 'CA'.md5('perf-leg-'.$m->id), 'budget_day' => $m->budget_day, 'reserved_micros' => 0,
                'tool_calls' => 0, 'duration_seconds' => 45, 'started_at' => $cuando, 'ended_at' => $cuando->addSeconds(45), 'status_changed_at' => $cuando->addSeconds(45),
                'created_at' => $cuando, 'updated_at' => $cuando,
            ];
        }
        foreach (array_chunk($tramos, 400) as $t) {
            DB::table('voice_calls')->insert($t);
        }

        foreach (DB::table('voice_calls')->orderBy('id')->get(['id', 'uuid', 'direction', 'status', 'created_at', 'provider_call_sid', 'provider_conversation_id', 'tool_calls', 'transfer_of_call_id', 'requested_by_admin_id']) as $f) {
            $todas[] = ['id' => (int) $f->id, 'uuid' => (string) $f->uuid, 'direction' => (string) $f->direction, 'status' => (string) $f->status,
                'created' => CarbonImmutable::parse($f->created_at), 'sid' => $f->provider_call_sid, 'conv' => $f->provider_conversation_id,
                'tools' => (int) $f->tool_calls, 'leg' => $f->transfer_of_call_id !== null, 'admin' => $f->requested_by_admin_id];
        }

        return $todas;
    }

    /**
     * Los eventos (D8-13) de cada llamada, como los dejarían la puerta, el job,
     * las herramientas, la post-llamada y la conciliación, hasta 20 000.
     *
     * @param  list<array<string, mixed>>  $llamadas
     */
    protected function sembrarEventos(Admin $ana, array $llamadas): void
    {
        $filas = [];
        $ev = static function (array $c, string $kind, string $provider, ?string $key, int $seg, array $extra = []) use (&$filas): void {
            $cuando = $c['created']->addSeconds($seg);
            $filas[] = array_merge([
                'uuid' => (string) Str::uuid(), 'call_id' => $c['id'], 'provider' => $provider, 'kind' => $kind, 'provider_event_key' => $key,
                'tool' => null, 'outcome' => null, 'codes' => null,
                'actor_type' => $provider === 'laravel' ? 'system' : 'provider', 'actor_admin_id' => null, 'actor_name' => null, 'actor_role' => null,
                'occurred_at' => $cuando, 'created_at' => $cuando,
            ], $extra);
        };
        $herramientas = ['consultar_planes', 'consultar_horarios', 'solicitar_humano', 'no_volver_a_llamar'];
        foreach ($llamadas as $c) {
            if ($c['leg']) {
                $ev($c, 'cost_recorded', 'twilio', 'leg:'.$c['sid'], 120, ['outcome' => 'transfer_leg', 'codes' => json_encode(['micros' => 30000, 'unit' => 'USD'])]);

                continue;
            }
            if ($c['direction'] === 'inbound') {
                $ev($c, 'initiation_received', 'elevenlabs', $c['sid'], 0, ['outcome' => $c['status'] === 'denied' ? 'denied' : 'authorized',
                    'codes' => json_encode(['reasons' => $c['status'] === 'denied' ? ['not_an_active_voice_canary'] : [], 'caller' => 'active_voice', 'publication' => 'ok'])]);
            } else {
                $ev($c, 'outbound_requested', 'laravel', 'req:'.$c['uuid'], 0, ['actor_type' => 'admin', 'actor_admin_id' => $ana->id, 'actor_name' => (string) $ana->name, 'actor_role' => $ana->role,
                    'codes' => json_encode(['scope' => 'canary', 'reservedMicros' => 742000])]);
                if ($c['conv'] !== null) {
                    $ev($c, 'outbound_started', 'elevenlabs', $c['conv'], 2, ['outcome' => 'started', 'codes' => json_encode(['http' => 200])]);
                }
            }
            for ($t = 0; $t < $c['tools']; $t++) {
                $ev($c, 'tool_call', 'elevenlabs', null, 20 + 10 * $t, ['tool' => $herramientas[($c['id'] + $t) % 4], 'outcome' => 'ok']);
            }
            if ($c['status'] === 'ambiguous') {
                // Lo que deja el job al no saber si salió: el evento con los ids que vinieran (aquí, ninguno).
                $ev($c, 'outbound_ambiguous', 'elevenlabs', 'amb:'.$c['uuid'], 30, ['outcome' => 'connection', 'codes' => json_encode(['reason' => 'connection', 'conversationId' => null, 'callSid' => null])]);
            }
            if (in_array($c['status'], ['busy', 'no_answer', 'failed'], true) && $c['direction'] === 'outbound') {
                $ev($c, 'call_initiation_failure', 'elevenlabs', $c['conv'] ?? 'sid:'.$c['sid'], 35, ['outcome' => $c['status'] === 'busy' ? 'busy' : ($c['status'] === 'no_answer' ? 'no_answer' : 'unknown')]);
            }
            if ($c['status'] === 'completed') {
                $ev($c, 'post_call_transcription', 'elevenlabs', $c['conv'], 300, ['outcome' => 'received', 'codes' => json_encode(['durationSeconds' => 120, 'agentTurns' => 9, 'toolCalls' => $c['tools']])]);
                $violacion = $c['id'] % 211 === 0;
                $ev($c, 'guard_verdict', 'elevenlabs', $c['conv'], 301, ['outcome' => $violacion ? 'violation' : 'clean',
                    'codes' => json_encode(['violations' => $violacion ? [['turn' => 3, 'code' => 'voice_money_not_from_tool']] : []])]);
                if ($c['id'] % 10 === 0 && $c['sid'] !== null) {
                    $ev($c, 'reconciled', 'twilio', $c['sid'].':completed', 900, ['outcome' => 'completed', 'codes' => json_encode(['carrierStatus' => 'completed', 'before' => 'completed', 'after' => 'completed'])]);
                    $ev($c, 'cost_recorded', 'twilio', 'cost:'.$c['sid'], 901, ['outcome' => 'carrier_price', 'codes' => json_encode(['micros' => 52000, 'unit' => 'USD'])]);
                }
            }
        }
        // Al objetivo exacto. Si sobran, se quitan eventos `tool_call` repartidos (sin clave ni restricciones), nunca los
        // de las llamadas recientes; si faltan, ruido sin llamada (avisos antiguos de la subcuenta).
        $exceso = count($filas) - self::EVENTS_TARGET;
        if ($exceso > 0) {
            $herr = array_keys(array_filter($filas, static fn (array $f): bool => $f['kind'] === 'tool_call'));
            $paso = max(1, intdiv(count($herr), $exceso));
            $fuera = [];
            for ($i = 0; $i < count($herr) && count($fuera) < $exceso; $i += $paso) {
                $fuera[$herr[$i]] = true;
            }
            $filas = array_values(array_diff_key($filas, $fuera));
        }
        $relleno = 0;
        while (count($filas) < self::EVENTS_TARGET) {
            $relleno++;
            $cuando = CarbonImmutable::now()->subDays(100)->addMinutes($relleno);
            $filas[] = ['uuid' => (string) Str::uuid(), 'call_id' => null, 'provider' => 'twilio', 'kind' => 'cost_recorded', 'provider_event_key' => 'usage-perf-'.$relleno,
                'tool' => null, 'outcome' => 'usage_alert', 'codes' => json_encode(['source' => 'usage_trigger']), 'actor_type' => 'provider', 'actor_admin_id' => null,
                'actor_name' => null, 'actor_role' => null, 'occurred_at' => $cuando, 'created_at' => $cuando];
        }
        foreach (array_chunk(array_slice($filas, 0, self::EVENTS_TARGET), 1000) as $t) {
            DB::table('voice_call_events')->insert($t);
        }
    }

    /** 8 aperturas históricas del freno, todas sueltas por una persona: el freno queda SUELTO. */
    protected function sembrarFrenos(Admin $beto): void
    {
        $causas = [['guard_violation', 'guard:perf-1'], ['guard_violation', 'guard:perf-2'], ['guard_violation', 'guard:perf-3'], ['seal_mismatch', 'seal:perf-1'],
            ['agent_drift', 'drift:perf-1'], ['number_drift', 'number:perf-1'], ['usage_alert', 'usage:perf-1'], ['usage_alert', 'usage:perf-2']];
        foreach ($causas as [$causa, $clave]) {
            $a = VoiceBrake::open($causa, $clave, ['where' => 'perf_seed']);
            VoiceBrake::release($beto, (string) $a->uuid, 'Revisado en la siembra de rendimiento', (string) Str::uuid());
        }
    }

    /**
     * La versión REAL del Studio y los perfiles reales aprobados por otra
     * persona (por la API), cada uno con su veredicto del laboratorio: lo que
     * `compile-preview` y `publish` necesitan para recorrer su camino entero.
     */
    protected function sembrarStudioReal(Admin $autora, Admin $root): void
    {
        $v = $this->approvedVersion($autora, $root, 'Versión de voz perf real');
        for ($i = 0; $i < self::REAL_PROFILES; $i++) {
            $contenido = $i % 2 === 0 ? self::profileContentWithTransfer() : self::profileContent();
            $contenido['holes']['closing'] = 'Gracias por tu tiempo, hasta pronto '.($i + 1).'.';
            $p = $this->approvedProfile($autora, $root, $contenido, 'Perfil perf real '.($i + 1));
            $this->labVerdictFor($v, $p, $autora, $root);
        }
    }

    /**
     * Las sesiones del laboratorio hablado (cada una con su agente de
     * laboratorio, E4-9), sobre las versiones y perfiles de la cadena, en los
     * últimos 60 días: 70 % aprobadas, 20 % rechazadas y 10 % sin veredicto.
     *
     * @param  list<array<string, mixed>>  $agentes
     */
    protected function sembrarLaboratorio(Admin $abre, Admin $juzga, array $agentes, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $a = $agentes[$i % count($agentes)];
            $cuando = CarbonImmutable::now()->subDays(60)->addMinutes(280 * $i);
            $agente = DB::table('ultron_studio_voice_agents')->insertGetId([
                'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'purpose' => 'lab',
                'studio_version_id' => $a['versionId'], 'studio_content_hash' => $a['sello']['studio_content_hash'],
                'voice_profile_id' => $a['profileId'], 'voice_profile_hash' => $a['sello']['voice_profile_hash'],
                'compiler_version' => 'ultron-voice-compiler/1', 'spec_hash' => hash('sha256', 'perf-lab-spec-'.($i % 40)), 'payload_hash' => hash('sha256', 'perf-lab-carga-'.$i),
                'projection_hash' => hash('sha256', 'perf-lab-proy-'.$i), 'provider' => 'elevenlabs', 'status' => 'pending', 'serves_canary' => false, 'serves_rest' => false,
                'created_by_admin_id' => $abre->id, 'created_by_name' => (string) $abre->name, 'created_by_role' => $abre->role,
                'created_at' => $cuando, 'status_changed_at' => $cuando, 'updated_at' => $cuando,
            ]);
            DB::table('ultron_studio_voice_agents')->where('id', $agente)->update(['status' => 'deployed', 'provider_agent_id' => 'lab_perf_'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'observed_hash' => hash('sha256', 'perf-lab-proy-'.$i), 'observed_at' => $cuando, 'status_changed_at' => $cuando]);
            $sesion = DB::table('ultron_studio_voice_lab_sessions')->insertGetId([
                'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'agent_id' => $agente, 'status' => 'open',
                'budget_day' => $cuando->setTimezone('America/Bogota')->toDateString(), 'reserved_micros' => 450000, 'max_seconds' => 300,
                'created_by_admin_id' => $abre->id, 'created_by_name' => (string) $abre->name, 'created_by_role' => $abre->role,
                'created_at' => $cuando, 'status_changed_at' => $cuando, 'updated_at' => $cuando,
            ]);
            DB::table('ultron_studio_voice_lab_sessions')->where('id', $sesion)->update(['status' => 'ended', 'ended_at' => $cuando->addMinutes(4), 'end_reason' => 'completed',
                'duration_seconds' => 120 + $i % 150, 'cost_micros' => 180000 + $i % 200000, 'status_changed_at' => $cuando->addMinutes(4)]);
            $m = $i % 10;
            if ($m < 9) {
                DB::table('ultron_studio_voice_lab_sessions')->where('id', $sesion)->update([
                    'verdict' => $m < 7 ? 'approved' : 'rejected', 'verdict_note' => $m < 7 ? null : 'No dice el aviso completo',
                    'verdict_by_admin_id' => $juzga->id, 'verdict_by_name' => (string) $juzga->name, 'verdict_by_role' => $juzga->role, 'verdict_at' => $cuando->addMinutes(30),
                ]);
            }
        }
    }

    /** La versión real y un perfil real aprobado (el `$i`-ésimo). @return array{0: StudioVersion, 1: StudioVoiceProfile} */
    protected function versionYPerfilReales(int $i = 0): array
    {
        $v = StudioVersion::query()->where('label', 'Versión de voz perf real')->firstOrFail();
        $p = StudioVoiceProfile::query()->where('label', 'Perfil perf real '.($i + 1))->firstOrFail();

        return [$v, $p];
    }
}
