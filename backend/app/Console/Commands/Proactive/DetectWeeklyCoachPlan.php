<?php

namespace App\Console\Commands\Proactive;

use App\Models\Member;

/**
 * weekly.coach_plan — al inicio de la semana, invita al miembro a planificar
 * (entrenamiento, nutrición, constancia). Evento basado en calendario; se
 * agenda lunes por la mañana. Cadencia semanal (idempotente por semana ISO).
 */
class DetectWeeklyCoachPlan extends BaseProactiveDetectorCommand
{
    protected $signature = 'ironbody:detect-weekly-coach-plan {--dry-run} {--member-id=} {--limit=} {--event=}';
    protected $description = 'Invita a planificar la semana y emite weekly.coach_plan.';

    protected function detect(): void
    {
        $weekStart = $this->now()->startOfWeek()->toDateString();

        $this->forEachMember(function (Member $member) use ($weekStart) {
            $this->consider($member, 'weekly.coach_plan', [
                'weekly' => ['week_start' => $weekStart],
            ]);
        });
    }
}
