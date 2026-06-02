<?php

namespace App\Services;

use App\Models\Member;
use App\Models\NutritionAiRecommendation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IRON IA como coach nutricional. Construye un contexto SEGURO del usuario
 * (vía IronAiUserContextService), llama a OpenAI DESDE EL BACKEND (la API key
 * nunca sale de aquí), pide salida ESTRUCTURADA (json_object) y guarda la
 * recomendación en nutrition_ai_recommendations.
 *
 * Guardrails: la IA recomienda, NO modifica datos. No da diagnósticos médicos;
 * si hay lesiones/condiciones, sugiere consultar al entrenador/profesional.
 */
class NutritionAiCoachService
{
    public function __construct(
        private readonly IronAiUserContextService $contextService,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.openai.nutrition_enabled', true)
            && (bool) config('services.openai.enabled', true)
            && !empty(config('services.openai.api_key'));
    }

    /**
     * Genera la recomendación del día. Devuelve el array de datos para la app,
     * o null si la IA no está disponible (el controlador responde con cautela).
     */
    public function recommendForToday(Member $member): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Contexto mínimo y seguro: nutrición + progreso + perfil + racha.
        $context = $this->contextService->build($member, [
            'profile', 'nutrition', 'progress', 'streak', 'workouts',
        ]);

        $structured = $this->callOpenAi($member, $context);
        if ($structured === null) {
            return null;
        }

        // Persistir para auditoría/historial (sin tokens ni secretos).
        NutritionAiRecommendation::create([
            'member_id' => $member->id,
            'recommendation_date' => CarbonImmutable::now(NutritionService::TZ)->toDateString(),
            'context_json' => $context,
            'response_json' => $structured,
            'summary' => $structured['summary'] ?? null,
        ]);

        return $structured;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Eres IRON IA, el coach nutricional de la app fitness Iron Body. Hablas español
claro, motivador y profesional. Recibes un contexto REAL del usuario (meta,
consumo del día, macros, progreso, racha). Tu trabajo es dar una recomendación
breve y accionable para HOY que lo acerque a su meta.

Reglas estrictas:
- Recomiendas, NO modificas datos ni inventas que ya comió algo.
- NO das diagnósticos médicos ni tratas condiciones clínicas.
- Si el contexto incluye lesiones/restricciones, sé cauto y sugiere validar con
  su entrenador o un profesional de la salud.
- Usa los números reales del contexto (no inventes calorías consumidas).
- Sé específico pero conciso.

Responde ÚNICAMENTE con un JSON válido con esta forma exacta:
{
  "title": "string corto",
  "summary": "1-2 frases con el diagnóstico del día",
  "priority": "protein | carbs | fat | calories | hydration | general",
  "actions": ["acción 1", "acción 2", "acción 3"],
  "meal_suggestions": [
    {"meal_type": "breakfast|lunch|dinner|snacks", "title": "string", "description": "string", "estimated_calories": number, "protein_g": number}
  ],
  "warning": "string o null"
}
PROMPT;
    }

    private function callOpenAi(Member $member, array $context): ?array
    {
        $cfg = config('services.openai');
        $started = microtime(true);

        try {
            $response = Http::withToken($cfg['api_key'])
                ->timeout((int) ($cfg['timeout'] ?? 30))
                ->acceptJson()
                ->asJson()
                ->post(rtrim($cfg['base_url'], '/') . '/v1/chat/completions', [
                    'model' => $cfg['model'] ?? 'gpt-4.1-mini',
                    'temperature' => (float) ($cfg['temperature'] ?? 0.4),
                    'max_tokens' => 600,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => 'Contexto del usuario (JSON): ' . json_encode($context, JSON_UNESCAPED_UNICODE)],
                    ],
                ]);

            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            if ($response->failed()) {
                Log::error('nutrition-ai openai http error', [
                    'member_id' => $member->id,
                    'status' => $response->status(),
                    'latency_ms' => $latencyMs,
                ]);
                return null;
            }

            $content = data_get($response->json(), 'choices.0.message.content');
            if (!is_string($content) || trim($content) === '') {
                Log::error('nutrition-ai openai respuesta vacía', ['member_id' => $member->id]);
                return null;
            }

            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                Log::error('nutrition-ai openai json inválido', ['member_id' => $member->id]);
                return null;
            }

            Log::info('nutrition-ai ok', [
                'member_id' => $member->id,
                'latency_ms' => $latencyMs,
                'model' => $cfg['model'] ?? null,
            ]);

            return $this->normalize($parsed);
        } catch (Throwable $e) {
            Log::error('nutrition-ai openai exception', [
                'member_id' => $member->id,
                'error_class' => class_basename($e),
            ]);
            return null;
        }
    }

    /** Normaliza/sanea la salida del modelo a la forma esperada por la app. */
    private function normalize(array $p): array
    {
        $actions = array_values(array_filter(array_map(
            fn ($a) => is_string($a) ? trim($a) : null,
            $p['actions'] ?? []
        )));

        $suggestions = [];
        foreach ($p['meal_suggestions'] ?? [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $suggestions[] = [
                'meal_type' => in_array($s['meal_type'] ?? '', NutritionService::MEAL_TYPES, true) ? $s['meal_type'] : 'lunch',
                'title' => (string) ($s['title'] ?? ''),
                'description' => (string) ($s['description'] ?? ''),
                'estimated_calories' => (int) ($s['estimated_calories'] ?? 0),
                'protein_g' => (int) ($s['protein_g'] ?? 0),
            ];
        }

        return [
            'title' => (string) ($p['title'] ?? 'Recomendación para hoy'),
            'summary' => (string) ($p['summary'] ?? ''),
            'priority' => (string) ($p['priority'] ?? 'general'),
            'actions' => array_slice($actions, 0, 5),
            'meal_suggestions' => array_slice($suggestions, 0, 3),
            'warning' => isset($p['warning']) && is_string($p['warning']) && trim($p['warning']) !== ''
                ? trim($p['warning'])
                : null,
        ];
    }
}
