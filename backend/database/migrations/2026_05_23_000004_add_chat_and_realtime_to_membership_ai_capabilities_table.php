<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IRON IA — toggle explícito de chat de texto (`ai_chat_enabled`) y de
 * conversación de voz en vivo (`ai_realtime_voice_enabled`), configurables por
 * plan desde el CRM. `ai_chat_enabled` default true (no rompe lo existente);
 * realtime default false (se habilita por plan superior).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_ai_capabilities', function (Blueprint $table) {
            $table->boolean('ai_chat_enabled')->default(true)->after('ai_enabled');
            $table->boolean('ai_realtime_voice_enabled')->default(false)->after('ai_voice_chat_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('membership_ai_capabilities', function (Blueprint $table) {
            $table->dropColumn(['ai_chat_enabled', 'ai_realtime_voice_enabled']);
        });
    }
};
