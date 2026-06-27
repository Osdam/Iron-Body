<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de llamadas comerciales (futura integración Twilio Voice — Fase 6).
 * Se crea ahora como cimiento: el agente podrá programar "llamar en 2 horas"
 * (vía marketing_followups type=call) y, cuando Twilio Voice exista, asociar la
 * llamada real aquí. Hoy NO se conecta Twilio: la tabla solo persiste intención
 * y estado. Sin softDeletes (coherente con el resto de tablas marketing_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_calls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketing_lead_id')->nullable();
            $table->unsignedBigInteger('marketing_followup_id')->nullable();
            $table->string('provider')->default('twilio');
            $table->string('provider_call_sid')->nullable();
            $table->string('to_phone')->nullable();
            $table->string('from_phone')->nullable();
            $table->string('status')->default('pending');     // pending | queued | ringing | in_progress | completed | failed | no_answer | canceled
            $table->string('direction')->default('outbound'); // outbound | inbound
            $table->string('reason')->nullable();             // motivo del intento (venta abierta, recuperación...)
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->longText('transcript')->nullable();
            $table->text('summary')->nullable();
            $table->string('outcome')->nullable();            // won | lost | callback | voicemail | escalated...
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('marketing_lead_id');
            $table->index('marketing_followup_id');
            $table->index('provider_call_sid');

            $table->foreign('marketing_lead_id')->references('id')->on('marketing_leads')->nullOnDelete();
            $table->foreign('marketing_followup_id')->references('id')->on('marketing_followups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_calls');
    }
};
