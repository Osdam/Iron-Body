<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * LO QUE AFIRMA EL STUDIO de cada integración (contrato H6 §I7.2, ruta 39), el
 * panel de Twilio (§I5.2, rutas 43 y 44) y la voz (§I6.6, ruta 45). Sin red:
 * todo sale del espejo local, de la configuración y de las tablas. Cada bloque
 * se calcula por su cuenta: si su fuente falla, `available:false` y una línea
 * de log con la clase, nunca un valor inventado.
 */
class UltronStudioIntegrationsStatusTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const SID = 'AC0123456789abcdef0123456789abcdef';

    private const TOKEN = 'h6pruebaTWILIOTOKENcentinela00001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_por_defecto_las_clasificaciones_sin_configurar_y_la_capacidad_del_administrador(): void
    {
        $admin = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $data = $this->integrations('GET', 'status', $admin)->assertOk()->json('data');

        $this->assertSame(['campaignsManage' => true, 'canaryManage' => true, 'canaryAuthorize' => false, 'integrationsManage' => false], $data['can']);
        $this->assertSame(['writesEnabled' => false, 'twilioProbeEnabled' => false], $data['flags']);
        $this->assertSame('Las banderas son las del proceso web, con su caché de configuración.', $data['note']);
        $this->assertSame(['meta_ads', 'twilio', 'twilio_voice', 'canaries', 'elevenlabs'], array_column($data['providers'], 'id'));
        $porId = array_column($data['providers'], null, 'id');
        $this->assertSame(['not_configured', 'not_configured', 'missing_access_token'], [$porId['meta_ads']['classification'], $porId['meta_ads']['state'], $porId['meta_ads']['reason']]);
        $this->assertSame(['not_configured', 'otp_driver_not_twilio'], [$porId['twilio']['classification'], $porId['twilio']['reason']]);
        $this->assertSame(['classification' => 'not_configured', 'evidence' => ['enabled' => false, 'fromConfigured' => false]], array_intersect_key($porId['twilio_voice'], ['classification' => 1, 'evidence' => 1]));
        $this->assertSame(['active' => ['whatsapp' => 0, 'voice' => 0], 'pending' => 0, 'authorized' => 0, 'verdict' => 'no_engine_canary'], $porId['canaries']['evidence']);
        $this->assertSame(['not_configured', 'ELEVENLABS_NOT_CONFIGURED'], [$porId['elevenlabs']['classification'], $porId['elevenlabs']['code']]);
        $this->assertSame(['enabled' => false, 'missing' => ['api_key', 'agent_id', 'webhook_secret']], $porId['elevenlabs']['evidence']);

        $super = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->assertSame(['campaignsManage' => true, 'canaryManage' => true, 'canaryAuthorize' => true, 'integrationsManage' => true],
            $this->integrations('GET', 'status', $super)->json('data.can'));
    }

    public function test_la_clasificacion_de_produccion_al_congelar(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->metaConfigured();
        $this->twilioConfigured();
        $porId = array_column($this->integrations('GET', 'status', $ana)->json('data.providers'), null, 'id');

        $this->assertSame(['connected_read', 'ok', null], [$porId['meta_ads']['classification'], $porId['meta_ads']['state'], $porId['meta_ads']['reason']]);
        $this->assertSame(3, $porId['meta_ads']['evidence']['accounts']);
        $this->assertSame(180, $porId['meta_ads']['evidence']['staleAfterMinutes']);
        $this->assertSame('v26.0', $porId['meta_ads']['evidence']['graphVersion']);
        $this->assertSame(['connected_otp_only', 'ok', null], [$porId['twilio']['classification'], $porId['twilio']['state'], $porId['twilio']['reason']]);
        $this->assertSame(['otpDriver' => 'twilio', 'verifyActive' => true, 'sendEnabled' => true], array_intersect_key($porId['twilio']['evidence'], ['otpDriver' => 1, 'verifyActive' => 1, 'sendEnabled' => 1]));
    }

    public function test_twilio_degrada_con_el_interruptor_o_el_techo_duro_y_los_retos_no_degradan(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->twilioConfigured();
        $bloque = fn (): array => collect($this->integrations('GET', 'status', $ana)->json('data.providers'))->firstWhere('id', 'twilio');

        config(['otp.send_enabled' => false]);
        $this->assertSame(['degraded', 'kill_switch'], [$bloque()['classification'], $bloque()['reason']]);
        config(['otp.send_enabled' => true]);
        Cache::put('otp:cost:'.now()->format('Y-m-d'), 120000);
        $b = $bloque();
        $this->assertSame(['degraded', 'hard_limit_reached'], [$b['classification'], $b['reason']]);
        $this->assertSame(12.0, (float) $b['evidence']['estimatedSpentTodayUsd']);
        Cache::put('otp:cost:'.now()->format('Y-m-d'), 0);
        config(['otp.twilio.verify_service_sid' => null]);
        $this->assertSame(['not_configured', 'verify_incomplete'], [$bloque()['classification'], $bloque()['reason']]);
    }

    public function test_un_bloque_cuya_fuente_falla_sale_no_disponible_sin_tumbar_los_demas(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->as($ana);
        Log::spy();
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_contains($sql, 'trainer_auth_challenges')) {
                throw new RuntimeException('la base del OTP no responde (texto que no puede salir)');
            }
        });

        $r = $this->integrations('GET', 'status', $ana)->assertOk();
        $this->assertSame(['id' => 'twilio', 'available' => false], collect($r->json('data.providers'))->firstWhere('id', 'twilio'));
        $this->assertTrue(collect($r->json('data.providers'))->firstWhere('id', 'meta_ads')['available']);
        $this->assertStringNotContainsString('no responde', $r->getContent());
        Log::shouldHaveReceived('warning')->with('ultron_studio.integration_status_unavailable', ['provider' => 'twilio', 'error_class' => 'RuntimeException']);
    }

    public function test_twilio_voice_y_elevenlabs_segun_su_configuracion(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $porId = fn (): array => array_column($this->integrations('GET', 'status', $ana)->json('data.providers'), null, 'id');

        config(['voice.twilio.from' => '+576010000099']);
        $this->assertSame('disabled', $porId()['twilio_voice']['classification']);
        config(['voice.twilio.enabled' => true]);
        $this->assertSame('degraded', $porId()['twilio_voice']['classification']);
        config(['voice.twilio.from' => '601 000 0099']);
        $this->assertSame('not_configured', $porId()['twilio_voice']['classification'], 'sin forma E.164 no hay número de voz');

        config(['voice.elevenlabs.api_key' => 'sk_x', 'voice.elevenlabs.agent_id' => 'agent_1', 'voice.elevenlabs.webhook_secret' => 'wsec_x', 'voice.elevenlabs.region' => 'eu']);
        $this->assertSame(['disabled', 'ELEVENLABS_DISABLED'], [$porId()['elevenlabs']['classification'], $porId()['elevenlabs']['code']]);
        config(['voice.elevenlabs.enabled' => true]);
        $this->assertSame(['degraded', 'ELEVENLABS_UNVERIFIED'], [$porId()['elevenlabs']['classification'], $porId()['elevenlabs']['code']]);
    }

    public function test_el_panel_de_twilio_enseña_credenciales_sin_valores_y_llamadas_sin_datos_del_prospecto(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->twilioConfigured();
        config(['otp.twilio.base' => 'https://api.twilio.com.evil.test', 'otp.twilio.from' => '+15005550006']);
        DB::table('trainer_auth_challenges')->insert([
            ['uuid' => (string) Str::uuid(), 'purpose' => 'trainer_login', 'code_hash' => 'x', 'channel' => 'sms', 'destination' => '+573000000077', 'status' => 'verified',
                'expires_at' => now()->addMinutes(5), 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)],
            ['uuid' => (string) Str::uuid(), 'purpose' => 'trainer_login', 'code_hash' => 'x', 'channel' => 'sms', 'destination' => '+573000000077', 'status' => 'verified',
                'expires_at' => now()->addMinutes(5), 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)],
            ['uuid' => (string) Str::uuid(), 'purpose' => 'trainer_login', 'code_hash' => 'x', 'channel' => 'sms', 'destination' => '+573000000077', 'status' => 'expired',
                'expires_at' => now()->subMinutes(5), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()],
        ]);
        DB::table('marketing_calls')->insert([
            ['provider' => 'twilio', 'provider_call_sid' => 'CA'.str_repeat('9', 32), 'to_phone' => '+573000000088', 'from_phone' => '+15005550006', 'status' => 'completed',
                'direction' => 'outbound', 'reason' => 'Recuperación', 'transcript' => 'Hola, soy Ana', 'summary' => 'Resumen privado', 'metadata' => '{"x":1}',
                'duration_seconds' => 30, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
            ['provider' => 'twilio', 'provider_call_sid' => null, 'to_phone' => '+573000000089', 'from_phone' => null, 'status' => 'pending',
                'direction' => 'outbound', 'reason' => null, 'transcript' => null, 'summary' => null, 'metadata' => null,
                'duration_seconds' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $r = $this->integrations('GET', 'twilio', $ana)->assertOk();
        $d = $r->json('data');
        $this->assertSame(['last24h' => 1, 'last7d' => 2], array_intersect_key($d['otp']['verified'], ['last24h' => 1, 'last7d' => 1]));
        $this->assertTrue($d['otp']['memberLinkSenderConfigured']);
        $this->assertSame(['date', 'estimatedSpentUsd', 'dailySoftLimitUsd', 'dailyHardLimitUsd'], array_keys($d['cost']));
        $this->assertSame(['present' => true, 'source' => 'TWILIO_SID', 'ref' => 'AC…cdef'], $d['credentials']['accountSid']);
        $this->assertSame(8, strlen($d['credentials']['authToken']['fp']));
        $this->assertSame(['sid' => true, 'token' => true], $d['credentials']['aliasesConsistent']);
        $this->assertSame(['official' => false, 'host' => 'api.twilio.com.evil.test'], $d['credentials']['apiBase']);
        $this->assertSame(['official' => true], $d['credentials']['verifyBase']);
        $this->assertSame('usage_trigger', $d['webhooks'][0]['id']);
        $this->assertSame('solo log', $d['webhooks'][0]['persistence']);
        $this->assertSame(['total' => 2, 'withProviderSid' => 1, 'byStatus' => ['completed' => 1, 'pending' => 1], 'byDirection' => ['outbound' => 2]],
            array_intersect_key($d['calls'], ['total' => 1, 'withProviderSid' => 1, 'byStatus' => 1, 'byDirection' => 1]));
        $this->assertSame(['enabled' => false, 'credential' => 'otp', 'cooldownSeconds' => 300, 'last' => null], $d['probe']);

        $llamadas = $this->integrations('GET', 'twilio/calls', $ana)->assertOk();
        $this->assertSame(2, $llamadas->json('data.total'));
        $this->assertSame(['status', 'direction', 'reason', 'scheduledAt', 'startedAt', 'endedAt', 'durationSeconds', 'hasProviderSid', 'createdAt'], array_keys($llamadas->json('data.data.0')));
        $this->assertSame([false, true], array_column($llamadas->json('data.data'), 'hasProviderSid'));
        foreach ([$r->getContent(), $llamadas->getContent()] as $cuerpo) {
            foreach (['3000000088', '3000000089', '3000000077', 'Hola, soy Ana', 'Resumen privado', self::TOKEN, self::SID] as $centinela) {
                $this->assertStringNotContainsString($centinela, $cuerpo);
            }
        }
        $this->integrations('GET', 'twilio/calls?per_page=0', $ana)->assertStatus(422);
    }

    public function test_la_voz_sin_llamar_a_nadie_con_la_politica_y_los_tres_niveles(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $voz = $this->integrations('GET', 'voice', $ana)->assertOk()->json('data');

        $this->assertSame(['state' => 'ELEVENLABS_NOT_CONFIGURED', 'enabled' => false, 'missing' => ['api_key', 'agent_id', 'webhook_secret'], 'region' => 'default', 'lastVerification' => null], $voz['elevenlabs']);
        $this->assertSame(['enabled' => false, 'fromConfigured' => false, 'dailyCallCap' => 0], $voz['twilioVoice']);
        $this->assertSame(['allowed' => false, 'reasons' => ['calls_disabled_in_code', 'twilio_voice_disabled', 'no_voice_number', 'elevenlabs_not_configured', 'not_an_active_voice_canary', 'daily_cap_zero']], $voz['policy']);
        $this->assertSame([
            ['id' => 'text_simulation', 'available' => true, 'cost' => 'none', 'where' => 'laboratorio'],
            ['id' => 'integration_test', 'available' => false, 'reasons' => ['calls_disabled_in_code', 'twilio_voice_disabled', 'no_voice_number', 'elevenlabs_not_configured']],
            ['id' => 'real_call', 'available' => false, 'reasons' => $voz['policy']['reasons']],
        ], $voz['levels']);

        // Un canario de voz activo quita su motivo; las llamadas siguen cerradas en el código.
        $this->integrationsFlags(writes: true);
        $this->activeCanary($ana, $root, '+576010000099', 'voice', 'Fijo de recepción');
        $politica = $this->integrations('GET', 'voice', $ana)->json('data.policy');
        $this->assertFalse($politica['allowed']);
        $this->assertNotContains('not_an_active_voice_canary', $politica['reasons']);
        $this->assertSame('calls_disabled_in_code', $politica['reasons'][0]);
    }

    private function twilioConfigured(): void
    {
        config([
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => self::SID,
            'otp.twilio.token' => self::TOKEN,
            'otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'9f0e',
            'otp.send_enabled' => true,
            'ultron_studio.integrations.twilio_aliases' => ['sid_source' => 'TWILIO_SID', 'token_source' => 'TWILIO_TOKEN', 'sid_consistent' => true, 'token_consistent' => true],
        ]);
    }
}
