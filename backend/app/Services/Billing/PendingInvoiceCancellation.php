<?php

namespace App\Services\Billing;

use App\Models\ElectronicInvoice;
use Illuminate\Support\Facades\DB;

/**
 * CANCELAR UNA SOLICITUD DE FACTURA QUE NUNCA LLEGÓ A LA DIAN.
 *
 * Extraído de `billing:cancel-test-requests` para que la reclasificación de un
 * cobro (`payments:reclassify-to-commission`) cancele el borrador de su
 * concepto equivocado con la MISMA regla y la MISMA bitácora, y no con una
 * copia que se desvíe con el tiempo. La conducta del comando no cambia: él
 * sigue decidiendo sus motivos propios (sandbox, venta sin solicitud) y aquí
 * vive lo que es común.
 *
 * Qué hace y qué NO hace:
 *
 *  - Marca la solicitud `cancelled` con su motivo y `retry_allowed = false`,
 *    para que ningún barrido de reintentos ni botón del CRM la resucite.
 *  - Deja actor, fecha y motivo en `electronic_invoice_logs`.
 *  - NO elimina ninguna fila: la solicitud queda como evidencia.
 *  - NO toca el origen (pago o venta) ni llama a Factus.
 *  - NO toca nada con CUFE o número: eso exigiría nota crédito.
 */
final class PendingInvoiceCancellation
{
    /**
     * Por qué esta solicitud NO se puede cancelar sin más trámite, o null si
     * nunca llegó a la DIAN y se puede.
     */
    public function blocker(ElectronicInvoice $invoice): ?string
    {
        $status = self::statusOf($invoice);

        return match (true) {
            $status !== 'pending' => "no es pending (es {$status})",
            filled($invoice->cufe) => 'ya tiene CUFE',
            filled($invoice->full_number) => 'ya tiene número',
            default => null,
        };
    }

    /**
     * ¿El origen económico NO pidió factura?
     *
     * Es la única razón por la que una solicitud de una venta real puede
     * cancelarse sin más trámite: sin solicitud expresa la emisión está
     * prohibida por diseño, así que la fila nunca podrá convertirse en
     * documento. Se lee del hecho económico (pago o venta), no de la
     * transacción de pasarela.
     */
    public function isNotRequested(ElectronicInvoice $invoice): bool
    {
        $source = $invoice->source;

        return $source !== null && ! (bool) ($source->invoice_requested ?? false);
    }

    /**
     * Cancela la solicitud y deja su bitácora, juntas.
     *
     * `$origin` es la frase que explica en la bitácora de dónde venía la
     * solicitud y por qué no debe emitirse: el porqué tiene que ser EXACTO,
     * porque quien audite el documento años después solo tendrá ese texto.
     */
    public function cancel(ElectronicInvoice $invoice, string $reason, string $actor, string $origin): void
    {
        DB::transaction(function () use ($invoice, $reason, $actor, $origin) {
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
                    'Solicitud cancelada por %s el %s. Motivo: %s. %s retry_allowed=false. '
                    .'No se llamó a Factus y no se eliminó ningún registro.',
                    $actor,
                    now()->toDateTimeString(),
                    $reason,
                    $origin,
                ),
                'payload_excerpt' => null,
                'duration_ms' => null,
                'created_at' => now(),
            ]);
        });
    }

    /** El estado como texto, venga como enum (el cast del modelo) o como cadena. */
    public static function statusOf(ElectronicInvoice $invoice): string
    {
        $status = $invoice->status;

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
