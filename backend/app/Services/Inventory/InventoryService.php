<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementOrigin;
use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Admin;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Único punto del sistema que modifica `products.stock`.
 *
 * Cualquier variación de existencias entra por aquí y sale con su fila en
 * `inventory_movements`. No hay camino alternativo: si el saldo cambia, hay
 * traza; si hay traza, el saldo cambió. Antes convivían dos caminos sin
 * relación entre sí (la venta y un `delta` manual), y ninguno dejaba historia.
 *
 * Frontera de dominio: aquí solo entran PRODUCTOS FÍSICOS. La venta de un plan
 * o membresía se registra en `payments` y no llega nunca a este servicio.
 */
class InventoryService
{
    /**
     * Entrada de mercancía (reposición, devolución, carga inicial, corrección).
     *
     * @throws InsufficientStockException nunca; una entrada no puede faltar.
     */
    public function registerEntry(
        Product $product,
        int $quantity,
        InventoryMovementOrigin $origin,
        ?string $reason = null,
        ?Admin $user = null,
        ?float $unitAmount = null,
        ?string $notes = null,
    ): InventoryMovement {
        return DB::transaction(fn () => $this->apply(
            product: $product,
            type: InventoryMovementType::IN,
            quantity: $quantity,
            origin: $origin,
            reason: $reason,
            user: $user,
            unitAmount: $unitAmount ?? $this->floatOrNull($product->cost_price),
            notes: $notes,
        ));
    }

    /**
     * Salida ADMINISTRATIVA: daño, pérdida, vencimiento, consumo interno o
     * corrección de conteo. Nunca una venta — esa la escribe {@see self::registerSaleExit()}.
     *
     * @throws InsufficientStockException si no hay existencias suficientes.
     */
    public function registerExit(
        Product $product,
        int $quantity,
        InventoryMovementOrigin $origin,
        string $reason,
        ?Admin $user = null,
        ?string $notes = null,
    ): InventoryMovement {
        return DB::transaction(fn () => $this->apply(
            product: $product,
            type: InventoryMovementType::OUT,
            quantity: $quantity,
            origin: $origin,
            reason: $reason,
            user: $user,
            unitAmount: $this->floatOrNull($product->cost_price),
            notes: $notes,
        ));
    }

    /**
     * Descuenta el stock de TODAS las líneas de una venta de producto físico.
     *
     * Todo o nada: si una sola línea no alcanza, no se descuenta ninguna y la
     * venta no puede darse por cobrada. Debe llamarse DENTRO de la transacción
     * que confirma el pago, para que cobro y descuento caigan juntos.
     *
     * @return InventoryMovement[]
     *
     * @throws InsufficientStockException
     */
    public function registerSaleExit(ProductSale $sale, ?Admin $user = null): array
    {
        $movements = [];

        foreach ($sale->items as $item) {
            if ($item->product_id === null || $item->quantity <= 0) {
                continue;
            }

            $product = Product::find($item->product_id);
            if (! $product) {
                // Producto borrado después de la venta: la línea conserva su
                // snapshot de nombre y precio, pero ya no hay existencias que
                // mover. No es motivo para tumbar el cobro.
                continue;
            }

            $movements[] = $this->apply(
                product: $product,
                type: InventoryMovementType::OUT,
                quantity: (int) $item->quantity,
                origin: InventoryMovementOrigin::SALE_CAFETERIA,
                reason: null,
                user: $user,
                unitAmount: $this->floatOrNull($item->unit_price),
                notes: null,
                reference: $sale,
            );
        }

        return $movements;
    }

    /**
     * Comprueba que una lista de líneas cabe en el stock ANTES de cobrar.
     *
     * Suma las cantidades por producto: pedir el mismo artículo en dos líneas
     * distintas tiene que contar como una sola demanda, o la validación se
     * podría burlar partiendo el pedido.
     *
     * @param  array<int,array{product_id:int|string, quantity:int|string}>  $lines
     *
     * @throws InsufficientStockException
     */
    public function assertAvailability(array $lines): void
    {
        $required = [];
        foreach ($lines as $line) {
            $id = (int) $line['product_id'];
            $required[$id] = ($required[$id] ?? 0) + (int) $line['quantity'];
        }

        foreach ($required as $productId => $quantity) {
            $product = Product::find($productId);
            if (! $product) {
                continue;
            }
            if (! $product->hasStockFor($quantity)) {
                throw InsufficientStockException::forProduct($product, $quantity);
            }
        }
    }

    /**
     * Núcleo: bloquea la fila del producto, valida, mueve el saldo y escribe la
     * traza. El bloqueo pesimista evita que dos cobros simultáneos del mismo
     * producto lean el mismo stock y lo dejen en negativo.
     *
     * @throws InsufficientStockException
     */
    private function apply(
        Product $product,
        InventoryMovementType $type,
        int $quantity,
        InventoryMovementOrigin $origin,
        ?string $reason,
        ?Admin $user,
        ?float $unitAmount,
        ?string $notes,
        ?Model $reference = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('La cantidad de un movimiento de inventario debe ser mayor que cero.');
        }

        // Relee el producto con bloqueo: el `$product` recibido puede traer un
        // stock ya obsoleto.
        $locked = Product::whereKey($product->getKey())->lockForUpdate()->first() ?? $product;

        $before = (int) $locked->stock;
        $after = $type === InventoryMovementType::IN
            ? $before + $quantity
            : $before - $quantity;

        if ($after < 0) {
            throw InsufficientStockException::forProduct($locked, $quantity);
        }

        $locked->forceFill(['stock' => $after])->save();

        $movement = InventoryMovement::create([
            'product_id' => $locked->id,
            'type' => $type->value,
            'origin' => $origin->value,
            'quantity' => $quantity,
            'stock_before' => $before,
            'stock_after' => $after,
            'unit_amount' => $unitAmount,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            // `admin_id` y no `user_id`: el personal del CRM vive en `admins`,
            // mientras que `users` son los miembros de la aplicación.
            'admin_id' => $user?->id,
            'user_name' => $user?->name,
            'reason' => $reason,
            'notes' => $notes,
        ]);

        // Deja el modelo del llamador coherente con lo que acaba de pasar.
        $product->setAttribute('stock', $after);

        return $movement;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
