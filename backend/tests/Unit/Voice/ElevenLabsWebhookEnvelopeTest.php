<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\ElevenLabs\ElevenLabsWebhookEnvelope as Sobre;
use PHPUnit\Framework\TestCase;

/**
 * El sobre del webhook post-llamada (contrato H6 §I6.4): solo el `type`, y
 * solo si es uno de los tres oficiales. Nada más del cuerpo sale de aquí.
 */
class ElevenLabsWebhookEnvelopeTest extends TestCase
{
    public function test_los_tres_tipos_oficiales(): void
    {
        $this->assertSame(['post_call_transcription', 'post_call_audio', 'call_initiation_failure'], Sobre::TYPES);
        foreach (Sobre::TYPES as $tipo) {
            $this->assertSame($tipo, Sobre::type(json_encode(['type' => $tipo, 'event_timestamp' => 1761955200, 'data' => ['transcript' => [['role' => 'user', 'message' => 'Mi número es 3001234567']]]])));
        }
    }

    public function test_lo_que_no_es_un_tipo_conocido_es_null(): void
    {
        $casos = [
            'JSON no válido' => '{"type":"post_call_audio"',
            'vacío' => '',
            'texto' => 'post_call_audio',
            'sin type' => '{"data":{"type":"post_call_audio"}}',
            'otro tipo' => '{"type":"post_call_video"}',
            'tipo en mayúsculas' => '{"type":"POST_CALL_AUDIO"}',
            'tipo con espacio' => '{"type":" post_call_audio"}',
            'type numérico' => '{"type":1}',
            'type nulo' => '{"type":null}',
            'type lista' => '{"type":["post_call_audio"]}',
            'una lista JSON' => '[{"type":"post_call_audio"}]',
            'objeto vacío' => '{}',
            'escalar JSON' => '"post_call_audio"',
        ];
        foreach ($casos as $nombre => $cuerpo) {
            $this->assertNull(Sobre::type($cuerpo), $nombre);
        }
    }

    public function test_devuelve_solo_el_tipo_y_ningun_otro_dato(): void
    {
        $cuerpo = json_encode(['type' => 'post_call_transcription', 'data' => ['conversation_id' => 'conv_secreta', 'transcript' => 'Llámame al 3001234567']]);

        $tipo = Sobre::type($cuerpo);

        $this->assertSame('post_call_transcription', $tipo);
        $this->assertStringNotContainsString('3001234567', (string) $tipo);
        // La clase no tiene estado donde guardar nada.
        $this->assertSame([], (new \ReflectionClass(Sobre::class))->getProperties());
    }
}
