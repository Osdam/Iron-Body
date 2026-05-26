<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retos de verificación en dos pasos (OTP por SMS) para el inicio de sesión de
 * miembros. Un reto nace al pedir login con documento y se consume al verificar
 * el código. El código se guarda HASHEADO (nunca en claro).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_auth_challenges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('code_hash');
            $table->string('channel')->default('sms');
            $table->string('destination')->nullable(); // teléfono usado para el envío

            // Contexto del dispositivo que solicita el acceso.
            $table->string('device_id')->nullable()->index();
            $table->string('device_name')->nullable();
            $table->string('platform')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('resend_count')->default(0);
            // pending | verified | expired | blocked
            $table->string('status')->default('pending');

            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_auth_challenges');
    }
};
