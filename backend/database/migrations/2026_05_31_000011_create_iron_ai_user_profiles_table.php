<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil resumido de IRON IA por miembro — memoria controlada.
 *
 * Guarda un resumen estable (objetivo, nivel, estilo nutricional, lesiones) y
 * un `ai_memory_summary` que da continuidad al coach SIN tener que reenviar
 * todo el historial. Vive en PostgreSQL (n8n nunca es la memoria principal).
 *
 * NO guarda datos sensibles (documento, biometría, firma, pagos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iron_ai_user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->string('primary_goal')->nullable();
            $table->string('secondary_goal')->nullable();
            $table->string('training_level')->nullable();
            $table->string('nutrition_style')->nullable();
            $table->text('preferences_summary')->nullable();
            $table->text('injuries_summary')->nullable();
            $table->text('ai_memory_summary')->nullable();
            $table->timestamp('last_context_refresh_at')->nullable();
            $table->timestamps();

            $table->unique('member_id');
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iron_ai_user_profiles');
    }
};
