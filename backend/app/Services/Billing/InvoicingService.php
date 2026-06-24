<?php

namespace App\Services\Billing;

use App\Enums\InvoiceLogAction;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Jobs\EmitCreditNoteJob;
use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceLog;
use App\Models\Payment;
use App\Models\ProductSale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Orquestador de facturación (patrón outbox, espejo de AutomationEventService).
 *
 * - Crea el comprobante como fuente de verdad (idempotente por
 *   source_type+source_id+type) ANTES de cualquier llamada externa.
 * - Si config('billing.enabled') es true, despacha el job de emisión a la cola
 *   'billing'; si es false, la factura queda 'pending' y NUNCA se llama a Factus.
 * - Es best-effort: cualquier excepción se captura y se loguea; jamás rompe el
 *   flujo de pago (el cobro ya ocurrió).
 */
class InvoicingService
{
    /** Mapa cerrado source_type amigable → clase del modelo (seguridad). */
    public const SOURCE_MAP = [
        'payment'      => Payment::class,
        'product_sale' => ProductSale::class,
    ];

    public function __construct(
        private FiscalProfileResolver $resolver,
        private InvoiceDtoBuilder $builder,
    ) {
    }

    /**
     * Encola (o crea) la factura de un pago/membresía aprobado. Idempotente.
     * Nunca lanza: devuelve la factura o null si algo falló (ya logueado).
     */
    public function enqueueForPayment(Payment $payment, bool $force = false): ?ElectronicInvoice
    {
        return $this->enqueue($payment, InvoiceType::INVOICE, fn () => $this->builder->forPayment(
            $payment,
            $this->resolver->resolveForPayment($payment)
        ), $force);
    }

    /**
     * Encola (o crea) la factura de una venta POS. Listo para Fase 2.
     */
    public function enqueueForSale(ProductSale $sale, bool $force = false): ?ElectronicInvoice
    {
        return $this->enqueue($sale, InvoiceType::INVOICE, fn () => $this->builder->forSale(
            $sale,
            $this->resolver->resolveForSale($sale)
        ), $force);
    }

    /**
     * Emisión manual desde el CRM por source_type amigable + source_id.
     * Idempotente: si ya hay factura validada/en curso, la devuelve sin duplicar;
     * si está en error/pending y $force, reencola.
     *
     * @throws InvalidArgumentException si el source_type no está permitido o el
     *                                  source no existe (el controller → 422).
     */
    public function manualEmit(string $sourceType, int $sourceId, bool $force = true): ?ElectronicInvoice
    {
        $class = self::SOURCE_MAP[$sourceType] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("source_type no soportado: {$sourceType}");
        }

        $model = $class::find($sourceId);
        if ($model === null) {
            throw new InvalidArgumentException("Fuente no encontrada: {$sourceType}#{$sourceId}");
        }

