<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('members')) {
            return;
        }
        Schema::table('members', function (Blueprint $table) {
            if (! Schema::hasColumn('members', 'anonymized_at')) {
                // Marca de cuenta eliminada/anonimizada (RGPD-like). Bloquea el
                // login y deja constancia de cuándo se procesó el borrado.
                $table->timestamp('anonymized_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('members') || ! Schema::hasColumn('members', 'anonymized_at')) {
            return;
        }
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
