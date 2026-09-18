<?php

namespace App\Http\Controllers\Api\Admin\Reports;

use App\Services\Reports\StaffPerformance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/reports/staff — el rendimiento del equipo.
 *
 * Tres niveles, del general al detalle, que es como se usa de verdad:
 *
 *   index    → el ranking del periodo y el listado de personas.
 *   profile  → una persona: sus cifras, qué planes vendió, en qué días trabajó.
 *   timeline → un día de esa persona, hora a hora.
 *
 * La persona se identifica por una CLAVE y no por un id ({@see StaffIdentity}):
 * hay cobros firmados solo con el nombre y otros sin firmar, y los tres casos
 * tienen que poder consultarse.
 */
class StaffController extends ReportsController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate($this->baseRules());

        $equipo = new StaffPerformance($this->range($request));

        return response()->json([
            'range' => $this->range($request)->toArray(),
            'roster' => $equipo->roster(),
            'leaderboard' => $equipo->leaderboard(),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $request->validate(array_merge($this->baseRules(), ['staff' => 'required|string|max:120']));

        $equipo = new StaffPerformance($this->range($request));

        return response()->json($equipo->profile((string) $request->query('staff')));
    }

    /** «¿Qué hizo Eduar el 12 de septiembre?», literalmente. */
    public function timeline(Request $request): JsonResponse
    {
        $request->validate([
            'staff' => 'required|string|max:120',
            'date' => 'required|date_format:Y-m-d',
        ]);

        $equipo = new StaffPerformance($this->range($request));

        return response()->json($equipo->timeline(
            (string) $request->query('staff'),
            (string) $request->query('date'),
        ));
    }
}
