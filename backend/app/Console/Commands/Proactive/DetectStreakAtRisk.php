<?php

namespace App\Console\Commands\Proactive;

use App\Models\Member;
use App\Services\WeeklyStreakService;

/**
 * streak.at_risk — el miembro tiene racha activa (o días activos esta semana)
 * pero AÚN no ha hecho la acción mínima de HOY. Protege el impulso antes de que
 * caiga. Pensado para final de tarde/noche temprana (no madrugada).
 */
class DetectStreakAtRisk extends BaseProactiveDetectorCommand
{
    protected $signature = 'ironbody:detect-streak-at-risk {--dry-run} {--member-id=} {--limit=} {--event=}';
    protected $description = 'Detecta miembros con racha activa sin acción hoy y emite streak.at_risk.';

    public function __construct(private readonly WeeklyStreakService $streak)
    {
        parent::__construct();
    }

    protected function detect(): void
    {
        $this->forEachMember(function (Member $member) {
            $summary = $this->streak->summary($member);

            $hasMomentum = ($summary['current_streak_days'] ?? 0) > 0
                || ($summary['active_days_this_week'] ?? 0) > 0;
            $todayMarked = (bool) ($summary['today_marked'] ?? false);

            if (!$hasMomentum || $todayMarked) {
                return; // sin racha que proteger, o ya cumplió hoy
            }

            $this->consider($member, 'streak.at_risk', [
                'streak' => [
                    'current_streak_days' => (int) ($summary['current_streak_days'] ?? 0),
                    'active_days_this_week' => (int) ($summary['active_days_this_week'] ?? 0),
                    'weekly_goal_days' => (int) ($summary['weekly_goal_days'] ?? 0),
                ],
            ]);
        });
    }
}
