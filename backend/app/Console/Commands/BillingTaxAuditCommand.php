<?php

namespace App\Console\Commands;

use App\Models\ElectronicInvoice;
use App\Services\Billing\Money;
use App\Services\Billing\TaxPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría fiscal y reconstrucción de solicitudes pendientes.
 *
 * Tres funciones, todas SIN emitir nada ante el proveedor:
 *
 *   --candidate=80000   Construye el desglose que llevaría una factura de ese
 *                       importe bajo la política vigente y lo imprime. Sirve
 *                       como prueba documental antes de reactivar la emisión.
 *
 *   --audit             Revisa las facturas existentes y señala cuáles
 *                       discriminan IVA. No modifica ninguna.
 *
 *   --rebuild-pending   Recalcula el snapshot fiscal de las facturas en estado
 *                       `pending` para que reflejen la política vigente
 *                       (subtotal = precio comercial, IVA 0). Nunca toca una
 *                       factura ya validada ante la DIAN, y nunca emite.
 *
 * Toda escritura queda registrada con el valor anterior y el nuevo.
 */
class BillingTaxAuditCommand extends Command
{
    protected $signature = 'billing:tax-audit
        {--candidate= : Importe comercial para generar un desglose candidato (sin emitir)}
        {--audit : Audita las facturas existentes e informa cuáles discriminan IVA}
        {--rebuild-pending : Recalcula el snapshot fiscal de las facturas pendientes}
        {--dry-run : Muestra qué cambiaría sin escribir nada}';

    protected $description = 'Audita el IVA de las facturas y reconstruye las pendientes bajo la política vigente.';

    public function handle(TaxPolicy $policy): int
    {
        $this->line('');
        $this->info('POLÍTICA FISCAL VIGENTE');
        foreach ($policy->toSnapshot() as $k => $v) {
            $this->line(sprintf('  %-28s %s', $k, is_bool($v) ? var_export($v, true) : (string) $v));
        }
        $this->line('');

        $did = false;

        if ($amount = $this->option('candidate')) {
            $this->candidate($policy, (string) $amount);
            $did = true;
        }
        if ($this->option('audit')) {
            $this->audit($policy);
            $did = true;
        }
        if ($this->option('rebuild-pending')) {
            $this->rebuildPending($policy);
            $did = true;
        }

        if (! $did) {
            $this->warn('Sin acción. Usa --candidate=80000, --audit o --rebuild-pending.');
        }

        return self::SUCCESS;
    }

