<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COBRO MIXTO EN LA CAJA DE PRODUCTOS.
 *
 * «Te pago 10.000 en efectivo y el resto con tarjeta» pasa en el mostrador
 * todos los días, y en Pagos ya se podía registrar: un cobro de membresía
 * repartido guarda sus partes en `payment_splits` y el arqueo las atribuye a
 * cada medio. Las ventas de productos no tenían dónde.
 *
 * Lo que se hacía era elegir UN medio y que el otro se perdiera. El cajón
 * cuadraba en total pero no por medio: aparecían 50.000 en efectivo que no
 * estaban, y el arqueo del turno los daba por faltantes.
 *
 * Esta tabla es el espejo de `payment_splits`. Se hace aparte y no una tabla
 * polimórfica compartida porque convertir la que ya existe obligaría a migrar
 * los desgloses en producción, y el beneficio —una tabla menos— no paga ese
 * riesgo sobre datos de dinero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sale_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_sale_id')->constrained()->cascadeOnDelete();
            $table->string('method', 80);
            $table->decimal('amount', 12, 2);
            $table->string('reference', 120)->nullable();
            $table->timestamps();

            // El arqueo pregunta «¿cuánto entró por cada medio en este turno?»
            // y llega aquí por la venta.
            $table->index(['product_sale_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sale_splits');
    }
};
