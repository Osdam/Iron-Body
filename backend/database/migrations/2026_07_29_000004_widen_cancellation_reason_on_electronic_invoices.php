<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `cancellation_reason` era varchar(60), dimensionada para códigos cortos como
 * `sandbox_test`. Al cancelar la solicitud #19 con el motivo real —«La venta
 * V-000004 no fue creada con solicitud de factura electrónica», 68 caracteres—
 * PostgreSQL abortó con «value too long for type character varying(60)».
 *
 * El motivo de cancelación de un registro fiscal tiene que poder explicarse en
 * una frase que se entienda sin contexto: quien audite el documento años después
 * no va a tener a nadie a quien preguntar qué significaba una etiqueta de 60
 * caracteres. Se iguala a `cancelled_by` (255).
 *
 * Cambio de metadatos: en PostgreSQL ampliar un varchar no reescribe la tabla ni
 * la bloquea de forma apreciable.
 */
return new class extends Migration
{
    private const TABLA = 'electronic_invoices';

    private const COLUMNA = 'cancellation_reason';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLA, self::COLUMNA)) {
            return;
        }

        $this->cambiarAncho(255);
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLA, self::COLUMNA)) {
            return;
        }

        // Volver a 60 sólo si NINGÚN motivo ya guardado excede ese ancho:
        // truncar el motivo de una cancelación fiscal para poder revertir una
        // migración sería destruir la explicación de un documento real.
        $demasiadoLargos = DB::table(self::TABLA)
            ->whereNotNull(self::COLUMNA)
            ->whereRaw('LENGTH('.self::COLUMNA.') > 60')
            ->count();

        if ($demasiadoLargos > 0) {
            throw new RuntimeException(sprintf(
                'No se revierte: %d motivo(s) de cancelación superan los 60 caracteres y '
                .'estrecharlos truncaría la explicación de documentos fiscales reales.',
                $demasiadoLargos,
            ));
        }

        $this->cambiarAncho(60);
    }

    /**
     * SQLite —el motor de la suite— no implementa `ALTER COLUMN ... TYPE` y
     * además NO aplica la longitud de un VARCHAR, así que allí no hay nada que
     * cambiar y la migración es un no-op. En PostgreSQL (producción) se usa SQL
     * directo: ampliar un varchar es un cambio de metadatos, sin reescritura de
     * tabla ni bloqueo apreciable.
     */
    private function cambiarAncho(int $ancho): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s TYPE VARCHAR(%d)',
            self::TABLA,
            self::COLUMNA,
            $ancho,
        ));
    }
};
