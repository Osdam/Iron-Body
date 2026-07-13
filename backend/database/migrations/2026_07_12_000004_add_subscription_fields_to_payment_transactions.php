<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza los COBROS RECURRENTES con su suscripción REUTILIZANDO la tabla
 * `payment_transactions` (y con ella todo el flujo existente: máquina de estados,
 * webhook, reconciliación y activación de membresía). Un cobro automático es una
 * PaymentTransaction normal con estos campos rellenos; el pago único los deja NULL.
 *
 * ADITIVA y REVERSIBLE. Todas las columnas nullable; no altera ni un registro
 * existente. El modelo PaymentTransaction NO se modifica en este bloque (los
 * campos se empezarán a escribir en el bloque de servicios).
 *
 * Idempotencia DURA anti-doble-cobro: índice único (subscription_id,
 * billing_period). Como ambas columnas son nullable, los pagos únicos (NULL,NULL)
 * NUNCA colisionan (Postgres y sqlite tratan NULL como distinto en índices únicos).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('payment_transactions')) {
            return;
        }

        Schema::table('payment_transactions', function (Blueprint $table) {
            $add = function (string $col, callable $def) use ($table) {
                if (! Schema::hasColumn('payment_transactions', $col)) {
                    $def($table);
                }
            };

            // Suscripción de la que proviene este cobro (membership_subscriptions.id).
            $add('subscription_id', fn ($t) => $t->unsignedBigInteger('subscription_id')->nullable()->index());
            // Clave de periodo facturado (p. ej. "{sub_uuid}:{period_start}"). Junto
            // con subscription_id garantiza UN cobro por periodo (anti doble cobro).
            $add('billing_period', fn ($t) => $t->string('billing_period')->nullable());
            // Marca explícita de cobro recurrente (COF/3RI) frente al pago único.
            $add('is_recurring', fn ($t) => $t->boolean('is_recurring')->default(false));
            // Fuente de pago Wompi usada (id NO sensible), para trazabilidad.
            $add('wompi_payment_source_id', fn ($t) => $t->string('wompi_payment_source_id')->nullable());
        });

        // Índice único de idempotencia por periodo. Se crea aparte y de forma
        // tolerante (si ya existiera, no es error).
        Schema::table('payment_transactions', function (Blueprint $table) {
            try {
                $table->unique(['subscription_id', 'billing_period'], 'payment_transactions_subscription_period_unique');
            } catch (\Throwable $e) {
                // Índice ya existente: no es error.
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_transactions')) {
            return;
        }

        Schema::table('payment_transactions', function (Blueprint $table) {
            try {
                $table->dropUnique('payment_transactions_subscription_period_unique');
            } catch (\Throwable $e) {
                // best-effort
            }
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            foreach (['subscription_id', 'billing_period', 'is_recurring', 'wompi_payment_source_id'] as $col) {
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