        return $model instanceof ProductSale
            ? $this->enqueueForSale($model, $force)
            : $this->enqueueForPayment($model, $force);
    }

    /**
     * Reintenta una factura en estado error/pending. Solo si el flag está activo.
     */
    public function retry(ElectronicInvoice $invoice): bool
    {
        if (! $invoice->status->canRetry() || ! config('billing.enabled')) {
            return false;
        }
        $this->recordLog($invoice, InvoiceLogAction::RETRY, 'ok', 'Reintento despachado.');

        $job = $invoice->type === InvoiceType::CREDIT_NOTE
            ? EmitCreditNoteJob::dispatch($invoice->id)
            : EmitElectronicInvoiceJob::dispatch($invoice->id);
        $job->onQueue($this->queue());

        return true;
    }

    /**
     * Crea (idempotente) la nota crédito de una factura validada y, si el flag
     * está activo, despacha EmitCreditNoteJob. La original debe estar VALIDATED y
     * con CUFE; si no, error controlado (no se anula algo no emitido).
     *
     * @throws InvalidArgumentException (el controller → 422).
     */
    public function createCreditNote(ElectronicInvoice $original, string $reason): ElectronicInvoice
    {
        if ($original->type !== InvoiceType::INVOICE) {
            throw new InvalidArgumentException('Solo se emiten notas crédito sobre facturas.');
        }
        if ($original->status !== InvoiceStatus::VALIDATED || empty($original->cufe)) {
            throw new InvalidArgumentException('La factura original debe estar validada (con CUFE) para anularse.');
        }

        $note = ElectronicInvoice::firstOrCreate(
            [
                'source_type' => $original->source_type,
                'source_id'   => $original->source_id,
                'type'        => InvoiceType::CREDIT_NOTE->value,
            ],
            [
                'status'                => InvoiceStatus::CREDIT_NOTE_PENDING->value,
                'references_invoice_id' => $original->id,
                'numbering_range_id'    => config('billing.numbering.range_id'),
                'prefix'                => config('billing.numbering.prefix'),
                // La causal/razón la consume EmitCreditNoteJob (campo failure_reason).
                'failure_reason'        => $reason,
                // Snapshot del adquiriente + montos copiados de la original.
                'customer_doc_type'        => $original->customer_doc_type,
                'customer_doc_number'      => $original->customer_doc_number,
                'customer_dv'              => $original->customer_dv,
                'customer_name'            => $original->customer_name,
                'customer_email'           => $original->customer_email,
                'customer_phone'           => $original->customer_phone,
                'customer_address'         => $original->customer_address,
                'customer_city_code'       => $original->customer_city_code,
                'customer_department_code' => $original->customer_department_code,
                'is_final_consumer'        => $original->is_final_consumer,
                'currency'                 => $original->currency,
                'subtotal'                 => $original->subtotal,
                'discount'                 => $original->discount,
                'tax_total'                => $original->tax_total,
                'total'                    => $original->total,
            ]
        );

        if ($note->wasRecentlyCreated) {
            $this->recordLog($note, InvoiceLogAction::CREDIT_NOTE, 'ok', 'Nota crédito creada (pending).');
        }

        if (config('billing.enabled') && $note->status->canRetry()) {
            EmitCreditNoteJob::dispatch($note->id)->onQueue($this->queue());
        }

        return $note;
    }

    /**
     * Núcleo idempotente. firstOrCreate por (source_type, source_id, type) y, si
     * corresponde, despacho del job. El snapshot (montos+customer) se persiste de
     * una vez para que el CRM muestre los datos aunque el flag esté apagado.
     *
     * @param  callable():array{snapshot:array,payload:array}  $build
     */
    private function enqueue(Model $source, InvoiceType $type, callable $build, bool $force): ?ElectronicInvoice
    {
        try {
            $built = $build();

            /** @var ElectronicInvoice $invoice */
            $invoice = ElectronicInvoice::firstOrCreate(
                [
                    'source_type' => $source->getMorphClass(),
                    'source_id'   => $source->getKey(),
                    'type'        => $type->value,
                ],
                array_merge($built['snapshot'], [
                    'status'             => InvoiceStatus::PENDING->value,
                    'numbering_range_id' => config('billing.numbering.range_id'),
                    'prefix'             => config('billing.numbering.prefix'),
                ])
            );

            if ($invoice->wasRecentlyCreated) {
                $this->recordLog($invoice, InvoiceLogAction::ENQUEUE, 'ok', 'Factura encolada (pending).');
            }

            // Ya validada o en curso: no la tocamos (idempotencia).
            $shouldDispatch = config('billing.enabled')
                && ($invoice->wasRecentlyCreated || ($force && $invoice->status->canRetry()));

            if ($shouldDispatch) {
                EmitElectronicInvoiceJob::dispatch($invoice->id)->onQueue($this->queue());
            }

            return $invoice;
        } catch (Throwable $e) {
            // Best-effort: el pago no debe fallar por la facturación.
            Log::warning('billing.enqueue_failed', [
                'source' => $source->getMorphClass() . ':' . $source->getKey(),
                'type'   => $type->value,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function recordLog(
        ElectronicInvoice $invoice,
        InvoiceLogAction $action,
        string $result,
        ?string $message = null,
        ?string $endpoint = null,
        ?int $httpStatus = null,
        array $payloadExcerpt = [],
        ?int $durationMs = null,
    ): void {
        ElectronicInvoiceLog::create([
            'electronic_invoice_id' => $invoice->id,
            'action'                => $action->value,
            'endpoint'              => $endpoint,
            'http_status'           => $httpStatus,
            'result'                => $result,
            'message'               => $message,
            'payload_excerpt'       => $payloadExcerpt ?: null,
            'duration_ms'           => $durationMs,
        ]);
    }

    private function queue(): string
    {
        return (string) config('billing.queue', 'billing');
    }
}
