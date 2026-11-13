<?php

namespace App\Console\Commands;

use App\Jobs\IronIa\GenerateRecoveryProposal;
use App\Services\Trainer\Recovery\RoutineRecoveryCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Recuperación de rutinas de Iron IA del flujo antiguo (docs/iron-ia/RECUPERACION-RUTINAS.md).
 *
 *   php artisan iron-ia:recover-routines recuperacion-2026-11              # simulación (no escribe)
 *   php artisan iron-ia:recover-routines recuperacion-2026-11 --execute    # deja las propuestas en borrador
 *
 * Nunca publica nada: la publicación es del entrenador, con el botón de siempre.
 * Volver a lanzarla es seguro: lo hecho no se repite y lo fallido se reintenta.
 */
class RecoverIronIaRoutinesCommand extends Command
{
    protected $signature = 'iron-ia:recover-routines
        {campaign : identificador único de la campaña, p. ej. recuperacion-2026-11}
        {--execute : genera de verdad las propuestas en borrador; sin esto solo simula}
        {--cutoff=2026-11-13 : último día (hora de Bogotá) de generaciones del flujo antiguo que se tratan}
        {--limit=0 : máximo de propuestas en esta ejecución (0 = todas las candidatas)}
        {--max-tokens=2000000 : tope de tokens de TODA la campaña; al llegar, se para}
        {--routine=* : tratar solo estas rutinas originales (ids)}
        {--queue= : mandar cada propuesta a esta cola en vez de generarla aquí}
        {--report= : ruta del informe JSON (por defecto, storage/app/iron-ia/recovery/<campaña>/)}';

    protected $description = 'Propuestas de recuperación de rutinas de Iron IA (borradores para revisión del entrenador; nunca publica)';

    /** Tokens medidos por generación del plan integral en producción (13-11-2026), por potencia de rutina. */
    private const TOKENS_BY_POWER = [1 => 11500, 2 => 14000, 3 => 55000, 4 => 35000, 5 => 60000];

    /** Estimación (gpt-4.1-mini, mezcla entrada/salida): solo para orientar el coste. */
    private const USD_PER_MILLION_TOKENS = 0.8;

