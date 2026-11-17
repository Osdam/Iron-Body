<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ¿SE COBRA ESTE PLAN EN LA CAJA DE GIMNASIO? («Se cobra en recepción»)
 *
 * Cada canal de venta tenía que preguntar por su cuenta y ninguno preguntaba lo
 * suyo:
 *
 *   `active`          el sistema lo usa. Era también el interruptor del
 *                     mostrador, pero el mostrador solo rechazaba los
 *                     archivados: un plan inactivo se seguía cobrando por la API.
 *   `sellable`        el negocio quiere ofrecerlo a alguien de fuera (ULTRON,
 *                     links de pago). Solo cubre lo comercial.
 *   `visible_in_app`  el socio lo ve en su teléfono.
 *
 * Faltaba poder decir «esto NO se cobra en recepción» sin desactivarlo, y al
 * revés: el plan 24 («PLAN SEPTIEMBRE», 1 peso, 2 días) no es vendible
 * comercialmente y recepción lo cobra todas las semanas. Por eso es una bandera
 * aparte y no una derivada de `sellable`.
 *
 * NACE EN `true` para todos: es lo que ya ocurría, y una migración no cambia el
 * comportamiento por su cuenta. La única excepción es estructural, no una lista
 * de ids: un plan archivado no se vende por ninguna vía (PlanArchive), así que
 * nace en `false`.
 *
 * Reversible: `down()` quita la columna. Lo que alguien hubiera marcado a mano
 * se pierde con ella; el resto de banderas y la auditoría se quedan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->boolean('counter_sellable')->default(true)->after('visible_in_app');
        });

        // Query builder y no el modelo: sin observadores ni eventos de catálogo.
        DB::table('plans')
            ->whereNotNull('archived_at')
            ->update(['counter_sellable' => false]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('counter_sellable');
        });
    }
};
