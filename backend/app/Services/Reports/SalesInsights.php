<?php

namespace App\Services\Reports;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Payment;
use App\Models\Receivable;
use App\Services\Caja\CashReceipts;
use App\Support\Caja\PaymentOrigin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * LO QUE SE VENDIÓ, que no es lo mismo que lo que se cobró.
 *
 * Una venta es un plan que alguien compró. El dinero de esa venta puede entrar
 * completo ese día, a plazos durante dos meses, o no entrar nunca. Mezclar las
 * dos cosas es el error clásico de los informes de gimnasio: un plan de 200.000
 * vendido con 80.000 de entrada se cuenta como 200.000 de ingreso y el mes
 * cuadra mal para siempre.
 *
 * Aquí conviven las dos cifras, separadas y con nombre propio:
 *
 *   VENDIDO   → el valor del contrato. Lo que el socio se comprometió a pagar.
 *   COBRADO   → lo que de esas ventas ha entrado, incluidos los abonos
 *               posteriores, esté donde esté el calendario.
 *
 * NUEVA O RENOVACIÓN. Se decide por el historial del socio: si antes de esta
 * venta ya tenía otro plan pagado, es una renovación. No se usa el estado de la
 * membresía, que cambia con el tiempo y haría que el mismo informe diera
 * resultados distintos según el día en que se mire.
 */
final class SalesInsights
{
    public function __construct(
        private readonly ReportRange $range,
        private readonly array $filters = [],
    ) {}

    public function forRange(ReportRange $range): self
    {
        return new self($range, $this->filters);
    }

