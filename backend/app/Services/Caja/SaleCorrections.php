<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Exceptions\CashShiftException;
use App\Exceptions\CorrectionException;
use App\Models\Admin;
use App\Models\CashRefund;
use App\Models\CashShift;
use App\Models\ProductSale;
use App\Models\ProductSaleItem;
use App\Models\ReceiptSource;
use App\Models\Receivable;
use App\Services\Audit\FinancialAudit;
use App\Services\Billing\InvoicingService;
use App\Services\Billing\Money;
use App\Services\Inventory\InventoryService;
use App\Services\Payments\PaymentSplits;
use App\Support\Access\CrmPermission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correcciones de una venta de la Caja de Cafetería: anular, devolver (total o
 * parcial) y corregir su medio de pago.
 *
 * ANULAR ≠ DEVOLVER (docs/ops/CORRECCIONES-ADMINISTRATIVAS.md §1). Anular dice
 * que la venta no debió existir: deja de contar en su turno (si está abierto) y
 * su deuda, si era fiada y sin abonos, se anula con ella. Devolver dice que el
 * dinero entró y sale hoy: la venta sigue contando en su turno y la salida va al
 * turno de cafetería ABIERTO.
 *
 * Orden de bloqueo, el mismo en las tres: turno (compartido) → venta → deuda →
 * productos por id. El estado se relee con la venta bloqueada; lo que dijo la
 * vista previa no se da por bueno.
 */
class SaleCorrections
{
    /** Estados en que entró dinero por la venta (y por tanto se puede devolver). */
    private const MONEY_IN = ['paid', 'delivered'];

    /** Estados en que la venta sacó producto del almacén. */
    private const STOCK_OUT = ['paid', 'delivered', ProductSale::STATUS_CREDIT];

    public function __construct(
        private readonly CorrectionGuards $guards,
        private readonly CashShiftService $shifts,
    ) {}

    // ── Anular / cancelar ───────────────────────────────────────────────────

