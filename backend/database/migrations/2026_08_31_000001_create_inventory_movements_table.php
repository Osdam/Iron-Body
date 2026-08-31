<?php

use App\Models\ProductSale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libro de movimientos de inventario (append-only).
 *
 * Hasta ahora `products.stock` era un número sin historia: se movía desde la
 * venta ({@see ProductSale::markPaid()}) y desde un ajuste manual con
 * un `delta` suelto, y ninguna de las dos cosas dejaba rastro. El «historial de
 * movimientos» que mostraba el CRM era estado local del navegador.
 *
 * Aquí queda el rastro real. Toda variación de existencias escribe una fila, y
 * `stock_before`/`stock_after` congelan el saldo a ambos lados del movimiento,
 * de modo que el inventario se puede auditar sin depender de recalcular.
 *
 * Separación de dominios: SOLO los productos físicos tienen movimientos. La
 * venta de un plan/membresía vive en `payments` y NO escribe aquí nunca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // in | out  (ver App\Enums\InventoryMovementType)
            $table->string('type', 16);

            // Naturaleza del movimiento (ver App\Enums\InventoryMovementOrigin).
            // Distingue lo comercial (`sale_cafeteria`) de lo administrativo
            // (`damage`, `loss`, `expiration`, `internal_use`, `adjustment`…).
            $table->string('origin', 32);

            // Siempre positiva: el signo lo da `type`, no la cantidad.
            $table->unsignedInteger('quantity');

            $table->integer('stock_before');
            $table->integer('stock_after');

            // Costo/precio unitario del momento, cuando aplica (compra o venta).
            $table->decimal('unit_amount', 12, 2)->nullable();

            // Documento que originó el movimiento: ProductSale en las ventas,
            // null en los movimientos administrativos.
            $table->nullableMorphs('reference');

            // Quién lo hizo. `user_name` es snapshot: si la cuenta se borra, la
            // traza no debe quedarse sin autor.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();

            // Motivo obligatorio en las salidas administrativas (lo exige el
            // servicio, no la columna: las salidas por venta no lo necesitan).
            $table->string('reason', 255)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index(['type', 'origin']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
