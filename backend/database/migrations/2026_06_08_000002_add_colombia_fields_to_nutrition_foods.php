<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobertura Colombia: campos opcionales para priorizar productos vendidos en
 * Colombia (país, cadenas/stores, marca/tienda normalizadas y un score de
 * prioridad). Todo nullable → no rompe datos ni migraciones existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nutrition_foods', function (Blueprint $table) {
            $table->string('country', 60)->nullable()->after('category')->index();
            $table->string('stores', 255)->nullable()->after('country');
            $table->string('normalized_brand')->nullable()->after('stores')->index();
            $table->string('normalized_store')->nullable()->after('normalized_brand');
            $table->string('imported_region', 60)->nullable()->after('normalized_store');
            $table->unsignedSmallInteger('imported_priority_score')->nullable()
                ->after('imported_region')->index();
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_foods', function (Blueprint $table) {
            $table->dropColumn([
                'country', 'stores', 'normalized_brand',
                'normalized_store', 'imported_region', 'imported_priority_score',
            ]);
        });
    }
};
