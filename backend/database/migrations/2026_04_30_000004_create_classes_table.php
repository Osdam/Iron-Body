<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Ej: Spinning, Funcional, Yoga
            $table->string('type'); // Ej: Spinning, Funcional, Cross Training, Yoga, Pilates, Boxeo, Cardio
            $table->foreignId('trainer_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('day_of_week'); // Ej: Lunes, Martes, etc
            $table->time('start_time'); // Hora de inicio
            $table->time('end_time'); // Hora de fin
            $table->integer('duration_minutes')->default(60); // Duración en minutos
            $table->integer('max_capacity')->default(20); // Cupos máximos
            $table->integer('enrolled_count')->default(0); // Inscritos actuales
            $table->string('location')->nullable(); // Sala o ubicación
            $table->string('status')->default('active'); // active, inactive, finished
            $table->text('description')->nullable(); // Descripción de la clase
            $table->text('notes')->nullable(); // Notas internas
            $table->boolean('is_recurring')->default(true); // ¿Es recurrente semanalmente?
            $table->boolean('allow_online_booking')->default(true); // ¿Permite inscripción online?
            $table->boolean('requires_active_plan')->default(false); // ¿Requiere plan activo?
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
