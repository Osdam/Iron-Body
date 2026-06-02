<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_contracts', function (Blueprint $table) {
            $table->id();
            $table->uuid('contract_uuid')->unique();
            // Folio legible para soporte/auditoría (ej. IB-2026-000123).
            $table->string('folio', 40)->unique()->nullable();

            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_template_id')->constrained('contract_templates');

            // Clave de plantilla (basic_registration | workout_registration | minor_release).
            $table->string('contract_type', 80);
            // draft | pending_signature | signed | void
            $table->string('status', 30)->default('draft');

            // Snapshots inmutables al momento de la firma (los cambios de perfil
            // posteriores NO alteran un contrato firmado).
            $table->json('member_snapshot')->nullable();
            $table->json('guardian_snapshot')->nullable();
            $table->json('medical_snapshot')->nullable();
            $table->json('acceptance_snapshot')->nullable();

            // La firma se guarda como ARCHIVO PNG privado (nunca base64 en DB).
            $table->string('signature_path')->nullable();

            // PDF final firmado (archivo privado) + su checksum SHA256.
            $table->string('signed_pdf_path')->nullable();
            $table->string('signed_pdf_checksum', 64)->nullable();
            $table->timestamp('signed_at')->nullable();

            // Anulación auditada (solo admin).
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();

            // Metadatos de trazabilidad (no sensibles).
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('device_id', 128)->nullable();
            $table->string('app_platform', 40)->nullable();
            $table->string('app_version', 40)->nullable();
            // Versión de la plantilla usada (snapshot, por si la plantilla sube de versión).
            $table->string('template_version', 40)->nullable();

            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index('contract_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_contracts');
    }
};
