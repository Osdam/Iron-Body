<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos fiscales de la tarifa: si el precio YA incluye el impuesto
 * (IVA incluido vs no incluido) y una descripción legible. Aditivo y nullable.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->boolean('price_includes_tax')->nullable()->after('factus_tribute_id');
            $table->string('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropColumn(['price_includes_tax', 'description']);
        });
    }
};
