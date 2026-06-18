<?php

namespace App\Console\Commands\Proactive;

use App\Models\IronAiConversation;
use App\Models\Member;
use App\Models\MemberAppActivityDay;
use App\Models\NutritionMealLog;
use App\Models\RoutineCompletion;
use Carbon\CarbonImmutable;

/**
 * coach.reactivation — el miembro no registra NINGUNA actividad (entrenamiento,
 * nutrición, racha ni chat IA) desde hace N días. Invita a volver sin presión.
 * Se evita molestar a quienes ya están muy lejos (> max_idle_days): para esos,
 * la reactivación es campaña aparte, no notificación. Cadencia semanal.
 */
class DetectCoachReactivation extends BaseProactiveDetectorCommand
{
    protected $signature = 'ironbody:detect-coach-reactivation {--dry-run} {--member-id=} {--limit=} {--event=}';
    protected $description = 'Detecta miembros inactivos varios días y emite coach.reactivation.';

    protected function detect(): void
    {
        $t = config('proactive_coach.thresholds');
        $idleDays = (int) ($t['reactivation_idle_days'] ?? 7);
        $maxIdleDays = (int) ($t['reactivation_max_idle_days'] ?? 45);
        $now = $this->now();

        $this->forEachMember(function (Member $member) use ($now, $idleDays, $maxIdleDays) {
            $last = $this->lastActivityAt($member);

            // Sin ninguna señal: usamos la fecha de alta como referencia.
            $reference = $last ?? ($member->created_at
                ? CarbonImmutable::parse($member->created_at)
                : null);
            if ($reference === null) {
                return;
            }

            $idle = $reference->diffInDays($now);
            if ($idle < $idleDays || $idle > $maxIdleDays) {
                return; // demasiado reciente, o demasiado lejano (campaña aparte)
            }

            $this->consider($member, 'coach.reactivation', [
                'reactivation' => ['idle_days' => (int) $idle],
            ]);
        });
    }

    /** Última actividad real entre entrenamiento/nutrición/racha/chat IA. */
    private function lastActivityAt(Member $member): ?CarbonImmutable
    {
        $dates = [];

        $c = RoutineCompletion::query()->where('member_id', $member->id)->max('completed_at');
        if ($c) {
            $dates[] = CarbonImmutable::parse((string) $c);
        }
        $n = NutritionMealLog::query()->where('member_id', $member->id)->max('log_date');
        if ($n) {
            $dates[] = CarbonImmutable::parse((string) $n);
        }
        $a = MemberAppActivityDay::query()->where('member_id', $member->id)->max('activity_date');
        if ($a) {
            $dates[] = CarbonImmutable::parse((string) $a);
        }
        $m = IronAiConversation::query()->where('member_id', $member->id)->max('last_message_at');
        if ($m) {
            $dates[] = CarbonImmutable::parse((string) $m);
        }

        if ($dates === []) {
            return null;
        }
        return collect($dates)->sortDesc()->first();
    }
}
