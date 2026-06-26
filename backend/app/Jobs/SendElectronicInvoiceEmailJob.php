<?php

namespace App\Jobs;

use App\Enums\InvoiceLogAction;
use App\Enums\InvoiceStatus;
use App\Mail\ElectronicInvoiceMail;
use App\Models\ElectronicInvoice;
use App\Services\Billing\InvoiceDtoBuilder;
use App\Services\Billing\InvoicingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Envía el comprobante electrónico al correo del cliente vía SMTP propio.
 *
 * FALLBACK al envío nativo de Factus (que en producción respondió
 * send_email=false / customer.email=null). Es totalmente best-effort y
 * DESACOPLADO de la emisión: la factura ya quedó 'validated' y este job JAMÁS
 * la revierte. Si el envío falla, solo se deja constancia (status=failed + log
 * email_failed) y se conserva la factura intacta.
 *
 * Idempotente: si customer_email_sent_at ya existe, no reenvía.
 */
class SendElectronicInvoiceEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public int $electronicInvoiceId)
    {
        $this->onQueue((string) config('billing.queue', 'billing'));
    }

    public function handle(InvoicingService $invoicing): void
    {
        // Guarda de configuración: nunca enviar con el fallback apagado.
        if (! config('billing.customer_email_delivery.enabled')) {
            return;
        }

        $invoice = ElectronicInvoice::find($this->electronicInvoiceId);
        if ($invoice === null) {
            return;
        }

        // Idempotencia: no reenviar si ya salió.
        if ($invoice->customerEmailAlreadySent()) {
            return;
        }

        // Solo facturas efectivamente validadas (no notas crédito ni otros estados).
        if ($invoice->status !== InvoiceStatus::VALIDATED) {
            return;
        }

        // Email válido (consumidor final / datos incompletos pueden no traerlo).
        if (! InvoiceDtoBuilder::hasValidEmail($invoice->customer_email)) {
            $invoicing->recordLog(
                $invoice,
                InvoiceLogAction::EMAIL_FAILED,
                'error',
                'Sin correo válido del cliente; no se envía.',
            );
            $invoice->markCustomerEmailFailed('Sin correo válido del cliente.');
            return;
        }

        $attachments = $this->buildAttachments($invoice);

        try {
            Mail::to($invoice->customer_email)
                ->send(new ElectronicInvoiceMail($invoice, $attachments));
        } catch (Throwable $e) {
            // El fallo del correo NO revierte la factura: queda 'validated'.
            $invoice->markCustomerEmailFailed('Error al enviar el correo.');
            $invoicing->recordLog(
                $invoice,
                InvoiceLogAction::EMAIL_FAILED,
                'error',
                'Error al enviar el correo del comprobante.',
            );
            Log::warning('billing.customer_email_failed', [
                'invoice_id' => $invoice->id,
                'recipient'  => $this->maskEmail($invoice->customer_email),
            ]);
            return; // No relanzar: best-effort, sin reintentos a ciegas.
        }

        $invoice->markCustomerEmailSent();
        $invoicing->recordLog(
            $invoice,
            InvoiceLogAction::EMAIL_SENT,
            'ok',
            'Comprobante enviado al correo del cliente.',
            payloadExcerpt: [
                'recipient'    => $this->maskEmail($invoice->customer_email),
                'attached_pdf' => $this->hasSpec($attachments, 'pdf'),
                'attached_xml' => $this->hasSpec($attachments, 'xml'),
            ],
        );
    }

    /**
     * Construye los adjuntos (PDF/XML) según config y existencia REAL en disco
     * privado. Nombres limpios: no expone rutas internas del servidor.
     *
     * @return array<int,array{disk:string,path:string,as:string,mime:string}>
     */
    private function buildAttachments(ElectronicInvoice $invoice): array
    {
        $disk = (string) config('billing.storage.disk', 'local');
        $base = $invoice->full_number ?: $invoice->number ?: $invoice->uuid;

        $specs = [];

        if (config('billing.customer_email_delivery.attach_pdf')
            && $invoice->pdf_path
            && Storage::disk($disk)->exists($invoice->pdf_path)) {
            $specs[] = [
                'disk' => $disk,
                'path' => $invoice->pdf_path,
                'as'   => "Factura-{$base}.pdf",
                'mime' => 'application/pdf',
            ];
        }

        if (config('billing.customer_email_delivery.attach_xml')
            && $invoice->xml_path
            && Storage::disk($disk)->exists($invoice->xml_path)) {
            $specs[] = [
                'disk' => $disk,
                'path' => $invoice->xml_path,
                'as'   => "Factura-{$base}.xml",
                'mime' => 'application/xml',
            ];
        }

        return $specs;
    }

    /** @param array<int,array{as:string}> $specs */
    private function hasSpec(array $specs, string $ext): bool
    {
        foreach ($specs as $s) {
            if (str_ends_with($s['as'], '.' . $ext)) {
                return true;
            }
        }
        return false;
    }

    /** Enmascara el correo para auditoría (no persistir el dato completo). */
    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }
        return $email[0] . str_repeat('*', max(1, $at - 1)) . substr($email, $at);
    }
}
