<?php

namespace App\Services\Reports;

use App\Enums\CashShiftType;
use App\Http\Controllers\Api\PaymentController;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Services\Caja\CashReceipts;
use App\Support\Caja\PaymentMethodKind;
use App\Support\Caja\PaymentOrigin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * EL DINERO QUE ENTRÓ, de las tres puertas por las que entra.
 *
 *   1. Cobros de membresía   → `payments`
 *   2. Abonos de una deuda   → `receivable_payments`
 *   3. Ventas de productos   → `product_sales`
 *
 * Es la única fuente de la que beben las cifras financieras del informe, para
 * que la tarjeta, el gráfico, el desglose por medio y el listado de
 * transacciones no puedan contradecirse: todos preguntan aquí, con los mismos
 * filtros.
 *
 * LAS REGLAS, heredadas de {@see CashReceipts} —las mismas del arqueo de caja,
 * que es lo que hace que el total de un día cuadre con lo que hay en el cajón:
 *
 *  · El asiento de un plan a plazos NO es dinero. Nace por el importe completo
 *    para que la membresía exista, pero de él solo entró lo que se abonó. Se
 *    excluye, y sus abonos se cuentan uno a uno el día que llegaron.
 *  · Un abono revertido deja de ser dinero: salió del cajón.
 *  · Una venta a crédito tampoco es dinero hasta que se abona.
 *
 * TODO SE AGREGA EN LA BASE. Nada de traer las filas para sumarlas en PHP: el
 * informe se abre sobre un año entero y eso serían decenas de miles de filas
 * por tarjeta. Lo único que se trae fila a fila es la página del listado.
 */
final class MoneyLedger
{
    public const SOURCE_MEMBERSHIP = 'membership';

    public const SOURCE_INSTALLMENT = 'installment';

    public const SOURCE_PRODUCT = 'product';

    /** @var list<string> */
    public const SOURCES = [self::SOURCE_MEMBERSHIP, self::SOURCE_INSTALLMENT, self::SOURCE_PRODUCT];

    /** Techo de filas que se pueden paginar hacia el fondo del listado. */
    private const MAX_ROWS = 3000;

    private ?Collection $roles = null;

    /**
     * @param  array<string, mixed>  $filters  source, area, channel, staff, method, member_id, plan, search
     */
    public function __construct(
        private readonly ReportRange $range,
        private readonly array $filters = [],
    ) {}

    public function forRange(ReportRange $range): self
    {
        return new self($range, $this->filters);
    }

    public function withFilters(array $filters): self
    {
        return new self($this->range, array_merge($this->filters, $filters));
    }

    // ── Totales ──────────────────────────────────────────────────────────────

    /**
     * Lo recaudado en el periodo, con el desglose por puerta de entrada.
     *
     * @return array{collected: float, count: int, average: float, by_source: list<array<string,mixed>>}
     */
    public function totals(): array
    {
        $porFuente = [];
        $total = 0.0;
        $operaciones = 0;

        foreach (self::SOURCES as $fuente) {
            [$suma, $n] = $this->sumOf($fuente);
            $total += $suma;
            $operaciones += $n;
            $porFuente[] = [
                'key' => $fuente,
                'label' => self::sourceLabel($fuente),
                'amount' => round($suma, 2),
                'count' => $n,
            ];
        }

        return [
            'collected' => round($total, 2),
            'count' => $operaciones,
            // El ticket promedio: lo que deja cada operación. Con cero
            // operaciones es cero y no una división por cero.
            'average' => $operaciones > 0 ? round($total / $operaciones, 2) : 0.0,
            'by_source' => $porFuente,
        ];
    }

