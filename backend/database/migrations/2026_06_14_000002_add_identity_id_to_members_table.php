<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza cada miembro con su identidad (aditivo, nullable). Se usa
 * `unsignedBigInteger` + índice sin FK a nivel de motor — misma convención que
 * `member_trainer_assignments` — para no romper SQLite en los ALTER de la suite
 * de tests; la integridad referencial se garantiza en la capa de aplicación.
 * El backfill posterior rellena los valores. NO altera columnas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->unsignedBigInteger('identity_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropIndex(['identity_id']);
            $table->dropColumn('identity_id');
        });
    }
};
