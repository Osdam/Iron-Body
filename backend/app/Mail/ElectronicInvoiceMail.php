<?php

namespace App\Mail;

use App\Models\ElectronicInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Comprobante electrónico enviado al correo del cliente vía SMTP propio
 * (fallback al envío nativo de Factus). NO expone rutas internas del servidor:
 * los adjuntos llevan un nombre limpio (Factura-{full_number}.pdf|.xml) y el
 * cuerpo solo muestra datos fiscales públicos (número, total, CUFE, fecha).
 *
 * El despacho/validez del envío lo controla SendElectronicInvoiceEmailJob; esta
 * clase solo arma el mensaje con los adjuntos que el job decidió incluir.
 */
class ElectronicInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int,array{disk:string,path:string,as:string,mime:string}>  $attachmentSpecs
     */
    public function __construct(
        public ElectronicInvoice $invoice,
        public array $attachmentSpecs = [],
    ) {
    }

    public function envelope(): Envelope
    {
        $number = $this->invoice->full_number ?: $this->invoice->number ?: $this->invoice->uuid;

        return new Envelope(
            subject: "Factura electrónica {$number} - Iron Body Neiva",
        );
    }

    public function content(): Content
    {
        return new Content(
            // Vista HTML propia (no Markdown) para un diseño premium de marca.
            // Solo cambia la PRESENTACIÓN: mismos datos fiscales, mismos adjuntos.
            view: 'mail.electronic-invoice',
            with: [
                'fullNumber'   => $this->invoice->full_number ?: $this->invoice->number,
                'total'        => number_format((float) $this->invoice->total, 2, ',', '.'),
                'currency'     => $this->invoice->currency,
                'cufe'         => $this->invoice->cufe,
                'validatedAt'  => optional($this->invoice->validated_at)->format('Y-m-d H:i'),
                // Branding (presentación). Logo: si no hay URL absoluta en .env,
                // cae al asset público de marca (resuelto contra APP_URL como URL
                // absoluta, requisito de los clientes de correo). Si tampoco, el
                // header usa el fallback tipográfico "IRON BODY".
                'logoUrl'      => $this->resolveLogoUrl(),
                'supportEmail' => config('billing.customer_email_delivery.support_email')
                    ?: 'facturacion@ironbodyneiva.cloud',
                // Reflejan los adjuntos REALES que el job decidió incluir.
                'hasPdf'       => $this->attachmentHasExtension('.pdf'),
                'hasXml'       => $this->attachmentHasExtension('.xml'),
            ],
        );
    }

    /**
     * URL absoluta del logo para el header del correo. Prioridad:
     *   1) BILLING_EMAIL_LOGO_URL (config) si está definida.
     *   2) Asset público de marca brand/iron-body-email-logo.jpg (URL absoluta).
     *      Logo con fondo negro propio, pensado para el header NEGRO.
     * Si el asset no existe en disco, devuelve null para usar el fallback de texto.
     */
    private function resolveLogoUrl(): ?string
    {
        $configured = config('billing.customer_email_delivery.logo_url');
        if (! empty($configured)) {
            return $configured;
        }

        $relative = 'brand/iron-body-email-logo.jpg';
        if (is_file(public_path($relative))) {
            return asset($relative);
        }

        return null;
    }

    /** Indica si entre los adjuntos hay uno con la extensión dada (p. ej. ".pdf"). */
    private function attachmentHasExtension(string $ext): bool
    {
        foreach ($this->attachmentSpecs as $spec) {
            if (isset($spec['as']) && str_ends_with(strtolower($spec['as']), $ext)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $a) => Attachment::fromStorageDisk($a['disk'], $a['path'])
                ->as($a['as'])
                ->withMime($a['mime']),
            $this->attachmentSpecs,
        );
    }
}
