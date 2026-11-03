<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\ElevenLabs\ElevenLabsCallStarter;
use App\Services\Voice\VoiceCallPolicy;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Voice\Support\FreshHttpFake;

/**
 * El iniciador de salientes de ElevenLabs (contrato H8, D8-3 y D8-12).
 *
 * LO QUE SE PROTEGE.
 *  - CON LAS LLAMADAS CERRADAS EN EL CÓDIGO NO SALE NADA, aunque la
 *    configuración esté completa y la clase se instancie a mano.
 *  - El cuerpo (puro): el agente sellado, el número importado, el destino, la
 *    grabación apagada en los dos sitios [P-7], la detección de contestador
 *    según el buzón [P-10], `user_id = call_ref` y las variables de la puerta.
 *  - La tabla de resultados (pura): `started` solo con éxito y los dos ids;
 *    nunca un «no se marcó» cuando no se sabe (`ambiguous`).
 *  - Ningún resultado lleva el número.
 *
 * El camino con red (la constante girada) lo prueba el banco en una copia del
 * árbol (TEST8), nunca aquí: la constante no se gira en el árbol compartido.
 */
class ElevenLabsCallStarterTest extends TestCase
{
    use FreshHttpFake;

    private const DESTINO = '+573000000042';

    private const REF = '9a7c1e2b-3d4f-4a5b-8c6d-7e8f9a0b1c2d';

    private static function peticion(string $buzon = 'hang_up'): OutboundCallRequest
    {
        return new OutboundCallRequest('agent_sealed_0001', 'phnum_0001', self::DESTINO, self::REF, [
            'call_direction' => 'saliente', 'call_ref' => self::REF, 'caller_authorized' => 'si', 'version_label' => 'v12',
        ], 30, $buzon);
    }

    public function test_con_las_llamadas_cerradas_en_el_codigo_no_sale_nada_aunque_todo_este_configurado(): void
    {
        $this->assertFalse(VoiceCallPolicy::CALLS_ENABLED_IN_CODE, 'esta prueba describe el árbol compartido');
        config(['voice.elevenlabs' => [
            'enabled' => true, 'api_key' => 'xi-centinela', 'agent_id' => 'agent_verify', 'webhook_secret' => 'whsec', 'region' => 'default',
            'agent_phone_number_id' => 'phnum_0001', 'tool_secret' => 't', 'initiation_secret' => 'i', 'base_url' => 'http://127.0.0.1:8096', 'timeout_seconds' => 10,
        ]]);
        self::freshFake(['*' => Http::response(['success' => true, 'conversation_id' => 'conv_1', 'callSid' => 'CA'.str_repeat('a', 32)], 200)]);

        $r = (new ElevenLabsCallStarter)->startOutbound(self::peticion());

        $this->assertSame(['not_attempted', 'calls_disabled_in_code', null, null, null], [$r->outcome, $r->reason, $r->conversationId, $r->callSid, $r->httpStatus]);
        Http::assertNothingSent();
    }

    public function test_el_cuerpo_de_la_peticion(): void
    {
        $colgar = ElevenLabsCallStarter::requestBody(self::peticion());
        $this->assertSame([
            'agent_id' => 'agent_sealed_0001',
            'agent_phone_number_id' => 'phnum_0001',
            'to_number' => self::DESTINO,
            'call_recording_enabled' => false,
            'telephony_call_config' => [
                'ringing_timeout_secs' => 30,
                'twilio_call_recording_enabled' => false,
                'twilio_machine_detection' => ['mode' => 'enable', 'actions' => [['action' => 'hang_up', 'answered_by' => ['fax', 'machine_start']]]],
            ],
            'conversation_initiation_client_data' => [
                'user_id' => self::REF,
                'dynamic_variables' => ['call_direction' => 'saliente', 'call_ref' => self::REF, 'caller_authorized' => 'si', 'version_label' => 'v12'],
            ],
        ], $colgar);
        $this->assertArrayNotHasKey('conversation_config_override', $colgar['conversation_initiation_client_data'], 'sin sobrescrituras (D8-11)');

        $dejar = ElevenLabsCallStarter::requestBody(self::peticion('leave_message'));
        $this->assertNull($dejar['telephony_call_config']['twilio_machine_detection'], 'dejar el mensaje = sin detección de contestador');
    }

