<?php

namespace App\Console\Commands\Proactive;

use App\Models\Member;
use App\Models\MemberAppActivityDay;

/**
 * streak.not_started — el miembro NUNCA ha registrado un día de actividad
 * (no tiene racha iniciada). Invita a construir su primera racha con una acción
 * simple. Cadencia semanal (no insistir a diario).
 */
class DetectStreakNotStarted extends BaseProactiveDetectorCommand
{
    protected $signature = 'ironbody:detect-streak-not-started {--dry-run} {--member-id=} {--limit=} {--event=}';
    protected $description = 'Detecta miembros sin racha iniciada y emite streak.not_started.';

    protected function detect(): void
    {
        $this->forEachMember(function (Member $member) {
            $hasAny = MemberAppActivityDay::query()
                ->where('member_id', $member->id)
                ->exists();
            if ($hasAny) {
                return; // ya tiene al menos un día de actividad
            }

            $this->consider($member, 'streak.not_started', [
                'streak' => ['has_any_activity_day' => false],
            ]);
        });
    }
}
