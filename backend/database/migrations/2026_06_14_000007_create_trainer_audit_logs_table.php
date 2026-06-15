<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora append-only de eventos del dominio profesional (acciones del CRM
 * sobre entrenadores, accesos, cambios de espacio, valoraciones, asistencia…).
 * Solo `created_at` (no se edita ni se borra evidencia). Mismo espíritu que
 * `contract_audit_logs`. NUNCA guarda OTP, tokens, documentos completos ni
 * teléfonos completos: solo referencias e IDs internos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainer_audit_logs', function (Blueprint $table): void {
            $table->id();

            // Quién ejecuta: admin (CRM), trainer (portal) o system.
            $table->string('actor_type', 20)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();

            // Sujeto del evento.
            $table->unsignedBigInteger('trainer_id')->nullable()->index();
            $table->unsignedBigInteger('identity_id')->nullable()->index();

            $table->string('event', 80)->index();
            $table->json('metadata')->nullable();

            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_audit_logs');
    }
};
