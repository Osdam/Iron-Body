<?php

namespace App\Jobs;

use App\Enums\InvoiceLogAction;
use App\Enums\InvoiceStatus;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\Factus\FactusConfigValidator;
use App\Services\Billing\FactusPayloadSanitizer;
use App\Services\Billing\FactusResponseMapper;
use App\Services\Billing\FiscalProfileResolver;
use App\Services\Billing\InvoiceDtoBuilder;
use App\Services\Billing\InvoiceEmissionGuard;
use App\Services\Billing\InvoicePdfStorageService;
use App\Services\Billing\InvoiceReconciler;
use App\Services\Billing\InvoicingService;
use App\Services\Billing\TaxPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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

    /**
     * Recorre el payload y verifica que ninguna línea lleve impuesto.
     *
     * Con el emisor no responsable, cada item debe declararse sin tributo. Una
     * tarifa positiva o un `rate` distinto de cero aborta la emisión.
     */
    private function assertPayloadHasNoVat(array $payload, TaxPolicy $policy, string $ref): void
    {
        if ($policy->collectsVat()) {
            return;
        }

        foreach (($payload['items'] ?? []) as $i => $item) {
            foreach (($item['taxes'] ?? []) as $tax) {
                $rate = (float) ($tax['rate'] ?? 0);
                if ($rate > 0) {
                    throw new RuntimeException(sprintf(
                        'Bloqueo tributario: la factura %s lleva tarifa %.2f%% en el ítem %d. '
                        .'Iron Body es responsabilidad %s (no responsable de IVA).',
                        $ref, $rate, $i + 1, $policy->issuerVatResponsibility(),
                    ));
                }
            }
        }
    }

    public function handle(
        FactusClient $client,
        InvoiceDtoBuilder $builder,
        FiscalProfileResolver $resolver,
        FactusResponseMapper $mapper,
        FactusPayloadSanitizer $sanitizer,
        InvoicePdfStorageService $storage,
        InvoicingService $invoicing,
        InvoiceReconciler $reconciler,
    ): void {
        if (! config('billing.enabled')) {
            return; // Seguridad: jamás emitir con el módulo apagado.
        }

        // 🔒 Servidor de producción con Factus en sandbox: NUNCA emitir
        // comprobantes de prueba para ventas reales. La factura queda 'pending'.
        // (En local/sandbox/testing no aplica → no rompe smoke ni tests.)
        if (app()->environment('production') && config('billing.env') !== 'production') {
            Log::warning('billing.refused_sandbox_on_production_server', ['invoice' => $this->invoiceId]);

            return;
        }

        // 🔒 En producción, no emitir si la configuración no está lista
        // (rangos, municipio, datos del emisor, decisión tributaria). La
        // factura queda 'pending' hasta corregir. En sandbox no aplica.
        if (config('billing.env') === 'production'
            && ! FactusConfigValidator::fromConfig()->isReadyForProduction()) {
            Log::warning('billing.production_not_ready', ['invoice' => $this->invoiceId]);

            return;
        }

        $invoice = ElectronicInvoice::find($this->invoiceId);
        if ($invoice === null || $invoice->status->isFinal() || ! $invoice->status->canRetry()) {
            return; // Idempotencia: ya validada / en curso / inexistente.
        }

        $source = $invoice->source; // morphTo: Payment | ProductSale

        // PAYLOAD CONGELADO: si el comprobante ya lo tiene, se reutiliza tal cual.
        // Es lo que garantiza que un reintento envíe exactamente lo mismo y que
        // un cambio posterior de precio o de tarifa no altere una factura
        // pendiente. Solo se reconstruye si nunca se congeló (facturas creadas
        // antes de Pricing V2 y aún sin pasar por billing:freeze-pending-invoices).
        if ($invoice->hasPayloadSnapshot()) {
            $payload = $invoice->payload_snapshot;
        } else {
            if ($source === null) {
                $invoice->markError('Fuente del comprobante no encontrada.');

                return;
            }

            $built = $source instanceof ProductSale
                ? $builder->forSale($source, $resolver->resolveForSale($source))
                : $builder->forPayment($source, $resolver->resolveForPayment($source));

            $payload = $built['payload'];

            // Se congela ahora para que los reintentos siguientes lo reutilicen.
            $invoice->forceFill([
                'payload_snapshot' => $payload,
                'line_items_snapshot' => $built['line_items'] ?? null,
                'source_amount_snapshot' => InvoicingService::sourceGrossAmount($source),
            ])->save();
        }

        $payload['reference_code'] = $invoice->uuid; // trazabilidad CRM ↔ Factus

        // 🔒 GUARDARRAÍL: nunca emitir por un importe distinto al cobrado.
        $reconciliation = $reconciler->check($invoice, $source);
        if (! $reconciliation['ok']) {
            $invoice->markReconciliationFailed(
                (float) $reconciliation['source_amount'],
                (float) $reconciliation['difference'],
                (string) $reconciliation['reason'],
            );
            $invoicing->recordLog(
                $invoice,
                InvoiceLogAction::EMIT,
                'error',
                (string) $reconciliation['reason'],
                payloadExcerpt: $sanitizer->excerpt([
                    'reconciliation' => [
                        'invoice_total' => (float) $invoice->total,
                        'source_amount' => $reconciliation['source_amount'],
                        'difference' => $reconciliation['difference'],
                    ],
                ]),
            );
            Log::warning('billing.reconciliation_failed', [
                'invoice_id' => $invoice->id,
                'difference' => $reconciliation['difference'],
            ]);

            // No se relanza: reintentar a ciegas volvería a fallar igual.
            return;
        }

        if (! ($reconciliation['skipped'] ?? false)) {
            $invoice->markReconciliationOk(
                (float) $reconciliation['source_amount'],
                (float) $reconciliation['difference'],
            );
        }

        // Auditoría del envío nativo de Factus al correo del cliente. Sin datos
        // sensibles: solo si se solicitó el envío y si el cliente tenía email válido.
        Log::info('billing.invoice_email', [
            'invoice_id' => $invoice->id,
            'reference_code' => $invoice->uuid,
            'email_requested' => (bool) ($payload['send_email'] ?? false),
            // Del comprobante persistido, NO de `$built`: esa variable sólo
            // existe en la rama que reconstruye el payload, así que en el camino
            // normal —factura con snapshot congelado— estaba sin definir y el
            // campo salía siempre en false.
            'has_customer_email' => InvoiceDtoBuilder::hasValidEmail($invoice->customer_email),
        ]);

        // request_payload SANEADO y persistido ANTES de enviarlo: si la llamada
        // falla o el proceso muere, queda la evidencia exacta de lo que se iba a
        // transmitir. Las 17 facturas previas no lo guardaron y eso impidió
        // reconstruir por qué se emitió con IVA.
        $invoice->forceFill([
            'request_payload' => app(FactusPayloadSanitizer::class)->sanitize($payload),
        ])->save();

        // ── BARRERAS NO REINTENTABLES, ANTES DE TOCAR EL ESTADO ──────────────
        //
        // Se ejecutan aquí, y no dentro del POST, por un caso real: la solicitud
        // #18 (venta V-000003) quedó ATASCADA EN `processing` para siempre. El
        // job marcaba processing y sólo entonces la barrera de emisión rechazaba
        // el documento; al reintentar, la guarda de idempotencia veía
        // `processing` —que no está en canRetry()— y hacía `return`. La cola daba
        // el job por bueno, `failed()` no se llamaba nunca y el estado terminal
        // no se escribía jamás: ni número, ni error, ni motivo.
        //
        // Un rechazo tributario, una venta sin solicitud del cliente o unos datos
        // fiscales incompletos NO se reintentan: volverían a fallar igual. Se
        // marca `error` con el motivo real y se termina sin relanzar.
        $taxPolicy = app(TaxPolicy::class);
        try {
            // Origen económico, solicitud expresa, ambiente, duplicados.
            app(InvoiceEmissionGuard::class)->assertMayEmit($payload);

            // Doble comprobación tributaria: total del comprobante y cada línea.
            // El total de impuesto se lee del COMPROBANTE, que es el snapshot
            // fiscal congelado y existe en las dos ramas. Antes salía de
            // `$built`, definida sólo cuando el payload se reconstruye: en el
            // camino normal la variable no existía, el operando caía al `?? 0`
            // y esta comprobación pasaba siempre pasara lo que pasara. La de
            // cada línea (assertPayloadHasNoVat) sí funcionaba; ésta, no.
            $taxPolicy->assertNoVatAmount($invoice->tax_total ?? 0, 'factura '.$invoice->uuid);
            $this->assertPayloadHasNoVat($payload, $taxPolicy, $invoice->uuid);
        } catch (Throwable $e) {
            $invoice->markError($e->getMessage());
            $invoicing->recordLog(
                $invoice,
                InvoiceLogAction::EMIT,
                'error',
                $e->getMessage(),
            );
            Log::warning('billing.emission_blocked', [
                'invoice_id' => $invoice->id,
                'reason' => $e->getMessage(),
            ]);

            return; // Deliberado: reintentar a ciegas repetiría el mismo rechazo.
        }

        // A partir de aquí sólo quedan fallos TÉCNICOS (red, 5xx, proceso muerto),
        // que sí merecen backoff. `processing` se marca inmediatamente antes del
        // POST y nunca sin salida: el catch escribe el estado terminal.
        $invoice->markProcessing();

        $startedAt = microtime(true);
        try {
            $result = $client->createInvoice($payload);
        } catch (Throwable $e) {
            // Sin esto, una excepción aquí dejaba la solicitud en `processing`
            // y el reintento se rendía en silencio contra ese mismo estado.
            $invoice->markError('Fallo al emitir: '.$e->getMessage());
            $invoicing->recordLog(
                $invoice,
                InvoiceLogAction::EMIT,
                'error',
                $e->getMessage(),
                endpoint: 'bills/validate',
            );

            throw $e; // Técnico: la cola reintenta con backoff desde `error`.
        }
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $invoicing->recordLog(
            $invoice,
            InvoiceLogAction::EMIT,
            $result['ok'] ? 'ok' : 'error',
            $result['error'],
            endpoint: 'bills/validate',
            httpStatus: $result['status'],
            payloadExcerpt: $sanitizer->excerpt([
                'request' => $payload,
                'response' => $result['body'],
            ]),
            durationMs: $durationMs,
        );

        if ($result['ok']) {
            $this->applySuccess($invoice, $mapper->map($result['body']), $storage, $client);
            $this->maybeQueueCustomerEmail($invoice, $invoicing);

            return;
        }

        // No-2xx: distinguir rechazo de datos (no reintentar) de fallo técnico.
        $status = (int) $result['status'];
        if ($status >= 400 && $status < 500) {
            $invoice->markRejected('Rechazo de Factus/DIAN (HTTP '.$status.').');

            return;
        }

        // Técnico (5xx / red / 0): marcar error y relanzar para backoff.
        $invoice->markError('Error técnico al emitir (HTTP '.$status.').');
        throw new RuntimeException('Factus emit failed (HTTP '.$status.') invoice='.$invoice->id);
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

        /*
         * Un 2xx no basta para dar por validado un documento fiscal.
         *
         * El CUFE y el número son lo que hace que una factura EXISTA ante la
         * DIAN: el primero la identifica de forma única, el segundo consume un
         * consecutivo del rango autorizado. Una respuesta correcta a la que le
         * falten es la clase de fallo que más se parece a un éxito —código 201,
         * cuerpo presente, ningún error a la vista— y la que peor termina:
         * `validated` es TERMINAL, así que el comprobante nunca se reintentaría
         * y el panel afirmaría tener un documento que no se puede defender en
         * una inspección.
         *
         * Queda en `error`, que sí es recuperable, con el motivo escrito.
         */
        if (empty($mapped['cufe']) || empty($mapped['number'])) {
            $invoice->markError(
                'Factus respondió sin los campos que identifican el documento'
                .' (CUFE: '.($mapped['cufe'] ? 'sí' : 'no')
                .', número: '.($mapped['number'] ? 'sí' : 'no').').'
                .' No se marca validada: requiere comprobación en Factus antes de reintentar.',
            );

            Log::warning('billing.incomplete_validation_response', [
                'invoice_id' => $invoice->id,
                'has_cufe' => ! empty($mapped['cufe']),
                'has_number' => ! empty($mapped['number']),
                'dian_status' => $mapped['dian_status'],
            ]);

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
            'factus_id' => $mapped['factus_id'],
            'number' => $mapped['number'],
            'prefix' => $mapped['prefix'] ?? $invoice->prefix,
            'full_number' => $mapped['full_number'],
            'cufe' => $mapped['cufe'],
            'dian_status' => $mapped['dian_status'],
            'qr_url' => $mapped['qr_url'],
            'qr_data' => $mapped['qr_data'],
        ]));
    }

    /**
     * Encola el envío PROPIO (SMTP) del comprobante al cliente como fallback al
     * envío nativo de Factus. Solo si: el flag está activo, la factura quedó
     * efectivamente 'validated' (no nota crédito) y el cliente tiene email
     * válido. Best-effort: NO afecta la emisión ya completada.
     */
    private function maybeQueueCustomerEmail(ElectronicInvoice $invoice, InvoicingService $invoicing): void
    {
        if (! config('billing.customer_email_delivery.enabled')) {
            return;
        }
        if ($invoice->status !== InvoiceStatus::VALIDATED) {
            return; // Solo facturas validadas (rechazos / notas crédito quedan fuera).
        }
        if (! InvoiceDtoBuilder::hasValidEmail($invoice->customer_email)) {
            return; // Sin email válido no se intenta (consumidor final, etc.).
        }
        if ($invoice->customerEmailAlreadySent()) {
            return; // Idempotencia: ya se envió antes.
        }

        $invoice->forceFill(['customer_email_status' => 'queued'])->save();
        $invoicing->recordLog(
            $invoice,
            InvoiceLogAction::EMAIL_QUEUED,
            'ok',
            'Envío del comprobante al cliente encolado.',
        );

        SendElectronicInvoiceEmailJob::dispatch($invoice->id);
    }

    /** Número fiscal real de Factus (p. ej. SETP990006967), no el uuid interno. */
    private function isRealNumber(string $n): bool
    {
        return $n !== '' && ! str_contains($n, '-');
    }

    /**
     * La cola agotó los reintentos: dejar constancia dura del error.
     *
     * Saca la solicitud de `processing` sin condiciones. `markError()` no
     * consulta el estado previo, así que es la última red que garantiza que
     * ninguna solicitud se quede colgada en un estado no reintentable — el
     * defecto que dejó la #18 atascada indefinidamente.
     *
     * Nunca toca una factura ya VALIDADA: si el fallo ocurrió después de que
     * Factus la aceptara, el documento fiscal existe y su estado manda.
     */
    public function failed(Throwable $e): void
    {
        $invoice = ElectronicInvoice::find($this->invoiceId);

        if ($invoice === null || $invoice->status->isFinal()) {
            return;
        }

        $invoice->markError('Reintentos agotados: '.$e->getMessage());
    }
}
