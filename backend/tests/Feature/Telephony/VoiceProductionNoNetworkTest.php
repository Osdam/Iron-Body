<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Services\Voice\VoiceProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * CERO RED CON LA CONFIGURACIÓN REAL DE PRODUCCIÓN (contrato H8, Enmienda 2,
 * E2-4; Enmienda 1, E1-12): ElevenLabs configurado y encendido (la clave, el
 * agente de verificación, los secretos del webhook y de las herramientas;
 * estado `ELEVENLABS_UNVERIFIED`), así que la fábrica ya devuelve los
 * adaptadores REALES; Twilio Voice sin credenciales; todas las banderas de la
 * voz por defecto; `APP_ENV=production` (hosts oficiales). Ninguna ruta de la
 * telefonía, ningún comando `voice:*` ni el job sacan UNA sola petición: lo que
 * cierra la red ya no es la falta de clave, sino la constante, las banderas y
 * que no hay publicaciones (fase 1).
 *
 * `Http::fake()` sin stubs GRABA toda petición (y no sale): el recuento final
 * no es vacío por no grabar.
 */
class VoiceProductionNoNetworkTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private string $envAntes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        config([
            'app.key' => self::APP_KEY,
            'voice.elevenlabs.enabled' => true,
            'voice.elevenlabs.api_key' => 'clave-de-prueba-e1-12-'.str_repeat('k', 24),
            'voice.elevenlabs.agent_id' => 'agent_e112',
            'voice.elevenlabs.webhook_secret' => self::WEBHOOK_SECRET,
            'voice.elevenlabs.tool_secret' => self::TOOL_SECRET,
            'voice.elevenlabs.region' => 'default',
            'voice.elevenlabs.base_url' => null,
        ]);
        $this->envAntes = $this->app['env'];
        $this->app['env'] = 'production';
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->envAntes;
        parent::tearDown();
    }

    public function test_ninguna_ruta_comando_ni_job_de_la_telefonia_sale_a_la_red(): void
    {
        $this->assertTrue(app()->isProduction());
        $this->assertSame('ELEVENLABS_UNVERIFIED', VoiceProviders::status()->state, 'la configuración de E1-12');
        $this->assertFalse(config('voice.inbound.enabled'));
        $this->assertFalse(config('voice.outbound.enabled'));
        $this->assertFalse(config('voice.publishing.enabled'));
        $this->assertFalse(config('voice.lab.enabled'));

        // Las públicas, con credencial válida donde la hay (la de iniciación no está en producción).
        $cuerpo = '{"type":"post_call_transcription","event_timestamp":'.now()->getTimestamp().',"data":{"agent_id":"agent_x","conversation_id":"conv_prod_1","transcript":[]}}';
        $this->rawPost('/api/webhooks/voice/elevenlabs/post-call', $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo)])->assertOk();
        $this->rawPost('/api/webhooks/voice/elevenlabs/initiation', '{"call_sid":"CA'.str_repeat('0', 32).'"}', $this->initiationHeaders('x'))->assertStatus(401);
        $this->rawPost('/api/voice/tools/consultar_planes', '{"conversation_id":"conv_prod_2"}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])->assertOk()->assertJson(['verdict' => 'call_not_found']);
        $this->rawPost('/api/webhooks/voice/twilio/usage-trigger', 'AccountSid=AC1', ['Content-Type' => 'application/x-www-form-urlencoded', 'X-Twilio-Signature' => str_repeat('A', 27).'='])->assertStatus(401);

        // El CRM entero, como Super Admin.
        $root = $this->adminAs(Admin::ROLE_SUPER_ADMIN);
        $uuid = (string) Str::uuid();
        foreach ([['GET', 'status'], ['GET', 'calls'], ['GET', "calls/{$uuid}"], ['GET', 'metrics'], ['GET', 'contacts']] as [$m, $ruta]) {
            $this->assertLessThan(500, $this->json($m, '/api/admin/voice/'.$ruta, [], $root)->status(), $ruta);
        }
        $canario = $this->activeVoiceCanaryInProduction();
        $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $canario]], $root)->assertStatus(409);
        $contacto = $this->postJson('/api/admin/voice/contacts', ['clientRequestId' => (string) Str::uuid(), 'number' => '+57 300 000 0051', 'source' => ['type' => 'manual', 'id' => null]], $root)->assertCreated()->json('contact.uuid');
        $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'contact', 'uuid' => $contacto]], $root)->assertStatus(409);
        $this->postJson("/api/admin/voice/contacts/{$contacto}/consents", ['source' => 'written_form', 'evidence' => 'Formulario firmado'], $root)->assertStatus(202);

        // El job de una saliente que llegara a la cola: la constante la cancela sin red.
        $llamada = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000052']);
        (new PlaceOutboundVoiceCall((int) $llamada->id))->handle();
        $this->assertSame('canceled', VoiceCall::query()->findOrFail($llamada->id)->status);
        $viva = $this->voiceCall('outbound', 'dialing', ['remote' => '+573000000053']);
        $this->postJson("/api/admin/voice/calls/{$viva->uuid}/hangup", [], $root)->assertOk()->assertJson(['result' => 'not_configured']);

        // Los comandos.
        foreach ([['voice:doctor', []], ['voice:doctor', ['--telefonia' => true]], ['voice:doctor', ['--telefonia' => true, '--json' => true]],
            ['voice:reconcile', []], ['voice:reconcile', ['--json' => true]], ['voice:hangup', ['--all' => true, '--confirm' => true]]] as [$comando, $opciones]) {
            Artisan::call($comando, $opciones);
        }

        $this->assertCount(0, Http::recorded(), 'ninguna petición saliente con la configuración de producción');
        Queue::assertNothingPushed();
    }

    /** El registro de canarios del H6 con sus cuatro ojos (fuera de producción: la API del Studio no se abre con APP_ENV=production en la prueba). */
    private function activeVoiceCanaryInProduction(): string
    {
        $this->app['env'] = $this->envAntes;
        config(['ultron_studio.integrations.writes_enabled' => true]);
        $uuid = $this->activeVoiceCanary('+573000000050');
        config(['ultron_studio.integrations.writes_enabled' => false]);
        $this->app['env'] = 'production';

        return $uuid;
    }
}
