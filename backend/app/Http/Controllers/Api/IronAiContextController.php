<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IronAiCoachService;
use App\Services\IronAiUserContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Contexto seguro de IRON IA para el member autenticado (debug/uso interno).
 *
 *  GET /api/app/iron-ai/context-summary
 *
 * Devuelve SOLO el contexto mínimo y seguro (perfil, membresía, progreso,
 * nutrición, racha, clases, último resumen IA). NUNCA documento, biometría,
 * firma, contrato ni datos de pago. El member sale de auth.member.
 */
class IronAiContextController extends Controller
{
    public function __construct(private readonly IronAiUserContextService $context)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $data = $this->context->build($member, [
            'profile', 'membership', 'workouts', 'streak',
            'nutrition', 'progress', 'evaluation', 'classes', 'last_ai_summary',
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/app/iron-ai/coach
     *
     * Genera el "plan de hoy" contextual (contexto + memoria → OpenAI desde
     * Laravel). Rate limit por miembro. focus: today|progress|nutrition|streak.
     */
    public function coach(Request $request, IronAiCoachService $coach): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $request->validate([
            'focus' => 'nullable|string|in:today,progress,nutrition,streak',
        ]);
        $focus = $request->input('focus', 'today');

        // Rate limit: 15 análisis por miembro por hora.
        $key = 'iron-ai-coach:' . $member->id;
        if (RateLimiter::tooManyAttempts($key, 15)) {
            return response()->json([
                'success' => false,
                'message' => 'Has alcanzado el límite de análisis por ahora. Intenta más tarde.',
            ], 429);
        }
        RateLimiter::hit($key, 3600);

        if (!$coach->isEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'El coach IRON IA no está disponible por ahora.',
            ], 503);
        }

        $data = $coach->coach($member, $focus);
        if ($data === null) {
            return response()->json([
                'success' => false,
                'message' => 'No pudimos generar tu plan. Intenta de nuevo.',
            ], 502);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }
}
