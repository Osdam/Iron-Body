<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Récords personales por ejercicio y métrica.
 *
 * Hasta ahora `ProgressSummaryService::personalRecords()` devolvía siempre lista
 * vacía con un comentario explícito: "no existe una fuente de cargas por
 * ejercicio en PostgreSQL". Esta tabla es esa fuente.
 *
 * Una fila por (miembro, ejercicio, métrica): el récord VIGENTE. Se conserva
 * `previous_value` para poder mostrar la mejora sin consultar todo el historial,
 * y `workout_session_id` / `workout_session_set_id` para que cada récord sea
 * trazable hasta la serie exacta que lo estableció.
 *
 * `source` distingue el origen: 'workout' (derivado de una sesión del socio) o
 * 'trainer' (registrado manualmente por el entrenador). La columna existe desde
 * el principio para que integrar el registro manual del entrenador más adelante
 * no exija otra migración ni pise los récords derivados del entrenamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_records', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained('exercises')->nullOnDelete();

            $table->string('exercise_name');
            $table->string('exercise_key', 160);

            // 'max_weight' | 'estimated_1rm'
            $table->string('metric', 32);
            $table->decimal('value', 10, 2);
            $table->string('unit', 12)->default('kg');

            // Contexto de la serie que lo consiguió (para "5 reps × 80 kg").
            $table->unsignedSmallInteger('reps')->nullable();
            $table->decimal('weight_kg', 7, 2)->nullable();

            $table->decimal('previous_value', 10, 2)->nullable();
            $table->timestamp('achieved_at');

            $table->string('source', 20)->default('workout');
            $table->foreignId('workout_session_id')->nullable()
                ->constrained('workout_sessions')->nullOnDelete();
            $table->foreignId('workout_session_set_id')->nullable()
                ->constrained('workout_session_sets')->nullOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('trainers')->nullOnDelete();

            $table->timestamps();

            // El récord vigente es único por miembro/ejercicio/métrica: repetir
            // el mismo entrenamiento no puede crear un récord duplicado.
            $table->unique(['member_id', 'exercise_key', 'metric'], 'personal_record_unique');
            $table->index(['member_id', 'achieved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_records');
    }
};
