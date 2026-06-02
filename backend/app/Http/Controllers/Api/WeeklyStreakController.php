<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WeeklyStreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Racha semanal "Esta semana" para la app (member autenticado).
 *
 *  POST /api/app/weekly-streak/touch → marca hoy como activo + devuelve resumen
 *  GET  /api/app/weekly-streak       → devuelve el resumen sin registrar nada
 *
 * El member SIEMPRE sale de auth.member; la app nunca manda member_id ni fecha.
 */
class WeeklyStreakController extends Controller
{
    public function __construct(private readonly WeeklyStreakService $service)
    {
    }

    /** POST /api/app/weekly-streak/touch */
    public function touch(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $data = $this->service->touch($member);

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
