<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IRON IA multimodal — distingue el tipo de consumo (text|audio|image) para
 * poder aplicar cuotas por tipo (audios/mes, imágenes/mes) sin afectar el
 * conteo de mensajes de texto existente. Filas previas → 'text'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iron_ai_usage_logs', function (Blueprint $table) {
            $table->string('kind')->default('text')->after('status'); // text | audio | image
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('iron_ai_usage_logs', function (Blueprint $table) {
            $table->dropIndex(['kind', 'created_at']);
            $table->dropColumn('kind');
        });
    }
};
