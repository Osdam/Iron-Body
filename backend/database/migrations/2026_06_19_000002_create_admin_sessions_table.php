<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones del panel/CRM. Cada login admin emite un `token` opaco (bearer real)
 * cuyo hash SHA-256 vive aquí; el token en claro NUNCA se guarda. Permite cerrar
 * sesión (revocar) y caducar por `expires_at`. Espejo reducido de
 * `member_device_sessions` / `trainer_device_sessions` (sin device_id: el CRM es
 * web, no kiosko por dispositivo).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('admin_id')->constrained()->cascadeOnDelete();

            $table->string('token_hash')->unique();

            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoked_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_sessions');
    }
};
