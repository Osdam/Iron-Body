<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\TrainerTask;
use App\Services\TrainerTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint INTERNO disparado por n8n (firmado HMAC, middleware
 * automation.internal). Crea una tarea para el ENTRENADOR HUMANO asignado al
 * miembro, permitiendo que n8n enriquezca el copy/estrategia del mensaje.
 *
 *  POST /api/internal/automation/notify-trainer
 *
 * Complementa a /notify-member: aquel notifica al miembro (IRON IA), este crea
 * la tarea accionable del entrenador. n8n NO accede a PostgreSQL; solo envía
 * trainer_id + member_id + copy seguro. Laravel valida la asignación real.
 */
class NotifyTrainerController extends Controller
{
    public function __construct(private readonly TrainerTaskService $service)
    {
    }

    public function notify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trainer_id'   => 'required|integer',
            'member_id'    => 'required|integer',
            'type'         => 'required|string|max:80',
            'title'        => 'required|string|max:140',
            'body'         => 'required|string|max:500',
            'priority'     => 'nullable|string|in:low,normal,high',
            'action_route' => 'nullable|string|max:200',
            'metadata'     => 'nullable|array',
            // Permite idempotencia controlada desde n8n (opcional).
            'idempotency_key'     => 'nullable|string|max:191',
            'automation_event_id' => 'nullable|integer',
        ]);

        $result = $this->service->createForTrainer(
            trainerId: $data['trainer_id'],
            memberId: $data['member_id'],
            type: $data['type'],
            title: $data['title'],
            body: $data['body'],
            priority: $data['priority'] ?? TrainerTask::PRIORITY_NORMAL,
            actionRoute: $data['action_route'] ?? null,
            metadata: $data['metadata'] ?? [],
            automationEventId: $data['automation_event_id'] ?? null,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );

        // skipped_no_assignment: el entrenador no es el activo del miembro.
        if ($result['status'] === 'skipped_no_assignment') {
            return response()->json([
                'ok'      => false,
                'status'  => $result['status'],
                'message' => 'El entrenador no está asignado a ese miembro.',
            ], 409);
        }

        return response()->json([
            'ok'      => true,
            'status'  => $result['status'], // created | skipped_duplicate | skipped_limit
            'task_id' => $result['task']?->id,
        ]);
    }
}
