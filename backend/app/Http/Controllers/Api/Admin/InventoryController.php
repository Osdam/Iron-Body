<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\InventoryMovementOrigin;
use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\Inventory\InventoryService;
use App\Support\Access\AdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Movimientos de inventario (CRM) — EXISTENCIAS, NO VENTAS.
 *
 * Este módulo administra existencias y su historia. No cobra, no arma carritos
 * y no registra ventas: eso es Caja (App\Http\Controllers\Api\Admin\CajaController).
 *
 * Las salidas que se registran aquí son ADMINISTRATIVAS —daño, pérdida,
 * vencimiento, consumo interno, corrección de conteo— y exigen motivo. La salida
 * por venta de cafetería no se registra a mano: la escribe el cobro, con el
 * comprobante de la venta como referencia.
 */
class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * GET /api/admin/inventory/movements
     * Filtros: product_id, type (in|out), origin, from, to, per_page.
     */
    public function movements(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', Rule::in(InventoryMovementType::values())],
            'origin' => ['nullable', Rule::in(InventoryMovementOrigin::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = InventoryMovement::query()
            ->with(['product:id,name,sku', 'reference'])
            ->latest('id');

        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (isset($filters['origin'])) {
            $query->where('origin', $filters['origin']);
        }
        if (isset($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $page = $query->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (InventoryMovement $m) => $m->toCrmArray())->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** GET /api/admin/products/{product}/movements — historia de un producto. */
    public function productMovements(Request $request, Product $product): JsonResponse
    {
        $limit = (int) $request->integer('limit', 50);

        return response()->json([
            'data' => $product->inventoryMovements()
                ->with(['reference'])
                ->limit(max(1, min($limit, 200)))
                ->get()
                ->map(fn (InventoryMovement $m) => $m->toCrmArray())
                ->all(),
        ]);
    }

    /**
     * POST /api/admin/products/{product}/entry — entrada de mercancía.
     * body: { quantity, origin?, reason?, unit_amount?, notes? }
     */
    public function entry(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'origin' => ['nullable', Rule::in(InventoryMovementOrigin::manualEntryValues())],
            'reason' => ['nullable', 'string', 'max:255'],
            'unit_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $movement = $this->inventory->registerEntry(
            product: $product,
            quantity: (int) $data['quantity'],
            origin: InventoryMovementOrigin::from($data['origin'] ?? InventoryMovementOrigin::PURCHASE->value),
            reason: $data['reason'] ?? null,
            user: AdminActor::from($request),
            unitAmount: isset($data['unit_amount']) ? (float) $data['unit_amount'] : null,
            notes: $data['notes'] ?? null,
        );

        return response()->json([
            'data' => $product->fresh(),
            'movement' => $movement->fresh('product')->toCrmArray(),
        ], 201);
    }

    /**
     * POST /api/admin/products/{product}/exit — salida ADMINISTRATIVA.
     * body: { quantity, origin, reason, notes? }
     *
     * `reason` es obligatorio: una salida sin motivo es una merma sin explicar.
     * `origin` no admite `sale_cafeteria` — una venta no se da de baja a mano.
     */
    public function exit(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'origin' => ['required', Rule::in(InventoryMovementOrigin::manualExitValues())],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $movement = $this->inventory->registerExit(
                product: $product,
                quantity: (int) $data['quantity'],
                origin: InventoryMovementOrigin::from($data['origin']),
                reason: $data['reason'],
                user: AdminActor::from($request),
                notes: $data['notes'] ?? null,
            );
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'insufficient_stock',
                'stock' => $e->toArray(),
            ], 422);
        }

        return response()->json([
            'data' => $product->fresh(),
            'movement' => $movement->fresh('product')->toCrmArray(),
        ], 201);
    }

    /**
     * GET /api/admin/inventory/movement-options — vocabulario para el CRM.
     *
     * El front no debe inventar ni duplicar los orígenes válidos: los pide.
     */
    public function movementOptions(): JsonResponse
    {
        $map = static fn (array $values) => array_map(
            static fn (string $v) => [
                'value' => $v,
                'label' => InventoryMovementOrigin::from($v)->label(),
            ],
            $values,
        );

        return response()->json([
            'entry_origins' => $map(InventoryMovementOrigin::manualEntryValues()),
            'exit_origins' => $map(InventoryMovementOrigin::manualExitValues()),
        ]);
    }
}
