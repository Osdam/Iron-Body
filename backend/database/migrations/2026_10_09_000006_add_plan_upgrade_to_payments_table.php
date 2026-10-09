<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEJORAR DE PLAN A MITAD DE PERIODO.
 *
 * El caso es de todas las semanas: alguien paga el Mensual y a los siete días
 * quiere el Total Access. Hasta ahora no había forma de registrarlo: o se le
 * cobraba el plan nuevo entero —y el sistema le ENCADENABA treinta días al
 * final de los que ya tenía, regalándole un mes— o se anulaba el cobro
 * anterior y se rehacía, moviendo dinero de un turno que ya estaba cerrado.
 * Las dos ensucian la caja para arreglar un cambio de plan.
 *
 * LO QUE SE COBRA es la diferencia de precio entre los dos planes, completa.
 * Y EL VENCIMIENTO NO SE MUEVE: se mejora lo que le queda del periodo que ya
 * compró, no se le vende uno nuevo. Es lo más fácil de explicar en el
 * mostrador y lo que no deja al socio con días de regalo.
 *
 * Esta columna es lo que distingue ese cobro de una renovación. Sin ella,
 * `MembershipPeriod::apply()` vería un pago con plan y haría lo de siempre:
 * sumar la duración del plan nuevo. Con ella sabe que solo tiene que cambiar
 * el plan y dejar las fechas en paz.
 *
 * Guarda DESDE QUÉ PLAN se mejoró, no un simple «sí»: es lo que permite leer
 * el apunte meses después —«pagó 70.000, mejora de Mensual a Total Access»—
 * en el informe del turno y en el historial del socio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('upgraded_from_plan_id')->nullable()->after('plan_id');
            $table->index('upgraded_from_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['upgraded_from_plan_id']);
            $table->dropColumn('upgraded_from_plan_id');
        });
    }
};
