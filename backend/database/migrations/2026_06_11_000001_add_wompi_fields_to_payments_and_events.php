<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración ADITIVA y REVERSIBLE para la integración Wompi. No edita ni borra
 * nada de lo aplicado en producción:
 *
 *  1) Extiende `payment_transactions` con columnas Wompi (todas nullable, los
 *     registros ePayco históricos siguen 100% legibles; `provider` distingue).
 *  2) Crea `payment_webhook_events` (idempotencia de webhooks; dedupe por hash).
 *  3) Crea `payment_consents` (auditoría de aceptación de términos + tratamiento
 *     de datos exigida por Wompi — NUNCA guarda secretos de la pasarela).
 *
 * Se reutilizan columnas existentes: `provider` (= gateway), `method`
 * (= payment_method), `provider_ref` (id de referencia de pasarela). Para Wompi
 * se añade además `wompi_transaction_id` (id propio de Wompi) por claridad.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $add = function (string $col, callable $def) use ($table) {
                if (! Schema::hasColumn('payment_transactions', $col)) {
                    $def($table);
                }
            };

            // Identificador público estable (además del `reference` interno).
            $add('uuid', fn ($t) => $t->uuid('uuid')->nullable()->unique());
            // Ambiente con el que se procesó (sandbox|production) — auditoría/seguridad.
            $add('environment', fn ($t) => $t->string('environment', 20)->nullable()->index());
            // Id de la transacción en Wompi (distinto de provider_ref legado de ePayco).
            $add('wompi_transaction_id', fn ($t) => $t->string('wompi_transaction_id')->nullable()->unique());
            // Mensaje legible del estado (sanitizado) y código del procesador.
            $add('status_message', fn ($t) => $t->string('status_message')->nullable());
            $add('processor_response_code', fn ($t) => $t->string('processor_response_code', 40)->nullable());
            // Datos del pagador (NO sensibles): email/phone y documento legal.
            $add('customer_email', fn ($t) => $t->string('customer_email')->nullable());
            $add('customer_phone', fn ($t) => $t->string('customer_phone', 30)->nullable());
            $add('customer_legal_id_type', fn ($t) => $t->string('customer_legal_id_type', 10)->nullable());
            $add('customer_legal_id', fn ($t) => $t->string('customer_legal_id', 40)->nullable());
            // URL oficial de autenticación externa (PSE/3DS) devuelta por Wompi.
            $add('external_auth_url', fn ($t) => $t->text('external_auth_url')->nullable());
            $add('redirect_url', fn ($t) => $t->text('redirect_url')->nullable());
            // Marcas de tiempo por estado terminal + control de reconciliación.
            $add('approved_at', fn ($t) => $t->timestamp('approved_at')->nullable());
            $add('declined_at', fn ($t) => $t->timestamp('declined_at')->nullable());
            $add('voided_at', fn ($t) => $t->timestamp('voided_at')->nullable());
            $add('failed_at', fn ($t) => $t->timestamp('failed_at')->nullable());
            $add('expires_at', fn ($t) => $t->timestamp('expires_at')->nullable());
            $add('last_reconciled_at', fn ($t) => $t->timestamp('last_reconciled_at')->nullable());
            $add('retry_count', fn ($t) => $t->unsignedInteger('retry_count')->default(0));
            // Datos NO sensibles de tarjeta si Wompi los devuelve.
            $add('card_brand', fn ($t) => $t->string('card_brand', 30)->nullable());
            $add('card_last_four', fn ($t) => $t->string('card_last_four', 4)->nullable());
            $add('installments', fn ($t) => $t->unsignedSmallInteger('installments')->nullable());
            $add('metadata', fn ($t) => $t->json('metadata')->nullable());
        });

        // Índice por created_at para reportes/reconciliación (si no existe ya).
        Schema::table('payment_transactions', function (Blueprint $table) {
            try {
                $table->index('created_at', 'payment_transactions_created_at_index');
            } catch (\Throwable $e) {
                // Índice ya existente: no es error.
            }
        });

        if (! Schema::hasTable('payment_webhook_events')) {
            Schema::create('payment_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('provider', 30)->default('wompi')->index();
                $table->string('event_type')->nullable();
                // Checksum/firma del evento (para trazabilidad; no es secreto).
                $table->string('checksum')->nullable();
                // Id de transacción referida por el evento.
                $table->string('transaction_id')->nullable()->index();
                $table->string('environment', 20)->nullable();
                // Hash SHA-256 del payload crudo → dedupe de reentregas idénticas.
                $table->string('payload_hash', 64);
                $table->json('payload')->nullable();
                // received | processed | skipped | failed
                $table->string('processing_status', 20)->default('received')->index();
                $table->timestamp('processed_at')->nullable();
                $table->string('error_message')->nullable();
                $table->unsignedInteger('retry_count')->default(0);
                $table->timestamps();

                // Idempotencia dura: un mismo payload (misma reentrega) no se
                // procesa dos veces. Cambios de estado reales traen otro payload.
                $table->unique(['provider', 'payload_hash'], 'webhook_events_provider_payload_unique');
            });
        }

        if (! Schema::hasTable('payment_consents')) {
            Schema::create('payment_consents', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                // Vínculo flexible: por referencia de la transacción y/o ids.
                $table->string('reference')->nullable()->index();
                $table->unsignedBigInteger('payment_transaction_id')->nullable()->index();
                $table->unsignedBigInteger('member_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                // Tokens/enlaces de aceptación de Wompi aceptados por el usuario.
                // (El token presigned NO es un secreto de la pasarela.)
                $table->text('acceptance_token')->nullable();
                $table->text('accept_personal_auth_token')->nullable();
                $table->text('terms_link')->nullable();
                $table->text('privacy_link')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent')->nullable();
                $table->string('environment', 20)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_consents');
        Schema::dropIfExists('payment_webhook_events');

        Schema::table('payment_transactions', function (Blueprint $table) {
            foreach ([
                'uuid', 'environment', 'wompi_transaction_id', 'status_message',
                'processor_response_code', 'customer_email', 'customer_phone',
                'customer_legal_id_type', 'customer_legal_id', 'external_auth_url',
                'redirect_url', 'approved_at', 'declined_at', 'voided_at',
                'failed_at', 'expires_at', 'last_reconciled_at', 'retry_count',
                'card_brand', 'card_last_four', 'installments', 'metadata',
            ] as $col) {
                if (Schema::hasColumn('payment_transactions', $col)) {
                    try {
                        $table->dropColumn($col);
                    } catch (\Throwable $e) {
                        // best-effort
                    }
                }
            }
        });
    }
};
