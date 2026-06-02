<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\TrainerTask;
use App\Services\TrainerTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tareas/alertas del entrenador humano para el CRM (Angular).
 *
 * NOTA DE SEGURIDAD: sigue el patrón actual del CRM, donde las rutas /admin/*
 * NO llevan middleware de auth propio (lo protege la capa de red/front del CRM).
 * Los entrenadores TODAVÍA no tienen login propio en el backend, por eso el
 * consumo principal es el CRM/admin (no la app). Cuando exista auth de
 * entrenador, estos endpoints pueden reutilizarse scoping por trainer_id.
 */
class TrainerTaskController extends Controller
{
    public function __construct(private readonly TrainerTaskService $service)
    {
    }

    /** GET /api/admin/trainers/{trainer}/tasks?status=&priority=&per_page= */
    public function index(Request $request, Trainer $trainer): JsonResponse
    {
        $status = $request->query('status');     // pending|seen|done|dismissed
        $priority = $request->query('priority');  // low|normal|high
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $query = TrainerTask::query()
            ->with('member:id,full_name')
            ->where('trainer_id', $trainer->id);

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }
        if (is_string($priority) && $priority !== '') {
            $query->where('priority', $priority);
        }

        // Orden: prioridad (high→low) y luego más recientes.
        $page = $query
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")
            ->latest('created_at')
            ->paginate($perPage);

        return response()->json([
            'ok'   => true,
            'data' => collect($page->items())->map(fn (TrainerTask $t) => $t->toPublicArray())->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    /** GET /api/admin/trainers/{trainer}/tasks/unread-count */
    public function unreadCount(Trainer $trainer): JsonResponse
    {
        return response()->json([
            'ok'      => true,
            'pending' => $this->service->pendingCountForTrainer($trainer->id),
        ]);
    }

    /** POST /api/admin/trainer-tasks/{id}/seen */
    public function seen(int $id): JsonResponse
    {
        return $this->respond($this->service->markSeen($id));
    }

    /** POST /api/admin/trainer-tasks/{id}/complete */
    public function complete(int $id): JsonResponse
    {
        return $this->respond($this->service->markDone($id));
    }

    /** POST /api/admin/trainer-tasks/{id}/dismiss */
    public function dismiss(int $id): JsonResponse
    {
        return $this->respond($this->service->markDismissed($id));
    }

    /** GET /api/admin/members/{member}/coach-timeline — historial coach del miembro. */
    public function memberTimeline(Member $member): JsonResponse
    {
        $tasks = TrainerTask::query()
            ->where('member_id', $member->id)
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (TrainerTask $t) => $t->toPublicArray())
            ->all();

        return response()->json([
            'ok'        => true,
            'member_id' => $member->id,
            'data'      => $tasks,
        ]);
    }

    private function respond(?TrainerTask $task): JsonResponse
    {
        if ($task === null) {
            return response()->json(['ok' => false, 'message' => 'Tarea no encontrada.'], 404);
        }
        return response()->json(['ok' => true, 'data' => $task->toPublicArray()]);
    }
}
