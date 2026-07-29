<?php

namespace App\Console\Commands;

use App\Models\ElectronicInvoice;
use App\Models\InvoiceFiscalReconciliation;
use App\Services\Billing\FiscalReconciliationService;
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
 *   --audit             Contrasta cada factura contra el documento fiscal real
 *                       del proveedor (GET, de solo lectura) y señala las
 *                       discrepancias. Para un documento validado la AUTORIDAD
 *                       es el proveedor, no las columnas locales. Deja
 *                       constancia en la bitácora de reconciliación y termina
 *                       con código distinto de cero si hay hallazgos. No
 *                       modifica ningún importe contable.
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
        {--apply-provider-values : RESERVADO. Sobrescribiría los importes locales con los del proveedor; exige aprobación del contador}
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
        $findings = 0;

        if ($amount = $this->option('candidate')) {
            $this->candidate($policy, (string) $amount);
            $did = true;
        }
        if ($this->option('audit')) {
            $findings = $this->audit($policy);
            $did = true;
        }
        if ($this->option('rebuild-pending')) {
            $this->rebuildPending($policy);
            $did = true;
        }
        if ($this->option('apply-provider-values')) {
            // Camino explícitamente distinto de --audit, y hoy cerrado.
            // Sobrescribir los importes locales con los del proveedor cambiaría
            // los libros: es una decisión contable, no técnica.
            $this->error('Sincronizar los importes locales con los del proveedor requiere');
            $this->error('aprobación escrita del contador. La auditoría solo deja constancia.');

            return self::FAILURE;
        }

        if (! $did) {
            $this->warn('Sin acción. Usa --candidate=80000, --audit o --rebuild-pending.');

            return self::SUCCESS;
        }

        if ($findings > 0) {
            // Código distinto de cero: si esto corre en CI o en un cron, una
            // discrepancia fiscal no puede pasar como ejecución correcta.
            $this->error(sprintf(
                'Auditoría con %d hallazgo%s sin resolver.',
                $findings, $findings === 1 ? '' : 's',
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Desglose candidato: la prueba de que $80.000 factura $80.000 con IVA 0.
     *
     * Se trabaja en CENTAVOS (enteros), nunca en coma flotante: un comprobante
     * legal no puede depender de la representación binaria de un decimal.
     */
    private function candidate(TaxPolicy $policy, string $amount): void
    {
        $priceCents = (int) round(((float) $amount) * 100);
        $taxCents = 0;                       // La política no admite otro valor.
        $totalCents = $priceCents + $taxCents;

        $fmt = static fn (int $cents): string => number_format($cents / 100, 2, '.', '');
        $price = $fmt($priceCents);
        $tax = $fmt($taxCents);
        $total = $fmt($totalCents);

        $this->info('DESGLOSE CANDIDATO (no se emite nada)');
        $this->table(
            ['Concepto', 'Valor'],
            [
                ['Precio comercial', $price],
                ['Subtotal', $price],
                ['Descuentos', '0.00'],
                ['IVA', $tax],
                ['Total a pagar', $total],
                ['Tarifa aplicada', '0.00 %'],
                ['Responsabilidad emisor', $policy->issuerVatResponsibility()],
                ['Leyenda', $policy->issuerLegend()],
            ],
        );

        // Comprobaciones explícitas del contrato exigido.
        // Valor que produciría la extracción del 19 % sobre este importe: es el
        // que NO debe aparecer por ningún lado (para 80000 → 67226.89).
        $extracted = number_format(round($priceCents / 1.19) / 100, 2, '.', '');

        $checks = [
            'subtotal == precio comercial' => $price === $fmt($priceCents),
            'IVA == 0' => $taxCents === 0,
            'total == precio comercial' => $totalCents === $priceCents,
            'no existe tasa 19' => $policy->defaultVatRate() === 0.0
                && $policy->effectiveBasisPoints(null) === 0,
            'no hay extracción de IVA (≠ '.$extracted.')' => $price !== $extracted,
        ];
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('  [%s] %s', $ok ? 'OK' : 'FALLA', $label));
        }
        $policy->assertNoVat($tax, 'payload candidato');
        $this->line('  [OK] barrera assertNoVat() superada');
        $this->line('');
    }

    /**
     * Informe de las facturas existentes contrastadas contra el PROVEEDOR.
     *
     * Antes este método leía `electronic_invoices.tax_total` y daba por buena
     * cualquier factura con IVA local 0,00. Eso produjo un FALSO NEGATIVO: la
     * IBFE1 figura localmente con IVA 0,00 mientras el documento validado ante
     * la DIAN discrimina 12.773,11 (19 %). Auditar contra la propia base es
     * auditar la copia, no el original.
     *
     * Ahora, para un documento validado, la autoridad es el proveedor. La base
     * local solo sirve para exhibir la discrepancia.
     *
     * No modifica ningún importe contable: únicamente consulta por GET y añade
     * filas a la bitácora de reconciliación.
     *
     * @return int Número de facturas con discrepancia o sin evidencia.
     */
    private function audit(TaxPolicy $policy): int
    {
        $this->info('AUDITORÍA FISCAL — el proveedor es la autoridad para documentos validados');
        $this->line('');

        $service = app(FiscalReconciliationService::class);

        $rows = [];
        $providerTaxCents = 0;
        $mismatches = 0;
        $unavailable = 0;
        $withVat = 0;

        foreach (ElectronicInvoice::orderBy('id')->get() as $invoice) {
            $r = $service->reconcile($invoice, actor: 'billing:tax-audit');

            $providerTaxCents += $r->providerTaxCents();

            if ($r->providerTaxCents() > 0) {
                $withVat++;
            }

            $status = match ($r->reconciliation_status) {
                InvoiceFiscalReconciliation::STATUS_MISMATCH => '✗ DISCREPANCIA',
                InvoiceFiscalReconciliation::STATUS_RECONCILED => '✓ conciliada',
                default => '· sin evidencia',
            };

            if ($r->isMismatch()) {
                $mismatches++;
            } elseif ($r->reconciliation_status === InvoiceFiscalReconciliation::STATUS_UNAVAILABLE
                && $r->local_status === 'validated') {
                // Una `pending` o `rejected` sin documento fiscal es lo normal y
                // no cuenta como fallo. Una VALIDADA que no se pudo consultar sí:
                // significa que hay un documento ante la DIAN sobre el que no
                // tenemos evidencia.
                $unavailable++;
            }

            $rows[] = [
                $invoice->id,
                $r->invoice_number ?: '—',
                $r->local_status,
                $r->local_subtotal,
                $r->local_tax_total,
                $r->provider_taxable_amount ?? '—',
                $r->provider_tax_amount ?? '—',
                $r->provider_rate ?? '—',
                $status,
            ];
        }

        $this->table([
            'id', 'número', 'estado',
            'local_subtotal', 'local_tax_total',
            'prov_base_gravable', 'prov_tax_total', 'prov_tarifa',
            'reconciliación',
        ], $rows);

        $this->line('');
        $this->warn(sprintf(
            'IVA discriminado según el PROVEEDOR: %s  (%d factura%s)',
            number_format($providerTaxCents / 100, 2, '.', ''),
            $withVat,
            $withVat === 1 ? '' : 's',
        ));
        $this->line(sprintf('  Discrepancias local↔proveedor:        %d', $mismatches));
        $this->line(sprintf('  Validadas sin evidencia del proveedor: %d', $unavailable));
        $this->line('');
        $this->line('  Los documentos ya validados ante la DIAN NO se modifican: su');
        $this->line('  corrección exige nota crédito y es decisión del contador.');
        $this->line('  Los importes contables locales tampoco se tocan; esta auditoría');
        $this->line('  solo deja constancia auditable de la divergencia.');
        $this->line('');

        return $mismatches + $unavailable;
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
            $fmt = static fn ($v): string => number_format((float) $v, 2, '.', '');
            $oldSubtotalC = (int) round(((float) $invoice->subtotal) * 100);
            $oldTaxC = (int) round(((float) $invoice->tax_total) * 100);
            $totalC = (int) round(((float) $invoice->total) * 100);
            $oldSubtotal = $fmt($invoice->subtotal);
            $oldTax = $fmt($invoice->tax_total);
            $total = $fmt($invoice->total);

            if ($oldTaxC === 0 && $oldSubtotalC === $totalC) {
                $this->line("  #{$invoice->id} ya cumple la política. Sin cambios.");

                continue;
            }

            // El TOTAL cobrado al cliente es intocable: es lo que ya se pagó.
            // Lo que se corrige es el desglose: todo el total pasa a subtotal.
            $newSubtotal = $total;
            $newTax = '0.00';

            $this->line(sprintf(
                '  #%-3d  antes: subtotal=%s IVA=%s total=%s  →  después: subtotal=%s IVA=%s total=%s',
                $invoice->id, $oldSubtotal, $oldTax, $total, $newSubtotal, $newTax, $total,
            ));

            if ($dry) {
                $changed++;

                continue;
            }

            DB::transaction(function () use ($invoice, $newSubtotal, $newTax, $oldSubtotal, $oldTax, $policy) {
                $invoice->forceFill([
                    'subtotal' => $newSubtotal,
                    'tax_total' => $newTax,
                    // Deja constancia auditable de la reconstrucción.
                    'failure_reason' => sprintf(
                        'Snapshot fiscal reconstruido el %s: subtotal %s→%s, IVA %s→0.00 '
                        .'(política %s, responsabilidad %s). Total cobrado sin cambios.',
                        now()->toDateTimeString(),
                        $oldSubtotal, $newSubtotal, $oldTax,
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
