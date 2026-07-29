<?php

namespace App\Console\Commands;

use App\Enums\InvoiceLogAction;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\ElectronicInvoice;
use App\Services\Billing\InvoicingService;
use App\Services\Billing\Money;
use App\Services\Billing\PricingMode;
use App\Services\Billing\PricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Congela el payload de las facturas PENDING legacy, preservando sus importes.
 *
 * ¿Por qué existe? En producción hay 8 facturas `pending` de pagos de $80.000
 * con subtotal 67.226,89 + IVA 12.773,11 = 80.000 (IVA incluido). Cuando esas
 * facturas se emitan, el job reconstruiría el payload desde el catálogo VIVO; si
 * para entonces algún plan ya migró a base_plus_tax, se emitirían por 95.200
 * contra un cobro de 80.000. Congelar el payload ahora las deja inmunes a
 * cualquier cambio posterior de catálogo.
 *
 * GARANTÍAS:
 *   - NO llama a Factus. NO emite. NO cambia el estado del comprobante.
 *   - NO toca subtotal, discount, tax_total ni total: el payload se construye
 *     A PARTIR de esos importes ya persistidos, no recalculándolos.
 *   - Conserva legacy_inclusive: la base va como `price` unitario y el IVA se
 *     expresa con la tasa derivada de los propios importes de la factura.
 *   - Rechaza cualquier factura descuadrada (internamente o contra su pago).
 *   - Idempotente: omite las que ya tienen payload_snapshot.
 *   - Solo escribe con --apply. Sin él, es estrictamente de solo lectura.
 *
 * Uso:
 *   php artisan billing:freeze-pending-invoices              # simulación
 *   php artisan billing:freeze-pending-invoices --apply
 *   php artisan billing:freeze-pending-invoices --invoice-id=9 --apply
 */
class FreezePendingInvoicesCommand extends Command
{
    protected $signature = 'billing:freeze-pending-invoices
        {--apply : Persiste los cambios. Sin este flag el comando es de solo lectura.}
        {--invoice-id= : Procesa solo el comprobante indicado.}
        {--limit=100 : Máximo de comprobantes a procesar.}';

    protected $description = 'Congela el payload de facturas pending legacy sin alterar sus importes ni llamar a Factus (por defecto: simulación).';

