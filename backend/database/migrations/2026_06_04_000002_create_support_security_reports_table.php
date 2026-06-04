<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reportes de seguridad/acceso (Fase 9): robo/pérdida del teléfono, pérdida de
 * acceso al número, actividad sospechosa, cambio de teléfono u "otro". Se crean
 * desde el login (sin sesión) o desde la app autenticada; el CRM los revisa.
 * No referencia FK dura a members para poder registrar reportes de personas que
 * no resolvemos (no se revela si el documento existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_security_reports')) {
            return;
        }

        Schema::create('support_security_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id')->nullable()->index();
            $table->string('document_number', 40)->nullable()->index();
            $table->string('name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('report_type', 40);   // stolen_device|lost_access|phone_changed|suspicious_activity|other
            $table->string('status', 20)->default('pending'); // pending|reviewing|resolved|rejected
            $table->text('description')->nullable();
            $table->string('contact_channel', 40)->nullable();
            $table->string('resolution_note', 1000)->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_security_reports');
    }
};
