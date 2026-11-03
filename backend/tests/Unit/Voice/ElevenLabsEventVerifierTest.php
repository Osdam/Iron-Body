<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\ElevenLabs\ElevenLabsEventVerifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El verificador del webhook post-llamada (contrato H8, D8-3, D8-10 capa 4 y
 * D8-16). Sin red: `Http::preventStrayRequests()` lo demuestra.
 *
 * LO QUE SE PROTEGE.
 *  - Sin secreto, ninguna firma vale (`not_configured`).
 *  - La firma, sobre el cuerpo crudo, con sus seis resultados; el tope del cuerpo.
 *  - Un sobre firmado que no se entiende se acepta y se ignora (D8-16).
 *  - Del cuerpo solo sale lo que enumera el contrato: NUNCA la transcripción de
 *    la persona, el audio, los teléfonos (los del sobre de Twilio tampoco), las
 *    variables dinámicas (salvo `call_ref`) ni el análisis; los turnos del
 *    agente y los destinos pedidos solo en memoria, y ni `toArray()`, ni
 *    `var_dump()`, ni `print_r()` los enseñan.
 */
class ElevenLabsEventVerifierTest extends TestCase
{
    private const SECRETO = 'whsec-centinela-POSTCALL';

    private const AHORA = 1762204800;

    private const PERSONA = 'Mi cédula es 1234567890 y vivo en la calle 5';

    private const SU_NUMERO = '+573000000077';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        config(['voice.elevenlabs.webhook_secret' => self::SECRETO]);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private static function firma(string $cuerpo, int $t = self::AHORA): string
    {
        return 't='.$t.',v0='.hash_hmac('sha256', $t.'.'.$cuerpo, self::SECRETO);
    }

    private static function transcripcion(): string
    {
        return (string) json_encode([
            'type' => 'post_call_transcription',
            'event_timestamp' => self::AHORA,
            'data' => [
                'agent_id' => 'agent_sealed_0001',
                'agent_name' => 'Iron Body · voz',
                'conversation_id' => 'conv_0001',
                'status' => 'done',
                'user_id' => '9a7c1e2b-3d4f-4a5b-8c6d-7e8f9a0b1c2d',
                'version_id' => 'agtvrsn_0001',
                'branch_id' => 'agtbrch_0001',
                'environment' => 'production',
                'transcript' => [
                    ['role' => 'agent', 'message' => 'Hola, te habla el asistente virtual.', 'time_in_call_secs' => 0],
                    ['role' => 'user', 'message' => self::PERSONA, 'time_in_call_secs' => 4],
                    ['role' => 'agent', 'message' => 'Te paso con recepción.', 'tool_calls' => [
                        ['type' => 'webhook', 'tool_name' => 'solicitar_humano', 'params_as_json' => '{}'],
                        ['type' => 'system', 'tool_name' => 'transfer_to_number', 'params_as_json' => (string) json_encode(['transfer_number' => '+576010000001', 'client_message' => 'Un momento'])],
                    ]],
                ],
                'metadata' => [
                    'start_time_unix_secs' => self::AHORA - 120,
                    'call_duration_secs' => 118,
                    'cost' => 940.0,
                    'termination_reason' => 'Llamada terminada por '.self::SU_NUMERO,
                    'phone_call' => ['direction' => 'outbound', 'external_number' => self::SU_NUMERO, 'agent_number' => '+576010000009', 'call_sid' => 'CA'.str_repeat('d', 32)],
                ],
                'analysis' => ['transcript_summary' => 'La persona dijo: '.self::PERSONA],
                'conversation_initiation_client_data' => ['dynamic_variables' => [
                    'call_ref' => '9a7c1e2b-3d4f-4a5b-8c6d-7e8f9a0b1c2d', 'caller_authorized' => 'si', 'system__caller_id' => self::SU_NUMERO,
                ]],
                'has_audio' => false,
            ],
        ]);
    }

