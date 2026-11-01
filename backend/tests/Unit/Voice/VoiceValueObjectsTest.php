<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\VoiceCheckResult;
use App\Services\Voice\VoiceProviderStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Los valores del puerto de voz (contrato H6 §I6.1): las formas de `toArray()`
 * con sus claves en orden, y que nada fuera de los conjuntos cerrados de §I2
 * (ni un estado imposible) llegue a construirse.
 */
class VoiceValueObjectsTest extends TestCase
{
    public function test_el_estado_de_produccion_del_h6(): void
    {
        $s = new VoiceProviderStatus('none', 'ELEVENLABS_NOT_CONFIGURED', false, ['api_key', 'agent_id', 'webhook_secret'], 'default');

        $this->assertSame(
            ['provider' => 'none', 'state' => 'ELEVENLABS_NOT_CONFIGURED', 'enabled' => false, 'missing' => ['api_key', 'agent_id', 'webhook_secret'], 'region' => 'default'],
            $s->toArray(),
        );
        $this->assertSame(['provider', 'state', 'enabled', 'missing', 'region'], array_keys($s->toArray()));
    }

    public function test_los_tres_estados_que_puede_llevar(): void
    {
        $this->assertSame(['ELEVENLABS_NOT_CONFIGURED', 'ELEVENLABS_DISABLED', 'ELEVENLABS_UNVERIFIED'], VoiceProviderStatus::STATES);
        $this->assertSame(['api_key', 'agent_id', 'webhook_secret', 'region'], VoiceProviderStatus::PIECES);

        $this->assertSame('ELEVENLABS_DISABLED', (new VoiceProviderStatus('none', 'ELEVENLABS_DISABLED', false, [], 'eu'))->state);
        $this->assertSame('elevenlabs', (new VoiceProviderStatus('elevenlabs', 'ELEVENLABS_UNVERIFIED', true, [], 'default'))->provider);
        $this->assertNull((new VoiceProviderStatus('none', 'ELEVENLABS_NOT_CONFIGURED', true, ['region'], null))->region);
    }

    public function test_un_estado_imposible_no_se_construye(): void
    {
        $malos = [
            'proveedor desconocido' => ['openai', 'ELEVENLABS_NOT_CONFIGURED', false, ['api_key'], 'default'],
            'VERIFIED no sale de aquí' => ['elevenlabs', 'ELEVENLABS_VERIFIED', true, [], 'default'],
            'estado desconocido' => ['none', 'NOT_CONFIGURED', false, ['api_key'], 'default'],
            'piezas desordenadas' => ['none', 'ELEVENLABS_NOT_CONFIGURED', false, ['agent_id', 'api_key'], 'default'],
            'pieza repetida' => ['none', 'ELEVENLABS_NOT_CONFIGURED', false, ['api_key', 'api_key'], 'default'],
            'pieza desconocida' => ['none', 'ELEVENLABS_NOT_CONFIGURED', false, ['voice_id'], 'default'],
            'piezas con claves' => ['none', 'ELEVENLABS_NOT_CONFIGURED', false, ['x' => 'api_key'], 'default'],
            'región fuera de la lista' => ['none', 'ELEVENLABS_DISABLED', false, [], 'us'],
            'región nula sin que falte' => ['none', 'ELEVENLABS_DISABLED', false, [], null],
            'falta la región pero viene una' => ['none', 'ELEVENLABS_NOT_CONFIGURED', false, ['region'], 'eu'],
            'NOT_CONFIGURED sin piezas que falten' => ['none', 'ELEVENLABS_NOT_CONFIGURED', false, [], 'default'],
            'DISABLED con piezas que faltan' => ['none', 'ELEVENLABS_DISABLED', false, ['api_key'], 'default'],
            'DISABLED con la bandera encendida' => ['none', 'ELEVENLABS_DISABLED', true, [], 'default'],
            'UNVERIFIED con la bandera apagada' => ['elevenlabs', 'ELEVENLABS_UNVERIFIED', false, [], 'default'],
            'UNVERIFIED con el proveedor nulo' => ['none', 'ELEVENLABS_UNVERIFIED', true, [], 'default'],
            'elevenlabs sin estar UNVERIFIED' => ['elevenlabs', 'ELEVENLABS_DISABLED', false, [], 'default'],
        ];
        foreach ($malos as $nombre => [$p, $e, $b, $m, $r]) {
            try {
                new VoiceProviderStatus($p, $e, $b, $m, $r);
                $this->fail("{$nombre}: tenía que rechazarse");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_el_resultado_de_la_verificacion(): void
    {
        $this->assertSame(['credentials_ok', 'invalid_credentials', 'agent_not_found', 'not_configured', 'unreachable', 'error'], VoiceCheckResult::OUTCOMES);

        $r = new VoiceCheckResult('credentials_ok', 200, true, 87);
        $this->assertSame(['outcome' => 'credentials_ok', 'httpStatus' => 200, 'agentAudioUlaw8000' => true, 'latencyMs' => 87], $r->toArray());
        $this->assertSame(['outcome', 'httpStatus', 'agentAudioUlaw8000', 'latencyMs'], array_keys($r->toArray()));

        $this->assertSame(['outcome' => 'not_configured', 'httpStatus' => null, 'agentAudioUlaw8000' => null, 'latencyMs' => 0], (new VoiceCheckResult('not_configured', null, null, 0))->toArray());
    }

    public function test_un_resultado_imposible_no_se_construye(): void
    {
        $malos = [
            'resultado desconocido' => ['ok', 200, null, 10],
            'estado HTTP por debajo de 100' => ['error', 99, null, 10],
            'estado HTTP por encima de 599' => ['error', 600, null, 10],
            'latencia negativa' => ['unreachable', null, null, -1],
            'formato de audio sin credentials_ok' => ['agent_not_found', 404, false, 10],
        ];
        foreach ($malos as $nombre => [$o, $h, $a, $l]) {
            try {
                new VoiceCheckResult($o, $h, $a, $l);
                $this->fail("{$nombre}: tenía que rechazarse");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