    /**
     * @return array{count: int, sold: float, collected: float, average: float, new: int, renewals: int, installment_sales: int, members: int}
     */
    public function totals(): array
    {
        $fila = $this->sales()
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(payments.amount), 0) as vendido,'
                .' COUNT(DISTINCT payments.user_id) as socios')
            ->first();

        $renovaciones = (clone $this->sales())->whereExists($this->previousSaleExists())->count();
        $aPlazos = (clone $this->sales())
            ->whereRaw('LOWER(payments.method) = ?', [CashReceipts::ACCRUAL_METHOD])->count();

        $n = (int) ($fila->n ?? 0);
        $vendido = (float) ($fila->vendido ?? 0);

        return [
            'count' => $n,
            'sold' => round($vendido, 2),
            'collected' => round($this->collected(), 2),
            'average' => $n > 0 ? round($vendido / $n, 2) : 0.0,
            'new' => $n - $renovaciones,
            'renewals' => $renovaciones,
            'installment_sales' => $aPlazos,
            'members' => (int) ($fila->socios ?? 0),
        ];
    }

    /**
     * Dinero entrado por las ventas del periodo.
     *
     * Las de contado valen su importe. Las de plazos valen lo que lleven
     * abonado —da igual cuándo—, porque preguntar «de lo que vendí en
     * septiembre, ¿cuánto he cobrado?» incluye el abono que llegó en octubre.
     */
    private function collected(): float
    {
        $contado = (float) (clone $this->sales())
            ->whereRaw('(payments.method IS NULL OR LOWER(payments.method) <> ?)', [CashReceipts::ACCRUAL_METHOD])
            ->sum('payments.amount');

        $idsAPlazos = (clone $this->sales())
            ->whereRaw('LOWER(payments.method) = ?', [CashReceipts::ACCRUAL_METHOD])
            ->pluck('payments.id');

        if ($idsAPlazos->isEmpty()) {
            return $contado;
        }

        $abonado = (float) Receivable::query()
            ->where('source_type', Payment::class)
            ->whereIn('source_id', $idsAPlazos->all())
            ->join('receivable_payments', 'receivable_payments.receivable_id', '=', 'receivables.id')
            ->where('receivable_payments.status', 'applied')
            ->sum('receivable_payments.amount');

        return $contado + $abonado;
    }

    /**
     * Ventas por plan: cuántas y por cuánto. Es la respuesta a «¿qué se vende
     * de verdad aquí?», y el orden lo decide el dinero, no el número.
     *
     * @return list<array{plan: string, count: int, sold: float, share: float}>
     */
    public function byPlan(): array
    {
        $filas = $this->sales()
            ->leftJoin('plans', 'plans.id', '=', 'payments.plan_id')
            ->groupByRaw("COALESCE(plans.name, 'Sin plan')")
            ->selectRaw("COALESCE(plans.name, 'Sin plan') as plan, COUNT(*) as n, COALESCE(SUM(payments.amount), 0) as vendido")
            ->get();

        $total = (float) $filas->sum('vendido');

        return $filas
            ->map(fn ($f) => [
                'plan' => (string) $f->plan,
                'count' => (int) $f->n,
                'sold' => round((float) $f->vendido, 2),
                'share' => $total > 0 ? round((float) $f->vendido / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('sold')
            ->values()
            ->all();
    }

    /**
     * Quién vendió. Para un plan a plazos el vendedor es quien abrió la venta,
     * no quien cobre después cada abono: son dos trabajos distintos y este
     * informe mide el primero.
     *
     * @return list<array<string, mixed>>
     */
    public function byStaff(): array
    {
        $acc = [];

        foreach ($this->sales()
            ->groupBy('payments.registered_by', 'payments.registered_by_name', 'payments.registered_by_role')
            ->selectRaw('payments.registered_by as id, payments.registered_by_name as nombre, payments.registered_by_role as rol,'
                .' COUNT(*) as n, COALESCE(SUM(payments.amount), 0) as vendido')
            ->get() as $f) {
            $clave = StaffIdentity::key($f->id ? (int) $f->id : null, $f->nombre);
            $acc[$clave] = [
                'key' => $clave,
                'admin_id' => $f->id ? (int) $f->id : null,
                'name' => $f->nombre ?: StaffIdentity::UNKNOWN_LABEL,
                'role' => $f->rol,
                'count' => (int) $f->n,
                'sold' => round((float) $f->vendido, 2),
            ];
        }

        return collect($acc)->sortByDesc('sold')->values()->all();
    }

    /**
     * Serie de ventas del periodo: importe vendido y número de ventas.
     *
     * @return list<array{period: string, label: string, sold: float, count: int}>
     */
    public function series(?string $granularity = null): array
    {
        $grano = $granularity ?? $this->range->granularity();
        $bucket = ReportSql::businessDate(self::SALE_DATE);

        $filas = $this->sales()
            ->selectRaw("{$bucket} as periodo, COALESCE(SUM(payments.amount), 0) as vendido, COUNT(*) as n")
            ->groupByRaw($bucket)
            ->get();

        $importes = ReportCalendar::fold($filas->pluck('vendido', 'periodo')->map(fn ($v) => (float) $v)->all(), $grano);
        $conteos = ReportCalendar::fold($filas->pluck('n', 'periodo')->map(fn ($v) => (float) $v)->all(), $grano);

        return array_map(fn (array $p) => [
            'period' => $p['key'],
            'label' => $p['label'],
            'sold' => round((float) ($importes[$p['key']] ?? 0), 2),
            'count' => (int) ($conteos[$p['key']] ?? 0),
        ], ReportCalendar::buckets($this->range, $grano));
    }

    /**
     * El detalle de cada venta, paginado.
     *
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function rows(int $page = 1, int $perPage = 25): array
    {
        $perPage = max(1, min(100, $perPage));

        $pagina = $this->sales()
            ->with(['user:id,name,document', 'plan:id,name,duration_days', 'splits'])
            ->orderByRaw(self::SALE_DATE.' DESC')
            ->paginate($perPage, ['payments.*'], 'page', max(1, $page));

        // Saldos de las ventas a plazos, en una sola consulta.
        $cuentas = $this->receivablesFor($pagina->getCollection());
        $previas = $this->previousSaleIds($pagina->getCollection());

        return [
            'data' => $pagina->getCollection()->map(function (Payment $p) use ($cuentas, $previas): array {
                $cuenta = $cuentas->get($p->id);
                $esPlazos = strtolower((string) $p->method) === CashReceipts::ACCRUAL_METHOD;
                $cobrado = $esPlazos
                    ? (float) ($cuenta?->applied_payments_sum_amount ?? 0)
                    : (float) $p->amount;

                return [
                    'id' => $p->id,
                    'date' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
                    'member_id' => $p->user_id,
                    'member_name' => $p->user?->name,
                    'member_document' => $p->user?->document,
                    'plan' => $p->plan?->name ?? 'Sin plan',
                    'plan_days' => (int) ($p->plan?->duration_days ?? 0),
                    'sold' => (float) $p->amount,
                    'collected' => round($cobrado, 2),
                    'balance' => round(max(0, (float) $p->amount - $cobrado), 2),
                    'kind' => $previas->contains($p->id) ? 'renewal' : 'new',
                    'kind_label' => $previas->contains($p->id) ? 'Renovación' : 'Nueva',
                    'payment_mode' => $esPlazos ? 'installments' : 'full',
                    'method_label' => $esPlazos
                        ? 'Plan a plazos'
                        : ($p->isMixed() ? 'Mixto' : \App\Support\Caja\PaymentMethodKind::normalize($p->method)->label()),
                    'channel' => $p->channel(),
                    'channel_label' => PaymentOrigin::channelLabelFor($p->channel()),
                    'staff_key' => StaffIdentity::key($p->registered_by, $p->registered_by_name),
                    'staff_name' => $p->registered_by_name,
                    'staff_role' => $p->registered_by_role,
                    'period_start' => $p->period_start?->toDateString(),
                    'period_end' => $p->period_end?->toDateString(),
                    'status' => $p->status,
                    'reference' => $p->reference,
                ];
            })->values()->all(),
            'total' => $pagina->total(),
            'page' => $pagina->currentPage(),
            'per_page' => $pagina->perPage(),
            'last_page' => $pagina->lastPage(),
        ];
    }

    /**
     * Las ventas del periodo: cobros con plan, incluido el asiento de un plan a
     * plazos —que es una venta de verdad aunque su dinero llegue después—.
     */
    public function sales(): Builder
    {
        [$desde, $hasta] = $this->range->utcBounds();
        $pagados = PaymentController::PAID_STATUSES;

        $q = Payment::query()
            ->whereNotNull('payments.plan_id')
            ->whereRaw('LOWER(payments.status) IN ('.ReportSql::marks($pagados).')', $pagados)
            ->whereRaw(self::SALE_DATE.' BETWEEN ? AND ?', [$desde, $hasta]);

        if ($staff = trim((string) ($this->filters['staff'] ?? ''))) {
            StaffIdentity::constrain($q, $staff, 'payments.registered_by', 'payments.registered_by_name');
        }

        if ($plan = trim((string) ($this->filters['plan'] ?? ''))) {
            $q->whereHas('plan', fn (Builder $p) => $p->where('plans.name', $plan));
        }

        if ($canal = (string) ($this->filters['channel'] ?? 'all')) {
            if (in_array($canal, ['gym', 'app'], true)) {
                $origenes = array_map(
                    fn (PaymentOrigin $o) => $o->value,
                    array_filter(PaymentOrigin::cases(), fn (PaymentOrigin $o) => $o->channel() === $canal),
                );
                $q->whereIn('payments.origin', $origenes);
            }
        }

        if ($miembro = (int) ($this->filters['member_id'] ?? 0)) {
            $q->where('payments.user_id', $miembro);
        }

        if ($tipo = (string) ($this->filters['kind'] ?? '')) {
            if ($tipo === 'renewal') {
                $q->whereExists($this->previousSaleExists());
            } elseif ($tipo === 'new') {
                $q->whereNotExists($this->previousSaleExists());
            }
        }

        if ($texto = trim((string) ($this->filters['search'] ?? ''))) {
            $op = ReportSql::likeOperator();
            $like = ReportSql::like($texto);
            $q->whereHas('user', fn (Builder $u) => $u
                ->where('users.name', $op, $like)
                ->orWhere('users.document', $op, $like));
        }

        return $q;
    }

    private const SALE_DATE = 'COALESCE(payments.paid_at, payments.created_at)';

    /**
     * «¿Este socio ya había comprado un plan antes de esta venta?». Se usa como
     * subconsulta correlacionada para no traerse el historial entero.
     */
    private function previousSaleExists(): \Closure
    {
        $pagados = PaymentController::PAID_STATUSES;

        return function ($q) use ($pagados): void {
            $q->select(\Illuminate\Support\Facades\DB::raw(1))
                ->from('payments as anteriores')
                ->whereColumn('anteriores.user_id', 'payments.user_id')
                ->whereColumn('anteriores.id', '<>', 'payments.id')
                ->whereNotNull('anteriores.plan_id')
                ->whereRaw('LOWER(anteriores.status) IN ('.ReportSql::marks($pagados).')', $pagados)
                ->whereRaw('COALESCE(anteriores.paid_at, anteriores.created_at) < COALESCE(payments.paid_at, payments.created_at)');
        };
    }

    /**
     * De una página de ventas, cuáles son renovaciones. Una sola consulta en
     * vez de una por fila.
     *
     * @param  Collection<int, Payment>  $ventas
     * @return Collection<int, int>
     */
    private function previousSaleIds(Collection $ventas): Collection
    {
        if ($ventas->isEmpty()) {
            return collect();
        }

        return Payment::query()
            ->whereIn('payments.id', $ventas->pluck('id')->all())
            ->whereExists($this->previousSaleExists())
            ->pluck('payments.id');
    }

    /**
     * Cuentas por cobrar de las ventas a plazos de una página, con lo abonado.
     *
     * @param  Collection<int, Payment>  $ventas
     * @return Collection<int, Receivable>
     */
    private function receivablesFor(Collection $ventas): Collection
    {
        $ids = $ventas
            ->filter(fn (Payment $p) => strtolower((string) $p->method) === CashReceipts::ACCRUAL_METHOD)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return Receivable::query()
            ->where('source_type', Payment::class)
            ->whereIn('source_id', $ids->all())
            ->withSum('appliedPayments', 'amount')
            ->get()
            ->keyBy('source_id');
    }
}
