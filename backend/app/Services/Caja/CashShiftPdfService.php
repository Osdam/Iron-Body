<?php

namespace App\Services\Caja;

use App\Models\CashShift;
use Carbon\CarbonImmutable;
use TCPDF;

/**
 * El informe de cierre de caja, en papel.
 *
 * Sustituye al procedimiento que había: cerrar la caja, hacer una captura de
 * pantalla y guardarla o mandarla por WhatsApp. Una captura no se archiva, no se
 * busca, no dice quién la tomó y desaparece con el teléfono.
 *
 * Toma los datos de {@see CashShiftReport}, la MISMA composición que alimenta la
 * pantalla. No recalcula ni reinterpreta nada: si el PDF y la pantalla pudieran
 * discrepar sobre el mismo turno, ninguno de los dos serviría para un arqueo.
 *
 * Usa TCPDF, que ya estaba en el proyecto para los contratos de socios. No se
 * añade dependencia: una librería nueva para esto sería peso muerto.
 */
class CashShiftPdfService
{
    private const GRIS = [245, 245, 245];

    private const LINEA = [200, 200, 200];

    public function __construct(private readonly CashShiftReport $report) {}

    /** Nombre determinista, apto para archivar y buscar. */
    public function filename(CashShift $shift): string
    {
        $fecha = optional($shift->closed_at ?? $shift->opened_at)->format('Y-m-d') ?? 'sin-fecha';

        return sprintf('iron-body-cierre-%s-%s-turno-%d.pdf', $shift->type->value, $fecha, $shift->id);
    }

    /** El documento, como bytes. */
    public function render(CashShift $shift): string
    {
        $datos = $this->report->for($shift);
        $s = $datos['shift'];

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('IRON BODY CRM');
        $pdf->SetAuthor('IRON BODY');
        $pdf->SetTitle('Informe de cierre de caja · Turno '.$shift->id);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();

        $this->encabezado($pdf, $s);
        $this->responsables($pdf, $s);
        $this->resumen($pdf, $s);
        $this->metodos($pdf, $s);
        $this->arqueo($pdf, $s);
        $this->operaciones($pdf, $shift, $datos['transactions']);
        $this->observaciones($pdf, $s);
        $this->pie($pdf);

        return $pdf->Output($this->filename($shift), 'S');
    }

