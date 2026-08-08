<?php

namespace App\Jobs;

use App\Enums\InvoiceStatus;
use App\Models\ElectronicInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Barrido periódico de comprobantes en 'error' (fallo técnico) para reencolar su
 * emisión. Respeta el flag maestro y un tope de antigüedad. Los rechazos de
 * datos ('rejected') NO se reintentan aquí: requieren corrección manual.
 */
class RetryElectronicInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        // Hoy nadie lo encola —solo se menciona en un comentario— pero se le da
        // su carril igual. Después de retirar el worker de `default`, un job sin
        // carril no lo procesaría nadie: se quedaría en la tabla indefinidamente
        // sin error visible. El día que alguien lo conecte, ya está en su sitio.
        $lane = (array) config('queue.lanes.billing');
        $this->onQueue($lane['queue'] ?? 'billing');
    }

    public function handle(): void
    {
        if (! config('billing.enabled')) {
            return;
        }

        $maxAge = (int) config('billing.reconciliation.max_age_minutes', 1440);

        ElectronicInvoice::query()
            ->whereIn('status', [InvoiceStatus::ERROR->value, InvoiceStatus::CREDIT_NOTE_ERROR->value])
            ->where('updated_at', '>=', now()->subMinutes($maxAge))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (ElectronicInvoice $invoice): void {
                if ($invoice->type->value === 'credit_note') {
                    EmitCreditNoteJob::dispatch($invoice->id)->onQueue(config('billing.queue', 'billing'));
                } else {
                    EmitElectronicInvoiceJob::dispatch($invoice->id)->onQueue(config('billing.queue', 'billing'));
                }
            });
    }
}
