<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acciones CRM del agente comercial (Fase 4C). Flujo human-in-the-loop:
 * la IA/sistema PROPONE; un humano aprueba/ejecuta desde el CRM; todo queda
 * auditado (quién, cuándo, qué cambió). Distinta de marketing_ai_actions
 * (que es el log de decisiones del orquestador). Nunca ejecuta nada crítico
 * (pagos/membresías/WhatsApp real) — solo acciones CRM seguras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_agent_actions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('marketing_lead_id')->nullable();
            $table->unsignedBigInteger('marketing_conversation_id')->nullable();
            $table->unsignedBigInteger('marketing_message_id')->nullable();

            $table->string('suggested_by')->default('ai'); // ai | system | admin
            $table->string('action_type');
            $table->string('status')->default('suggested'); // suggested|approved|executed|rejected|failed|cancelled
            $table->string('priority')->default('normal');   // low|normal|high|urgent

            $table->string('title');
            $table->text('reason')->nullable();
            $table->jsonb('payload')->nullable();
            $table->jsonb('result')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->boolean('requires_approval')->default(true);

            $table->unsignedBigInteger('approved_by_admin_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('executed_by_admin_id')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->unsignedBigInteger('rejected_by_admin_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->text('failed_reason')->nullable();

            $table->timestamps();

            $table->index('marketing_lead_id');
            $table->index('marketing_conversation_id');
            $table->index(['status', 'created_at']);
            $table->index(['action_type', 'status']);
            $table->index('priority');

            $table->foreign('marketing_lead_id')->references('id')->on('marketing_leads')->nullOnDelete();
            $table->foreign('marketing_conversation_id')->references('id')->on('marketing_conversations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_agent_actions');
    }
};
