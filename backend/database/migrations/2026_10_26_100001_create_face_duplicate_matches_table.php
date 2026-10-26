<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué plantilla chocó un registro de rostro rechazado por «rostro de otra
 * persona» (`biometric.duplicate_rejected`), y a qué distancia.
 *
 * Hasta ahora la traza solo decía quién intentaba registrarse: con varias
 * plantillas activas no había forma de saber cuál bloqueaba ni si era un
 * duplicado real o un parecido en el límite del umbral (caso 2026-10-26).
 *
 * Tabla aparte y no un campo más del `metadata` de la bitácora: ese metadata
 * sale en crudo por `GET admin/trainers/{id}/audit` (permiso `trainers`), y
 * esto es de quien administra la biometría. Ningún endpoint lee esta tabla, y
 * `workforce:maintenance` la poda (`workforce.face.duplicate_match_retention_days`).
 *
 * Solo el id interno de la plantilla y la distancia: ni vectores, ni datos de
 * la persona (quién intentaba registrarse ya está en la fila de la bitácora).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_duplicate_matches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('audit_log_id');
            $table->unsignedBigInteger('face_reference_id');
            $table->decimal('distance', 6, 4);
            // Sin NULL: una fila sin fecha se libraría de la poda.
            $table->timestamp('created_at')->useCurrent();

            $table->index('audit_log_id');
            $table->index('face_reference_id');
            $table->index('created_at'); // poda
            // Un diagnóstico no impide borrar su evento ni la plantilla.
            $table->foreign('audit_log_id')->references('id')->on('trainer_audit_logs')->cascadeOnDelete();
            $table->foreign('face_reference_id')->references('id')->on('trainer_face_references')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_duplicate_matches');
    }
};
