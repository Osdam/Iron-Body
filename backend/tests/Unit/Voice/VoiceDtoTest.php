<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Dto\CarrierCall;
use App\Services\Voice\Dto\CarrierCallsPage;
use App\Services\Voice\Dto\CarrierResult;
use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceAgentTarget;
use App\Services\Voice\Dto\VoiceDeployResult;
use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\Dto\VoiceLabSession;
use App\Services\Voice\Dto\VoiceWebhookVerdict;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentMapper;
use Closure;
use Exception;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Voice\Support\VoiceSpecSamples as M;

/**
 * Las DTO neutras de D8-3 (contrato H8): conjuntos cerrados, coherencia entre
 * campos y, para lo que va en claro (números, URL firmadas, turnos del agente,
 * la carga compilada), que ni `toArray()`, ni `var_dump()`, ni `print_r()` lo
 * enseñan y que `serialize()` se niega. Puras.
 */
class VoiceDtoTest extends TestCase
{
    private const REF = '9a7c1e2b-3d4f-4a5b-8c6d-7e8f9a0b1c2d';

    private const VARIABLES = ['call_direction' => 'saliente', 'call_ref' => self::REF, 'caller_authorized' => 'si', 'version_label' => 'v12'];

    /** @return iterable<string, array{0: Closure(): mixed}> */
    public static function imposibles(): iterable
    {
        $sid = 'CA'.str_repeat('a', 32);
        yield 'target: http hacia un host público' => [static fn () => new VoiceAgentTarget('publication', 'http://api.ironbody.test', 't', 'i', null, 3, 1)];
        yield 'target: base con consulta' => [static fn () => new VoiceAgentTarget('publication', 'https://api.ironbody.test?x=1', 't', 'i', null, 3, 1)];
        yield 'target: propósito desconocido' => [static fn () => new VoiceAgentTarget('prod', M::BASE, 't', 'i', null, 3, 1)];
        yield 'target: diario 0' => [static fn () => new VoiceAgentTarget('publication', M::BASE, 't', 'i', null, 0, 1)];
        yield 'target: concurrencia 5' => [static fn () => new VoiceAgentTarget('publication', M::BASE, 't', 'i', null, 3, 5)];
        yield 'target: referencia con barra' => [static fn () => new VoiceAgentTarget('publication', M::BASE, 'a/b', 'i', null, 3, 1)];
        yield 'target: número sin forma' => [static fn () => new VoiceAgentTarget('publication', M::BASE, 't', 'i', null, 3, 1, [M::REF_A => '3000000001'])];
        yield 'target: número repetido' => [static fn () => new VoiceAgentTarget('publication', M::BASE, 't', 'i', null, 3, 1, [M::REF_A => M::PHONE_A, M::REF_B => M::PHONE_A])];
        yield 'saliente: no autorizada' => [static fn () => new OutboundCallRequest('agent_1', 'phnum_1', '+573000000001', self::REF, ['caller_authorized' => 'no'] + self::VARIABLES, 30, 'hang_up')];
        yield 'saliente: dirección entrante' => [static fn () => new OutboundCallRequest('agent_1', 'phnum_1', '+573000000001', self::REF, ['call_direction' => 'entrante'] + self::VARIABLES, 30, 'hang_up')];
        yield 'saliente: call_ref de otra fila' => [static fn () => new OutboundCallRequest('agent_1', 'phnum_1', '+573000000001', self::REF, ['call_ref' => 'otro'] + self::VARIABLES, 30, 'hang_up')];
        yield 'saliente: una variable de más' => [static fn () => new OutboundCallRequest('agent_1', 'phnum_1', '+573000000001', self::REF, self::VARIABLES + ['greeting' => 'x'], 30, 'hang_up')];
        yield 'saliente: número sin +' => [static fn () => new OutboundCallRequest('agent_1', 'phnum_1', '573000000001', self::REF, self::VARIABLES, 30, 'hang_up')];
        yield 'saliente: 5 s de timbre' => [static fn () => new OutboundCallRequest('agent_1', 'phnum_1', '+573000000001', self::REF, self::VARIABLES, 5, 'hang_up')];
        yield 'saliente: dejar mensaje (E1-8)' => [static fn () => new OutboundCallRequest('agent_1', 'phnum_1', '+573000000001', self::REF, self::VARIABLES, 30, 'leave_message')];
        yield 'resultado: iniciada sin callSid' => [static fn () => new OutboundCallResult('started', null, 'conv_1', null, 200)];
        yield 'resultado: error (no existe: es ambigua)' => [static fn () => new OutboundCallResult('error', null, null, null, null)];
        yield 'resultado: motivo de otro resultado' => [static fn () => new OutboundCallResult('rejected', 'connection', null, null, null)];
        yield 'resultado: sin red con estado' => [static fn () => new OutboundCallResult('not_attempted', 'not_configured', null, null, 200)];
        yield 'despliegue: creado sin id' => [static fn () => new VoiceDeployResult('created', null, [], 200, null)];
        yield 'despliegue: herramienta ajena' => [static fn () => new VoiceDeployResult('created', 'agent_1', ['cobrar' => 'tool_1'], 200, null)];
        yield 'despliegue: sin intento y sin motivo' => [static fn () => new VoiceDeployResult('not_attempted', null, [], null, null)];
        yield 'observación: observada sin proyección' => [static fn () => new VoiceAgentObservation('observed', 'agent_1', null, null, null, 200, null)];
        yield 'veredicto: aceptado sin tipo' => [static fn () => new VoiceWebhookVerdict('accepted', 'ok', null, null, null, null, null, null, null, null, null, null, null, null, [])];
        yield 'veredicto: rechazado con ok' => [static fn () => new VoiceWebhookVerdict('rejected', 'ok', null, null, null, null, null, null, null, null, null, null, null, null, [])];
        yield 'veredicto: turnos en un audio' => [static fn () => new VoiceWebhookVerdict('accepted', 'ok', 'post_call_audio', null, null, null, null, null, null, null, null, null, null, null, [], ['hola'])];
        yield 'veredicto: motivo de fallo fuera de su tipo' => [static fn () => new VoiceWebhookVerdict('accepted', 'ok', 'post_call_transcription', null, null, null, null, null, null, null, null, null, 'busy', null, [])];
        yield 'sesión: emitida sin URL' => [static fn () => new VoiceLabSession('issued', null, 'agent_1', 200)];
        yield 'operador: estado desconocido' => [static fn () => new CarrierResult('requested', $sid, 'answered', 200, null)];
        yield 'llamada: SID sin forma' => [static fn () => new CarrierCall('CA123', null, null, null, null, null, null, null, null, null, null)];
        yield 'llamada: número completo como últimos dígitos' => [static fn () => new CarrierCall($sid, null, null, null, null, null, null, null, null, '+573000000001', null)];
        yield 'página: truncada y con continuación' => [static fn () => new CarrierCallsPage('listed', [], '1:PA1', true, 200)];
        yield 'página: fallida con llamadas' => [static fn () => new CarrierCallsPage('error', [new CarrierCall($sid, null, null, null, null, null, null, null, null, null, null)], null, false, 500)];
    }

