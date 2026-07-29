<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot fiscal de la suscripción recurrente (Pricing V2).
 *
 * `price_snapshot` se CONSERVA por compatibilidad (lo leen las 6 suscripciones
 * canceladas históricas y el código legacy). El cobro nuevo usa `gross_snapshot`.
 *
 * Congelar la tarifa y el modo — no solo el precio — es lo que impide que una
 * renovación futura cobre un importe distinto al autorizado si alguien cambia
 * el tratamiento tributario del plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_subscriptions', function (Blueprint $table) {
            $table->decimal('base_snapshot', 14, 2)->nullable()->after('price_snapshot');
            $table->decimal('tax_amount_snapshot', 14, 2)->nullable()->after('base_snapshot');
            $table->decimal('gross_snapshot', 14, 2)->nullable()->after('tax_amount_snapshot');
            $table->unsignedBigInteger('tax_rate_id_snapshot')->nullable()->after('gross_snapshot');
            $table->decimal('tax_rate_snapshot', 6, 2)->nullable()->after('tax_rate_id_snapshot');
            $table->string('pricing_mode_snapshot', 32)->nullable()->after('tax_rate_snapshot');
            $table->string('pricing_rules_version', 32)->nullable()->after('pricing_mode_snapshot');
            $table->timestamp('priced_at')->nullable()->after('pricing_rules_version');
        });
    }

    public function down(): void
    {
        Schema::table('membership_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'base_snapshot', 'tax_amount_snapshot', 'gross_snapshot',
                'tax_rate_id_snapshot', 'tax_rate_snapshot', 'pricing_mode_snapshot',
                'pricing_rules_version', 'priced_at',
            ]);
        });
    }
};
