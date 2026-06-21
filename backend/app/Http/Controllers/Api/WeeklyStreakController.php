<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WeeklyStreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Racha semanal "Esta semana" para la app (member autenticado).
 *
 *  POST /api/app/weekly-streak/touch → SOLO LECTURA (devuelve el resumen)
 *  GET  /api/app/weekly-streak       → devuelve el resumen sin registrar nada
 *
 * IMPORTANTE: la racha ya NO se marca al abrir la app. La ÚNICA fuente que
 * marca un día activo es la ASISTENCIA al gimnasio (entrada registrada en el
 * punto físico → AttendanceController::store llama a WeeklyStreakService::touch).
 * El endpoint /touch se conserva por compatibilidad con versiones viejas de la
 * app, pero ya no registra nada: solo devuelve el resumen igual que el GET.
 *
 * El member SIEMPRE sale de auth.member; la app nunca manda member_id ni fecha.
 */
class WeeklyStreakController extends Controller
{
    public function __construct(private readonly WeeklyStreakService $service)
    {
    }

    /**
     * POST /api/app/weekly-streak/touch
     *
     * Conservado por compatibilidad: ya NO marca el día activo (abrir la app no
     * cuenta para la racha). Solo devuelve el resumen; la racha la marca la
     * asistencia al gimnasio.
     */
    public function touch(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $data = $this->service->summary($member);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** GET /api/app/weekly-streak */
    public function show(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $data = $this->service->summary($member);

        return response()->json(['success' => true, 'data' => $data]);
    }
}
