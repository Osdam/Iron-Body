<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventario de productos (CRM). Fuente única que también alimenta la Tienda de
 * la app (los `visible_in_app`). Patrón /admin/* del CRM.
 */
class ProductController extends Controller
{
    // GET /api/admin/products
    public function index(Request $request): JsonResponse
    {
        $query = Product::query();

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('status')) {
            // ok | low | out  → se filtra en memoria (depende de stock vs min_stock)
            $status = $request->input('status');
            $items = $query->orderBy('name')->get()
                ->filter(fn (Product $p) => $p->stock_status === $status)
                ->values();
            return response()->json(['data' => $items]);
        }
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('sku', 'like', $term)
                ->orWhere('supplier', 'like', $term));
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    // GET /api/admin/products/stats
    public function stats(): JsonResponse
    {
        $all = Product::all();

        return response()->json([
            'total'        => $all->count(),
            'in_app'       => $all->where('visible_in_app', true)->where('active', true)->count(),
            'low_stock'    => $all->filter(fn (Product $p) => $p->stock_status === 'low')->count(),
            'out_of_stock' => $all->filter(fn (Product $p) => $p->stock_status === 'out')->count(),
            'inventory_value' => (float) $all->sum(fn (Product $p) => (float) $p->cost_price * $p->stock),
            'retail_value'    => (float) $all->sum(fn (Product $p) => (float) $p->sale_price * $p->stock),
            'categories'   => $all->pluck('category')->unique()->values(),
        ]);
    }

    // GET /api/admin/products/{product}
    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $product]);
    }

    // POST /api/admin/products
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $product = Product::create($data);

        return response()->json(['data' => $product], 201);
    }

    // PUT/PATCH /api/admin/products/{product}
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validatePayload($request, $product->id);
        $product->update($data);

        return response()->json(['data' => $product->fresh()]);
    }

    // DELETE /api/admin/products/{product}
    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['ok' => true]);
    }

    // POST /api/admin/products/{product}/stock   { delta: +/- }  ajuste manual
    public function adjustStock(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['delta' => ['required', 'integer']]);
        $product->stock = max(0, $product->stock + $data['delta']);
        $product->save();

        return response()->json(['data' => $product]);
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'sku'            => ['nullable', 'string', 'max:255'],
            'name'           => ['required', 'string', 'max:255'],
            'category'       => ['nullable', 'string', 'max:255'],
            'description'    => ['nullable', 'string'],
            'image_url'      => ['nullable', 'string', 'max:1024'],
            'sale_price'     => ['required', 'numeric', 'min:0'],
            'cost_price'     => ['nullable', 'numeric', 'min:0'],
            'stock'          => ['nullable', 'integer', 'min:0'],
            'min_stock'      => ['nullable', 'integer', 'min:0'],
            'supplier'       => ['nullable', 'string', 'max:255'],
            'visible_in_app' => ['nullable', 'boolean'],
            'active'         => ['nullable', 'boolean'],
        ]);
    }
}
