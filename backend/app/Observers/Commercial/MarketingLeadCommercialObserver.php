<?php

namespace App\Observers\Commercial;

use App\Models\MarketingLead;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialVocabulary as V;

/**
 * Nacimiento y calificación de un prospecto.
 *
 * «Calificado» aquí no es una etiqueta que ponga la IA por su cuenta: es el
 * estado que el CRM y el clasificador de intención ya escriben en la ficha. El
 * motor se limita a enterarse.
 */
class MarketingLeadCommercialObserver
{
    public function __construct(private readonly CommercialEventRecorder $recorder) {}

    /** Estados en los que un prospecto ya merece trabajo comercial. */
    private const QUALIFIED = [
        MarketingLead::STATUS_INTERESTED,
        MarketingLead::STATUS_HOT,
        MarketingLead::STATUS_WARM,
    ];

    public function created(MarketingLead $lead): void
    {
        if (! $this->armed()) {
            return;
        }

        $this->recorder->record(
            event: V::EV_LEAD_CREATED,
            subject: ['lead_id' => $lead->id, 'member_id' => $lead->member_id],
            payload: ['channel' => $lead->channel, 'source' => $lead->source],
            dedupeKey: "lead:{$lead->id}",
        );
    }

    public function updated(MarketingLead $lead): void
    {
        if (! $this->armed() || ! $lead->wasChanged('status')) {
            return;
        }

        $status = (string) $lead->status;

        // Pedir una persona es el hecho que MANDA sobre todos los demás: hace
        // que el motor se aparte en lugar de decidir. Va primero por eso.
        if ($status === MarketingLead::STATUS_NEEDS_HUMAN) {
            $this->emit($lead, V::EV_HUMAN_REQUESTED, 'needs_human');

            return;
        }

        if (in_array($status, self::QUALIFIED, true)) {
            // La clave incluye el estado: pasar de tibio a caliente es una
            // calificación nueva y merece que el motor la vuelva a mirar.
            $this->emit($lead, V::EV_LEAD_QUALIFIED, "qualified:{$status}");
        }
    }

    private function emit(MarketingLead $lead, string $event, string $keySuffix): void
    {
        $this->recorder->record(
            event: $event,
            subject: ['lead_id' => $lead->id, 'member_id' => $lead->member_id],
            payload: ['status' => $lead->status, 'temperature' => $lead->temperature ?? null],
            dedupeKey: "lead:{$lead->id}:{$keySuffix}",
        );
    }

    private function armed(): bool
    {
        return (bool) config('commercial.events_enabled', false);
    }
}
