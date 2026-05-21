<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iron_ai_messages', function (Blueprint $table) {
            $table->id();
            // Identificación flexible: el auth aún no está unificado, así que
            // un mensaje puede colgar de un User, de un Member o de ninguno
            // (conversación anónima vía conversation_id).
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->string('conversation_id')->nullable();
            $table->string('role'); // user | assistant | system
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('member_id');
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iron_ai_messages');
    }
};
