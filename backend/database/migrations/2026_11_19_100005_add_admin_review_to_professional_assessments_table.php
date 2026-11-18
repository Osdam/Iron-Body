<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Nueva» / «Revisada» de la bandeja de valoraciones del CRM
 * (docs/agenda-valoraciones/CONTRATO_BANDEJA_SEGUIMIENTO.md). Persiste en la
 * base, no en la pestaña: `admin_reviewed_at` y quién lo marcó.
 *
 * Aditiva: el código viejo no lee las columnas. Las valoraciones ya enviadas
 * antes de desplegar se marcan revisadas (con su propia fecha de envío) para no
 * mostrar un centenar de «nuevas» falsas; las que lleguen después nacen nuevas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET lock_timeout = '5s'");
        }

        Schema::table('professional_assessments', function (Blueprint $table): void {
            $table->timestamp('admin_reviewed_at')->nullable();
            $table->foreignId('admin_reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
        });

        DB::table('professional_assessments')
            ->whereNotNull('submitted_at')
            ->whereNull('admin_reviewed_at')
            ->update(['admin_reviewed_at' => DB::raw('submitted_at')]);
    }

    public function down(): void
    {
        Schema::table('professional_assessments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('admin_reviewed_by_admin_id');
            $table->dropColumn('admin_reviewed_at');
        });
    }
};
