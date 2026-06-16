<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referencia facial del entrenador para el login en la tablet (kiosko). Guarda
 * el EMBEDDING (vector 192-D) calculado en el dispositivo durante el enrolamiento;
 * NO la imagen. El embedding nunca se devuelve al cliente: el match (login)
 * compara el embedding vivo contra esta referencia EN EL BACKEND. Una referencia
 * vigente por entrenador.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trainer_face_references')) {
            return;
        }

        Schema::create('trainer_face_references', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('trainer_id')->unique();
            $table->json('embedding'); // [192 floats]
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_face_references');
    }
};