    /** @param array<string,mixed> $s */
    private function encabezado(TCPDF $pdf, array $s): void
    {
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 9, 'IRON BODY', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 6, 'Informe de cierre de caja', 0, 1, 'L');
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 7, sprintf('Caja %s · Turno #%d', $s['type_label'], $s['id']), 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Estado: '.mb_strtoupper((string) $s['status_label']), 0, 1, 'L');
        $this->separador($pdf);
    }

    /** @param array<string,mixed> $s */
    private function responsables(TCPDF $pdf, array $s): void
    {
        $this->titulo($pdf, 'Responsables');
        $pdf->SetFont('helvetica', '', 10);

        $this->fila($pdf, 'Abrió', $this->persona($s['opened_by_name'], $s['opened_at']));
        $this->fila($pdf, 'Cerró', $this->persona($s['closed_by_name'], $s['closed_at']));

        if ($s['duration_minutes'] !== null) {
            $m = (int) $s['duration_minutes'];
            $this->fila($pdf, 'Duración', sprintf('%dh %02dm', intdiv($m, 60), $m % 60));
        }

        if (! empty($s['forced'])) {
            $pdf->SetFont('helvetica', 'B', 10);
            $this->fila($pdf, 'Cierre forzado', 'Sí · '.($s['forced_reason'] ?: 'sin motivo registrado'));
            $pdf->SetFont('helvetica', '', 10);
        }

        $this->separador($pdf);
    }

    /** @param array<string,mixed> $s */
    private function resumen(TCPDF $pdf, array $s): void
    {
        $this->titulo($pdf, 'Resumen');
        $pdf->SetFont('helvetica', '', 10);
        $this->fila($pdf, 'Operaciones', $this->entero($s['operations_count']));
        $pdf->SetFont('helvetica', 'B', 13);
        $this->fila($pdf, 'Total', $this->dinero($s['gross_total']), 12);
        $this->separador($pdf);
    }

    /** @param array<string,mixed> $s */
    private function metodos(TCPDF $pdf, array $s): void
    {
        $this->titulo($pdf, 'Métodos de pago');
        $pdf->SetFont('helvetica', '', 10);

        foreach ([
            'Efectivo' => 'cash_total',
            'Transferencia' => 'transfer_total',
            'Tarjeta' => 'card_total',
            'Wompi' => 'wompi_total',
            'Otros' => 'other_total',
        ] as $etiqueta => $campo) {
            $this->fila($pdf, $etiqueta, $this->dinero($s[$campo] ?? null));
        }

        $pdf->SetFont('helvetica', 'B', 10);
        $this->fila($pdf, 'Total', $this->dinero($s['gross_total']));
        $this->separador($pdf);
    }

    /** @param array<string,mixed> $s */
    private function arqueo(TCPDF $pdf, array $s): void
    {
        $this->titulo($pdf, 'Arqueo físico');
        $pdf->SetFont('helvetica', '', 10);

        if ($s['counted_amount'] === null) {
            $pdf->SetFont('helvetica', 'I', 10);
            $pdf->Cell(0, 6, 'Arqueo físico no registrado.', 0, 1, 'L');
            $this->separador($pdf);

            return;
        }

        $dif = (float) $s['difference'];
        $this->fila($pdf, 'Efectivo esperado', $this->dinero($s['expected_cash']));
        $this->fila($pdf, 'Efectivo contado', $this->dinero($s['counted_amount']));
        $pdf->SetFont('helvetica', 'B', 10);
        $this->fila($pdf, 'Diferencia', $this->dinero($dif).'  ('.$this->veredicto($dif).')');
        $this->separador($pdf);
    }

    /** @param list<array<string,mixed>> $ops */
    private function operaciones(TCPDF $pdf, CashShift $shift, array $ops): void
    {
        $esProductos = $shift->type->value === 'products';
        $this->titulo($pdf, $esProductos ? 'Ventas del turno' : 'Cobros del turno');

        if ($ops === []) {
            $pdf->SetFont('helvetica', 'I', 10);
            $pdf->Cell(0, 6, 'No se registraron operaciones en este turno.', 0, 1, 'L');
            $this->separador($pdf);

            return;
        }

        $esProductos ? $this->tablaVentas($pdf, $ops) : $this->tablaCobros($pdf, $ops);
        $this->separador($pdf);
    }

    /** @param list<array<string,mixed>> $ops */
    private function tablaVentas(TCPDF $pdf, array $ops): void
    {
        foreach ($ops as $v) {
            $pdf->SetFont('helvetica', 'B', 9.5);
            $pdf->SetFillColor(...self::GRIS);
            $pdf->Cell(30, 6, (string) $v['code'], 0, 0, 'L', true);
            $pdf->Cell(32, 6, $this->hora($v['at']), 0, 0, 'L', true);
            $pdf->Cell(38, 6, (string) ($v['cashier'] ?? '—'), 0, 0, 'L', true);
            $pdf->Cell(32, 6, $this->metodo($v['payment_method']), 0, 0, 'L', true);
            $pdf->Cell(0, 6, $this->dinero($v['total']).'  '.$this->estado($v['status']), 0, 1, 'R', true);

            $pdf->SetFont('helvetica', '', 8.5);
            foreach ($v['lines'] as $l) {
                $pdf->Cell(6, 5, '', 0, 0);
                $pdf->Cell(84, 5, mb_strimwidth((string) $l['name'], 0, 40, '…'), 0, 0, 'L');
                $pdf->Cell(18, 5, 'x'.$l['quantity'], 0, 0, 'C');
                $pdf->Cell(32, 5, $this->dinero($l['unit_price']), 0, 0, 'R');
                $pdf->Cell(0, 5, $this->dinero($l['subtotal']), 0, 1, 'R');
            }
            $pdf->Ln(1.5);
        }
    }

    /** @param list<array<string,mixed>> $ops */
    private function tablaCobros(TCPDF $pdf, array $ops): void
    {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(...self::GRIS);
        $pdf->Cell(30, 6, 'Referencia', 0, 0, 'L', true);
        $pdf->Cell(28, 6, 'Hora', 0, 0, 'L', true);
        $pdf->Cell(46, 6, 'Socio', 0, 0, 'L', true);
        $pdf->Cell(30, 6, 'Plan', 0, 0, 'L', true);
        $pdf->Cell(0, 6, 'Valor', 0, 1, 'R', true);

        $pdf->SetFont('helvetica', '', 9);
        foreach ($ops as $p) {
            $pdf->Cell(30, 5.5, (string) ($p['reference'] ?? '#'.$p['id']), 0, 0, 'L');
            $pdf->Cell(28, 5.5, $this->hora($p['at']), 0, 0, 'L');
            $pdf->Cell(46, 5.5, mb_strimwidth((string) ($p['member'] ?? '—'), 0, 26, '…'), 0, 0, 'L');
            $pdf->Cell(30, 5.5, mb_strimwidth((string) ($p['plan'] ?? '—'), 0, 18, '…'), 0, 0, 'L');
            $pdf->Cell(0, 5.5, $this->dinero($p['total']).'  '.$this->metodo($p['payment_method']), 0, 1, 'R');
        }
    }

    /** @param array<string,mixed> $s */
    private function observaciones(TCPDF $pdf, array $s): void
    {
        $texto = trim((string) ($s['auto_observation'] ?? ''));
        $notas = trim((string) ($s['closing_notes'] ?? ''));
        if ($texto === '' && $notas === '') {
            return;
        }

        $this->titulo($pdf, 'Observaciones');
        $pdf->SetFont('helvetica', '', 9);
        foreach (array_filter([$texto, $notas]) as $bloque) {
            $pdf->MultiCell(0, 5, $bloque, 0, 'L');
            $pdf->Ln(1);
        }
        $this->separador($pdf);
    }

    private function pie(TCPDF $pdf): void
    {
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(120, 120, 120);
        // La fecha de GENERACIÓN, que no es la del cierre: quien lea el informe
        // meses después debe poder distinguirlas sin dudarlo.
        $pdf->Cell(0, 5, 'Informe generado por IRON BODY CRM el '.now()->format('d/m/Y H:i'), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    // ── Presentación ────────────────────────────────────────────────────────

    private function titulo(TCPDF $pdf, string $texto): void
    {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, mb_strtoupper($texto), 0, 1, 'L');
    }

    private function fila(TCPDF $pdf, string $etiqueta, string $valor, float $alto = 6): void
    {
        $pdf->Cell(55, $alto, $etiqueta, 0, 0, 'L');
        $pdf->Cell(0, $alto, $valor, 0, 1, 'L');
    }

    private function separador(TCPDF $pdf): void
    {
        $pdf->Ln(2);
        $pdf->SetDrawColor(...self::LINEA);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3.5);
    }

    private function persona(?string $nombre, ?string $iso): string
    {
        if ($nombre === null && $iso === null) {
            return 'No registrado';
        }

        return trim(($nombre ?: 'No registrado').'   ·   '.$this->fechaHora($iso));
    }

    private function dinero(mixed $v): string
    {
        if ($v === null) {
            return 'No registrado';
        }

        $n = (float) $v;

        return ($n < 0 ? '-' : '').'$ '.number_format(abs($n), 0, ',', '.');
    }

    private function entero(mixed $v): string
    {
        return $v === null ? 'No registrado' : (string) (int) $v;
    }

    private function veredicto(float $dif): string
    {
        if (abs($dif) < 0.01) {
            return 'cuadrada';
        }

        return $dif > 0 ? 'sobrante' : 'faltante';
    }

    private function metodo(?string $m): string
    {
        return match ($m) {
            'cash' => 'Efectivo',
            'transfer' => 'Transferencia',
            'card' => 'Tarjeta',
            'wompi' => 'Wompi',
            null => '—',
            default => ucfirst($m),
        };
    }

    private function estado(?string $e): string
    {
        return match ($e) {
            'paid' => '(pagada)',
            'delivered' => '(entregada)',
            'cancelled' => '(ANULADA)',
            'pending' => '(pendiente)',
            null => '',
            default => '('.$e.')',
        };
    }

    private function fechaHora(?string $iso): string
    {
        return $iso === null ? 'No registrado' : CarbonImmutable::parse($iso)
            ->setTimezone(config('caja.timezone'))->format('d/m/Y H:i');
    }

    private function hora(?string $iso): string
    {
        return $iso === null ? '—' : CarbonImmutable::parse($iso)
            ->setTimezone(config('caja.timezone'))->format('d/m H:i');
    }
}
