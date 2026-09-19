<?php

namespace App\Jobs\Wompi;

use App\Models\PaymentTransaction;
use App\Services\Observability\ChannelLog;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sella UNA transacción que la pasarela ya dio por terminada, sin esperar al cron.
 *
 * Por qué existe. `PaymentStatusProvider` pregunta a Wompi en vivo para que el
 * modelo no le diga «no me consta» a quien acaba de pagar, pero es una LECTURA:
 * no mueve la fila. Entre esa lectura y la pasada del cron
 * `payments:wompi-reconcile` (cada cinco minutos) la fila sigue `pending`, y
 * durante esa ventana el cerrojo `already_paid` de la generación de links no
 * protege: el mismo lead puede recibir un segundo link de pago por algo que ya
 * pagó. Este job cierra la ventana pasando el hecho al camino AUDITADO.
 *
 * Y por eso llama a `reconcileOne` y no a `refresh`: `reconcileOne` es el que
 * usa el cron, el que lleva `retry_count` y `last_reconciled_at`, y el que deja
 * la transición registrada. La activación de la membresía, el aviso al cliente y
 * la factura salen de ahí —idempotentes—, no de una lectura del endpoint de IA.
 *
 * NO LANZA. Un fallo aquí no es una pérdida: el cron vuelve a mirar la misma
 * fila en cinco minutos. Propagar la excepción solo serviría para llenar
 * `failed_jobs` de reintentos que ya tienen quien los haga.
 */
class ReconcileWompiTransaction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $transactionId)
    {
        // Carril comercial: es el que atiende el trabajo de fondo de marketing y
        // el que ya tiene worker propio en producción. No se inventa una cola
        // nueva porque una cola sin worker es un job que no corre nunca.
        $lane = (array) config('queue.lanes.commercial');
        $this->onQueue($lane['queue'] ?? 'commercial');
    }

    public function handle(): void
    {
        try {
            $tx = PaymentTransaction::query()->whereKey($this->transactionId)->first();

            // Si ya no está en vuelo, alguien la selló antes (el webhook, el cron
            // u otra pasada de este job): no hay nada que reconciliar.
            if ($tx === null || ! in_array((string) $tx->status, PaymentStateMachine::IN_FLIGHT, true)) {
                return;
            }

            WompiReconciliationService::make()->reconcileOne($tx);
        } catch (Throwable $e) {
            // Sin `getMessage()`: el cuerpo de un error de pasarela puede traer
            // referencias y datos del pagador. La clase del fallo y el id de la
            // fila bastan para ir a buscarla.
            ChannelLog::warning('wompi.reconcile_one.failed', [
                'transaction_id' => $this->transactionId,
                'exception' => class_basename($e),
            ]);
        }
    }
}
