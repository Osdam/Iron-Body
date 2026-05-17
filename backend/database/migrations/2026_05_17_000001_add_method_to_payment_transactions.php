<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda el método de pago (card|pse|nequi|daviplata) en la transacción para
 * poder mostrarlo en el historial/comprobante sin depender de raw_response.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('method', 20)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
