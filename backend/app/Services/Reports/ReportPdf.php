<?php

namespace App\Services\Reports;

use App\Services\Exports\ExportColumn;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/**
 * El informe en papel.
 *
 * Usa TCPDF, que ya estaba en el proyecto para los contratos de socios y los
 * cierres de caja; no se añade ninguna dependencia. Si la librería no estuviera
 * instalada, el formato simplemente no se ofrece —igual que el .xlsx cuando
 * falta la extensión zip— en vez de romper la descarga con un error de clase no
 * encontrada.
 *
 * El PDF es para ENSEÑAR, no para procesar: lleva el título, el periodo, los
 * filtros y la fecha de generación en la cabecera, y la tabla recortada a lo
 * que cabe con sentido. Quien necesite todas las filas se lleva el Excel.
 */
final class ReportPdf
{
    /** Filas que caben en un documento legible. Más que esto es una hoja de cálculo. */
    private const MAX_ROWS = 400;

    public function available(): bool
    {
        return class_exists(TCPDF::class);
    }

    /**
     * @param  list<string>  $meta
     * @param  list<ExportColumn>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function render(string $title, array $meta, array $columns, iterable $rows, string $filename): StreamedResponse
    {
        if (! $this->available()) {
            abort(422, 'Este servidor no puede generar PDF. Descarga el informe en Excel o CSV.');
        }

        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('IRON BODY CRM');
        $pdf->SetAuthor('IRON BODY');
        $pdf->SetTitle('Iron Body · '.$title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 12, 10);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->AddPage();

        $this->header($pdf, $title, $meta);
        $total = $this->table($pdf, $columns, $rows);
        $this->footer($pdf, $total);

        $contenido = $pdf->Output($filename, 'S');

        return response()->streamDownload(
            fn () => print $contenido,
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** @param list<string> $meta */
    private function header(TCPDF $pdf, string $title, array $meta): void
    {
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 8, 'IRON BODY', 0, 1, 'L');

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 7, $title, 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 9);
        foreach (array_slice($meta, 1) as $linea) {
            $pdf->Cell(0, 5, $linea, 0, 1, 'L');
        }

        $pdf->Ln(2);
        $pdf->SetDrawColor(210, 210, 210);
        $pdf->Cell(0, 0, '', 'T', 1);
        $pdf->Ln(3);
    }

    /**
     * @param  list<ExportColumn>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function table(TCPDF $pdf, array $columns, iterable $rows): int
    {
        $ancho = ($pdf->getPageWidth() - 20) / max(1, count($columns));

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(240, 240, 240);
        foreach ($columns as $col) {
            $pdf->Cell($ancho, 7, $this->clip($col->label, $ancho), 1, 0, 'L', true);
        }
        $pdf->Ln();

        $pdf->SetFont('helvetica', '', 8);
        $n = 0;
        foreach ($rows as $fila) {
            if ($n >= self::MAX_ROWS) {
                break;
            }
            $n++;
            foreach ($columns as $col) {
                $valor = $col->resolve($fila);
                $texto = match (true) {
                    $valor === null || $valor === '' => '—',
                    $col->type === ExportColumn::TYPE_NUMBER && is_numeric($valor) => number_format((float) $valor, 0, ',', '.'),
                    $col->type === ExportColumn::TYPE_DATE => substr((string) $valor, 0, 10),
                    default => (string) $valor,
                };
                $pdf->Cell($ancho, 6, $this->clip($texto, $ancho), 'LR', 0, $col->type === ExportColumn::TYPE_NUMBER ? 'R' : 'L');
            }
            $pdf->Ln();
        }

        if ($n === 0) {
            $pdf->Cell(0, 8, 'Sin datos para este periodo y estos filtros.', 1, 1, 'C');
        } else {
            $pdf->Cell(0, 0, '', 'T', 1);
        }

        return $n;
    }

    private function footer(TCPDF $pdf, int $filas): void
    {
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 5, $filas >= self::MAX_ROWS
            ? 'Se muestran las primeras '.self::MAX_ROWS.' filas. Para el detalle completo, descarga el informe en Excel.'
            : $filas.' fila(s).', 0, 1, 'L');
    }

    /** Recorta un texto a lo que cabe en la celda, con puntos suspensivos. */
    private function clip(string $texto, float $ancho): string
    {
        $maximo = max(6, (int) floor($ancho / 1.6));

        return mb_strlen($texto) > $maximo ? mb_substr($texto, 0, $maximo - 1).'…' : $texto;
    }
}
