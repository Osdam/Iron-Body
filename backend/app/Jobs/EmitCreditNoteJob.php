<?php

namespace App\Jobs;

use App\Enums\InvoiceLogAction;
use App\Enums\InvoiceType;
use App\Models\ElectronicInvoice;
use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\FactusPayloadSanitizer;
use App\Services\Billing\FactusResponseMapper;
use App\Services\Billing\InvoicePdfStorageService;
use App\Services\Billing\InvoicingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Emite una NOTA CRÉDITO (anulación/reembolso) en Factus.
 *
 * DISEÑADO para Fase 2: opera sobre un ElectronicInvoice de tipo credit_note ya
 * creado (con references_invoice_id apuntando a la factura original). El
 * cableado a ProductSale::cancel()/refund se hará en Fase 2; la estructura,
 * idempotencia y manejo de errores quedan listos aquí.
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
    ): void {
        if (! config('billing.enabled')) {
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
        if ($original === null || empty($original->cufe)) {
            $note->markError('Nota crédito sin factura original válida (CUFE).');
            return;
        }

        // El esquema exacto del payload de nota crédito (causal, referencia por
        // CUFE/número) se confirma contra la doc de Factus V2 (ver preguntas).
        $payload = [
            'numbering_range_id' => $note->numbering_range_id ?? config('billing.numbering.range_id'),
            'reference_code'     => $note->uuid,
            'bill_cufe'          => $original->cufe,
            'bill_number'        => $original->full_number,
            'reason'             => $note->failure_reason ?? 'Anulación',
            'customer'           => [
                'identification' => $note->customer_doc_number,
                'names'          => $note->customer_name,
                'email'          => $note->customer_email,
            ],
            'amount' => (float) $note->total,
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
                $original->update(['status' => \App\Enums\InvoiceStatus::CANCELLED->value]);
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
