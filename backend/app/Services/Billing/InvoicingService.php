<?php

namespace App\Services\Billing;

use App\Enums\InvoiceLogAction;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Exceptions\ManualEmissionRejectedException;
use App\Jobs\EmitCreditNoteJob;
use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceLog;
use App\Models\Payment;
use App\Models\ProductSale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
        'payment' => Payment::class,
        'product_sale' => ProductSale::class,
    ];

    public function __construct(
        private FiscalProfileResolver $resolver,
        private InvoiceDtoBuilder $builder,
    ) {}

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
     * Emisión ADMINISTRATIVA desde el CRM por source_type amigable + source_id.
     *
     * Antes esto exigía que el cliente hubiese marcado la casilla al crear la
     * venta (`invoice_requested`). La intención era buena —la solicitud #18
     * nació de un botón que encolaba jobs condenados— pero la regla mezclaba un
     * hecho histórico («el cliente pidió factura al comprar») con una decisión
     * revisable («debe emitirse este documento»), y convertía un `false` del día
     * de la compra en una prohibición perpetua. En `/payments` ni siquiera
     * existía la casilla, así que NINGÚN pago de membresía podía facturarse
     * jamás, ni con el cliente delante pidiéndola.
     *
     * Ahora la vía administrativa es legítima y queda SELLADA en la solicitud
     * (`manual_authorization_at` + `created_by_admin_id`), que es lo que la
     * barrera de emisión lee después. `invoice_requested` no se toca: sigue
     * significando exactamente lo que significaba.
     *
     * Lo que NO cambia: sigue sin encolarse nada que esté condenado. Las
     * comprobaciones que antes hacía el job —cobro real, duplicados,
     * trazabilidad— se adelantan aquí para que el operador vea el motivo en
     * pantalla en vez de encontrarse una fila «pendiente» que no avanza.
     *
     * @param  bool  $force  Emitir aunque `auto_emit` esté apagado (siempre, en la vía manual).
     * @param  ?int  $adminId  Administrador autenticado que autoriza. Null en automatizaciones por token.
     * @param  ?bool  $finalConsumer  Decisión expresa de adquiriente; null = política automática.
     *
     * @throws InvalidArgumentException si el source_type no está permitido o el source no existe.
     * @throws ManualEmissionRejectedException si el pago no es facturable (422) o el estado lo impide (409).
     */
    public function manualEmit(
        string $sourceType,
        int $sourceId,
        bool $force = true,
        ?int $adminId = null,
        ?bool $finalConsumer = null,
    ): ?ElectronicInvoice {
        $class = self::SOURCE_MAP[$sourceType] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("source_type no soportado: {$sourceType}");
        }

        $model = $class::find($sourceId);
        if ($model === null) {
            throw new InvalidArgumentException("Fuente no encontrada: {$sourceType}#{$sourceId}");
        }

        $this->assertSourceIsPaid($model);
        $this->assertOriginIsTraceable($model);

        [$invoice, $debeDespachar] = DB::transaction(
            fn () => $this->claimForManualEmission($model, $adminId, $finalConsumer, $force)
        );

        // Fuera de la transacción y sólo si ESTA llamada reclamó la emisión:
        // dos clics seguidos comparten la fila bloqueada y sólo el primero
        // despacha (ver claimForManualEmission).
        if ($debeDespachar && config('billing.enabled')) {
            EmitElectronicInvoiceJob::dispatch($invoice->id)->onQueue($this->queue());
        }

        return $invoice;
    }

    /**
     * Ventana en la que un segundo despacho manual se considera un doble clic.
     *
     * La fila está bloqueada mientras se decide, así que dos peticiones
     * simultáneas se serializan y la segunda ve el intento de la primera. Pasado
     * este margen, un nuevo clic sí es una reintención deliberada del operador.
     */
    private const MANUAL_DISPATCH_DEBOUNCE_SECONDS = 60;

    /**
     * Reserva la emisión dentro de una transacción con la fila bloqueada.
     *
     * @return array{0: ElectronicInvoice, 1: bool} la solicitud y si hay que despachar
     */
    private function claimForManualEmission(Model $source, ?int $adminId, ?bool $finalConsumer, bool $force): array
    {
        $existente = ElectronicInvoice::where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('type', InvoiceType::INVOICE->value)
            ->lockForUpdate()
            ->first();

        if ($existente !== null) {
            $this->assertExistingIsEmittable($existente);
        }

        // Se resuelve el adquiriente ANTES de tocar nada: si la decisión es
        // «nominativa» y el perfil fiscal no sirve, esto lanza con el detalle
        // de lo que falta y no se ha modificado ni creado ninguna fila.
        $customer = $source instanceof ProductSale
            ? $this->resolver->resolveForSale($source, $finalConsumer)
            : $this->resolver->resolveForPayment($source, $finalConsumer);

        $built = $source instanceof ProductSale
            ? $this->builder->forSale($source, $customer)
            : $this->builder->forPayment($source, $customer);

        $invoice = $existente ?? ElectronicInvoice::create(array_merge($built['snapshot'], [
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'type' => InvoiceType::INVOICE->value,
            'status' => InvoiceStatus::PENDING->value,
            'numbering_range_id' => config('billing.numbering.range_id'),
            'prefix' => config('billing.numbering.prefix'),
            'payload_snapshot' => $built['payload'],
            'line_items_snapshot' => $built['line_items'] ?? null,
            'source_amount_snapshot' => self::sourceGrossAmount($source),
        ]));

        if ($existente === null) {
            $this->recordLog($invoice, InvoiceLogAction::ENQUEUE, 'ok', 'Factura encolada (pending) por autorización administrativa.');
        } else {
            $this->refreshBuyerSnapshot($invoice, $built);
        }

        $yaAutorizada = $invoice->isManuallyAuthorized();
        $intentoReciente = $invoice->last_attempt_at !== null
            && $invoice->last_attempt_at->gt(now()->subSeconds(self::MANUAL_DISPATCH_DEBOUNCE_SECONDS));

        $debeDespachar = $force && $invoice->status->canRetry() && ! $intentoReciente;

        $invoice->forceFill(array_filter([
            // La PRIMERA autorización es la que consta: un segundo clic no mueve
            // la fecha, igual que `marcarFacturaSolicitada` no mueve la del cliente.
            'manual_authorization_at' => $yaAutorizada ? null : now(),
            'created_by_admin_id' => $adminId ?? $invoice->created_by_admin_id,
            'manual_authorization_note' => $this->authorizationNote($finalConsumer),
            // Marca el intento dentro del lock: es lo que hace que el segundo
            // clic no despache un job duplicado.
            'last_attempt_at' => $debeDespachar ? now() : null,
        ], fn ($v) => $v !== null))->save();

        if (! $yaAutorizada) {
            $this->recordLog(
                $invoice,
                InvoiceLogAction::ENQUEUE,
                'ok',
                'Autorización administrativa registrada'.($adminId !== null ? " (admin #{$adminId})" : ' (automatización)').'.'
            );
        }

        return [$invoice, $debeDespachar];
    }

    /**
     * Rehace el snapshot del ADQUIRIENTE cuando la decisión actual difiere de la
     * congelada, y sólo mientras el documento no haya salido nunca.
     *
     * El payload se congela a propósito para que un cambio posterior de precio
     * no altere un comprobante pendiente. Pero congelar «a quién se factura» no
     * protege de nada: en producción hay una solicitud pendiente cuyo snapshot
     * apunta a una empresa con el NIT guardado con espacios, y emitirla tal cual
     * sería mandar eso a la DIAN. Corregir el adquiriente antes de la primera
     * transmisión es exactamente lo que el operador está decidiendo en el modal.
     *
     * Los IMPORTES no se tocan: si el total reconstruido no coincide con el
     * congelado, se aborta en vez de reescribirlo.
     *
     * @param  array{snapshot: array<string,mixed>, payload: array<string,mixed>, line_items?: array}  $built
     */
    private function refreshBuyerSnapshot(ElectronicInvoice $invoice, array $built): void
    {
        if ($invoice->hasBeenTransmitted()) {
            return; // Un documento ya emitido no se reescribe jamás.
        }

        $nuevo = $built['snapshot'];

        $mismoAdquiriente = (bool) $invoice->is_final_consumer === (bool) ($nuevo['is_final_consumer'] ?? false)
            && (string) $invoice->customer_doc_number === (string) ($nuevo['customer_doc_number'] ?? '')
            && (string) $invoice->customer_doc_type === (string) ($nuevo['customer_doc_type'] ?? '');

        if ($mismoAdquiriente) {
            return;
        }

        $totalCongelado = round((float) $invoice->total, 2);
        $totalNuevo = round((float) ($nuevo['total'] ?? 0), 2);

        if (abs($totalCongelado - $totalNuevo) > 0.01) {
            throw ManualEmissionRejectedException::noFacturable(sprintf(
                'Cambiar el adquiriente alteraría el importe del comprobante (%s → %s). '
                .'No se emite: revisa el pago antes de continuar.',
                number_format($totalCongelado, 2), number_format($totalNuevo, 2),
            ));
        }

        $anterior = $invoice->is_final_consumer ? 'consumidor final' : (string) $invoice->customer_name;

        $invoice->forceFill(array_merge(
            // Sólo el bloque del adquiriente + el payload que lo contiene.
            array_intersect_key($nuevo, array_flip([
                'customer_doc_type', 'customer_doc_number', 'customer_dv', 'customer_name',
                'customer_email', 'customer_phone', 'customer_address',
                'customer_city_code', 'customer_department_code', 'is_final_consumer',
            ])),
            [
                'payload_snapshot' => $built['payload'],
                'line_items_snapshot' => $built['line_items'] ?? $invoice->line_items_snapshot,
            ]
        ))->save();

        $this->recordLog(
            $invoice,
            InvoiceLogAction::ENQUEUE,
            'ok',
            sprintf(
                'Adquiriente actualizado antes de emitir: «%s» → «%s». Importes sin cambios (%s).',
                $anterior,
                $invoice->is_final_consumer ? 'consumidor final' : (string) $invoice->customer_name,
                number_format($totalCongelado, 2),
            )
        );
    }

    private function authorizationNote(?bool $finalConsumer): string
    {
        return match ($finalConsumer) {
            true => 'Emisión manual desde el CRM · adquiriente: consumidor final',
            false => 'Emisión manual desde el CRM · adquiriente: perfil fiscal del cliente',
            default => 'Emisión manual desde el CRM · adquiriente: según perfil fiscal disponible',
        };
    }

    /**
     * Sólo se factura lo efectivamente cobrado.
     *
     * `product_sales` tiene dos estados: `status` es el ciclo de vida de la venta
     * y `payment_status` es si el dinero entró. Manda el segundo cuando existe,
     * igual que en PaymentOriginInspector.
     */
    private function assertSourceIsPaid(Model $source): void
    {
        $estado = $source->payment_status ?? $source->status ?? null;
        $estado = $estado instanceof \BackedEnum ? (string) $estado->value : (string) $estado;

        if (strtolower($estado) === 'paid') {
            return;
        }

        throw ManualEmissionRejectedException::noFacturable(sprintf(
            'Este %s está en estado «%s», no «pagado». Sólo se factura lo efectivamente cobrado.',
            $source instanceof ProductSale ? 'venta' : 'pago',
            $estado !== '' ? $estado : 'desconocido',
        ));
    }

    /**
     * El dinero tiene que poder rastrearse. Se comprueba ANTES de crear la
     * solicitud para no dejar filas «pendientes» que nadie va a poder emitir.
     */
    private function assertOriginIsTraceable(Model $source): void
    {
        $origen = app(PaymentOriginInspector::class)->inspectSource($source);

        if ($origen['is_sandbox']) {
            throw ManualEmissionRejectedException::noFacturable(sprintf(
                'El pago proviene de una transacción en ambiente «%s». Un pago de sandbox '
                .'no movió dinero: facturarlo declararía una venta que no existió.',
                $origen['environment'],
            ));
        }

        if ($origen['is_test_card']) {
            throw ManualEmissionRejectedException::noFacturable(sprintf(
                'El pago proviene de una tarjeta de prueba (terminada en %s). No se factura.',
                $origen['card_last_four'],
            ));
        }

        if (! $origen['has_verifiable_reference']) {
            throw ManualEmissionRejectedException::noFacturable(
                'El pago no tiene una referencia verificable: su método indica pasarela '
                .'pero no existe la transacción que lo respalde. No se factura lo que no se puede rastrear.'
            );
        }
    }

    /**
     * ¿La solicitud existente admite una emisión ahora?
     *
     * Es la misma familia de comprobaciones que hace InvoiceEmissionGuard justo
     * antes del POST; se adelantan para poder responder al operador con un
     * código HTTP que distinga «corrige algo» de «mira la factura que ya hay».
     */
    private function assertExistingIsEmittable(ElectronicInvoice $invoice): void
    {
        if ($invoice->hasBeenTransmitted()) {
            throw ManualEmissionRejectedException::conflicto(sprintf(
                'Este pago ya fue facturado en el documento %s (solicitud #%d). '
                .'Emitirlo de nuevo duplicaría la factura ante la DIAN; para anularlo, usa una nota crédito.',
                $invoice->full_number ?: 'con CUFE',
                $invoice->id,
            ));
        }

        if ($invoice->status->isProcessing()) {
            throw ManualEmissionRejectedException::conflicto(sprintf(
                'La solicitud #%d ya tiene una emisión en curso. Espera a que termine antes de reintentar.',
                $invoice->id,
            ));
        }

        // Antes que `isFinal()`, que también incluye CANCELLED: el motivo de una
        // cancelación es lo primero que el operador necesita leer.
        if ($invoice->status === InvoiceStatus::CANCELLED) {
            throw ManualEmissionRejectedException::noFacturable(sprintf(
                'La solicitud #%d está cancelada%s. Una solicitud cancelada no se reemite.',
                $invoice->id,
                $invoice->cancellation_reason ? " (motivo: {$invoice->cancellation_reason})" : '',
            ));
        }

        if ($invoice->status->isFinal()) {
            throw ManualEmissionRejectedException::conflicto(sprintf(
                'La solicitud #%d está en estado «%s» y no admite una nueva emisión.',
                $invoice->id, $invoice->status->value,
            ));
        }

        // `retry_allowed = false` marca lo que se decidió que nunca debía
        // facturarse. Esa decisión no se revierte desde el botón.
        if ($invoice->retry_allowed !== null && ! $invoice->retry_allowed) {
            throw ManualEmissionRejectedException::noFacturable(sprintf(
                'La solicitud #%d tiene los reintentos deshabilitados%s.',
                $invoice->id,
                $invoice->cancellation_reason ? " (motivo: {$invoice->cancellation_reason})" : '',
            ));
        }

        if ($invoice->reconciliationFailed()) {
            throw ManualEmissionRejectedException::noFacturable(sprintf(
                'La solicitud #%d tiene un descuadre de conciliación sin resolver. '
                .'Corrige el origen del pago antes de emitir.',
                $invoice->id,
            ));
        }

        if ($invoice->status === InvoiceStatus::REJECTED) {
            throw ManualEmissionRejectedException::noFacturable(sprintf(
                'La solicitud #%d fue rechazada por el proveedor: %s. Reintentarla exige autorización explícita.',
                $invoice->id, $invoice->failure_reason ?: 'sin motivo registrado',
            ));
        }

        // Otra fila apuntando al mismo origen que ya consumió consecutivo.
        $duplicada = ElectronicInvoice::where('source_type', $invoice->source_type)
            ->where('source_id', $invoice->source_id)
            ->where('type', $invoice->type)
            ->where('id', '!=', $invoice->id)
            ->whereNotNull('full_number')
            ->first();

        if ($duplicada !== null) {
            throw ManualEmissionRejectedException::conflicto(sprintf(
                'Este pago ya fue facturado en el documento %s (solicitud #%d).',
                $duplicada->full_number, $duplicada->id,
            ));
        }
    }

    /**
     * Reintenta una factura en estado error/pending. Solo si el flag está activo.
     */
    public function retry(ElectronicInvoice $invoice): bool
    {
        if (! $invoice->status->canRetry() || ! config('billing.enabled')) {
            return false;
        }

        // 🔒 Un descuadre NO se reintenta a ciegas: el payload congelado sigue
        // siendo el mismo y volvería a fallar el guardarraíl. Primero hay que
        // corregir el origen (pago/venta) y regenerar el snapshot.
        if ($invoice->reconciliationFailed()) {
            $this->recordLog(
                $invoice,
                InvoiceLogAction::RETRY,
                'error',
                'Reintento bloqueado: la factura tiene un descuadre de conciliación sin resolver.'
            );

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
                'source_id' => $original->source_id,
                'type' => InvoiceType::CREDIT_NOTE->value,
            ],
            [
                'status' => InvoiceStatus::CREDIT_NOTE_PENDING->value,
                'references_invoice_id' => $original->id,
                'numbering_range_id' => config('billing.numbering.range_id'),
                'prefix' => config('billing.numbering.prefix'),
                // La causal/razón la consume EmitCreditNoteJob (campo failure_reason).
                'failure_reason' => $reason,
                // Snapshot del adquiriente + montos copiados de la original.
                'customer_doc_type' => $original->customer_doc_type,
                'customer_doc_number' => $original->customer_doc_number,
                'customer_dv' => $original->customer_dv,
                'customer_name' => $original->customer_name,
                'customer_email' => $original->customer_email,
                'customer_phone' => $original->customer_phone,
                'customer_address' => $original->customer_address,
                'customer_city_code' => $original->customer_city_code,
                'customer_department_code' => $original->customer_department_code,
                'is_final_consumer' => $original->is_final_consumer,
                'currency' => $original->currency,
                'subtotal' => $original->subtotal,
                'discount' => $original->discount,
                'tax_total' => $original->tax_total,
                'total' => $original->total,
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
                    'source_id' => $source->getKey(),
                    'type' => $type->value,
                ],
                array_merge($built['snapshot'], [
                    'status' => InvoiceStatus::PENDING->value,
                    'numbering_range_id' => config('billing.numbering.range_id'),
                    'prefix' => config('billing.numbering.prefix'),
                    // Payload CONGELADO desde el primer momento: los reintentos y
                    // la emisión lo reutilizan literalmente, de modo que un cambio
                    // posterior de precio o de tarifa no puede alterar el
                    // comprobante. El reference_code lo fija el job (uuid).
                    'payload_snapshot' => $built['payload'],
                    'line_items_snapshot' => $built['line_items'] ?? null,
                    'source_amount_snapshot' => self::sourceGrossAmount($source),
                ])
            );

            if ($invoice->wasRecentlyCreated) {
                $this->recordLog($invoice, InvoiceLogAction::ENQUEUE, 'ok', 'Factura encolada (pending).');
            }

            // Despacho a Factus SOLO si:
            //   - es manual (force: el cliente la solicitó), o
            //   - la emisión automática de ese origen está activada (auto_emit).
            // El comprobante 'pending' SIEMPRE queda creado como evidencia.
            $shouldDispatch = config('billing.enabled')
                && ($invoice->wasRecentlyCreated || ($force && $invoice->status->canRetry()))
                && ($force || $this->autoEmitEnabled($source));

            if ($shouldDispatch) {
                EmitElectronicInvoiceJob::dispatch($invoice->id)->onQueue($this->queue());
            }

            return $invoice;
        } catch (Throwable $e) {
            // Best-effort: el pago no debe fallar por la facturación.
            Log::warning('billing.enqueue_failed', [
                'source' => $source->getMorphClass().':'.$source->getKey(),
                'type' => $type->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** ¿La emisión automática está activada para el origen (membresía vs POS)? */
    private function autoEmitEnabled(Model $source): bool
    {
        return $source instanceof ProductSale
            ? (bool) config('billing.auto_emit.product_sales')
            : (bool) config('billing.auto_emit.memberships');
    }

    /**
     * Total BRUTO congelado del origen — la referencia contra la que se concilia
     * el comprobante antes de emitir.
     *
     * Con snapshot Pricing V2 usa `gross_amount`; sin él cae al importe legacy
     * (`payments.amount` / `product_sales.total`), que siempre fue el bruto
     * efectivamente cobrado.
     */
    public static function sourceGrossAmount(Model $source): ?float
    {
        if ($source instanceof ProductSale) {
            return $source->grossAmountValue();
        }
        if ($source instanceof Payment) {
            return $source->grossAmountValue();
        }

        return null;
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
            'action' => $action->value,
            'endpoint' => $endpoint,
            'http_status' => $httpStatus,
            'result' => $result,
            'message' => $message,
            'payload_excerpt' => $payloadExcerpt ?: null,
            'duration_ms' => $durationMs,
        ]);
    }

    private function queue(): string
    {
        return (string) config('billing.queue', 'billing');
    }
}