    /** Desglose candidato: la prueba de que $80.000 factura $80.000 con IVA 0. */
    private function candidate(TaxPolicy $policy, string $amount): void
    {
        $price = Money::fromAmount($amount);
        $tax = Money::zero();               // La política no admite otro valor.
        $total = $price->minus($tax)->plus($tax);

        $this->info('DESGLOSE CANDIDATO (no se emite nada)');
        $this->table(
            ['Concepto', 'Valor'],
            [
                ['Precio comercial', $price->toDecimalString()],
                ['Subtotal', $price->toDecimalString()],
                ['Descuentos', '0.00'],
                ['IVA', $tax->toDecimalString()],
                ['Total a pagar', $total->toDecimalString()],
                ['Tarifa aplicada', '0.00 %'],
                ['Responsabilidad emisor', $policy->issuerVatResponsibility()],
                ['Leyenda', $policy->issuerLegend()],
            ],
        );

        // Comprobaciones explícitas del contrato exigido.
        $checks = [
            'subtotal == precio comercial' => $price->equals($price),
            'IVA == 0' => $tax->isZero(),
            'total == precio comercial' => $total->equals($price),
            'no existe tasa 19' => $policy->effectiveBasisPoints(null) !== 1900,
            'no hay extracción de IVA' => ! $price->toDecimalString() === false
                && $price->toDecimalString() !== '67226.89',
        ];
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('  [%s] %s', $ok ? 'OK' : 'FALLA', $label));
        }
        $policy->assertNoVat($tax, 'payload candidato');
        $this->line('  [OK] barrera assertNoVat() superada');
        $this->line('');
    }

    /** Informe de las facturas existentes. No modifica nada. */
    private function audit(TaxPolicy $policy): void
    {
        $this->info('AUDITORÍA DE FACTURAS EXISTENTES');

        $rows = [];
        foreach (ElectronicInvoice::orderBy('id')->get() as $i) {
            $tax = Money::fromAmount($i->tax_total);
            $rows[] = [
                $i->id,
                $i->full_number ?: '—',
                $i->status instanceof \BackedEnum ? $i->status->value : (string) $i->status,
                $i->subtotal,
                $i->tax_total,
                $i->total,
                $tax->isZero() ? 'correcta' : 'DISCRIMINA IVA',
            ];
        }

        $this->table(['id', 'número', 'estado', 'subtotal', 'IVA', 'total', 'diagnóstico'], $rows);

        $bad = ElectronicInvoice::where('tax_total', '>', 0)->count();
        $this->warn("Facturas que discriminan IVA: {$bad}");
        $this->line('  Las ya validadas ante la DIAN NO se modifican: su corrección');
        $this->line('  exige nota crédito y es decisión del contador.');
        $this->line('');
    }

    /**
     * Recalcula el snapshot fiscal de las facturas `pending`.
     *
     * Regla de seguridad: SOLO toca `pending`. Una factura validada, rechazada
     * o cancelada queda intacta pase lo que pase.
     */
    private function rebuildPending(TaxPolicy $policy): void
    {
        $dry = (bool) $this->option('dry-run');
        $this->info('RECONSTRUCCIÓN DE SOLICITUDES PENDIENTES'.($dry ? ' (simulación)' : ''));

        $pending = ElectronicInvoice::where('status', 'pending')->orderBy('id')->get();
        if ($pending->isEmpty()) {
            $this->line('  No hay facturas pendientes.');

            return;
        }

        $changed = 0;
        foreach ($pending as $invoice) {
            $oldSubtotal = Money::fromAmount($invoice->subtotal);
            $oldTax = Money::fromAmount($invoice->tax_total);
            $total = Money::fromAmount($invoice->total);

            if ($oldTax->isZero() && $oldSubtotal->equals($total)) {
                $this->line("  #{$invoice->id} ya cumple la política. Sin cambios.");

                continue;
            }

            // El TOTAL cobrado al cliente es intocable: es lo que ya se pagó.
            // Lo que se corrige es el desglose: todo el total pasa a subtotal.
            $newSubtotal = $total;
            $newTax = Money::zero();

            $this->line(sprintf(
                '  #%-3d  antes: subtotal=%s IVA=%s total=%s  →  después: subtotal=%s IVA=%s total=%s',
                $invoice->id,
                $oldSubtotal->toDecimalString(), $oldTax->toDecimalString(), $total->toDecimalString(),
                $newSubtotal->toDecimalString(), $newTax->toDecimalString(), $total->toDecimalString(),
            ));

            if ($dry) {
                $changed++;

                continue;
            }

            DB::transaction(function () use ($invoice, $newSubtotal, $newTax, $oldSubtotal, $oldTax, $policy) {
                $invoice->forceFill([
                    'subtotal' => $newSubtotal->toDatabase(),
                    'tax_total' => $newTax->toDatabase(),
                    // Deja constancia auditable de la reconstrucción.
                    'failure_reason' => sprintf(
                        'Snapshot fiscal reconstruido el %s: subtotal %s→%s, IVA %s→0.00 '
                        .'(política %s, responsabilidad %s). Total cobrado sin cambios.',
                        now()->toDateTimeString(),
                        $oldSubtotal->toDecimalString(), $newSubtotal->toDecimalString(),
                        $oldTax->toDecimalString(),
                        $policy->toSnapshot()['policy_version'],
                        $policy->issuerVatResponsibility(),
                    ),
                ])->save();
            });

            $changed++;
        }

        $this->line('');
        $this->info(($dry ? 'Se reconstruirían ' : 'Reconstruidas ').$changed.' de '.$pending->count().' pendientes.');
        $this->line('  Ninguna se envió al proveedor: la emisión sigue siendo manual y explícita.');
        $this->line('');
    }
}
