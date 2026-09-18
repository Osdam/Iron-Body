<?php

namespace App\Http\Controllers\Api\Admin\Reports;

use App\Services\Reports\MembershipInsights;
use App\Services\Reports\SalesInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/reports/memberships — el estado de las membresías.
 *
 * Mezcla dos relojes y lo dice: el ESTADO es de hoy (quién puede entrar, a
 * quién se le vence) y las VENTAS son del periodo elegido. Confundirlos es lo
 * que hacía que «membresías vendidas» y «membresías activas» parecieran
 * contradecirse.
 *
 * Las listas —por vencer y vencidas— son lo que de verdad se usa: son la lista
 * de a quién llamar, ordenada por urgencia y con el teléfono a mano.
 */
class MembershipsController extends ReportsController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate($this->baseRules());

        $rango = $this->range($request);
        $insights = app(MembershipInsights::class);
        $ventas = new SalesInsights($rango, $this->filters($request));
        $totales = $ventas->totals();
        $previas = $ventas->forRange($rango->previous())->totals();

        return response()->json([
            'range' => $rango->toArray(),
            'snapshot' => $insights->snapshot(),
            'by_plan' => $insights->byPlan(),
            'expiring_buckets' => $insights->expiringBuckets(),
            'overdue_buckets' => $insights->overdueBuckets(),
            'sold' => [
                'count' => $this->variation($totales['count'], $previas['count']),
                'new' => $totales['new'],
                'renewals' => $this->variation($totales['renewals'], $previas['renewals']),
                'amount' => $this->variation($totales['sold'], $previas['sold']),
                'by_plan' => $ventas->byPlan(),
                'series' => $ventas->series(),
            ],
        ]);
    }

    /** Las que vencen dentro de N días: la lista de llamadas de esta semana. */
    public function expiring(Request $request): JsonResponse
    {
        $request->validate(array_merge($this->baseRules(), ['days' => 'nullable|integer|min:0|max:120']));

        return response()->json(app(MembershipInsights::class)->expiring(
            (int) $request->query('days', 7),
            $this->page($request),
            $this->perPage($request, 24),
            (string) $request->query('search', ''),
        ));
    }

    /** Las vencidas, por antigüedad: quién lleva cuánto tiempo fuera. */
    public function expired(Request $request): JsonResponse
    {
        $request->validate(array_merge($this->baseRules(), [
            'bucket' => 'nullable|in:1_3,4_7,8_15,16_30,30_plus',
        ]));

        return response()->json(app(MembershipInsights::class)->expired(
            $request->query('bucket'),
            $this->page($request),
            $this->perPage($request, 24),
            (string) $request->query('search', ''),
        ));
    }
}
