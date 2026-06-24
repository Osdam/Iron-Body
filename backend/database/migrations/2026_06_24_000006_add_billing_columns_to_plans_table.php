<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos tributarios del plan para facturación electrónica. Aditivo y nullable:
 * no rompe nada existente. `price_includes_tax` por defecto true (los precios
 * actuales son "precio final"); el builder deriva base + IVA a partir de ahí.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->foreignId('tax_rate_id')->nullable()->after('price')
                ->constrained('tax_rates')->nullOnDelete();
            $table->boolean('price_includes_tax')->default(true)->after('tax_rate_id');
            $table->string('unspsc_code')->nullable()->after('price_includes_tax');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_rate_id');
            $table->dropColumn(['price_includes_tax', 'unspsc_code']);
        });
    }
};
