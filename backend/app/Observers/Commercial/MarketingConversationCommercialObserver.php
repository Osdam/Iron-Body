<?php

namespace App\Observers\Commercial;

use App\Models\MarketingConversation;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;

/**
 * El momento en que una persona toma el mando de la conversación.
 *
 * Es el evento más importante del sistema, y no por volumen. Todos los demás
 * hacen que el motor actúe; este hace que se calle. Un agente que sigue
 * escribiendo después de que el cliente pidió hablar con alguien no es un
 * agente torpe: es el motivo por el que se bloquea el número del gimnasio.
 *
 * `human_takeover` se activa por tres caminos distintos —el cliente lo pide, el
 * clasificador detecta frustración, o un asesor entra por su cuenta desde el
 * inbox— y los tres significan lo mismo para el motor.
 */
class MarketingConversationCommercialObserver
{
    public function __construct(
        private readonly CommercialEventRecorder $recorder,
        private readonly CommercialSubjectResolver $resolver,
    ) {}

    public function updated(MarketingConversation $conversation): void
    {
        if (! (bool) config('commercial.events_enabled', false)) {
            return;
        }

        if (! $conversation->wasChanged('human_takeover') || ! $conversation->human_takeover) {
            return;
        }

        $subject = $this->resolver->fromLead($conversation->lead_id);

        $this->recorder->record(
            event: V::EV_HUMAN_REQUESTED,
            subject: $subject + ['opportunity_id' => null],
            payload: [
                'source' => $conversation->human_takeover_source,
                'conversation_id' => $conversation->id,
            ],
            // Una conversación puede pasar a manos humanas y volver más de una
            // vez a lo largo de meses. La clave incluye el momento para que la
            // segunda vez también detenga al motor; lo que se evita con ella es
            // el doble registro de un mismo cambio, no el segundo cambio.
            dedupeKey: "conversation:{$conversation->id}:takeover:".now()->format('YmdHi'),
        );
    }
}
