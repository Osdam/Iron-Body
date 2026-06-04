<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueos de seguridad de una cuenta (Fase 10). Un bloqueo activo suspende el
 * acceso (login + sesiones) hasta `locked_until` o hasta que el CRM lo resuelva.
 * La suspensión AUTOMÁTICA está apagada por defecto (config security.autosuspend);
 * por ahora el sistema solo avisa. Los bloqueos manuales del CRM sí aplican.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_risk_locks')) {
            return;
        }

        Schema::create('member_risk_locks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->string('reason', 255);
            $table->string('status', 20)->default('active'); // active|resolved|expired
            $table->timestamp('locked_until')->nullable();
            $table->string('created_by', 20)->default('system'); // system|admin
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('resolution_note', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_risk_locks');
    }
};
