<?php

namespace App\Services\Nutrition\Ai;

use App\Models\Member;
use App\Models\NutritionAiRun;
use App\Services\Nutrition\NutritionStatsService;

/**
 * FLUJO 5 — Insights de constancia para el usuario (coach fitness, NO médico).
 * Usa métricas REALES (sin PII). Si la IA no está disponible o no hay datos
 * suficientes, degrada a insights deterministas / mensaje educativo (no rompe).
 */
class NutritionAIInsightService
{
    public function __construct(
        private NutritionAIEnrichmentService $engine,
        private NutritionStatsService $stats,
    ) {
    }

    public function insights(Member $member, string $range = 'week'): array
    {
        $stats = $this->stats->constancy($member, $range);

        // Sin registros mínimos → mensaje educativo (no se llama a la IA).
        if (($stats['summary']['days_registered'] ?? 0) < 1) {
            return [
                'ok' => true, 'ai_used' => false, 'range' => $range,
                'insights' => [[
                    'title' => 'Empieza tu constancia',
                    'body'  => 'Registra tus comidas durante el día para ver análisis de tu progreso aquí.',
                    'tone'  => 'neutral',
                ]],
            ];
        }

        $metrics = $this->metrics($stats);
        $model = config('nutrition.ai.model_insights') ?: config('services.openai.model');
        $messages = [
            ['role' => 'system', 'content' => NutritionAiPrompts::insightsSystem()],
            ['role' => 'user', 'content' => 'Métricas reales del usuario (' . $range . "):\n"
                . json_encode($metrics, JSON_UNESCAPED_UNICODE)],
        ];

        $prep = $this->engine->prepare(NutritionAiRun::MODE_INSIGHT, $member, $model, $messages,
            'ins:' . $range . ':' . md5(json_encode($metrics)), []);

        if ($prep['outcome'] === 'cache' && is_array($prep['raw'])) {
            return ['ok' => true, 'ai_used' => true, 'cached' => true, 'range' => $range,
                'insights' => $this->cleanInsights($prep['raw']['insights'] ?? [])];
        }
        if ($prep['outcome'] === 'provider' && is_array($prep['raw'])) {
            $insights = $this->cleanInsights($prep['raw']['insights'] ?? []);
            if ($insights !== []) {
                $this->engine->record(NutritionAiRun::MODE_INSIGHT, $member, [], $prep['hash'], $prep['model'],
                    NutritionAiRun::STATUS_SUCCESS, null, ['insights' => $insights]);
                return ['ok' => true, 'ai_used' => true, 'range' => $range, 'insights' => $insights];
            }
        }

        // Degradación: insights deterministas desde las métricas reales.
        return ['ok' => true, 'ai_used' => false, 'range' => $range,
            'insights' => $this->deterministic($stats)];
    }

    /** Métricas anónimas (sin nombre/teléfono/correo) para la IA. */
    private function metrics(array $stats): array
    {
        $s = $stats['summary'];
        $c = $stats['compliance'];
        return [
            'days_registered' => $s['days_registered'], 'days_total' => $s['days_total'],
            'current_streak' => $s['current_streak'], 'best_streak' => $s['best_streak'],
            'adherence_percent' => $s['adherence_percent'],
            'days_in_range' => $s['days_in_range'], 'days_below' => $s['days_below'], 'days_above' => $s['days_above'],
            'avg_vs_goal' => [
                'calories' => $c['calories']['percent'], 'protein' => $c['protein']['percent'],
                'carbs' => $c['carbs']['percent'], 'fat' => $c['fat']['percent'],
            ],
        ];
    }

    private function cleanInsights($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, 4) as $i) {
            if (! is_array($i) || empty($i['body'])) {
                continue;
            }
            $out[] = [
                'title' => mb_substr((string) ($i['title'] ?? 'Análisis'), 0, 60),
                'body'  => mb_substr((string) $i['body'], 0, 220),
                'tone'  => in_array(($i['tone'] ?? 'neutral'), ['positive', 'neutral', 'warning'], true) ? $i['tone'] : 'neutral',
            ];
        }
        return $out;
    }

    /** Insights básicos calculados (sin IA) como respaldo. */
    private function deterministic(array $stats): array
    {
        $s = $stats['summary'];
        $c = $stats['compliance'];
        $out = [];
        $out[] = [
            'title' => 'Adherencia',
            'body'  => "Registraste {$s['days_registered']} de {$s['days_total']} días. "
                . "Tu adherencia va en {$s['adherence_percent']}%.",
            'tone'  => $s['adherence_percent'] >= 70 ? 'positive' : 'neutral',
        ];
        if (($c['protein']['percent'] ?? 100) < 80) {
            $out[] = ['title' => 'Proteína', 'body' => 'Tu proteína quedó por debajo de la meta. '
                . 'Prioriza una fuente proteica en cada comida.', 'tone' => 'warning'];
        }
        if ($s['current_streak'] >= 2) {
            $out[] = ['title' => 'Racha', 'body' => "Llevas {$s['current_streak']} días seguidos registrando. ¡Sostenlo!",
                'tone' => 'positive'];
        }
        return array_slice($out, 0, 4);
    }
}
