<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de una venta/pedido (`product_sales`). Cada fila guarda un snapshot
 * (nombre y precio) para que el comprobante no cambie si el producto se edita
 * o se elimina después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_sale_id')->constrained('product_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('name');           // snapshot del nombre
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->decimal('subtotal', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sale_items');
    }
};
