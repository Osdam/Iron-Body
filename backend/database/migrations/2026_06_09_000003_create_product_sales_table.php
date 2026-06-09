<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas y pedidos de productos.
 *
 * Unifica dos orígenes en una sola tabla (campo `channel`):
 *  • `pos` → venta en mostrador registrada desde la Caja del CRM.
 *  • `app` → pedido creado por un miembro desde la Tienda de la app.
 *
 * Flujo de estados:
 *   pending → paid → delivered      (cancelled en cualquier punto previo a delivered)
 *
 * El stock se descuenta al pasar a `paid` (POS inmediato; app: online inmediato
 * o al confirmarse en caja si fue "reservar y pagar en caja").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();                 // comprobante legible: V-000123

            $table->string('channel')->default('pos');        // pos | app
            $table->string('status')->default('pending');     // pending|paid|delivered|cancelled

            $table->foreignId('member_id')->nullable()        // pedido de la app
                ->constrained('members')->nullOnDelete();
            $table->foreignId('cashier_user_id')->nullable()  // operó la caja
                ->constrained('users')->nullOnDelete();
            $table->string('customer_name')->nullable();      // snapshot/etiqueta

            $table->string('payment_method')->nullable();     // cash|card|online|nequi|transfer
            $table->string('payment_status')->default('pending'); // pending|paid
            $table->string('payment_reference')->nullable();  // ref de pasarela
            $table->string('receipt_url', 1024)->nullable();  // comprobante subido (app)

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sales');
    }
};
