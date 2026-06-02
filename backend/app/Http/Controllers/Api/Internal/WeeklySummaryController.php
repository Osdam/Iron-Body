<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\AutomationEventService;
use App\Services\IronAiCoachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint INTERNO disparado por n8n (firmado HMAC, middleware
 * automation.internal). n8n solo coordina: NO construye contexto ni accede a
 * PostgreSQL. Laravel construye el contexto seguro, llama al coach (OpenAI desde
 * Laravel), guarda el resumen y emite iron_ai.weekly_summary_ready.
 *
 *  POST /api/internal/automation/weekly-summary  { "member_id": 123 }
 */
class WeeklySummaryController extends Controller
{
    public function __construct(
        private readonly IronAiCoachService $coach,
        private readonly AutomationEventService $events,
    ) {
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_id' => 'required|integer|exists:members,id',
        ]);

        $member = Member::find($data['member_id']);
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Miembro no encontrado.'], 404);
        }

        if (!$this->coach->isEnabled()) {
            return response()->json(['success' => false, 'message' => 'Coach IA no disponible.'], 503);
        }

        // Laravel construye el contexto y llama al coach (foco semanal).
        $plan = $this->coach->coach($member, 'progress');
        if ($plan === null) {
            return response()->json(['success' => false, 'message' => 'No se pudo generar el resumen.'], 502);
        }

        // El coach ya persistió la recomendación; recuperamos su id.
        $summaryId = \App\Models\NutritionAiRecommendation::query()
            ->where('member_id', $member->id)
            ->latest('id')
            ->value('id');

        // Emite el evento de "resumen listo" hacia n8n (payload mínimo seguro).
        $event = $this->events->emit('iron_ai.weekly_summary_ready', $member->id, [
            'member_id' => $member->id,
            'summary_id' => $summaryId,
            'priority' => $plan['priority'] ?? 'consistency',
            'safe_message' => 'Resumen semanal listo',
        ], 'iron_ai.weekly_summary_ready:' . $member->id . ':' . now()->toDateString());

        return response()->json([
            'success' => true,
            'data' => [
                'member_id' => $member->id,
                'summary_id' => $summaryId,
                'priority' => $plan['priority'] ?? 'consistency',
                'event_status' => $event->status,
            ],
        ]);
    }
}
