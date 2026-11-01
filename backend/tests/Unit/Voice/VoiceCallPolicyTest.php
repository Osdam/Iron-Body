<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\VoiceCallContext;
use App\Services\Voice\VoiceCallDecision;
use App\Services\Voice\VoiceCallPolicy as Politica;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * La política de llamadas (contrato H6 §I6.5), con casos escritos a mano desde
 * el contrato.
 *
 * LO QUE SE PROTEGE. Que en este hito NUNCA se permite una llamada y que
 * `calls_disabled_in_code` sale siempre el primero; que los motivos se
 * acumulan en el orden de REASONS; que `VERIFIED` no añade motivo; que un
 * marcador nunca consume `marketing_calls` (T-7); y que un valor fuera de los
 * conjuntos cerrados no se cuela.
 */
class VoiceCallPolicyTest extends TestCase
{
    /** @param  array<string,mixed>  $cambios */
    private static function contexto(array $cambios = []): VoiceCallContext
    {
        $c = $cambios + [
            'twilioVoiceEnabled' => true,
            'twilioVoiceFromConfigured' => true,
            'providerState' => 'ELEVENLABS_VERIFIED',
            'canary' => 'active_voice',
            'source' => 'canary_registry',
            'dailyCap' => 5,
        ];

        return new VoiceCallContext($c['twilioVoiceEnabled'], $c['twilioVoiceFromConfigured'], $c['providerState'], $c['canary'], $c['source'], $c['dailyCap']);
    }

    /** @return array<string, array{0: array<string,mixed>, 1: list<string>}> */
    public static function casos(): array
    {
        return [
            'todo en verde: solo el código lo cierra' => [[], ['calls_disabled_in_code']],
            'el estado de producción del H6 (§I6.6)' => [
                ['twilioVoiceEnabled' => false, 'twilioVoiceFromConfigured' => false, 'providerState' => 'ELEVENLABS_NOT_CONFIGURED', 'canary' => 'not_registered', 'dailyCap' => 0],
                ['calls_disabled_in_code', 'twilio_voice_disabled', 'no_voice_number', 'elevenlabs_not_configured', 'not_an_active_voice_canary', 'daily_cap_zero'],
            ],
            'todo en contra' => [
                ['twilioVoiceEnabled' => false, 'twilioVoiceFromConfigured' => false, 'providerState' => 'ELEVENLABS_NOT_CONFIGURED', 'canary' => 'not_active', 'source' => 'marketing_calls', 'dailyCap' => -1],
                ['calls_disabled_in_code', 'twilio_voice_disabled', 'no_voice_number', 'elevenlabs_not_configured', 'not_an_active_voice_canary', 'source_not_allowed', 'daily_cap_zero'],
            ],
            'ElevenLabs sin configurar' => [['providerState' => 'ELEVENLABS_NOT_CONFIGURED'], ['calls_disabled_in_code', 'elevenlabs_not_configured']],
            'ElevenLabs apagado' => [['providerState' => 'ELEVENLABS_DISABLED'], ['calls_disabled_in_code', 'elevenlabs_disabled']],
            'ElevenLabs sin verificar' => [['providerState' => 'ELEVENLABS_UNVERIFIED'], ['calls_disabled_in_code', 'elevenlabs_unverified']],
            'ElevenLabs verificado no añade nada' => [['providerState' => 'ELEVENLABS_VERIFIED'], ['calls_disabled_in_code']],
            'Twilio Voice apagado' => [['twilioVoiceEnabled' => false], ['calls_disabled_in_code', 'twilio_voice_disabled']],
            'sin número de voz' => [['twilioVoiceFromConfigured' => false], ['calls_disabled_in_code', 'no_voice_number']],
            'canario de voz vivo pero no activo' => [['canary' => 'not_active'], ['calls_disabled_in_code', 'not_an_active_voice_canary']],
            'sin canario de voz registrado' => [['canary' => 'not_registered'], ['calls_disabled_in_code', 'not_an_active_voice_canary']],
            'origen marketing_calls (T-7)' => [['source' => 'marketing_calls'], ['calls_disabled_in_code', 'source_not_allowed']],
            'otro origen' => [['source' => 'other'], ['calls_disabled_in_code', 'source_not_allowed']],
            'cupo 0' => [['dailyCap' => 0], ['calls_disabled_in_code', 'daily_cap_zero']],
            'cupo negativo' => [['dailyCap' => -3], ['calls_disabled_in_code', 'daily_cap_zero']],
            'cupo 1' => [['dailyCap' => 1], ['calls_disabled_in_code']],
            'cupo 20' => [['dailyCap' => 20], ['calls_disabled_in_code']],
            'la política no recorta el cupo (lo hace la configuración)' => [['dailyCap' => 21], ['calls_disabled_in_code']],
            'mezcla: sin verificar, marketing_calls y cupo 0' => [
                ['providerState' => 'ELEVENLABS_UNVERIFIED', 'source' => 'marketing_calls', 'dailyCap' => 0],
                ['calls_disabled_in_code', 'elevenlabs_unverified', 'source_not_allowed', 'daily_cap_zero'],
            ],
            'mezcla: apagado, sin número y sin canario' => [
                ['providerState' => 'ELEVENLABS_DISABLED', 'twilioVoiceFromConfigured' => false, 'canary' => 'not_registered'],
                ['calls_disabled_in_code', 'no_voice_number', 'elevenlabs_disabled', 'not_an_active_voice_canary'],
            ],
        ];
    }

