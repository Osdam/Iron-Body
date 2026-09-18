<?php

namespace App\Http\Controllers\Api\Admin\Reports;

use App\Services\Reports\MemberInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/reports/members — la población del gimnasio.
 *
 * Cuántos son, cuántos entraron en el periodo, cuántos se fueron y cuántos de
 * los que vencieron volvieron a pagar.
 *
 * La ficha individual de un socio NO se duplica aquí: el buscador devuelve a
 * quién se refiere y el CRM abre su historial completo, que ya sirve
 * {@see \App\Http\Controllers\Api\Admin\MemberPaymentHistoryController}. Tener
 * dos sitios que cuentan la vida de un socio es tener dos sitios que pueden
 * contarla distinto.
 */
class MembersController extends ReportsController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate($this->baseRules());

        $rango = $this->range($request);
        $socios = new MemberInsights($rango);
        $previos = $socios->forRange($rango->previous());

        $totales = $socios->totals();

        return response()->json([
            'range' => $rango->toArray(),
            'totals' => $totales,
            'new' => $this->variation($totales['new'], $previos->totals()['new']),
            'signups' => $socios->signupSeries(),
            'retention' => $socios->retention(),
        ]);
    }

    /** Los que vencieron en el periodo y no han vuelto: la lista de rescate. */
    public function lapsed(Request $request): JsonResponse
    {
        $request->validate($this->baseRules());

        return response()->json((new MemberInsights($this->range($request)))->lapsed(
            $this->page($request),
            $this->perPage($request, 24),
            (string) $request->query('search', ''),
        ));
    }

    /** Buscador del selector de socio. */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'nullable|string|max:120']);

        return response()->json([
            'data' => (new MemberInsights($this->range($request)))->search((string) $request->query('q', '')),
        ]);
    }
}
