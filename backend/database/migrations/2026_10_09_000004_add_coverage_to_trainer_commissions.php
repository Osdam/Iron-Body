<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EL PISO PASA A CUBRIR UN NÚMERO DE DÍAS, Y ESOS DÍAS ABREN LA PUERTA.
 *
 * DOS CAMBIOS QUE SON UNO SOLO.
 *
 * 1. CUÁNTOS DÍAS. El cobro era «el piso de octubre» y el mes del calendario
 *    hacía de duración. Hoy se cobra mensual, pero eso es una costumbre, no
 *    una regla: en cuanto alguien pacte quince días o veinte, un mes del
 *    calendario deja de servir para contar nada. Así que la duración se pacta
 *    en el acuerdo y cada cobro guarda el tramo EXACTO que cubrió.
 *
 * 2. PAGAR ABRE LA PUERTA. Hasta ahora el piso era solo dinero: se cobraba y
 *    no daba acceso a nadie. Ahora el entrenador entra mientras su piso esté
 *    pagado, y deja de entrar cuando se le acaba. Es lo que convierte el cobro
 *    en algo que se puede reclamar solo: quien no paga, no trabaja dentro.
 *
 * EL ACCESO NO SE GUARDA AQUÍ. Se escribe como un acceso de empleado —el
 * mecanismo que ya existe y que la puerta y el terminal saben aplicar sin
 * internet— con su fecha de fin. Inventar una segunda forma de abrir la misma
 * puerta es como se acaba con dos sistemas que dicen cosas distintas sobre si
 * alguien puede entrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainer_commission_agreements', function (Blueprint $table): void {
            // La duración habitual del trato. 30 es lo que se usa hoy.
            $table->unsignedSmallInteger('cycle_days')->default(30)->after('amount');
        });

        Schema::table('trainer_commission_charges', function (Blueprint $table): void {
            // El tramo EXACTO que cubrió este cobro, congelado como el importe.
            // Son dos fechas y no «un mes» porque es lo único que sigue
            // significando lo mismo si mañana se pactan quince días.
            $table->date('covers_from')->nullable()->after('period');
            $table->date('covers_to')->nullable()->after('covers_from');
            $table->unsignedSmallInteger('cycle_days')->nullable()->after('covers_to');
            $table->index(['covers_to', 'covers_from']);
        });

        Schema::table('employee_accesses', function (Blueprint $table): void {
            /*
             * QUIÉN ESCRIBIÓ ESTE ACCESO.
             *
             * 'manual'     → alguien se lo concedió desde la ficha. Se respeta
             *                siempre: el piso no puede quitárselo.
             * 'commission' → lo abrió un piso pagado, y se mueve solo con él.
             *
             * Sin esta marca, renovar un piso pisaría el acceso permanente que
             * el gimnasio le dio a mano a un entrenador, y al vencer el piso se
             * quedaría fuera alguien a quien nadie se lo quitó.
             */
            $table->string('source', 12)->default('manual')->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('trainer_commission_agreements', function (Blueprint $table): void {
            $table->dropColumn('cycle_days');
        });

        Schema::table('trainer_commission_charges', function (Blueprint $table): void {
            $table->dropIndex(['covers_to', 'covers_from']);
            $table->dropColumn(['covers_from', 'covers_to', 'cycle_days']);
        });

        Schema::table('employee_accesses', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