    public function test_la_constante_cierra_las_llamadas_en_el_codigo(): void
    {
        $this->assertFalse(Politica::CALLS_ENABLED_IN_CODE);
    }

    public function test_los_motivos_del_contrato_en_su_orden(): void
    {
        $this->assertSame(['calls_disabled_in_code', 'twilio_voice_disabled', 'no_voice_number', 'elevenlabs_not_configured',
            'elevenlabs_disabled', 'elevenlabs_unverified', 'not_an_active_voice_canary', 'source_not_allowed', 'daily_cap_zero'], Politica::REASONS);
    }

    public function test_los_casos_del_contrato(): void
    {
        foreach (self::casos() as $nombre => [$cambios, $motivos]) {
            $d = Politica::decide(self::contexto($cambios));
            $this->assertSame($motivos, $d->reasons, $nombre);
            $this->assertFalse($d->allowed, "{$nombre}: en este hito nunca se permite una llamada");
            $this->assertSame('calls_disabled_in_code', $d->reasons[0], "{$nombre}: el código lo cierra primero");
            $this->assertSame(['allowed' => false, 'reasons' => $motivos], $d->toArray(), $nombre);
        }
        $this->assertGreaterThanOrEqual(16, count(self::casos()), 'el fixture pide al menos 16');
    }

    public function test_un_contexto_fuera_de_los_conjuntos_cerrados_no_se_construye(): void
    {
        $malos = [
            'estado desconocido' => ['providerState' => 'ELEVENLABS_READY'],
            'estado en minúsculas' => ['providerState' => 'elevenlabs_verified'],
            'canario desconocido' => ['canary' => 'active'],
            'origen desconocido' => ['source' => 'csv_import'],
            'origen vacío' => ['source' => ''],
        ];
        foreach ($malos as $nombre => $cambios) {
            try {
                self::contexto($cambios);
                $this->fail("{$nombre}: tenía que rechazarse");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_una_decision_incoherente_no_se_construye(): void
    {
        $malas = [
            'permitida con motivos' => [true, ['daily_cap_zero']],
            'denegada sin motivos' => [false, []],
            'motivos desordenados' => [false, ['daily_cap_zero', 'calls_disabled_in_code']],
            'motivo repetido' => [false, ['calls_disabled_in_code', 'calls_disabled_in_code']],
            'motivo desconocido' => [false, ['calls_disabled_in_code', 'night_time']],
            'motivos con claves' => [false, ['a' => 'calls_disabled_in_code']],
        ];
        foreach ($malas as $nombre => [$permitida, $motivos]) {
            try {
                new VoiceCallDecision($permitida, $motivos);
                $this->fail("{$nombre}: tenía que rechazarse");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Lo que sí puede construirse: un subconjunto ordenado (p. ej., los motivos de un nivel que excluye algunos).
        $nivel = new VoiceCallDecision(false, ['calls_disabled_in_code', 'twilio_voice_disabled', 'elevenlabs_not_configured']);
        $this->assertSame(['calls_disabled_in_code', 'twilio_voice_disabled', 'elevenlabs_not_configured'], $nivel->reasons);
        $this->assertTrue((new VoiceCallDecision(true, []))->allowed, 'la forma existe aunque hoy decide() no la produzca');
    }

    public function test_es_pura(): void
    {
        $codigo = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Voice/VoiceCallPolicy.php');
        foreach (['config(', 'env(', 'now(', 'Carbon', 'DB::', 'Http::', 'Cache::', 'Log::', 'time(', 'date('] as $impuro) {
            $this->assertStringNotContainsString($impuro, $codigo, "la política usa {$impuro}");
        }
    }
}
