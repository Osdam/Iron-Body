<?php

namespace App\Services\Billing;

use App\Models\ElectronicInvoice;
use App\Services\Billing\Factus\FactusClient;
use RuntimeException;

/**
 * Última verificación antes de que un documento fiscal salga a la red.
 *
 * Se ejecuta desde {@see FactusClient}, no desde el
 * servicio o el job que originan la emisión, por una razón concreta: en este
 * sistema hay al menos cuatro caminos que pueden disparar una factura
 * —`InvoicingService`, `EmitElectronicInvoiceJob` y sus reintentos,
 * `WompiTransactionService` y `PaymentMembershipActivator`, estos dos últimos
 * forzándola sin consultar `auto_emit`— y una comprobación repartida entre
 * ellos solo es tan fuerte como el camino que alguien olvidó actualizar.
 *
 * Cada regla existe por un hecho verificado en producción, no por hipótesis:
 * se emitieron documentos fiscales reales ante la DIAN (IBFE7, IBFE8) a partir
 * de transacciones de Wompi en SANDBOX con tarjeta de prueba 4242.
 *
 * Aborta en vez de corregir: una factura electrónica es un documento legal y no
 * admite arreglos silenciosos.
 */
class InvoiceEmissionGuard
{
    public function __construct(private PaymentOriginInspector $inspector) {}

    /**
     * @param  array<string,mixed>  $payload
     *
     * @throws RuntimeException
     */
    public function assertMayEmit(array $payload): void
    {
        if (! config('tax_policy.emission_guard_enabled', true)) {
            return;
        }

        $this->assertEndpointMatchesEnvironment();

        $reference = (string) ($payload['reference_code'] ?? '');

        // Sondas explícitas de sandbox (comando de prueba): no hay factura en la
        // base porque no representan una venta. Solo se admiten si el ambiente
        // completo es sandbox, cosa que ya verificó el método anterior.
        if (str_starts_with($reference, SandboxProbe::REFERENCE_PREFIX)) {
            $this->assertSandboxEnvironment($reference);

            return;
        }

        $invoice = $reference === ''
            ? null
            : ElectronicInvoice::where('uuid', $reference)->first();

        if ($invoice === null) {
            throw $this->reject(
                'la solicitud no corresponde a ninguna factura registrada '
                ."(reference_code «{$reference}»). No se emite lo que no se puede trazar.",
            );
        }

        $this->assertInvoiceIsEmittable($invoice);
        $this->assertNotAlreadyInvoiced($invoice);
        $this->assertPaymentOriginIsReal($invoice);
    }

    // ── Ambiente y endpoint ───────────────────────────────────────────────

    /**
     * El endpoint debe corresponder al ambiente declarado. Un `base_url` de
     * producción con `billing.env=sandbox` (o al revés) significa que alguien
     * cambió media configuración, y ese es exactamente el estado en el que se
     * emiten documentos reales creyendo que son pruebas.
     */
    private function assertEndpointMatchesEnvironment(): void
    {
        $env = strtolower((string) config('billing.env', 'sandbox'));
        $baseUrl = strtolower((string) config('billing.base_url', ''));

        $expected = match ($env) {
            'production' => 'https://api.factus.com.co',
            default => 'https://api-sandbox.factus.com.co',
        };

        if (rtrim($baseUrl, '/') !== $expected) {
            throw $this->reject(sprintf(
                'el ambiente declarado es «%s» pero el endpoint configurado es «%s». '
                .'Para «%s» el único endpoint admitido es «%s».',
                $env, $baseUrl ?: '(vacío)', $env, $expected,
            ));
        }
    }

    private function assertSandboxEnvironment(string $reference): void
    {
        if (strtolower((string) config('billing.env', 'sandbox')) === 'production') {
            throw $this->reject(
                "la referencia «{$reference}» es una prueba de sandbox y el ambiente "
                .'activo es producción. Las pruebas tributarias no se ejecutan contra '
                .'la DIAN.',
            );
        }
    }

    // ── Estado de la solicitud ────────────────────────────────────────────

