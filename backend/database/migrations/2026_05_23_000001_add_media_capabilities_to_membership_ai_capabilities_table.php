<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IRON IA multimodal — añade capacidades de voz/imagen/archivos a las
 * capacidades por membresía. Defaults conservadores (todo bloqueado, límites 0)
 * para que las filas existentes no habiliten funciones premium sin querer.
 * El comando iron-ai:sync-membership-capabilities --force las puebla desde
 * config/iron_ai.php según el tier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_ai_capabilities', function (Blueprint $table) {
            $table->boolean('ai_voice_chat_enabled')->default(false)->after('proactive_notifications_enabled');
            $table->boolean('ai_image_analysis_enabled')->default(false)->after('ai_voice_chat_enabled');
            $table->boolean('ai_file_upload_enabled')->default(false)->after('ai_image_analysis_enabled');
            $table->integer('ai_audio_monthly_limit')->nullable()->default(0)->after('ai_file_upload_enabled');
            $table->integer('ai_image_monthly_limit')->nullable()->default(0)->after('ai_audio_monthly_limit');
            $table->integer('ai_max_audio_seconds')->default(60)->after('ai_image_monthly_limit');
            $table->integer('ai_max_image_size_mb')->default(5)->after('ai_max_audio_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('membership_ai_capabilities', function (Blueprint $table) {
            $table->dropColumn([
                'ai_voice_chat_enabled',
                'ai_image_analysis_enabled',
                'ai_file_upload_enabled',
                'ai_audio_monthly_limit',
                'ai_image_monthly_limit',
                'ai_max_audio_seconds',
                'ai_max_image_size_mb',
            ]);
        });
    }
};
