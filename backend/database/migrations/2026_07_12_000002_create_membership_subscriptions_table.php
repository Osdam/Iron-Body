<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suscripción de membresía (pago automático tipo Netflix). Representa la
 * AUTORIZACIÓN recurrente del miembro sobre un plan, cobrada periódicamente
 * usando una `wompi_payment_sources`. NO cobra por sí sola: el cobro real es una
 * `PaymentTransaction` normal (provider=wompi) que fluye por el activador de
 * membresía existente. Aquí vive el "quién / cuándo / con qué fuente / a qué precio".
 *
 * Reglas selladas en el esquema:
 *   - `price_snapshot`: precio AUTORITATIVO congelado al autorizar (evita que un
 *     cambio de tarifa altere cobros ya consentidos).
 *   - `next_charge_at`: cuándo toca el próximo cobro (el scheduler lo detecta).
 *   - Idempotencia "una sola suscripción viva por miembro": índice único PARCIAL
 *     en Postgres (real). En sqlite de test se omite (se cubre en la capa de
 *     servicio con lockForUpdate); el índice DURO que impide el doble cobro por
 *     periodo vive en `payment_transactions (subscription_id, billing_period)`.
 *
 * Aditiva; no toca el flujo de pago único.
 */
return new class extends Migration {
    /** Estados que cuentan como "suscripción viva" (no permite duplicar por miembro). */
    private const LIVE_STATUSES = ['pending_first_payment', 'active', 'past_due', 'paused'];

    public function up(): void
    {
        if (! Schema::hasTable('membership_subscriptions')) {
            Schema::create('membership_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();

                $table->unsignedBigInteger('member_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('plan_id')->nullable()->index();
                // Fuente de pago usada para los cobros (wompi_payment_sources.id).
                $table->unsignedBigInteger('payment_source_id')->nullable()->index();

                // pending_first_payment | active | past_due | paused | cancelled
                //  | expired | failed
                $table->string('status', 30)->default('pending_first_payment')->index();

                // Precio congelado al autorizar (autoritativo). Nunca se lee del cliente.
                $table->decimal('price_snapshot', 12, 2)->default(0);
                $table->string('currency', 3)->default('COP');
                // Duración del ciclo en días (del plan al autorizar).
                $table->unsignedInteger('interval_days')->default(30);
                // Método de cobro recurrente (por ahora siempre 'card').
                $table->string('method', 20)->default('card');

                // Programación del cobro.
                $table->timestamp('next_charge_at')->nullable()->index();
                $table->date('current_period_start')->nullable();
                $table->date('current_period_end')->nullable();
                $table->timestamp('last_charged_at')->nullable();

                // Control de reintentos (escalera +1d / +3d → past_due).
                $table->unsignedInteger('failed_attempts')->default(0);
                // 0 = sin reintento pendiente; 1 = +1 día; 2 = +3 días.
                $table->unsignedSmallInteger('retry_stage')->default(0);

                // Referencia del último intento de cobro (payment_transactions.reference).
                $table->string('last_charge_reference')->nullable()->index();

                // Cancelación (conserva histórico; corta cobros futuros).
                $table->boolean('cancel_at_period_end')->default(false);
                $table->timestamp('cancelled_at')->nullable();
                // member | admin | system
                $table->string('cancelled_by', 20)->nullable();
                $table->string('cancel_reason')->nullable();

                $table->timestamp('paused_at')->nullable();

                $table->json('metadata')->nullable();

                $table->timestamps();

                $table->index(['member_id', 'status'], 'membership_subscriptions_member_status_index');
                $table->index(['status', 'next_charge_at'], 'membership_subscriptions_status_next_charge_index');
            });
        }

        // Índice único PARCIAL: máximo UNA suscripción viva por miembro. Solo
        // Postgres (la BD real). En sqlite (tests) se omite → lo cubre el servicio.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $statuses = "'".implode("','", self::LIVE_STATUSES)."'";
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS membership_subscriptions_one_live_per_member '
                .'ON membership_subscriptions (member_id) '
                ."WHERE member_id IS NOT NULL AND status IN ($statuses)"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS membership_subscriptions_one_live_per_member');
        }
        Schema::dropIfExists('membership_subscriptions');
    }
};