    private function assertInvoiceIsEmittable(ElectronicInvoice $invoice): void
    {
        $status = $this->statusOf($invoice);

        if ($status === 'cancelled') {
            throw $this->reject(sprintf(
                'la solicitud #%d está cancelada%s. Una solicitud cancelada no se reintenta.',
                $invoice->id,
                $invoice->cancellation_reason ? " (motivo: {$invoice->cancellation_reason})" : '',
            ));
        }

        // `retry_allowed = false` marca lo que nunca debió facturarse. Es una
        // decisión ya tomada y registrada; no se revierte desde un reintento.
        //
        // El modelo no declara un cast para esta columna, así que el valor llega
        // como 0/"0" según el motor. Comparar con `=== false` dejaba pasar todo:
        // se distingue null (columna sin fijar) de un cero explícito.
        if ($invoice->retry_allowed !== null && ! $invoice->retry_allowed) {
            throw $this->reject(sprintf(
                'la solicitud #%d tiene los reintentos deshabilitados%s.',
                $invoice->id,
                $invoice->cancellation_reason ? " (motivo: {$invoice->cancellation_reason})" : '',
            ));
        }

        if ($status === 'rejected' && blank($invoice->cufe)) {
            // Un rechazo sin CUFE no consumió consecutivo y es técnicamente
            // reintentable, pero solo con autorización explícita: el rechazo
            // anterior tuvo una causa que alguien debe haber resuelto.
            if (! SandboxProbe::rejectedRetryAuthorized($invoice->id)) {
                throw $this->reject(sprintf(
                    'la solicitud #%d fue rechazada por el proveedor y no tiene CUFE. '
                    .'Reintentarla exige autorización explícita.',
                    $invoice->id,
                ));
            }
        }
    }

    private function assertNotAlreadyInvoiced(ElectronicInvoice $invoice): void
    {
        if (filled($invoice->cufe) || filled($invoice->full_number)) {
            throw $this->reject(sprintf(
                'la solicitud #%d ya tiene documento fiscal (%s). Emitirla de nuevo '
                .'duplicaría la factura ante la DIAN.',
                $invoice->id,
                $invoice->full_number ?: 'con CUFE',
            ));
        }

        // La unicidad real la garantiza el índice
        // `electronic_invoices_source_type_unique (source_type, source_id, type)`,
        // que impide dos filas para el mismo pago. Esto detecta el caso en que
        // alguien intentara emitir una fila distinta apuntando al mismo pago.
        $duplicate = ElectronicInvoice::where('source_type', $invoice->source_type)
            ->where('source_id', $invoice->source_id)
            ->where('type', $invoice->type)
            ->where('id', '!=', $invoice->id)
            ->whereNotNull('full_number')
            ->first();

        if ($duplicate !== null) {
            throw $this->reject(sprintf(
                'el pago %s ya fue facturado en el documento %s (solicitud #%d).',
                $invoice->source_id, $duplicate->full_number, $duplicate->id,
            ));
        }
    }

    // ── Origen del dinero ─────────────────────────────────────────────────

    private function assertPaymentOriginIsReal(ElectronicInvoice $invoice): void
    {
        $origin = $this->inspector->inspect($invoice);

        if ($origin['is_sandbox']) {
            throw $this->reject(sprintf(
                'la solicitud #%d proviene de una transacción en ambiente «%s». '
                .'Un pago de sandbox no movió dinero: facturarlo declararía ante la '
                .'DIAN una venta que no existió.',
                $invoice->id, $origin['environment'],
            ));
        }

        if ($origin['is_test_card']) {
            throw $this->reject(sprintf(
                'la solicitud #%d proviene de una tarjeta de prueba (terminada en %s).',
                $invoice->id, $origin['card_last_four'],
            ));
        }

        if ($origin['payment_status'] !== 'paid') {
            throw $this->reject(sprintf(
                'el pago de la solicitud #%d está en estado «%s», no «paid». Solo se '
                .'factura lo efectivamente cobrado.',
                $invoice->id, $origin['payment_status'] ?? 'desconocido',
            ));
        }

        if (! $origin['has_verifiable_reference']) {
            throw $this->reject(sprintf(
                'el pago de la solicitud #%d no tiene una referencia verificable contra '
                .'la pasarela.',
                $invoice->id,
            ));
        }

        if (! $origin['wants_invoice']) {
            throw $this->reject(sprintf(
                'la factura de la solicitud #%d no fue solicitada por el cliente. '
                .'La solicitud debe constar en el origen económico '
                .'(payments/product_sales.invoice_requested) o, para pagos de Wompi '
                .'anteriores, en metadata.wants_invoice. No se factura lo que nadie pidió.',
                $invoice->id,
            ));
        }
    }

    // ── Utilidades ────────────────────────────────────────────────────────

    private function reject(string $why): RuntimeException
    {
        return new RuntimeException(
            'Emisión rechazada por la barrera de producción: '.$why
            .' No se envió nada al proveedor y no se consumió consecutivo.',
        );
    }

    private function statusOf(ElectronicInvoice $invoice): string
    {
        $status = $invoice->status;

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
