<?php

namespace App\Console\Commands;

use App\Models\ElectronicInvoice;
use App\Services\Billing\PaymentOriginInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cancela solicitudes de factura originadas en pagos de prueba.
 *
 * Contexto: siete solicitudes pendientes (ID 10–16) resultaron ser
 * transacciones de Wompi en ambiente **sandbox** con tarjeta de prueba `4242`;
 * cinco de ellas eran ensayos del flujo de suscripción recurrente. No movieron
 * dinero, así que facturarlas declararía ante la DIAN ventas inexistentes.
 *
 * Qué hace y qué NO hace:
 *
 *  - Marca la solicitud como `cancelled` con motivo `sandbox_test` y
 *    `retry_allowed = false`, para que ningún barrido de reintentos ni botón
 *    del CRM pueda resucitarla.
 *  - Registra actor, fecha y motivo en `electronic_invoice_logs`.
 *  - NO elimina ninguna fila: la solicitud queda como evidencia.
 *  - NO toca el pago ni la transacción: son registros del negocio, no de
 *    facturación, y podrían ser válidos para otros fines.
 *  - NO llama a Factus: una solicitud `pending` nunca llegó a la DIAN.
 *  - NO toca documentos ya validados: cancelarlos exigiría nota crédito.
 */
class BillingCancelTestRequestsCommand extends Command
{
    protected $signature = 'billing:cancel-test-requests
        {--ids= : Lista o rango explícito de IDs, p. ej. 10-16 o 10,11,12}
        {--reason=sandbox_test : Motivo estable que se guarda en la factura}
        {--actor= : Quién ordena la cancelación (por defecto, el usuario del sistema)}
        {--dry-run : Muestra qué se cancelaría sin escribir nada}';

    protected $description = 'Cancela solicitudes de factura originadas en pagos de prueba (sandbox), sin eliminarlas.';

    public function handle(PaymentOriginInspector $inspector): int
    {
        $dry = (bool) $this->option('dry-run');
        $reason = (string) $this->option('reason');
        $actor = (string) ($this->option('actor') ?: ('cli:'.(get_current_user() ?: 'desconocido')));

        $invoices = $this->targets();

        if ($invoices->isEmpty()) {
            $this->warn('No hay solicitudes candidatas.');

            return self::SUCCESS;
        }

        $this->info('CANCELACIÓN DE SOLICITUDES DE PRUEBA'.($dry ? ' (simulación)' : ''));
        $this->line('');

        $rows = [];
        $eligible = [];

        foreach ($invoices as $invoice) {
            $origin = $inspector->inspect($invoice);
            $status = $this->statusOf($invoice);

            // Solo se cancela lo que nunca llegó a la DIAN.
            $blocked = match (true) {
                $status !== 'pending' => "no es pending (es {$status})",
                filled($invoice->cufe) => 'ya tiene CUFE',
                filled($invoice->full_number) => 'ya tiene número',
                ! $origin['is_sandbox'] && ! $origin['is_test_card'] => 'no es de sandbox: requiere decisión manual',
                default => null,
            };

            $rows[] = [
                $invoice->id,
                $status,
                $origin['environment'] ?? '—',
                $origin['card_last_four'] ?? '—',
                $invoice->total,
                $blocked ?? '→ se cancela',
            ];

            if ($blocked === null) {
                $eligible[] = $invoice;
            }
        }

        $this->table(['id', 'estado', 'entorno', 'tarjeta', 'total', 'resultado'], $rows);

        if ($eligible === []) {
            $this->warn('Ninguna solicitud cumple las condiciones para cancelarse.');

            return self::SUCCESS;
        }

        if ($dry) {
            $this->info('Se cancelarían '.count($eligible).' solicitudes. Nada fue escrito.');

            return self::SUCCESS;
        }

        foreach ($eligible as $invoice) {
            $this->cancel($invoice, $reason, $actor);
            $this->line("  #{$invoice->id} cancelada · motivo={$reason} · actor={$actor}");
        }

        $this->line('');
        $this->info('Canceladas '.count($eligible).' solicitudes. Ninguna fue eliminada.');
        $this->line('  Los pagos y transacciones asociados NO se modificaron.');
        $this->line('  No se llamó a Factus: estas solicitudes nunca llegaron a la DIAN.');

        return self::SUCCESS;
    }

    /** @return Collection<int,ElectronicInvoice> */
    private function targets(): Collection
    {
        $ids = $this->parseIds((string) $this->option('ids'));

        $query = ElectronicInvoice::query()->orderBy('id');

        return $ids === []
            ? $query->where('status', 'pending')->get()
            : $query->whereIn('id', $ids)->get();
    }

    /**
     * Acepta «10-16», «10,11,12» y combinaciones. Un rango explícito evita
     * cancelar por error algo que apareciera después.
     *
     * @return array<int,int>
     */
    private function parseIds(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $chunk) {
            $chunk = trim($chunk);

            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $chunk, $m)) {
                $ids = array_merge($ids, range((int) $m[1], (int) $m[2]));

                continue;
            }

            if ($chunk !== '') {
                $ids[] = (int) $chunk;
            }
        }

        return array_values(array_unique($ids));
    }

    private function cancel(ElectronicInvoice $invoice, string $reason, string $actor): void
    {
        DB::transaction(function () use ($invoice, $reason, $actor) {
            $invoice->forceFill([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'retry_allowed' => false,
                'cancelled_at' => now(),
                'cancelled_by' => $actor,
            ])->save();

            // Bitácora: actor, fecha y motivo, en la tabla que ya existe para
            // el rastro de cada factura.
            DB::table('electronic_invoice_logs')->insert([
                'electronic_invoice_id' => $invoice->id,
                'action' => 'cancel',
                'endpoint' => null,
                'http_status' => null,
                'result' => 'cancelled',
                'message' => sprintf(
                    'Solicitud cancelada por %s el %s. Motivo: %s. Origen: pago de prueba '
                    .'(sandbox); no movió dinero y no debe facturarse. retry_allowed=false. '
                    .'No se llamó a Factus y no se eliminó ningún registro.',
                    $actor, now()->toDateTimeString(), $reason,
                ),
                'payload_excerpt' => null,
                'duration_ms' => null,
                'created_at' => now(),
            ]);
        });
    }

    private function statusOf(ElectronicInvoice $invoice): string
    {
        $status = $invoice->status;

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
