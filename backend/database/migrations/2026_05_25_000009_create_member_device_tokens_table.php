<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens FCM (push nativo) por miembro y dispositivo. Una fila por token; al
 * renovarse el token del dispositivo se actualiza (upsert por token). Permite
 * enviar push a TODOS los equipos del miembro y dar de baja al cerrar sesión.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('device_id')->nullable()->index();
            $table->string('platform')->nullable(); // android | ios
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_device_tokens');
    }
};
