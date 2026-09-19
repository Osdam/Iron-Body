<?php

namespace App\Console\Commands\Proactive;

use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\Routine;
use App\Models\RoutineCompletion;

/**
 * workout.not_started_today — el miembro tiene una rutina asignada cuyo día
 * incluye hoy, pero aún no ha iniciado/completado nada hoy. Incentiva ANTES de
 * que pierda el día. Si la rutina no define días (`days` vacío) o los define sin
 * día de la semana (programa por días ordinales: {name:"Día 1"}) se asume que
 * cualquier día es válido (degradación segura). Sin rutina asignada → no aplica.
 */
class DetectWorkoutNotStarted extends BaseProactiveDetectorCommand
{
    protected $signature = 'ironbody:detect-workout-not-started {--dry-run} {--member-id=} {--limit=} {--event=}';

    protected $description = 'Detecta miembros con entrenamiento esperado hoy sin iniciar y emite workout.not_started_today.';

    protected function detect(): void
    {
        $startOfToday = $this->now()->startOfDay();
        $isoDow = (int) $this->now()->dayOfWeekIso; // 1=lunes … 7=domingo

        $this->forEachMember(function (Member $member) use ($startOfToday, $isoDow) {
            $routineIds = MemberRoutineAssignment::query()
                ->where('member_id', $member->id)
                ->pluck('routine_id');

            if ($routineIds->isEmpty()) {
                return; // sin rutina asignada: no podemos afirmar "esperado hoy"
            }

            $routines = Routine::query()->whereIn('id', $routineIds)->get(['id', 'days']);
            if (! $this->expectedToday($routines, $isoDow)) {
                return;
            }

            $startedToday = RoutineCompletion::query()
                ->where('member_id', $member->id)
                ->where('completed_at', '>=', $startOfToday->toDateTimeString())
                ->exists();
            if ($startedToday) {
                return;
            }

            $this->consider($member, 'workout.not_started_today', [
                'workouts' => ['assigned_routines' => $routineIds->count()],
            ]);
        });
    }

    /** ¿Alguna rutina asignada incluye hoy (o no define días)? */
    private function expectedToday($routines, int $isoDow): bool
    {
        $names = [
            1 => ['1', 'monday', 'mon', 'lunes', 'lun'],
            2 => ['2', 'tuesday', 'tue', 'martes', 'mar'],
            3 => ['3', 'wednesday', 'wed', 'miercoles', 'miércoles', 'mie', 'mié'],
            4 => ['4', 'thursday', 'thu', 'jueves', 'jue'],
            5 => ['5', 'friday', 'fri', 'viernes', 'vie'],
            6 => ['6', 'saturday', 'sat', 'sabado', 'sábado', 'sab', 'sáb'],
            7 => ['7', '0', 'sunday', 'sun', 'domingo', 'dom'],
        ];
        $todayTokens = $names[$isoDow] ?? [];

        foreach ($routines as $routine) {
            $days = $routine->days;
            if (empty($days) || ! is_array($days)) {
                return true; // sin calendario definido → válido cualquier día
            }

            // ¿Esta rutina declara días de la semana en algún elemento? Si no
            // (programa por días ordinales: {name:"Día 1"}), no hay calendario
            // que comparar y aplica la misma degradación segura que `days` vacío.
            $hasWeekdayCalendar = false;
            foreach ($days as $d) {
                $weekday = $this->weekdayOf($d);
                if ($weekday === null) {
                    continue;
                }
                $hasWeekdayCalendar = true;
                if (in_array(mb_strtolower(trim($weekday)), $todayTokens, true)) {
                    return true;
                }
            }

            if (! $hasWeekdayCalendar) {
                return true; // rutina sin calendario semanal → cualquier día
            }
        }

        return false;
    }

    /**
     * Día de la semana declarado por un elemento de `days`, o null si ese
     * elemento no codifica ninguno.
     *
     * Formas reales en producción:
     *   - escalar heredado: "lunes"                       → el propio valor
     *   - objeto con día:   {day:"Lunes", title:…}        → el valor de `day`
     *   - objeto ordinal:   {name:"Día 1", order:1, …}    → null (sin día)
     *
     * Nunca castea un array a string: ese cast era la causa del
     * `ErrorException: Array to string conversion` que mataba el comando.
     */
    private function weekdayOf(mixed $day): ?string
    {
        if (is_scalar($day)) {
            return (string) $day;
        }

        if (! is_array($day)) {
            return null;
        }

        foreach (['day', 'weekday', 'dia', 'día'] as $key) {
            if (isset($day[$key]) && is_scalar($day[$key])) {
                return (string) $day[$key];
            }
        }

        return null;
    }
}
