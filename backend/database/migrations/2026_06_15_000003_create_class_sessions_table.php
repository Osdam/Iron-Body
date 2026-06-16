<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesión REAL de una clase (una instancia por fecha). Guarda cuándo el entrenador
 * inició y finalizó la clase de verdad (con verificación facial), para supervisar
 * el cumplimiento del horario programado (classes.start_time/end_time) vs el real.
 * Una fila por (clase, fecha). Append/update; no se borra evidencia de horarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('class_sessions')) {
            return;
        }

        Schema::create('class_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->date('session_date');

            // Inicio / fin REAL (con rostro del entrenador).
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('started_by')->nullable(); // trainer_id
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->boolean('start_face_verified')->default(false);
            $table->boolean('end_face_verified')->default(false);

            $table->timestamps();

            $table->unique(['class_id', 'session_date']);
            $table->index('session_date');
            $table->index('started_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
    }
};
