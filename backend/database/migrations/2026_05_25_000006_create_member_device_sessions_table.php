<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones por dispositivo de un miembro. Cada login verificado (o desbloqueo
 * biométrico) emite/rota un `session_token` opaco cuyo hash vive aquí. El token
 * en claro NUNCA se guarda. Permite:
 *   - Acceso biométrico sin repetir SMS (la sesión del dispositivo sigue viva).
 *   - Detección de "misma cuenta en varios dispositivos" y revocación remota.
 * Hay una fila por (member_id, device_id): re-login en el mismo equipo la rota.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_device_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('device_id')->index();
            $table->string('device_name')->nullable();
            $table->string('platform')->nullable();
            $table->string('app_version')->nullable();

            $table->string('token_hash')->unique();

            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('trusted_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoked_reason')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_device_sessions');
    }
};
