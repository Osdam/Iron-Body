<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Models\ProductSaleItem;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Enums\CashShiftType;
use App\Support\SseStream;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * El `Payment` que deja una venta a plazos NO es dinero recibido.
     *
     * Nace por el importe COMPLETO del plan para que la membresía exista y el
     * historial del socio la enseñe, pero de ese plan solo ha entrado la parte
     * que se pagó. Contarlo aquí haría que el día de la venta esta pantalla
     * dijera 200.000 mientras en el cajón había 80.000. El dinero de verdad son
     * los abonos, y esos se suman aparte, cada uno el día que entró.
     *
     * Las ventas a crédito de cafetería ya quedaban fuera por su estado
     * (`credit` no está en CAFE_PAID); lo que faltaba era sumar sus abonos.
     */
    private const ACCRUAL_METHOD = 'receivable';

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'group_by' => ['nullable', 'in:day,month'],
            'source' => ['nullable', 'in:all,gym,cafeteria'],
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
            $gymRevenue = (float) $this->cashPayments()
                ->whereBetween('paid_at', [$from, $to])->sum('amount')
                + (float) $this->receivedQuery(CashShiftType::GYM, $from, $to)->sum('amount');
            $gymCount = $this->cashPayments()
                ->whereBetween('paid_at', [$from, $to])->count()
                + $this->receivedQuery(CashShiftType::GYM, $from, $to)->count();
        }

        $cafeRevenue = 0.0;
        $cafeCount = 0;
        $cafeProfit = 0.0;
        if ($includeCafe) {
            $cafeRevenue = (float) ProductSale::whereIn('status', self::CAFE_PAID)
                ->whereBetween('paid_at', [$from, $to])->sum('total')
                + (float) $this->receivedQuery(CashShiftType::PRODUCTS, $from, $to)->sum('amount');
            $cafeCount = ProductSale::whereIn('status', self::CAFE_PAID)
                ->whereBetween('paid_at', [$from, $to])->count()
                + $this->receivedQuery(CashShiftType::PRODUCTS, $from, $to)->count();
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
            'period' => $p,
            'gym' => round($gymByPeriod[$p] ?? 0, 2),
            'cafeteria' => round($cafeByPeriod[$p] ?? 0, 2),
            'total' => round(($gymByPeriod[$p] ?? 0) + ($cafeByPeriod[$p] ?? 0), 2),
            'cafeteria_profit' => round($cafeProfitByPeriod[$p] ?? 0, 2),
        ])->all();

        // ── Desglose por método de pago ───────────────────────────────────────
        $byMethod = [];
        if ($includeGym) {
            // Los cobros de un solo medio se agrupan por su columna; los mixtos
            // se reparten desde payment_splits, cada parte a su medio. Sin esta
            // segunda consulta, un cobro repartido aparecería entero bajo
            // «mixed» y el desglose dejaría de decir por dónde entró el dinero.
            $simples = $this->cashPayments()
                ->whereBetween('paid_at', [$from, $to])
                ->whereDoesntHave('splits')
                ->selectRaw('method, SUM(amount) as amount')
                ->groupBy('method')
                ->get();

            $partes = DB::table('payment_splits')
                ->join('payments', 'payments.id', '=', 'payment_splits.payment_id')
                ->whereIn('payments.status', self::GYM_PAID)
                ->where('payments.method', '<>', self::ACCRUAL_METHOD)
                ->whereBetween('payments.paid_at', [$from, $to])
                ->groupBy('payment_splits.method')
                ->selectRaw('payment_splits.method as method, SUM(payment_splits.amount) as amount')
                ->get();

            // Los abonos entran por su propio medio: un plan a plazos empezado
            // en efectivo y terminado por transferencia son dos medios, no uno.
            $abonos = $this->receivedQuery(CashShiftType::GYM, $from, $to)
                ->groupBy('method')
                ->selectRaw('method, SUM(amount) as amount')
                ->get();

            $acumulado = [];
            foreach ($simples->concat($partes)->concat($abonos) as $row) {
                $clave = $row->method ?: 'otro';
                $acumulado[$clave] = ($acumulado[$clave] ?? 0) + (float) $row->amount;
            }
            foreach ($acumulado as $metodo => $importe) {
                $byMethod[] = ['source' => 'gym', 'method' => $metodo, 'amount' => round($importe, 2)];
            }
        }
        if ($includeCafe) {
            foreach (ProductSale::whereIn('status', self::CAFE_PAID)->whereBetween('paid_at', [$from, $to])
                ->selectRaw('payment_method, SUM(total) as amount')->groupBy('payment_method')->get() as $row) {
                $byMethod[] = ['source' => 'cafeteria', 'method' => $row->payment_method ?: 'otro', 'amount' => (float) $row->amount];
            }
            foreach ($this->receivedQuery(CashShiftType::PRODUCTS, $from, $to)
                ->groupBy('method')->selectRaw('method, SUM(amount) as amount')->get() as $row) {
                $byMethod[] = ['source' => 'cafeteria', 'method' => $row->method ?: 'otro', 'amount' => (float) $row->amount];
            }
        }

        return response()->json([
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'group_by' => $groupBy,
                'source' => $source,
            ],
            'totals' => [
                'gym_revenue' => round($gymRevenue, 2),
                'cafeteria_revenue' => round($cafeRevenue, 2),
                'combined_revenue' => round($gymRevenue + $cafeRevenue, 2),
                'cafeteria_profit' => round($cafeProfit, 2),
                // Utilidad gimnasio = ingresos (sin costo asociado).
                'combined_profit' => round($gymRevenue + $cafeProfit, 2),
                'gym_count' => $gymCount,
                'cafeteria_count' => $cafeCount,
            ],
            'series' => $series,
            'by_method' => $byMethod,
        ]);
    }

    /**
     * GET /api/admin/earnings/stream — tiempo real (SSE). Cuando entra un pago
     * del gimnasio o una venta de cafetería (o cambia su estado), los CRM con el
     * módulo abierto recargan el reporte. Firma = conteo + última modificación de
     * las TRES tablas de dinero; sin crear notificaciones (no satura la campana).
     */
    public function stream(Request $request): StreamedResponse
    {
        // Los abonos entran en la firma: sin ellos, cobrar un plazo movía el
        // dinero del informe y la pantalla abierta seguía enseñando el anterior.
        //
        // Y las cuentas también, porque hay cambios que el CRM debe ver y que no
        // mueven un peso: pactar o corregir una fecha límite no crea ningún
        // abono, así que sin esta parte la pantalla seguiría diciendo "al día"
        // sobre una deuda que acaba de vencer.
        $signature = static fn (): string => Payment::count().':'.(string) Payment::max('updated_at').'|'.
            ProductSale::count().':'.(string) ProductSale::max('updated_at').'|'.
            ReceivablePayment::count().':'.(string) ReceivablePayment::max('updated_at').'|'.
            Receivable::count().':'.(string) Receivable::max('updated_at');

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

        $pagos = $this->cashPayments()
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw("$expr as period, SUM(amount) as total")
            ->groupBy('period')->orderBy('period')
            ->pluck('total', 'period')
            ->map(fn ($v) => (float) $v)->all();

        return $this->mergePeriods($pagos, $this->receivedSeries(CashShiftType::GYM, $from, $to, $groupBy));
    }

    /** Ingresos de cafetería agrupados por periodo → [periodo => total]. */
    private function cafeSeries(Carbon $from, Carbon $to, string $groupBy): array
    {
        $expr = $this->periodExpr('product_sales.paid_at', $groupBy);

        $ventas = ProductSale::whereIn('status', self::CAFE_PAID)
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw("$expr as period, SUM(total) as total")
            ->groupBy('period')->orderBy('period')
            ->pluck('total', 'period')
            ->map(fn ($v) => (float) $v)->all();

        return $this->mergePeriods($ventas, $this->receivedSeries(CashShiftType::PRODUCTS, $from, $to, $groupBy));
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
     * Los pagos de gimnasio que SÍ son dinero.
     *
     * Todos menos el apunte contable del plan a plazos. `method` puede ser nulo
     * en cobros antiguos, y un nulo tiene que seguir contando: comparar con
     * `<>` a secas los dejaría fuera sin que nadie lo pidiera.
     */
    private function cashPayments(): Builder
    {
        return Payment::whereIn('status', self::GYM_PAID)
            ->where(function (Builder $q): void {
                $q->whereNull('method')->orWhere('method', '<>', self::ACCRUAL_METHOD);
            });
    }

    /**
     * Abonos aplicados de UNA caja, por la fecha en que entró el dinero.
     *
     * `created_at` y no la fecha de la deuda: el día que cuenta es el día que se
     * cobró. Los anulados quedan fuera por su estado, así que revertir un abono
     * lo saca del informe igual que lo saca del arqueo.
     */
    private function receivedQuery(CashShiftType $type, Carbon $from, Carbon $to): Builder
    {
        return ReceivablePayment::query()
            ->where('receivable_payments.status', ReceivablePayment::STATUS_APPLIED)
            ->whereBetween('receivable_payments.created_at', [$from, $to])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('receivables')
                ->whereColumn('receivables.id', 'receivable_payments.receivable_id')
                ->where('receivables.type', $type->value));
    }

    /** Abonos de una caja agrupados por periodo → [periodo => total]. */
    private function receivedSeries(CashShiftType $type, Carbon $from, Carbon $to, string $groupBy): array
    {
        $expr = $this->periodExpr('receivable_payments.created_at', $groupBy);

        return $this->receivedQuery($type, $from, $to)
            ->selectRaw("$expr as period, SUM(amount) as total")
            ->groupBy('period')->orderBy('period')
            ->pluck('total', 'period')
            ->map(fn ($v) => (float) $v)->all();
    }

    /**
     * Suma dos mapas [periodo => importe] sin perder los periodos que solo
     * aparecen en uno: un día puede tener abonos y ninguna venta.
     *
     * @param  array<string, float>  $a
     * @param  array<string, float>  $b
     * @return array<string, float>
     */
    private function mergePeriods(array $a, array $b): array
    {
        foreach ($b as $periodo => $importe) {
            $a[$periodo] = ($a[$periodo] ?? 0) + $importe;
        }

        return $a;
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
            'pgsql' => "TO_CHAR(($column AT TIME ZONE 'UTC' AT TIME ZONE 'America/Bogota'), '$fmtPg')",
            'sqlite' => "strftime('$fmtSqlite', $column)",
            default => "DATE_FORMAT(CONVERT_TZ($column, '+00:00', '-05:00'), '$fmtMysql')",
        };
    }
}
