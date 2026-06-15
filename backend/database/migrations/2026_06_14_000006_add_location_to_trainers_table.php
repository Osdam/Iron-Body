<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sede del entrenador (aditivo, nullable). El sistema no tiene catálogo de
 * sedes; se usa una etiqueta de texto coherente con `classes.location`. La sede
 * participa en la autorización (acceso por sede) en fases posteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainers', function (Blueprint $table): void {
            $table->string('location')->nullable()->after('contract_type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('trainers', function (Blueprint $table): void {
            $table->dropIndex(['location']);
            $table->dropColumn('location');
        });
    }
};
