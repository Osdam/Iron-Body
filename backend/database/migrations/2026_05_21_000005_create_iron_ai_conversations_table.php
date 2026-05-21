<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iron_ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Propietario flexible (igual que el resto de IRON IA): user, member
            // y/o documento. El aislamiento se valida contra estos campos.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->string('document')->nullable();

            $table->string('title')->default('Consulta con IRON IA');
            $table->string('topic')->nullable();
            $table->text('summary')->nullable();
            $table->string('last_message_preview', 500)->nullable();
            $table->unsignedInteger('messages_count')->default(0);
            // active | archived | deleted
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('member_id');
            $table->index('document');
            $table->index('status');
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iron_ai_conversations');
    }
};
