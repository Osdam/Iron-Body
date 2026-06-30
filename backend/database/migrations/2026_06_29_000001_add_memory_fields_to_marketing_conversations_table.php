<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memoria comercial por conversación (aditivo, sin tocar el flujo de membresías).
 * Guarda un resumen corto, el objetivo detectado, el score 0-100, la etapa del
 * lead y las intenciones (principal + última) para usarlos en el siguiente
 * análisis. Nada de esto activa pagos ni membresías.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table): void {
            $table->text('summary')->nullable()->after('ai_enabled');
            $table->string('detected_objective')->nullable()->after('summary');
            $table->unsignedSmallInteger('lead_score')->nullable()->after('detected_objective');
            $table->string('lead_stage')->nullable()->after('lead_score');
            $table->string('primary_intent')->nullable()->after('lead_stage');
            $table->string('last_intent')->nullable()->after('primary_intent');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table): void {
            $table->dropColumn([
                'summary', 'detected_objective', 'lead_score',
                'lead_stage', 'primary_intent', 'last_intent',
            ]);
        });
    }
};
