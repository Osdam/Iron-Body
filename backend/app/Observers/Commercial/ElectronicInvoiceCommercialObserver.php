<?php

namespace App\Observers\Commercial;

use App\Enums\InvoiceStatus;
use App\Models\ElectronicInvoice;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;

/**
 * Facturación electrónica, observada sin tocarla.
 *
 * Este observer LEE. No emite, no reintenta, no corrige y no decide nada sobre
 * la DIAN: el encargo marca la facturación como intocable y aquí se respeta
 * literalmente. Lo único que hace es enterarse de en qué estado quedó un
 * comprobante, porque de eso depende qué se le dice al cliente.
 *
 * El rechazo es el caso que importa. Casi siempre viene de datos fiscales
 * incompletos del cliente —un NIT mal escrito, una ciudad que falta— y se
 * resuelve pidiéndoselos por WhatsApp. Sin este evento, la factura se queda
 * rechazada en un tablero que nadie mira y el cliente nunca la recibe.
 */
class ElectronicInvoiceCommercialObserver
{
    public function __construct(
        private readonly CommercialEventRecorder $recorder,
        private readonly CommercialSubjectResolver $resolver,
    ) {}

    public function created(ElectronicInvoice $invoice): void
    {
        if (! $this->armed()) {
            return;
        }

        $this->emit($invoice, V::EV_INVOICE_REQUESTED, 'requested');
    }

    public function updated(ElectronicInvoice $invoice): void
    {
        if (! $this->armed() || ! $invoice->wasChanged('status')) {
            return;
        }

        $event = match ((string) $invoice->status) {
            InvoiceStatus::VALIDATED->value,
            InvoiceStatus::CREDIT_NOTE_VALIDATED->value => V::EV_INVOICE_VALIDATED,

            InvoiceStatus::REJECTED->value,
            InvoiceStatus::CREDIT_NOTE_REJECTED->value => V::EV_INVOICE_REJECTED,

            // `error` es un fallo técnico que se reintenta solo. No es un hecho
            // comercial: molestar al cliente por un timeout de red sería pedirle
            // que resuelva un problema nuestro.
            default => null,
        };

        if ($event === null) {
            return;
        }

        $this->emit($invoice, $event, (string) $invoice->status);
    }

    private function emit(ElectronicInvoice $invoice, string $event, string $keySuffix): void
    {
        $subject = $this->subjectFor($invoice);

        if ($subject['member_id'] === null && $subject['lead_id'] === null) {
            return; // factura de mostrador sin ficha: no hay a quién escribir
        }

        $this->recorder->record(
            event: $event,
            subject: $subject,
            payload: [
                'full_number' => $invoice->full_number,
                'total' => $invoice->total,
                // El motivo del rechazo es lo que permite pedir EXACTAMENTE el
                // dato que falta en vez de mandar un «hubo un problema».
                'failure_reason' => $invoice->failure_reason,
                'has_pdf' => filled($invoice->pdf_path) || filled($invoice->pdf_url),
                'has_xml' => filled($invoice->xml_path) || filled($invoice->xml_url),
            ],
            dedupeKey: "invoice:{$invoice->id}:{$keySuffix}",
        );
    }

    /**
     * El comprobante apunta a su origen por morph (`source_type`/`source_id`);
     * de ahí se saca el miembro. Si el origen no tiene miembro —una venta de
     * mostrador a alguien que no es socio— no hay sujeto comercial.
     *
     * @return array{lead_id:?int, member_id:?int}
     */
    private function subjectFor(ElectronicInvoice $invoice): array
    {
        $empty = ['lead_id' => null, 'member_id' => null];

        $class = (string) $invoice->source_type;

        if ($class === '' || ! class_exists($class)) {
            return $empty;
        }

        try {
            $source = $class::query()->find($invoice->source_id);
        } catch (\Throwable) {
            return $empty;
        }

        if ($source === null) {
            return $empty;
        }

        if (! empty($source->member_id)) {
            return $this->resolver->fromMember((int) $source->member_id);
        }

        if (! empty($source->user_id)) {
            return $this->resolver->fromUser((int) $source->user_id);
        }

        return $empty;
    }

    private function armed(): bool
    {
        return (bool) config('commercial.events_enabled', false);
    }
}
