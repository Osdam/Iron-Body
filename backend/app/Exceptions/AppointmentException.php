<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Una transición de la Agenda comercial que el dominio no permite: completar
 * una visita que nadie confirmó, reabrir una cancelada, mover una cita al
 * pasado. Lleva el código HTTP y un código estable para el CRM.
 */
class AppointmentException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function invalidTransition(string $desde, string $hacia): self
    {
        return new self("Una cita {$desde} no puede pasar a {$hacia}.", 'invalid_transition');
    }

    public static function notConfirmed(): self
    {
        return new self('Esta visita está solicitada pero nadie la ha confirmado: confírmala antes de marcarla como cumplida.', 'appointment_not_confirmed');
    }

    /** Completar es decir que la visita ocurrió: antes de su hora no ha podido ocurrir. */
    public static function notDue(): self
    {
        return new self('La visita todavía no puede marcarse como completada porque su hora no ha llegado.', 'appointment_not_due');
    }

    public static function closed(): self
    {
        return new self('La cita ya está cerrada (cumplida, cancelada o no asistió): su historial no se reescribe.', 'appointment_closed');
    }

    /** @param  string  $antesDe  lo que se iba a hacer: «confirmarla», «moverla», «guardar los cambios». */
    public static function changed(string $antesDe = 'seguir'): self
    {
        return new self("La cita cambió mientras la mirabas (otra sesión o ULTRON la movió): revisa la nueva fecha antes de {$antesDe}.", 'appointment_changed');
    }

    public static function duplicateRequest(): self
    {
        return new self('Esta persona ya tiene otra solicitud de visita abierta: confírmala o cancélala antes de convertir esta en visita.', 'appointment_duplicate_request');
    }

    public static function outOfRange(): self
    {
        return new self('Esa fecha no es válida para la agenda: elige una entre los años 2000 y 2100.', 'scheduled_out_of_range', 422);
    }

    public static function inThePast(): self
    {
        return new self('Esa fecha ya pasó: elige una fecha futura.', 'scheduled_in_the_past', 422);
    }
}
