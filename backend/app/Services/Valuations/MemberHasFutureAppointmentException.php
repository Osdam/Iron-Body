<?php

namespace App\Services\Valuations;

use App\Exceptions\ValuationException;

/**
 * «Programar próxima valoración» (`as_next_follow_up`) con una cita abierta
 * FUTURA ya agendada (CONTRATO_CICLO_VALORACIONES.md §4): 409
 * `member_has_future_appointment` con `existing` para que el CRM ofrezca
 * «Conservar» o «Reprogramar».
 *
 * Es una {@see ValuationException}: el controlador la traduce como cualquier
 * otro rechazo de la agenda (`{ok:false, code, message, existing}`).
 *
 * `existing` es null cuando la cita está fuera del alcance de quien pregunta
 * (un entrenador vinculado que no ve la agenda de otro): la cita de otro no se
 * describe, igual que en el resto de la agenda.
 */
final class MemberHasFutureAppointmentException extends ValuationException
{
    /** @param  array{uuid: string, starts_at: string, trainer: array{id: int, name: string}, status: string}|null  $existente */
    public function __construct(?array $existente)
    {
        parent::__construct(
            'El socio ya tiene una valoración agendada. Consérvala o reprográmala.',
            'member_has_future_appointment',
            409,
            ['existing' => $existente],
        );
    }
}
