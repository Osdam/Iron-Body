<?php

namespace App\Jobs;

use App\Enums\InvoiceLogAction;
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

/**
 * Reconciliación: consulta en Factus el estado de los comprobantes que quedaron
 * en 'processing' (emisión asíncrona o respuesta perdida) y los cierra como
 * validated/rejected. Vía oficial cuando Factus NO ofrece webhooks.
 */
class SyncFactusInvoiceStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

        ElectronicInvoice::query()
            ->processing()
            ->whereNotNull('factus_id')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (ElectronicInvoice $invoice) use ($client, $mapper, $sanitizer, $storage, $invoicing): void {
                $result = $client->getInvoice((string) $invoice->factus_id);

                $invoicing->recordLog(
                    $invoice,
                    InvoiceLogAction::SYNC,
                    $result['ok'] ? 'ok' : 'error',
                    $result['error'],
                    endpoint: 'bills/show',
                    httpStatus: $result['status'],
                    payloadExcerpt: $sanitizer->excerpt(['response' => $result['body']]),
                );

                if (! $result['ok']) {
                    return; // se reintenta en el próximo barrido
                }

                $mapped = $mapper->map($result['body']);
                if ($mapped['is_rejected']) {
                    $invoice->markRejected($mapped['reason'] ?? 'Rechazada por DIAN.');
                    return;
                }
                if ($mapped['is_validated']) {
                    $files = $storage->store($invoice, $mapped);
                    $invoice->markValidated(array_merge($files, [
                        'factus_id'   => $mapped['factus_id'] ?? $invoice->factus_id,
                        'number'      => $mapped['number'],
                        'full_number' => $mapped['full_number'],
                        'cufe'        => $mapped['cufe'],
                        'dian_status' => $mapped['dian_status'],
                        'qr_url'      => $mapped['qr_url'],
                        'qr_data'     => $mapped['qr_data'],
                    ]));
                }
            });
    }
}
