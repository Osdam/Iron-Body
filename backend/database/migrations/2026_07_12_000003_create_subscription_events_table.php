<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de auditoría de una suscripción: cada cambio relevante (creada, fuente
 * vinculada, cobro aprobado/declinado, reintento programado, past_due, pausada,
 * reanudada, cancelada, expirada) queda registrado con actor y correlación. NO se
 * borra al cancelar (histórico legal/auditoría). Sin datos sensibles.
 *
 * Aditiva; independiente del flujo de pago único.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('subscription_events')) {
            return;
        }

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('subscription_id')->index();
            $table->unsignedBigInteger('member_id')->nullable()->index();

            // created | source_attached | first_charge_approved | charge_approved
            //  | charge_declined | charge_error | retry_scheduled | past_due
            //  | paused | resumed | cancelled | expired
            $table->string('type', 40)->index();
            // member | admin | system
            $table->string('actor', 20)->nullable();

            // Correlación con el intento de cobro (payment_transactions.reference).
            $table->string('reference')->nullable()->index();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('message')->nullable();

            // Contexto auditable NO sensible (estados previos/nuevos, códigos, etc.).
            $table->json('context')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
