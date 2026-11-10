<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Services\Telephony\VoiceTranscriptGuard;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\UltronStudio\StudioIntegrations;
use App\Services\UltronStudio\StudioVoiceTemplate;
use App\Services\Voice\ElevenLabs\ElevenLabsVoiceProvider;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La voz en las INTEGRACIONES del Studio con el Hito 8 (contrato H8, D8-21 e
 * «Impacto»; S8-b): la ruta 45 con el estado real, la fase que se deduce, el
 * freno y la publicación vigente (lo del H6, intacto); `twilio_voice:
 * connected_voice`; y los hechos de voz con la subcuenta, las referencias y las
 * huellas de los secretos nuevos, sin un secreto en claro y sin duplicar la
 * huella del secreto de iniciación.
 *
 * S8-c (E5-6, Q-1 y Q-2 de FE8): la ruta 45 dice además lo que puede la
 * sesión con las 7 llaves de D8-22 (`can`) y trae la plantilla vigente con los
 * topes del perfil (`template`), fijados contra los CHECK de la migración de
 * DB8 y contra lo que la API admite al crear un perfil.
 */
class UltronStudioVoiceIntegrationsTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use StudioVoiceFixtures;

    /** La migración del gobierno de la voz (DB8): sus CHECK son los topes del perfil. */
    private const VOICE_MIGRATION = '2026_11_10_100001_create_ultron_studio_voice_tables';

    private const SECRETS = [
        'voice.elevenlabs.api_key' => 'sk_h8s_CENTINELA_api_key_0001',
        'voice.elevenlabs.webhook_secret' => 'wsec_h8s_CENTINELA_webhook_0001',
        'voice.elevenlabs.tool_secret' => 'tsec_h8s_CENTINELA_tools_0001',
        'voice.elevenlabs.initiation_secret' => 'isec_h8s_CENTINELA_initiation_0001',
        'voice.twilio.api_key_secret' => 'tw_h8s_CENTINELA_key_secret_0001',
        'voice.twilio.signing_token' => 'tw_h8s_CENTINELA_signing_0001',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_la_ruta_45_suma_el_estado_real_la_fase_el_freno_y_la_publicacion_sin_tocar_lo_del_h6(): void
    {
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana');
        $voz = $this->integrations('GET', 'voice', $ana)->assertOk()->json('data');

        // Lo del H6, igual que siempre.
        $this->assertSame(['enabled' => false, 'fromConfigured' => false, 'dailyCallCap' => 0], $voz['twilioVoice']);
        $this->assertSame('calls_disabled_in_code', $voz['policy']['reasons'][0]);
        // Lo nuevo, todo apagado por defecto: fase 1, freno suelto, sin publicación.
        $this->assertSame(['id' => '1', 'basis' => 'config'], $voz['phase']);
        $this->assertSame(['id' => 'telephonyState', 'available' => true, 'providerState' => 'ELEVENLABS_NOT_CONFIGURED', 'subaccountReady' => false, 'numberImported' => false,
            'inbound' => ['enabled' => false, 'dailyCap' => 0], 'outbound' => ['enabled' => false, 'scope' => 'canary', 'dailyCap' => 0]], $voz['telephonyState']);
        $this->assertSame(['id' => 'brake', 'available' => true, 'state' => 'released'], $voz['brake']);
        $this->assertSame([false, 'voice_publishing_disabled'], [$voz['publishing']['enabled'], $voz['publishing']['code']]);
        $this->assertSame([null, null, null, true], [$voz['publication']['head'], $voz['publication']['canary'], $voz['publication']['rest'], $voz['publication']['verified']]);
    }

    public function test_la_ruta_45_dice_lo_que_puede_la_sesion_con_las_siete_llaves_de_d8_22(): void
    {
        // Las 7 filas de D8-22, en su orden, cada una con su nombre en el DTO.
        $this->assertSame(['ultron.voice_manage', 'ultron.approve', 'ultron.voice_publish', 'ultron.voice_lab_run', 'telephony.view', 'telephony.call_place', 'telephony.contacts_manage'],
            array_values(StudioIntegrations::VOICE_CAPABILITIES));
        $claves = array_keys(StudioIntegrations::VOICE_CAPABILITIES);

        // Super Admin: todas.
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->assertSame(array_fill_keys($claves, true), $this->integrations('GET', 'voice', $root)->assertOk()->json('data.can'));

        // Administrador (P-18): editar el perfil, ver el registro y aprobar como hoy; ni
        // publicar, ni el laboratorio hablado, ni llamar, ni los contactos.
        $admin = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Administradora');
        $comoAdmin = ['voiceManage' => true, 'approve' => true, 'voicePublish' => false, 'voiceLabRun' => false,
            'telephonyView' => true, 'telephonyCallPlace' => false, 'telephonyContactsManage' => false];
        $this->assertSame($comoAdmin, $this->integrations('GET', 'voice', $admin)->assertOk()->json('data.can'));
        // Es el reparto del catálogo, no una lista propia.
        $defectos = CrmPermission::defaultsFor(Admin::ROLE_ADMINISTRADOR);
        foreach (StudioIntegrations::VOICE_CAPABILITIES as $clave => $permiso) {
            $this->assertSame(in_array($permiso, $defectos, true), $comoAdmin[$clave], "{$clave} no es el valor por defecto del Administrador");
        }

        // Lo que dice la SESIÓN (su rol y la política guardada), no el nombre del rol.
        $llama = $this->personWith(['ultron.view', 'telephony.call_place'], 'Solo llama');
        $this->assertSame(['telephonyCallPlace'], array_keys(array_filter($this->integrations('GET', 'voice', $llama)->assertOk()->json('data.can'))));
        $ve = $this->personWith(['ultron.view'], 'Solo ve');
        $this->assertSame(array_fill_keys($claves, false), $this->integrations('GET', 'voice', $ve)->assertOk()->json('data.can'));
    }

    public function test_la_ruta_45_sin_sesion_401_con_el_token_compartido_403_y_sin_ultron_view_403(): void
    {
        $this->json('GET', '/api/admin/ultron-studio/integrations/voice')->assertStatus(401);
        $this->json('GET', '/api/admin/ultron-studio/integrations/voice', [], $this->sharedTokenHeaders())->assertStatus(403);
        // Las llaves de la telefonía no abren el Studio: la ruta 45 exige ver el Studio.
        $soloTelefonia = $this->personWith(['telephony.view', 'telephony.call_place'], 'Solo telefonía');
        $r = $this->integrations('GET', 'voice', $soloTelefonia)->assertStatus(403);
        $this->assertSame(['forbidden', 'ultron.view'], [$r->json('code'), $r->json('required_permission')]);
        $this->assertArrayNotHasKey('data', $r->json());
        // Y en el servicio, cada llave presupone ver el Studio (como `integrationCapabilities()`).
        $this->assertSame(array_fill_keys(array_keys(StudioIntegrations::VOICE_CAPABILITIES), false), StudioIntegrations::voiceCapabilities(new StudioAccess($soloTelefonia)));
    }

    public function test_la_ruta_45_trae_la_plantilla_vigente_y_los_topes_del_esquema_de_db8(): void
    {
        config(self::SECRETS + ['voice.limits.per_call_max_seconds' => 240]);
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $plantilla = $this->integrations('GET', 'voice', $root)->assertOk()->json('data.template');

        // Las constantes de la plantilla, tal cual (las herramientas del servidor con P-3 aplicado:
        // el catálogo que admite el compilador).
        $this->assertSame([
            'version' => StudioVoiceTemplate::VERSION,
            'hash' => StudioVoiceTemplate::hash(),
            'aiNotice' => StudioVoiceTemplate::AI_NOTICE,
            'aiNotices' => StudioVoiceTemplate::AI_NOTICES,
            'greetingMax' => StudioVoiceTemplate::GREETING_MAX,
            'textMin' => StudioVoiceTemplate::TEXT_MIN,
            'holes' => StudioVoiceTemplate::HOLES,
            'tones' => StudioVoiceTemplate::TONES,
            'goals' => StudioVoiceTemplate::GOALS,
            'fallbacks' => StudioVoiceTemplate::FALLBACKS,
            'serverTools' => StudioVoiceTemplate::serverTools(),
            'systemTools' => StudioVoiceTemplate::SYSTEM_TOOLS,
        ], array_diff_key($plantilla, ['limits' => true]));

        // Los topes, contra los CHECK de la migración de DB8 (no una copia a mano).
        $migracion = require database_path('migrations/'.self::VOICE_MIGRATION.'.php');
        $checks = $migracion::ROW_CHECKS[$migracion::PROFILES];
        $this->assertSame(1, preg_match('/^max_duration_seconds BETWEEN (\d+) AND (\d+)$/D', $checks['usvp_duration_chk'], $duracion), $checks['usvp_duration_chk']);
        $this->assertSame(1, preg_match('/^retention_days BETWEEN (\d+) AND (\d+)$/D', $checks['usvp_retention_chk'], $retencion), $checks['usvp_retention_chk']);
        $this->assertSame(1, preg_match('/^transfer_count BETWEEN (\d+) AND (\d+) AND /', $checks['usvp_transfer_chk'], $destinos), $checks['usvp_transfer_chk']);
        $this->assertSame([
            'maxDurationSeconds' => ['min' => (int) $duracion[1], 'max' => 240, 'ceiling' => (int) $duracion[2]],
            'retentionDays' => ['max' => (int) $retencion[2]],
            'transferDestinations' => ['max' => (int) $destinos[2]],
        ], $plantilla['limits']);

        // `max` es el tope del servidor (`VOICE_PER_CALL_MAX_SECONDS`, recortado a 60–600).
        foreach ([900 => 600, 30 => 60, 300 => 300] as $configurado => $tope) {
            config(['voice.limits.per_call_max_seconds' => $configurado]);
            $this->assertSame($tope, $this->integrations('GET', 'voice', $root)->json('data.template.limits.maxDurationSeconds.max'), "configurado {$configurado}");
        }

        // Y son lo que la API admite al crear un perfil: el tope pasa, uno más no.
        config(['voice.limits.per_call_max_seconds' => 240]);
        $this->createProfile($root, self::profileContent(['maxDurationSeconds' => 240]))->assertCreated();
        $this->createProfile($root, self::profileContent(['maxDurationSeconds' => 241]))->assertStatus(422)->assertJsonPath('reason', 'duration_exceeds_limit');
        $this->createProfile($root, self::profileContent(['privacy' => ['retentionDays' => $plantilla['limits']['retentionDays']['max'] + 1]]))
            ->assertStatus(422)->assertJsonValidationErrors(['content.privacy.retentionDays']);
        $conTransferencia = self::profileContentWithTransfer();
        $conTransferencia['transfer']['destinations'] = array_map(static fn (int $i): array => ['alias' => "Destino {$i}", 'phone' => '+57601000009'.$i], range(1, $plantilla['limits']['transferDestinations']['max'] + 1));
        $this->createProfile($root, $conTransferencia)->assertStatus(422)->assertJsonValidationErrors(['content.transfer.destinations']);

        // Los dos avisos (tú y usted) son los de la plantilla, y el de usted lleva los tres elementos que exige la guarda posterior.
        $this->assertSame(['tu' => StudioVoiceTemplate::AI_NOTICE, 'usted' => StudioVoiceTemplate::AI_NOTICE_USTED], $plantilla['aiNotices']);
        $this->assertTrue(VoiceTranscriptGuard::hasNotice($plantilla['aiNotices']['usted']));

        // La misma para cualquier sesión, y sin teléfonos ni secretos (la huella de la plantilla aparte: es hexadecimal).
        $this->assertSame($plantilla, $this->integrations('GET', 'voice', $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana'))->json('data.template'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plantilla['hash']);
        $json = (string) json_encode(array_diff_key($plantilla, ['hash' => true]), JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/\d{4,}|\+\d/', $json);
        foreach (self::SECRETS as $secreto) {
            $this->assertStringNotContainsString($secreto, $json);
        }
    }

    public function test_la_fase_que_se_deduce_de_la_configuracion(): void
    {
        $t = static fn (array $over): array => array_replace_recursive([
            'providerState' => 'ELEVENLABS_NOT_CONFIGURED', 'subaccountReady' => false, 'numberImported' => false,
            'inbound' => ['enabled' => false], 'outbound' => ['enabled' => false, 'scope' => 'canary'],
        ], $over);
        $this->assertSame('1', StudioIntegrations::voicePhase($t([]))['id']);
        $this->assertSame('1', StudioIntegrations::voicePhase($t(['providerState' => 'ELEVENLABS_VERIFIED', 'subaccountReady' => true]))['id'], 'sin número importado no es la 2');
        $this->assertSame('2', StudioIntegrations::voicePhase($t(['providerState' => 'ELEVENLABS_UNVERIFIED', 'subaccountReady' => true, 'numberImported' => true]))['id']);
        $this->assertSame('3', StudioIntegrations::voicePhase($t(['inbound' => ['enabled' => true]]))['id']);
        $this->assertSame('4a', StudioIntegrations::voicePhase($t(['inbound' => ['enabled' => true], 'outbound' => ['enabled' => true]]))['id']);
        $this->assertSame('4b', StudioIntegrations::voicePhase($t(['outbound' => ['enabled' => true, 'scope' => 'contacts']]))['id']);
    }

    public function test_twilio_voice_gana_connected_voice_con_la_subcuenta_completa(): void
    {
        $this->assertContains('connected_voice', StudioIntegrations::PROVIDER_CLASSIFICATIONS['twilio_voice']);
        // La regla del H6 sin el tercer argumento (la paridad del H6 sigue igual).
        $this->assertSame('degraded', StudioIntegrations::classifyTwilioVoice(true, true));
        $this->assertSame('connected_voice', StudioIntegrations::classifyTwilioVoice(true, true, true));
        $this->assertSame('disabled', StudioIntegrations::classifyTwilioVoice(true, false, true));
        $this->assertSame('not_configured', StudioIntegrations::classifyTwilioVoice(false, true, true));

        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana');
        config(['voice.twilio.enabled' => true, 'voice.twilio.from' => '+576010000090', 'voice.twilio.account_sid' => 'AC'.str_repeat('1', 32),
            'voice.twilio.api_key_sid' => 'SK'.str_repeat('2', 32), 'voice.twilio.api_key_secret' => self::SECRETS['voice.twilio.api_key_secret']]);
        $porId = collect($this->integrations('GET', 'status', $ana)->assertOk()->json('data.providers'))->keyBy('id');
        $this->assertSame('connected_voice', $porId['twilio_voice']['classification']);
        $this->assertSame(['enabled' => true, 'fromConfigured' => true], $porId['twilio_voice']['evidence'], 'la evidencia del H6 no cambia');
        $this->assertSame(0, Artisan::call('ultron-studio:integrations-doctor', ['--expect' => 'twilio_voice:connected_voice']));
    }

    public function test_los_hechos_de_voz_del_h8_con_huellas_y_referencias_sin_un_secreto(): void
    {
        config(self::SECRETS + [
            'voice.elevenlabs.tool_secret_ref' => 'secref_tools_h8s_ABCD',
            'voice.elevenlabs.initiation_secret_ref' => 'secref_init_h8s_EFGH',
            'voice.twilio.account_sid' => 'AC'.str_repeat('1', 28).'9876',
            'voice.twilio.api_key_sid' => 'SK'.str_repeat('2', 28).'5432',
            'voice.webhook_base_url' => 'https://CRM.IronBody.test/backend',
        ]);
        $hechos = StudioIntegrationConfig::voiceFacts();
        $clave = StudioIntegrationConfig::key();

        // La huella del secreto de iniciación es la de su único lector (no se duplica).
        $this->assertSame(['present' => true, 'fp' => ElevenLabsVoiceProvider::secretFingerprints($clave)['initiation_secret']], $hechos['elevenlabs']['initiationSecret']);
        $this->assertSame(['tool' => ['present' => true, 'ref' => 'ABCD'], 'initiation' => ['present' => true, 'ref' => 'EFGH'], 'postCallWebhook' => ['present' => false]], $hechos['elevenlabs']['secretRefs']);
        $sub = $hechos['twilioVoice']['subaccount'];
        $this->assertSame([['present' => true, 'ref' => 'AC…9876'], ['present' => true, 'ref' => 'SK…5432'], true, true, ['official' => true]],
            [$sub['accountSid'], $sub['apiKeySid'], $sub['apiKeySecret']['present'], $sub['signingToken']['present'], $sub['apiBase']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $sub['apiKeySecret']['fp']);
        $this->assertSame(['present' => true, 'host' => 'crm.ironbody.test'], $hechos['twilioVoice']['webhookBase']);
        $this->assertSame(['elevenlabs', 'twilioVoice'], array_keys($hechos), 'las claves de primer nivel del H6 no cambian');

        // Ningún secreto en claro, ni en los hechos ni en la ruta.
        $cuerpo = json_encode($hechos).$this->integrations('GET', 'config', $this->person(Admin::ROLE_SUPER_ADMIN, 'Root'))->assertOk()->getContent();
        foreach (self::SECRETS as $secreto) {
            $this->assertStringNotContainsString($secreto, $cuerpo);
        }

        // Sin `$h8`, los hechos del H6 tal cual (la exportación de fixtures y su paridad).
        $h6 = StudioIntegrationConfig::voiceFactsFrom(['api_key' => null, 'webhook_secret' => null, 'tool_secret' => null], false, null, 'default', null, false, null, 0);
        $this->assertArrayNotHasKey('initiationSecret', $h6['elevenlabs']);
        $this->assertArrayNotHasKey('subaccount', $h6['twilioVoice']);
    }

    public function test_sin_ninguna_pieza_del_h8_los_hechos_y_su_huella_son_los_del_h6(): void
    {
        // La producción de hoy (E1-12): ElevenLabs con su clave y sus dos secretos, nada del H8.
        config(['voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => self::SECRETS['voice.elevenlabs.api_key'],
            'voice.elevenlabs.agent_id' => 'agent_h8s_0001', 'voice.elevenlabs.webhook_secret' => self::SECRETS['voice.elevenlabs.webhook_secret'],
            'voice.elevenlabs.tool_secret' => self::SECRETS['voice.elevenlabs.tool_secret']]);
        $hechos = StudioIntegrationConfig::voiceFacts();
        $comoH6 = StudioIntegrationConfig::voiceFactsFrom(ElevenLabsVoiceProvider::secretFingerprints(StudioIntegrationConfig::key()),
            true, 'agent_h8s_0001', 'default', null, false, null, 0);
        $this->assertSame($comoH6, $hechos, 'desplegar el H8 no cambia la huella autorizada de voz (fase 1)');
        $this->assertSame(StudioIntegrationConfig::fingerprintOf($comoH6), StudioIntegrationConfig::fingerprintOf($hechos));

        // La primera pieza del H8 (fase 2) cambia los hechos: se reconocen otra vez.
        config(['voice.elevenlabs.initiation_secret' => self::SECRETS['voice.elevenlabs.initiation_secret']]);
        $this->assertNotSame(StudioIntegrationConfig::fingerprintOf($comoH6), StudioIntegrationConfig::fingerprintOf(StudioIntegrationConfig::voiceFacts()));
        $this->assertArrayHasKey('subaccount', StudioIntegrationConfig::voiceFacts()['twilioVoice']);
    }
}
