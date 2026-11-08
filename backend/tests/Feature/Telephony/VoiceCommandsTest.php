<?php

namespace Tests\Feature\Telephony;

use App\Models\Voice\VoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * Los comandos de la telefonía (contrato H8, D8-20 nivel 0b y D8-21):
 * `voice:hangup` (el corte; `--all` exige `--confirm`) y `voice:doctor
 * --telefonia` (el estado sin red ni secretos; la salida por defecto del H6 no
 * cambia: la fija `VoiceDoctorCommandTest`). Sin red.
 */
class VoiceCommandsTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld(['voice.elevenlabs.api_key' => 'xi-centinela-API-0123456789abcdef']);
    }

    public function test_voice_hangup_valida_sus_argumentos_y_cancela_las_pedidas_sin_red(): void
    {
        $this->assertSame(1, Artisan::call('voice:hangup'), 'ni uuid ni --all');
        $this->assertSame(1, Artisan::call('voice:hangup', ['--all' => true]), '--all sin --confirm');
        $this->assertSame(0, Artisan::call('voice:hangup', ['--all' => true, '--confirm' => true]));
        $this->assertStringContainsString('No hay llamadas en curso', Artisan::output());

        $pedida = $this->voiceCall('outbound', 'requested');
        $viva = $this->voiceCall('outbound', 'dialing', ['remote' => '+573000000061']);
        $this->assertSame(1, Artisan::call('voice:hangup', ['--all' => true, '--confirm' => true]), 'la viva no se puede colgar sin la credencial de la subcuenta');
        $salida = Artisan::output();
        $this->assertSame('canceled', VoiceCall::query()->findOrFail($pedida->id)->status);
        $this->assertSame('dialing', VoiceCall::query()->findOrFail($viva->id)->status);
        $this->assertStringContainsString($pedida->uuid, $salida);
        $this->assertStringNotContainsString('573000000', $salida);
        $this->assertSame(1, Artisan::call('voice:hangup', ['call' => '00000000-0000-4000-8000-000000000000']), 'una llamada que no existe');
        Http::assertNothingSent();
    }

    public function test_voice_doctor_telefonia_por_nombre_y_sin_valores(): void
    {
        $this->publishVoice();
        $this->assertSame(0, Artisan::call('voice:doctor', ['--telefonia' => true]));
        $texto = Artisan::output();
        foreach (['Voz · telefonía (H8)', 'Constante  : cerrada', 'Cadena     : canary ok · seq 1', 'Cadena     : rest none', 'Freno      : suelto'] as $esperado) {
            $this->assertStringContainsString($esperado, $texto);
        }
        $this->assertSame(0, Artisan::call('voice:doctor', ['--telefonia' => true, '--json' => true]));
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['callsEnabledInCode', 'flags', 'scopes', 'provider', 'number', 'carrierConfigured', 'publication', 'brakeOpen', 'limits', 'schedule', 'facts', 'transferDirectory', 'today'], array_keys($json['telefonia']));
        $this->assertFalse($json['telefonia']['callsEnabledInCode']);
        foreach (['xi-centinela', self::INITIATION_SECRET, self::TOOL_SECRET, self::WEBHOOK_SECRET, '+576010000090', '576010000090'] as $s) {
            $this->assertStringNotContainsString($s, $texto.json_encode($json), "la salida lleva «{$s}»");
        }
        Http::assertNothingSent();
    }
}
