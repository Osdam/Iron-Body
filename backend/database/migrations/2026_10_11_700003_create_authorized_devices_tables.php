<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispositivos autorizados: la tablet que registra una jornada o inicia una
 * clase tiene que poder PROBAR que es esa tablet.
 *
 * Hasta ahora el «dispositivo» era un `device_id` que la app inventa al
 * instalarse y el servidor se cree: copiando la APK a otra tablet, o con curl,
 * cualquiera dice ser cualquiera. Aquí la identidad del dispositivo es una
 * CLAVE PRIVADA no exportable (Android Keystore / StrongBox, Secure Enclave en
 * iOS) cuyo público se registra con un código que genera un administrador. Cada
 * operación sensible firma un desafío de un solo uso.
 *
 *  · authorized_devices ...... el dispositivo, su clave pública y lo que DECLARA
 *                              (modelo, versión) separado de lo VERIFICADO
 *                              (atestación de la clave).
 *  · device_pairing_codes .... el código de vinculación (un solo uso, 15 min).
 *  · device_challenges ....... nonces de un solo uso (anti-replay).
 *  · device_security_events .. intentos desde equipos desconocidos, revocados,
 *                              con firma inválida o desafío reutilizado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorized_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('location', 120)->nullable();
            $table->string('status', 20)->default('active'); // active | revoked

            // Lo DECLARADO por la app: útil para reconocer el equipo, nunca prueba de nada.
            $table->string('platform', 20)->nullable();
            $table->string('manufacturer', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('os_version', 40)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('key_storage_declared', 20)->nullable();

            // La credencial: SubjectPublicKeyInfo DER en base64 (EC P-256).
            $table->string('key_algorithm', 10)->default('ES256');
            $table->text('public_key');
            $table->string('key_fingerprint', 64)->unique(); // sha256 hex del DER

            // Lo VERIFICADO: resultado de la atestación de la clave.
            $table->string('attestation_status', 20)->default('not_provided'); // verified | unverified | not_provided
            $table->string('attestation_security_level', 20)->nullable();      // strongbox | tee | software
            $table->json('attestation_summary')->nullable();

            $table->timestamp('registered_at')->nullable();
            $table->unsignedBigInteger('registered_by_admin_id')->nullable();
            $table->string('registered_by_name', 120)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 64)->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_admin_id')->nullable();
            $table->string('revoked_by_name', 120)->nullable();
            $table->string('revoked_reason', 500)->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('device_pairing_codes', function (Blueprint $table): void {
            $table->id();
            // HMAC del código con la APP_KEY: un volcado no permite probar
            // códigos fuera de línea.
            $table->string('code_hash', 64)->unique();
            $table->string('name', 120);
            $table->string('location', 120)->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('consumed_device_id')->nullable();
            $table->timestamps();

            // Integridad referencial dentro del dominio (los dispositivos no se
            // borran: se revocan). Sin FK a los administradores: una cuenta del
            // CRM se puede borrar y su nombre ya va copiado en la fila.
            $table->foreign('consumed_device_id')->references('id')->on('authorized_devices')->restrictOnDelete();
        });

        Schema::create('device_challenges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('authorized_device_id');
            $table->string('nonce', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['authorized_device_id', 'created_at']);
            $table->index('expires_at'); // poda
            $table->foreign('authorized_device_id')->references('id')->on('authorized_devices')->restrictOnDelete();
        });

        Schema::create('device_security_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('authorized_device_id')->nullable();
            $table->string('claimed_device_id', 64)->nullable();
            $table->string('event', 40);
            $table->string('operation', 120)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index('authorized_device_id');
            // NULL cuando el id declarado no corresponde a ningún dispositivo.
            $table->foreign('authorized_device_id')->references('id')->on('authorized_devices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_security_events');
        Schema::dropIfExists('device_challenges');
        Schema::dropIfExists('device_pairing_codes');
        Schema::dropIfExists('authorized_devices');
    }
};
