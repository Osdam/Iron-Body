<?php

use App\Support\Caja\PaymentMethodKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COBRO MIXTO EN UN ABONO.
 *
 * «Te doy 50.000 en efectivo y 30.000 por Nequi, y el resto te lo pago el
 * viernes» es una frase normal en el mostrador, pero el pago parcial de un plan
 * solo admitía UN medio: al elegir «pago parcial» en Pagos, la opción «Mixto»
 * desaparecía.
 *
 * Y no se podía dejar pasar sin esto. El medio de un abono se guarda ya
 * normalizado a un medio canónico ({@see PaymentMethodKind}),
 * así que escribir ahí «mixto» lo habría convertido en «Otros»: el arqueo
 * esperaría ese dinero en otro sitio del cajón y el turno saldría descuadrado
 * por el importe entero.
 *
 * Espejo de `payment_splits` y de `product_sale_splits`. Tabla aparte y no una
 * polimórfica común por lo mismo que allí: unificarlas obligaría a migrar
 * desgloses ya escritos en producción, y ahorrarse una tabla no paga ese riesgo
 * sobre datos de dinero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_payment_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receivable_payment_id')->constrained()->cascadeOnDelete();
            $table->string('method', 80);
            $table->decimal('amount', 12, 2);
            $table->string('reference', 120)->nullable();
            $table->timestamps();

            // El arqueo pregunta «¿cuánto entró por cada medio en este turno?»
            // y llega aquí por el abono.
            $table->index(['receivable_payment_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_payment_splits');
    }
};
