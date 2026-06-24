<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos tributarios del producto para facturación electrónica de ventas POS.
 * Aditivo y nullable. Listo para Fase 2 (facturación de cafetería/productos).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('tax_rate_id')->nullable()->after('sale_price')
                ->constrained('tax_rates')->nullOnDelete();
            $table->boolean('price_includes_tax')->default(true)->after('tax_rate_id');
            $table->string('unspsc_code')->nullable()->after('price_includes_tax');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_rate_id');
            $table->dropColumn(['price_includes_tax', 'unspsc_code']);
        });
    }
};
