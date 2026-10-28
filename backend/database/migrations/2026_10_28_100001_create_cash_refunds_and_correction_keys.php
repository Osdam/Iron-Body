<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CORRECCIONES ADMINISTRATIVAS (docs/ops/CORRECCIONES-ADMINISTRATIVAS.md).
 *
 * 1 · `cash_refunds`: el dinero que SALE de la caja al devolverle a un cliente
 *     lo que pagó. Hasta ahora no existía una sola salida de dinero en el
 *     sistema: la única forma de «deshacer» un cobro era anularlo, y anular
 *     dice que el dinero nunca entró —se borra del arqueo del turno en que
 *     entró—. Una devolución es lo contrario: el dinero entró en su turno y
 *     sale HOY, del turno abierto. Mezclar las dos cosas descuadraba un cierre
 *     o el otro.
 *
 *     `cash_shift_id` es nulo SOLO para la pasarela: la devolución se hizo en el
 *     panel de Wompi, no salió ningún billete del cajón, y la fila existe para
 *     dejar constancia con su referencia externa.
 *
 * 2 · `client_request_id` en `payments` y `product_sales`, único: el CRM manda
 *     uno por intento y lo reutiliza si reintenta tras un fallo de red. El índice
 *     es lo que de verdad impide el cobro doble; el camino rápido del servidor
 *     solo evita trabajo. Mismo patrón que `receivable_payments`.
 *
 * 3 · `product_sale_items.refunded_quantity` / `refunded_amount` (y su suma en
 *     `product_sales.refunded_total`, para los informes): lo devuelto de
 *     cada línea. Nunca se devuelve más de lo vendido, y el importe se lleva
 *     acumulado para que la última unidad absorba el redondeo y la venta
 *     devuelta entera sume exactamente su total.
 *
 * Reversible: `down()` quita lo que añade. No toca ni reescribe datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_refunds', function (Blueprint $table): void {
            $table->id();
            // gym | products. La caja de la operación devuelta.
            $table->string('cash_type', 16);
            // Turno ABIERTO del que sale el dinero. Nulo solo en la pasarela.
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->restrictOnDelete();
            // El cobro (`payments`) o la venta (`product_sales`) devueltos.
            $table->string('refundable_type', 120);
            $table->unsignedBigInteger('refundable_id');
            $table->decimal('amount', 12, 2);
            // Medio por el que sale el dinero. El arqueo lo resta de ESE medio.
            $table->string('method', 80);
            $table->string('reason', 500);
            // Devolución parcial de una venta: [{item_id, quantity, amount}].
            $table->json('items')->nullable();
            $table->boolean('restocked')->default(false);
            // Referencia de la devolución en el panel de la pasarela.
            $table->string('external_reference', 120)->nullable();
            $table->string('client_request_id', 100)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            // El arqueo pregunta «¿cuánto salió de este turno, por medio?».
            $table->index(['cash_shift_id', 'method']);
            $table->index(['refundable_type', 'refundable_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('client_request_id', 100)->nullable()->unique();
        });

        Schema::table('product_sales', function (Blueprint $table): void {
            $table->string('client_request_id', 100)->nullable()->unique();
            // Lo devuelto de la venta, acumulado: los informes de base comercial
            // restan esto del total sin sumar línea a línea.
            $table->decimal('refunded_total', 12, 2)->default(0);
        });

        Schema::table('product_sale_items', function (Blueprint $table): void {
            $table->unsignedInteger('refunded_quantity')->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('product_sale_items', function (Blueprint $table): void {
            $table->dropColumn(['refunded_quantity', 'refunded_amount']);
        });

        Schema::table('product_sales', function (Blueprint $table): void {
            $table->dropUnique(['client_request_id']);
            $table->dropColumn(['client_request_id', 'refunded_total']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['client_request_id']);
            $table->dropColumn('client_request_id');
        });

        Schema::dropIfExists('cash_refunds');
    }
};
