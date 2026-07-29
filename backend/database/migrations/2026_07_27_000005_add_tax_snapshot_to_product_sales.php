<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot fiscal de la venta de caja y de cada una de sus líneas (Pricing V2).
 *
 * `product_sales.subtotal` y `.total` conservan su semántica histórica (importes
 * brutos de mostrador) para no romper los reportes y recibos existentes. El
 * desglose fiscal vive en las columnas nuevas.
 *
 * Las líneas guardan snapshot COMPLETO (base, tarifa, impuesto, bruto) porque el
 * comprobante se arma por línea: sin esto, editar la tarifa de un producto
 * cambiaría la factura de una venta ya cobrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_sales', function (Blueprint $table) {
            $table->decimal('base_amount', 14, 2)->nullable()->after('total');
            $table->decimal('tax_amount', 14, 2)->nullable()->after('base_amount');
            $table->decimal('gross_amount', 14, 2)->nullable()->after('tax_amount');
            $table->string('pricing_mode', 32)->nullable()->after('gross_amount');
            $table->string('pricing_rules_version', 32)->nullable()->after('pricing_mode');
            $table->timestamp('priced_at')->nullable()->after('pricing_rules_version');
        });

        Schema::table('product_sale_items', function (Blueprint $table) {
            $table->decimal('base_unit_amount', 14, 2)->nullable()->after('subtotal');
            $table->decimal('tax_unit_amount', 14, 2)->nullable()->after('base_unit_amount');
            $table->decimal('gross_unit_amount', 14, 2)->nullable()->after('tax_unit_amount');
            $table->decimal('base_amount', 14, 2)->nullable()->after('gross_unit_amount');
            $table->decimal('tax_amount', 14, 2)->nullable()->after('base_amount');
            $table->decimal('gross_amount', 14, 2)->nullable()->after('tax_amount');
            $table->unsignedBigInteger('tax_rate_id')->nullable()->after('gross_amount');
            $table->decimal('tax_rate', 6, 2)->nullable()->after('tax_rate_id');
            $table->string('pricing_mode', 32)->nullable()->after('tax_rate');
            $table->string('pricing_rules_version', 32)->nullable()->after('pricing_mode');
        });
    }

    public function down(): void
    {
        Schema::table('product_sales', function (Blueprint $table) {
            $table->dropColumn([
                'base_amount', 'tax_amount', 'gross_amount',
                'pricing_mode', 'pricing_rules_version', 'priced_at',
            ]);
        });

        Schema::table('product_sale_items', function (Blueprint $table) {
            $table->dropColumn([
                'base_unit_amount', 'tax_unit_amount', 'gross_unit_amount',
                'base_amount', 'tax_amount', 'gross_amount',
                'tax_rate_id', 'tax_rate', 'pricing_mode', 'pricing_rules_version',
            ]);
        });
    }
};
