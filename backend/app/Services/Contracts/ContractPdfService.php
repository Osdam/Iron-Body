<?php

namespace App\Services\Contracts;

use Illuminate\Support\Facades\Config;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Genera el PDF FIRMADO usando el documento oficial como FONDO (FPDI) y
 * estampando, por coordenadas controladas (config/contracts.php), únicamente
 * los datos del usuario y la firma. NO redibuja, NO cambia textos legales, NO
 * mueve logos ni el diseño: respeta el documento original y su número de
 * páginas. Anexa al final una "Constancia de firma electrónica y auditoría"
 * SIN alterar las páginas originales.
 */
class ContractPdfService
{
    public function __construct(private ContractTemplateService $templates)
    {
    }

    /**
     * @param  string       $templateKey
     * @param  array        $fields     [field_key => string]
     * @param  array        $multiline  [field_key => string]
     * @param  string|null  $signaturePngPath  ruta ABSOLUTA del PNG de la firma
     * @param  array        $audit      metadatos para la página de constancia
     * @return string  contenido binario del PDF final
     */
    public function generate(
        string $templateKey,
        array $fields,
        array $multiline,
        ?string $signaturePngPath,
        array $audit
    ): string {
        $def = $this->templates->definition($templateKey);
        $source = $this->templates->sourceAbsolutePath($templateKey);

        $pdf = new Fpdi('P', 'pt', 'LETTER', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->setFontSubsetting(true);

        $pageCount = $pdf->setSourceFile($source);

        // 1) Importar TODAS las páginas oficiales tal cual y estampar encima.
        for ($p = 1; $p <= $pageCount; $p++) {
            $tplId = $pdf->importPage($p);
            $size = $pdf->getTemplateSize($tplId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height'], true);

            $pdf->SetTextColor(15, 23, 42); // gris muy oscuro, legible sobre blanco

            // Campos de una línea de esta página.
            foreach (($def['fields'] ?? []) as $key => $f) {
                if (($f['page'] ?? 1) !== $p) {
                    continue;
                }
                $value = trim((string) ($fields[$key] ?? ''));
                if ($value === '') {
                    continue;
                }
                $this->stampText($pdf, (float) $f['x'], (float) $f['y'], (float) $f['w'], $value, (int) ($f['size'] ?? 9));
            }

            // Campos multilínea (observaciones médicas, lesiones).
            foreach (($def['multiline'] ?? []) as $key => $ml) {
                if (($ml['page'] ?? 1) !== $p) {
                    continue;
                }
                $value = trim((string) ($multiline[$key] ?? ''));
                if ($value === '') {
                    continue;
                }
                $this->stampMultiline($pdf, $value, $ml);
            }

            // Firma (imagen PNG) en la página correspondiente.
            $sig = $def['signature'] ?? null;
            if ($sig && ($sig['page'] ?? 1) === $p && $signaturePngPath && is_file($signaturePngPath)) {
                $pdf->Image(
                    $signaturePngPath,
                    (float) $sig['x'], (float) $sig['y'],
                    (float) $sig['w'], (float) $sig['h'],
                    'PNG', '', 'T', false, 300, '', false, false, 0, 'CT'
                );
            }
        }

        // 2) Página de constancia/auditoría (NO altera las páginas originales).
        $this->appendAuditPage($pdf, $def, $audit);

        return $pdf->Output('contract.pdf', 'S');
    }

    /** Estampa un texto de una línea ajustado al ancho del campo (auto-shrink). */
    private function stampText(Fpdi $pdf, float $x, float $y, float $w, string $text, int $size): void
    {
        $size = $this->fitFontSize($pdf, $text, $w, $size);
        $pdf->SetFont('helvetica', '', $size);
        // El cell se ubica con su tope en (y-1); la línea base queda sobre el
        // subrayado del documento oficial.
        $pdf->SetXY($x, $y - 1);
        $pdf->Cell($w, $size + 2, $text, 0, 0, 'L', false, '', 0, false, 'T', 'T');
    }

    /** Reduce el tamaño de fuente hasta que el texto quepa en el ancho dado. */
    private function fitFontSize(Fpdi $pdf, string $text, float $w, int $size): int
    {
        $min = 6;
        for ($s = $size; $s >= $min; $s--) {
            $pdf->SetFont('helvetica', '', $s);
            if ($pdf->GetStringWidth($text) <= ($w - 2)) {
                return $s;
            }
        }

        return $min;
    }

    /** Reparte el texto en las líneas (slots) declaradas; trunca con … si excede. */
    private function stampMultiline(Fpdi $pdf, string $text, array $ml): void
    {
        $size = (int) ($ml['size'] ?? 9);
        $pdf->SetFont('helvetica', '', $size);
        $lines = $ml['lines'] ?? [];
        if (empty($lines)) {
            return;
        }

        $words = preg_split('/\s+/', $text) ?: [];
        $slotIndex = 0;
        $current = '';

        $flush = function (string $line, int $idx) use ($pdf, $lines, $size): void {
            $slot = $lines[$idx];
            $pdf->SetXY((float) $slot['x'], (float) $slot['y'] - 1);
            $pdf->Cell((float) $slot['w'], $size + 2, $line, 0, 0, 'L', false, '', 0, false, 'T', 'T');
        };

        foreach ($words as $word) {
            $slot = $lines[$slotIndex];
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($pdf->GetStringWidth($candidate) <= ((float) $slot['w'] - 2)) {
                $current = $candidate;
                continue;
            }
            // La línea actual se llenó: escribirla y pasar a la siguiente.
            $flush($current, $slotIndex);
            $current = $word;
            $slotIndex++;
            if ($slotIndex >= count($lines)) {
                // Sin más espacio: marcar truncamiento en la última línea.
                $slot = $lines[count($lines) - 1];
                while ($current !== '' && $pdf->GetStringWidth($current.' …') > ((float) $slot['w'] - 2)) {
                    $current = preg_replace('/\s*\S+$/', '', $current) ?? '';
                }
                $flush(trim($current).' …', count($lines) - 1);
                return;
            }
        }

        if ($current !== '' && $slotIndex < count($lines)) {
            $flush($current, $slotIndex);
        }
    }

    /** Página final de constancia de firma electrónica y auditoría. */
    private function appendAuditPage(Fpdi $pdf, array $def, array $audit): void
    {
        $pdf->AddPage('P', 'LETTER');
        $pdf->SetTextColor(15, 23, 42);

        $pdf->SetXY(40, 48);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 18, 'Constancia de firma electrónica y auditoría', 0, 1, 'L');

        $pdf->SetX(40);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->MultiCell(515, 12,
            'Documento firmado electrónicamente desde la App Iron Body. Esta página es un anexo de '.
            'trazabilidad y no modifica el documento oficial de las páginas anteriores.',
            0, 'L');
        $pdf->Ln(6);
        $pdf->SetTextColor(15, 23, 42);

        $rows = [
            ['Documento',            (string) ($audit['template_name'] ?? '')],
            ['Tipo de contrato',     (string) ($audit['contract_type'] ?? '')],
            ['Versión de plantilla', (string) ($audit['template_version'] ?? '')],
            ['Folio / ID interno',   (string) ($audit['folio'] ?? '').'  ('.(string) ($audit['contract_uuid'] ?? '').')'],
            ['Titular',              (string) ($audit['member_name'] ?? '')],
            ['Documento del titular', (string) ($audit['member_document'] ?? '')],
            ['Fecha/hora de firma',  (string) ($audit['signed_at'] ?? '')],
            ['IP registrada',        (string) ($audit['ip_address'] ?? '—')],
            ['Dispositivo',          (string) ($audit['device_id'] ?? '—')],
            ['Plataforma / versión', trim((string) ($audit['app_platform'] ?? '—').' '.(string) ($audit['app_version'] ?? ''))],
            ['Contacto de soporte',  (string) ($audit['support_contact'] ?? '')],
        ];

        foreach ($rows as [$label, $value]) {
            $pdf->SetX(40);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(150, 14, $label, 'TB', 0, 'L');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(365, 14, $value !== '' ? $value : '—', 'TB', 'L', false, 1, '', '', true, 0, false, true, 14, 'M');
        }

        // Aceptación explícita (checkboxes) — texto exacto + valor.
        $pdf->Ln(10);
        $pdf->SetX(40);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 16, 'Aceptación explícita (consentimiento informado)', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 8.5);
        foreach (($audit['checkboxes'] ?? []) as $cb) {
            $mark = ! empty($cb['value']) ? '[X]' : '[  ]';
            $pdf->SetX(40);
            $pdf->Cell(22, 12, $mark, 0, 0, 'L');
            $pdf->MultiCell(493, 12, (string) ($cb['text'] ?? ''), 0, 'L', false, 1, '', '', true, 0, false, true, 12, 'T');
        }

        $pdf->Ln(8);
        $pdf->SetX(40);
        $pdf->SetFont('helvetica', 'I', 7.5);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->MultiCell(515, 10,
            'El checksum SHA-256 del PDF final y la bitácora completa de auditoría (creación, '.
            'visualización, firma, descargas) quedan registrados en el sistema Iron Body asociados al '.
            'folio indicado. Este andamiaje técnico no constituye asesoría legal.',
            0, 'L');
    }
}
