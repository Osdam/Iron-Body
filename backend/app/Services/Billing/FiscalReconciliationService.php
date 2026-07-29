<?php

namespace App\Services\Billing;

use App\Models\ElectronicInvoice;
use App\Models\InvoiceFiscalReconciliation;
use App\Services\Billing\Factus\FactusClient;
use Illuminate\Support\Carbon;

/**
 * Contrasta cada factura local contra el documento fiscal real del proveedor.
 *
 * REGLA CENTRAL: para un documento ya validado, la autoridad es lo que está en
 * Factus/DIAN, no las columnas locales. El motivo es empírico: IBFE1 figura en
 * la base local con IVA 0,00 mientras el documento validado ante la DIAN
 * discrimina 12.773,11 (19 %). Auditar contra la base local producía un falso
 * negativo — decía «correcta» sobre una factura mal emitida.
 *
 * El servicio es de SOLO LECTURA respecto de la contabilidad: consulta por HTTP
 * GET (que no crea documentos ni consume consecutivos) y escribe únicamente
 * filas nuevas en la bitácora de reconciliación. Nunca toca importes de
 * `electronic_invoices`: corregir los libros exige aprobación del contador.
 */
class FiscalReconciliationService
{
    /**
     * Campos de la respuesta del proveedor que se conservan.
     *
     * Es una LISTA BLANCA a propósito, no una lista negra: así ningún campo
     * nuevo del proveedor —un token, un correo, una cédula— puede colarse en la
     * base por olvido. Lo que no esté aquí, no se guarda.
     */
    private const SNAPSHOT_FIELDS = [
        'number', 'cufe', 'is_validated', 'validated_at', 'status',
        'document_type', 'operation_type', 'totals', 'taxes', 'withholding_taxes',
    ];

    public function __construct(private FactusClient $client) {}

    /**
     * Reconcilia una factura y deja constancia. Devuelve la fila creada.
     *
     * @param  string  $actor  Comando o usuario técnico que originó la consulta.
     */
    public function reconcile(ElectronicInvoice $invoice, string $actor = 'billing:tax-audit'): InvoiceFiscalReconciliation
    {
        $base = $this->localSide($invoice, $actor);

        // ── Facturas SIN documento fiscal ────────────────────────────────
        // Una `pending` nunca se envió y una `rejected` no obtuvo número ni
        // CUFE. Consultarlas como si estuvieran validadas sería tratarlas como
        // emitidas. No se hace ninguna petición: no hay nada que consultar.
        if (($reason = $this->missingFiscalDocument($invoice)) !== null) {
            return $this->store($base + [
                'reconciliation_status' => InvoiceFiscalReconciliation::STATUS_UNAVAILABLE,
                'unavailable_reason' => $reason,
            ]);
        }

        $response = $this->client->getInvoice((string) $invoice->full_number);

        if (! ($response['ok'] ?? false)) {
            // El proveedor no respondió o falló. NUNCA se interpreta como
            // «coincide»: sin evidencia no hay conformidad.
            return $this->store($base + [
                'reconciliation_status' => InvoiceFiscalReconciliation::STATUS_UNAVAILABLE,
                'unavailable_reason' => sprintf(
                    'El proveedor no devolvió el documento (HTTP %s, %s).',
                    $response['status'] ?? 0,
                    $response['error_class'] ?? 'desconocido',
                ),
            ]);
        }

        $data = $response['body']['data'] ?? $response['body']['data']['bill'] ?? [];
        $provider = $this->extractProviderValues($data);

        $differences = $this->diff($invoice, $provider);

        return $this->store($base + $provider + [
            'reconciliation_status' => $differences === []
                ? InvoiceFiscalReconciliation::STATUS_RECONCILED
                : InvoiceFiscalReconciliation::STATUS_MISMATCH,
            'differences' => $differences === [] ? null : $differences,
            'provider_snapshot' => $this->sanitizedSnapshot($data),
            'provider_payload_hash' => hash('sha256', json_encode($data) ?: ''),
        ]);
    }

