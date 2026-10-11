<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desde qué dispositivo y con qué prueba de identidad se inició y finalizó
 * cada clase.
 *
 *  · `*_device_id` ............ dispositivo AUTORIZADO que firmó la operación
 *                               (authorized_devices.id), o NULL.
 *  · `*_declared_device` ...... lo que dijo la sesión de la app (nombre y
 *                               plataforma) cuando no hubo firma: identidad
 *                               DECLARADA, se muestra como «no verificado».
 *  · `*_identity_method` ...... face (rostro comparado en el servidor),
 *                               admin_code (respaldo autorizado) o legacy_claim
 *                               (la app publicada dice «verificado» sin comparar).
 *
 * Aditiva y nullable: las sesiones históricas quedan en NULL y el CRM las
 * muestra como «No registrado». No se inventa nada hacia atrás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('start_device_id')->nullable();
            $table->string('start_declared_device', 120)->nullable();
            $table->string('start_identity_method', 20)->nullable();
            $table->unsignedBigInteger('end_device_id')->nullable();
            $table->string('end_declared_device', 120)->nullable();
            $table->string('end_identity_method', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'start_device_id', 'start_declared_device', 'start_identity_method',
                'end_device_id', 'end_declared_device', 'end_identity_method',
            ]);
        });
    }
};
