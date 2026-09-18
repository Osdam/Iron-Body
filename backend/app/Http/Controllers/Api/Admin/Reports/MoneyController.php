<?php

namespace App\Http\Controllers\Api\Admin\Reports;

use App\Services\Reports\MoneyLedger;
use App\Services\Reports\SalesInsights;
use App\Support\Caja\PaymentMethodKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/reports/money — el informe financiero.
 *
 * Todo lo que entró en el periodo, de dónde vino, por qué medio, quién lo
 * cobró, y qué se devolvió. Las cifras salen de {@see MoneyLedger}, que aplica
 * las reglas del arqueo de caja: así el total de un día cuadra con lo que hay
 * en el cajón, y no con lo que se prometió cobrar.
 *
 * `GET .../money/transactions` sirve el historial fila a fila, con los mismos
 * filtros: es el desglose que justifica cada tarjeta. Una cifra que no se puede
 * abrir no sirve para tomar decisiones.
 */
class MoneyController extends ReportsController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate($this->baseRules());

        $rango = $this->range($request);
        $filtros = $this->filters($request);

        $dinero = new MoneyLedger($rango, $filtros);
        $previo = new MoneyLedger($rango->previous(), $filtros);
        $ventas = new SalesInsights($rango, $filtros);

        $totales = $dinero->totals();
        $totalesPrevios = $previo->totals();
        $ventasTotales = $ventas->totals();

        return response()->json([
            'range' => $rango->toArray(),
            'previous_range' => $rango->previous()->toArray(),
            'totals' => [
                'collected' => $this->variation($totales['collected'], $totalesPrevios['collected']),
                'operations' => $this->variation($totales['count'], $totalesPrevios['count']),
                'average' => $this->variation($totales['average'], $totalesPrevios['average']),
                'by_source' => $totales['by_source'],
            ],
            // LAS DOS CIFRAS QUE NO SON LA MISMA, juntas y con nombre: lo que se
            // vendió (contratos) y lo que se cobró (dinero). Verlas separadas es
            // lo que evita reconocer como ingreso un plan a plazos entero.
            'sold_vs_collected' => [
                'sold' => $ventasTotales['sold'],
                'collected_from_sales' => $ventasTotales['collected'],
                'sales' => $ventasTotales['count'],
                'installment_sales' => $ventasTotales['installment_sales'],
            ],
            'by_method' => $dinero->byMethod(),
            'series' => $dinero->series(),
            'previous_series' => $previo->series(),
            'by_staff' => $dinero->byStaff(),
            'reversals' => $dinero->reversals(),
            'receivables' => $dinero->outstanding(),
            'filters' => $this->options(),
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $request->validate($this->baseRules());

        $dinero = new MoneyLedger($this->range($request), $this->filters($request));

        return response()->json($dinero->rows($this->page($request), $this->perPage($request)));
    }

    /**
     * Las opciones de los desplegables, servidas por el mismo sitio que las
     * aplica: si el CRM las escribiera por su cuenta, un medio nuevo tendría
     * que añadirse en dos lugares y el filtro fallaría en silencio.
     */
    private function options(): array
    {
        return [
            'methods' => array_map(
                fn (PaymentMethodKind $m) => ['value' => $m->value, 'label' => $m->label()],
                PaymentMethodKind::cases(),
            ),
            'sources' => array_map(
                fn (string $s) => ['value' => $s, 'label' => MoneyLedger::sourceLabel($s)],
                MoneyLedger::SOURCES,
            ),
            'channels' => [
                ['value' => 'gym', 'label' => 'Gimnasio (caja)'],
                ['value' => 'app', 'label' => 'App'],
            ],
        ];
    }
}
