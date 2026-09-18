<?php

namespace App\Services\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Escribe un dataset a fichero: Excel (.xlsx) o CSV.
 *
 * SIN dependencias nuevas. Un .xlsx es un zip con unas pocas piezas de XML, y
 * PHP trae ZipArchive; añadir PhpSpreadsheet sería otra librería que instalar
 * en el servidor, que ya tiene alguna pendiente. Si la extensión zip no está,
 * el formato simplemente no se ofrece (ver availableFormats()).
 *
 * Las filas se recorren con lazyById: nunca se cargan todas en memoria.
 */
class ExportWriter
{
    public const FORMAT_XLSX = 'xlsx';

    public const FORMAT_CSV = 'csv';

    /**
     * Formatos que este servidor puede producir de verdad.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public function availableFormats(): array
    {
        $formatos = [];

        if (class_exists(ZipArchive::class)) {
            $formatos[] = [
                'key' => self::FORMAT_XLSX,
                'label' => 'Excel (.xlsx)',
                'description' => 'Se abre directo en Excel con fechas y números listos para sumar y filtrar.',
            ];
        }

        $formatos[] = [
            'key' => self::FORMAT_CSV,
            'label' => 'CSV (.csv)',
            'description' => 'Texto separado por comas, para importar en otros sistemas o en Google Sheets.',
        ];

        return $formatos;
    }

    /** @return list<string> */
    public function formatKeys(): array
    {
        return array_column($this->availableFormats(), 'key');
    }

    /**
     * @param  Builder|iterable<mixed>  $source  consulta a recorrer, o filas ya calculadas
     * @param  list<ExportColumn>  $columns
     * @param  list<string>  $meta  líneas de cabecera (título, periodo, filtros) sobre la tabla
     */
    public function download(Builder|iterable $source, array $columns, string $format, string $filename, array $meta = []): StreamedResponse|BinaryFileResponse
    {
        return match ($format) {
            self::FORMAT_XLSX => $this->xlsx($source, $columns, $filename.'.xlsx', $meta),
            self::FORMAT_CSV => $this->csv($source, $columns, $filename.'.csv', $meta),
            default => throw new RuntimeException("Formato de exportación no soportado: {$format}"),
        };
    }

    /**
     * Las filas a escribir, venga el dataset de una consulta o ya resuelto.
     *
     * `lazyById` nunca carga la tabla entera en memoria; los informes, en
     * cambio, entregan un puñado de filas ya agregadas y se recorren tal cual.
     *
     * @param  Builder|iterable<mixed>  $source
     */
    private function rows(Builder|iterable $source): iterable
    {
        return $source instanceof Builder ? $source->lazyById(500) : $source;
    }

    // ── CSV ──────────────────────────────────────────────────────────────────

