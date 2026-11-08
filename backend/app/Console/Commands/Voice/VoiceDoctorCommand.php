<?php

namespace App\Console\Commands\Voice;

use App\Services\Telephony\VoiceTelephonyStatus;
use App\Services\Voice\ElevenLabs\ElevenLabsConfig;
use App\Services\Voice\VoiceCheckResult;
use App\Services\Voice\VoiceProviders;
use App\Services\Voice\VoiceProviderStatus;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ¿Cómo está la voz? (contrato H6 §I6.3). Imprime el estado de ElevenLabs y
 * las piezas que faltan POR NOMBRE, nunca valores, y sale con 0: un
 * `ELEVENLABS_NOT_CONFIGURED` no es un fallo, es el estado de este hito.
 *
 * `--verify` solo actúa con `ELEVENLABS_UNVERIFIED` (bandera encendida y todas
 * las piezas): comprueba la clave y el agente con un GET de solo lectura,
 * imprime el resultado SIN GUARDARLO (este espacio neutro no guarda nada; la
 * evidencia la deja el comando de instantáneas de integraciones con su opción
 * de verificar la voz) y sale con 0 solo si es `credentials_ok`. Con otro
 * estado no llama a nada y sale con 1.
 *
 * En este hito no se ejecuta ninguna verificación en producción: es un paso
 * del procedimiento de activación.
 */
class VoiceDoctorCommand extends Command
{
    protected $signature = 'voice:doctor
        {--json : el estado en JSON (con --verify, también la verificación)}
        {--verify : con ELEVENLABS_UNVERIFIED, comprueba la clave y el agente con un GET de solo lectura (no guarda nada)}
        {--telefonia : además, el estado de la telefonía del H8 (banderas, cadena por partes, freno, límites y el día), sin red}';

    protected $description = 'Estado de la voz (ElevenLabs): piezas que faltan por nombre, nunca valores. Con --verify y ELEVENLABS_UNVERIFIED, un GET de solo lectura; entonces sale 0 solo con credentials_ok.';

    /** La variable de entorno de cada pieza, para decir dónde se pone sin enseñar nada. */
    private const ENV = [
        'api_key' => 'ELEVENLABS_API_KEY',
        'agent_id' => 'ELEVENLABS_AGENT_ID',
        'webhook_secret' => 'ELEVENLABS_WEBHOOK_SECRET',
        'region' => 'ELEVENLABS_REGION',
    ];

