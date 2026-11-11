<?php

namespace App\Console\Commands;

use App\Services\Observability\QueueHealthService;
use Illuminate\Console\Command;

/**
 * Foto de los carriles de cola, para mirar desde el servidor.
 *
 * Existe para el momento en que algo va mal y hay que decidir rápido si falta un
 * proceso o faltan manos. `supervisorctl status` dice cuántos workers cree tener
 * Supervisor, que es la pregunta equivocada: un worker puede estar arrancado y
 * bloqueado, o vivo pero escuchando la cola equivocada. Esto dice si el trabajo
 * AVANZA, que es lo que importa.
 *
 * Auditoría B-T16 (H-7 de OPS7): también un worker que murió con un trabajo
 * RESERVADO (`huérfanos`, en los carriles que lo vigilan): sin trabajo nuevo
 * detrás, la espera no lo delata.
 */
class QueueHealthCommand extends Command
{
    protected $signature = 'queue:health {--json : Salida en JSON para un script}';

    protected $description = 'Muestra el estado de cada carril de cola: backlog, espera y último latido.';

    public function handle(QueueHealthService $health): int
    {
        $snapshot = $health->snapshot();

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(
            ['carril', 'cola', 'P', 'pendientes', 'en curso', 'más viejo', 'último latido', 'jobs/min', 'fallos 1h', 'huérfanos', 'estado'],
            collect($snapshot)->map(fn (array $l, string $name) => [
                $name,
                $l['queue'],
                $l['priority'],
                $l['backlog'],
                $l['reserved'],
                $l['oldest_pending_seconds'].'s',
                $l['last_processed_seconds_ago'] === null ? '—' : $l['last_processed_seconds_ago'].'s',
                $l['jobs_last_minute'],
                $l['failed_last_hour'],
                ($l['watch_reserved'] ?? false) === true ? $l['orphaned_reserved'] : '—',
                $this->estado($l),
            ])->values()->all(),
        );

        // Salida distinta de cero cuando algo está mal: así se puede colgar de
        // un cron o de un check externo sin tener que interpretar el texto.
        $problemas = collect($snapshot)->filter(
            fn (array $l) => $l['looks_unattended'] || $l['breaching_slo'] || ($l['looks_orphaned'] ?? false),
        );

        if ($problemas->isNotEmpty()) {
            $this->newLine();
            foreach ($problemas as $name => $l) {
                if ($l['looks_unattended'] || $l['breaching_slo']) {
                    $this->warn(sprintf(
                        '· %s: %s',
                        $name,
                        $l['looks_unattended']
                            ? 'hay trabajo esperando y nadie lo procesa. Comprobar que su worker está arrancado.'
                            : 'los workers están vivos pero no dan: es capacidad, no avería.',
                    ));
                }
                if ($l['looks_orphaned'] ?? false) {
                    $this->warn(sprintf(
                        '· %s: %d trabajo(s) reservado(s) hace más de %d s (su retry_after) sin un latido del carril: el worker murió con el trabajo en la mano. Comprobar que su worker está arrancado; sin worker, nadie lo rescata.',
                        $name,
                        $l['orphaned_reserved'],
                        $l['retry_after_seconds'],
                    ));
                }
            }

            return self::FAILURE;
        }

        $this->info('Todos los carriles al día.');

        return self::SUCCESS;
    }

    private function estado(array $lane): string
    {
        if ($lane['looks_unattended']) {
            return 'SIN WORKER';
        }

        if ($lane['looks_orphaned'] ?? false) {
            return 'WORKER MUERTO';
        }

        if ($lane['breaching_slo']) {
            return 'saturado';
        }

        return $lane['backlog'] > 0 ? 'trabajando' : 'al día';
    }
}
