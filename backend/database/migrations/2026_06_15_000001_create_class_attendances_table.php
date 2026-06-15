<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asistencia POR CLASE y por fecha de sesión. Distinta de `attendances` (el
 * torniquete de entrada/salida del gimnasio): aquí el entrenador funcional marca
 * quién asistió a una sesión concreta de su clase.
 *
 * Una clase recurrente tiene asistencia por `session_date`. El índice único
 * (class_id, member_id, session_date) impide doble asistencia: el primer marcado
 * crea; correcciones posteriores ACTUALIZAN con auditoría (corrected_at + nota),
 * nunca duplican. Sin FK de motor (compatibilidad SQLite en tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_attendances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id')->index();
            $table->unsignedBigInteger('member_id')->index();
            $table->date('session_date')->index();

            // present | absent | late
            $table->string('status', 20)->default('present');

            $table->unsignedBigInteger('marked_by_trainer_id')->nullable();
            $table->timestamp('marked_at')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->string('correction_note', 255)->nullable();

            $table->timestamps();

            $table->unique(['class_id', 'member_id', 'session_date']);
            $table->index(['class_id', 'session_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_attendances');
    }
};
