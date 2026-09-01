<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\InventoryMovementOrigin;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSaleItem;
use App\Services\Inventory\InventoryService;
use App\Support\Access\AdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de productos (CRM). Fuente única que también alimenta la Tienda de
 * la app (los `visible_in_app`). Patrón /admin/* del CRM.
 *
 * Solo CATÁLOGO y consulta. Las existencias se mueven en
 * App\Http\Controllers\Api\Admin\InventoryController (entradas y salidas
 * administrativas trazadas) y en Caja al cobrar una venta. Este controlador
 * nunca vende.
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
            $term = '%'.$request->input('search').'%';
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
            'total' => $all->count(),
            'in_app' => $all->where('visible_in_app', true)->where('active', true)->count(),
            'low_stock' => $all->filter(fn (Product $p) => $p->stock_status === 'low')->count(),
            'out_of_stock' => $all->filter(fn (Product $p) => $p->stock_status === 'out')->count(),
            'inventory_value' => (float) $all->sum(fn (Product $p) => (float) $p->cost_price * $p->stock),
            'retail_value' => (float) $all->sum(fn (Product $p) => (float) $p->sale_price * $p->stock),
            'categories' => $all->pluck('category')->unique()->values(),
        ]);
    }

    // GET /api/admin/products/{product}
    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $product]);
    }

    /**
     * POST /api/admin/products
     *
     * El stock inicial no se escribe a pelo: se crea el producto en cero y la
     * carga entra como movimiento `initial_stock`, para que la historia del
     * producto empiece en su primera unidad y no en un saldo sin explicar.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $initialStock = (int) ($data['stock'] ?? 0);
        $data['stock'] = 0;

        $product = Product::create($data);

        if ($initialStock > 0) {
            app(InventoryService::class)->registerEntry(
                product: $product,
                quantity: $initialStock,
                origin: InventoryMovementOrigin::INITIAL_STOCK,
                reason: 'Carga inicial al crear el producto',
                user: AdminActor::from($request),
            );
        }

        return response()->json(['data' => $product->fresh()], 201);
    }

    /**
     * PUT/PATCH /api/admin/products/{product}
     *
     * Editar la ficha no mueve existencias por la puerta de atrás: si el
     * payload trae un `stock` distinto, la diferencia se aplica como ajuste
     * trazado en lugar de sobrescribir el saldo en silencio.
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validatePayload($request, $product->id);

        $requestedStock = array_key_exists('stock', $data) && $data['stock'] !== null
            ? (int) $data['stock']
            : null;
        unset($data['stock']);

        $product->update($data);

        if ($requestedStock !== null && $requestedStock !== (int) $product->stock) {
            $delta = $requestedStock - (int) $product->stock;
            $inventory = app(InventoryService::class);

            try {
                if ($delta > 0) {
                    $inventory->registerEntry(
                        product: $product,
                        quantity: $delta,
                        origin: InventoryMovementOrigin::ADJUSTMENT,
                        reason: 'Corrección de existencias al editar el producto',
                        user: AdminActor::from($request),
                    );
                } else {
                    $inventory->registerExit(
                        product: $product,
                        quantity: abs($delta),
                        origin: InventoryMovementOrigin::ADJUSTMENT,
                        reason: 'Corrección de existencias al editar el producto',
                        user: AdminActor::from($request),
                    );
                }
            } catch (InsufficientStockException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'insufficient_stock',
                    'stock' => $e->toArray(),
                ], 422);
            }
        }

        return response()->json(['data' => $product->fresh()]);
    }

    /**
     * DELETE /api/admin/products/{product}
     *
     * ARCHIVA por defecto y solo borra físicamente cuando el producto no dejó
     * rastro. Un producto vendido o con movimientos no se puede destruir: sus
     * líneas de venta, sus comprobantes y su historial de existencias dejarían
     * de poder explicarse contra nada.
     *
     * El borrado lógico ya existía (`SoftDeletes`), pero nadie lo exponía: el
     * frontend no tenía ningún control que llamara aquí, y por eso «no se podían
     * eliminar productos».
     */
    public function destroy(Product $product): JsonResponse
    {
        $usage = $this->historyOf($product);
        $hasHistory = array_sum($usage) > 0;

        if ($hasHistory) {
            // Archivar: desaparece del catálogo activo y de la tienda, pero
            // sigue existiendo para las ventas y los movimientos que lo citan.
            $product->update(['active' => false, 'visible_in_app' => false]);
            $product->delete();

            return response()->json([
                'ok' => true,
                'archived' => true,
                'usage' => $usage,
                'message' => 'El producto tiene historial y se archivó para conservar la trazabilidad.',
            ]);
        }

        // Sin historial no hay nada que preservar.
        $product->forceDelete();

        return response()->json([
            'ok' => true,
            'archived' => false,
            'message' => 'El producto no tenía historial y se eliminó permanentemente.',
        ]);
    }

    /**
     * GET /api/admin/products/{product}/usage — qué pasaría al eliminarlo.
     *
     * Lo consulta el CRM ANTES de confirmar, para poder decir si va a archivar
     * o a borrar en vez de prometer una cosa y hacer otra.
     */
    public function usage(Product $product): JsonResponse
    {
        $usage = $this->historyOf($product);

        return response()->json([
            'usage' => $usage,
            'can_hard_delete' => array_sum($usage) === 0,
        ]);
    }

    /**
     * Rastro histórico del producto.
     *
     * @return array{sale_items: int, movements: int}
     */
    private function historyOf(Product $product): array
    {
        return [
            'sale_items' => ProductSaleItem::where('product_id', $product->id)->count(),
            'movements' => InventoryMovement::where('product_id', $product->id)->count(),
        ];
    }

    /**
     * POST /api/admin/products/{product}/stock   { delta: +/-, reason? }
     *
     * Ajuste manual heredado. Se conserva por compatibilidad con clientes
     * antiguos, pero ya NO escribe el stock a mano: delega en InventoryService,
     * así que deja movimiento con origen `adjustment` y autor. Antes hacía
     * `max(0, stock + delta)`, de modo que una salida mayor que las existencias
     * se recortaba en silencio y el descuadre nunca se veía.
     *
     * Para registrar entradas y salidas con su naturaleza real usa
     * `/products/{product}/entry` y `/products/{product}/exit`.
     */
    public function adjustStock(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $delta = (int) $data['delta'];
        $inventory = app(InventoryService::class);

        try {
            if ($delta > 0) {
                $inventory->registerEntry(
                    product: $product,
                    quantity: $delta,
                    origin: InventoryMovementOrigin::ADJUSTMENT,
                    reason: $data['reason'] ?? 'Ajuste manual de inventario',
                    user: AdminActor::from($request),
                );
            } else {
                $inventory->registerExit(
                    product: $product,
                    quantity: abs($delta),
                    origin: InventoryMovementOrigin::ADJUSTMENT,
                    reason: $data['reason'] ?? 'Ajuste manual de inventario',
                    user: AdminActor::from($request),
                );
            }
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'insufficient_stock',
                'stock' => $e->toArray(),
            ], 422);
        }

        return response()->json(['data' => $product->fresh()]);
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'sku' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:1024'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'visible_in_app' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);
    }
}
