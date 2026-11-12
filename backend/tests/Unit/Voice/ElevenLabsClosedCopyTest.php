<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentDeployer;
use App\Services\Voice\ElevenLabs\ElevenLabsCallStarter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Voice\Support\ClosedPolicyCopy;
use Tests\Unit\Voice\Support\FreshHttpFake;

/**
 * EL INICIADOR Y EL DESPLEGADOR CON LA CONSTANTE CERRADA, sobre su copia
 * cerrada en memoria (C-4; {@see ClosedPolicyCopy}): lo que afirmaban
 * `ElevenLabsCallStarterTest` y `ElevenLabsAgentDeployerTest` antes del commit
 * `voice(calls-open)`, que es el destino de la vuelta atrás.
 *
 * LO QUE SE PROTEGE.
 *  - Cerrado, el iniciador NO SALE A LA RED aunque la configuración esté
 *    completa (`not_attempted`, `calls_disabled_in_code`); con la MISMA
 *    configuración, el iniciador del árbol (abierto) sí sale: lo único que
 *    cambia es la constante.
 *  - Cerrado, asignar un agente al número no sale (`not_attempted`,
 *    `calls_disabled_in_code`); dejarlo sin agente, sí (un PATCH).
 */
class ElevenLabsClosedCopyTest extends TestCase
{
    use FreshHttpFake;

    private const SIM = 'http://127.0.0.1:8096';

    private const REF = '9a7c1e2b-3d4f-4a5b-8c6d-7e8f9a0b1c2d';

    protected function setUp(): void
    {
        parent::setUp();
        ClosedPolicyCopy::load();
        config(['voice.elevenlabs' => [
            'enabled' => true, 'api_key' => 'xi-centinela-COPIA-0123456789', 'agent_id' => 'agent_verify', 'webhook_secret' => 'whsec', 'region' => 'default',
            'agent_phone_number_id' => 'phnum_0001', 'tool_secret' => 't', 'initiation_secret' => 'i', 'base_url' => self::SIM, 'timeout_seconds' => 10,
        ]]);
    }

    private static function peticion(): OutboundCallRequest
    {
        return new OutboundCallRequest('agent_sealed_0001', 'phnum_0001', '+573000000042', self::REF, [
            'call_direction' => 'saliente', 'call_ref' => self::REF, 'caller_authorized' => 'si', 'version_label' => 'v12',
        ], 30, 'hang_up');
    }

    public function test_cerrado_el_iniciador_no_sale_aunque_todo_este_configurado_y_abierto_si(): void
    {
        $respuesta = ['*' => Http::response(['success' => true, 'conversation_id' => 'conv_1', 'callSid' => 'CA'.str_repeat('a', 32)], 200)];

        /** @var class-string<ElevenLabsCallStarter> $cerrado */
        $cerrado = ClosedPolicyCopy::STARTER;
        self::freshFake($respuesta);
        $r = (new $cerrado)->startOutbound(self::peticion());
        $this->assertSame(['not_attempted', 'calls_disabled_in_code', null, null, null], [$r->outcome, $r->reason, $r->conversationId, $r->callSid, $r->httpStatus]);
        Http::assertNothingSent();

        // La misma configuración y la misma petición con el iniciador del árbol: sale una.
        self::freshFake($respuesta);
        $this->assertSame('started', (new ElevenLabsCallStarter)->startOutbound(self::peticion())->outcome);
        Http::assertSentCount(1);
    }

    public function test_cerrado_asignar_un_agente_no_sale_y_quitarlo_si(): void
    {
        /** @var class-string<ElevenLabsAgentDeployer> $cerrado */
        $cerrado = ClosedPolicyCopy::DEPLOYER;
        $d = new $cerrado;

        self::freshFake(['*' => Http::response(['phone_number_id' => 'phnum_0001', 'assigned_agent' => ['agent_id' => 'agent_new_0001', 'agent_name' => 'x']], 200)]);
        $r = $d->assignNumber('phnum_0001', 'agent_new_0001');
        $this->assertSame(['not_attempted', 'calls_disabled_in_code'], [$r->outcome, $r->reason]);
        Http::assertNothingSent();

        self::freshFake(['*' => Http::response(['phone_number_id' => 'phnum_0001', 'assigned_agent' => null], 200)]);
        $r = $d->assignNumber('phnum_0001', null);
        $this->assertSame(['assigned', null, null], [$r->outcome, $r->agentId, $r->reason]);
        Http::assertSentCount(1);
        [$peticion] = Http::recorded()[0];
        $this->assertSame(['PATCH', self::SIM.'/v1/convai/phone-numbers/phnum_0001', ['agent_id' => null]], [$peticion->method(), $peticion->url(), $peticion->data()]);
    }
}
