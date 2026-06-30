<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda comercial (Fase 4B): citas con leads de marketing para convertir.
 * NO es agenda de clases grupales, entrenadores ni calendario físico del gym.
 * Vinculable a un lead y/o conversación del Inbox. Nunca se borra físicamente:
 * se usa status='cancelled'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_appointments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('marketing_lead_id')->nullable();
            $table->unsignedBigInteger('marketing_conversation_id')->nullable();
            $table->unsignedBigInteger('assigned_to_admin_id')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();

            $table->string('type');    // visit | call | assessment | follow_up | other
            $table->string('status')->default('scheduled'); // scheduled|completed|cancelled|no_show|rescheduled

            $table->string('title');
            $table->text('notes')->nullable();

            $table->timestamp('scheduled_at');
            $table->unsignedInteger('duration_minutes')->default(30);
            $table->string('location')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_name')->nullable();

            $table->timestamp('reminder_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('marketing_lead_id');
            $table->index('marketing_conversation_id');
            $table->index('assigned_to_admin_id');
            $table->index(['status', 'scheduled_at']);
            $table->index(['type', 'scheduled_at']);

            $table->foreign('marketing_lead_id')
                ->references('id')->on('marketing_leads')->nullOnDelete();
            $table->foreign('marketing_conversation_id')
                ->references('id')->on('marketing_conversations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_appointments');
    }
};
