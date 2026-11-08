<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioVoiceLabSession;
use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\UltronStudio\StudioVoiceLabAgents;
use App\Services\UltronStudio\StudioVoiceLabConfig;
use App\Services\UltronStudio\StudioVoiceLabSessions;
use App\Services\Voice\VoiceCheckResult;
use App\Services\Voice\VoiceProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CERO RED EN EL LABORATORIO HABLADO con las banderas por defecto (contrato
 * H8, protección 12, puerta 2 y Enmienda 2, E2-4), como
 * `UltronStudioIntegrationsNoNetworkTest`. Desde E1-12 producción tiene
 * ElevenLabs configurado y encendido: la fábrica de adaptadores de B8 da los
 * REALES, así que el cerrojo de la credencial ya no basta y el laboratorio se
 * cierra por su propia bandera (`VOICE_LAB_ENABLED`, apagada por defecto), su
 * driver (`fake` por defecto), su presupuesto (0 por defecto) y la
 * verificación de la credencial.
 *
 * Todas las peticiones quedan registradas en un `Http::fake()` que respondería
 * a cualquiera: al final, ninguna salió y nada se escribió.
 */
class UltronStudioVoiceLabNoNetworkTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use VoiceLabFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->voiceLabRoutes();
        $this->importBaseline();
        // Producción desde el 03-11 (E1-12), verificada.
        $this->elevenLabsLikeProduction();
        $this->voiceVerified();
    }

    public function test_los_defectos_dejan_el_laboratorio_cerrado(): void
    {
        $cfg = StudioVoiceLabConfig::fromValues([]);
        $this->assertFalse($cfg->enabled);
        $this->assertSame('fake', $cfg->mode());
        $this->assertSame([0, 0, null], [$cfg->dailyBudgetMicros, $cfg->sessionBudgetMicros, $cfg->pricePerMinuteMicros]);
        $this->assertSame([300, 5, 300], [$cfg->maxSessionSeconds, $cfg->maxSessionsPerDay, $cfg->perCallMaxSeconds]);
        $this->assertNull($cfg->webhookBaseUrl);

        // Lo que no es exactamente `true` no enciende; los topes duros se aplican aunque lleguen otros.
        foreach (['true', 1, '1', 'yes', 'on'] as $casi) {
            $this->assertFalse(StudioVoiceLabConfig::fromValues(['enabled' => $casi])->enabled, var_export($casi, true));
        }
        $topes = StudioVoiceLabConfig::fromValues(['max_session_seconds' => 3600, 'max_sessions_per_day' => 999, 'per_call_max_seconds' => 9999]);
        $this->assertSame([300, 50, 600], [$topes->maxSessionSeconds, $topes->maxSessionsPerDay, $topes->perCallMaxSeconds]);
        $this->assertSame(60, StudioVoiceLabConfig::fromValues(['max_session_seconds' => 5])->maxSessionSeconds);

        // Con ElevenLabs, sin precio ni presupuesto no hay nada que reservar: cerrado.
        $eleven = StudioVoiceLabConfig::fromValues(['enabled' => true, 'driver' => 'elevenlabs', 'price_micros_per_minute' => 0]);
        $this->assertSame(['credentials_unverified', 'missing_webhook_base', 'missing_secret_refs', 'missing_price', 'zero_budget'], $eleven->reasons('ELEVENLABS_UNVERIFIED'));
        $this->assertNull($eleven->reservationFor(300));

        // Sin `voice.lab.*` en la configuración, lo que lee el servidor también está cerrado.
        $this->assertFalse(StudioVoiceLabConfig::current()->enabled);
        $this->assertSame('ELEVENLABS_UNVERIFIED', VoiceProviders::status()->state, 'la configuración de la prueba es la de producción');
        Http::assertNothingSent();
    }

    public function test_con_elevenlabs_como_en_produccion_y_la_bandera_por_defecto_ningun_camino_sale_a_la_red(): void
    {
        $tina = $this->tester();
        $rita = $this->reviewer();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana)->uuid;
        $perfil = $this->seedProfile($ana, null, 'submitted');
        $uuid = (string) Str::uuid();

        $this->voiceLab('GET', 'status', $tina)->assertOk()->assertJsonPath('data.enabled', false);
        $this->voiceLab('GET', 'sessions', $tina)->assertStatus(409);
        $this->voiceLab('GET', "sessions/{$uuid}", $tina)->assertStatus(409);
        $this->openSession($tina, $version, $perfil)->assertStatus(409)->assertJsonPath('code', 'voice_lab_disabled');
        $this->voiceLab('POST', "sessions/{$uuid}/end", $tina, self::observations())->assertStatus(409);
        $this->voiceLab('POST', "sessions/{$uuid}/verdict", $rita, ['decision' => 'approved', 'specHash' => str_repeat('a', 64)])->assertStatus(409);
        $this->assertSame('not_a_lab_session', app(StudioVoiceLabSessions::class)->recordProviderReport(self::postCall('agent_desconocido_1', 'conv_1', 30)));

        Http::assertNothingSent();
        $this->assertSame(0, StudioVoiceLabSession::query()->count());
        $this->assertSame(0, DB::table('ultron_studio_voice_agents')->count());
    }

    public function test_encendido_en_el_simulador_tampoco_sale_a_la_red(): void
    {
        $this->voiceLabFake();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $uuid = $this->openSession($tina, $this->labVersion($ana)->uuid, $this->seedProfile($ana, null, 'submitted'), null, 0)->assertCreated()->json('data.uuid');
        $this->voiceLab('POST', "sessions/{$uuid}/end", $tina, self::observations())->assertOk();
        $this->voiceLab('GET', 'sessions', $tina)->assertOk();

        Http::assertNothingSent();
    }

    public function test_con_los_adaptadores_nulos_desplegar_y_pedir_la_url_fallan_cerrados_sin_red(): void
    {
        // La configuración del laboratorio pasa, pero ElevenLabs está apagado: la
        // fábrica de B8 da el desplegador y el emisor NULOS. Por la API no se
        // llega aquí (la credencial sin verificar cierra antes); si se llegara,
        // fallaría cerrado.
        $this->voiceLabElevenLabs();
        config(['voice.elevenlabs.enabled' => false]);
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $agentes = app(StudioVoiceLabAgents::class);
        $preparado = $agentes->prepare($this->labVersion($ana)->uuid, $this->seedProfile($ana, null, 'submitted'), StudioVoiceLabConfig::current());
        $id = $agentes->insertPending($preparado, ['id' => $tina->id, 'name' => $tina->name, 'role' => $tina->role], (string) Str::uuid());

        $this->assertSame(['ok' => false, 'endReason' => 'agent_not_created'], $agentes->deploy($id, $preparado));
        $this->assertSame(['failed', 'create_not_configured'], array_values((array) DB::table('ultron_studio_voice_agents')->where('id', $id)->first(['status', 'failure_code'])));
        $this->assertSame(['ok' => false, 'endReason' => 'url_not_configured'], $agentes->issueUrl('elevenlabs', 'agent_cualquiera_1'));
        Http::assertNothingSent();
    }

    public function test_con_la_bandera_encendida_cada_cerrojo_que_falta_cierra_sin_red(): void
    {
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $version = $this->labVersion($ana)->uuid;
        $perfil = $this->seedProfile($ana, null, 'submitted');

        foreach ([
            'presupuesto diario 0' => ['voice.lab.daily_budget_usd' => '0'],
            'tope por sesión 0' => ['voice.lab.session_budget_usd' => '0'],
            'sin precio' => ['voice.prices.elevenlabs' => null],
            'sin cupo' => ['voice.lab.max_sessions_per_day' => 0],
            'sin base' => ['voice.webhook_base_url' => null],
        ] as $caso => $cambio) {
            $this->voiceLabElevenLabs($cambio);
            $this->openSession($tina, $version, $perfil, null, 500000)->assertStatus(409)->assertJsonPath('code', 'voice_lab_not_configured');
            $this->assertSame(0, StudioVoiceLabSession::query()->count(), $caso);
        }

        // Y la credencial sin verificar: la ÚLTIMA verificación salió mal.
        $this->voiceLabElevenLabs();
        app(StudioIntegrationConfig::class)->recordVoiceVerification(new VoiceCheckResult(VoiceCheckResult::OUTCOME_INVALID_CREDENTIALS, 401, null, 50));
        $this->openSession($tina, $version, $perfil, null, 500000)->assertStatus(409)->assertJsonPath('reasons', ['credentials_unverified']);

        Http::assertNothingSent();
    }
}
