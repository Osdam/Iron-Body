<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renovación / cancelación de membresía (Bloque 3).
 *
 * La verdad de la membresía vive en `users` (plan + fechas). Estas columnas
 * modelan el ciclo de renovación/cancelación de forma productiva:
 *   - membership_auto_renew: el usuario quiere renovar (intención). false tras
 *     solicitar cancelación.
 *   - cancellation_requested_at: cuándo se pidió cancelar (no borra datos).
 *   - cancellation_effective_at: hasta cuándo conserva acceso (= fin de periodo
 *     vigente). Al expirar esa fecha la membresía queda 'cancelled'/'expired'.
 *   - payment_provider_subscription_id: id de suscripción del proveedor de cobro
 *     recurrente (hook reservado; null mientras no haya proveedor conectado).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'membership_auto_renew')) {
                $table->boolean('membership_auto_renew')->default(true)->after('membership_end_date');
            }
            if (! Schema::hasColumn('users', 'membership_cancellation_requested_at')) {
                $table->timestamp('membership_cancellation_requested_at')->nullable()->after('membership_auto_renew');
            }
            if (! Schema::hasColumn('users', 'membership_cancellation_effective_at')) {
                $table->date('membership_cancellation_effective_at')->nullable()->after('membership_cancellation_requested_at');
            }
            if (! Schema::hasColumn('users', 'payment_provider_subscription_id')) {
                $table->string('payment_provider_subscription_id', 191)->nullable()->after('membership_cancellation_effective_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'payment_provider_subscription_id',
                'membership_cancellation_effective_at',
                'membership_cancellation_requested_at',
                'membership_auto_renew',
            ] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
