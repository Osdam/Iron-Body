<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\UltronStudio\StudioIntegrations;
use App\Services\Voice\ElevenLabs\ElevenLabsVoiceProvider;
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
 */
class UltronStudioVoiceIntegrationsTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

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
