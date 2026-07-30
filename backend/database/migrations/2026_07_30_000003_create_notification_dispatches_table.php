<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libro mayor de envíos. Una fila por INTENTO, enviado o no.
 *
 * Registrar también lo suprimido es lo que hace auditable el sistema: sin ello,
 * "no me llegó" y "el sistema decidió no mandártelo porque era medianoche" son
 * indistinguibles, que es justo la duda que costó este trabajo de diagnosticar.
 *
 * Sirve a la vez de:
 *  - llave de idempotencia (`idempotency_key` única),
 *  - contador para los límites diarios/semanales,
 *  - memoria de qué plantilla vio ya el socio (para no repetirla),
 *  - historial y métricas para el CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_dispatches')) {
            return;
        }

        Schema::create('notification_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->string('category', 40);
            $table->string('supplement_kind', 40)->nullable();
            $table->string('template_key', 80)->nullable();

            // Lo que de verdad se envió, no lo que la plantilla decía hoy.
            $table->string('title');
            $table->text('body');
            $table->string('action_route')->nullable();

            $table->string('idempotency_key', 191)->unique();

            // sent | suppressed | failed
            $table->string('status', 20);
            $table->string('reason', 60)->nullable();

            $table->unsignedSmallInteger('tokens_targeted')->default(0);
            $table->unsignedSmallInteger('tokens_delivered')->default(0);

            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'created_at'], 'nd_member_created_idx');
            $table->index(['member_id', 'category', 'created_at'], 'nd_member_cat_idx');
            $table->index(['category', 'status'], 'nd_cat_status_idx');
            $table->index('campaign_id', 'nd_campaign_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
    }
};
