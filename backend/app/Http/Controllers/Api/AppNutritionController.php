<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NutritionDayLog;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sincroniza el resumen nutricional diario del miembro (la nutrición se captura
 * en la app). Persiste un registro por día (upsert) y, cuando el día cumple la
 * meta, dispara la notificación/push "Día nutricional registrado".
 */
class AppNutritionController extends Controller
{
    /** POST /api/app/nutrition/day */
    public function store(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $data = $request->validate([
            'date'           => 'nullable|date',
            'calories'       => 'nullable|numeric|min:0',
            'protein'        => 'nullable|numeric|min:0',
            'carbs'          => 'nullable|numeric|min:0',
            'fat'            => 'nullable|numeric|min:0',
            'goal_calories'  => 'nullable|numeric|min:0',
            'goal_protein'   => 'nullable|numeric|min:0',
            'goal_met'       => 'nullable|boolean',
            'goal_completed' => 'nullable|boolean',
            'percentage'     => 'nullable|integer|min:0|max:999',
        ]);

        $date = $data['date'] ?? now()->toDateString();

        $log = NutritionDayLog::updateOrCreate(
            ['member_id' => $member->id, 'log_date' => $date],
            [
                'calories'      => (float) ($data['calories'] ?? 0),
                'protein'       => (float) ($data['protein'] ?? 0),
                'carbs'         => (float) ($data['carbs'] ?? 0),
                'fat'           => (float) ($data['fat'] ?? 0),
                'goal_calories' => (float) ($data['goal_calories'] ?? 0),
                'goal_protein'  => (float) ($data['goal_protein'] ?? 0),
                'goal_met'      => (bool) ($data['goal_met'] ?? false),
                'source'        => 'app',
            ],
        );

        // Push "Meta nutricional completada" (tipo nutrition) cuando la rueda
        // llega al 100%. Idempotente por día (nutrition_goal_completed_MID_FECHA):
        // varios guardados el mismo día NO duplican; al día siguiente sí notifica.
        if (! empty($data['goal_completed'])) {
            $percentage = (int) ($data['percentage'] ?? 100);
            app(NotificationService::class)
                ->notifyNutritionGoalCompleted($member, $percentage, $date);
        }

        return response()->json([
            'ok'   => true,
            'data' => [
                'id'       => $log->id,
                'log_date' => $log->log_date->toDateString(),
                'goal_met' => $log->goal_met,
            ],
        ], 201);
    }
}
