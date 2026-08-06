<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IRON GUARD — incidentes del canal y su historia.
 *
 * El problema que resuelve: hoy, cuando algo falla en el canal de WhatsApp,
 * queda una línea en un log que nadie lee hasta que un cliente se queja. No hay
 * forma de saber si el fallo ocurrió una vez o doscientas, si empezó tras un
 * despliegue, ni a cuántas conversaciones afectó.
 *
 * `fingerprint` es la clave de todo: identifica la CLASE de problema, no cada
 * ocurrencia. Doscientos mensajes que fallan por el mismo motivo son UN
 * incidente con doscientas ocurrencias, no doscientas alarmas. Sin eso, un
 * worker caído una hora genera tanto ruido que el panel se vuelve inútil y se
 * deja de mirar, que es la forma habitual en que muere la observabilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();

            // Identidad de la CLASE de problema. Único: la segunda ocurrencia
            // suma, no duplica.
            $table->string('fingerprint', 64)->unique();

            $table->string('source');        // meta_webhook | outbox | media | queue | hermes | openai
            $table->string('kind');          // identificador estable del tipo de fallo
            $table->string('title');
            $table->string('severity')->default('medium'); // low | medium | high | critical
            // open → acknowledged → resolved | ignored
            $table->string('status')->default('open');

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('occurrences')->default(1);

            // A cuánto afecta: conversaciones y mensajes tocados. Es lo que
            // convierte "hay un error" en "hay un error que dejó a 12 personas
            // sin respuesta", que es lo único que permite priorizar.
            $table->unsignedInteger('affected_conversations')->default(0);
            $table->unsignedInteger('affected_messages')->default(0);

            // Evidencia concreta: ids, códigos, muestras. Nunca datos personales
            // ni el texto completo de un prospecto.
            $table->jsonb('evidence')->nullable();
            // Hilos que se pueden seguir en el log para reproducirlo.
            $table->jsonb('correlation_ids')->nullable();

            // Análisis. Se rellena solo si se pide (y con presupuesto): la
            // detección es determinista, la hipótesis es lo caro.
            $table->text('root_cause')->nullable();
            $table->string('confidence')->nullable();       // low | medium | high
            $table->text('recommended_action')->nullable();
            $table->text('prevention')->nullable();
            $table->string('analyzed_by')->nullable();      // rules | hermes | openai
            $table->timestamp('analyzed_at')->nullable();

            // Despliegue durante el que apareció: la primera pregunta ante un
            // incidente nuevo siempre es "¿qué cambió?".
            $table->string('release')->nullable();

            $table->unsignedBigInteger('assigned_to_admin_id')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();

            $table->timestamps();

            // El panel: incidentes abiertos por gravedad y recencia.
            $table->index(['status', 'severity', 'last_seen_at']);
            $table->index(['source', 'last_seen_at']);
        });

        Schema::create('incident_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('incident_id');

            // occurrence  — se repitió
            // analysis    — se generó una hipótesis de causa raíz
            // remediation — se ejecutó una acción de la allowlist
            // note        — alguien escribió algo
            // status      — cambió de estado (reconocido, resuelto…)
            $table->string('kind');
            $table->string('actor')->nullable();   // system | admin:<id> | iron-guard
            $table->text('summary')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'id']);

            $table->foreign('incident_id')->references('id')->on('incidents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_events');
        Schema::dropIfExists('incidents');
    }
};
