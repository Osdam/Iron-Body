<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesión de entrenamiento REALMENTE ejecutada por el miembro.
 *
 * Hasta ahora `routine_completions` solo registraba "esta rutina se completó":
 * sin duración, sin cargas y sin series. Lo que el socio escribía durante el
 * entrenamiento vivía en memoria de Flutter y desaparecía al cerrar la app.
 *
 * ADITIVA: no toca `routine_completions`, que sigue siendo la fuente de los
 * consumidores existentes (Progreso, contexto de IRON IA, comandos proactivos,
 * notificaciones). Cada sesión apunta a su completion para que ambas vistas
 * queden trazables.
 *
 * Idempotencia: `client_session_id` lo genera la app UNA vez al empezar el
 * entrenamiento y lo reenvía en cada reintento. El índice único por miembro
 * hace imposible que un doble toque o un retry HTTP creen dos sesiones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_sessions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // La rutina puede borrarse sin que se pierda el historial entrenado.
            $table->foreignId('routine_id')->nullable()->constrained('routines')->nullOnDelete();
            $table->foreignId('routine_completion_id')->nullable()
                ->constrained('routine_completions')->nullOnDelete();

            // Clave de idempotencia generada por el cliente.
            $table->string('client_session_id', 64);

            $table->string('routine_name')->nullable(); // snapshot legible
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at');

            // Derivados y persistidos: la app no vuelve a calcularlos.
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->decimal('total_volume_kg', 10, 2)->default(0);
            $table->unsignedSmallInteger('total_sets')->default(0);
            $table->unsignedSmallInteger('total_exercises')->default(0);

            $table->string('source', 20)->default('app');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['member_id', 'client_session_id']);
            $table->index(['member_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_sessions');
    }
};
