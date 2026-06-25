<?php

namespace App\Jobs;

use App\Enums\InvoiceLogAction;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\ElectronicInvoice;
use App\Models\ProductSale;
use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\Factus\FactusConfigValidator;
use App\Services\Billing\FactusPayloadSanitizer;
use App\Services\Billing\FactusResponseMapper;
use App\Services\Billing\FiscalProfileResolver;
use App\Services\Billing\InvoiceDtoBuilder;
use App\Services\Billing\InvoicePdfStorageService;
use App\Services\Billing\InvoicingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Emite una NOTA CRÉDITO (anulación/reembolso) en Factus V2.
 *
 * Opera sobre un ElectronicInvoice de tipo credit_note (con references_invoice_id
 * a la factura original VALIDADA). Reconstruye customer/items/payment_details
 * desde el source (mismo builder que la factura) y añade los campos propios de
 * NC: correction_concept_code, customization_id, bill_number (de la original) y
 * SU PROPIO numbering_range_id. Estructura confirmada contra la colección oficial
 * (POST /v2/credit-notes/validate).
 */
class EmitCreditNoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $backoff;

    public function __construct(public int $creditNoteInvoiceId)
    {
        $this->tries   = (int) config('billing.http.retry_times', 5);
        $this->backoff = (int) config('billing.http.retry_backoff', 60);
    }

    public function handle(
        FactusClient $client,
        FactusResponseMapper $mapper,
        FactusPayloadSanitizer $sanitizer,
        InvoicePdfStorageService $storage,
        InvoicingService $invoicing,
        InvoiceDtoBuilder $builder,
        FiscalProfileResolver $resolver,
    ): void {
        if (! config('billing.enabled')) {
            return;
        }

        // 🔒 En producción, no emitir si la config no está lista (ver Emit job).
        if (config('billing.env') === 'production'
            && ! FactusConfigValidator::fromConfig()->isReadyForProduction()) {
            Log::warning('billing.production_not_ready', ['credit_note' => $this->creditNoteInvoiceId]);
            return;
        }

        $note = ElectronicInvoice::with('referencesInvoice')->find($this->creditNoteInvoiceId);
        if ($note === null || $note->type !== InvoiceType::CREDIT_NOTE) {
            return;
        }
        if ($note->status->isFinal() || ! $note->status->canRetry()) {
            return;
        }

        $original = $note->referencesInvoice;
        if ($original === null || empty($original->full_number)) {
            $note->markError('Nota crédito sin factura original válida (número).');
            return;
        }

        $source = $note->source; // Payment | ProductSale (mismo que la original)
        if ($source === null) {
            $note->markError('Fuente de la nota crédito no encontrada.');
            return;
        }

        // Reconstruye customer + items + payment_details con el mismo builder.
        $built = $source instanceof ProductSale
            ? $builder->forSale($source, $resolver->resolveForSale($source))
            : $builder->forPayment($source, $resolver->resolveForPayment($source));
        $base = $built['payload'];

        $payload = [
            'reference_code'          => $note->uuid,
            'correction_concept_code' => (string) config('billing.credit_note.correction_concept_code', '2'),
            'customization_id'        => (string) config('billing.credit_note.customization_id', '20'),
            'bill_number'             => $original->full_number,
            'numbering_range_id'      => (int) (config('billing.numbering.credit_range_id') ?: $note->numbering_range_id),
            'observation'             => $note->failure_reason ?? 'Anulación',
            'cash_rounding_amount'    => '0.00',
            'payment_details'         => $base['payment_details'],
            'customer'                => $base['customer'],
            'items'                   => $base['items'],
        ];

        $note->markProcessing();

        $result = $client->createCreditNote($payload);

        $invoicing->recordLog(
            $note,
            InvoiceLogAction::CREDIT_NOTE,
            $result['ok'] ? 'ok' : 'error',
            $result['error'],
            endpoint: 'credit-notes/validate',
            httpStatus: $result['status'],
            payloadExcerpt: $sanitizer->excerpt(['request' => $payload, 'response' => $result['body']]),
        );

        if ($result['ok']) {
            $mapped = $mapper->map($result['body']);
            if ($mapped['is_validated']) {
                $files = $storage->store($note, $mapped);
                $note->markValidated(array_merge($files, [
                    'factus_id'   => $mapped['factus_id'],
                    'number'      => $mapped['number'],
                    'full_number' => $mapped['full_number'],
                    'cufe'        => $mapped['cufe'],
                    'dian_status' => $mapped['dian_status'],
                ]));
                // La factura original queda anulada por la nota crédito validada.
                $original->update(['status' => InvoiceStatus::CANCELLED->value]);
                return;
            }
            $note->markRejected($mapped['reason'] ?? 'Nota crédito rechazada.');
            return;
        }

        $status = (int) $result['status'];
        if ($status >= 400 && $status < 500) {
            $note->markRejected('Rechazo de Factus/DIAN (HTTP ' . $status . ').');
            return;
        }

        $note->markError('Error técnico nota crédito (HTTP ' . $status . ').');
        throw new RuntimeException('Factus credit-note failed (HTTP ' . $status . ') invoice=' . $note->id);
    }

    public function failed(Throwable $e): void
    {
        $note = ElectronicInvoice::find($this->creditNoteInvoiceId);
        $note?->markError('Reintentos agotados: ' . $e->getMessage());
    }
}
