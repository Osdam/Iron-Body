<?php

namespace App\Services;

use App\Models\Member;
use App\Models\NutritionAiRecommendation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IRON IA Coach contextual.
 *
 * Une el contexto global del usuario (IronAiUserContextService) + su memoria
 * (IronAiMemoryService), llama a OpenAI DESDE LARAVEL (key solo backend), pide
 * salida estructurada y devuelve un "plan de hoy". Guarda la respuesta para
 * continuidad. No diagnostica, no prescribe, no toca pagos/biometría/documentos.
 */
class IronAiCoachService
{
    public function __construct(
        private readonly IronAiUserContextService $context,
        private readonly IronAiMemoryService $memory,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.openai.coach_enabled', true)
            && (bool) config('services.openai.enabled', true)
            && !empty(config('services.openai.api_key'));
    }

    /**
     * Genera el plan del día. `$focus` orienta la respuesta (today|progress|
     * nutrition|streak). Devuelve el array estructurado o null si IA no disponible.
     */
    public function coach(Member $member, string $focus = 'today'): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $context = $this->context->build($member, [
            'profile', 'membership', 'workouts', 'streak',
            'nutrition', 'progress', 'evaluation', 'classes', 'last_ai_summary',
            'gym_equipment',
        ]);
        $memoryEvents = $this->memory->recentImportantEvents($member, 8);
        $profile = $this->memory->profileFor($member);

        $structured = $this->callOpenAi($member, $focus, $context, $memoryEvents, $profile?->ai_memory_summary);
        if ($structured === null) {
            return null;
        }

        // Persistir para continuidad/historial (reusa la tabla de recomendaciones).
        NutritionAiRecommendation::create([
            'member_id' => $member->id,
            'recommendation_date' => CarbonImmutable::now(NutritionService::TZ)->toDateString(),
            'context_json' => ['focus' => $focus, 'context' => $context],
            'response_json' => $structured,
            'summary' => $structured['summary'] ?? null,
        ]);

        // Memoria: deja rastro de que se generó un plan (continuidad).
        $this->memory->updateProfile($member, [
            'ai_memory_summary' => $structured['summary'] ?? $profile?->ai_memory_summary,
        ]);

        return $structured;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Eres IRON IA, el coach de bienestar y entrenamiento de la app fitness Iron Body.
Hablas español claro, motivador y profesional. Recibes el CONTEXTO REAL del
usuario (perfil, objetivo, membresía, progreso, nutrición, racha, clases,
memoria reciente). Tu trabajo: darle un "plan de hoy" accionable que lo acerque
a su objetivo.

Reglas estrictas:
- Recomiendas, NO modificas datos ni ejecutas acciones.
- NO das diagnósticos médicos ni prescribes tratamientos.
- Si hay lesiones/restricciones, sé cauto y sugiere validar con su entrenador o
  un profesional de la salud.
- Si faltan datos (sin evaluación / sin nutrición / sin objetivo), pídele
  amablemente completarlos en vez de inventar.
- Usa los números reales del contexto; no inventes.
- EQUIPOS: el contexto trae `gym_equipment` con las máquinas que SÍ existen en
  el gimnasio. Es una restricción dura: NO sugieras ejercicios que requieran un
  equipo que no esté en esa lista. Si el ideal necesita una máquina inexistente,
  ofrece una variante con el equipo disponible o con peso corporal. Si la lista
  llega vacía, no asumas máquinas concretas.
- Las acciones deben apuntar a rutas reales de la app: /nutrition, /workouts,
  /progress, /evaluation, /classes, /membership.

Responde ÚNICAMENTE con un JSON válido con esta forma exacta:
{
  "title": "string corto",
  "summary": "1-2 frases con el diagnóstico del día",
  "priority": "nutrition | training | recovery | consistency | membership",
  "insights": ["observación 1", "observación 2"],
  "actions": [
    {"label": "string", "type": "route", "route": "/nutrition"}
  ]
}
PROMPT;
    }

    private function callOpenAi(Member $member, string $focus, array $context, array $memory, ?string $memorySummary): ?array
    {
        $cfg = config('services.openai');
        $started = microtime(true);

        $userContent = json_encode([
            'focus' => $focus,
            'context' => $context,
            'recent_memory' => $memory,
            'memory_summary' => $memorySummary,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::withToken($cfg['api_key'])
                ->timeout((int) ($cfg['timeout'] ?? 30))
                ->acceptJson()
                ->asJson()
                ->post(rtrim($cfg['base_url'], '/') . '/v1/chat/completions', [
                    'model' => $cfg['model'] ?? 'gpt-4.1-mini',
                    'temperature' => (float) ($cfg['temperature'] ?? 0.4),
                    'max_tokens' => 700,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => 'Contexto del usuario (JSON): ' . $userContent],
                    ],
                ]);

            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            if ($response->failed()) {
                Log::error('iron-ai-coach openai http error', [
                    'member_id' => $member->id,
                    'status' => $response->status(),
                    'latency_ms' => $latencyMs,
                ]);
                return null;
            }

            $content = data_get($response->json(), 'choices.0.message.content');
            if (!is_string($content) || trim($content) === '') {
                return null;
            }
            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                return null;
            }

            Log::info('iron-ai-coach ok', ['member_id' => $member->id, 'latency_ms' => $latencyMs, 'focus' => $focus]);
            return $this->normalize($parsed);
        } catch (Throwable $e) {
            Log::error('iron-ai-coach openai exception', [
                'member_id' => $member->id,
                'error_class' => class_basename($e),
            ]);
            return null;
        }
    }

    private function normalize(array $p): array
    {
        $insights = array_values(array_filter(array_map(
            fn ($i) => is_string($i) ? trim($i) : null,
            $p['insights'] ?? []
        )));

        $validRoutes = ['/nutrition', '/workouts', '/progress', '/evaluation', '/classes', '/membership'];
        $actions = [];
        foreach ($p['actions'] ?? [] as $a) {
            if (!is_array($a)) {
                continue;
            }
            $route = $a['route'] ?? null;
            $actions[] = [
                'label' => (string) ($a['label'] ?? ''),
                'type' => 'route',
                'route' => in_array($route, $validRoutes, true) ? $route : '/workouts',
            ];
        }

        return [
            'title' => (string) ($p['title'] ?? 'Plan para hoy'),
            'summary' => (string) ($p['summary'] ?? ''),
            'priority' => in_array($p['priority'] ?? '', ['nutrition', 'training', 'recovery', 'consistency', 'membership'], true)
                ? $p['priority']
                : 'consistency',
            'insights' => array_slice($insights, 0, 5),
            'actions' => array_slice($actions, 0, 4),
            'disclaimer' => 'Sugerencias generales de bienestar y entrenamiento. No reemplazan asesoría médica, nutricional ni profesional.',
        ];
    }
}
