<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué pasó con CADA ejercicio de una sesión, no solo con sus series.
 *
 * Hasta ahora `workout_session_sets` era todo el registro, y era plano: series
 * sueltas colgando de la sesión. Un ejercicio que el socio no llegó a hacer no
 * tiene series, así que sencillamente no dejaba rastro —era indistinguible de
 * uno que no estaba en la rutina—, y uno cuyas series venían prescritas pero
 * sin marcar contaba como ejercicio entrenado en `total_exercises`. Esta tabla
 * da al ejercicio una fila propia donde vive su estado.
 *
 * IDENTIDAD = `exercise_order`, no `exercise_id`. Una rutina puede repetir el
 * mismo ejercicio (superserie, o press de banca en dos bloques del día), así
 * que el id del catálogo no identifica una posición dentro de la sesión. Es la
 * misma identidad que ya usaba `workout_session_sets`, y por eso ambas tablas
 * se cruzan por `exercise_order` sin necesidad de una FK entre ellas: mantener
 * las series colgando directamente de la sesión deja intacto el historial y los
 * récords personales, que es exactamente lo que no se podía tocar.
 *
 * `exercise_name` es snapshot, igual que en las series: si el ejercicio se
 * retira del catálogo, el historial del socio sigue siendo legible.
 *
 * `skip_reason` solo acompaña a `skipped_temporarily`. Hoy ninguna fila
 * persistida puede llevarlo, porque una sesión con un ejercicio saltado no
 * puede cerrarse; se declara ahora para no tener que migrar la tabla el día que
 * una sesión a medias sea guardable, y porque validar un motivo en la frontera
 * para después tirarlo en silencio sería peor que no validarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_session_exercises', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('workout_session_id')->constrained('workout_sessions')->cascadeOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained('exercises')->nullOnDelete();

            $table->string('exercise_name');
            $table->string('exercise_key', 160)->index();

            $table->unsignedSmallInteger('exercise_order')->default(0);

            $table->string('status', 32);
            $table->string('skip_reason', 32)->nullable();

            $table->timestamps();

            // Un ejercicio por posición: un reenvío no duplica, igual que las
            // series. Es también lo que impide que dos entradas del payload se
            // peleen por el mismo hueco.
            $table->unique(['workout_session_id', 'exercise_order'], 'workout_session_exercise_unique');
            $table->index(['workout_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_session_exercises');
    }
};
