<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Añade `provider` (motor OCR usado) y `barcode` (contexto del escaneo) a los
 * escaneos OCR. El status ahora también admite 'confirmed' (string libre, sin
 * cambio de columna).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nutrition_ocr_scans', function (Blueprint $table) {
            $table->string('provider', 40)->nullable()->after('member_id');
            $table->string('barcode', 32)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_ocr_scans', function (Blueprint $table) {
            $table->dropColumn(['provider', 'barcode']);
        });
    }
};
