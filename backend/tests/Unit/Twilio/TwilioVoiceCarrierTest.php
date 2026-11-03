<?php

namespace Tests\Unit\Twilio;

use App\Services\Twilio\TwilioVoiceCarrier;
use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Voice\Support\FreshHttpFake;

/**
 * El operador de la subcuenta de voz (contrato H8, D8-3, D8-20 nivel 0b y
 * D8-21), siempre contra `Http::fake` y con `Http::preventStrayRequests()`.
 *
 * LO QUE SE PROTEGE.
 *  - Sin la credencial completa de la subcuenta NO SALE NADA (y no mira la del OTP).
 *  - Colgar = un POST de formulario con `Status=completed` a la llamada, con la
 *    API key de Laravel en Basic; en `production`, solo `api.twilio.com`.
 *  - Listar = UN GET, una página, `StartTime>` en UTC; de `next_page_uri` solo
 *    `Page` y `PageToken`, comprobados, contra el host propio; si no se pueden
 *    seguir, `truncated`.
 *  - Ningún resultado lleva un número completo ni la credencial.
 */
class TwilioVoiceCarrierTest extends TestCase
{
    use FreshHttpFake;

    private const CUENTA = 'AC0123456789abcdef0123456789abcdef';

    private const LLAVE = 'SK' . '0123456789abcdef' . '0123456789abcdef';

    private const SECRETO = 'centinela-SECRETO-DE-LA-KEY';

