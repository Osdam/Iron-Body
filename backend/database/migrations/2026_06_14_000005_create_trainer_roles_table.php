<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles profesionales asignados a un entrenador (planta / funcional). Pivot
 * aditivo: un entrenador puede tener uno o ambos roles. Sin FK de motor
 * (compatibilidad SQLite en tests); índices para resolver permisos rápido y un
 * único par (trainer_id, role) para evitar duplicados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainer_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('trainer_id')->index();
            $table->string('role', 40);
            $table->timestamps();

            $table->unique(['trainer_id', 'role']);
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_roles');
    }
};
