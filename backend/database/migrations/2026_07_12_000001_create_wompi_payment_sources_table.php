<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuentes de pago tokenizadas de Wompi para COBRO RECURRENTE (pago automático de
 * membresías). Guarda la referencia SEGURA que Wompi entrega tras tokenizar +
 * crear la fuente (`payment_source_id`), NUNCA datos sensibles: sin PAN, sin CVC,
 * sin fecha completa. Solo marca, últimos 4 y expiración (si Wompi los devuelve).
 *
 * Aditiva y aislada: no toca `payment_transactions`, `payments` ni el flujo de
 * pago único. Todo el módulo recurrente queda detrás de WOMPI_RECURRING_ENABLED.
 *
 * Métodos soportados por Wompi para fuentes: CARD y NEQUI (docs oficiales). Este
 * proyecto arranca SOLO con CARD; NEQUI queda modelado pero inactivo por flag.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('wompi_payment_sources')) {
            return;
        }

        Schema::create('wompi_payment_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Vínculos (sin FK dura, igual que payment_transactions.plan_id/member_id).
            $table->unsignedBigInteger('member_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('provider', 30)->default('wompi')->index();
            // Id de la fuente en Wompi (POST /payment_sources → data.id). Nullable
            // hasta que Wompi la crea; unique cuando existe.
            $table->string('wompi_payment_source_id')->nullable()->unique();

            // CARD | NEQUI (Wompi type). Este proyecto usa CARD por ahora.
            $table->string('type', 20)->default('CARD');

            // pending | available | declined | expired | revoked | failed
            $table->string('status', 20)->default('pending')->index();
            $table->string('status_message')->nullable();

            // Estado del proceso 3DS al crear la fuente (opcional; solo tarjeta):
            // pending | available | declined | error. Se llena si 3DS está activo.
            $table->string('three_ds_status', 20)->nullable();

            // Datos NO sensibles de la tarjeta (si Wompi los devuelve).
            $table->string('card_brand', 30)->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->string('exp_month', 2)->nullable();
            $table->string('exp_year', 4)->nullable();

            $table->string('customer_email')->nullable();
            $table->string('environment', 20)->nullable()->index();

            // Fuente por defecto del miembro para cobros automáticos.
            $table->boolean('is_default')->default(false);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Metadatos auditables NO sensibles (correlación, flags 3DS, etc.).
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['member_id', 'status'], 'wompi_payment_sources_member_status_index');
            $table->index(['user_id', 'status'], 'wompi_payment_sources_user_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wompi_payment_sources');
    }
};
