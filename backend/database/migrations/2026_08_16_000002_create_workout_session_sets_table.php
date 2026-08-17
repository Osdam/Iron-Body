<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada serie ejecutada dentro de una sesión: reps, carga y RPE reales.
 *
 * Se guarda en columnas y no como JSON opaco porque el dominio necesita
 * consultarlo: volumen semanal, récords personales por ejercicio y evolución de
 * carga. Un JSON obligaría a recorrer todas las sesiones en PHP.
 *
 * `exercise_name` es un snapshot deliberado: si el ejercicio se retira del
 * catálogo, el historial del socio sigue siendo legible aunque `exercise_id`
 * quede en null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_session_sets', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('workout_session_id')->constrained('workout_sessions')->cascadeOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained('exercises')->nullOnDelete();

            $table->string('exercise_name');
            // Clave normalizada (sin tildes, minúsculas) para agrupar récords
            // aunque el catálogo cambie de id o el nombre varíe en mayúsculas.
            $table->string('exercise_key', 160)->index();

            $table->unsignedSmallInteger('exercise_order')->default(0);
            $table->unsignedSmallInteger('set_number');

            $table->unsignedSmallInteger('reps')->nullable();
            $table->decimal('weight_kg', 7, 2)->nullable();
            $table->unsignedTinyInteger('rpe')->nullable();

            // Solo las series marcadas cuentan para volumen y récords.
            $table->boolean('completed')->default(false);
            $table->timestamp('performed_at')->nullable();

            $table->timestamps();

            // Una serie por (sesión, ejercicio, número): un reenvío no duplica.
            $table->unique(['workout_session_id', 'exercise_order', 'set_number'], 'workout_set_unique');
            $table->index(['exercise_key', 'completed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_session_sets');
    }
};