    #[DataProvider('imposibles')]
    public function test_lo_imposible_no_se_construye(Closure $intento): void
    {
        $this->expectException(InvalidArgumentException::class);
        $intento();
    }

    public function test_lo_que_va_en_claro_no_se_ve_ni_se_serializa(): void
    {
        $peticion = new OutboundCallRequest('agent_1', 'phnum_1', '+573000000042', self::REF, self::VARIABLES, 30, 'hang_up');
        $carga = ElevenLabsAgentMapper::payload(M::voiceSpec(), M::target());
        $sesion = new VoiceLabSession('issued', 'wss://api.elevenlabs.io/x?conversation_signature=FIRMA-CENTINELA', 'agent_1', 200);
        $veredicto = new VoiceWebhookVerdict('accepted', 'ok', 'post_call_transcription', 1, 'agent_1', 'conv_1', null, null, null, null, 10, 1, null, false, ['transfer_to_number'], ['TURNO-CENTINELA'], [M::PHONE_A]);
        $target = M::target();

        $casos = [[$peticion, '3000000042'], [$carga, '6010000001'], [$carga, 'Eres el asistente'], [$sesion, 'FIRMA-CENTINELA'], [$veredicto, 'TURNO-CENTINELA'], [$veredicto, '6010000001'], [$target, '6010000001']];
        foreach ($casos as [$objeto, $centinela]) {
            ob_start();
            var_dump($objeto);
            $volcado = (string) ob_get_clean();
            foreach ([json_encode($objeto->toArray()), print_r($objeto, true), $volcado] as $salida) {
                $this->assertStringNotContainsString($centinela, (string) $salida, $objeto::class);
            }
            try {
                serialize($objeto);
                $this->fail($objeto::class.' se serializó');
            } catch (Exception) {
                $this->addToAssertionCount(1);
            }
        }

        // Y quien tiene que leerlo, lo lee.
        $this->assertSame('+573000000042', $peticion->toNumber());
        $this->assertSame(['TURNO-CENTINELA'], $veredicto->agentTurns());
        $this->assertSame([M::REF_A => M::PHONE_A, M::REF_B => M::PHONE_B], $target->transferNumbers());
        $this->assertSame([M::REF_A, M::REF_B], $target->toArray()['transferRefs']);
    }

    public function test_la_carga_calcula_sus_huellas_y_no_las_recibe(): void
    {
        $carga = ElevenLabsAgentMapper::payload(M::voiceSpec(), M::target());
        $otra = new VoiceAgentPayload('elevenlabs', ElevenLabsAgentMapper::VERSION, $carga->specHash, 'publication', $carga->agentBody(), $carga->tools(), $carga->projection());
        $this->assertSame([$carga->payloadHash, $carga->projectionHash], [$otra->payloadHash, $otra->projectionHash]);

        $cuerpo = $carga->agentBody();
        $cuerpo['conversation_config']['conversation']['max_duration_seconds'] = 600;
        $cambiada = new VoiceAgentPayload('elevenlabs', ElevenLabsAgentMapper::VERSION, $carga->specHash, 'publication', $cuerpo, $carga->tools(), $carga->projection());
        $this->assertNotSame($carga->payloadHash, $cambiada->payloadHash);

        $observada = new VoiceAgentObservation('observed', 'agent_1', 'agtvrsn_1', null, $carga->projection(), 200, null);
        $this->assertTrue($observada->matches($carga));
        $this->assertFalse((new VoiceAgentObservation('not_found', 'agent_1', null, null, null, 404, null))->matches($carga));
    }

    public function test_los_ids(): void
    {
        $this->assertTrue(VoiceIds::isProviderId('agent_01jx-AB'));
        $this->assertFalse(VoiceIds::isProviderId("agent\n"));
        $this->assertFalse(VoiceIds::isProviderId('../x'));
        $this->assertTrue(VoiceIds::isCallSid('CA'.str_repeat('f', 32)));
        $this->assertFalse(VoiceIds::isCallSid('CA'.str_repeat('F', 32)));
        $this->assertTrue(VoiceIds::isUuid(self::REF));
        $this->assertFalse(VoiceIds::isUuid(strtoupper(self::REF)));
        $this->assertNull(VoiceIds::uuidOrNull(42));
    }
}
