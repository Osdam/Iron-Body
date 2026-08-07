<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operaciones que una persona tiene que autorizar antes de que ocurran.
 *
 * Tabla propia y no una columna más en `marketing_agent_actions`, aunque ahí ya
 * exista un `requires_approval`. Son dos cosas distintas: aquello son acciones
 * conversacionales que el agente sugiere —responder, agendar, etiquetar— y esto
 * son excepciones que mueven dinero o tocan documentos fiscales. Un descuento,
 * una nota crédito o una fusión de identidad no comparten ciclo de vida, ni
 * autorización, ni consecuencias con «sugerir una respuesta», y mezclarlas
 * obligaría a que cada consulta de una recordara excluir la otra.
 *
 * Lo que sostiene la integridad de esta tabla:
 *
 *  · **`idempotency_key` única.** Una aprobación se ejecuta UNA vez. Sin esto,
 *    dos supervisores pulsando aprobar a la vez —o el mismo pulsando dos veces
 *    porque no vio el spinner— producen dos reembolsos.
 *
 *  · **`decided_at` y `executed_at` separados.** Aprobar no es ejecutar.
 *    Confundirlos impide distinguir «lo autorizó y falló» de «nadie lo miró»,
 *    que es justo lo que hay que saber cuando un cliente reclama.
 *
 *  · **`expires_at`.** Una autorización sin caducidad es una autorización
 *    eterna: alguien aprueba un descuento hoy y se ejecuta dentro de tres
 *    meses, cuando ya no tiene sentido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_approvals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Qué se pide autorizar. Lista cerrada en el vocabulario.
            $table->string('type');
            $table->string('status')->default('pending');

            // A quién afecta. Los tres pueden ser nulos: una campaña masiva no
            // tiene un cliente concreto detrás.
            $table->unsignedBigInteger('marketing_lead_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->unsignedBigInteger('marketing_conversation_id')->nullable();

            // Quién lo pide: el agente, un empleado, o un proceso.
            $table->string('requested_by')->default('agent');
            $table->unsignedBigInteger('requested_by_admin_id')->nullable();

            // El dinero en juego. Nulo cuando la operación no mueve importe
            // -una fusión de identidad, por ejemplo-, y eso NO es cero.
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->text('justification');
            $table->jsonb('evidence')->nullable();
            /** low|medium|high: cuánto duele si se aprueba mal. */
            $table->string('risk')->default('medium');
            $table->text('impact')->nullable();

            // Decisión humana.
            $table->unsignedBigInteger('decided_by_admin_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();

            // Ejecución, separada de la decisión a propósito.
            $table->timestamp('executed_at')->nullable();
            $table->text('execution_result')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('expires_at')->nullable();

            /*
             * La clave que impide ejecutar dos veces. Única sobre la tabla, así
             * que la carrera la resuelve la base de datos y no un `if` que dos
             * procesos pueden pasar a la vez.
             */
            $table->string('idempotency_key')->unique();
            $table->string('correlation_id')->nullable();

            $table->timestamps();

            // La bandeja se lee siempre igual: pendientes primero, más viejas
            // arriba. Este índice es esa consulta.
            $table->index(['status', 'created_at']);
            $table->index(['type', 'status']);
            $table->index('marketing_lead_id');
            $table->index('member_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_approvals');
    }
};
