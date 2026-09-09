<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desglose de un cobro repartido entre varios medios de pago.
 *
 * Hasta ahora un pago tenía UN `method`, así que «20.000 en Nequi, 20.000 con
 * tarjeta y 20.000 en efectivo» no se podía registrar: había que elegir uno y
 * mentir en los otros dos. Eso rompía dos cosas a la vez —el desglose por medio
 * de los reportes y, peor, el efectivo esperado del arqueo de caja— porque los
 * 60.000 se atribuían enteros a un solo medio.
 *
 * El importe total sigue viviendo en `payments.amount`: esta tabla NO lo
 * sustituye, lo explica. La suma de las líneas tiene que cuadrar exactamente
 * con él, y el backend lo verifica antes de guardar.
 *
 * Un pago sin filas aquí es un pago de un solo medio, que es la inmensa mayoría
 * y sigue funcionando igual que siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            // Mismo vocabulario que `payments.method`: lo normaliza
            // PaymentMethodKind, que ya sabe leer las grafías históricas.
            $table->string('method', 80);
            $table->decimal('amount', 10, 2);

            // Comprobante de esa parte concreta (número de aprobación del
            // datáfono, referencia de la transferencia…). Cada medio trae el
            // suyo, y guardarlos todos en el `reference` del pago los mezclaba.
            $table->string('reference', 120)->nullable();

            $table->timestamps();

            // Se consulta siempre por pago (al mostrar el detalle) y por medio
            // (al agregar en reportes y en el arqueo).
            $table->index('payment_id');
            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_splits');
    }
};
