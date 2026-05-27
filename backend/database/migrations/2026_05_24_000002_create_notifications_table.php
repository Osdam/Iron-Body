<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla propia de notificaciones de Iron Body (NO la tabla nativa morph de
 * Laravel: el proyecto no usa el canal database de Notifiable). Es la única
 * fuente de verdad que consumen tanto la app Flutter como el CRM Angular.
 *
 * `event_key` da idempotencia: un evento real (un pago, una reserva, una
 * asignación de rutina) genera como máximo UNA notificación por audiencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Destinatario (cualquiera puede ser null según la audiencia).
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('member_id')->nullable()->index();
            $table->string('document')->nullable()->index();

            // member | admin | trainer | system
            $table->string('audience')->default('member')->index();
            // payment | membership | class | system | trainer | promotion | iron_ai | routine
            $table->string('type')->default('system')->index();

            $table->string('title');
            $table->text('message');

            // unread | read
            $table->string('status')->default('unread')->index();
            // low | medium | high
            $table->string('priority')->default('medium');

            $table->string('action_type')->nullable();
            $table->string('action_url')->nullable();
            $table->json('action_payload')->nullable();
            $table->json('metadata')->nullable();

            // Idempotencia por evento real. Único cuando está presente.
            $table->string('event_key')->nullable()->unique();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['audience', 'status']);
            $table->index(['document', 'audience']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
