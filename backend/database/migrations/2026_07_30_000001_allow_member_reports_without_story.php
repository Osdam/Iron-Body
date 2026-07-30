<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Habilita reportar a una PERSONA, no sólo a una publicación.
 *
 * Google Play exige, para apps con contenido generado por usuarios, un
 * mecanismo dentro de la app para denunciar tanto contenido como usuarios. El
 * sistema sólo permitía lo primero: `content_reports` nació atado a una Story y
 * `report_content_snapshots.original_story_id` es NOT NULL.
 *
 * Además había un hueco funcional real: reportar o bloquear sólo era alcanzable
 * desde el visor de estados, así que a alguien sin una Story activa no se le
 * podía denunciar por ningún camino.
 *
 * El cambio es ADITIVO y no reescribe datos:
 *  - `original_story_id` pasa a nullable — un reporte de perfil no tiene story.
 *  - Se añade un índice para la deduplicación de reportes de perfil.
 *
 * Los reportes existentes siguen siendo válidos: todos son de tipo `story` y
 * conservan su `original_story_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_content_snapshots')) {
            return;
        }

        // SQLite (la suite) no implementa `ALTER COLUMN ... TYPE/DROP NOT NULL`,
        // y además no aplica la restricción con el rigor de PostgreSQL. En
        // producción (pgsql) es un cambio de metadatos: sin reescritura de tabla.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE report_content_snapshots ALTER COLUMN original_story_id DROP NOT NULL'
            );
        }

        Schema::table('content_reports', function ($table) {
            if (! $this->hasIndex('content_reports_target_idx')) {
                // Dedup y consulta de "reportes sobre esta persona": el par
                // (tipo, contenido) ya está indexado, pero la bandeja del CRM
                // agrupa por miembro reportado y sin esto hace seq scan.
                $table->index(
                    ['reported_member_id', 'content_type', 'status'],
                    'content_reports_target_idx'
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('report_content_snapshots')) {
            return;
        }

        Schema::table('content_reports', function ($table) {
            if ($this->hasIndex('content_reports_target_idx')) {
                $table->dropIndex('content_reports_target_idx');
            }
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Volver a NOT NULL sólo si NINGÚN snapshot tiene la columna vacía:
        // hacerlo con reportes de perfil ya registrados destruiría la evidencia
        // de casos reales para poder revertir una migración.
        $huerfanos = DB::table('report_content_snapshots')
            ->whereNull('original_story_id')
            ->count();

        if ($huerfanos > 0) {
            throw new RuntimeException(sprintf(
                'No se revierte: %d snapshot(s) corresponden a reportes de perfil sin story '
                .'y volver a NOT NULL exigiría borrar evidencia de casos reales.',
                $huerfanos,
            ));
        }

        DB::statement(
            'ALTER TABLE report_content_snapshots ALTER COLUMN original_story_id SET NOT NULL'
        );
    }

    private function hasIndex(string $name): bool
    {
        return Schema::getConnection()
            ->getSchemaBuilder()
            ->getIndexes('content_reports') !== []
            && collect(Schema::getConnection()->getSchemaBuilder()->getIndexes('content_reports'))
                ->contains(fn (array $i) => ($i['name'] ?? null) === $name);
    }
};
