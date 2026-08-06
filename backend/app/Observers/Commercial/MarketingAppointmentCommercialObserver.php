<?php

namespace App\Observers\Commercial;

use App\Models\MarketingAppointment;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;

/**
 * La agenda como señal comercial.
 *
 * Una cita creada dice que hay interés real; una cita completada dice que la
 * persona vino y habló con alguien, que es el momento de mayor intención de
 * compra de todo el recorrido. Una cita a la que no se presentó dice lo
 * contrario, y por eso `no_show` no se trata como completada.
 */
class MarketingAppointmentCommercialObserver
{
    public function __construct(
        private readonly CommercialEventRecorder $recorder,
        private readonly CommercialSubjectResolver $resolver,
    ) {}

    public function created(MarketingAppointment $appointment): void
    {
        if (! $this->armed()) {
            return;
        }

        $this->emit($appointment, V::EV_APPOINTMENT_CREATED, 'created');
    }

    public function updated(MarketingAppointment $appointment): void
    {
        if (! $this->armed() || ! $appointment->wasChanged('status')) {
            return;
        }

        if ((string) $appointment->status !== MarketingAppointment::STATUS_COMPLETED) {
            return;
        }

        $this->emit($appointment, V::EV_APPOINTMENT_COMPLETED, 'completed');
    }

    private function emit(MarketingAppointment $appointment, string $event, string $keySuffix): void
    {
        $subject = $this->resolver->fromLead($appointment->marketing_lead_id);

        $this->recorder->record(
            event: $event,
            subject: $subject,
            payload: [
                'type' => $appointment->type,
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
            ],
            dedupeKey: "appointment:{$appointment->id}:{$keySuffix}",
        );
    }

    private function armed(): bool
    {
        return (bool) config('commercial.events_enabled', false);
    }
}
