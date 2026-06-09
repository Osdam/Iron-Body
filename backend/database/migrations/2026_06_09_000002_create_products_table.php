<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de productos del gimnasio (inventario + tienda).
 *
 * Fuente única para DOS superficies:
 *  • CRM → módulo Inventario (CRUD, stock, compras).
 *  • App → módulo Tienda (los marcados `visible_in_app` y `active` con stock).
 *
 * Las ventas se registran en `product_sales` (caja/POS y pedidos de la app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('sku')->nullable()->index();
            $table->string('name');
            $table->string('category')->default('Otros')->index();
            $table->text('description')->nullable();
            $table->string('image_url', 1024)->nullable();

            $table->decimal('sale_price', 12, 2)->default(0);   // precio de venta
            $table->decimal('cost_price', 12, 2)->nullable();   // precio de compra
            $table->integer('stock')->default(0);
            $table->integer('min_stock')->default(0);
            $table->string('supplier')->nullable();

            $table->boolean('visible_in_app')->default(true); // aparece en la tienda
            $table->boolean('active')->default(true);          // activo en el catálogo

            $table->timestamps();
            $table->softDeletes();

            $table->index(['active', 'visible_in_app']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
