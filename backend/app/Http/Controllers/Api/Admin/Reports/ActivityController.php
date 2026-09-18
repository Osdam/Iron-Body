<?php

namespace App\Http\Controllers\Api\Admin\Reports;

use App\Services\Reports\ActivityFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/reports/activity — quién hizo qué en el CRM.
 *
 * Exige `audit.view`, que es el permiso más restringido del sistema y que ni
 * siquiera tiene el rol Administrador por defecto: aquí se ve quién anuló un
 * cobro y quién cambió una membresía, y eso es material de supervisión, no de
 * consulta diaria. Lo impone la ruta en el servidor; esconder la pestaña en el
 * navegador no protegería nada.
 */
class ActivityController extends ReportsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(array_merge($this->baseRules(), [
            'module' => 'nullable|string|max:80',
            'action' => 'nullable|string|max:40',
            'entity' => 'nullable|string|max:80',
        ]));

        $filtros = array_merge($this->filters($request), array_filter([
            'module' => $request->query('module'),
            'action' => $request->query('action'),
            'entity' => $request->query('entity'),
        ]));

        $feed = new ActivityFeed($this->range($request), $filtros);

        return response()->json(array_merge(
            $feed->page($this->page($request), $this->perPage($request, 30)),
            [
                'range' => $this->range($request)->toArray(),
                'facets' => $feed->facets(),
            ],
        ));
    }
}
