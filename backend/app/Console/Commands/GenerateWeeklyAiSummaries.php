<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\AutomationEventService;
use App\Services\IronAiCoachService;
use App\Models\NutritionAiRecommendation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Genera el resumen semanal IA de cada miembro activo (coach con OpenAI desde
 * Laravel) y emite iron_ai.weekly_summary_ready. Idempotente por (member, semana).
 *
 *   php artisan ironbody:generate-weekly-ai-summaries
 *   php artisan ironbody:generate-weekly-ai-summaries --limit=50
 *
 * Si el coach IA está deshabilitado, no hace nada (no rompe).
 */
class GenerateWeeklyAiSummaries extends Command
{
    protected $signature = 'ironbody:generate-weekly-ai-summaries {--limit=200 : Máx. miembros por corrida}';
    protected $description = 'Genera resúmenes semanales IA y emite iron_ai.weekly_summary_ready.';

    public function handle(IronAiCoachService $coach, AutomationEventService $events): int
    {
        if (!$coach->isEnabled()) {
            $this->warn('Coach IA deshabilitado: no se generan resúmenes.');
            return self::SUCCESS;
        }

        $weekTag = CarbonImmutable::now('America/Bogota')->format('o-\WW');
        $limit = max(1, (int) $this->option('limit'));
        $count = 0;

        Member::query()
            ->where('status', Member::STATUS_ACTIVE)
            ->limit($limit)
            ->get()
            ->each(function (Member $member) use ($coach, $events, $weekTag, &$count) {
                // Idempotencia: si ya se emitió esta semana, saltar (evita re-gastar IA).
                $alreadyEmitted = \App\Models\AutomationEvent::query()
                    ->where('event_type', 'iron_ai.weekly_summary_ready')
                    ->where('idempotency_key', 'iron_ai.weekly_summary_ready:' . $member->id . ':' . $weekTag)
                    ->exists();
                if ($alreadyEmitted) {
                    return;
                }

                $plan = $coach->coach($member, 'progress');
                if ($plan === null) {
                    return;
                }

                $summaryId = NutritionAiRecommendation::query()
                    ->where('member_id', $member->id)
                    ->latest('id')
                    ->value('id');

                $events->emit('iron_ai.weekly_summary_ready', $member->id, [
                    'member_id' => $member->id,
                    'summary_id' => $summaryId,
                    'priority' => $plan['priority'] ?? 'consistency',
                    'safe_message' => 'Resumen semanal listo',
                ], 'iron_ai.weekly_summary_ready:' . $member->id . ':' . $weekTag);

                $count++;
            });

        $this->info("Resúmenes semanales generados: {$count}");
        return self::SUCCESS;
    }
}
