<?php

namespace App\Http\Controllers\Api\Admin\Reports;

use App\Models\Plan;
use App\Models\User;
use App\Services\Reports\SalesInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/reports/sales — qué se vendió, cuándo y quién lo vendió.
 *
 * Una venta aquí es un PLAN comprado, tanto si se pagó completo como si se
 * abrió a plazos. Por eso el importe vendido no coincide con el recaudado del
 * mismo periodo, y las dos cifras viajan juntas: lo contrario invita a leer una
 * por otra.
 */
class SalesController extends ReportsController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate($this->baseRules());

        $rango = $this->range($request);
        $ventas = new SalesInsights($rango, $this->filters($request));
        $previas = $ventas->forRange($rango->previous())->totals();
        $totales = $ventas->totals();

        return response()->json([
            'range' => $rango->toArray(),
            'totals' => [
                'count' => $this->variation($totales['count'], $previas['count']),
                'sold' => $this->variation($totales['sold'], $previas['sold']),
                'collected' => $totales['collected'],
                'average' => $this->variation($totales['average'], $previas['average']),
                'new' => $totales['new'],
                'renewals' => $totales['renewals'],
                'installment_sales' => $totales['installment_sales'],
                'members' => $totales['members'],
            ],
            'by_plan' => $ventas->byPlan(),
            'by_staff' => $ventas->byStaff(),
            'series' => $ventas->series(),
            'plans' => $this->planOptions(),
        ]);
    }

    /** El detalle de cada venta, con lo pagado y lo que queda debiendo. */
    public function rows(Request $request): JsonResponse
    {
        $request->validate($this->baseRules());

        $ventas = new SalesInsights($this->range($request), $this->filters($request));

        return response()->json($ventas->rows($this->page($request), $this->perPage($request)));
    }

    /**
     * Planes para el filtro: los del catálogo y los que aparezcan en fichas de
     * socios, porque un plan retirado sigue teniendo ventas que consultar.
     *
     * @return list<array{value: string, label: string}>
     */
    private function planOptions(): array
    {
        return Plan::query()->pluck('name')
            ->merge(User::query()->whereNotNull('plan')->where('plan', '!=', '')->distinct()->pluck('plan'))
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(fn (string $n) => ['value' => $n, 'label' => $n])
            ->all();
    }
}
