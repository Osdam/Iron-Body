<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hace revocable el `access_hash` permanente (BACK-006). Aditivo y nullable: una
 * marca de tiempo que, cuando está presente, invalida el `access_hash` como
 * bearer sin borrar el valor. No altera columnas existentes ni rompe el flujo
 * normal (miembros sin la marca siguen igual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->timestamp('access_hash_revoked_at')->nullable()->after('access_hash');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('access_hash_revoked_at');
        });
    }
};
