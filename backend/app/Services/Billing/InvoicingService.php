<?php

namespace App\Services\Billing;

use App\Enums\InvoiceLogAction;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceLog;
use App\Models\Payment;
use App\Models\ProductSale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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
     * Reintenta una factura en estado error/pending. Solo si el flag está activo.
     */
    public function retry(ElectronicInvoice $invoice): bool
    {
        if (! $invoice->status->canRetry() || ! config('billing.enabled')) {
            return false;
        }
        $this->recordLog($invoice, InvoiceLogAction::RETRY, 'ok', 'Reintento despachado.');
        EmitElectronicInvoiceJob::dispatch($invoice->id)->onQueue($this->queue());

        return true;
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
