<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox de eventos comerciales Laravel → n8n (ULTRON).
 *
 * Existe en lugar de reutilizar `automation_events` por una razón que se
 * comprobó antes de decidirla: aquella tabla es MEMBER-céntrica. Su columna
 * `member_id` no tiene clave foránea —así que técnicamente admitiría cualquier
 * número— pero la consultan `ProactiveCoachService` y
 * `GenerateWeeklyAiSummaries` para razonar sobre socios. Meter ahí el id de un
 * `marketing_leads` significaría que el lead 5 y el socio 5 son la misma
 * persona para esos servicios, y no lo son. Con 278 675 filas dentro, el
 * estropicio no se deshace.
 *
 * Así que un outbox propio, lead-céntrico, con el mismo patrón probado: se
 * persiste ANTES de intentar el envío, se deduplica por `idempotency_key`
 * único, y los fallos quedan con su motivo en vez de desaparecer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_automation_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64);
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->jsonb('payload_json')->nullable();
            // pending | sent | failed | skipped
            $table->string('status', 16)->default('pending');
            $table->string('idempotency_key', 160)->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'marketing_automation_events_queue_idx');
            $table->index('conversation_id');

            // Sí hay FK aquí: el lead es de verdad un lead, y si se borra no
            // tiene sentido conservar un evento que apunta a nadie.
            $table->foreign('lead_id')->references('id')->on('marketing_leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_automation_events');
    }
};
