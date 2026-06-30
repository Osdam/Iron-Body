<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags de una conversación (Inbox CRM, Fase 2A). Slug simple normalizado,
 * único por conversación. Permite filtrar la bandeja por etiqueta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_conversation_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->string('tag', 40);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'tag']);
            $table->index('tag');
            $table->foreign('conversation_id')
                ->references('id')->on('marketing_conversations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_conversation_tags');
    }
};
