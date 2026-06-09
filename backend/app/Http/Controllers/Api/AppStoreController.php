<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tienda de la app (miembro autenticado).
 *
 * Lee del MISMO catálogo `products` que el CRM (los `visible_in_app`), así que
 * lo que Inventario publique aparece aquí sin duplicar datos. El checkout crea
 * un pedido en `product_sales` (channel=app) que la Caja del CRM gestiona.
 *
 * Pago:
 *  • `cash`  → "reservar y pagar en caja": el pedido queda pendiente y se cobra
 *    en el mostrador (la Caja lo confirma y descuenta stock).
 *  • `online|nequi|transfer` → el miembro adjunta un comprobante (`receipt_url`);
 *    la Caja lo verifica y confirma. (La estructura admite también pasarela vía
 *    `payment_reference` cuando se integre el cobro automático.)
 */
class AppStoreController extends Controller
{
    // GET /api/app/store/products
    public function products(Request $request): JsonResponse
    {
        $query = Product::forStore();
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        $items = $query->orderBy('name')->get();

        return response()->json([
            'data'       => $items->map(fn (Product $p) => $p->toStoreArray()),
            'categories' => $items->pluck('category')->unique()->values(),
        ]);
    }

    // GET /api/app/store/orders — pedidos del miembro
    public function orders(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $orders = ProductSale::app()
            ->where('member_id', $member->id)
            ->with('items')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (ProductSale $s) => $s->toReceiptArray());

        return response()->json(['data' => $orders]);
    }

    // GET /api/app/store/orders/{uuid} — comprobante de un pedido
    public function showOrder(Request $request, string $uuid): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        $order = ProductSale::app()->where('uuid', $uuid)
            ->where('member_id', optional($member)->id)
            ->with('items')->first();

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Pedido no encontrado.'], 404);
        }

        return response()->json(['data' => $order->toReceiptArray()]);
    }

    /**
     * POST /api/app/store/orders — checkout.
     * body: { items:[{product_id, quantity}], payment_method, receipt_url?, notes? }
     */
    public function createOrder(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $data = $request->validate([
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'integer', 'min:1'],
            'payment_method'     => ['required', Rule::in(['cash', 'online', 'nequi', 'transfer'])],
            'receipt_url'        => ['nullable', 'string', 'max:1024'],
            'notes'              => ['nullable', 'string'],
        ]);

        // Validar stock disponible antes de crear el pedido.
        foreach ($data['items'] as $line) {
            $product = Product::forStore()->find($line['product_id']);
            if (! $product || $product->stock < $line['quantity']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Uno de los productos no tiene stock suficiente.',
                ], 422);
            }
        }

        $order = DB::transaction(function () use ($data, $member) {
            $order = ProductSale::create([
                'channel'        => 'app',
                'status'         => 'pending',
                'member_id'      => $member->id,
                'customer_name'  => $member->full_name,
                'payment_method' => $data['payment_method'],
                'payment_status' => 'pending',
                'receipt_url'    => $data['receipt_url'] ?? null,
                'notes'          => $data['notes'] ?? null,
            ]);

            $subtotal = 0;
            foreach ($data['items'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                $lineTotal = (float) $product->sale_price * $line['quantity'];
                $subtotal += $lineTotal;

                $order->items()->create([
                    'product_id' => $product->id,
                    'name'       => $product->name,
                    'unit_price' => $product->sale_price,
                    'quantity'   => $line['quantity'],
                    'subtotal'   => $lineTotal,
                ]);
            }

            $order->update(['subtotal' => $subtotal, 'total' => $subtotal]);

            return $order;
        });

        return response()->json(['data' => $order->fresh('items')->toReceiptArray()], 201);
    }

    /**
     * POST /api/app/store/orders/{uuid}/receipt — adjuntar comprobante de pago.
     * body: { receipt_url }
     */
    public function attachReceipt(Request $request, string $uuid): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        $data = $request->validate(['receipt_url' => ['required', 'string', 'max:1024']]);

        $order = ProductSale::app()->where('uuid', $uuid)
            ->where('member_id', optional($member)->id)->first();
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Pedido no encontrado.'], 404);
        }

        $order->update(['receipt_url' => $data['receipt_url']]);

        return response()->json(['data' => $order->fresh('items')->toReceiptArray()]);
    }
}
