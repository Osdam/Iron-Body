<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'tier')) {
                // Categoría/segmento comercial del plan: lite | pro | premium.
                // Es solo una etiqueta de agrupación; las features siguen siendo
                // independientes por plan (ver Plan::defaultFeatures()).
                $table->string('tier', 20)->default('lite')->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'tier')) {
                $table->dropColumn('tier');
            }
        });
    }
};
