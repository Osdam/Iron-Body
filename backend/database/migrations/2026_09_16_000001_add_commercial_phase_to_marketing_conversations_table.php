<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memoria de la máquina comercial (ULTRON v1).
 *
 * Tres columnas, todas nullable y aditivas. No sustituyen a `lead_stage`,
 * `lead_score`, `primary_intent`, `last_intent` ni `detected_objective`:
 * conviven. `lead_stage` sigue alimentando el CRM de Mercadeo y se sigue
 * derivando de la intención; `commercial_phase` es otra cosa —dónde está la
 * conversación— y tiene transiciones legales que la anterior no tiene.
 *
 * `recommended_plan_id` guarda QUÉ plan recomendó el agente, nunca su precio.
 * El precio sale siempre de `Plan::price` en el momento de escribir el mensaje.
 * `nullOnDelete` porque desactivar o borrar un plan no puede llevarse por
 * delante el historial de la conversación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_conversations', 'commercial_phase')) {
                $table->string('commercial_phase', 40)->nullable()->after('lead_stage');
            }
            if (! Schema::hasColumn('marketing_conversations', 'main_barrier')) {
                $table->string('main_barrier', 40)->nullable()->after('detected_objective');
            }
            if (! Schema::hasColumn('marketing_conversations', 'recommended_plan_id')) {
                $table->unsignedBigInteger('recommended_plan_id')->nullable()->after('main_barrier');
            }
        });

        Schema::table('marketing_conversations', function (Blueprint $table): void {
            $table->index('commercial_phase', 'marketing_conversations_phase_idx');
            $table->foreign('recommended_plan_id', 'marketing_conversations_recommended_plan_fk')
                ->references('id')->on('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table): void {
            $table->dropForeign('marketing_conversations_recommended_plan_fk');
            $table->dropIndex('marketing_conversations_phase_idx');
            $table->dropColumn(['commercial_phase', 'main_barrier', 'recommended_plan_id']);
        });
    }
};
