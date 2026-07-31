<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSale;
use App\Rules\DeliverableInvoiceEmail;
use App\Services\Billing\InvoiceEmail;
use App\Services\Billing\InvoicingService;
use App\Services\Billing\Money;
use App\Services\Billing\PricingException;
use App\Services\Billing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Caja / Punto de venta (CRM).
 *
 * Dos funciones:
 *  • Registrar ventas en mostrador (POS): elegir productos, cobrar, descontar stock.
 *  • Gestionar los pedidos que llegan de la Tienda de la app: confirmar pago en
 *    caja, marcar entregado o cancelar.
 *
 * Patrón /admin/* del CRM. Este módulo se restringirá luego a ciertos usuarios.
 */
class CajaController extends Controller
{
    // GET /api/admin/caja/sales
    public function index(Request $request): JsonResponse
    {
        $query = ProductSale::query()->with(['items', 'member:id,full_name', 'electronicInvoice'])->latest('id');

        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->boolean('today')) {
            $query->whereDate('created_at', now()->toDateString());
        }

        return response()->json([
            'data' => $query->limit(200)->get()->map(fn (ProductSale $s) => $this->serialize($s)),
        ]);
    }

    // GET /api/admin/caja/stats
    public function stats(): JsonResponse
    {
        $today = ProductSale::whereDate('created_at', now()->toDateString());

        return response()->json([
            'sales_today' => (clone $today)->where('status', '!=', 'cancelled')->count(),
            'revenue_today' => (float) (clone $today)->whereIn('status', ['paid', 'delivered'])->sum('total'),
            'pending_app' => ProductSale::app()->where('status', 'pending')->count(),
            'to_deliver' => ProductSale::where('status', 'paid')->count(),
        ]);
    }

    // GET /api/admin/caja/sales/{sale}
    public function show(ProductSale $sale): JsonResponse
    {
        $sale->load(['items', 'member:id,full_name', 'electronicInvoice']);

        return response()->json(['data' => $this->serialize($sale)]);
    }

    /**
     * POST /api/admin/caja/sales — venta en mostrador (POS).
     * body: { items:[{product_id, quantity}], payment_method, customer_name?, discount?, paid? }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            // Cantidad estrictamente positiva y acotada: sin 0 ni negativos.
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'payment_method' => ['required', Rule::in(ProductSale::PAYMENT_METHODS)],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'paid' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            // Factura electrónica: OPT-IN explícito del cajero a petición del
            // cliente. Sin esto la venta no se factura, por mucho que esté
            // cobrada. Nunca se activa por defecto.
            'request_invoice' => ['nullable', 'boolean'],
            'invoice_email' => ['nullable', 'email', 'max:160', new DeliverableInvoiceEmail],
        ]);

        $this->assertInvoiceRequestIsComplete($data);

        try {
            $sale = DB::transaction(fn () => $this->buildSale($data, $request));
        } catch (PricingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // La solicitud se guarda ANTES de cobrar: si el cajero la marcó, debe
        // quedar registrada aunque el cobro se confirme después (venta a
        // crédito, entrega diferida) o aunque el encolado falle.
        $this->persistInvoiceRequest($sale, $data);

        // En POS normalmente se cobra al instante → descuenta stock.
        if ($data['paid'] ?? true) {
            $sale->load('items');
            $sale->markPaid($data['payment_method']);
            $this->enqueueInvoice($sale);
        }

        return response()->json(['data' => $this->serialize($sale->fresh(['items', 'member:id,full_name']))], 201);
    }

    // POST /api/admin/caja/sales/{sale}/pay   { payment_method?, payment_reference? }
    public function pay(Request $request, ProductSale $sale): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['nullable', Rule::in(ProductSale::PAYMENT_METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            // El cliente puede pedir la factura al pagar, aunque no la pidiera
            // al crear la venta.
            'request_invoice' => ['nullable', 'boolean'],
            'invoice_email' => ['nullable', 'email', 'max:160', new DeliverableInvoiceEmail],
        ]);

        $this->assertInvoiceRequestIsComplete($data);
        $this->persistInvoiceRequest($sale, $data);

        $sale->load('items');
        $sale->markPaid($data['payment_method'] ?? null, $data['payment_reference'] ?? null);
        $this->enqueueInvoice($sale->fresh('items'));

        return response()->json(['data' => $this->serialize($sale->fresh(['items', 'member:id,full_name']))]);
    }

    // POST /api/admin/caja/sales/{sale}/deliver
    public function deliver(ProductSale $sale): JsonResponse
    {
        $sale->markDelivered();

        return response()->json(['data' => $this->serialize($sale->fresh('items'))]);
    }

    // POST /api/admin/caja/sales/{sale}/cancel
    public function cancel(ProductSale $sale): JsonResponse
    {
        $sale->cancel();

        return response()->json(['data' => $this->serialize($sale->fresh('items'))]);
    }

    /**
     * Arma la venta cotizando CADA línea con PricingService y congelando su
     * snapshot fiscal.
     *
     * El descuento de mostrador se reparte proporcionalmente entre las líneas
     * (por base gravable) para que quede correctamente representado en el
     * comprobante: antes se restaba del total pero se enviaba discount_rate=0.00
     * a Factus, con lo que el total calculado por Factus no podía coincidir con
     * el cobrado. El último renglón absorbe el residuo del reparto, de modo que
     * la suma de descuentos por línea cuadra al centavo con el descuento total.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws PricingException si un producto gravable no tiene tarifa, la
     *                          cantidad es inválida o el descuento es excesivo.
     */
    private function buildSale(array $data, Request $request): ProductSale
    {
        $pricing = app(PricingService::class);
        $discount = Money::fromAmount($data['discount'] ?? 0);

        /** @var array<int,array{product:Product, quantity:int, base:Money}> $prepared */
        $prepared = [];
        $baseTotal = Money::zero();

        // 1ª pasada: cotizar sin descuento para conocer la base gravable total.
        foreach ($data['items'] as $line) {
            $product = Product::with('taxRate')->findOrFail($line['product_id']);
            $quantity = (int) $line['quantity'];
            $quote = $pricing->quoteForProduct($product, $quantity);

            $prepared[] = ['product' => $product, 'quantity' => $quantity, 'base' => $quote->baseAmount];
            $baseTotal = $baseTotal->plus($quote->baseAmount);
        }

        if ($discount->greaterThan($baseTotal) && ! $baseTotal->isZero()) {
            throw PricingException::discountTooLarge(
                'el descuento ('.$discount->toDecimalString().') supera la base gravable de la venta ('
                .$baseTotal->toDecimalString().').'
            );
        }

        $sale = ProductSale::create([
            'channel' => 'pos',
            'status' => 'pending',
            'cashier_user_id' => optional($request->user())->id,
            'customer_name' => $data['customer_name'] ?? null,
            'payment_method' => $data['payment_method'],
            'discount' => $discount->toDatabase(),
            'notes' => $data['notes'] ?? null,
        ]);

        $subtotal = Money::zero();   // bruto de mostrador (semántica histórica)
        $baseSum = Money::zero();
        $taxSum = Money::zero();
        $grossSum = Money::zero();
        $discountAssigned = Money::zero();
        $lastIndex = count($prepared) - 1;

        // 2ª pasada: repartir el descuento y congelar el snapshot de cada línea.
        foreach ($prepared as $i => $row) {
            $lineDiscount = $i === $lastIndex
                ? $discount->minus($discountAssigned)          // residuo exacto
                : $this->proportionalShare($discount, $row['base'], $baseTotal);
            $discountAssigned = $discountAssigned->plus($lineDiscount);

            $quote = $pricing->quoteForProduct($row['product'], $row['quantity'], $lineDiscount);

            $unitPrice = Money::fromAmount($row['product']->sale_price);
            $lineSubtotal = $unitPrice->multipliedBy($row['quantity']);
            $subtotal = $subtotal->plus($lineSubtotal);
            $baseSum = $baseSum->plus($quote->baseAmount);
            $taxSum = $taxSum->plus($quote->taxAmount);
            $grossSum = $grossSum->plus($quote->grossAmount);

            $sale->items()->create([
                'product_id' => $row['product']->id,
                'name' => $row['product']->name,
                'unit_price' => $unitPrice->toDatabase(),
                'quantity' => $row['quantity'],
                'subtotal' => $lineSubtotal->toDatabase(),
                // Snapshot fiscal congelado de la línea.
                'base_unit_amount' => $quote->unitBaseAmount->toDatabase(),
                'tax_unit_amount' => Money::fromCents(intdiv($quote->taxAmount->cents(), $row['quantity']))->toDatabase(),
                'gross_unit_amount' => Money::fromCents(intdiv($quote->grossAmount->cents(), $row['quantity']))->toDatabase(),
                'base_amount' => $quote->baseAmount->toDatabase(),
                'tax_amount' => $quote->taxAmount->toDatabase(),
                'gross_amount' => $quote->grossAmount->toDatabase(),
                'tax_rate_id' => $quote->taxRateId,
                'tax_rate' => $quote->taxRateString(),
                'pricing_mode' => $quote->pricingMode->value,
                'pricing_rules_version' => $quote->pricingRulesVersion,
            ]);
        }

        $sale->update([
            'subtotal' => $subtotal->toDatabase(),
            // `total` es lo que se cobra en caja: base + IVA - descuento.
            'total' => $grossSum->toDatabase(),
            // Snapshot fiscal de la venta.
            'base_amount' => $baseSum->toDatabase(),
            'tax_amount' => $taxSum->toDatabase(),
            'gross_amount' => $grossSum->toDatabase(),
            'pricing_mode' => count($prepared) > 0
                ? $prepared[0]['product']->pricingMode()->value
                : null,
            'pricing_rules_version' => PricingService::RULES_VERSION,
            'priced_at' => now(),
        ]);

        return $sale;
    }

    /** Parte proporcional del descuento para una línea, en aritmética entera. */
    private function proportionalShare(Money $discount, Money $lineBase, Money $totalBase): Money
    {
        if ($discount->isZero() || $totalBase->isZero()) {
            return Money::zero();
        }

        return Money::fromCents(
            intdiv($discount->cents() * $lineBase->cents(), $totalBase->cents())
        );
    }

    /**
     * Facturación electrónica de la venta (best-effort). Crea el comprobante
     * 'pending'; NO emite a Factus salvo que FACTUS_PRODUCT_SALES_AUTO_EMIT esté
     * activo (o se emita manualmente). Nunca rompe la venta. Consumidor final si
     * faltan datos fiscales.
     */
    private function enqueueInvoice(ProductSale $sale): void
    {
        // `force` sólo cuando el cliente pidió la factura: replica el
        // comportamiento de la app (la solicitud expresa manda sobre auto_emit).
        $sale = $sale->fresh('items');
        app(InvoicingService::class)->enqueueForSale($sale, force: (bool) $sale->invoice_requested);
    }

    // ── Factura electrónica solicitada en mostrador ─────────────────────────

    /**
     * Si se pide factura, hace falta un correo al que mandarla.
     *
     * Se comprueba ANTES de cobrar para que el cajero corrija en el momento, con
     * el cliente delante, en vez de descubrirlo cuando la emisión ya falló.
     *
     * @param  array<string,mixed>  $data
     */
    private function assertInvoiceRequestIsComplete(array $data): void
    {
        if (! (bool) ($data['request_invoice'] ?? false)) {
            return;
        }

        if (InvoiceEmail::normalizar($data['invoice_email'] ?? null) === null) {
            throw ValidationException::withMessages([
                'invoice_email' => ['Para solicitar la factura electrónica hace falta un correo real del cliente.'],
            ]);
        }
    }

    /**
     * Guarda la solicitud en la VENTA (no en una transacción de pasarela): una
     * venta de mostrador no tiene pasarela, y aun así puede requerir factura.
     *
     * @param  array<string,mixed>  $data
     */
    private function persistInvoiceRequest(ProductSale $sale, array $data): void
    {
        if (! (bool) ($data['request_invoice'] ?? false)) {
            return;
        }

        $sale->marcarFacturaSolicitada($data['invoice_email'] ?? null);
    }

    private function serialize(ProductSale $sale): array
    {
        return array_merge($sale->toReceiptArray(), [
            'id' => $sale->id,
            'invoice' => $sale->invoice_summary,
            'member_id' => $sale->member_id,
            'member_name' => $sale->member?->full_name,
            'receipt_url' => $sale->receipt_url,
            'notes' => $sale->notes,
            'cashier_user_id' => $sale->cashier_user_id,
            // Sin esto el CRM no puede saber si la venta pidió factura, y el
            // botón «Factura» aparecería para ventas que la barrera va a
            // rechazar (el caso de la solicitud #18).
            'invoice_requested' => (bool) $sale->invoice_requested,
            'invoice_email' => $sale->invoice_email,
            'invoice_requested_at' => optional($sale->invoice_requested_at)->toIso8601String(),
        ]);
    }
}
