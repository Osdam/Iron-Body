<?php

namespace Tests\Feature\UltronStudio;

use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioIntegrationSnapshot;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LA SONDA DE SOLO LECTURA DE TWILIO por HTTP (contrato H6 §I5.4, ruta 56):
 * apagada por defecto; con su bandera, cinco GET a los hosts oficiales y una
 * instantánea SANEADA; enfriamiento de 300 s sin red ni evento; reintento por
 * `clientRequestId`; un fallo del proveedor nunca es un 5xx; el presupuesto de
 * 30 s salta los pasos que no caben. Todo contra `Http::fake`: ninguna
 * petición real.
 */
class UltronStudioIntegrationsProbeTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const SID = 'AC0123456789abcdef0123456789abcdef';

    private const TOKEN = 'h6pruebaTWILIOTOKENcentinela00001';

    /** Un teléfono de una persona en los cuerpos crudos: no puede llegar a la instantánea. */
    private const HOSTIL = '+573000000021';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        $this->integrationsFlags(writes: false, probe: true);
        config(['otp.twilio.sid' => self::SID, 'otp.twilio.token' => self::TOKEN, 'ultron_studio.integrations.twilio_probe.base' => null]);
    }

    public function test_apagada_por_defecto_responde_409_sin_red_ni_libro_y_el_permiso_va_antes(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->integrationsFlags(probe: false);

        $this->integrations('POST', 'twilio/probe', $root, [])->assertStatus(422);
        $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'probe_disabled');
        $this->integrations('POST', 'twilio/probe', $ana, ['clientRequestId' => (string) Str::uuid()])->assertStatus(403)->assertJsonPath('required_permission', 'ultron.integrations_manage');
        Http::assertNothingSent();
        $this->assertSame(0, StudioAuditEvent::query()->count());
        $this->assertSame(0, StudioIntegrationSnapshot::query()->count());
    }

    public function test_sin_cuenta_o_sin_credencial_es_409_twilio_not_configured(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $sondear = fn () => $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()]);

        foreach ([null, '', 'AC123', 'AC'.strtoupper(substr(self::SID, 2))] as $sid) {
            config(['otp.twilio.sid' => $sid]);
            $sondear()->assertStatus(409)->assertJsonPath('code', 'twilio_not_configured')->assertJsonPath('reason', 'missing_account_sid');
        }
        config(['otp.twilio.sid' => self::SID, 'otp.twilio.token' => null]);
        $sondear()->assertStatus(409)->assertJsonPath('reason', 'missing_credential');
        // La llave restringida a medias no cuenta: sigue siendo la del OTP, que falta.
        config(['ultron_studio.integrations.twilio_probe.key_sid' => 'SK'.str_repeat('1', 32)]);
        $sondear()->assertStatus(409)->assertJsonPath('reason', 'missing_credential');
        Http::assertNothingSent();
    }

    public function test_cinco_get_a_los_hosts_oficiales_y_una_instantanea_saneada(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        Http::fake(self::twilio());

        $r = $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        $r->assertJsonPath('data.replayed', false)->assertJsonPath('data.reused', false)->assertJsonPath('data.snapshot.outcome', 'ok')
            ->assertJsonPath('data.snapshot.by.name', 'Root');
        $facts = $r->json('data.snapshot.facts');
        $this->assertSame('otp', $facts['credential']);
        $this->assertSame(['status' => 'active', 'type' => 'Full'], $facts['account']);
        $this->assertSame(['count' => 1, 'truncated' => false, 'voiceCapable' => 0, 'smsCapable' => 1, 'items' => [['last4' => '0006', 'voice' => false, 'sms' => true]]], $facts['numbers']);
        $this->assertSame(['total' => 2, 'truncated' => false, 'byCode' => ['60200' => 2], 'byLevel' => ['warning' => 2]], $facts['alerts24h']);
        $this->assertSame([], $facts['errors']);

        Http::assertSentCount(5);
        $hosts = [];
        foreach (Http::recorded() as [$peticion]) {
            $this->assertSame('GET', $peticion->method());
            $this->assertSame('https', parse_url($peticion->url(), PHP_URL_SCHEME));
            $hosts[] = parse_url($peticion->url(), PHP_URL_HOST);
            $this->assertSame('Basic '.base64_encode(self::SID.':'.self::TOKEN), $peticion->header('Authorization')[0]);
        }
        $this->assertSame(['api.twilio.com', 'api.twilio.com', 'api.twilio.com', 'api.twilio.com', 'monitor.twilio.com'], $hosts);

        $fila = StudioIntegrationSnapshot::query()->sole();
        $this->assertSame(['twilio', 'probe', 'ok', 'admin', $root->id], [$fila->provider, $fila->kind, $fila->outcome, $fila->actor_type, $fila->actor_admin_id]);
        $evento = StudioAuditEvent::query()->sole();
        $this->assertSame(['integration.probe_run', 'integration', $fila->uuid], [$evento->event, $evento->subject_type, $evento->subject_uuid]);
        $this->assertSame(['provider' => 'twilio', 'outcome' => 'ok'], $evento->diff);
        $plano = json_encode([$r->json(), $fila->facts, $evento->diff, $evento->summary]);
        foreach ([self::HOSTIL, '573000000021', self::TOKEN, self::SID, 'Iron Body', 'evil.test'] as $centinela) {
            $this->assertStringNotContainsString($centinela, $plano);
        }

        // `GET integrations/twilio` enseña la última sonda.
        $this->assertSame($fila->uuid, $this->integrations('GET', 'twilio', $root)->json('data.probe.last.uuid'));
    }

    public function test_el_enfriamiento_reutiliza_la_ultima_sin_red_ni_evento_y_el_reintento_devuelve_la_suya(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $otro = $this->personWith(self::SUPER_KEYS, 'Otro');
        Http::fake(self::twilio());
        $id = (string) Str::uuid();

        $uuid = $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => $id])->assertCreated()->json('data.snapshot.uuid');
        $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => $id])->assertOk()
            ->assertJsonPath('data.replayed', true)->assertJsonPath('data.snapshot.uuid', $uuid);
        $this->integrations('POST', 'twilio/probe', $otro, ['clientRequestId' => $id])->assertStatus(409)->assertJsonPath('code', 'client_request_reused');

        $this->travel(299)->seconds();
        $this->integrations('POST', 'twilio/probe', $otro, ['clientRequestId' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('data.reused', true)->assertJsonPath('data.replayed', false)->assertJsonPath('data.snapshot.uuid', $uuid);
        Http::assertSentCount(5);
        $this->assertSame(1, StudioAuditEvent::query()->count());

        $this->travel(1)->seconds();
        $this->integrations('POST', 'twilio/probe', $otro, ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        Http::assertSentCount(10);
        $this->assertSame(2, StudioIntegrationSnapshot::query()->count());

        // El reintento va antes que la configuración: aunque ya esté rota.
        config(['otp.twilio.sid' => null]);
        $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => $id])->assertOk()->assertJsonPath('data.replayed', true);
    }

    public function test_un_401_en_la_cuenta_para_la_sonda_y_no_es_un_5xx(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        Http::fake(['*' => Http::response(['code' => 20003, 'message' => 'Authenticate'], 401)]);

        $r = $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        $r->assertJsonPath('data.snapshot.outcome', 'auth_failed');
        $this->assertSame([
            ['step' => 'account', 'outcome' => 'auth_failed', 'httpStatus' => 401],
            ['step' => 'numbers', 'outcome' => 'skipped', 'httpStatus' => null],
            ['step' => 'caller_ids', 'outcome' => 'skipped', 'httpStatus' => null],
            ['step' => 'calls', 'outcome' => 'skipped', 'httpStatus' => null],
            ['step' => 'alerts', 'outcome' => 'skipped', 'httpStatus' => null],
        ], $r->json('data.snapshot.facts.errors'));
        Http::assertSentCount(1);
    }

    public function test_si_se_pasan_los_30_segundos_los_pasos_que_faltan_se_saltan(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        Http::fake(function (HttpRequest $peticion) {
            // Cada paso «tarda» 11 s: al cuarto, el presupuesto ya no da.
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(11));

            return self::response(self::step($peticion));
        });

        $r = $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        CarbonImmutable::setTestNow();
        $r->assertJsonPath('data.snapshot.outcome', 'degraded');
        // 11 + 11 + 11 = 33 s tras el tercer paso: llamadas y alertas no caben.
        $this->assertSame([
            ['step' => 'calls', 'outcome' => 'skipped', 'httpStatus' => null],
            ['step' => 'alerts', 'outcome' => 'skipped', 'httpStatus' => null],
        ], $r->json('data.snapshot.facts.errors'));
        Http::assertSentCount(3);
    }

    public function test_con_la_llave_restringida_completa_lee_con_ella(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $sk = 'SK'.str_repeat('1', 32);
        config(['ultron_studio.integrations.twilio_probe.key_sid' => $sk, 'ultron_studio.integrations.twilio_probe.key_secret' => 'h6pruebaKEYSECRETcentinela0001']);
        Http::fake(self::twilio());

        $r = $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('restricted_key', $r->json('data.snapshot.facts.credential'));
        foreach (Http::recorded() as [$peticion]) {
            $this->assertSame('Basic '.base64_encode($sk.':h6pruebaKEYSECRETcentinela0001'), $peticion->header('Authorization')[0]);
            if (parse_url($peticion->url(), PHP_URL_HOST) === 'api.twilio.com') {
                $this->assertStringContainsString('/Accounts/'.self::SID, $peticion->url(), 'el SID de la cuenta va siempre en la ruta');
            }
        }
        $this->assertStringNotContainsString('h6pruebaKEYSECRETcentinela0001', $r->getContent());
    }

    public function test_fuera_de_produccion_respeta_la_base_del_simulador_y_en_produccion_la_ignora(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        config(['ultron_studio.integrations.twilio_probe.base' => 'http://127.0.0.1:8095']);
        Http::fake(self::twilio());
        $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        foreach (Http::recorded() as [$peticion]) {
            $this->assertSame('127.0.0.1', parse_url($peticion->url(), PHP_URL_HOST));
        }

        $this->travel(301)->seconds();
        $this->app->detectEnvironment(fn (): string => 'production');
        try {
            $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
        $hosts = array_values(array_unique(array_map(static fn (array $p): string => (string) parse_url($p[0]->url(), PHP_URL_HOST), Http::recorded()->all())));
        $this->assertSame(['127.0.0.1', 'api.twilio.com', 'monitor.twilio.com'], $hosts);
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Las cinco respuestas, con textos hostiles que el saneador tiene que
     * tirar, según el paso que se pide (cada vez una respuesta nueva).
     */
    private static function twilio(): Closure
    {
        return static fn (HttpRequest $peticion) => self::response(self::step($peticion));
    }

    private static function response(string $paso): mixed
    {
        $alerta = static fn (string $nivel): array => ['sid' => 'NO'.str_repeat('3', 32), 'error_code' => '60200', 'log_level' => $nivel,
            'alert_text' => 'Invalid parameter To: '.self::HOSTIL.' see https://evil.test/x', 'request_url' => 'https://evil.test'];

        return match ($paso) {
            'account' => Http::response(['sid' => self::SID, 'friendly_name' => 'Iron Body +57 300 000 0021', 'status' => 'active', 'type' => 'Full', 'auth_token' => self::TOKEN], 200),
            'numbers' => Http::response(['incoming_phone_numbers' => [['phone_number' => '+15005550006', 'friendly_name' => 'Iron Body', 'capabilities' => ['voice' => false, 'sms' => true]]], 'next_page_uri' => null], 200),
            'caller_ids' => Http::response(['outgoing_caller_ids' => [], 'next_page_uri' => null], 200),
            'calls' => Http::response(['calls' => [], 'next_page_uri' => null], 200),
            default => Http::response(['alerts' => [$alerta('warning'), $alerta('warning')], 'meta' => ['next_page_url' => null]], 200),
        };
    }

    private static function step(HttpRequest $peticion): string
    {
        $ruta = (string) parse_url($peticion->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($ruta, '/IncomingPhoneNumbers.json') => 'numbers',
            str_ends_with($ruta, '/OutgoingCallerIds.json') => 'caller_ids',
            str_ends_with($ruta, '/Calls.json') => 'calls',
            str_ends_with($ruta, '/v1/Alerts') => 'alerts',
            default => 'account',
        };
    }
}