    public function handle(RoutineRecoveryCampaign $campaign): int
    {
        $nombre = (string) $this->argument('campaign');
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $nombre)) {
            $this->error('El identificador de campaña va en minúsculas, cifras, punto, guion o guion bajo (3–64).');

            return self::INVALID;
        }
        $corte = (string) $this->option('cutoff');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $corte)) {
            $this->error('--cutoff va como AAAA-MM-DD.');

            return self::INVALID;
        }
        $ejecutar = (bool) $this->option('execute');
        $solo = array_map('intval', (array) $this->option('routine'));

        $evaluacion = $campaign->evaluate($nombre, $corte);
        $candidatas = array_values(array_filter($evaluacion, fn ($d) => $d['eligible'] && ($solo === [] || in_array($d['routine_id'], $solo, true))));

        $this->summary($evaluacion, $candidatas);

        $informe = [
            'campaign' => $nombre,
            'mode' => $ejecutar ? 'execute' : 'dry-run',
            'cutoff' => $corte,
            'at' => now()->toIso8601String(),
            'evaluation' => $evaluacion,
            'results' => [],
        ];

        if (! $ejecutar) {
            $this->line('');
            $this->info('SIMULACIÓN: no se ha escrito nada. Para generar las propuestas, repite con --execute.');
            $this->writeReport($nombre, $informe);

            return self::SUCCESS;
        }

        // Una sola ejecución de la campaña a la vez.
        $cerrojo = Cache::lock('iron-ia-recovery:'.$nombre, 4 * 3600);
        if (! $cerrojo->get()) {
            $this->error("Ya hay una ejecución de «{$nombre}» en curso.");

            return self::FAILURE;
        }

        try {
            $limite = max(0, (int) $this->option('limit'));
            $tope = max(0, (int) $this->option('max-tokens'));
            $hechas = 0;
            foreach ($candidatas as $c) {
                if ($limite > 0 && $hechas >= $limite) {
                    $this->warn("Límite de {$limite} propuestas alcanzado en esta ejecución.");
                    break;
                }
                if ($tope > 0 && $campaign->tokensSpent($nombre) >= $tope) {
                    $this->warn("Tope de {$tope} tokens de la campaña alcanzado: se para.");
                    break;
                }

                // El tope viaja con el job: cada propuesta lo vuelve a mirar justo antes de gastar.
                $job = new GenerateRecoveryProposal($nombre, $c['routine_id'], $corte, $tope);
                if (($cola = (string) $this->option('queue')) !== '') {
                    dispatch($job)->onQueue($cola);
                    $res = ['routine_id' => $c['routine_id'], 'outcome' => 'queued', 'reason' => $cola];
                } else {
                    // Aquí mismo y de una en una (sin worker de por medio): el job
                    // es el mismo; dispatch_sync() lo mandaría a la conexión `sync`
                    // y perdería el resultado.
                    $res = $job->handle($campaign);
                }

                $informe['results'][] = $res;
                $this->line(sprintf('  rutina %d → %s%s', $res['routine_id'], $res['outcome'],
                    isset($res['proposal_routine_id']) ? ' (propuesta '.$res['proposal_routine_id'].')' : (isset($res['error_code']) ? ' ('.$res['error_code'].')' : (isset($res['reason']) ? ' ('.$res['reason'].')' : ''))));
                if (in_array($res['outcome'], ['proposed', 'adopted', 'queued'], true)) {
                    $hechas++;
                }
                if (($res['reason'] ?? null) === 'token_cap') {
                    $this->warn("Tope de {$tope} tokens de la campaña alcanzado: se para.");
                    break;
                }
            }
        } finally {
            $cerrojo->release();
        }

        $informe['tokens_spent'] = $campaign->tokensSpent($nombre);
        $this->line('');
        $this->info('Tokens gastados por la campaña: '.$informe['tokens_spent'].'. Ninguna propuesta se ha publicado: lo hace el entrenador.');
        $this->writeReport($nombre, $informe);

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string,mixed>>  $evaluacion
     * @param  list<array<string,mixed>>  $candidatas
     */
    private function summary(array $evaluacion, array $candidatas): void
    {
        $porMotivo = [];
        foreach ($evaluacion as $d) {
            $porMotivo[$d['reason']] = ($porMotivo[$d['reason']] ?? 0) + 1;
        }
        arsort($porMotivo);
        $this->info('Rutinas de Iron IA evaluadas: '.count($evaluacion));
        $this->table(['Motivo', 'Rutinas'], array_map(fn ($m, $n) => [$m, $n], array_keys($porMotivo), $porMotivo));

        $tokens = 0;
        foreach ($candidatas as $c) {
            $tokens += self::TOKENS_BY_POWER[$c['power'] ?? 1] ?? self::TOKENS_BY_POWER[1];
        }
        $this->info(sprintf(
            'Candidatas: %d (socios %d, entrenadores %d) · estimación %s tokens ≈ %.2f USD',
            count($candidatas),
            count(array_unique(array_column($candidatas, 'member_id'))),
            count(array_unique(array_column($candidatas, 'trainer_id'))),
            number_format($tokens, 0, ',', '.'),
            $tokens / 1_000_000 * self::USD_PER_MILLION_TOKENS,
        ));
        $this->table(
            ['Rutina original', 'Socio', 'Entrenador', 'Días', 'Potencia rutina'],
            array_map(fn ($c) => [$c['routine_id'], $c['member_id'], $c['trainer_id'], $c['days'], $c['power'] ?? '1 (anterior a las potencias)'], $candidatas),
        );
    }

    /** @param  array<string,mixed>  $informe */
    private function writeReport(string $campaign, array $informe): void
    {
        $ruta = (string) ($this->option('report') ?: storage_path('app/iron-ia/recovery/'.$campaign.'/'.now()->format('Ymd-His').'-'.$informe['mode'].'.json'));
        File::ensureDirectoryExists(dirname($ruta));
        File::put($ruta, json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line('Informe: '.$ruta);
    }
}
