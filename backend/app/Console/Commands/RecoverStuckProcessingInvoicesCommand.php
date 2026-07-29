<?php

namespace App\Console\Commands;

use App\Enums\InvoiceLogAction;
use App\Enums\InvoiceStatus;
use App\Models\ElectronicInvoice;
use App\Services\Billing\InvoicingService;
use Illuminate\Console\Command;

/**
 * Rescata solicitudes ATASCADAS en `processing`.
 *
 * Nace de un caso real: la solicitud #18 (venta V-000003) se quedó en
 * `processing` indefinidamente. El job marcaba `processing` y sólo entonces la
 * barrera rechazaba la emisión; al reintentar, la guarda de idempotencia veía
 * `processing` —que no está en `canRetry()`— y hacía `return`. La cola daba el
 * job por bueno, `failed()` no se llamaba y el estado terminal no se escribía
 * nunca. Los dos barridos existentes tampoco la alcanzaban:
 * SyncFactusInvoiceStatusJob exige `full_number` y RetryElectronicInvoiceJob
 * sólo mira `error`.
 *
 * El defecto de origen ya está corregido en EmitElectronicInvoiceJob. Este
 * comando existe para las que quedaron colgadas antes, y como red permanente
 * ante cualquier proceso que muera entre `markProcessing()` y la respuesta.
 *
 * Sólo toca solicitudes que demostrablemente NO llegaron al proveedor: sin
 * número, sin CUFE y sin respuesta. Una solicitud con cualquiera de esos datos
 * PUEDE tener un documento fiscal real detrás, y en ese caso lo que hay que
 * hacer es reconciliar contra Factus, nunca reescribir el estado a mano.
 *
 * Uso:
 *   php artisan billing:recover-stuck-processing                    # simulación
 *   php artisan billing:recover-stuck-processing --minutes=10
 *   php artisan billing:recover-stuck-processing --id=18 --confirm
 */
class RecoverStuckProcessingInvoicesCommand extends Command
{
    protected $signature = 'billing:recover-stuck-processing
        {--minutes=15 : Antigüedad mínima en processing para considerarla colgada.}
        {--id= : Rescatar SOLO esta solicitud (ignora el filtro de antigüedad).}
        {--reason= : Motivo a registrar en failure_reason.}
        {--confirm : Persiste el cambio. Sin este flag el comando es de solo lectura.}';

    protected $description = 'Pasa a error las solicitudes colgadas en processing que nunca llegaron al proveedor (read-only sin --confirm).';

    private const MOTIVO_POR_DEFECTO = 'Emisión interrumpida: la solicitud quedó en processing sin '
        .'respuesta del proveedor (sin número ni CUFE). No se envió ningún documento y no se '
        .'consumió consecutivo.';

    public function handle(InvoicingService $invoicing): int
    {
        $minutes = max(0, (int) $this->option('minutes'));
        $id = $this->option('id');
        $confirm = (bool) $this->option('confirm');

        $candidatas = ElectronicInvoice::query()
            ->whereIn('status', [
                InvoiceStatus::PROCESSING->value,
                InvoiceStatus::CREDIT_NOTE_PROCESSING->value,
            ])
            // Prueba de que nada llegó al proveedor. Si alguno existe, el
            // documento puede ser real: no se toca desde aquí.
            ->whereNull('number')
            ->whereNull('full_number')
            ->whereNull('factus_id')
            ->whereNull('cufe')
            ->whereNull('response_payload')
            ->when($id !== null, fn ($q) => $q->where('id', (int) $id))
            ->when($id === null && $minutes > 0, fn ($q) => $q->where(
                fn ($w) => $w->where('last_attempt_at', '<=', now()->subMinutes($minutes))
                    ->orWhereNull('last_attempt_at')
            ))
            ->orderBy('id')
            ->get();

        if ($candidatas->isEmpty()) {
            $this->info('No hay solicitudes colgadas en processing que cumplan las condiciones.');

            return self::SUCCESS;
        }

        $this->warn($candidatas->count().' solicitud(es) colgada(s) en processing:');
        $this->table(
            ['ID', 'uuid', 'origen', 'total', 'último intento', 'número', 'CUFE'],
            $candidatas->map(fn (ElectronicInvoice $i) => [
                $i->id,
                $i->uuid,
                class_basename((string) $i->source_type).'#'.$i->source_id,
                $i->total,
                $i->last_attempt_at ?? '—',
                $i->full_number ?? '—',
                $i->cufe ?? '—',
            ])->all(),
        );

        if (! $confirm) {
            $this->line('');
            $this->info('SIMULACIÓN: no se escribió nada. Añade --confirm para aplicar.');

            return self::SUCCESS;
        }

        $motivo = (string) ($this->option('reason') ?: self::MOTIVO_POR_DEFECTO);

        foreach ($candidatas as $invoice) {
            $invoice->markError($motivo);

            // Traza en la propia bitácora de la factura: quien la audite años
            // después tiene que poder ver que el cambio fue una recuperación
            // manual y no una respuesta del proveedor.
            $invoicing->recordLog(
                $invoice,
                InvoiceLogAction::EMIT,
                'error',
                'Recuperada de processing por billing:recover-stuck-processing. '.$motivo,
            );

            $this->line("  #{$invoice->id} -> error");
        }

        $this->line('');
        $this->info($candidatas->count().' solicitud(es) pasada(s) a error. NO se reintentó ninguna.');
        $this->line('  Para reintentar una, hace falta corregir antes la causa (p. ej. la');
        $this->line('  solicitud expresa del cliente) y despacharla explícitamente.');

        return self::SUCCESS;
    }
}
