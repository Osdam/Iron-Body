<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\ElevenLabs\ElevenLabsLabSessionIssuer as Emisor;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Voice\Support\FreshHttpFake;

/**
 * El emisor de URL firmadas del laboratorio hablado (contrato H8, D8-3 y D8-23).
 *
 * LO QUE SE PROTEGE.
 *  - Sin la configuración completa no sale nada.
 *  - Un GET con el `agent_id` en la consulta y la clave en `xi-api-key`; en
 *    `production`, solo el host oficial.
 *  - La URL que vuelve tiene que ser un WebSocket del MISMO host: otra no se entrega.
 *  - La URL es una credencial: no sale en `toArray()`, `print_r()` ni `var_dump()`, y no se serializa.
 */
class ElevenLabsLabSessionIssuerTest extends TestCase
{
    use FreshHttpFake;

    private const CLAVE = 'xi-centinela-LAB-0001';

    private const FIRMA = 'conversation_signature=CENTINELA-FIRMA-0001';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configurar();
    }

    /** @param  array<string, mixed>  $cambios */
    private function configurar(array $cambios = []): void
    {
        config(['voice.elevenlabs' => $cambios + [
            'enabled' => true, 'api_key' => self::CLAVE, 'agent_id' => 'agent_verify', 'webhook_secret' => 'whsec', 'region' => 'default',
            'agent_phone_number_id' => null, 'tool_secret' => null, 'initiation_secret' => null, 'base_url' => 'http://127.0.0.1:8096', 'timeout_seconds' => 10,
        ]]);
    }

    public function test_sin_configuracion_no_sale_nada(): void
    {
        self::freshFake();
        $this->configurar(['enabled' => false]);
        $this->assertSame('not_configured', (new Emisor)->signedSessionUrl('agent_lab_0001')->outcome);
        Http::assertNothingSent();
    }

    public function test_emite_la_url_del_mismo_host_y_no_la_ensena(): void
    {
        $this->app['env'] = 'production';
        self::freshFake(['https://api.elevenlabs.io/v1/convai/conversation/get-signed-url*' => Http::response(['signed_url' => 'wss://api.elevenlabs.io/v1/convai/conversation?agent_id=agent_lab_0001&'.self::FIRMA], 200)]);

        $s = (new Emisor)->signedSessionUrl('agent_lab_0001');

        $this->assertSame(['issued', 'agent_lab_0001', 200], [$s->outcome, $s->agentId, $s->httpStatus]);
        $this->assertStringContainsString(self::FIRMA, (string) $s->signedUrl());
        Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET'
            && $r->url() === 'https://api.elevenlabs.io/v1/convai/conversation/get-signed-url?agent_id=agent_lab_0001'
            && $r->header('xi-api-key') === [self::CLAVE]);

        ob_start();
        var_dump($s);
        $volcado = (string) ob_get_clean();
        foreach ([json_encode($s->toArray()), print_r($s, true), $volcado] as $salida) {
            $this->assertStringNotContainsString('CENTINELA-FIRMA', (string) $salida);
            $this->assertStringNotContainsString(self::CLAVE, (string) $salida);
        }
        $this->assertSame(900, $s->toArray()['maxAgeSeconds']);

        $this->expectException(\Exception::class);
        serialize($s);
    }

    public function test_una_url_de_otro_host_o_sin_wss_no_se_entrega(): void
    {
        $this->app['env'] = 'production';
        foreach (['wss://evil.test/v1/convai/conversation?x=1', 'https://api.elevenlabs.io/v1/convai/conversation', 'ws://api.elevenlabs.io/v1/convai/conversation', 'wss://user:pw@api.elevenlabs.io/x', '', 42] as $url) {
            self::freshFake(['*' => Http::response(['signed_url' => $url], 200)]);
            $s = (new Emisor)->signedSessionUrl('agent_lab_0001');
            $this->assertSame('error', $s->outcome, (string) json_encode($url));
            $this->assertNull($s->signedUrl());
        }
    }

    public function test_los_fallos(): void
    {
        foreach ([[404, 'not_found'], [401, 'auth_failed'], [403, 'auth_failed'], [422, 'rejected'], [500, 'error']] as [$estado, $resultado]) {
            self::freshFake(['*' => Http::response(['detail' => 'x'], $estado)]);
            $this->assertSame($resultado, (new Emisor)->signedSessionUrl('agent_lab_0001')->outcome, (string) $estado);
        }
        self::freshFake(static fn (Request $r) => Create::rejectionFor(new ConnectException('sin red', new Psr7Request('GET', $r->url()))));
        $this->assertSame('unreachable', (new Emisor)->signedSessionUrl('agent_lab_0001')->outcome);

        self::freshFake();
        $this->assertSame('rejected', (new Emisor)->signedSessionUrl("agent\n")->outcome);
        Http::assertNothingSent();
    }

    public function test_el_simulador_local_admite_ws_de_su_mismo_host(): void
    {
        $this->assertTrue(Emisor::sameHostWebSocket('ws://127.0.0.1:8096/v1/convai/conversation?x=1', 'http://127.0.0.1:8096'));
        $this->assertTrue(Emisor::sameHostWebSocket('wss://127.0.0.1:8096/v1/convai/conversation', 'http://127.0.0.1:8096'));
        $this->assertFalse(Emisor::sameHostWebSocket('ws://127.0.0.2:8096/x', 'http://127.0.0.1:8096'));
        $this->assertFalse(Emisor::sameHostWebSocket('ws://api.elevenlabs.io/x', 'https://api.elevenlabs.io'));
        $this->assertFalse(Emisor::sameHostWebSocket("wss://api.elevenlabs.io/x\n", 'https://api.elevenlabs.io'));
        $this->assertFalse(Emisor::sameHostWebSocket('wss://api.elevenlabs.io/x#frag', 'https://api.elevenlabs.io'));
    }
}
