<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza cada entrenador con su identidad (aditivo, nullable). Mismo criterio
 * que en `members`: columna indexada sin FK de motor (compatibilidad SQLite en
 * tests), integridad en la capa de aplicación. El backfill rellena los valores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainers', function (Blueprint $table): void {
            $table->unsignedBigInteger('identity_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('trainers', function (Blueprint $table): void {
            $table->dropIndex(['identity_id']);
            $table->dropColumn('identity_id');
        });
    }
};
