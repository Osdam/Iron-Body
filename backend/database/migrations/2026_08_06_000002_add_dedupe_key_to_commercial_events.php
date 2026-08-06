<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La barrera que hace idempotente el registro de un hecho.
 *
 * Los hechos llegan por caminos que no se coordinan entre sí: el webhook de
 * Wompi, la reconciliación periódica que corre cada cinco minutos, el CRM y la
 * app. Los cuatro pueden observar el MISMO pago aprobado y los cuatro querrán
 * anunciarlo. Sin esta clave, una sola aprobación generaría cuatro eventos y el
 * motor calcularía cuatro veces la siguiente acción sobre la misma persona.
 *
 * La clave se construye a partir del hecho en sí —«pago X aprobado»— y no del
 * momento en que se observó, que es justo lo que la hace resistente a los
 * reintentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_events', function (Blueprint $table): void {
            $table->string('dedupe_key')->nullable()->after('event');
            $table->unique('dedupe_key');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_events', function (Blueprint $table): void {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