    /** @return array{0: float, 1: int} */
    private function sumOf(string $source): array
    {
        if (! $this->includes($source)) {
            return [0.0, 0];
        }

        $fila = match ($source) {
            self::SOURCE_MEMBERSHIP => $this->membershipPayments()
                ->selectRaw('COALESCE(SUM(payments.amount), 0) as suma, COUNT(*) as n')->first(),
            self::SOURCE_INSTALLMENT => $this->installments()
                ->selectRaw('COALESCE(SUM(receivable_payments.amount), 0) as suma, COUNT(*) as n')->first(),
            self::SOURCE_PRODUCT => $this->productSales()
                ->selectRaw('COALESCE(SUM(product_sales.total), 0) as suma, COUNT(*) as n')->first(),
        };

        return [(float) ($fila->suma ?? 0), (int) ($fila->n ?? 0)];
    }

    /**
     * Cuánto entró por cada medio de pago.
     *
     * Los cobros mixtos se reparten parte a parte: atribuir los 60.000 de un
     * «20 en efectivo + 40 con tarjeta» a un solo medio descuadraba el arqueo y
     * mentía en este desglose. Ver {@see \App\Models\PaymentSplit}.
     *
     * @return list<array{key: string, label: string, amount: float, share: float}>
     */
    public function byMethod(): array
    {
        $porMedio = array_fill_keys(PaymentMethodKind::values(), 0.0);
        $suma = function (?string $metodo, float $importe) use (&$porMedio): void {
            $porMedio[PaymentMethodKind::normalize($metodo)->value] += $importe;
        };

        if ($this->includes(self::SOURCE_MEMBERSHIP)) {
            // Cobros de un solo medio.
            foreach ($this->membershipPayments()
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('payment_splits')
                    ->whereColumn('payment_splits.payment_id', 'payments.id'))
                ->groupBy('payments.method')
                ->selectRaw('payments.method as metodo, COALESCE(SUM(payments.amount), 0) as suma')
                ->get() as $fila) {
                $suma($fila->metodo, (float) $fila->suma);
            }

            // Y cada parte de los mixtos, a su medio.
            foreach ($this->membershipPayments()
                ->join('payment_splits', 'payment_splits.payment_id', '=', 'payments.id')
                ->groupBy('payment_splits.method')
                ->selectRaw('payment_splits.method as metodo, COALESCE(SUM(payment_splits.amount), 0) as suma')
                ->get() as $fila) {
                $suma($fila->metodo, (float) $fila->suma);
            }
        }

        if ($this->includes(self::SOURCE_INSTALLMENT)) {
            foreach ($this->installments()
                ->groupBy('receivable_payments.method')
                ->selectRaw('receivable_payments.method as metodo, COALESCE(SUM(receivable_payments.amount), 0) as suma')
                ->get() as $fila) {
                $suma($fila->metodo, (float) $fila->suma);
            }
        }

        if ($this->includes(self::SOURCE_PRODUCT)) {
            foreach ($this->productSales()
                ->groupBy('product_sales.payment_method')
                ->selectRaw('product_sales.payment_method as metodo, COALESCE(SUM(product_sales.total), 0) as suma')
                ->get() as $fila) {
                $suma($fila->metodo, (float) $fila->suma);
            }
        }

        $total = array_sum($porMedio);

