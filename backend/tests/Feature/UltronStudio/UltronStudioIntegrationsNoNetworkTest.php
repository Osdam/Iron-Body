<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CERO RED POR DEFECTO (contrato H6 §I1 P2 y §I9.3). Con las llaves por defecto
 * y `Http::preventStrayRequests()`, ninguna de las 18 rutas del hito hace una
 * petición saliente: una que se escapara lanzaría `StrayRequestException` (un
 * 500). Con la sonda encendida, solo sus 5 pasos, solo GET y solo a los hosts
 * oficiales. Meta se lee del espejo local: el Studio no llama a la Marketing API.
 */
class UltronStudioIntegrationsNoNetworkTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        // Todo «configurado», para que ninguna ruta se quede antes por falta de algo.
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], '120000000000000387', 'Pauta');
        config([
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'AC0123456789abcdef0123456789abcdef',
            'otp.twilio.token' => 'token-de-la-prueba-sin-red',
            'otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'9f0e',
            'voice.elevenlabs.enabled' => true,
            'voice.elevenlabs.api_key' => 'sk_prueba_sin_red',
            'voice.elevenlabs.agent_id' => 'agent_sin_red',
            'voice.elevenlabs.webhook_secret' => 'wsec_prueba_sin_red',
        ]);
    }

    public function test_con_las_llaves_por_defecto_ninguna_de_las_18_rutas_sale_a_la_red(): void
    {
        $this->assertFalse(config('ultron_studio.integrations.writes_enabled'));
        $this->assertFalse(config('ultron_studio.integrations.twilio_probe.enabled'));
        // Con el falso puesto, toda petición queda REGISTRADA (y no sale): así el recuento final no es vacío por no grabar.
        Http::fake();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $uuid = (string) Str::uuid();

        $rutas = [
            ['GET', 'status', []], ['GET', 'meta/campaigns', []], ['GET', 'meta/links', []], ['GET', 'canaries', []],
            ['GET', 'twilio', []], ['GET', 'twilio/calls', []], ['GET', 'voice', []], ['GET', 'config', []],
            ['POST', 'meta/links', ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId('120000000000000387'), 'target' => ['kind' => 'baseline']]],
            ['POST', "meta/links/{$uuid}/deactivate", ['reason' => 'Motivo de la prueba']],
            ['POST', 'canaries', ['clientRequestId' => (string) Str::uuid(), 'channel' => 'whatsapp', 'phone' => '+573000000041', 'alias' => 'Móvil sin red', 'consentAttested' => true]],
            ['POST', "canaries/{$uuid}/authorize", []], ['POST', "canaries/{$uuid}/activate", []],
            ['POST', "canaries/{$uuid}/deactivate", ['reason' => 'Motivo de la prueba']], ['POST', "canaries/{$uuid}/revoke", ['reason' => 'Motivo de la prueba']],
            ['POST', "canaries/{$uuid}/expectation", ['versionUuid' => null]],
            ['POST', 'config/acknowledge', ['clientRequestId' => (string) Str::uuid(), 'provider' => 'voice', 'fingerprint' => str_repeat('0', 64)]],
            ['POST', 'twilio/probe', ['clientRequestId' => (string) Str::uuid()]],
        ];
        $this->assertCount(18, $rutas);
        foreach ($rutas as [$metodo, $ruta, $cuerpo]) {
            $r = $this->integrations($metodo, $ruta, $root, $cuerpo);
            $this->assertLessThan(500, $r->status(), "{$metodo} {$ruta}: ".$r->getContent());
        }
        $this->assertCount(0, Http::recorded(), 'ninguna petición saliente');
    }

    public function test_con_la_sonda_encendida_solo_sus_cinco_get_a_los_hosts_oficiales(): void
    {
        $this->integrationsFlags(writes: false, probe: true);
        Http::fake(fn (HttpRequest $r) => Http::response(['sid' => 'AC0123456789abcdef0123456789abcdef', 'status' => 'active', 'type' => 'Full'], 200));
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');

        foreach (['status', 'meta/campaigns', 'canaries', 'twilio', 'voice', 'config'] as $lectura) {
            $this->integrations('GET', $lectura, $root)->assertOk();
        }
        $this->assertCount(0, Http::recorded(), 'las lecturas no salen ni con la sonda encendida');

        $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        $salidas = array_map(static fn (array $p): string => $p[0]->method().' '.parse_url($p[0]->url(), PHP_URL_SCHEME).'://'.parse_url($p[0]->url(), PHP_URL_HOST), Http::recorded()->all());
        $this->assertSame([
            'GET https://api.twilio.com', 'GET https://api.twilio.com', 'GET https://api.twilio.com', 'GET https://api.twilio.com', 'GET https://monitor.twilio.com',
        ], $salidas);
    }
}
