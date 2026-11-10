<?php

namespace App\Console\Commands\UltronStudio;

use App\Services\Telephony\VoiceProviderState;
use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\Voice\VoiceCheckResult;
use App\Services\Voice\VoiceProviders;
use App\Services\Voice\VoiceProviderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * El REGISTRO DE CAMBIOS de la configuración de las integraciones (contrato H6
 * §I5.3): por cada proveedor (Meta Ads, Twilio y voz), una instantánea de
 * SISTEMA solo si la huella efectiva es distinta de la última instantánea
 * `config` de ese proveedor, sea de quien sea. Lo lanza `devops` en cada
 * despliegue (programarlo es del Hito 7). SIN RED.
 *
 * `--verify-voice` (el paso 6 del procedimiento de activación de la voz, que
 * en H6 NO se ejecuta en producción): solo con la voz en
 * `ELEVENLABS_UNVERIFIED`, hace UN GET con `verifyCredentials()` y guarda su
 * resultado como instantánea `probe` de voz (con 30 días, el Studio afirma
 * `ELEVENLABS_VERIFIED`). Con la misma evidencia deja la MARCA de la telefonía
 * (H8, E4-6, B8-g: `VoiceProviderState::recordStudioVerification()`, que es lo
 * que da `VERIFIED` a la puerta de salientes; la telefonía no lee esta tabla):
 * `ok` la escribe y cualquier otro resultado la borra. Sale 0 solo si las
 * credenciales valen y la marca quedó escrita, y 1 si no. Con otro estado no
 * llama a nada, no toca la marca y sale 1 con el motivo.
 *
 * Nunca imprime secretos ni valores: proveedor, huella del conjunto y si se
 * anotó.
 */
class IntegrationsSnapshotCommand extends Command
{
    protected $signature = 'ultron-studio:integrations-snapshot
        {--verify-voice : verifica las credenciales de voz (solo en ELEVENLABS_UNVERIFIED) y guarda la evidencia}
        {--json : el resultado en JSON}';

    protected $description = 'Anota la configuración vigente de Meta Ads, Twilio y voz cuando cambió (instantánea de sistema). Con --verify-voice, verifica la voz y guarda la evidencia.';

    public function handle(StudioIntegrationConfig $config): int
    {
        $anotadas = $config->recordSystemSnapshots(StudioIntegrationConfig::SYSTEM_ACTOR);
        $voz = null;
        $codigo = self::SUCCESS;

        if ($this->option('verify-voice')) {
            $estado = VoiceProviders::status()->state;
            if ($estado !== VoiceProviderStatus::STATE_UNVERIFIED) {
                $voz = ['verified' => false, 'reason' => 'state_'.strtolower(str_replace('ELEVENLABS_', '', $estado)), 'state' => $estado];
                $codigo = self::FAILURE;
            } else {
                $resultado = VoiceProviders::current()->verifyCredentials();
                $fila = $config->recordVoiceVerification($resultado, StudioIntegrationConfig::SYSTEM_ACTOR);
                // E4-6 (B8-g): la misma evidencia, como marca para la telefonía, que no lee
                // esta tabla. Sin evidencia guardada, la marca no cambia (como el Studio).
                $marca = $fila === null ? null
                    : VoiceProviderState::recordStudioVerification($fila->outcome === 'ok', CarbonImmutable::instance($fila->created_at ?? now()));
                $voz = [
                    'verified' => $resultado->outcome === VoiceCheckResult::OUTCOME_CREDENTIALS_OK,
                    'outcome' => $resultado->outcome,
                    'snapshotOutcome' => $fila?->outcome,
                    'snapshot' => $fila?->uuid,
                    'httpStatus' => $resultado->httpStatus,
                    'agentAudioUlaw8000' => $resultado->agentAudioUlaw8000,
                    'telephonyMark' => $marca,
                ];
                $codigo = $resultado->outcome === VoiceCheckResult::OUTCOME_CREDENTIALS_OK && $marca === VoiceProviderState::RECORD_WRITTEN ? self::SUCCESS : self::FAILURE;
            }
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode(['snapshots' => $anotadas, 'voice' => $voz], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return $codigo;
        }

        foreach ($anotadas as $a) {
            $corta = substr($a['fingerprint'], 0, 12);
            $this->line($a['recorded']
                ? "{$a['provider']}: anotada (huella {$corta}…)"
                : "{$a['provider']}: sin cambios (huella {$corta}…)");
        }
        if ($voz !== null) {
            if (isset($voz['reason'])) {
                $this->error("Verificación de voz: no se llama a nada con el estado {$voz['state']} (solo con ELEVENLABS_UNVERIFIED).");
            } elseif ($voz['verified'] && $voz['telephonyMark'] === VoiceProviderState::RECORD_WRITTEN) {
                $this->info('Verificación de voz: credenciales válidas; evidencia guardada y marca de la telefonía escrita (30 días).');
            } elseif ($voz['verified']) {
                $this->error('Verificación de voz: credenciales válidas y evidencia guardada, pero la caché no aceptó la marca de la telefonía: las salientes siguen en elevenlabs_unverified.');
            } else {
                $this->error("Verificación de voz: {$voz['outcome']}".($voz['snapshot'] !== null ? '; evidencia guardada y marca de la telefonía borrada.' : '; sin evidencia.'));
            }
        }

        return $codigo;
    }
}
