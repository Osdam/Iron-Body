<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot fiscal congelado del pago (Pricing V2).
 *
 * `payments.amount` sigue siendo el TOTAL BRUTO efectivamente cobrado (no cambia
 * su semántica). Estas columnas añaden el desglose con el que se cobró, para que
 * la factura no tenga que reconsultar el catálogo vivo.
 *
 * TODAS nullable: los pagos históricos quedan con NULL y conservan el
 * tratamiento legacy (ver InvoiceDtoBuilder::forPayment). NO hay backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('base_amount', 14, 2)->nullable()->after('amount');
            $table->decimal('tax_amount', 14, 2)->nullable()->after('base_amount');
            $table->decimal('gross_amount', 14, 2)->nullable()->after('tax_amount');
            $table->decimal('discount_amount', 14, 2)->nullable()->after('gross_amount');
            $table->unsignedBigInteger('tax_rate_id')->nullable()->after('discount_amount');
            $table->decimal('tax_rate', 6, 2)->nullable()->after('tax_rate_id');
            $table->string('pricing_mode', 32)->nullable()->after('tax_rate');
            $table->string('pricing_rules_version', 32)->nullable()->after('pricing_mode');
            $table->string('currency', 3)->nullable()->after('pricing_rules_version');
            $table->timestamp('priced_at')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'base_amount', 'tax_amount', 'gross_amount', 'discount_amount',
                'tax_rate_id', 'tax_rate', 'pricing_mode', 'pricing_rules_version',
                'currency', 'priced_at',
            ]);
        });
    }
};
