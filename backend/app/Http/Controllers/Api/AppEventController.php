<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppEvent;
use Illuminate\Http\JsonResponse;

/**
 * Eventos del gimnasio para la app (Bloque 4). Solo eventos vigentes (activos y
 * no terminados), ordenados por fecha de inicio. Nada hardcodeado: todo viene
 * del CRM.
 */
class AppEventController extends Controller
{
    public function index(): JsonResponse
    {
        $events = AppEvent::visible()
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AppEvent $e) => $e->toAppArray());

        return response()->json(['ok' => true, 'data' => $events]);
    }

    public function show(AppEvent $event): JsonResponse
    {
        if (! $event->is_active) {
            return response()->json(['ok' => false, 'message' => 'Evento no disponible.'], 404);
        }
        return response()->json(['ok' => true, 'data' => $event->toAppArray()]);
    }
}
