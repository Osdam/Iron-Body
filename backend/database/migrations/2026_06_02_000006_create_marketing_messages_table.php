<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes de una conversación comercial. `meta_message_id` único garantiza
 * idempotencia (un webhook reentregado no duplica el mensaje).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->string('direction');                 // inbound | outbound
            $table->string('sender_type');               // lead | ai | human | system
            $table->text('body')->nullable();
            $table->string('meta_message_id')->nullable()->unique();
            $table->string('status')->nullable();        // sent|delivered|read|failed (WhatsApp)
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->foreign('conversation_id')->references('id')->on('marketing_conversations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_messages');
    }
};
