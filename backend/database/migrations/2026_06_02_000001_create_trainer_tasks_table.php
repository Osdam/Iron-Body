<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tareas/alertas accionables para el ENTRENADOR HUMANO asignado a un miembro.
 *
 * Las generan los eventos de automatización (workout.missed, nutrition.missing,
 * progress.stalled, evaluation.outdated, membership.expiring, weekly_summary)
 * cuando el miembro tiene un entrenador activo (member_trainer_assignments).
 * Complementan —no reemplazan— las notificaciones de IRON IA al miembro
 * (app_notifications). NUNCA guardan datos sensibles (el servicio sanea).
 *
 * Idempotencia: `idempotency_key` único evita duplicar la tarea por el mismo
 * evento. `automation_event_id` traza la señal original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainer_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('trainer_id');
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('automation_event_id')->nullable();
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->string('priority')->default('normal'); // low | normal | high
            $table->string('status')->default('pending');   // pending | seen | done | dismissed
            $table->string('action_route')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['trainer_id', 'status']);
            $table->index('member_id');
            $table->index('type');
            $table->index('created_at');
            $table->index('automation_event_id');

            // FKs aditivas: si se borra el miembro/entrenador, se limpia el histórico.
            $table->foreign('trainer_id')->references('id')->on('trainers')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('automation_event_id')->references('id')->on('automation_events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_tasks');
    }
};
