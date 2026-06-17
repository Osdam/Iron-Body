<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clases FIJAS/recurrentes: cada cuánto se "renueva" el ciclo de reservas.
 *
 *  - classes.renewal_hours: horas tras finalizar la clase para reabrir las
 *    reservas (8/12/24/48/168 = semanal). NULL o 0 = no renovar automáticamente.
 *  - class_sessions.renewed_at: marca que esa sesión finalizada ya cumplió su
 *    ciclo y fue "archivada" (deja de ser la sesión vigente del miembro, pero
 *    se CONSERVA para la supervisión/historial). No se borra evidencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('classes')) {
            Schema::table('classes', function (Blueprint $table): void {
                if (! Schema::hasColumn('classes', 'renewal_hours')) {
                    $table->unsignedSmallInteger('renewal_hours')->nullable()->default(24)->after('is_recurring');
                }
            });
        }

        if (Schema::hasTable('class_sessions')) {
            Schema::table('class_sessions', function (Blueprint $table): void {
                if (! Schema::hasColumn('class_sessions', 'renewed_at')) {
                    $table->timestamp('renewed_at')->nullable()->after('ended_at');
                    $table->index('renewed_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('classes') && Schema::hasColumn('classes', 'renewal_hours')) {
            Schema::table('classes', fn (Blueprint $table) => $table->dropColumn('renewal_hours'));
        }
        if (Schema::hasTable('class_sessions') && Schema::hasColumn('class_sessions', 'renewed_at')) {
            Schema::table('class_sessions', function (Blueprint $table): void {
                $table->dropIndex(['renewed_at']);
                $table->dropColumn('renewed_at');
            });
        }
    }
};
