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
            ['carril', 'cola', 'P', 'pendientes', 'en curso', 'más viejo', 'último latido', 'jobs/min', 'fallos 1h', 'estado'],
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
                $this->estado($l),
            ])->values()->all(),
        );

        // Salida distinta de cero cuando algo está mal: así se puede colgar de
        // un cron o de un check externo sin tener que interpretar el texto.
        $problemas = collect($snapshot)->filter(
            fn (array $l) => $l['looks_unattended'] || $l['breaching_slo'],
        );

        if ($problemas->isNotEmpty()) {
            $this->newLine();
            foreach ($problemas as $name => $l) {
                $this->warn(sprintf(
                    '· %s: %s',
                    $name,
                    $l['looks_unattended']
                        ? 'hay trabajo esperando y nadie lo procesa. Comprobar que su worker está arrancado.'
                        : 'los workers están vivos pero no dan: es capacidad, no avería.',
                ));
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

        if ($lane['breaching_slo']) {
            return 'saturado';
        }

        return $lane['backlog'] > 0 ? 'trabajando' : 'al día';
    }
}
