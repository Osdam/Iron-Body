<?php

namespace App\Jobs\IronIa;

use App\Services\Trainer\Recovery\RoutineRecoveryCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Una propuesta de recuperación de Iron IA (docs/iron-ia/RECUPERACION-RUTINAS.md).
 *
 * Por defecto `iron-ia:recover-routines` la ejecuta en el mismo proceso, de una
 * en una: producción no tiene worker para la cola `default`. Con `--queue` se
 * puede mandar a una cola que sí atienda un worker.
 *
 * Los reintentos NO son del job (un intento): los lleva la fila de la campaña
 * (RoutineRecoveryCampaign::MAX_ATTEMPTS), que es lo que hace idempotente
 * volver a lanzar la campaña.
 */
class GenerateRecoveryProposal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** El nivel 1 tarda hasta ~30 s y los niveles altos más; con margen. */
    public int $timeout = 180;

    public function __construct(
        public readonly string $campaign,
        public readonly int $originalRoutineId,
        public readonly string $cutoffDate,
        public readonly int $maxTokens = 0,
    ) {}

    /** @return array<string,mixed> */
    public function handle(RoutineRecoveryCampaign $campaign): array
    {
        return $campaign->process($this->campaign, $this->originalRoutineId, $this->cutoffDate, $this->maxTokens);
    }
}
