<?php

namespace App\Console\Commands\Proactive;

use App\Models\Member;
use App\Models\MemberAppActivityDay;
use App\Models\NutritionMealLog;
use App\Models\RoutineCompletion;

/**
 * daily.compliance_missing — el miembro no ha cumplido NINGUNA acción clave del
 * día (entrenamiento, nutrición ni racha/actividad). Invita a cerrar el día con
 * una sola acción inteligente. Evento "fuerte" (urgencia positiva) → pensado
 * para la tarde, una vez al día.
 */
class DetectDailyComplianceMissing extends BaseProactiveDetectorCommand
{
    protected $signature = 'ironbody:detect-daily-compliance-missing {--dry-run} {--member-id=} {--limit=} {--event=}';
    protected $description = 'Detecta miembros sin ninguna acción clave hoy y emite daily.compliance_missing.';

    protected function detect(): void
    {
        $startOfToday = $this->now()->startOfDay();
        $todayDate = $this->now()->toDateString();

        $this->forEachMember(function (Member $member) use ($startOfToday, $todayDate) {
            $trainedToday = RoutineCompletion::query()
                ->where('member_id', $member->id)
                ->where('completed_at', '>=', $startOfToday->toDateTimeString())
                ->exists();
            if ($trainedToday) {
                return;
            }

            $ateToday = NutritionMealLog::query()
                ->where('member_id', $member->id)
                ->whereDate('log_date', $todayDate)
                ->whereHas('items')
                ->exists();
            if ($ateToday) {
                return;
            }

            $activeToday = MemberAppActivityDay::query()
                ->where('member_id', $member->id)
                ->whereDate('activity_date', $todayDate)
                ->exists();
            if ($activeToday) {
                return;
            }

            $this->consider($member, 'daily.compliance_missing', [
                'today' => ['trained' => false, 'nutrition' => false, 'active' => false],
            ]);
        });
    }
}
