<?php

namespace Tests\Feature\Telephony;

use App\Models\Voice\VoiceBrakeEntry;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceHangup;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Twilio\TwilioVoiceCarrier;
use App\Services\Voice\Dto\CarrierCallsPage;
use App\Services\Voice\Dto\CarrierResult;
use App\Services\Voice\NullVoiceAgentDeployer;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * B8-g (punto 4): LA CUENTA DE TWILIO DE LA VOZ. La telefonía usa
 * `TWILIO_VOICE_ACCOUNT_SID` + una API key restringida (SK…, Basic) + el token
 * de firma, todo de `voice.twilio.*`. El código no distingue la cuenta
 * principal de una subcuenta: el SID va en la ruta y la key en la cabecera,
 * igual en las dos. Lo que cambia es lo que CONTIENE la cuenta: la conciliación
 * lista TODAS las llamadas de la cuenta configurada, así que con la principal
 * cualquier llamada que no haga la voz (otro número u otro producto) es
 * desconocida y echa el freno; con la subcuenta del contrato (D8-4, D8-5)
 * solo están las de la voz. Y ninguna ruta de la voz toca la credencial ni el
 * cliente del OTP (`otp.twilio.*`), sea cual sea la cuenta. Sin red.
 */
class VoiceTwilioAccountTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    /** La cuenta principal (la del OTP) y una subcuenta suya: dos SID con forma, de ficción. */
    private const PRINCIPAL = 'AC1111111111111111111111111111111a';

    private const SUBCUENTA = 'AC2222222222222222222222222222222b';

    private const KEY_SID = 'SK3333333333333333333333333333333c';

    private const KEY_SECRET = 'secreto-de-la-key-restringida-0001';

    /** La credencial del OTP: el Auth Token de la cuenta principal. Nunca puede viajar en una petición de la voz. */
    private const TOKEN_OTP = 'auth-token-maestro-del-otp-centinela';

    private const SID = 'CA000000000000000000000000b8a40001';

    /** @var list<Request> */
    private array $peticiones = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));
        $this->voiceWorld([
            'voice.twilio.api_key_sid' => self::KEY_SID, 'voice.twilio.api_key_secret' => self::KEY_SECRET,
            'voice.twilio.signing_token' => null, 'voice.twilio.base_url' => null,
            'otp.twilio.sid' => self::PRINCIPAL, 'otp.twilio.token' => self::TOKEN_OTP,
            'voice.limits.daily_budget_usd' => '1000',
        ]);
    }

    /** Twilio simulado (el host oficial: fuera de producción, sin base configurada, también es el de siempre). */
    private function twilio(string $cuenta, array $llamadas = []): void
    {
        $pagina = json_decode((string) file_get_contents(base_path('tests/fixtures/voice/twilio-webhook-bodies.json')), true)['callsPage'][0]['page'];
        $modelo = $pagina['calls'][0];
        $pagina['calls'] = array_map(static fn (array $c): array => array_merge($modelo, ['account_sid' => $cuenta], $c), $llamadas);
        $pagina['end'] = count($pagina['calls']);
        $pagina['next_page_uri'] = null;
        $this->peticiones = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use ($pagina) {
            $this->peticiones[] = $r;
            if ($r->method() === 'GET' && str_contains($r->url(), '/Calls.json')) {
                return Http::response($pagina);
            }
            if ($r->method() === 'POST' && str_contains($r->url(), '/Calls/')) {
                return Http::response(['status' => 'completed'], 200);
            }

            return null;
        });
    }

    /** @return list<array{method: string, path: string, query: ?string, auth: string, body: string}> lo que la voz pide a Twilio, sin el SID de la cuenta */
    private function pedido(string $cuenta): array
    {
        return array_map(static fn (Request $r): array => [
            'method' => $r->method(),
            'host' => (string) parse_url($r->url(), PHP_URL_HOST),
            'path' => str_replace($cuenta, '{AC}', (string) parse_url($r->url(), PHP_URL_PATH)),
            'query' => parse_url($r->url(), PHP_URL_QUERY),
            'auth' => (string) ($r->header('Authorization')[0] ?? ''),
            'body' => $r->body(),
        ], $this->peticiones);
    }

    public function test_el_transportista_pide_lo_mismo_con_la_cuenta_principal_que_con_una_subcuenta_y_nunca_con_la_credencial_del_otp(): void
    {
        $pedidos = [];
        foreach ([self::PRINCIPAL, self::SUBCUENTA] as $cuenta) {
            config(['voice.twilio.account_sid' => $cuenta]);
            $this->assertTrue(TwilioVoiceCarrier::configured());
            $this->assertInstanceOf(TwilioVoiceCarrier::class, VoiceAdapters::carrier());
            $this->twilio($cuenta, [['sid' => self::SID, 'status' => 'completed']]);

            $colgar = VoiceAdapters::carrier()->hangup(self::SID);
            $lista = VoiceAdapters::carrier()->listCalls(now()->subDay(), null);
            $this->assertSame([CarrierResult::OUTCOME_REQUESTED, CarrierCallsPage::OUTCOME_LISTED, 1], [$colgar->outcome, $lista->outcome, count($lista->calls)]);
            foreach ($this->peticiones as $r) {
                $this->assertStringContainsString('/2010-04-01/Accounts/'.$cuenta.'/Calls', $r->url(), 'el SID configurado va en la ruta');
            }
            $pedidos[$cuenta] = $this->pedido($cuenta);
        }

        // Las mismas peticiones, byte a byte, salvo el SID de la cuenta en la ruta.
        $this->assertSame($pedidos[self::PRINCIPAL], $pedidos[self::SUBCUENTA]);
        $this->assertSame('Basic '.base64_encode(self::KEY_SID.':'.self::KEY_SECRET), $pedidos[self::PRINCIPAL][0]['auth'], 'la key restringida (SK…), en Basic');
        $this->assertSame(['POST', 'api.twilio.com', '/2010-04-01/Accounts/{AC}/Calls/'.self::SID.'.json', null, 'Status=completed'],
            [$pedidos[self::PRINCIPAL][0]['method'], $pedidos[self::PRINCIPAL][0]['host'], $pedidos[self::PRINCIPAL][0]['path'], $pedidos[self::PRINCIPAL][0]['query'], $pedidos[self::PRINCIPAL][0]['body']]);
        $this->assertSame(['GET', '/2010-04-01/Accounts/{AC}/Calls.json', 'PageSize=100&StartTime%3E=2026-11-08'],
            [$pedidos[self::PRINCIPAL][1]['method'], $pedidos[self::PRINCIPAL][1]['path'], $pedidos[self::PRINCIPAL][1]['query']]);

        // La credencial del OTP no viaja nunca, ni el cliente de Verify sale a la red, aunque la cuenta sea la misma.
        foreach ($pedidos as $lista) {
            foreach ($lista as $p) {
                $this->assertNotSame('verify.twilio.com', $p['host']);
                $this->assertStringNotContainsString(base64_encode(self::PRINCIPAL.':'.self::TOKEN_OTP), $p['auth']);
                $this->assertStringNotContainsString(self::TOKEN_OTP, $p['auth'].$p['body'].$p['query']);
            }
        }
    }

    /**
     * La conciliación es la misma con las dos cuentas: lo que cambia es lo que
     * enseña la cuenta. Una llamada que la voz no hizo (en la principal, la de
     * otro número u otro producto) es desconocida y echa el freno; con la
     * subcuenta dedicada solo están las de la voz. Por eso el contrato pide la
     * subcuenta (D8-4, D8-5) y, con la principal, que no tenga otro tráfico de voz.
     */
    public function test_con_la_cuenta_principal_una_llamada_ajena_a_la_voz_es_desconocida_y_frena_igual_que_en_la_subcuenta(): void
    {
        foreach ([self::PRINCIPAL, self::SUBCUENTA] as $i => $cuenta) {
            config(['voice.twilio.account_sid' => $cuenta]);
            $sid = 'CA000000000000000000000000b8a4010'.$i;
            $this->twilio($cuenta, [['sid' => $sid, 'status' => 'completed', 'direction' => 'inbound', 'to' => '+576010000555', 'from' => '+573001234567']]);
            $resumen = app(VoiceReconciler::class)->run(VoiceAdapters::carrier(), new NullVoiceAgentDeployer, now()->subDays(2));
            $this->assertSame(['listed', 0, 1], [$resumen['carrier'], $resumen['known'], $resumen['unknown']], $cuenta);
            $this->assertSame(1, VoiceBrakeEntry::query()->where('open_key', VoiceHangup::brakeKey($sid))->count(), $cuenta);
        }
        $this->assertTrue(VoiceBrake::isOpen());
    }
}
