<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de seguridad de la cuenta del miembro: OTP enviado/verificado,
 * intentos fallidos, sesiones revocadas por concurrencia, accesos desde nuevos
 * dispositivos y patrones sospechosos. Sirve de auditoría y para decidir si se
 * exige verificación adicional o se bloquea el acceso.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('type')->index(); // login_otp_sent, login_verified, ...
            $table->string('description')->nullable();

            $table->string('device_id')->nullable();
            $table->string('device_name')->nullable();
            $table->string('platform')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_security_events');
    }
};
