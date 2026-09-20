<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El índice que pide la pregunta «¿este mensaje va a tener turno?».
 *
 * `UltronDecideService::supersededBy()` correlaciona por `message_id` en un
 * `whereExists`, y esa consulta corre en CADA decide y en CADA commit. La tabla
 * tenía índices por `status+created_at` y por `conversation_id`, pero no por la
 * columna con la que se cruza: sin él, la comprobación degenera en un recorrido
 * de la tabla a medida que el outbox crece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_automation_events', function (Blueprint $table): void {
            $table->index('message_id', 'marketing_automation_events_message_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_automation_events', function (Blueprint $table): void {
            $table->dropIndex('marketing_automation_events_message_idx');
        });
    }
};