    public function test_sin_secreto_ninguna_firma_vale(): void
    {
        config(['voice.elevenlabs.webhook_secret' => '  ']);
        $cuerpo = self::transcripcion();
        $v = (new ElevenLabsEventVerifier)->verifyPostCall($cuerpo, self::firma($cuerpo), self::AHORA);
        $this->assertSame(['not_configured', 'not_configured', null], [$v->outcome, $v->reason, $v->kind]);
    }

    public function test_los_resultados_de_la_firma_y_el_tope(): void
    {
        $cuerpo = self::transcripcion();
        $v = new ElevenLabsEventVerifier;

        $this->assertSame('missing_signature', $v->verifyPostCall($cuerpo, null, self::AHORA)->reason);
        $this->assertSame('malformed_signature', $v->verifyPostCall($cuerpo, 'v0=abc', self::AHORA)->reason);
        $this->assertSame('expired', $v->verifyPostCall($cuerpo, self::firma($cuerpo, self::AHORA - 1801), self::AHORA)->reason);
        $this->assertSame('from_future', $v->verifyPostCall($cuerpo, self::firma($cuerpo, self::AHORA + 301), self::AHORA)->reason);
        $this->assertSame('mismatch', $v->verifyPostCall($cuerpo.' ', self::firma($cuerpo), self::AHORA)->reason, 'sobre el cuerpo crudo: un byte más no vale');
        foreach (['missing_signature', 'malformed_signature'] as $_) {
            $this->assertSame('rejected', $v->verifyPostCall($cuerpo, null, self::AHORA)->outcome);
        }

        $grande = str_repeat(' ', ElevenLabsEventVerifier::MAX_BODY_BYTES + 1);
        $r = $v->verifyPostCall($grande, self::firma($grande), self::AHORA);
        $this->assertSame(['rejected', 'too_large'], [$r->outcome, $r->reason]);
    }

    public function test_una_transcripcion_firmada_da_solo_lo_que_enumera_el_contrato(): void
    {
        $cuerpo = self::transcripcion();
        $v = (new ElevenLabsEventVerifier)->verifyPostCall($cuerpo, self::firma($cuerpo), self::AHORA);

        $this->assertSame(['accepted', 'ok', 'post_call_transcription'], [$v->outcome, $v->reason, $v->kind]);
        $this->assertSame(self::AHORA, $v->eventTimestamp);
        $this->assertSame(['agent_sealed_0001', 'conv_0001', 'agtvrsn_0001', 'agtbrch_0001'], [$v->agentId, $v->conversationId, $v->versionId, $v->branchId]);
        $this->assertSame('CA'.str_repeat('d', 32), $v->callSid);
        $this->assertSame('9a7c1e2b-3d4f-4a5b-8c6d-7e8f9a0b1c2d', $v->callRef);
        $this->assertSame([118, 940, false], [$v->durationSeconds, $v->cost, $v->hasAudio]);
        $this->assertSame(['solicitar_humano', 'transfer_to_number'], $v->toolCalls);
        $this->assertSame(['Hola, te habla el asistente virtual.', 'Te paso con recepción.'], $v->agentTurns(), 'solo los turnos del agente, en memoria');
        $this->assertSame(['+576010000001'], $v->transferTargets());

        // Nada de la persona, ni su número, ni el resumen, ni las variables.
        foreach ([json_encode($v->toArray()), print_r($v, true), self::dump($v)] as $salida) {
            $this->assertStringNotContainsString('1234567890', (string) $salida);
            $this->assertStringNotContainsString(self::SU_NUMERO, (string) $salida);
            $this->assertStringNotContainsString('calle 5', (string) $salida);
        }
        foreach ([json_encode($v->toArray()), print_r($v, true), self::dump($v)] as $salida) {
            $this->assertStringNotContainsString('Te paso con recepción', (string) $salida, 'los turnos del agente tampoco salen de la memoria');
            $this->assertStringNotContainsString('+576010000001', (string) $salida);
        }
        $this->assertSame(2, $v->toArray()['agentTurnCount']);
    }

