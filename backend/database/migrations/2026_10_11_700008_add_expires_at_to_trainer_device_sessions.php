<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caducidad de las sesiones del portal profesional abiertas en un KIOSKO.
 *
 * Una sesión del portal no caducaba nunca: vivía hasta que alguien la
 * revocaba. En el teléfono del entrenador es lo esperado; en una tablet
 * compartida, no. Las sesiones emitidas por `kiosk/verify` (rostro comparado
 * en el servidor) llevan `expires_at` y se cierran también por inactividad.
 *
 * NULL = sin caducidad (todas las sesiones existentes y las del teléfono):
 * aditiva, no cambia el comportamiento de nadie que ya tenga sesión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainer_device_sessions', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trainer_device_sessions', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });
    }
};
