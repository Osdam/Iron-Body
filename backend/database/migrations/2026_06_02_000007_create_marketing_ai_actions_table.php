<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auditoría de decisiones del asesor comercial IA (trazabilidad obligatoria). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_ai_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('action_type');              // reply|ask_objective|send_plan|schedule_visit|notify_human|reactivate|discard...
            $table->text('reason')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('status')->default('proposed'); // proposed|executed|skipped|failed
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
            $table->index('action_type');
            $table->foreign('lead_id')->references('id')->on('marketing_leads')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('marketing_conversations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_ai_actions');
    }
};