    /**
     * ¿La factura carece de documento fiscal que consultar?
     *
     * @return string|null Motivo, o null si sí tiene documento.
     */
    private function missingFiscalDocument(ElectronicInvoice $invoice): ?string
    {
        $status = $this->statusOf($invoice);

        if ($status !== 'validated') {
            return sprintf(
                'La factura está en estado «%s»: no tiene documento fiscal ante la DIAN. '
                .'No se consulta al proveedor ni se trata como emitida.',
                $status,
            );
        }

        if (blank($invoice->full_number)) {
            return 'La factura figura como validada pero no tiene número asignado.';
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function localSide(ElectronicInvoice $invoice, string $actor): array
    {
        return [
            'electronic_invoice_id' => $invoice->id,
            'invoice_number' => $invoice->full_number,
            'local_subtotal' => $invoice->subtotal,
            'local_tax_total' => $invoice->tax_total,
            'local_total' => $invoice->total,
            'local_status' => $this->statusOf($invoice),
            'actor' => $actor,
            'fetched_at' => now(),
        ];
    }

    private function statusOf(ElectronicInvoice $invoice): string
    {
        $status = $invoice->status;

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    /**
     * Extrae los valores fiscales de la respuesta.
     *
     * La estructura observada en la API V2 es plana bajo `data`, con los
     * importes en `totals` y la representación del tributo en `taxes[0]`:
     *
     *   totals: {taxable_amount, tax_amount, total, ...}
     *   taxes:  [{tribute:{code,name}, is_excluded, rates:[{rate, ...}]}]
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function extractProviderValues(array $data): array
    {
        $totals = $data['totals'] ?? [];
        $tax = $data['taxes'][0] ?? ($data['items'][0]['taxes'][0] ?? []);
        $rate = $tax['rates'][0] ?? [];

        return [
            'provider_taxable_amount' => $totals['taxable_amount'] ?? ($rate['taxable_amount'] ?? null),
            'provider_tax_amount' => $totals['tax_amount'] ?? ($rate['tax_amount'] ?? null),
            'provider_total' => $totals['total'] ?? null,
            'provider_rate' => $rate['rate'] ?? null,
            'provider_tribute_code' => $tax['tribute']['code'] ?? null,
            'provider_is_excluded' => array_key_exists('is_excluded', $tax)
                ? (bool) $tax['is_excluded']
                : null,
            'provider_cufe' => $data['cufe'] ?? null,
            'provider_is_validated' => array_key_exists('is_validated', $data)
                ? (bool) $data['is_validated']
                : null,
            'provider_validated_at' => $this->parseDate($data['validated_at'] ?? null),
        ];
    }

    /**
     * El proveedor entrega la fecha como «26-06-2026 01:12:31 PM».
     * Si algún día cambia el formato, se prefiere null antes que una fecha mal
     * interpretada en un registro de auditoría.
     */
    private function parseDate(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        foreach (['d-m-Y h:i:s A', 'Y-m-d H:i:s', 'd/m/Y h:i:s A'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Diferencias entre local y proveedor, en CENTAVOS enteros.
     *
     * Se comparan enteros y no floats porque una diferencia de un céntimo por
     * representación binaria haría saltar —o peor, silenciaría— una alerta
     * sobre un documento legal.
     *
     * @param  array<string,mixed>  $provider
     * @return array<string,array{local:string,proveedor:string,diferencia:string}>
     */
    private function diff(ElectronicInvoice $invoice, array $provider): array
    {
        $pairs = [
            'iva' => [$invoice->tax_total, $provider['provider_tax_amount']],
            'base_gravable' => [$invoice->subtotal, $provider['provider_taxable_amount']],
            'total' => [$invoice->total, $provider['provider_total']],
        ];

        $differences = [];

        foreach ($pairs as $concept => [$local, $remote]) {
            if ($remote === null) {
                continue; // Sin dato del proveedor no se afirma diferencia.
            }

            $localCents = (int) round(((float) $local) * 100);
            $remoteCents = (int) round(((float) $remote) * 100);

            if ($localCents !== $remoteCents) {
                $differences[$concept] = [
                    'local' => $this->money($localCents),
                    'proveedor' => $this->money($remoteCents),
                    'diferencia' => $this->money($remoteCents - $localCents),
                ];
            }
        }

        // Una tarifa positiva en el documento validado es un hallazgo por sí
        // misma, aunque los importes locales casaran.
        if ((float) ($provider['provider_rate'] ?? 0) > 0) {
            $differences['tarifa_declarada'] = [
                'local' => '0.00',
                'proveedor' => (string) $provider['provider_rate'],
                'diferencia' => (string) $provider['provider_rate'],
            ];
        }

        return $differences;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Instantánea sanitizada: solo los campos de {@see SNAPSHOT_FIELDS}.
     *
     * Deja fuera `customer` (datos personales del adquirente), `company`,
     * `links` y cualquier binario o credencial.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function sanitizedSnapshot(array $data): array
    {
        return array_intersect_key($data, array_flip(self::SNAPSHOT_FIELDS));
    }

    /** @param  array<string,mixed>  $attributes */
    private function store(array $attributes): InvoiceFiscalReconciliation
    {
        return InvoiceFiscalReconciliation::create($attributes);
    }
}
