<?php

namespace App\Console\Commands;

use App\Services\Valuations\ValuationImporter;
use App\Support\Caja\UntrustedText;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Importa citas de valoración desde un CSV (transición desde Google Calendar).
 *
 *   php artisan valuations:import-csv /ruta/citas.csv            (SIMULA: no escribe)
 *   php artisan valuations:import-csv /ruta/citas.csv --apply    (escribe las válidas, en una transacción)
 *   … --include-past   (también las pasadas, sin ningún aviso; por defecto se rechazan)
 *
 * Formato y reglas: {@see ValuationImporter}. El informe dice, fila a fila,
 * qué es válido y qué no (socio no encontrado, entrenador no encontrado o
 * ambiguo, en el pasado, conflicto, duplicado, fila inválida). Del documento
 * solo se muestran los 4 últimos dígitos.
 *
 * La fecha y la hora se pintan tal como venían en el fichero, así que cada
 * celda pasa por {@see self::celda()}: sin caracteres de control ni invisibles
 * (un ESC movería la terminal de quien lee el informe) y sin etiquetas de
 * formato de la consola (`<error>` se ve como texto).
 */
class ImportValuationsCsv extends Command
{
    protected $signature = 'valuations:import-csv {path : Ruta del CSV} {--apply : Escribir las filas válidas (por defecto solo simula)} {--include-past : Importar también las citas cuyo inicio ya pasó, sin ningún aviso}';

    protected $description = 'Importa citas de valoración desde un CSV (simulación por defecto)';

    /** Lo más que se pinta de un campo del fichero. */
    private const CELL_MAX = 80;

    private const LABELS = [
        'valid' => 'Filas válidas',
        'member_not_found' => 'Socio no encontrado',
        'trainer_not_found' => 'Entrenador no encontrado',
        'trainer_ambiguous' => 'Entrenador ambiguo',
        'in_past' => 'En el pasado',
        'conflict' => 'Conflicto (disponibilidad, bloqueo, clase u otra cita)',
        'duplicate' => 'Duplicado',
        'invalid' => 'Fila inválida',
    ];

    public function handle(ValuationImporter $importer): int
    {
        $aplicar = (bool) $this->option('apply');

        try {
            $r = $importer->run((string) $this->argument('path'), $aplicar, (bool) $this->option('include-past'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($aplicar ? 'MODO: APLICAR (escribe en la base)' : 'MODO: SIMULACIÓN (no se escribe nada; usa --apply para importar)');
        if ($this->option('include-past')) {
            $this->line('Citas pasadas: se importan, sin ningún aviso (--include-past).');
        }
        $this->table(['Resultado', 'Filas'], collect(self::LABELS)->map(fn ($l, $k) => [$l, $r['counts'][$k] ?? 0])->values()->all());

        $problemas = array_values(array_filter($r['rows'], fn ($f) => $f['category'] !== 'valid'));
        if ($problemas !== []) {
            $this->line('Detalle de las filas que no se importan:');
            $this->table(
                ['Línea', 'Resultado', 'Motivo', 'Socio (…)', 'Entrenador (…)', 'Fecha', 'Hora'],
                array_map(fn ($f) => array_map(self::celda(...), [
                    $f['line'], self::LABELS[$f['category']] ?? $f['category'], $f['reason'],
                    $f['member_document_tail'] ? '…'.$f['member_document_tail'] : '', $f['trainer_document_tail'] ? '…'.$f['trainer_document_tail'] : '',
                    $f['date'], $f['time'],
                ]), $problemas),
            );
        }

        $this->info($aplicar
            ? "Importadas: {$r['created']} citas."
            : "Se importarían {$r['counts']['valid']} citas.");

        return self::SUCCESS;
    }

    /** Una celda del informe: texto plano, recortado y escapado para la consola. */
    private static function celda(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }

        return OutputFormatter::escape(UntrustedText::sanitize((string) $valor, self::CELL_MAX) ?? '');
    }
}