    /** @return iterable<string, array{0:int, 1:string, 2:string, 3:?string, 4:?string, 5:?int}> */
    public static function tabla(): iterable
    {
        $sid = 'CA'.str_repeat('b', 32);
        $ok = json_encode(['success' => true, 'message' => 'ok', 'conversation_id' => 'conv_0001', 'callSid' => $sid]);
        yield '200 con éxito y los dos ids' => [200, (string) $ok, 'started', null, 'conv_0001', $sid];
        yield '201 con éxito y los dos ids' => [201, (string) $ok, 'started', null, 'conv_0001', $sid];
        yield '200 sin éxito' => [200, (string) json_encode(['success' => false, 'message' => 'Twilio error', 'conversation_id' => null, 'callSid' => null]), 'rejected', 'provider_declined', null, null];
        yield '200 sin éxito con ids' => [200, (string) json_encode(['success' => false, 'message' => 'x', 'conversation_id' => 'conv_2', 'callSid' => $sid]), 'rejected', 'provider_declined', 'conv_2', $sid];
        yield '200 con éxito sin callSid' => [200, (string) json_encode(['success' => true, 'message' => 'ok', 'conversation_id' => 'conv_3', 'callSid' => null]), 'ambiguous', 'missing_ids', 'conv_3', null];
        yield '200 con un callSid sin forma' => [200, (string) json_encode(['success' => true, 'message' => 'ok', 'conversation_id' => 'conv_4', 'callSid' => 'CAxyz']), 'ambiguous', 'missing_ids', 'conv_4', null];
        yield '200 con success como texto' => [200, (string) json_encode(['success' => 'true', 'conversation_id' => 'conv_5', 'callSid' => $sid]), 'ambiguous', 'missing_ids', 'conv_5', $sid];
        yield '200 que no es JSON' => [200, '<html>ok</html>', 'ambiguous', 'unreadable_response', null, null];
        yield '200 con una lista JSON' => [200, '[1,2]', 'ambiguous', 'unreadable_response', null, null];
        yield '408' => [408, '', 'ambiguous', 'server_error', null, null];
        yield '500' => [500, '{"detail":"boom"}', 'ambiguous', 'server_error', null, null];
        yield '503' => [503, '', 'ambiguous', 'server_error', null, null];
        yield '400' => [400, '{"detail":"bad"}', 'rejected', 'client_error', null, null];
        yield '401' => [401, '', 'rejected', 'client_error', null, null];
        yield '404' => [404, '', 'rejected', 'client_error', null, null];
        yield '422' => [422, '{"detail":[{"msg":"to_number"}]}', 'rejected', 'client_error', null, null];
        yield '429' => [429, '', 'rejected', 'client_error', null, null];
        yield '302 (no se sigue)' => [302, '', 'ambiguous', 'unexpected', null, null];
        yield 'un estado imposible' => [999, '', 'ambiguous', 'unexpected', null, null];
    }

    #[DataProvider('tabla')]
    public function test_la_tabla_de_resultados(int $estado, string $cuerpo, string $resultado, ?string $motivo, ?string $conversacion, ?string $sid): void
    {
        $r = ElevenLabsCallStarter::resultFrom($estado, $cuerpo);

        $this->assertSame([$resultado, $motivo, $conversacion, $sid], [$r->outcome, $r->reason, $r->conversationId, $r->callSid]);
        $this->assertSame($estado >= 100 && $estado <= 599 ? $estado : null, $r->httpStatus);
    }

    public function test_ningun_resultado_lleva_el_numero(): void
    {
        $r = ElevenLabsCallStarter::resultFrom(200, (string) json_encode(['success' => true, 'conversation_id' => 'conv_1', 'callSid' => 'CA'.str_repeat('c', 32), 'to' => self::DESTINO]));
        $this->assertStringNotContainsString(self::DESTINO, (string) json_encode($r->toArray()));
        $this->assertStringNotContainsString(self::DESTINO, (string) json_encode(self::peticion()->toArray()));
        $this->assertSame('0042', self::peticion()->toArray()['toLast4']);
    }
}
