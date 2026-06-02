<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluaciones físicas de los miembros — fuente real de peso, estatura,
 * composición corporal y medidas.
 *
 * Cada POST crea una fila nueva (historial inmutable): así la evolución de peso
 * y el historial muestran datos reales en el tiempo. La "última evaluación" es
 * simplemente la fila más reciente por created_at.
 *
 * Datos corporales en columnas tipadas (decimal), nunca strings. El IMC NO se
 * persiste: se calcula on-the-fly desde peso/estatura para evitar inconsistencias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('trainer_id')->nullable();

            // Composición corporal.
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->decimal('body_fat_pct', 5, 2)->nullable();
            $table->decimal('muscle_mass_pct', 5, 2)->nullable();

            // Medidas (cm).
            $table->decimal('waist_cm', 6, 2)->nullable();
            $table->decimal('hip_cm', 6, 2)->nullable();
            $table->decimal('chest_cm', 6, 2)->nullable();
            $table->decimal('arm_cm', 6, 2)->nullable();
            $table->decimal('leg_cm', 6, 2)->nullable();

            // Notas.
            $table->text('injuries')->nullable();
            $table->text('trainer_notes')->nullable();

            $table->timestamps();

            $table->index(['member_id', 'created_at']);

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('trainer_id')->references('id')->on('trainers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_evaluations');
    }
};
