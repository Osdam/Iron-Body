<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor comercial: oportunidades, segmentos y eventos.
 *
 * El principio que sostiene todo esto: **ninguna venta termina la relación
 * comercial**. Hoy, cuando alguien paga un plan mensual, el sistema registra el
 * pago y se olvida. No hay nada que diga «este cliente lleva tres semanas
 * viniendo cuatro veces por semana, es el momento de hablarle del anual», ni
 * nada que recuerde que hace mes y medio dejó un enlace de pago a medias.
 *
 * Estas tres tablas son la memoria comercial que faltaba:
 *
 *  · `commercial_opportunities` — qué toca hacer con esta persona, cuándo, por
 *    qué, y qué NO hacer todavía. Una oportunidad abierta por sujeto y objetivo.
 *  · `commercial_segments` — en qué situación está, calculado con evidencia y
 *    fecha, no una etiqueta pegada a mano que nadie vuelve a mirar.
 *  · `commercial_events` — lo que ha ido pasando. Cada evento puede provocar
 *    que se recalcule la siguiente mejor acción.
 *
 * Las reglas viven en PHP, no en el modelo de lenguaje. La IA redacta e
 * interpreta; Laravel decide, limita y audita. Esa separación es deliberada:
 * una oferta la tiene que poder explicar un humano mirando una fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Sujeto: un prospecto que aún no es miembro, o un miembro. Puede
            // tener los dos si el lead ya se convirtió.
            $table->unsignedBigInteger('marketing_lead_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->unsignedBigInteger('marketing_conversation_id')->nullable();

            // Objetivo comercial activo (cerrar plan, cobrar, renovar, reactivar…).
            $table->string('goal');
            // open → in_progress → won | lost | expired | cancelled | blocked
            $table->string('status')->default('open');

            // La decisión del motor.
            $table->string('next_action');
            $table->string('next_offer')->nullable();
            // Oferta principal, alternativa y mínimo comercial aceptable. Tener
            // los tres evita el error clásico de ir a por el anual y volver con
            // las manos vacías cuando el semestral se habría cerrado.
            $table->unsignedBigInteger('offer_plan_id')->nullable();
            $table->unsignedBigInteger('alternative_plan_id')->nullable();
            $table->unsignedBigInteger('floor_plan_id')->nullable();

            // Prioridad 1-100: mayor number, antes se atiende.
            $table->unsignedSmallInteger('priority')->default(50);
            $table->decimal('confidence', 5, 4)->default(0.5);

            // Por qué esta acción, y por qué NO otra. Lo segundo importa tanto
            // como lo primero: «no ofrecer anual todavía porque no ha venido
            // nunca» es una decisión que hay que poder auditar.
            $table->text('reason');
            $table->jsonb('exclusions')->nullable();
            // Datos concretos que se usaron para decidir.
            $table->jsonb('evidence')->nullable();

            // Momento correcto. Un mensaje bueno en mal momento es un mensaje malo.
            $table->timestamp('act_after')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('channel')->default('whatsapp');

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('last_attempt_at')->nullable();

            // Resultado, para poder atribuir ingresos y aprender.
            $table->string('outcome')->nullable();
            $table->string('outcome_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            // Valor esperado en COP. Es lo que convierte una lista de tareas en
            // una cartera priorizable por dinero.
            $table->decimal('estimated_value', 12, 2)->nullable();
            $table->decimal('realized_value', 12, 2)->nullable();

            $table->string('created_by')->default('engine'); // engine | admin | ai
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();

            // La cola de trabajo: qué toca ahora, por prioridad.
            $table->index(['status', 'act_after', 'priority']);
            $table->index(['marketing_lead_id', 'status']);
            $table->index(['member_id', 'status']);
            $table->index(['goal', 'status']);

            $table->foreign('marketing_lead_id')->references('id')->on('marketing_leads')->nullOnDelete();
            $table->foreign('marketing_conversation_id')->references('id')->on('marketing_conversations')->nullOnDelete();
        });

        Schema::create('commercial_segments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketing_lead_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();

            $table->string('segment');
            // Un segmento sin evidencia es una etiqueta, y las etiquetas
            // envejecen mal. Aquí siempre se sabe por qué y desde cuándo.
            $table->decimal('confidence', 5, 4)->default(1.0);
            $table->jsonb('evidence')->nullable();
            $table->timestamp('computed_at');
            // Caducidad: pasado el plazo hay que recalcular, no dar por bueno.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['segment', 'computed_at']);
            $table->unique(['marketing_lead_id', 'segment'], 'commercial_segments_lead_unique');
            $table->unique(['member_id', 'segment'], 'commercial_segments_member_unique');

            $table->foreign('marketing_lead_id')->references('id')->on('marketing_leads')->cascadeOnDelete();
        });

        Schema::create('commercial_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marketing_lead_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->unsignedBigInteger('commercial_opportunity_id')->nullable();

            $table->string('event');
            $table->jsonb('payload')->nullable();
            $table->timestamp('occurred_at');
            // Cuándo lo evaluó el motor. Null = pendiente de evaluar.
            $table->timestamp('evaluated_at')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();

            $table->index(['event', 'occurred_at']);
            $table->index(['evaluated_at', 'id']);
            $table->index(['marketing_lead_id', 'occurred_at']);
            $table->index(['member_id', 'occurred_at']);

            $table->foreign('marketing_lead_id')->references('id')->on('marketing_leads')->cascadeOnDelete();
            $table->foreign('commercial_opportunity_id')->references('id')->on('commercial_opportunities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_events');
        Schema::dropIfExists('commercial_segments');
        Schema::dropIfExists('commercial_opportunities');
    }
};
