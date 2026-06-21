<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Models\ProductSaleItem;
use App\Support\SseStream;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte de GANANCIAS del CRM combinando las dos fuentes de dinero:
 *   - Gimnasio  → pagos de membresías/planes (`Payment`).
 *   - Cafetería → ventas POS/app (`ProductSale` + `ProductSaleItem`).
 *
 * Devuelve ingresos y utilidad (cafetería con margen), totales, serie temporal
 * alineada por periodo (para el gráfico apilado) y desglose por método de pago.
 * GET /api/admin/earnings?from=&to=&group_by=day|month&source=all|gym|cafeteria
 */
class EarningsController extends Controller
{
    /** Estados que cuentan como dinero recibido. */
    private const GYM_PAID = ['paid', 'approved'];
    private const CAFE_PAID = ['paid', 'delivered'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date'],
            'group_by' => ['nullable', 'in:day,month'],
            'source'   => ['nullable', 'in:all,gym,cafeteria'],
        ]);

        $groupBy = $data['group_by'] ?? 'day';
        $source = $data['source'] ?? 'all';

        // Rango por defecto: mes actual.
        $from = isset($data['from'])
            ? Carbon::parse($data['from'])->startOfDay()
            : Carbon::now()->startOfMonth();
        $to = isset($data['to'])
            ? Carbon::parse($data['to'])->endOfDay()
            : Carbon::now()->endOfDay();

        $includeGym = $source === 'all' || $source === 'gym';
        $includeCafe = $source === 'all' || $source === 'cafeteria';

        // ── Totales ───────────────────────────────────────────────────────────
        $gymRevenue = 0.0;
        $gymCount = 0;
        if ($includeGym) {
            $gymRevenue = (float) Payment::whereIn('status', self::GYM_PAID)
                ->whereBetween('paid_at', [$from, $to])->sum('amount');
            $gymCount = Payment::whereIn('status', self::GYM_PAID)
                ->whereBetween('paid_at', [$from, $to])->count();
        }

        $cafeRevenue = 0.0;
        $cafeCount = 0;
        $cafeProfit = 0.0;
        if ($includeCafe) {
            $cafeRevenue = (float) ProductSale::whereIn('status', self::CAFE_PAID)
                ->whereBetween('paid_at', [$from, $to])->sum('total');
            $cafeCount = ProductSale::whereIn('status', self::CAFE_PAID)
                ->whereBetween('paid_at', [$from, $to])->count();
            $cafeProfit = (float) $this->cafeProfitQuery($from, $to)
                ->selectRaw('SUM((product_sale_items.unit_price - products.cost_price) * product_sale_items.quantity) as profit')
                ->value('profit');
        }

        // ── Serie temporal alineada por periodo ───────────────────────────────
        $gymByPeriod = $includeGym ? $this->gymSeries($from, $to, $groupBy) : [];
        $cafeByPeriod = $includeCafe ? $this->cafeSeries($from, $to, $groupBy) : [];
        $cafeProfitByPeriod = $includeCafe ? $this->cafeProfitSeries($from, $to, $groupBy) : [];

        $periods = collect(array_keys($gymByPeriod + $cafeByPeriod))->unique()->sort()->values();
        $series = $periods->map(fn (string $p) => [
            'period'          => $p,
            'gym'             => round($gymByPeriod[$p] ?? 0, 2),
            'cafeteria'       => round($cafeByPeriod[$p] ?? 0, 2),
            'total'           => round(($gymByPeriod[$p] ?? 0) + ($cafeByPeriod[$p] ?? 0), 2),
            'cafeteria_profit'=> round($cafeProfitByPeriod[$p] ?? 0, 2),
        ])->all();

        // ── Desglose por método de pago ───────────────────────────────────────
        $byMethod = [];
        if ($includeGym) {
            foreach (Payment::whereIn('status', self::GYM_PAID)->whereBetween('paid_at', [$from, $to])
                ->selectRaw('method, SUM(amount) as amount')->groupBy('method')->get() as $row) {
                $byMethod[] = ['source' => 'gym', 'method' => $row->method ?: 'otro', 'amount' => (float) $row->amount];
            }
        }
        if ($includeCafe) {
            foreach (ProductSale::whereIn('status', self::CAFE_PAID)->whereBetween('paid_at', [$from, $to])
                ->selectRaw('payment_method, SUM(total) as amount')->groupBy('payment_method')->get() as $row) {
                $byMethod[] = ['source' => 'cafeteria', 'method' => $row->payment_method ?: 'otro', 'amount' => (float) $row->amount];
            }
        }

        return response()->json([
            'range' => [
                'from'     => $from->toDateString(),
                'to'       => $to->toDateString(),
                'group_by' => $groupBy,
                'source'   => $source,
            ],
            'totals' => [
                'gym_revenue'       => round($gymRevenue, 2),
                'cafeteria_revenue' => round($cafeRevenue, 2),
                'combined_revenue'  => round($gymRevenue + $cafeRevenue, 2),
                'cafeteria_profit'  => round($cafeProfit, 2),
                // Utilidad gimnasio = ingresos (sin costo asociado).
                'combined_profit'   => round($gymRevenue + $cafeProfit, 2),
                'gym_count'         => $gymCount,
                'cafeteria_count'   => $cafeCount,
            ],
            'series'    => $series,
            'by_method' => $byMethod,
        ]);
    }

    /**
     * GET /api/admin/earnings/stream — tiempo real (SSE). Cuando entra un pago
     * del gimnasio o una venta de cafetería (o cambia su estado), los CRM con el
     * módulo abierto recargan el reporte. Firma = conteo + última modificación de
     * ambas tablas; sin crear notificaciones (no satura la campana).
     */
    public function stream(Request $request): StreamedResponse
    {
        $signature = static fn (): string =>
            Payment::count() . ':' . (string) Payment::max('updated_at') . '|' .
            ProductSale::count() . ':' . (string) ProductSale::max('updated_at');

        $last = null;

        return SseStream::response(function () use (&$last, $signature): void {
            $now = $signature();
            if ($last === null) {
                $last = $now; // línea base
                return;
            }
            if ($now !== $last) {
                $last = $now;
                SseStream::emit('earnings', ['sig' => $now]);
            }
        }, 25, 2000);
    }

    /** Ingresos del gimnasio agrupados por periodo → [periodo => total]. */
    private function gymSeries(Carbon $from, Carbon $to, string $groupBy): array
    {
        $expr = $this->periodExpr('payments.paid_at', $groupBy);

        return Payment::whereIn('status', self::GYM_PAID)
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw("$expr as period, SUM(amount) as total")
            ->groupBy('period')->orderBy('period')
            ->pluck('total', 'period')
            ->map(fn ($v) => (float) $v)->all();
    }

    /** Ingresos de cafetería agrupados por periodo → [periodo => total]. */
    private function cafeSeries(Carbon $from, Carbon $to, string $groupBy): array
    {
        $expr = $this->periodExpr('product_sales.paid_at', $groupBy);

        return ProductSale::whereIn('status', self::CAFE_PAID)
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw("$expr as period, SUM(total) as total")
            ->groupBy('period')->orderBy('period')
            ->pluck('total', 'period')
            ->map(fn ($v) => (float) $v)->all();
    }

    /** Utilidad de cafetería (venta − costo) agrupada por periodo. */
    private function cafeProfitSeries(Carbon $from, Carbon $to, string $groupBy): array
    {
        $expr = $this->periodExpr('product_sales.paid_at', $groupBy);

        return $this->cafeProfitQuery($from, $to)
            ->selectRaw("$expr as period, SUM((product_sale_items.unit_price - products.cost_price) * product_sale_items.quantity) as profit")
            ->groupBy('period')->orderBy('period')
            ->pluck('profit', 'period')
            ->map(fn ($v) => (float) $v)->all();
    }

    /** Query base de utilidad: items de ventas pagadas en rango, con su producto. */
    private function cafeProfitQuery(Carbon $from, Carbon $to)
    {
        return ProductSaleItem::query()
            ->join('product_sales', 'product_sales.id', '=', 'product_sale_items.product_sale_id')
            ->join('products', 'products.id', '=', 'product_sale_items.product_id')
            ->whereIn('product_sales.status', self::CAFE_PAID)
            ->whereBetween('product_sales.paid_at', [$from, $to]);
    }

    /**
     * Expresión SQL "periodo" (día o mes) según el motor. En PostgreSQL convierte
     * de UTC a America/Bogota para que los días cuadren con la operación local.
     */
    private function periodExpr(string $column, string $groupBy): string
    {
        $driver = DB::connection()->getDriverName();
        $fmtPg = $groupBy === 'month' ? 'YYYY-MM' : 'YYYY-MM-DD';
        $fmtSqlite = $groupBy === 'month' ? '%Y-%m' : '%Y-%m-%d';
        $fmtMysql = $groupBy === 'month' ? '%Y-%m' : '%Y-%m-%d';

        return match ($driver) {
            'pgsql'  => "TO_CHAR(($column AT TIME ZONE 'UTC' AT TIME ZONE 'America/Bogota'), '$fmtPg')",
            'sqlite' => "strftime('$fmtSqlite', $column)",
            default  => "DATE_FORMAT(CONVERT_TZ($column, '+00:00', '-05:00'), '$fmtMysql')",
        };
    }
}
