<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de moderación en `stories` — aditivo y reversible.
 *
 * Antes de esto, borrar una Story era FÍSICO e inmediato (fila + objeto en
 * Firebase). Eso destruye la evidencia de un caso abierto. A partir de aquí:
 *
 *   visible     → estado normal.
 *   quarantined → oculta del feed mientras se revisa. Reversible.
 *   removed     → retirada por moderación. Reversible por un admin.
 *
 * Además se añade `deleted_at` (soft delete): el borrado por el propio autor
 * deja de destruir la fila. El binario solo se borra cuando NO hay reporte
 * activo ni retención de evidencia pendiente (job `moderation:purge-evidence`).
 *
 * Las stories existentes quedan en 'visible' — comportamiento idéntico al
 * anterior para todo el contenido que no esté bajo moderación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            if (! Schema::hasColumn('stories', 'moderation_state')) {
                $table->string('moderation_state', 20)->default('visible');
            }
            if (! Schema::hasColumn('stories', 'moderated_at')) {
                $table->timestamp('moderated_at')->nullable();
            }
            if (! Schema::hasColumn('stories', 'moderation_reason_code')) {
                $table->string('moderation_reason_code', 48)->nullable();
            }
            if (! Schema::hasColumn('stories', 'reports_count')) {
                // Contador de reportantes ÚNICOS. Lo mantiene el backend; el
                // cliente nunca lo envía.
                $table->unsignedInteger('reports_count')->default(0);
            }
            if (! Schema::hasColumn('stories', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Índice del feed: filtrar por estado de moderación sin full scan.
        try {
            Schema::table('stories', function (Blueprint $table) {
                $table->index(['moderation_state', 'expires_at'], 'stories_moderation_idx');
            });
        } catch (Throwable $e) {
            // El índice ya existía — nada que hacer.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('stories', function (Blueprint $table) {
                $table->dropIndex('stories_moderation_idx');
            });
        } catch (Throwable $e) {
            // Índice ausente — continuar.
        }

        Schema::table('stories', function (Blueprint $table) {
            foreach (
                ['moderation_state', 'moderated_at', 'moderation_reason_code', 'reports_count', 'deleted_at'] as $column
            ) {
                if (Schema::hasColumn('stories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
