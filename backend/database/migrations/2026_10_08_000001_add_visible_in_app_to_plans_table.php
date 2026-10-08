<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ¿SE LE ENSEÑA ESTE PLAN AL SOCIO EN LA APP?
 *
 * Hasta ahora había dos interruptores y ninguno servía para esto:
 *
 *   `active`    el sistema lo usa. Apagarlo lo saca de TODAS partes, incluido
 *               el mostrador: deja de poder cobrarse.
 *   `sellable`  el negocio quiere venderlo. Gobierna cotizaciones y campañas.
 *
 * Lo que el gimnasio necesita es otra cosa: un plan que se sigue cobrando en
 * caja todos los días pero que no quiere anunciar en la app —un precio
 * heredado, un convenio, un plan que se está retirando—. Con los dos de arriba
 * la única forma era desactivarlo, y entonces recepción tampoco podía venderlo.
 *
 * Nace en `true` a propósito: todo lo que hoy se ve en la app tiene que seguir
 * viéndose después de esta migración. Ocultar algo es siempre una decisión
 * explícita de alguien.
 *
 * La app NO se toca: lee `GET /api/membership-plans`, y es ese endpoint el que
 * deja de incluir los ocultos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'visible_in_app')) {
                $table->boolean('visible_in_app')->default(true)->after('sellable');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            if (Schema::hasColumn('plans', 'visible_in_app')) {
                $table->dropColumn('visible_in_app');
            }
        });
    }
};
