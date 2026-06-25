<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'sales_today'      => (clone $today)->where('status', '!=', 'cancelled')->count(),
            'revenue_today'    => (float) (clone $today)->whereIn('status', ['paid', 'delivered'])->sum('total'),
            'pending_app'      => ProductSale::app()->where('status', 'pending')->count(),
            'to_deliver'       => ProductSale::where('status', 'paid')->count(),
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
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'integer', 'min:1'],
            'payment_method'     => ['required', Rule::in(ProductSale::PAYMENT_METHODS)],
            'customer_name'      => ['nullable', 'string', 'max:255'],
            'discount'           => ['nullable', 'numeric', 'min:0'],
            'paid'               => ['nullable', 'boolean'],
            'notes'              => ['nullable', 'string'],
        ]);

        $sale = DB::transaction(function () use ($data, $request) {
            $sale = ProductSale::create([
                'channel'         => 'pos',
                'status'          => 'pending',
                'cashier_user_id' => optional($request->user())->id,
                'customer_name'   => $data['customer_name'] ?? null,
                'payment_method'  => $data['payment_method'],
                'discount'        => $data['discount'] ?? 0,
                'notes'           => $data['notes'] ?? null,
            ]);

            $subtotal = 0;
            foreach ($data['items'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                $lineTotal = (float) $product->sale_price * $line['quantity'];
                $subtotal += $lineTotal;

                $sale->items()->create([
                    'product_id' => $product->id,
                    'name'       => $product->name,
                    'unit_price' => $product->sale_price,
                    'quantity'   => $line['quantity'],
                    'subtotal'   => $lineTotal,
                ]);
            }

            $sale->update([
                'subtotal' => $subtotal,
                'total'    => max(0, $subtotal - ($data['discount'] ?? 0)),
            ]);

            return $sale;
        });

        // En POS normalmente se cobra al instante → descuenta stock.
        if ($data['paid'] ?? true) {
            $sale->load('items');
            $sale->markPaid($data['payment_method']);
        }

        return response()->json(['data' => $this->serialize($sale->fresh(['items', 'member:id,full_name']))], 201);
    }

    // POST /api/admin/caja/sales/{sale}/pay   { payment_method?, payment_reference? }
    public function pay(Request $request, ProductSale $sale): JsonResponse
    {
        $data = $request->validate([
            'payment_method'    => ['nullable', Rule::in(ProductSale::PAYMENT_METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $sale->load('items');
        $sale->markPaid($data['payment_method'] ?? null, $data['payment_reference'] ?? null);

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

    private function serialize(ProductSale $sale): array
    {
        return array_merge($sale->toReceiptArray(), [
            'id'            => $sale->id,
            'invoice'       => $sale->invoice_summary,
            'member_id'     => $sale->member_id,
            'member_name'   => $sale->member?->full_name,
            'receipt_url'   => $sale->receipt_url,
            'notes'         => $sale->notes,
            'cashier_user_id' => $sale->cashier_user_id,
        ]);
    }
}
