<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca el ORIGEN del human_takeover: 'manual' cuando un asesor/administrador toma
 * control desde el CRM. Permite distinguir un takeover manual (que SÍ puede pausar
 * la IA) de takeovers automáticos viejos (que NUNCA deben dejar muda a la IA).
 *
 * Aditivo y nullable: no afecta filas existentes ni otros flujos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table): void {
            $table->string('human_takeover_source')->nullable()->after('human_takeover');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table): void {
            $table->dropColumn('human_takeover_source');
        });
    }
};
