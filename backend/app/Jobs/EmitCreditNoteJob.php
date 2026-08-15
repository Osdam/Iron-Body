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
        $this->tries = (int) config('billing.http.retry_times', 5);
        $this->backoff = (int) config('billing.http.retry_backoff', 60);

        // Carril de facturación. El nombre de la cola sale del mapa; el
        // `retry_after` lo pone la CONEXIÓN con la que arranca el worker, y esa
        // era la pieza rota: 90 s por defecto contra un timeout de 180 s. Con
        // un solo proceso no llegó a doler; con dos, un Factus lento habría
        // sido reservado por el segundo mientras el primero seguía emitiendo, y
        // eso son dos números fiscales consumidos por la misma venta.
        $lane = (array) config('queue.lanes.billing');
        $this->onQueue($lane['queue'] ?? 'billing');
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

        // 🔒 Servidor de producción con Factus en sandbox: no emitir (ver Emit job).
        if (app()->environment('production') && config('billing.env') !== 'production') {
            Log::warning('billing.refused_sandbox_on_production_server', ['credit_note' => $this->creditNoteInvoiceId]);

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

        /*
         * Una nota crédito ESPEJA el documento que anula. Su adquiriente y sus
         * líneas son los que se declararon ante la DIAN en la factura original,
         * no los que resultarían de resolver el origen HOY.
         *
         * Reconstruirlos desde el origen parecía equivalente y no lo es: entre
         * la emisión y la anulación puede cambiar el perfil fiscal del cliente,
         * y entonces la nota sale a nombre de otro. Ocurrió con IBFE10, emitida
         * a consumidor final: cuando se fue a anular, el perfil ya tenía NIT y
         * el payload reconstruido llevaba «901499742 / COSTRUMETALICA ROCHIS
         * S.A.S» para anular un documento de «222222222222 / CONSUMIDOR FINAL».
         *
         * El payload congelado de la original es la única fuente fiel. La
         * reconstrucción queda como respaldo para comprobantes anteriores al
         * congelado, y en ese caso la verificación de abajo hace de red.
         */
        $congelado = is_array($original->payload_snapshot) ? $original->payload_snapshot : [];

        if (isset($congelado['customer'], $congelado['items'], $congelado['payment_details'])) {
            $base = $congelado;
        } else {
            $built = $source instanceof ProductSale
                ? $builder->forSale($source, $resolver->resolveForSale($source))
                : $builder->forPayment($source, $resolver->resolveForPayment($source));
            $base = $built['payload'];

            Log::warning('billing.credit_note_rebuilt_payload', [
                'credit_note' => $note->id,
                'original' => $original->id,
                'motivo' => 'la factura original no tiene payload congelado',
            ]);
        }

        // 🔒 El adquiriente tiene que ser el mismo, venga de donde venga. Anular
        // a nombre de otro no es un detalle de formato: es un documento legal
        // atribuido a quien no corresponde.
        $documentoOriginal = (string) $original->customer_doc_number;
        $documentoNota = (string) ($base['customer']['identification'] ?? '');

        if ($documentoOriginal !== '' && $documentoOriginal !== $documentoNota) {
            $note->markError(sprintf(
                'Adquiriente incoherente: la factura %s se emitió a «%s» y la nota crédito '
                .'iba a enviarse a «%s». No se anula un documento a nombre de otro.',
                $original->full_number, $documentoOriginal, $documentoNota ?: '(vacío)',
            ));
            $invoicing->recordLog(
                $note,
                InvoiceLogAction::CREDIT_NOTE,
                'error',
                'Bloqueada: adquiriente distinto al de la factura original.',
            );

            return;
        }

        $payload = [
            'reference_code' => $note->uuid,
            'correction_concept_code' => (string) config('billing.credit_note.correction_concept_code', '2'),
            'customization_id' => (string) config('billing.credit_note.customization_id', '20'),
            'bill_number' => $original->full_number,
            'numbering_range_id' => (int) (config('billing.numbering.credit_range_id') ?: $note->numbering_range_id),
            'observation' => $note->failure_reason ?? 'Anulación',
            'cash_rounding_amount' => '0.00',
            'payment_details' => $base['payment_details'],
            'customer' => $base['customer'],
            'items' => $base['items'],
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
            // mapCreditNote, no map: en esta respuesta `data.bill` es la factura
            // que se anula. Con `map()` la nota se guardaba con el número y el
            // CUFE de la factura referenciada en vez de los suyos.
            $mapped = $mapper->mapCreditNote($result['body']);
            if ($mapped['is_validated']) {
                $files = $storage->store($note, $mapped);

                // Factus no devuelve PDF/XML en /validate: se descargan por
                // número, desde el endpoint de notas crédito. Sin esto la nota
                // quedaba sin copia privada — NC1 se emitió así.
                $numero = $mapped['full_number'] ?: $mapped['number'];
                if ($numero && empty($files['pdf_path'])) {
                    $files = array_merge($files, $storage->fetchAndStore($note, $client, (string) $numero));
                }
                $note->markValidated(array_merge($files, [
                    'factus_id' => $mapped['factus_id'],
                    'number' => $mapped['number'],
                    'full_number' => $mapped['full_number'],
                    'cufe' => $mapped['cufe'],
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
            $note->markRejected('Rechazo de Factus/DIAN (HTTP '.$status.').');

            return;
        }

        $note->markError('Error técnico nota crédito (HTTP '.$status.').');
        throw new RuntimeException('Factus credit-note failed (HTTP '.$status.') invoice='.$note->id);
    }

    public function failed(Throwable $e): void
    {
        $note = ElectronicInvoice::find($this->creditNoteInvoiceId);
        $note?->markError('Reintentos agotados: '.$e->getMessage());
    }
}
