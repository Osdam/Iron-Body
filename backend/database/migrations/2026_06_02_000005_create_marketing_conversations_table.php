<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Conversación comercial por lead/canal. Soporta toma de control humano. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_conversations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->string('channel');
            $table->string('status')->default('open'); // open | closed | snoozed
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('human_takeover')->default(false);
            $table->boolean('ai_enabled')->default(true);
            $table->timestamps();

            $table->index(['lead_id', 'status']);
            $table->foreign('lead_id')->references('id')->on('marketing_leads')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_conversations');
    }
};