    private const SID = 'CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['voice.twilio' => [
            'enabled' => false, 'from' => null, 'daily_call_cap' => 0,
            'account_sid' => self::CUENTA, 'api_key_sid' => self::LLAVE, 'api_key_secret' => self::SECRETO,
            'signing_token' => null, 'base_url' => 'http://127.0.0.1:8095',
        ]]);
        // La credencial del OTP, para demostrar que no se usa.
        config(['otp.twilio.sid' => 'AC'.str_repeat('9', 32), 'otp.twilio.token' => 'centinela-TOKEN-OTP']);
    }

    public function test_sin_la_credencial_de_la_subcuenta_no_sale_nada(): void
    {
        self::freshFake();
        foreach ([['account_sid' => null], ['account_sid' => 'AC123'], ['api_key_sid' => 'AC'.str_repeat('0', 32)], ['api_key_secret' => '  ']] as $cambio) {
            config(['voice.twilio' => $cambio + config('voice.twilio')]);
            $this->assertFalse(TwilioVoiceCarrier::configured());
            $this->assertSame('not_configured', (new TwilioVoiceCarrier)->hangup(self::SID)->outcome);
            $this->assertSame('not_configured', (new TwilioVoiceCarrier)->listCalls(new DateTimeImmutable('2026-11-03T00:00:00Z'), null)->outcome);
            $this->setUp();
            self::freshFake();
        }
        Http::assertNothingSent();
    }

    public function test_colgar_en_produccion(): void
    {
        $this->app['env'] = 'production';
        self::freshFake(['*' => Http::response(['sid' => self::SID, 'status' => 'completed', 'to' => '+573000000077'], 200)]);

        $r = (new TwilioVoiceCarrier)->hangup(self::SID);

        $this->assertSame(['requested', self::SID, 'completed', 200, null], [$r->outcome, $r->callSid, $r->providerStatus, $r->httpStatus, $r->providerErrorCode]);
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $q): bool => $q->method() === 'POST'
            && $q->url() === 'https://api.twilio.com/2010-04-01/Accounts/'.self::CUENTA.'/Calls/'.self::SID.'.json'
            && $q->body() === 'Status=completed'
            && $q->header('Authorization') === ['Basic '.base64_encode(self::LLAVE.':'.self::SECRETO)]);
        $plano = (string) json_encode($r->toArray());
        $this->assertStringNotContainsString(self::SECRETO, $plano);
        $this->assertStringNotContainsString('+573000000077', $plano);
    }

    public function test_colgar_fallos_e_idempotencia(): void
    {
        $casos = [
            [404, ['code' => 20404], 'not_found', 20404],
            [401, ['code' => 20003], 'auth_failed', 20003],
            [400, ['code' => 21220, 'message' => 'Call is not in-progress'], 'rejected', 21220],
            [500, [], 'error', null],
        ];
        foreach ($casos as [$estado, $cuerpo, $resultado, $codigo]) {
            self::freshFake(['*' => Http::response($cuerpo, $estado)]);
            $r = (new TwilioVoiceCarrier)->hangup(self::SID);
            $this->assertSame([$resultado, $estado, $codigo], [$r->outcome, $r->httpStatus, $r->providerErrorCode], (string) $estado);
        }
        self::freshFake(static fn (Request $q) => Create::rejectionFor(new ConnectException('sin red', new Psr7Request('POST', $q->url()))));
        $this->assertSame('unreachable', (new TwilioVoiceCarrier)->hangup(self::SID)->outcome);

        self::freshFake();
        foreach (['CA123', "CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n", '../Calls', 'SM'.str_repeat('a', 32)] as $malo) {
            $this->assertSame('invalid_request', (new TwilioVoiceCarrier)->hangup($malo)->outcome);
        }
        Http::assertNothingSent();
    }

    public function test_listar_una_pagina_sin_numeros_y_con_su_continuacion(): void
    {
        self::freshFake(['*' => Http::response([
            'calls' => [
                ['sid' => self::SID, 'parent_call_sid' => null, 'status' => 'completed', 'direction' => 'outbound-api', 'to' => '+573000000077', 'from' => '+576010000009',
                    'start_time' => 'Tue, 03 Nov 2026 20:59:00 +0000', 'end_time' => 'Tue, 03 Nov 2026 21:01:05 +0000', 'duration' => '125', 'price' => '-0.07854', 'price_unit' => 'USD'],
                ['sid' => 'CA'.str_repeat('b', 32), 'parent_call_sid' => self::SID, 'status' => 'in-progress', 'direction' => 'outbound-dial', 'to' => 'client:operador',
                    'from' => '+576010000009', 'start_time' => 'Tue, 03 Nov 2026 21:00:30 +0000', 'end_time' => null, 'duration' => null, 'price' => null, 'price_unit' => 'USD'],
            ],
            'next_page_uri' => '/2010-04-01/Accounts/'.self::CUENTA.'/Calls.json?PageSize=100&Page=1&PageToken=PACAbcdef0123',
            'page' => 0,
        ], 200)]);

        $p = (new TwilioVoiceCarrier)->listCalls(new DateTimeImmutable('2026-11-03T02:00:00-05:00'), null);

        $this->assertSame(['listed', '1:PACAbcdef0123', false, 200], [$p->outcome, $p->nextPageToken, $p->truncated, $p->httpStatus]);
        $this->assertCount(2, $p->calls);
        $this->assertSame([
            'sid' => self::SID, 'parentSid' => null, 'status' => 'completed', 'direction' => 'outbound-api',
            'startTime' => '2026-11-03T20:59:00Z', 'endTime' => '2026-11-03T21:01:05Z', 'durationSeconds' => 125,
            'price' => '-0.07854', 'priceUnit' => 'USD', 'toLast4' => '0077', 'fromLast4' => '0009',
        ], $p->calls[0]->toArray());
        $this->assertNull($p->calls[1]->toLast4, 'de un cliente o una URI SIP no quedan dígitos');
        $plano = (string) json_encode($p->toArray());
        $this->assertStringNotContainsString('+573000000077', $plano);
        $this->assertStringNotContainsString('3000000077', $plano);

        Http::assertSent(static fn (Request $q): bool => $q->method() === 'GET'
            && str_starts_with($q->url(), 'http://127.0.0.1:8095/2010-04-01/Accounts/'.self::CUENTA.'/Calls.json?')
            && $q->data() === ['PageSize' => 100, 'StartTime>' => '2026-11-03']);
    }

    public function test_la_pagina_siguiente_nunca_sigue_una_url_ajena(): void
    {
        self::freshFake(['*' => Http::response(['calls' => [], 'next_page_uri' => null], 200)]);
        (new TwilioVoiceCarrier)->listCalls(new DateTimeImmutable('2026-11-03T00:00:00Z'), '3:PACAbcdef0123');
        Http::assertSent(static fn (Request $q): bool => str_starts_with($q->url(), 'http://127.0.0.1:8095/')
            && $q->data() === ['PageSize' => 100, 'StartTime>' => '2026-11-03', 'Page' => 3, 'PageToken' => 'PACAbcdef0123']);

        // Un enlace que apunta a otro host: solo se toman Page y PageToken.
        self::freshFake(['*' => Http::response(['calls' => [], 'next_page_uri' => 'https://evil.test/Calls.json?Page=2&PageToken=PAtoken9'], 200)]);
        $this->assertSame('2:PAtoken9', (new TwilioVoiceCarrier)->listCalls(new DateTimeImmutable('2026-11-03T00:00:00Z'), null)->nextPageToken);

        // Un enlace con un token que no se puede seguir: truncada, en voz alta.
        self::freshFake(['*' => Http::response(['calls' => [], 'next_page_uri' => '/Calls.json?Page=2&PageToken=PA%20con%20espacios'], 200)]);
        $p = (new TwilioVoiceCarrier)->listCalls(new DateTimeImmutable('2026-11-03T00:00:00Z'), null);
        $this->assertSame(['listed', null, true], [$p->outcome, $p->nextPageToken, $p->truncated]);

        self::freshFake();
        foreach (['PAtoken', '1:PA token', 'x:PA', '1:'.str_repeat('a', 300)] as $malo) {
            $this->assertSame('invalid_request', (new TwilioVoiceCarrier)->listCalls(new DateTimeImmutable('2026-11-03T00:00:00Z'), $malo)->outcome);
        }
        Http::assertNothingSent();
    }

    public function test_listar_fallos(): void
    {
        foreach ([[401, [], 'auth_failed'], [500, [], 'error'], [200, ['calls' => 'no'], 'error'], [200, ['calls' => [['sid' => 'CAxyz']]], 'error']] as [$estado, $cuerpo, $resultado]) {
            self::freshFake(['*' => Http::response($cuerpo, $estado)]);
            $this->assertSame($resultado, (new TwilioVoiceCarrier)->listCalls(new DateTimeImmutable('2026-11-03T00:00:00Z'), null)->outcome);
        }
    }

    public function test_la_huella_de_la_key(): void
    {
        $this->assertSame(['api_key_secret' => substr(hash_hmac('sha256', self::SECRETO, 'clave'), 0, 8)], TwilioVoiceCarrier::secretFingerprints('clave'));
        config(['voice.twilio.api_key_secret' => null]);
        $this->assertSame(['api_key_secret' => null], TwilioVoiceCarrier::secretFingerprints('clave'));
        $this->expectException(\InvalidArgumentException::class);
        TwilioVoiceCarrier::secretFingerprints('');
    }
}
