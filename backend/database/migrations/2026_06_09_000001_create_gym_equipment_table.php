<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario de equipos/máquinas físicas del gimnasio.
 *
 * Propósito principal: alimentar a IRON IA con la lista REAL de máquinas que
 * existen en la instalación, para que JAMÁS recomiende un ejercicio que requiera
 * un equipo que el gimnasio no tiene. La capa de IA consume el catálogo vía
 * `GymEquipmentController@aiCatalog` (ver routes/api.php → bloque IRON IA).
 *
 * NO se confunde con `inventory` (productos de venta/stock): aquí va el equipamiento
 * de entrenamiento (máquinas guiadas, peso libre, cardio, funcional, accesorios).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('gym_equipment', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name');                       // "Prensa de piernas 45°"
            $table->string('slug')->index();              // normalizado para matching IA
            $table->string('category')->default('strength_machine');
            // strength_machine | free_weights | cardio | functional | accessory | bodyweight

            $table->json('muscle_groups')->nullable();    // ["cuádriceps","glúteos"]
            $table->json('aliases')->nullable();          // sinónimos para el matching de la IA: ["leg press","prensa"]

            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('zone')->nullable();           // "Zona de pesas", "Sala cardio"

            $table->unsignedInteger('quantity')->default(1);
            $table->string('status')->default('operational'); // operational | maintenance | out_of_service

            $table->string('image_url', 1024)->nullable();
            $table->text('notes')->nullable();

            // Si está en false, la IA NO lo tiene en cuenta aunque exista (p.ej. dañado a largo plazo).
            $table->boolean('is_available_for_ai')->default(true);

            $table->date('acquired_at')->nullable();
            $table->date('last_maintenance_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category']);
            $table->index('is_available_for_ai');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_equipment');
    }
};
