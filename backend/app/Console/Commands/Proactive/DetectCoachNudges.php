<?php

namespace App\Console\Commands\Proactive;

use App\Models\Member;
use App\Models\NutritionMealLog;
use App\Models\RoutineCompletion;

/**
 * coach.nudge — empujón contextual flexible para CUMPLIMIENTO PARCIAL: el
 * miembro ya hizo UNA acción clave hoy (entrenó o registró nutrición) pero le
 * falta la otra. Acompañamiento suave para completar el día. Si no hizo nada,
 * de eso se encarga daily.compliance_missing (no se solapan). El presupuesto
 * anti-spam evita que un mismo miembro reciba ambos.
 */
class DetectCoachNudges extends BaseProactiveDetectorCommand
{
    protected $signature = 'ironbody:detect-coach-nudges {--dry-run} {--member-id=} {--limit=} {--event=}';
    protected $description = 'Detecta cumplimiento parcial del día y emite coach.nudge contextual.';

    protected function detect(): void
    {
        $startOfToday = $this->now()->startOfDay();
        $todayDate = $this->now()->toDateString();

        $this->forEachMember(function (Member $member) use ($startOfToday, $todayDate) {
            $trained = RoutineCompletion::query()
                ->where('member_id', $member->id)
                ->where('completed_at', '>=', $startOfToday->toDateTimeString())
                ->exists();

            $ate = NutritionMealLog::query()
                ->where('member_id', $member->id)
                ->whereDate('log_date', $todayDate)
                ->whereHas('items')
                ->exists();

            // Cumplimiento PARCIAL: exactamente una de las dos acciones.
            if ($trained === $ate) {
                return; // ninguna (lo cubre compliance_missing) o ambas (ya cumplió)
            }

            $missing = $trained ? 'nutrition' : 'workout';
            $this->consider($member, 'coach.nudge', [
                'today' => ['trained' => $trained, 'nutrition' => $ate, 'missing' => $missing],
            ]);
        });
    }
}
