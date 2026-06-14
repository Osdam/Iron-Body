<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retos OTP del acceso profesional. Espejo de `member_auth_challenges` pero para
 * entrenadores. Reusa el MISMO motor de envío (Twilio/SmsSender + config/otp.php)
 * a través de TrainerOtpService; aquí solo vive la contabilidad del reto. El
 * código se guarda HASHEADO, nunca en claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainer_auth_challenges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('trainer_id')->nullable()->index();

            // trainer_login | profile_link | workspace_switch
            $table->string('purpose', 40)->default('trainer_login');

            $table->string('code_hash');
            $table->string('channel')->default('sms');
            $table->string('destination')->nullable();

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

            $table->index(['trainer_id', 'purpose', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_auth_challenges');
    }
};
