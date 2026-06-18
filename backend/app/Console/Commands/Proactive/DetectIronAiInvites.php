<?php

namespace App\Console\Commands\Proactive;

use App\Models\IronAiConversation;
use App\Models\Member;
use App\Models\MemberAppActivityDay;
use App\Models\NutritionAiRecommendation;
use App\Models\RoutineCompletion;

/**
 * Invitaciones a IA — un solo detector que evalúa varias señales de "aún no
 * descubres / hace tiempo no usas" y emite la invitación correspondiente. El
 * presupuesto anti-spam y la cadencia semanal evitan saturar (a lo sumo se
 * envía la primera que entre en presupuesto). Filtrable con --event=.
 *
 *  iron_ai.chat_invite      → nunca/hace mucho usó el chat IA.
 *  iron_ai.nutrition_invite → nunca/hace mucho usó análisis nutricional IA.
 *  iron_ai.progress_invite  → nunca/hace mucho usó análisis de progreso IA.
 *  iron_ai.streak_invite    → entrena pero NO usa la racha (no la conoce).
 */
class DetectIronAiInvites extends BaseProactiveDetectorCommand
{
    protected $signature = 'ironbody:detect-iron-ai-invites {--dry-run} {--member-id=} {--limit=} {--event=}';
    protected $description = 'Detecta usuarios que no usan módulos de IA y emite invitaciones (chat/nutrition/progress/streak).';

    protected function detect(): void
    {
        $t = config('proactive_coach.thresholds');
        $now = $this->now();

        $chatCutoff = $now->subDays((int) ($t['chat_invite_idle_days'] ?? 14));
        $nutCutoff = $now->subDays((int) ($t['nutrition_invite_idle_days'] ?? 10));
        $progCutoff = $now->subDays((int) ($t['progress_invite_idle_days'] ?? 21));
        $recentActivity = $now->subDays(14);

        $this->forEachMember(function (Member $member) use ($chatCutoff, $nutCutoff, $progCutoff, $recentActivity) {
            // chat_invite
            $lastChat = IronAiConversation::query()
                ->where('member_id', $member->id)
                ->where('messages_count', '>', 0)
                ->max('last_message_at');
            if ($lastChat === null || $this->isBefore($lastChat, $chatCutoff)) {
                $this->consider($member, 'iron_ai.chat_invite', ['ai' => ['ever_chatted' => $lastChat !== null]]);
            }

            // nutrition_invite
            $lastNut = NutritionAiRecommendation::query()
                ->where('member_id', $member->id)
                ->max('recommendation_date');
            if ($lastNut === null || $this->isBefore($lastNut, $nutCutoff)) {
                $this->consider($member, 'iron_ai.nutrition_invite', ['ai' => ['ever_used' => $lastNut !== null]]);
            }

            // progress_invite (recomendaciones con foco de progreso)
            $lastProg = NutritionAiRecommendation::query()
                ->where('member_id', $member->id)
                ->where('context_json->focus', 'progress')
                ->max('recommendation_date');
            if ($lastProg === null || $this->isBefore($lastProg, $progCutoff)) {
                $this->consider($member, 'iron_ai.progress_invite', ['ai' => ['ever_used' => $lastProg !== null]]);
            }

            // streak_invite: entrena (activo) pero nunca registró día de racha.
            $trainsRecently = RoutineCompletion::query()
                ->where('member_id', $member->id)
                ->where('completed_at', '>=', $recentActivity->toDateTimeString())
                ->exists();
            $hasStreakActivity = MemberAppActivityDay::query()
                ->where('member_id', $member->id)
                ->exists();
            if ($trainsRecently && !$hasStreakActivity) {
                $this->consider($member, 'iron_ai.streak_invite', ['ai' => ['trains' => true, 'uses_streak' => false]]);
            }
        });
    }

    /** Compara una fecha (string|Carbon) contra un corte. */
    private function isBefore($value, $cutoff): bool
    {
        try {
            return \Carbon\CarbonImmutable::parse((string) $value)->lessThan($cutoff);
        } catch (\Throwable) {
            return false;
        }
    }
}
