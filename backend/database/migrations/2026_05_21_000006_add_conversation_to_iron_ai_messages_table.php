<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iron_ai_messages', function (Blueprint $table) {
            // FK real a la conversación. (La columna previa `conversation_id` es
            // un string legacy — se conserva = uuid de la conversación.)
            $table->unsignedBigInteger('iron_ai_conversation_id')->nullable()->after('member_id');
            $table->string('conversation_uuid')->nullable()->after('iron_ai_conversation_id');

            $table->index('iron_ai_conversation_id');
            $table->index('conversation_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('iron_ai_messages', function (Blueprint $table) {
            $table->dropIndex(['iron_ai_conversation_id']);
            $table->dropIndex(['conversation_uuid']);
            $table->dropColumn(['iron_ai_conversation_id', 'conversation_uuid']);
        });
    }
};
