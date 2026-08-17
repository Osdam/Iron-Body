<?php

namespace App\Console\Commands;

use App\Models\RoutineCompletion;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionSet;
use App\Services\WorkoutSessionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara las sesiones guardadas con la hora local de Bogotá interpretada como
 * UTC.
 *
 * EL DAÑO
 * -------
 * Antes de `639a119`, la app mandaba `DateTime.now().toIso8601String()`, que en
 * Dart devuelve la hora LOCAL sin designador de zona. El backend la leía como
 * UTC y cada sesión quedaba fechada 5 horas antes de haber ocurrido: un
 * entrenamiento del lunes a la 1:16 de la madrugada se archivaba como las 20:16
 * del domingo y desaparecía de "esta semana".
 *
 * CÓMO SE RECONOCE UNA SESIÓN DAÑADA
 * ----------------------------------
 * `created_at` lo pone PostgreSQL con el reloj del servidor en el instante del
 * insert, así que es correcto. `completed_at` es lo que mandó la app. En una
 * sesión sana la diferencia es de segundos; en una dañada es exactamente el
 * desfase de Bogotá. Se exige que la diferencia caiga en una ventana estrecha
 * alrededor de ese desfase para no tocar nada por parecido casual, y se ignora
 * cualquier sesión creada después del despliegue del arreglo.
 *
 * NO BORRA NADA. Solo desplaza instantes mal escritos al instante real, y
 * arrastra consigo lo que se fechó a partir de ellos: las series de la sesión y
 * su fila de `routine_completions`.
 *
 * Uso:
 *   php artisan workouts:fix-naive-timestamps                 # simulacro
 *   php artisan workouts:fix-naive-timestamps --apply         # escribe
 *   php artisan workouts:fix-naive-timestamps --member=1013   # acota a un socio
 */
class WorkoutFixNaiveTimestampsCommand extends Command
{
    protected $signature = 'workouts:fix-naive-timestamps
        {--apply           : Escribe los cambios. Sin esto solo simula.}
        {--member=         : Limita la reparación a un member_id.}
        {--tolerance=10    : Minutos de holgura alrededor del desfase esperado.}';

    protected $description = 'Corrige sesiones cuya hora local se guardó como si fuera UTC (desfase de Bogotá). No borra datos.';

    /**
     * Momento del despliegue del arreglo (`639a119`). Una sesión creada después
     * ya viaja con zona explícita y no puede estar afectada.
     */
    private const FIXED_AT = '2026-08-17 12:00:00';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $tolerance = max(1, (int) $this->option('tolerance'));

        // El desfase real de la zona, no un 5 codificado a mano: si algún día
        // Colombia cambia de offset, esto sigue siendo cierto.
        $offsetMinutes = abs(
            CarbonImmutable::parse(self::FIXED_AT, 'UTC')
                ->setTimezone(WorkoutSessionService::TZ)
                ->utcOffset()
        );

        $this->info(sprintf(
            'Desfase esperado: %d min (±%d). Corte: %s UTC. Modo: %s',
            $offsetMinutes,
            $tolerance,
            self::FIXED_AT,
            $apply ? 'APLICAR' : 'SIMULACRO',
        ));

        $query = WorkoutSession::query()
            ->whereNotNull('completed_at')
            ->where('created_at', '<=', self::FIXED_AT)
            ->orderBy('id');

        if (filled($this->option('member'))) {
            $query->where('member_id', (int) $this->option('member'));
        }

        $shift = $offsetMinutes * 60;
        $rows = [];
        $targets = [];

        foreach ($query->cursor() as $session) {
            $completed = CarbonImmutable::parse($session->completed_at)->utc();
            $created = CarbonImmutable::parse($session->created_at)->utc();

            // Cuánto "antes" del insert dice la app que terminó el socio.
            $gap = $completed->diffInMinutes($created, absolute: false);

            if (abs($gap - $offsetMinutes) > $tolerance) {
                continue;
            }

            $fixed = $completed->addSeconds($shift);
            $targets[] = ['session' => $session, 'fixed' => $fixed];

            $rows[] = [
                $session->id,
                $session->member_id,
                $completed->setTimezone('America/Bogota')->format('Y-m-d H:i D'),
                $fixed->setTimezone('America/Bogota')->format('Y-m-d H:i D'),
                round($gap).' min',
            ];
        }

        if ($rows === []) {
            $this->info('No hay sesiones que reparar.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'socio', 'Bogotá ANTES (mal)', 'Bogotá DESPUÉS (real)', 'desfase'],
            $rows,
        );

        if (! $apply) {
            $this->warn(count($rows).' sesión(es) se repararían. Repite con --apply para escribir.');

            return self::SUCCESS;
        }

        $sets = 0;
        $completions = 0;

        DB::transaction(function () use ($targets, $shift, &$sets, &$completions): void {
            foreach ($targets as $t) {
                /** @var WorkoutSession $session */
                $session = $t['session'];
                /** @var CarbonImmutable $fixed */
                $fixed = $t['fixed'];

                $started = $session->started_at !== null
                    ? CarbonImmutable::parse($session->started_at)->utc()->addSeconds($shift)
                    : null;

                // `forceFill` + `timestamps=false`: `updated_at` no debe moverse,
                // es el registro de cuándo se tocó la fila, no de cuándo entrenó.
                $session->timestamps = false;
                $session->forceFill([
                    'started_at' => $started,
                    'completed_at' => $fixed,
                ])->save();

                // Las series se fecharon con el `completed_at` de la sesión.
                $sets += WorkoutSessionSet::query()
                    ->where('workout_session_id', $session->id)
                    ->whereNotNull('performed_at')
                    ->update(['performed_at' => $fixed]);

                if ($session->routine_completion_id !== null) {
                    $completions += RoutineCompletion::query()
                        ->whereKey($session->routine_completion_id)
                        ->update(['completed_at' => $fixed]);
                }
            }
        });

        $this->info(sprintf(
            'Reparadas %d sesión(es), %d serie(s) y %d completion(s). No se borró nada.',
            count($targets),
            $sets,
            $completions,
        ));

        return self::SUCCESS;
    }
}