    /**
     * Cancela un pedido pendiente o anula una venta cobrada, fiada o entregada.
     *
     * Idempotente: anular lo anulado devuelve `already` sin traza ni efectos, y
     * por eso el reingreso ocurre como mucho una vez por venta.
     *
     * @return array{sale: ProductSale, already: bool, restocked: bool, receivable_id: ?int}
     */
    public function cancel(ProductSale $sale, string $reason, ?bool $restock, ?Admin $actor, Request $request): array
    {
        return DB::transaction(function () use ($sale, $reason, $restock, $actor, $request) {
            $turno = $this->lockShiftOf($sale);
            $venta = $sale->lockFresh();

            if ($venta->status === 'cancelled') {
                return ['sale' => $venta, 'already' => true, 'restocked' => false, 'receivable_id' => null];
            }
            if ($venta->status === ProductSale::STATUS_REFUNDED) {
                throw CorrectionException::alreadyRefunded();
            }
            if ($venta->items->sum('refunded_quantity') > 0) {
                throw CorrectionException::hasRefunds();
            }
            if (! in_array($venta->status, ['pending', 'paid', 'delivered', ProductSale::STATUS_CREDIT], true)) {
                throw CorrectionException::saleNotCancellable($venta->status);
            }

            $this->guards->assertShiftAllows($turno ?? $this->shiftOf($venta), $actor);
            if ($venta->status !== 'pending') {
                $this->guards->assertInvoiceAllows($venta);
            }
            if (in_array($venta->status, self::MONEY_IN, true) && CorrectionGuards::isGatewaySale($venta)) {
                throw CorrectionException::gatewayPayment();
            }

            // La deuda de una venta fiada: sin abonos se anula aquí mismo, con
            // el mismo motivo; con abonos, primero se anulan en Créditos.
            $deudaId = null;
            if ($venta->status === ProductSale::STATUS_CREDIT) {
                $deudaId = $this->cancelCreditReceivable($venta, $reason, $actor);
            }

            $estadoPrevio = (string) $venta->status;
            $movioStock = in_array($estadoPrevio, self::STOCK_OUT, true);
            // Por defecto vuelve al inventario, salvo lo ENTREGADO: lo que el
            // cliente se llevó y consumió no está en ninguna nevera.
            $reingresa = $movioStock && ($restock ?? $estadoPrevio !== 'delivered');

            if ($reingresa) {
                app(InventoryService::class)->registerSaleReturn(
                    $venta,
                    $venta->items->mapWithKeys(fn (ProductSaleItem $i) => [$i->id => (int) $i->quantity])->all(),
                    'Anulación de la venta: '.$reason,
                    $actor,
                );
            }

            $venta->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                // El guardián de emisión lee `payment_status`: una venta anulada
                // no es dinero cobrado y no se factura.
                'payment_status' => $venta->payment_status === 'paid' ? 'cancelled' : $venta->payment_status,
            ]);

            $original = $this->shiftOf($venta);
            app(FinancialAudit::class)->saleCancelled($venta, $estadoPrevio, $reason, $reingresa, $actor, $request, array_filter([
                'shift_closed' => $original !== null && ! $original->isOpen(),
                'receivable_id' => $deudaId,
            ], static fn ($v) => $v !== null));

            return ['sale' => $venta, 'already' => false, 'restocked' => $reingresa, 'receivable_id' => $deudaId];
        }, 3);
    }

    /** Anula la deuda viva de una venta fiada, o rechaza si ya tiene abonos. */
    private function cancelCreditReceivable(ProductSale $venta, string $reason, ?Admin $actor): ?int
    {
        $deudas = Receivable::where('source_type', ProductSale::class)
            ->where('source_id', $venta->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $id = null;
        foreach ($deudas as $deuda) {
            if ($deuda->isCancelled()) {
                continue;
            }
            if ($deuda->appliedPayments()->exists()) {
                throw CorrectionException::receivableHasPayments($deuda->id);
            }
            app(ReceivableService::class)->cancel($deuda, 'Venta '.$venta->code.' anulada: '.$reason, $actor);
            $id = $deuda->id;
        }

        return $id;
    }

    // ── Devolver ────────────────────────────────────────────────────────────

    /**
     * Devuelve el dinero de una venta, entera o algunas líneas.
     *
     * @param  array{reason: string, items?: ?array<int, array{item_id: int, quantity: int}>, restock?: ?bool, method?: ?string, client_request_id?: ?string}  $input
     * @return array{sale: ProductSale, refund: ?CashRefund, already: bool, replay: bool}
     */
    public function refund(ProductSale $sale, array $input, ?Admin $actor, Request $request): array
    {
        $llave = $input['client_request_id'] ?? null;
        if ($llave !== null && $previa = CashRefund::where('client_request_id', $llave)->first()) {
            return $this->replay($previa, $sale);
        }

        try {
            return DB::transaction(fn () => $this->refundLocked($sale, $input, $actor, $request), 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($llave !== null && $previa = CashRefund::where('client_request_id', $llave)->first()) {
                return $this->replay($previa, $sale);
            }

            throw $e;
        }
    }

    /** @return array{sale: ProductSale, refund: ?CashRefund, already: bool, replay: bool} */
    private function refundLocked(ProductSale $sale, array $input, ?Admin $actor, Request $request): array
    {
        // Turno abierto PRIMERO (compartido), como toda operación que apunta
        // dinero a un turno: un cierre en paralelo espera y cuenta la salida.
        try {
            $turno = $this->shifts->lockOpen(CashShiftType::PRODUCTS);
        } catch (CashShiftException) {
            $turno = null;
        }

        $venta = $sale->lockFresh();

        // La llave, OTRA VEZ con la venta bloqueada: un reintento que esperó
        // detrás de su propia petición ve aquí la devolución ya hecha y la
        // devuelve, en vez de chocar con «no queda nada que devolver».
        $llave = $input['client_request_id'] ?? null;
        if ($llave !== null && $previa = CashRefund::where('client_request_id', $llave)->first()) {
            return $this->replay($previa, $venta);
        }

        if ($venta->status === ProductSale::STATUS_REFUNDED && empty($input['items'])) {
            return [
                'sale' => $venta, 'already' => true, 'replay' => false,
                'refund' => CashRefund::where('refundable_type', ProductSale::class)->where('refundable_id', $venta->id)->latest('id')->first(),
            ];
        }
        if ($venta->status === ProductSale::STATUS_CREDIT) {
            throw CorrectionException::useReceivableFlow(
                Receivable::where('source_type', ProductSale::class)->where('source_id', $venta->id)->value('id')
            );
        }
        if (! in_array($venta->status, self::MONEY_IN, true)) {
            throw CorrectionException::notPaid('sale_not_paid', (string) $venta->status);
        }
        if (CorrectionGuards::isGatewaySale($venta)) {
            throw CorrectionException::gatewayPayment();
        }
        if ($turno === null) {
            throw CorrectionException::noOpenShift('cafetería');
        }

        $lineas = $this->refundLines($venta, $input['items'] ?? null);
        $parcial = ! empty($input['items']) && ! $this->coversEverything($venta, $lineas);

        $bloqueo = $this->guards->invoiceBlocker($venta);
        if ($bloqueo !== null) {
            throw ($parcial && $bloqueo->code_ === 'invoice_validated')
                ? CorrectionException::partialRefundWithInvoice((int) $bloqueo->extra['invoice_id'])
                : $bloqueo;
        }
        // FACTURA AÚN NO EMITIDA y devolución parcial: la factura propia de la
        // venta se rehace por lo que queda (InvoicingService::refreshPendingSaleInvoice,
        // más abajo). La de un lote, o la de una venta sin importes por línea,
        // no se puede rehacer al centavo: 409 (ver §6 del contrato).
        if ($parcial) {
            if ($lote = $this->guards->bundleInvoiceFor($venta)) {
                throw CorrectionException::partialRefundInBundle($lote->id);
            }
            $propia = $this->guards->ownInvoiceFor($venta);
            if ($propia !== null && $venta->items->contains(fn (ProductSaleItem $i) => ! $i->hasFinancialSnapshot())) {
                throw CorrectionException::partialRefundLegacyInvoice($propia->id);
            }
        }

        $medio = $input['method'] ?? null;
        if ($medio === null) {
            if (app(PaymentSplits::class)->isMixed($venta)) {
                throw ValidationException::withMessages(['method' => [
                    'Esta venta se pagó con varios medios: indica por cuál sale la devolución.',
                ]]);
            }
            $medio = (string) $venta->payment_method;
        }
        $medio = strtolower(trim($medio));

        $estadoPrevio = (string) $venta->status;
        $reingresa = (bool) ($input['restock'] ?? $estadoPrevio !== 'delivered');
        $motivo = trim((string) $input['reason']);

        // Importe por línea con los snapshots fiscales ya congelados en la venta
        // (base + IVA − descuento repartido): la devolución no recalcula precios.
        $total = Money::zero();
        $detalle = [];
        foreach ($lineas as $l) {
            /** @var ProductSaleItem $item */
            $item = $l['item'];
            $importe = $this->lineAmount($venta, $item, $l['quantity']);
            $total = $total->plus($importe);
            $detalle[] = ['item_id' => $item->id, 'name' => $item->name, 'quantity' => $l['quantity'], 'amount' => $importe->toFloat()];
            $item->update([
                'refunded_quantity' => (int) $item->refunded_quantity + $l['quantity'],
                'refunded_amount' => Money::fromAmount($item->refunded_amount ?? 0)->plus($importe)->toDatabase(),
            ]);
        }

        if ($reingresa) {
            app(InventoryService::class)->registerSaleReturn(
                $venta,
                collect($lineas)->mapWithKeys(fn ($l) => [$l['item']->id => $l['quantity']])->all(),
                'Devolución: '.$motivo,
                $actor,
            );
        }

        $venta->load('items');
        $cambios = ['refunded_total' => Money::fromAmount($venta->refunded_total ?? 0)->plus($total)->toDatabase()];
        if ($this->fullyRefunded($venta)) {
            $cambios += ['status' => ProductSale::STATUS_REFUNDED, 'payment_status' => 'refunded'];
        }
        $venta->update($cambios);

        // La factura pendiente de una venta devuelta en parte sale por lo que
        // quedó; la de una devuelta entera ya no se emite (el guardián de
        // emisión rechaza lo que no está pagado).
        if ($venta->status !== ProductSale::STATUS_REFUNDED) {
            app(InvoicingService::class)->refreshPendingSaleInvoice($venta);
        }

        $devolucion = CashRefund::create([
            'cash_type' => CashShiftType::PRODUCTS->value,
            'cash_shift_id' => $turno->id,
            'refundable_type' => ProductSale::class,
            'refundable_id' => $venta->id,
            'amount' => $total->toDatabase(),
            'method' => $medio,
            'reason' => $motivo,
            'items' => $detalle,
            'restocked' => $reingresa,
            'client_request_id' => $input['client_request_id'] ?? null,
            'created_by' => $actor?->id,
            'created_by_name' => $actor?->name,
        ]);

        $original = $this->shiftOf($venta);
        app(FinancialAudit::class)->saleRefunded($venta, $devolucion, $estadoPrevio, $actor, $request, [
            'shift_closed' => $original !== null && ! $original->isOpen(),
            'partial' => $venta->status !== ProductSale::STATUS_REFUNDED,
        ]);

        return ['sale' => $venta, 'refund' => $devolucion, 'already' => false, 'replay' => false];
    }

    /**
     * Las líneas a devolver, validadas: de esta venta y nunca más de lo que
     * queda sin devolver. Sin `items`, todo lo que queda.
     *
     * @param  array<int, array{item_id: int, quantity: int}>|null  $pedidas
     * @return list<array{item: ProductSaleItem, quantity: int}>
     */
    private function refundLines(ProductSale $venta, ?array $pedidas): array
    {
        $porId = $venta->items->keyBy('id');
        $lineas = [];

        if (empty($pedidas)) {
            foreach ($venta->items as $item) {
                $resto = (int) $item->quantity - (int) $item->refunded_quantity;
                if ($resto > 0) {
                    $lineas[] = ['item' => $item, 'quantity' => $resto];
                }
            }
        } else {
            $sumadas = [];
            foreach ($pedidas as $p) {
                $sumadas[(int) $p['item_id']] = ($sumadas[(int) $p['item_id']] ?? 0) + (int) $p['quantity'];
            }
            foreach ($sumadas as $itemId => $cantidad) {
                $item = $porId->get($itemId);
                if ($item === null) {
                    throw ValidationException::withMessages(['items' => ["La línea #{$itemId} no es de esta venta."]]);
                }
                $resto = (int) $item->quantity - (int) $item->refunded_quantity;
                if ($cantidad > $resto) {
                    throw CorrectionException::refundExceedsSold((string) $item->name, max(0, $resto));
                }
                $lineas[] = ['item' => $item, 'quantity' => $cantidad];
            }
        }

        if ($lineas === []) {
            throw CorrectionException::nothingToRefund();
        }

        return $lineas;
    }

    /** ¿Estas líneas devuelven todo lo que quedaba? Entonces no es parcial. */
    private function coversEverything(ProductSale $venta, array $lineas): bool
    {
        $pedidas = collect($lineas)->mapWithKeys(fn ($l) => [$l['item']->id => $l['quantity']]);

        return $venta->items->every(fn (ProductSaleItem $i) => ((int) $i->quantity - (int) $i->refunded_quantity) === (int) ($pedidas[$i->id] ?? 0));
    }

    private function fullyRefunded(ProductSale $venta): bool
    {
        return $venta->items->every(fn (ProductSaleItem $i) => (int) $i->refunded_quantity >= (int) $i->quantity);
    }

    /**
     * Lo que vale devolver `$cantidad` unidades de una línea.
     *
     * El valor de la línea es su bruto congelado (`gross_amount`: base + IVA −
     * su parte del descuento). En una venta antigua sin snapshot, su parte del
     * total por subtotal. La ÚLTIMA unidad de la línea se lleva lo que quede, así
     * que devolver una venta entera —de una vez o a trozos— suma exactamente lo
     * cobrado, al centavo.
     */
    private function lineAmount(ProductSale $venta, ProductSaleItem $item, int $cantidad): Money
    {
        if ($item->gross_amount !== null) {
            $valorLinea = Money::fromAmount($item->gross_amount);
        } else {
            $subtotal = Money::fromAmount($venta->items->sum(fn ($i) => (float) $i->subtotal));
            $valorLinea = $subtotal->isZero()
                ? Money::zero()
                : Money::fromCents(intdiv(Money::fromAmount($venta->total)->cents() * Money::fromAmount($item->subtotal)->cents(), $subtotal->cents()));
        }

        $resto = (int) $item->quantity - (int) $item->refunded_quantity;
        if ($cantidad >= $resto) {
            return $valorLinea->minus(Money::fromAmount($item->refunded_amount ?? 0));
        }

        return Money::fromCents(intdiv($valorLinea->cents() * $cantidad, max(1, (int) $item->quantity)));
    }

    /** @return array{sale: ProductSale, refund: CashRefund, already: bool, replay: bool} */
    private function replay(CashRefund $previa, ProductSale $sale): array
    {
        if ($previa->refundable_type !== ProductSale::class || (int) $previa->refundable_id !== (int) $sale->id) {
            throw CorrectionException::requestIdConflict();
        }

        return ['sale' => $sale->fresh('items'), 'refund' => $previa, 'already' => false, 'replay' => true];
    }

    // ── Corregir el medio ───────────────────────────────────────────────────

    /**
     * Corrige el medio de una venta cobrada: el dinero pasa de un medio a otro
     * en el arqueo de su turno. «Mixto» exige partes que sumen el total; de
     * mixto a un medio simple, las partes se borran en la misma transacción.
     *
     * @param  array<int, array{method: string, amount: mixed, reference?: ?string}>|null  $splits
     * @return array{sale: ProductSale, already: bool}
     */
    public function correctMethod(ProductSale $sale, string $method, ?array $splits, string $reason, ?Admin $actor, Request $request): array
    {
        return DB::transaction(function () use ($sale, $method, $splits, $reason, $actor, $request) {
            $turno = $this->lockShiftOf($sale);
            $venta = $sale->lockFresh();

            if (! in_array($venta->status, self::MONEY_IN, true)) {
                throw CorrectionException::methodNotCorrectable((string) $venta->status);
            }
            if ($venta->items->sum('refunded_quantity') > 0) {
                throw CorrectionException::hasRefunds();
            }
            if (CorrectionGuards::isGatewaySale($venta)) {
                throw CorrectionException::gatewayPayment();
            }
            $this->guards->assertShiftAllows($turno ?? $this->shiftOf($venta), $actor);

            $mixto = strtolower($method) === PaymentSplits::MIXED || ! empty($splits);
            if ($mixto && empty($splits)) {
                throw ValidationException::withMessages(['splits' => ['Un cobro mixto necesita su desglose.']]);
            }
            if ($mixto) {
                app(PaymentSplits::class)->assertMatchesTotal($splits, (float) $venta->total);
            }

            $antes = $this->methodSnapshot($venta);
            $despuesPrevisto = [
                'method' => $mixto ? PaymentSplits::MIXED : $method,
                'splits' => $mixto
                    ? collect($splits)->map(fn ($p) => ['method' => (string) $p['method'], 'amount' => number_format((float) $p['amount'], 2, '.', '')])->sortBy(fn ($p) => $p['method'].$p['amount'])->values()->all()
                    : [],
            ];
            $antesOrdenado = $antes;
            $antesOrdenado['splits'] = collect($antes['splits'])->sortBy(fn ($p) => $p['method'].$p['amount'])->values()->all();
            if (strtolower((string) $antesOrdenado['method']) === strtolower((string) $despuesPrevisto['method'])
                && $antesOrdenado['splits'] == $despuesPrevisto['splits']) {
                return ['sale' => $venta, 'already' => true];
            }

            if ($mixto) {
                app(PaymentSplits::class)->sync($venta, $splits);
            } else {
                $venta->splits()->delete();
                $venta->forceFill(['payment_method' => $method])->save();
            }

            $original = $this->shiftOf($venta);
            app(FinancialAudit::class)->saleMethodChanged($venta, $antes, $this->methodSnapshot($venta), $reason, $actor, $request, [
                'shift_closed' => $original !== null && ! $original->isOpen(),
            ]);

            return ['sale' => $venta, 'already' => false];
        }, 3);
    }

    /** @return array{method: ?string, splits: list<array{method: string, amount: string}>} */
    private function methodSnapshot(ProductSale $venta): array
    {
        return [
            'method' => $venta->payment_method,
            'splits' => $venta->splits()->orderBy('id')->get(['method', 'amount'])
                ->map(fn ($p) => ['method' => (string) $p->method, 'amount' => number_format((float) $p->amount, 2, '.', '')])
                ->all(),
        ];
    }

    // ── Vista previa ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function preview(ProductSale $sale, string $accion, ?Admin $actor): array
    {
        $sale->load('items');
        $bloqueos = [];
        $bloquea = static function (?CorrectionException $e) use (&$bloqueos): void {
            if ($e !== null) {
                $bloqueos[] = $e->toBlocker();
            }
        };
        $estado = (string) $sale->status;
        $turno = $this->shiftOf($sale);
        $devoluciones = (int) $sale->items->sum('refunded_quantity');

        $permiso = $accion === 'refund' ? CrmPermission::CASH_PRODUCTS_REFUND : CrmPermission::CASH_PRODUCTS_MANAGE;
        if (! CrmPermission::allows($actor, $permiso)) {
            $bloqueos[] = ['code' => 'forbidden', 'message' => 'No tienes permiso para esta acción.', 'required_permission' => $permiso];
        }

        $deuda = Receivable::where('source_type', ProductSale::class)->where('source_id', $sale->id)->latest('id')->first();
        $refundEffect = null;
        $importe = null;

        if ($accion === 'cancel') {
            if ($estado === 'cancelled') {
                $bloqueos[] = ['code' => 'already_cancelled', 'message' => 'Esta venta ya está anulada.'];
            } elseif ($estado === ProductSale::STATUS_REFUNDED) {
                $bloquea(CorrectionException::alreadyRefunded());
            } elseif ($devoluciones > 0) {
                $bloquea(CorrectionException::hasRefunds());
            }
            if ($estado !== 'pending' && $estado !== 'cancelled') {
                $bloquea($this->guards->invoiceBlocker($sale));
            }
            if (in_array($estado, self::MONEY_IN, true) && CorrectionGuards::isGatewaySale($sale)) {
                $bloquea(CorrectionException::gatewayPayment());
            }
            if ($estado === ProductSale::STATUS_CREDIT && $deuda !== null && ! $deuda->isCancelled() && $deuda->appliedPayments()->exists()) {
                $bloquea(CorrectionException::receivableHasPayments($deuda->id));
            }
            $bloquea($this->guards->shiftBlocker($turno, $actor));
            $movio = in_array($estado, self::STOCK_OUT, true);
            $stock = $sale->items->map(fn (ProductSaleItem $i) => [
                'item_id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->name,
                'quantity' => (int) $i->quantity,
                'restockable' => $movio && $i->product_id !== null,
            ])->values()->all();
            $restockDefault = $movio && $estado !== 'delivered';
        } else {
            if ($estado === ProductSale::STATUS_CREDIT) {
                $bloquea(CorrectionException::useReceivableFlow($deuda?->id));
            } elseif ($estado === ProductSale::STATUS_REFUNDED) {
                $bloqueos[] = ['code' => 'already_refunded', 'message' => 'Esta venta ya está devuelta.'];
            } elseif (! in_array($estado, self::MONEY_IN, true)) {
                $bloquea(CorrectionException::notPaid('sale_not_paid', $estado));
            }
            if (CorrectionGuards::isGatewaySale($sale)) {
                $bloquea(CorrectionException::gatewayPayment());
            }
            $bloquea($this->guards->invoiceBlocker($sale));
            $abierto = CashShift::currentOfType(CashShiftType::PRODUCTS);
            if ($abierto === null) {
                $bloquea(CorrectionException::noOpenShift('cafetería'));
            }
            $stock = $sale->items->map(fn (ProductSaleItem $i) => [
                'item_id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->name,
                'quantity' => (int) $i->quantity,
                'refunded_quantity' => (int) $i->refunded_quantity,
                'refundable_quantity' => max(0, (int) $i->quantity - (int) $i->refunded_quantity),
                'refundable_amount' => in_array($estado, self::MONEY_IN, true)
                    ? $this->lineAmount($sale, $i, max(0, (int) $i->quantity - (int) $i->refunded_quantity))->toFloat()
                    : 0.0,
                'restockable' => $i->product_id !== null,
            ])->values()->all();
            $importe = round(array_sum(array_column($stock, 'refundable_amount')), 2);
            $refundEffect = [
                'shift_id' => $abierto?->id,
                'method' => app(PaymentSplits::class)->isMixed($sale) ? null : $sale->payment_method,
                'requires_method' => app(PaymentSplits::class)->isMixed($sale),
            ];
            $restockDefault = $estado !== 'delivered';
        }

        return [
            'action' => $accion,
            'allowed' => $bloqueos === [],
            'blockers' => array_values($bloqueos),
            'effects' => [
                'cash_shift' => $turno === null ? null : [
                    'id' => $turno->id,
                    'status' => $turno->status->value,
                    'closed_at' => optional($turno->closed_at)->toIso8601String(),
                ],
                'membership' => null,
                // La deuda de una venta fiada es de Créditos: solo con
                // `receivables.view`, igual que en la vista previa de un cobro.
                'receivable' => ($deuda === null || ! CrmPermission::allows($actor, 'receivables.view')) ? null : [
                    'id' => $deuda->id,
                    'status' => $deuda->status,
                    'balance' => $deuda->balance()->toFloat(),
                    'applied_count' => $deuda->appliedPayments()->count(),
                ],
                'invoice' => $this->guards->invoiceSummary($sale),
                'receipts' => $this->receipts($sale),
                'gateway' => CorrectionGuards::isGatewaySale($sale),
                'refund' => $refundEffect,
                'refund_amount' => $importe,
                'stock' => $stock,
                'restock_default' => $restockDefault,
            ],
        ];
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    /**
     * Bloqueo COMPARTIDO del turno de la venta, antes de la venta: un cierre en
     * paralelo espera a la corrección y la cuenta —o ya cerró, y entonces la
     * corrección se trata como posterior al cierre—.
     */
    private function lockShiftOf(ProductSale $sale): ?CashShift
    {
        $id = ProductSale::whereKey($sale->getKey())->value('cash_shift_id');

        return $id ? CashShift::whereKey($id)->sharedLock()->first() : null;
    }

    private function shiftOf(ProductSale $venta): ?CashShift
    {
        return $venta->cash_shift_id ? CashShift::find($venta->cash_shift_id) : null;
    }

    /** @return list<array{id: int, number: string}> */
    public function receipts(ProductSale $sale): array
    {
        return DB::table('receipt_sources')
            ->join('receipts', 'receipts.id', '=', 'receipt_sources.receipt_id')
            ->where('receipt_sources.source_type', ReceiptSource::TYPE_PRODUCT_SALE)
            ->where('receipt_sources.source_id', $sale->id)
            ->orderBy('receipts.id')
            ->get(['receipts.id', 'receipts.number'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'number' => (string) $r->number])
            ->all();
    }
}
