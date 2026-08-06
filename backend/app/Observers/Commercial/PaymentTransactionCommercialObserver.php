<?php

namespace App\Observers\Commercial;

use App\Models\PaymentTransaction;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Wompi\PaymentStateMachine as SM;

/**
 * Convierte los cambios de estado de un pago en hechos comerciales.
 *
 * Se hace con un observer y NO editando `WompiTransactionService` por dos
 * razones, y la segunda es la importante:
 *
 *  1. Los pagos son código que el encargo marca como intocable. Un observer
 *     añade comportamiento sin abrir ese archivo.
 *
 *  2. Un pago puede aprobarse por cuatro caminos distintos —el webhook de
 *     Wompi, la reconciliación que corre cada cinco minutos, el cobro
 *     recurrente y el push de Nequi—. Enganchar en el servicio obligaría a
 *     acordarse de los cuatro y a acordarse del quinto cuando se escriba. El
 *     observer escucha a la TABLA, así que cubre todos los caminos, incluidos
 *     los que aún no existen.
 *
 * Todo lo de aquí es best-effort y silencioso ante fallos: corre dentro de la
 * transacción del cobro, y ninguna consideración comercial justifica revertir
 * un pago real.
 */
class PaymentTransactionCommercialObserver
{
    public function __construct(
        private readonly CommercialEventRecorder $recorder,
        private readonly CommercialSubjectResolver $resolver,
    ) {}

    /**
     * Un intento recién creado con enlace de pago es una oferta puesta sobre la
     * mesa: a partir de aquí tiene sentido preguntar por qué no se usó.
     */
    public function created(PaymentTransaction $tx): void
    {
        if (! $this->armed()) {
            return;
        }

        if (blank($tx->checkout_url)) {
            return; // sin enlace no hay nada que recuperar después
        }

        $this->record(V::EV_PAYMENT_LINK_CREATED, $tx, 'link');
    }

    /**
     * Solo interesa ENTRAR en un estado, no refrescarlo. La reconciliación
     * reescribe la misma fila cada cinco minutos; sin esta comprobación cada
     * pasada generaría un hecho nuevo.
     */
    public function updated(PaymentTransaction $tx): void
    {
        if (! $this->armed() || ! $tx->wasChanged('status')) {
            return;
        }

        $event = match ((string) $tx->status) {
            SM::APPROVED => V::EV_PAYMENT_APPROVED,
            SM::DECLINED, SM::ERROR, SM::VOIDED => V::EV_PAYMENT_FAILED,
            SM::EXPIRED => V::EV_PAYMENT_EXPIRED,
            SM::PENDING, SM::REQUIRES_ACTION => V::EV_PAYMENT_PENDING,
            default => null,
        };

        if ($event === null) {
            return;
        }

        $this->record($event, $tx, (string) $tx->status);
    }

    private function record(string $event, PaymentTransaction $tx, string $keySuffix): void
    {
        $subject = $this->resolver->fromMember($tx->member_id);

        $this->recorder->record(
            event: $event,
            subject: $subject,
            payload: [
                'reference' => $tx->reference,
                'plan_id' => $tx->plan_id,
                'amount' => $tx->amount,
                'currency' => $tx->currency,
                'method' => $tx->method,
                'provider' => $tx->provider,
                // El motivo del rechazo cambia la conversación siguiente: no es
                // lo mismo un fondo insuficiente que una tarjeta vencida.
                'failure_reason' => $tx->failure_reason,
                'status_message' => $tx->status_message,
            ],
            // La identidad del hecho: esta transacción entrando en este estado.
            // Da igual cuántas veces lo observe la reconciliación.
            dedupeKey: "tx:{$tx->id}:{$keySuffix}",
        );
    }

    /**
     * El módulo comercial completo se desconecta con un flag. Con él apagado un
     * observer no debe ni consultar la base de datos.
     */
    private function armed(): bool
    {
        return (bool) config('commercial.events_enabled', false);
    }
}
