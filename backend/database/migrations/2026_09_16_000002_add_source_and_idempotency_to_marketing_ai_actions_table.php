<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procedencia e idempotencia de las decisiones del agente (ULTRON v1).
 *
 * Hasta ahora «¿ya analicé este mensaje?» se respondía con
 * `whereJsonContains('metadata->message_id', $id)` dentro de
 * {@see \App\Jobs\AnalyzeInboundMessage}: una comprobación LÓGICA, hecha antes
 * de tomar el cerrojo. Dos caminos concurrentes la pasan los dos. Con n8n
 * llamando por HTTP —que no participa de ese cerrojo— deja de ser teórico.
 *
 * `idempotency_key` UNIQUE convierte esa comprobación en una restricción de la
 * base de datos: el segundo INSERT falla y el llamante recibe un 409 con el
 * `ai_action_id` original. Ya no hay carrera que ganar.
 *
 * NULLABLE a propósito y sin backfill: en PostgreSQL un UNIQUE admite tantos
 * NULL como haga falta, así que las filas anteriores (129 en producción) y todo
 * el camino legado siguen funcionando sin tocarlas.
 *
 * Fuentes previstas:
 *   inbound_message → source_event_id = marketing_messages.id
 *                     idempotency_key = meta_message_id
 *   followup (v2)   → source_event_id = marketing_followups.id
 *                     idempotency_key = followup:{id}:{intento}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_ai_actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_ai_actions', 'source_type')) {
                $table->string('source_type', 32)->nullable()->after('conversation_id');
            }
            if (! Schema::hasColumn('marketing_ai_actions', 'source_event_id')) {
                $table->unsignedBigInteger('source_event_id')->nullable()->after('source_type');
            }
            if (! Schema::hasColumn('marketing_ai_actions', 'idempotency_key')) {
                $table->string('idempotency_key', 160)->nullable()->after('source_event_id');
            }
        });

        Schema::table('marketing_ai_actions', function (Blueprint $table): void {
            $table->unique('idempotency_key', 'marketing_ai_actions_idempotency_key_unique');
            $table->index(['source_type', 'source_event_id'], 'marketing_ai_actions_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_ai_actions', function (Blueprint $table): void {
            $table->dropUnique('marketing_ai_actions_idempotency_key_unique');
            $table->dropIndex('marketing_ai_actions_source_idx');
            $table->dropColumn(['source_type', 'source_event_id', 'idempotency_key']);
        });
    }
};