    public function handle(): int
    {
        $estado = VoiceProviders::status();
        $verificar = (bool) $this->option('verify');

        $resultado = null;
        if ($verificar && $estado->state === VoiceProviderStatus::STATE_UNVERIFIED) {
            $resultado = VoiceProviders::current()->verifyCredentials();
        }
        $codigo = ! $verificar || $resultado?->outcome === VoiceCheckResult::OUTCOME_CREDENTIALS_OK
            ? self::SUCCESS
            : self::FAILURE;

        if ($this->option('json')) {
            $salida = $estado->toArray();
            if ($verificar) {
                $salida['verification'] = $resultado?->toArray();
            }
            if ($this->option('telefonia')) {
                $salida['telefonia'] = VoiceTelephonyStatus::snapshot();
            }
            // Salida cruda: nada de marcado de consola dentro del JSON.
            $this->output->writeln((string) json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return $codigo;
        }

        $this->info('── Voz · ElevenLabs · doctor ──');
        $this->line('Estado     : '.$estado->state);
        $this->line('Proveedor  : '.$estado->provider);
        $this->line('Bandera    : '.($estado->enabled ? 'encendida' : 'apagada').' (ELEVENLABS_ENABLED)');
        $this->line('Región     : '.($estado->region ?? 'no válida (ELEVENLABS_REGION; admitidas: '.implode(', ', array_keys(ElevenLabsConfig::REGIONS)).')'));
        $this->line('Faltan     : '.($estado->missing === []
            ? 'ninguna'
            : implode(', ', array_map(static fn (string $pieza): string => $pieza.' ('.self::ENV[$pieza].')', $estado->missing))));
        $this->line('Llamadas   : cerradas en el código. En este hito no se hace ninguna llamada.');
        $this->newLine();
        if ($this->option('telefonia')) {
            $this->telephony(VoiceTelephonyStatus::snapshot());
        }

        $this->line(match ($estado->state) {
            VoiceProviderStatus::STATE_NOT_CONFIGURED => '<comment>Sin configurar: faltan piezas. No sale nada a la red.</comment>',
            VoiceProviderStatus::STATE_DISABLED => 'Piezas completas y bandera apagada. Para comprobar la clave: ELEVENLABS_ENABLED=true, caché de configuración y voice:doctor --verify.',
            default => 'Bandera encendida y piezas completas, sin verificar. voice:doctor --verify hace un GET de solo lectura al agente.',
        });

        if (! $verificar) {
            return $codigo;
        }

        if ($resultado === null) {
            $this->error("No se verifica: el estado es {$estado->state} y --verify solo actúa con ".VoiceProviderStatus::STATE_UNVERIFIED.'. No se ha hecho ninguna petición.');

            return $codigo;
        }

        $audio = match ($resultado->agentAudioUlaw8000) {
            true => 'sí',
            false => 'no',
            null => 'sin dato',
        };
        $this->line('Verificación: '.$resultado->outcome
            .' · HTTP '.($resultado->httpStatus ?? '—')
            .' · '.$resultado->latencyMs.' ms'
            .' · agente en μ-law 8000: '.$audio);
        $this->line('No se guarda: es solo una comprobación por consola.');

        if ($codigo === self::SUCCESS) {
            $this->info('La clave abre el agente configurado.');
        } else {
            $this->error('La verificación no salió bien: '.$resultado->outcome.'.');
        }

        return $codigo;
    }

    /**
     * La telefonía del H8 (contrato H8, D8-20 y D8-21), por nombre y sin
     * valores: ningún número, secreto ni dato personal.
     *
     * @param  array<string, mixed>  $t
     */
    private function telephony(array $t): void
    {
        $si = static fn (mixed $v): string => $v === true ? 'sí' : ($v === false ? 'no' : '—');
        $this->info('── Voz · telefonía (H8) ──');
        $this->line('Constante  : '.($t['callsEnabledInCode'] ? 'ABIERTA' : 'cerrada').' (la de VoiceCallPolicy: las llamadas en el código)');
        $this->line('Banderas   : entrantes '.$si($t['flags']['inbound']).' · salientes '.$si($t['flags']['outbound']).' ('.$t['scopes']['outbound'].') · Twilio Voice '.$si($t['flags']['twilioVoice'])
            .' · laboratorio '.$si($t['flags']['lab']).' · monitor '.$si($t['flags']['monitor']));
        $this->line('Proveedor  : '.$t['provider']);
        $this->line('Número     : '.($t['number']['configured'] ? 'configurado' : 'sin configurar (TWILIO_VOICE_FROM)')
            .($t['number']['collidesWithMessaging'] ? ' · CHOCA con un número protegido de la mensajería del motor (config/meta.php): la política deniega las salientes por nombre' : '')
            .' · número importado '.$si($t['number']['importedNumberIdConfigured']).' · subcuenta (corte y conciliación) '.$si($t['carrierConfigured']));
        if (is_array($t['publication'])) {
            foreach ($t['publication'] as $parte => $p) {
                $this->line(sprintf('Cadena     : %s %s%s%s', $parte, $p['status'], $p['seq'] === null ? '' : ' · seq '.$p['seq'], $p['reason'] === null ? '' : ' · '.$p['reason']));
            }
        } else {
            $this->line('Cadena     : no se pudo leer la base');
        }
        $this->line('Freno      : '.($t['brakeOpen'] === true ? 'ECHADO' : ($t['brakeOpen'] === false ? 'suelto' : '—')));
        $l = $t['limits'];
        $this->line(sprintf('Límites    : %d a la vez · %d s por llamada · salientes/día %d · entrantes/día %d · presupuesto %d µUS$ · RNE %d días',
            $l['maxConcurrentCalls'], $l['perCallMaxSeconds'], $l['outboundDailyCap'], $l['inboundDailyCap'], $l['dailyBudgetMicros'], $l['rneValidityDays']));
        $destinos = [];
        foreach ($t['transferDirectory'] as $parte => $n) {
            $destinos[] = $parte.' '.($n === null ? 'ilegibles' : $n);
        }
        $this->line('Horario    : propio '.$t['schedule']['ownHours'].' · personal '.$t['schedule']['staffHours'].' · destinos de transferencia '.implode(', ', $destinos));
        $this->newLine();
    }
}
