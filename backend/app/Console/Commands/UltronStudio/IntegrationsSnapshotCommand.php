<?php

namespace App\Console\Commands\UltronStudio;

use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\Voice\VoiceCheckResult;
use App\Services\Voice\VoiceProviders;
use App\Services\Voice\VoiceProviderStatus;
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
 * `ELEVENLABS_VERIFIED`). Sale 0 si las credenciales valen y 1 si no. Con otro
 * estado no llama a nada y sale 1 con el motivo.
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
                $voz = [
                    'verified' => $resultado->outcome === VoiceCheckResult::OUTCOME_CREDENTIALS_OK,
                    'outcome' => $resultado->outcome,
                    'snapshotOutcome' => $fila?->outcome,
                    'snapshot' => $fila?->uuid,
                    'httpStatus' => $resultado->httpStatus,
                    'agentAudioUlaw8000' => $resultado->agentAudioUlaw8000,
                ];
                $codigo = $resultado->outcome === VoiceCheckResult::OUTCOME_CREDENTIALS_OK ? self::SUCCESS : self::FAILURE;
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
            } elseif ($voz['verified']) {
                $this->info('Verificación de voz: credenciales válidas; evidencia guardada.');
            } else {
                $this->error("Verificación de voz: {$voz['outcome']}".($voz['snapshot'] !== null ? '; evidencia guardada.' : '; sin evidencia.'));
            }
        }

        return $codigo;
    }
}
