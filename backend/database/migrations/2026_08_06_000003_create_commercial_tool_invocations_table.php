<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El acta de todo lo que el agente ejecuta.
 *
 * Una herramienta comercial mueve dinero, membresías y facturas. Cuando algo
 * salga mal —y saldrá— la pregunta no será «¿qué hizo la IA?» sino «¿con qué
 * argumentos exactos, quién lo autorizó, qué devolvió y por qué se decidió
 * eso?». Sin esta tabla la respuesta es un log que nadie guarda.
 *
 * Cumple además dos funciones que no son de auditoría:
 *
 *  · **Idempotencia.** `idempotency_key` es único. Es la barrera que impide que
 *    un reintento genere un segundo enlace de pago o una segunda cita. La
 *    protección vive aquí, en la base, y no en la buena voluntad de quien
 *    llama.
 *
 *  · **Reintento informado.** Se guarda si el fallo era transitorio y cuántas
 *    veces se intentó, para poder distinguir «Wompi estaba caído» de «los datos
 *    estaban mal», que se resuelven de forma opuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_tool_invocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('tool');
            // Única: la barrera real contra el doble envío.
            $table->string('idempotency_key')->unique();

            // Sujeto y origen de la decisión.
            $table->unsignedBigInteger('marketing_lead_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->unsignedBigInteger('commercial_opportunity_id')->nullable();
            $table->unsignedBigInteger('marketing_conversation_id')->nullable();

            // Quién lo pidió: el motor, un asesor, o una prueba.
            $table->string('requested_by')->default('engine');
            $table->unsignedBigInteger('approved_by_admin_id')->nullable();

            // Por qué. Se guarda la decisión completa para poder explicarla sin
            // tener que reconstruirla desde los logs.
            $table->string('goal')->nullable();
            $table->string('decision_action')->nullable();
            $table->text('reason')->nullable();

            // Argumentos YA validados, nunca los crudos que propuso el modelo.
            $table->jsonb('arguments')->nullable();
            $table->jsonb('result')->nullable();

            // pending → running → succeeded | failed | rejected | skipped
            $table->string('status')->default('pending');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('retryable')->default(false);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();

            $table->uuid('correlation_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tool', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['marketing_lead_id', 'created_at']);
            $table->index(['member_id', 'created_at']);
            $table->index('commercial_opportunity_id');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_tool_invocations');
    }
};
