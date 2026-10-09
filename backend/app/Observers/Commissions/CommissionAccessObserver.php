<?php

namespace App\Observers\Commissions;

use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Services\Commissions\TrainerCommissionAccess;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EL PISO SE PUEDE PAGAR DESDE OTRA PANTALLA, Y LA PUERTA TIENE QUE ENTERARSE.
 *
 * El acceso del entrenador depende de que su piso esté pagado, pero el abono
 * no siempre pasa por el módulo de Comisiones: también se registra desde
 * Cuentas por cobrar, desde «saldar todo» de una persona, y se deshace al
 * anular un abono. Si solo lo recalculara el controlador de comisiones,
 * alguien pagaría su piso en caja y seguiría sin poder entrar, o lo tendría
 * abierto después de que le anularan el pago.
 *
 * Por eso se escucha el MOVIMIENTO DE DINERO, que es el hecho que de verdad
 * cambia la respuesta, y no la pantalla desde la que se hizo.
 *
 * NO BLOQUEA EL COBRO. Si recalcular falla, el dinero ya entró y el abono no
 * se puede deshacer por eso: se anota y se sigue. La siguiente operación
 * vuelve a calcularlo entero, porque el cálculo no es incremental.
 */
class CommissionAccessObserver
{
    public function created(ReceivablePayment $payment): void
    {
        $this->resync($payment);
    }

    /** Anular un abono devuelve saldo, y con él puede cerrarse la puerta. */
    public function updated(ReceivablePayment $payment): void
    {
        $this->resync($payment);
    }

    private function resync(ReceivablePayment $payment): void
    {
        try {
            $deuda = $payment->receivable ?? Receivable::find($payment->receivable_id);

            if ($deuda) {
                app(TrainerCommissionAccess::class)->syncFromReceivable($deuda);
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo recalcular el acceso por piso de entrenador', [
                'receivable_payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