    public function test_un_fallo_de_inicio_da_el_motivo_y_el_sid_y_nada_del_sobre_de_twilio(): void
    {
        $cuerpo = (string) json_encode(['type' => 'call_initiation_failure', 'event_timestamp' => self::AHORA, 'data' => [
            'agent_id' => 'agent_sealed_0001', 'conversation_id' => 'conv_0002', 'failure_reason' => 'no-answer',
            'metadata' => ['type' => 'twilio', 'body' => ['CallSid' => 'CA'.str_repeat('e', 32), 'CallStatus' => 'no-answer', 'To' => self::SU_NUMERO, 'From' => '+576010000009']],
        ]]);
        $v = (new ElevenLabsEventVerifier)->verifyPostCall($cuerpo, self::firma($cuerpo), self::AHORA);

        $this->assertSame(['accepted', 'call_initiation_failure', 'no-answer', 'CA'.str_repeat('e', 32), 'conv_0002'], [$v->outcome, $v->kind, $v->failureReason, $v->callSid, $v->conversationId]);
        $this->assertStringNotContainsString(self::SU_NUMERO, (string) json_encode($v->toArray()));
        $this->assertSame([], $v->agentTurns());

        $raro = (string) json_encode(['type' => 'call_initiation_failure', 'data' => ['failure_reason' => 'otra-cosa']]);
        $this->assertNull((new ElevenLabsEventVerifier)->verifyPostCall($raro, self::firma($raro), self::AHORA)->failureReason);
    }

    public function test_el_audio_se_acepta_para_descartarlo_y_no_sale_nada_de_el(): void
    {
        $audio = base64_encode(str_repeat('ID3-AUDIO-CENTINELA', 50));
        $cuerpo = (string) json_encode(['type' => 'post_call_audio', 'event_timestamp' => self::AHORA, 'data' => ['agent_id' => 'agent_sealed_0001', 'conversation_id' => 'conv_0003', 'full_audio' => $audio]]);
        $v = (new ElevenLabsEventVerifier)->verifyPostCall($cuerpo, self::firma($cuerpo), self::AHORA);

        $this->assertSame(['accepted', 'post_call_audio', 'conv_0003'], [$v->outcome, $v->kind, $v->conversationId]);
        $this->assertStringNotContainsString(substr($audio, 0, 40), (string) json_encode($v->toArray()).print_r($v, true));
    }

    public function test_un_sobre_firmado_que_no_se_entiende_se_acepta_y_se_ignora(): void
    {
        foreach (['{"type":"conversation_started","data":{"conversation_id":"conv_9"}}', 'no es json', '[1,2,3]', '{"data":{}}'] as $cuerpo) {
            $v = (new ElevenLabsEventVerifier)->verifyPostCall($cuerpo, self::firma($cuerpo), self::AHORA);
            $this->assertSame(['accepted', 'unknown', null], [$v->outcome, $v->kind, $v->conversationId], $cuerpo);
        }
    }

    public function test_los_ids_sin_forma_se_descartan(): void
    {
        $cuerpo = (string) json_encode(['type' => 'post_call_transcription', 'data' => [
            'agent_id' => "agent\n1", 'conversation_id' => str_repeat('x', 200), 'version_id' => '../v', 'user_id' => 'no-uuid',
            'metadata' => ['call_duration_secs' => -5, 'cost' => 'mucho', 'phone_call' => ['call_sid' => 'CA123']],
            'transcript' => 'no es una lista',
        ]]);
        $v = (new ElevenLabsEventVerifier)->verifyPostCall($cuerpo, self::firma($cuerpo), self::AHORA);

        $this->assertSame('accepted', $v->outcome);
        $this->assertSame([null, null, null, null, null, null, null], [$v->agentId, $v->conversationId, $v->versionId, $v->callRef, $v->callSid, $v->durationSeconds, $v->cost]);
        $this->assertSame([], $v->agentTurns());
    }

    private static function dump(object $o): string
    {
        ob_start();
        var_dump($o);

        return (string) ob_get_clean();
    }
}