    /** @param  list<ExportColumn>  $columns */
    private function csv(Builder|iterable $source, array $columns, string $filename, array $meta = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($source, $columns, $meta) {
            $out = fopen('php://output', 'wb');

            // BOM: sin él Excel abre el UTF-8 como Latin-1 y «Pérez» sale «PÃ©rez».
            fwrite($out, "\xEF\xBB\xBF");

            // Cabecera del informe —qué es, de qué periodo y cuándo se generó—
            // separada de la tabla por una línea en blanco: un fichero de datos
            // sin contexto acaba discutido en una reunión sin poder decir de
            // qué fechas era.
            foreach ($meta as $linea) {
                fputcsv($out, [$linea], ',', '"', '');
            }
            if ($meta !== []) {
                fputcsv($out, [''], ',', '"', '');
            }

            fputcsv($out, array_map(fn (ExportColumn $c) => $c->label, $columns), ',', '"', '');

            foreach ($this->rows($source) as $fila) {
                $valores = [];
                foreach ($columns as $col) {
                    $valores[] = $this->csvCell($col, $col->resolve($fila));
                }
                fputcsv($out, $valores, ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvCell(ExportColumn $col, mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }
        if ($col->type === ExportColumn::TYPE_NUMBER && is_numeric($valor)) {
            // Sin separador de miles: un «60.000» en CSV lo lee como 60 un
            // programa en inglés y como texto otro en español.
            return (string) (floor((float) $valor) == (float) $valor ? (int) $valor : $valor);
        }

        return $this->neutralizeFormula((string) $valor);
    }

    /**
     * Inyección de fórmulas: un nombre escrito como «=HYPERLINK(...)» se
     * ejecutaría al abrir el CSV en Excel. Los datos salen de formularios que
     * rellenan socios y empleados, así que no se puede dar por limpio. Se
     * antepone un apóstrofo, que Excel muestra como texto literal.
     */
    private function neutralizeFormula(string $texto): string
    {
        return $texto !== '' && in_array($texto[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$texto
            : $texto;
    }

    // ── XLSX ─────────────────────────────────────────────────────────────────

    /** @param  list<ExportColumn>  $columns */
    private function xlsx(Builder|iterable $source, array $columns, string $filename, array $meta = []): BinaryFileResponse
    {
        $hoja = tempnam(sys_get_temp_dir(), 'ib-sheet-');
        $zip = tempnam(sys_get_temp_dir(), 'ib-xlsx-');

        try {
            $filas = $this->writeSheet($hoja, $source, $columns, $meta);
            $this->packXlsx($zip, $hoja, $columns, $filas);
        } finally {
            @unlink($hoja);
        }

        return response()
            ->download($zip, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * Escribe sheet1.xml fila a fila en disco. Devuelve cuántas filas de datos.
     *
     * @param  list<ExportColumn>  $columns
     */
    private function writeSheet(string $ruta, Builder|iterable $source, array $columns, array $meta = []): int
    {
        $f = fopen($ruta, 'wb');
        $ultimaCol = $this->columnLetter(count($columns));
        // La tabla empieza debajo de la cabecera del informe, si la hay.
        $filaEncabezado = count($meta) + ($meta === [] ? 0 : 1) + 1;

        fwrite($f, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            // Encabezado congelado: al bajar por mil filas sigue viéndose qué es cada columna.
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$filaEncabezado.'" topLeftCell="A'.($filaEncabezado + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>');
        foreach ($columns as $i => $col) {
            $ancho = max(12, min(45, mb_strlen($col->label) + 4));
            $n = $i + 1;
            fwrite($f, "<col min=\"{$n}\" max=\"{$n}\" width=\"{$ancho}\" customWidth=\"1\"/>");
        }
        fwrite($f, '</cols><sheetData>');

        // Cabecera del informe: título, periodo y filtros aplicados.
        $r = 0;
        foreach ($meta as $linea) {
            $r++;
            fwrite($f, "<row r=\"{$r}\">".$this->textCell('A'.$r, $linea, 1).'</row>');
        }
        if ($meta !== []) {
            $r++; // línea en blanco entre la cabecera y la tabla
        }

        // Encabezado de columnas en negrita (estilo 1).
        $r++;
        fwrite($f, "<row r=\"{$r}\">");
        foreach ($columns as $i => $col) {
            fwrite($f, $this->textCell($this->columnLetter($i + 1).$r, $col->label, 1));
        }
        fwrite($f, '</row>');

        $inicioTabla = $r;
        foreach ($this->rows($source) as $fila) {
            $r++;
            fwrite($f, "<row r=\"{$r}\">");
            foreach ($columns as $i => $col) {
                fwrite($f, $this->cell($this->columnLetter($i + 1).$r, $col, $col->resolve($fila)));
            }
            fwrite($f, '</row>');
        }

        fwrite($f, '</sheetData>');
        if ($r > $inicioTabla) {
            fwrite($f, "<autoFilter ref=\"A{$inicioTabla}:{$ultimaCol}{$r}\"/>");
        }
        fwrite($f, '</worksheet>');
        fclose($f);

        return $r - $inicioTabla;
    }

    private function cell(string $ref, ExportColumn $col, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        if (is_bool($valor)) {
            return $this->textCell($ref, $valor ? 'Sí' : 'No');
        }
        if ($col->type === ExportColumn::TYPE_NUMBER && is_numeric($valor)) {
            return "<c r=\"{$ref}\" s=\"2\"><v>".(0 + $valor).'</v></c>';
        }
        if ($col->type === ExportColumn::TYPE_DATE && $serial = $this->excelDate((string) $valor)) {
            // Fecha real de Excel (número de serie + formato), no texto: así se
            // puede ordenar y filtrar por fecha.
            return "<c r=\"{$ref}\" s=\"3\"><v>{$serial}</v></c>";
        }

        return $this->textCell($ref, (string) $valor);
    }

    private function textCell(string $ref, string $texto, int $estilo = 0): string
    {
        $s = $estilo ? " s=\"{$estilo}\"" : '';

        // inlineStr nunca se interpreta como fórmula: aquí no hace falta el
        // apóstrofo del CSV.
        return "<c r=\"{$ref}\" t=\"inlineStr\"{$s}><is><t xml:space=\"preserve\">"
            .$this->xml($texto).'</t></is></c>';
    }

    /** Días desde 1899-12-30, el origen de fechas de Excel. */
    private function excelDate(string $valor): ?int
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $valor)) {
            return null;
        }

        $fecha = CarbonImmutable::createFromFormat('Y-m-d', substr($valor, 0, 10), 'UTC');
        if (! $fecha) {
            return null;
        }

        return (int) CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->diffInDays($fecha->startOfDay());
    }

    private function xml(string $texto): string
    {
        // Caracteres de control que XML 1.0 no admite: un salto raro pegado en
        // una dirección rompería el fichero entero al abrirlo.
        $limpio = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $texto) ?? '';

        return htmlspecialchars($limpio, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function columnLetter(int $n): string
    {
        $letras = '';
        while ($n > 0) {
            $resto = ($n - 1) % 26;
            $letras = chr(65 + $resto).$letras;
            $n = intdiv($n - 1, 26);
        }

        return $letras;
    }

    /** @param  list<ExportColumn>  $columns */
    private function packXlsx(string $destino, string $hoja, array $columns, int $filas): void
    {
        $zip = new ZipArchive;
        if ($zip->open($destino, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el fichero Excel.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Exportación" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');

        // Estilos: 0 normal · 1 encabezado en negrita · 2 número con miles · 3 fecha.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'</styleSheet>');

        $zip->addFile($hoja, 'xl/worksheets/sheet1.xml');

        if (! $zip->close()) {
            throw new RuntimeException('No se pudo cerrar el fichero Excel.');
        }
    }
}