        return collect($porMedio)
            ->map(fn (float $importe, string $clave) => [
                'key' => $clave,
                'label' => PaymentMethodKind::from($clave)->label(),
                'amount' => round($importe, 2),
                'share' => $total > 0 ? round($importe / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * La serie del gráfico: un punto por día, semana o mes del periodo, con los
     * huecos en cero.
     *
     * Los huecos importan: sin ellos, tres días sin cobros se dibujan como una
     * línea recta entre el lunes y el viernes, y parece que el negocio no paró.
     *
     * @return list<array{period: string, label: string, membership: float, installment: float, product: float, total: float}>
     */
    public function series(?string $granularity = null): array
    {
        $grano = $granularity ?? $this->range->granularity();

        $buckets = [
            self::SOURCE_MEMBERSHIP => $this->bucketSums(self::SOURCE_MEMBERSHIP, $grano),
            self::SOURCE_INSTALLMENT => $this->bucketSums(self::SOURCE_INSTALLMENT, $grano),
            self::SOURCE_PRODUCT => $this->bucketSums(self::SOURCE_PRODUCT, $grano),
        ];

        $serie = [];
        foreach (ReportCalendar::buckets($this->range, $grano) as $punto) {
            $clave = $punto['key'];
            $m = (float) ($buckets[self::SOURCE_MEMBERSHIP][$clave] ?? 0);
            $a = (float) ($buckets[self::SOURCE_INSTALLMENT][$clave] ?? 0);
            $p = (float) ($buckets[self::SOURCE_PRODUCT][$clave] ?? 0);

            $serie[] = [
                'period' => $clave,
                'label' => $punto['label'],
                'membership' => round($m, 2),
                'installment' => round($a, 2),
                'product' => round($p, 2),
                'total' => round($m + $a + $p, 2),
            ];
        }

        return $serie;
    }

    /** @return array<string, float> */
    private function bucketSums(string $source, string $granularity): array
    {
        if (! $this->includes($source)) {
            return [];
        }

        [$query, $fecha, $importe] = match ($source) {
            self::SOURCE_MEMBERSHIP => [$this->membershipPayments(), self::PAYMENT_DATE, 'payments.amount'],
            self::SOURCE_INSTALLMENT => [$this->installments(), 'receivable_payments.created_at', 'receivable_payments.amount'],
            self::SOURCE_PRODUCT => [$this->productSales(), self::SALE_DATE, 'product_sales.total'],
        };

        // Siempre por día en la base, y las semanas o meses se pliegan después:
        // ver {@see ReportCalendar::fold()}.
        $bucket = ReportSql::businessDate($fecha);

        $porDia = $query
            ->selectRaw("{$bucket} as periodo, COALESCE(SUM({$importe}), 0) as suma")
            ->groupByRaw($bucket)
            ->pluck('suma', 'periodo')
            ->map(fn ($v) => (float) $v)
            ->all();

        return ReportCalendar::fold($porDia, $granularity);
    }

    /**
     * Cuánto cobró cada persona del equipo.
     *
     * Solo dinero de mostrador: lo que se paga por la app no lo cobra nadie, y
     * repartirlo entre el equipo inflaría a quien estuviera de turno.
     *
     * @return list<array<string, mixed>>
     */
    public function byStaff(): array
    {
        $acc = [];
        $sumar = function (?int $id, ?string $nombre, ?string $rol, float $importe, int $n, string $fuente) use (&$acc): void {
            $clave = StaffIdentity::key($id, $nombre);
            $acc[$clave] ??= [
                'key' => $clave,
                'admin_id' => $id,
                'name' => $nombre ?: StaffIdentity::UNKNOWN_LABEL,
                'role' => $rol ?: $this->roleOf($id),
                'amount' => 0.0,
                'count' => 0,
                'by_source' => array_fill_keys(self::SOURCES, 0.0),
            ];
            $acc[$clave]['amount'] += $importe;
            $acc[$clave]['count'] += $n;
            $acc[$clave]['by_source'][$fuente] += $importe;
        };

        if ($this->includes(self::SOURCE_MEMBERSHIP)) {
            foreach ($this->membershipPayments()
                ->where('payments.origin', PaymentOrigin::COUNTER->value)
                ->groupBy('payments.registered_by', 'payments.registered_by_name', 'payments.registered_by_role')
                ->selectRaw('payments.registered_by as id, payments.registered_by_name as nombre, payments.registered_by_role as rol,'
                    .' COALESCE(SUM(payments.amount), 0) as suma, COUNT(*) as n')
                ->get() as $f) {
                $sumar($f->id ? (int) $f->id : null, $f->nombre, $f->rol, (float) $f->suma, (int) $f->n, self::SOURCE_MEMBERSHIP);
            }
        }

        if ($this->includes(self::SOURCE_INSTALLMENT)) {
            foreach ($this->installments()
                ->groupBy('receivable_payments.created_by', 'receivable_payments.created_by_name')
                ->selectRaw('receivable_payments.created_by as id, receivable_payments.created_by_name as nombre,'
                    .' COALESCE(SUM(receivable_payments.amount), 0) as suma, COUNT(*) as n')
                ->get() as $f) {
                $sumar($f->id ? (int) $f->id : null, $f->nombre, null, (float) $f->suma, (int) $f->n, self::SOURCE_INSTALLMENT);
            }
        }

        if ($this->includes(self::SOURCE_PRODUCT)) {
            foreach ($this->productSales()
                ->where('product_sales.channel', 'pos')
                ->groupBy('product_sales.cashier_admin_id', 'product_sales.cashier_name')
                ->selectRaw('product_sales.cashier_admin_id as id, product_sales.cashier_name as nombre,'
                    .' COALESCE(SUM(product_sales.total), 0) as suma, COUNT(*) as n')
                ->get() as $f) {
                $sumar($f->id ? (int) $f->id : null, $f->nombre, null, (float) $f->suma, (int) $f->n, self::SOURCE_PRODUCT);
            }
        }

        return collect($acc)
            ->map(function (array $fila) {
                $fila['amount'] = round($fila['amount'], 2);
                $fila['by_source'] = array_map(fn ($v) => round((float) $v, 2), $fila['by_source']);

                return $fila;
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * Página del listado de transacciones.
     *
     * Se piden las N primeras de cada fuente y se mezclan: son tres tablas
     * distintas y un UNION portable entre PostgreSQL y SQLite obligaría a
     * igualar columnas que no se parecen. El coste está acotado por `MAX_ROWS`,
     * y para ir más allá está el filtro, que es lo que de verdad usa quien
     * busca una operación concreta.
     *
     * @return array{data: list<array<string,mixed>>, total: int, amount: float, page: int, per_page: int, last_page: int, capped: bool}
     */
    public function rows(int $page = 1, int $perPage = 25): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $hasta = min($page * $perPage, self::MAX_ROWS);

        $movimientos = collect();
        if ($this->includes(self::SOURCE_MEMBERSHIP)) {
            $movimientos = $movimientos->concat($this->membershipRows($hasta));
        }
        if ($this->includes(self::SOURCE_INSTALLMENT)) {
            $movimientos = $movimientos->concat($this->installmentRows($hasta));
        }
        if ($this->includes(self::SOURCE_PRODUCT)) {
            $movimientos = $movimientos->concat($this->productRows($hasta));
        }

        $totales = $this->totals();
        $ordenadas = $movimientos->sortByDesc('date')->values();

        return [
            'data' => $ordenadas->forPage($page, $perPage)->values()->all(),
            'total' => $totales['count'],
            'amount' => $totales['collected'],
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil(min($totales['count'], self::MAX_ROWS) / $perPage)),
            'capped' => $totales['count'] > self::MAX_ROWS,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function membershipRows(int $limit): Collection
    {
        return $this->membershipPayments()
            ->with(['user:id,name,document', 'plan:id,name', 'splits'])
            ->orderByRaw(self::PAYMENT_DATE.' DESC')
            ->limit($limit)
            ->get()
            ->map(function (Payment $p): array {
                $canal = $p->channel();

                return [
                    'key' => 'payment-'.$p->id,
                    'source' => self::SOURCE_MEMBERSHIP,
                    'source_label' => self::sourceLabel(self::SOURCE_MEMBERSHIP),
                    'id' => $p->id,
                    'date' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
                    'amount' => (float) $p->amount,
                    'method' => $p->method,
                    'method_label' => $p->isMixed()
                        ? 'Mixto'
                        : PaymentMethodKind::normalize($p->method)->label(),
                    'splits' => $p->splits->map(fn ($s) => [
                        'method_label' => PaymentMethodKind::normalize($s->method)->label(),
                        'amount' => (float) $s->amount,
                    ])->values()->all(),
                    'member_id' => $p->user_id,
                    'member_name' => $p->user?->name,
                    'member_document' => $p->user?->document,
                    'concept' => $p->plan?->name ? 'Plan '.$p->plan->name : 'Cobro sin plan',
                    'channel' => $canal,
                    'channel_label' => PaymentOrigin::channelLabelFor($canal),
                    'staff_key' => StaffIdentity::key($p->registered_by, $p->registered_by_name),
                    'staff_name' => $p->registered_by_name,
                    'staff_role' => $p->registered_by_role,
                    'cash_shift_id' => $p->cash_shift_id,
                    'reference' => $p->reference,
                    'status' => $p->status,
                    'period_start' => $p->period_start?->toDateString(),
                    'period_end' => $p->period_end?->toDateString(),
                ];
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function installmentRows(int $limit): Collection
    {
        return $this->installments()
            ->with(['receivable.member.user:id,name,document'])
            ->orderByDesc('receivable_payments.created_at')
            ->limit($limit)
            ->get(['receivable_payments.*'])
            ->map(function (ReceivablePayment $a): array {
                $socio = $a->receivable?->member?->user;

                return [
                    'key' => 'installment-'.$a->id,
                    'source' => self::SOURCE_INSTALLMENT,
                    'source_label' => self::sourceLabel(self::SOURCE_INSTALLMENT),
                    'id' => $a->id,
                    'date' => $a->created_at?->toIso8601String(),
                    'amount' => (float) $a->amount,
                    'method' => $a->method,
                    'method_label' => PaymentMethodKind::normalize($a->method)->label(),
                    'splits' => [],
                    'member_id' => $socio?->id,
                    'member_name' => $socio?->name ?? $a->receivable?->member?->full_name,
                    'member_document' => $socio?->document,
                    'concept' => 'Abono · '.($a->receivable?->concept ?? 'cuenta por cobrar'),
                    'channel' => 'gym',
                    'channel_label' => PaymentOrigin::channelLabelFor('gym'),
                    'staff_key' => StaffIdentity::key($a->created_by, $a->created_by_name),
                    'staff_name' => $a->created_by_name,
                    'staff_role' => $this->roleOf($a->created_by),
                    'cash_shift_id' => $a->cash_shift_id,
                    'reference' => $a->reference,
                    'status' => $a->status,
                    'period_start' => null,
                    'period_end' => null,
                ];
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function productRows(int $limit): Collection
    {
        return $this->productSales()
            ->with(['member.user:id,name,document'])
            ->orderByRaw(self::SALE_DATE.' DESC')
            ->limit($limit)
            ->get()
            ->map(function (ProductSale $v): array {
                $socio = $v->member?->user;

                return [
                    'key' => 'sale-'.$v->id,
                    'source' => self::SOURCE_PRODUCT,
                    'source_label' => self::sourceLabel(self::SOURCE_PRODUCT),
                    'id' => $v->id,
                    'date' => ($v->paid_at ?? $v->created_at)?->toIso8601String(),
                    'amount' => (float) $v->total,
                    'method' => $v->payment_method,
                    'method_label' => PaymentMethodKind::normalize($v->payment_method)->label(),
                    'splits' => [],
                    'member_id' => $socio?->id,
                    'member_name' => $socio?->name ?? $v->customer_name,
                    'member_document' => $socio?->document,
                    'concept' => 'Venta '.$v->code,
                    'channel' => $v->channel === 'app' ? 'app' : 'gym',
                    'channel_label' => PaymentOrigin::channelLabelFor($v->channel === 'app' ? 'app' : 'gym'),
                    'staff_key' => StaffIdentity::key($v->cashier_admin_id, $v->cashier_name),
                    'staff_name' => $v->cashier_name,
                    'staff_role' => $this->roleOf($v->cashier_admin_id),
                    'cash_shift_id' => $v->cash_shift_id,
                    'reference' => $v->payment_reference,
                    'status' => $v->status,
                    'period_start' => null,
                    'period_end' => null,
                ];
            });
    }

    /**
     * Dinero que se DEVOLVIÓ o que nunca llegó a entrar, en el periodo.
     *
     * Va aparte de los ingresos a propósito: restarlo del total escondería que
     * ocurrió. Un mes con 8.000.000 cobrados y 300.000 devueltos no es lo mismo
     * que uno con 7.700.000 limpios, y quien revisa la caja necesita ver los dos
     * números.
     *
     * @return array<string, mixed>
     */
    public function reversals(): array
    {
        [$desde, $hasta] = $this->range->utcBounds();
        $fallidos = PaymentController::FAILED_STATUSES;

        $devueltos = Payment::query()
            ->whereRaw('LOWER(status) = ?', ['refunded'])
            ->whereRaw(self::PAYMENT_DATE.' BETWEEN ? AND ?', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(amount), 0) as suma, COUNT(*) as n')->first();

        $anulados = Payment::query()
            ->whereRaw('LOWER(status) IN ('.ReportSql::marks($fallidos).')', $fallidos)
            ->whereRaw(self::PAYMENT_DATE.' BETWEEN ? AND ?', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(amount), 0) as suma, COUNT(*) as n')->first();

        $abonosAnulados = ReceivablePayment::query()
            ->where('status', ReceivablePayment::STATUS_REVERSED)
            ->whereBetween('reversed_at', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(amount), 0) as suma, COUNT(*) as n')->first();

        $ventasAnuladas = ProductSale::query()
            ->whereNotNull('cancelled_at')
            ->whereBetween('cancelled_at', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(total), 0) as suma, COUNT(*) as n')->first();

        return [
            'refunded' => ['amount' => round((float) $devueltos->suma, 2), 'count' => (int) $devueltos->n],
            'cancelled_payments' => ['amount' => round((float) $anulados->suma, 2), 'count' => (int) $anulados->n],
            'reversed_installments' => ['amount' => round((float) $abonosAnulados->suma, 2), 'count' => (int) $abonosAnulados->n],
            'cancelled_sales' => ['amount' => round((float) $ventasAnuladas->suma, 2), 'count' => (int) $ventasAnuladas->n],
        ];
    }

    /**
     * Lo que el gimnasio tiene por cobrar HOY. No es del periodo: es una foto
     * del presente, y por eso no cambia al mover las fechas.
     *
     * @return array{total: float, count: int, overdue: float, overdue_count: int, by_type: array<string, float>}
     */
    public function outstanding(): array
    {
        $cuentas = Receivable::query()->outstanding()->withSum('appliedPayments', 'amount')->get();
        $hoy = Receivable::businessToday();

        $saldo = fn (Receivable $r) => $r->balance()->toFloat();
        $vencidas = $cuentas->filter(fn (Receivable $r) => $r->isOverdue($hoy));

        return [
            'total' => round($cuentas->sum($saldo), 2),
            'count' => $cuentas->count(),
            'overdue' => round($vencidas->sum($saldo), 2),
            'overdue_count' => $vencidas->count(),
            'by_type' => [
                CashShiftType::GYM->value => round($cuentas->where('type', CashShiftType::GYM->value)->sum($saldo), 2),
                CashShiftType::PRODUCTS->value => round($cuentas->where('type', CashShiftType::PRODUCTS->value)->sum($saldo), 2),
            ],
        ];
    }

    // ── Consultas base ───────────────────────────────────────────────────────

    /** Fecha efectiva de un cobro: cuándo se pagó o, si no consta, cuándo se creó. */
    private const PAYMENT_DATE = 'COALESCE(payments.paid_at, payments.created_at)';

    private const SALE_DATE = 'COALESCE(product_sales.paid_at, product_sales.created_at)';

    /** Cobros de membresía que son dinero de verdad, dentro del periodo. */
    public function membershipPayments(): Builder
    {
        [$desde, $hasta] = $this->range->utcBounds();
        $pagados = PaymentController::PAID_STATUSES;

        $q = app(CashReceipts::class)->cashPayments()
            ->whereRaw('LOWER(payments.status) IN ('.ReportSql::marks($pagados).')', $pagados)
            ->whereRaw(self::PAYMENT_DATE.' BETWEEN ? AND ?', [$desde, $hasta]);

        if ($canal = $this->channelFilter()) {
            $origenes = array_values(array_filter(
                PaymentOrigin::cases(),
                fn (PaymentOrigin $o) => $o->channel() === $canal,
            ));
            $q->whereIn('payments.origin', array_map(fn (PaymentOrigin $o) => $o->value, $origenes));
        }

        if ($staff = $this->staffFilter()) {
            StaffIdentity::constrain($q, $staff, 'payments.registered_by', 'payments.registered_by_name');
        }

        if ($miembro = $this->memberFilter()) {
            $q->where('payments.user_id', $miembro);
        }

        if ($plan = $this->planFilter()) {
            $q->whereHas('plan', fn (Builder $p) => $p->where('plans.name', $plan));
        }

        if ($medio = $this->methodFilter()) {
            $alias = $medio->aliases();
            $q->where(function (Builder $w) use ($alias, $medio): void {
                if ($alias !== []) {
                    $w->whereRaw('LOWER(payments.method) IN ('.ReportSql::marks($alias).')', $alias)
                        ->orWhereHas('splits', fn (Builder $s) => $s
                            ->whereRaw('LOWER(payment_splits.method) IN ('.ReportSql::marks($alias).')', $alias));
                } else {
                    // OTHER es «todo lo demás»: se define por exclusión.
                    $conocidos = self::knownMethodAliases();
                    $w->whereRaw('(payments.method IS NULL OR LOWER(payments.method) NOT IN ('.ReportSql::marks($conocidos).'))', $conocidos);
                }
            });
        }

        if ($texto = $this->searchFilter()) {
            $op = ReportSql::likeOperator();
            $q->whereHas('user', fn (Builder $u) => $u
                ->where('users.name', $op, $texto)
                ->orWhere('users.document', $op, $texto));
        }

        return $q;
    }

    /** Abonos aplicados dentro del periodo. */
    public function installments(): Builder
    {
        [$desde, $hasta] = $this->range->utcBounds();

        $q = app(CashReceipts::class)->appliedInstallments()
            ->whereBetween('receivable_payments.created_at', [$desde, $hasta]);

        // Un abono se cobra siempre en el mostrador: no hay canal que filtrar,
        // así que pedir «solo app» lo deja fuera entero.
        if ($this->channelFilter() === 'app') {
            $q->whereRaw('1 = 0');
        }

        if ($staff = $this->staffFilter()) {
            StaffIdentity::constrain($q, $staff, 'receivable_payments.created_by', 'receivable_payments.created_by_name');
        }

        if ($miembro = $this->memberFilter()) {
            $q->whereHas('receivable.member', fn (Builder $m) => $m->where('members.user_id', $miembro));
        }

        if ($medio = $this->methodFilter()) {
            $alias = $medio->aliases();
            if ($alias !== []) {
                $q->whereRaw('LOWER(receivable_payments.method) IN ('.ReportSql::marks($alias).')', $alias);
            } else {
                $conocidos = self::knownMethodAliases();
                $q->whereRaw('LOWER(receivable_payments.method) NOT IN ('.ReportSql::marks($conocidos).')', $conocidos);
            }
        }

        if ($plan = $this->planFilter()) {
            // El concepto de la cuenta guarda el plan vendido: «Plan Premium».
            // Con `whereHas` y no con un join: unir la tabla cambiaría las
            // columnas que devuelve la consulta y rompería el listado.
            $q->whereHas('receivable', fn (Builder $r) => $r
                ->where('receivables.concept', ReportSql::likeOperator(), ReportSql::like($plan)));
        }

        if ($texto = $this->searchFilter()) {
            $op = ReportSql::likeOperator();
            $q->whereHas('receivable.member', fn (Builder $m) => $m
                ->where('members.full_name', $op, $texto)
                ->orWhere('members.document_number', $op, $texto));
        }

        return $q;
    }

    /** Ventas de productos cobradas dentro del periodo. */
    public function productSales(): Builder
    {
        [$desde, $hasta] = $this->range->utcBounds();

        $q = ProductSale::query()
            ->whereIn('product_sales.status', ['paid', 'delivered'])
            ->whereRaw(self::SALE_DATE.' BETWEEN ? AND ?', [$desde, $hasta]);

        if ($canal = $this->channelFilter()) {
            $q->where('product_sales.channel', $canal === 'app' ? 'app' : 'pos');
        }

        if ($staff = $this->staffFilter()) {
            StaffIdentity::constrain($q, $staff, 'product_sales.cashier_admin_id', 'product_sales.cashier_name');
        }

        if ($miembro = $this->memberFilter()) {
            $q->whereHas('member', fn (Builder $m) => $m->where('members.user_id', $miembro));
        }

        // Un plan no se vende en la cafetería: filtrar por plan deja fuera las
        // ventas de producto en vez de devolverlas todas.
        if ($this->planFilter()) {
            $q->whereRaw('1 = 0');
        }

        if ($medio = $this->methodFilter()) {
            $alias = $medio->aliases();
            if ($alias !== []) {
                $q->whereRaw('LOWER(product_sales.payment_method) IN ('.ReportSql::marks($alias).')', $alias);
            } else {
                $conocidos = self::knownMethodAliases();
                $q->whereRaw('(product_sales.payment_method IS NULL OR LOWER(product_sales.payment_method) NOT IN ('.ReportSql::marks($conocidos).'))', $conocidos);
            }
        }

        if ($texto = $this->searchFilter()) {
            $op = ReportSql::likeOperator();
            $q->where(fn (Builder $w) => $w
                ->where('product_sales.customer_name', $op, $texto)
                ->orWhere('product_sales.code', $op, $texto));
        }

        return $q;
    }

    // ── Filtros ──────────────────────────────────────────────────────────────

    private function includes(string $source): bool
    {
        $pedida = (string) ($this->filters['source'] ?? 'all');

        return $pedida === 'all' || $pedida === $source;
    }

    private function channelFilter(): ?string
    {
        $canal = (string) ($this->filters['channel'] ?? 'all');

        return in_array($canal, ['gym', 'app'], true) ? $canal : null;
    }

    private function staffFilter(): ?string
    {
        $clave = trim((string) ($this->filters['staff'] ?? ''));

        return $clave !== '' ? $clave : null;
    }

    private function memberFilter(): ?int
    {
        $id = (int) ($this->filters['member_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function planFilter(): ?string
    {
        $plan = trim((string) ($this->filters['plan'] ?? ''));

        return $plan !== '' ? $plan : null;
    }

    private function methodFilter(): ?PaymentMethodKind
    {
        $medio = trim((string) ($this->filters['method'] ?? ''));

        return $medio !== '' && $medio !== 'all' ? PaymentMethodKind::tryFrom($medio) : null;
    }

    private function searchFilter(): ?string
    {
        $texto = trim((string) ($this->filters['search'] ?? ''));

        return $texto !== '' ? ReportSql::like($texto) : null;
    }

    /** @return list<string> */
    private static function knownMethodAliases(): array
    {
        $alias = [];
        foreach (PaymentMethodKind::cases() as $medio) {
            $alias = array_merge($alias, $medio->aliases());
        }

        return $alias;
    }

    /** Rol actual de una cuenta del CRM, para las fuentes que no lo congelan. */
    private function roleOf(?int $adminId): ?string
    {
        if ($adminId === null) {
            return null;
        }

        $this->roles ??= Admin::query()->pluck('role', 'id');

        return $this->roles->get($adminId);
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            self::SOURCE_MEMBERSHIP => 'Membresías',
            self::SOURCE_INSTALLMENT => 'Abonos',
            self::SOURCE_PRODUCT => 'Productos',
            default => $source,
        };
    }
}