    public function handle(InvoicingService $invoicing): int
    {
        $apply = (bool) $this->option('apply');

        $this->line($apply
            ? '<comment>MODO APLICAR</comment>: se persistirá el payload congelado.'
            : '<info>MODO SIMULACIÓN</info>: no se escribirá nada. Añade --apply para persistir.');
        $this->line('Este comando NUNCA llama a Factus ni emite comprobantes.');
        $this->newLine();

        $query = ElectronicInvoice::query()
            ->where('type', InvoiceType::INVOICE->value)
            ->where('status', InvoiceStatus::PENDING->value)
            ->whereNull('payload_snapshot')
            ->orderBy('id');

        if ($id = $this->option('invoice-id')) {
            $query->whereKey((int) $id);
        }

        $invoices = $query->limit((int) $this->option('limit'))->get();

        if ($invoices->isEmpty()) {
            $this->info('No hay facturas pending sin payload congelado. Nada que hacer.');

            return self::SUCCESS;
        }

        $rows = [];
        $frozen = 0;
        $rejected = 0;

        foreach ($invoices as $invoice) {
            $result = $this->evaluate($invoice);

            $rows[] = [
                $invoice->id,
                $invoice->source_type.'#'.$invoice->source_id,
                number_format((float) $invoice->subtotal, 2, ',', '.'),
                number_format((float) $invoice->tax_total, 2, ',', '.'),
                number_format((float) $invoice->total, 2, ',', '.'),
                $result['rate'] !== null ? $result['rate'].'%' : '—',
                $result['ok'] ? '<info>OK</info>' : '<error>RECHAZADA</error>',
                $result['reason'] ?? '',
            ];

            if (! $result['ok']) {
                $rejected++;

                continue;
            }

            if ($apply) {
                DB::transaction(function () use ($invoice, $result, $invoicing): void {
                    $invoice->forceFill([
                        'payload_snapshot' => $result['payload'],
                        'line_items_snapshot' => $result['line_items'],
                        'source_amount_snapshot' => $result['source_amount'],
                        'pricing_rules_version' => PricingService::RULES_VERSION,
                    ])->save();

                    $invoicing->recordLog(
                        $invoice,
                        InvoiceLogAction::ENQUEUE,
                        'ok',
                        'Payload congelado (legacy_inclusive) sin alterar importes. No se llamó a Factus.',
                        payloadExcerpt: [
                            'frozen' => [
                                'subtotal' => (float) $invoice->subtotal,
                                'tax_total' => (float) $invoice->tax_total,
                                'total' => (float) $invoice->total,
                                'tax_rate' => $result['rate'],
                            ],
                        ],
                    );
                });
            }

            $frozen++;
        }

        $this->table(
            ['ID', 'Origen', 'Subtotal', 'IVA', 'Total', 'Tasa', 'Estado', 'Motivo'],
            $rows
        );

        $this->newLine();
        $this->info(($apply ? 'Congeladas: ' : 'Congelables: ').$frozen);
        if ($rejected > 0) {
            $this->error("Rechazadas por descuadre: {$rejected}. Revísalas manualmente; NO se modificaron.");
        }
        if (! $apply && $frozen > 0) {
            $this->line('Ejecuta de nuevo con --apply para persistir.');
        }

        return $rejected > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Valida y construye el payload congelado a partir de los importes YA
     * persistidos en el comprobante. No recalcula nada desde el catálogo.
     *
     * @return array{ok: bool, reason: string|null, rate: string|null, payload: array<string,mixed>|null, line_items: array<int,mixed>|null, source_amount: float|null}
     */
    private function evaluate(ElectronicInvoice $invoice): array
    {
        $fail = fn (string $reason) => [
            'ok' => false, 'reason' => $reason, 'rate' => null,
            'payload' => null, 'line_items' => null, 'source_amount' => null,
        ];

        $subtotal = Money::fromAmount($invoice->subtotal);
        $tax = Money::fromAmount($invoice->tax_total);
        $discount = Money::fromAmount($invoice->discount);
        $total = Money::fromAmount($invoice->total);

        // 1) Coherencia interna: subtotal + IVA - descuento == total.
        if (! $subtotal->plus($tax)->minus($discount)->equals($total)) {
            return $fail('subtotal + IVA - descuento != total');
        }

        if ($subtotal->isZero() && ! $tax->isZero()) {
            return $fail('IVA sin base gravable');
        }

        // 2) Coherencia contra el pago/venta de origen (si sobrevive).
        $source = $invoice->source;
        $sourceAmount = $source !== null ? InvoicingService::sourceGrossAmount($source) : null;

        if ($sourceAmount !== null) {
            $difference = round(abs($sourceAmount - $total->toFloat()), 2);
            $tolerance = (float) config('billing.reconciliation_guard.tolerance', 1);
            if ($difference > $tolerance) {
                return $fail(sprintf(
                    'total %s != origen %s (dif %s)',
                    $total->toDecimalString(),
                    number_format($sourceAmount, 2, '.', ''),
                    number_format($difference, 2, '.', ''),
                ));
            }
        }

        // 3) Tasa derivada de los propios importes de la factura (no del catálogo).
        $rateBp = $subtotal->isZero()
            ? 0
            : (int) round(($tax->cents() * 10000) / $subtotal->cents());
        $rateString = number_format($rateBp / 100, 2, '.', '');

        // 4) Payload con la MISMA estructura que produce InvoiceDtoBuilder.
        $d = (array) config('billing.defaults');
        $customer = $this->customerFromInvoice($invoice, $d);
        $name = $this->observationFor($invoice);

        $item = [
            'code_reference' => $this->codeReferenceFor($invoice),
            'name' => $name,
            'quantity' => '1.00',
            'discount_rate' => '0.00',
            // Base ya persistida: la factura mantiene exactamente sus importes.
            'price' => $subtotal->toDecimalString(),
            'unit_measure_code' => (string) ($d['unit_measure_code'] ?? '94'),
            'standard_code' => (string) ($d['standard_code'] ?? '999'),
            'taxes' => $rateBp > 0
                ? [['code' => (string) ($d['tax_code'] ?? '01'), 'rate' => $rateString]]
                : [['is_excluded' => true]],
        ];

        $payload = [
            'document' => (string) ($d['document'] ?? '01'),
            'operation_type' => (string) ($d['operation_type'] ?? '10'),
            'numbering_range_id' => (int) config('billing.numbering.range_id'),
            'send_email' => false,
            'observation' => $name,
            'cash_rounding_amount' => '0.00',
            'payment_details' => [[
                'payment_form' => (int) ($d['payment_form'] ?? 1),
                'payment_method_code' => (string) ($d['payment_method_code'] ?? '10'),
                'amount' => $total->toDecimalString(),
            ]],
            'customer' => $customer,
            'items' => [$item],
        ];

        return [
            'ok' => true,
            'reason' => null,
            'rate' => $rateString,
            'payload' => $payload,
            'line_items' => [[
                'code_reference' => $item['code_reference'],
                'name' => $name,
                'quantity' => 1,
                'base' => $subtotal->toDecimalString(),
                'tax_rate' => (float) $rateString,
                'tax' => $tax->toDecimalString(),
                'discount' => $discount->toDecimalString(),
                'gross' => $total->toDecimalString(),
                'pricing_mode' => PricingMode::LEGACY_INCLUSIVE->value,
            ]],
            'source_amount' => $sourceAmount ?? $total->toFloat(),
        ];
    }

    /** Bloque customer reconstruido desde el snapshot ya guardado en la factura. */
    private function customerFromInvoice(ElectronicInvoice $invoice, array $d): array
    {
        $out = [
            'identification_document_code' => (string) ($invoice->customer_doc_type ?? ''),
            'identification' => (string) ($invoice->customer_doc_number ?? ''),
            'legal_organization_code' => (string) ($d['legal_organization_code'] ?? '2'),
            'tribute_code' => (string) ($d['tribute_code'] ?? 'ZZ'),
            'municipality_code' => $invoice->customer_city_code ?: ($d['municipality_code'] ?? null),
            'names' => (string) ($invoice->customer_name ?? ''),
        ];

        if (! empty($invoice->customer_dv)) {
            $out['dv'] = (string) $invoice->customer_dv;
        }
        foreach (['address' => 'customer_address', 'email' => 'customer_email', 'phone' => 'customer_phone'] as $key => $column) {
            if (! empty($invoice->{$column})) {
                $out[$key] = (string) $invoice->{$column};
            }
        }

        return $out;
    }

    private function codeReferenceFor(ElectronicInvoice $invoice): string
    {
        $source = $invoice->source;
        if ($source === null) {
            return 'PAGO';
        }

        return $source->plan_id ?? null ? 'PLAN-'.$source->plan_id : 'PAGO';
    }

    private function observationFor(ElectronicInvoice $invoice): string
    {
        $source = $invoice->source;
        $plan = $source?->plan_id ? $source->plan : null;

        return $plan ? 'Membresía '.$plan->name.' - Iron Body' : 'Pago Iron Body';
    }
}
