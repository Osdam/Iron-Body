<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorizaciones de corta vida del kiosko.
 *
 *  · workforce_authorization_codes: lo que un ADMINISTRADOR concede desde el CRM
 *    a una persona concreta: enrolar su rostro (`enrollment`) o, si la cámara
 *    falla, registrar UNA operación sin rostro (`fallback_workday`,
 *    `fallback_class`). Un solo uso, caduca, intentos limitados, y queda quién
 *    lo dio y por qué. El documento NUNCA basta para enrolar.
 *
 *  · workforce_kiosk_tokens: la identidad ya verificada en el kiosko, para UNA
 *    operación (`verification`) o para completar un enrolamiento aceptado
 *    (`enrollment`). Atada a persona + dispositivo + propósito.
 *
 * Los secretos se guardan como HMAC (APP_KEY), nunca en claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_authorization_codes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('identity_id');
            $table->string('purpose', 30); // enrollment | fallback_workday | fallback_class
            $table->string('code_hash', 64);
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('consumed_device_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['identity_id', 'purpose']);
            $table->index('expires_at');
            $table->foreign('identity_id')->references('id')->on('identities')->restrictOnDelete();
            $table->foreign('consumed_device_id')->references('id')->on('authorized_devices')->restrictOnDelete();
        });

        Schema::create('workforce_kiosk_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('kind', 20);    // verification | enrollment
            $table->string('purpose', 30); // workday | trainer_session | enrollment
            $table->unsignedBigInteger('identity_id');
            $table->unsignedBigInteger('authorized_device_id');
            $table->string('method', 20)->nullable(); // face | enrollment | admin_code
            $table->decimal('face_distance', 6, 4)->nullable();
            $table->unsignedBigInteger('authorization_code_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('expires_at');
            $table->foreign('identity_id')->references('id')->on('identities')->restrictOnDelete();
            $table->foreign('authorized_device_id')->references('id')->on('authorized_devices')->restrictOnDelete();
            $table->foreign('authorization_code_id')->references('id')->on('workforce_authorization_codes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_kiosk_tokens');
        Schema::dropIfExists('workforce_authorization_codes');
    }
};
