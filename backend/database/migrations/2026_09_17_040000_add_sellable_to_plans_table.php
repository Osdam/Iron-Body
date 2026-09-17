<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separa «el sistema puede usar este plan» de «este plan se le puede vender a
 * alguien».
 *
 * Hasta ahora sólo existía `active`, y el módulo comercial lo leía como si
 * significara vendible. No lo significa: `active` lo consultan una veintena de
 * controladores —lo que ve el socio en la app, los informes, el punto de venta—
 * y hay planes que TIENEN que seguir activos sin ser vendibles. El caso real:
 * «Comisión de personalizados», con 86 pagos registrados, es el vehículo
 * contable con el que el equipo apunta las comisiones de entrenamiento
 * personalizado. Desactivarlo para que el asesor no lo cotice habría roto la
 * operación diaria del gimnasio.
 *
 * Con la columna separada, un plan puede quedarse activo para su uso interno y
 * a la vez desaparecer del catálogo comercial.
 *
 * Arranca en `true` para todos: es lo que ya ocurría, y una migración no debe
 * cambiar el comportamiento por su cuenta. La única excepción se aplica abajo y
 * es estructural, no una lista de ids: un plan que no se puede cobrar no es un
 * plan comercial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->boolean('sellable')->default(true)->after('active');
        });

        // Regla estructural, válida en cualquier entorno: si no tiene precio,
        // no es algo que se venda. Los ids concretos no se tocan aquí porque
        // no existen igual fuera de producción.
        Schema::hasColumn('plans', 'sellable') && DB::table('plans')
            ->where('price', '<=', 0)
            ->update(['sellable' => false]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('sellable');
        });
    }
};
