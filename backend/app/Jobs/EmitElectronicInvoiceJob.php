<?php

namespace App\Jobs;

use App\Enums\InvoiceLogAction;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Services\Billing\Factus\FactusClient;
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
use RuntimeException;
use Throwable;

/**
 * Emite una factura electrónica en Factus. Idempotente y best-effort:
 *
 *  - Guarda de seguridad: si el flag está apagado, la factura ya quedó validada,
 *    o no está en estado emitible, no hace nada.
 *  - Errores TÉCNICOS (red/5xx) → markError + relanza para que la cola reintente
 *    con backoff (config('billing.http.retry_*')).
 *  - Rechazos de DATOS (4xx/validación o DIAN) → markRejected SIN relanzar
 *    (requiere corrección manual; reintentar a ciegas no sirve).
 *
 * Nunca persiste secretos: los logs guardan solo extractos saneados.
 */
class EmitElectronicInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $backoff;

    public function __construct(public int $invoiceId)
    {
        $this->tries   = (int) config('billing.http.retry_times', 5);
        $this->backoff = (int) config('billing.http.retry_backoff', 60);
    }

    public function handle(
        FactusClient $client,
        InvoiceDtoBuilder $builder,
        FiscalProfileResolver $resolver,
        FactusResponseMapper $mapper,
        FactusPayloadSanitizer $sanitizer,
        InvoicePdfStorageService $storage,
        InvoicingService $invoicing,
    ): void {
        if (! config('billing.enabled')) {
            return; // Seguridad: jamás emitir con el módulo apagado.
        }

        $invoice = ElectronicInvoice::find($this->invoiceId);
        if ($invoice === null || $invoice->status->isFinal() || ! $invoice->status->canRetry()) {
            return; // Idempotencia: ya validada / en curso / inexistente.
        }

        $source = $invoice->source; // morphTo: Payment | ProductSale
        if ($source === null) {
            $invoice->markError('Fuente del comprobante no encontrada.');
            return;
        }

        $built = $source instanceof ProductSale
            ? $builder->forSale($source, $resolver->resolveForSale($source))
            : $builder->forPayment($source, $resolver->resolveForPayment($source));

        $payload = $built['payload'];
        $payload['reference_code'] = $invoice->uuid; // trazabilidad CRM ↔ Factus

        $invoice->markProcessing();

        $startedAt = microtime(true);
        $result = $client->createInvoice($payload);
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $invoicing->recordLog(
            $invoice,
            InvoiceLogAction::EMIT,
            $result['ok'] ? 'ok' : 'error',
            $result['error'],
            endpoint: 'bills/validate',
            httpStatus: $result['status'],
            payloadExcerpt: $sanitizer->excerpt([
                'request'  => $payload,
                'response' => $result['body'],
            ]),
            durationMs: $durationMs,
        );

        if ($result['ok']) {
            $this->applySuccess($invoice, $mapper->map($result['body']), $storage, $client);
            return;
        }

        // No-2xx: distinguir rechazo de datos (no reintentar) de fallo técnico.
        $status = (int) $result['status'];
        if ($status >= 400 && $status < 500) {
            $invoice->markRejected('Rechazo de Factus/DIAN (HTTP ' . $status . ').');
            return;
        }

        // Técnico (5xx / red / 0): marcar error y relanzar para backoff.
        $invoice->markError('Error técnico al emitir (HTTP ' . $status . ').');
        throw new RuntimeException('Factus emit failed (HTTP ' . $status . ') invoice=' . $invoice->id);
    }

    /** Persiste número/CUFE/QR/archivos y marca validado o rechazado. */
    private function applySuccess(
        ElectronicInvoice $invoice,
        array $mapped,
        InvoicePdfStorageService $storage,
        FactusClient $client,
    ): void {
        if ($mapped['is_rejected']) {
            $invoice->markRejected($mapped['reason'] ?? 'Rechazada por DIAN.');
            return;
        }

        // Archivos del create (si vinieron inline).
        $files = $storage->store($invoice, $mapped);

        // Factus V2 NO devuelve PDF/XML en /validate: se descargan por número.
        $number = $mapped['full_number'] ?: $mapped['number'];
        // Aunque el create traiga un public_url, guardamos también una copia
        // privada descargando el archivo por su número fiscal real.
        if ($number && $this->isRealNumber((string) $number) && empty($files['pdf_path'])) {
            $files = array_merge($files, $storage->fetchAndStore($invoice, $client, (string) $number));
        }

        $invoice->markValidated(array_merge($files, [
            'factus_id'   => $mapped['factus_id'],
            'number'      => $mapped['number'],
            'prefix'      => $mapped['prefix'] ?? $invoice->prefix,
            'full_number' => $mapped['full_number'],
            'cufe'        => $mapped['cufe'],
            'dian_status' => $mapped['dian_status'],
            'qr_url'      => $mapped['qr_url'],
            'qr_data'     => $mapped['qr_data'],
        ]));
    }


    /** Número fiscal real de Factus (p. ej. SETP990006967), no el uuid interno. */
    private function isRealNumber(string $n): bool
    {
        return $n !== '' && ! str_contains($n, '-');
    }

    /** La cola agotó los reintentos: dejar constancia dura del error. */
    public function failed(Throwable $e): void
    {
        $invoice = ElectronicInvoice::find($this->invoiceId);
        $invoice?->markError('Reintentos agotados: ' . $e->getMessage());
    }
}
