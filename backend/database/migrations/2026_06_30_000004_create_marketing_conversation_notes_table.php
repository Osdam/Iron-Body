<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas internas de una conversación (Inbox CRM, Fase 2A). NO se envían a
 * WhatsApp: son anotaciones privadas del equipo, con autor y fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_conversation_notes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('author_admin_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->foreign('conversation_id')
                ->references('id')->on('marketing_conversations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_conversation_notes');
    }
};
