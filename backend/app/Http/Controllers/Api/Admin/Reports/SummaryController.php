<?php

namespace App\Http\Controllers\Api\Admin\Reports;

use App\Services\Reports\MemberInsights;
use App\Services\Reports\MembershipInsights;
use App\Services\Reports\MoneyLedger;
use App\Services\Reports\ReportRange;
use App\Services\Reports\SalesInsights;
use App\Services\Reports\StaffPerformance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/reports/summary — la portada del centro de informes.
 *
 * Contesta de un vistazo las preguntas que se hacen todos los días: cuánto
 * entró hoy, cómo va el mes, cuántos socios hay dentro, a cuántos se les vence
 * esta semana y quién está vendiendo.
 *
 * DOS RELOJES, a propósito. Las tarjetas de «hoy / ayer / semana / mes» NO
 * dependen del filtro de fechas: son el pulso del negocio y tienen que estar
 * ahí aunque se esté mirando marzo. Todo lo demás sí responde al periodo
 * elegido, y cada bloque dice a cuál de los dos pertenece.
 */
class SummaryController extends ReportsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate($this->baseRules());

        $rango = $this->range($request);
        $filtros = $this->filters($request);

        $dinero = new MoneyLedger($rango, $filtros);
        $anterior = new MoneyLedger($rango->previous(), $filtros);
        $ventas = new SalesInsights($rango, $filtros);
        $membresias = app(MembershipInsights::class);
        $socios = new MemberInsights($rango);

        $totales = $dinero->totals();
        $totalesPrevios = $anterior->totals();
        $ventasTotales = $ventas->totals();

        return response()->json([
            'range' => $rango->toArray(),
            'previous_range' => $rango->previous()->toArray(),

            // El pulso: siempre el mismo, mire donde mire el filtro.
            'pulse' => $this->pulse($filtros),

            'money' => [
                'collected' => $this->variation($totales['collected'], $totalesPrevios['collected']),
                'operations' => $this->variation($totales['count'], $totalesPrevios['count']),
                'average' => $this->variation($totales['average'], $totalesPrevios['average']),
                'by_source' => $totales['by_source'],
                'by_method' => $dinero->byMethod(),
                'series' => $dinero->series(),
            ],

            'sales' => [
                'count' => $this->variation($ventasTotales['count'], $ventas->forRange($rango->previous())->totals()['count']),
                'sold' => $this->variation($ventasTotales['sold'], $ventas->forRange($rango->previous())->totals()['sold']),
                'collected' => $ventasTotales['collected'],
                'new' => $ventasTotales['new'],
                'renewals' => $ventasTotales['renewals'],
                'top_plans' => array_slice($ventas->byPlan(), 0, 5),
            ],

            'memberships' => [
                'snapshot' => $membresias->snapshot(),
                'expiring' => $membresias->expiringBuckets(),
                'overdue' => $membresias->overdueBuckets(),
                'by_plan' => $membresias->byPlan(),
            ],

            'members' => [
                'totals' => $socios->totals(),
                'signups' => $socios->signupSeries(),
                'retention' => $socios->retention(),
            ],

            'staff' => array_slice((new StaffPerformance($rango))->leaderboard(), 0, 5),

            'receivables' => $dinero->outstanding(),
            'reversals' => $dinero->reversals(),
        ]);
    }

    /**
     * Hoy, ayer, esta semana y este mes, cada uno con su comparación natural.
     *
     * Son cuatro preguntas distintas y no cuatro filtros: «¿cuánto llevo hoy?»
     * se responde contra ayer, y «¿cómo va el mes?» contra el mes pasado hasta
     * el mismo día, que es la única comparación honesta a mitad de mes.
     *
     * @return list<array<string, mixed>>
     */
    private function pulse(array $filtros): array
    {
        $tarjetas = [];

        foreach (['today', 'yesterday', 'this_week', 'this_month'] as $preset) {
            $rango = ReportRange::fromRequest(['preset' => $preset]);
            $actual = (new MoneyLedger($rango, $filtros))->totals();
            $previo = (new MoneyLedger($rango->previous(), $filtros))->totals();

            $tarjetas[] = [
                'key' => $preset,
                'label' => $rango->toArray()['label'],
                'range' => $rango->toArray(),
                'collected' => $this->variation($actual['collected'], $previo['collected']),
                'operations' => $actual['count'],
                'comparison_label' => match ($preset) {
                    'today' => 'vs. ayer',
                    'yesterday' => 'vs. anteayer',
                    'this_week' => 'vs. semana pasada',
                    default => 'vs. mes pasado',
                },
            ];
        }

        return $tarjetas;
    }
}
