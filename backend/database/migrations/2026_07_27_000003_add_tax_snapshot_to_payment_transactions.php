<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotización AUTORIZADA por la pasarela (Pricing V2).
 *
 * `payment_transactions.amount` es el bruto que se firma y se envía a Wompi.
 * Estas columnas congelan el desglose con el que se autorizó el cobro, de modo
 * que el webhook valide contra el importe congelado y la factura consuma el
 * mismo snapshot sin volver a mirar el plan.
 *
 * Nullable: transacciones legacy conservan NULL y su comportamiento anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->decimal('base_amount', 14, 2)->nullable()->after('amount');
            $table->decimal('tax_amount', 14, 2)->nullable()->after('base_amount');
            $table->decimal('gross_amount', 14, 2)->nullable()->after('tax_amount');
            $table->decimal('discount_amount', 14, 2)->nullable()->after('gross_amount');
            $table->unsignedBigInteger('tax_rate_id')->nullable()->after('discount_amount');
            $table->decimal('tax_rate', 6, 2)->nullable()->after('tax_rate_id');
            $table->string('pricing_mode', 32)->nullable()->after('tax_rate');
            $table->string('pricing_rules_version', 32)->nullable()->after('pricing_mode');
            $table->timestamp('priced_at')->nullable()->after('pricing_rules_version');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'base_amount', 'tax_amount', 'gross_amount', 'discount_amount',
                'tax_rate_id', 'tax_rate', 'pricing_mode', 'pricing_rules_version', 'priced_at',
            ]);
        });
    }
};
