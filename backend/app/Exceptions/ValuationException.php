<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Lo que la agenda de valoraciones no permite: una hora ocupada, fuera de la
 * disponibilidad, en el pasado, una cita que cambió mientras se miraba…
 *
 * Mismo patrón que {@see AppointmentException}: un código ESTABLE que el CRM
 * traduce a su mensaje (CONTRATO_AGENDA_VALORACIONES.md §3–§4), el estado HTTP
 * y, cuando hace falta, datos extra que viajan junto al error (`block_kind`,
 * `class_name`, la lista de citas que pisa un bloqueo, la cita actual en
 * `appointment_changed`). Nunca lleva nombres de socios.
 */
class ValuationException extends RuntimeException
{
    /** @param  array<string,mixed>  $extra */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $que = 'La cita'): self
    {
        return new self("{$que} no existe o no está a tu alcance.", 'not_found', 404);
    }

    public static function forbidden(string $message = 'No tienes permiso para esta acción.'): self
    {
        return new self($message, 'forbidden', 403);
    }

    public static function inPast(): self
    {
        return new self('Esa hora ya pasó: elige una fecha y hora futuras.', 'in_past', 422);
    }

    public static function tooSoon(int $minutos): self
    {
        return new self("Las valoraciones se agendan con al menos {$minutos} minutos de anticipación.", 'too_soon', 422);
    }

    public static function beyondHorizon(int $dias): self
    {
        return new self("Solo se puede agendar hasta {$dias} días por delante.", 'beyond_horizon', 422);
    }

    public static function outsideAvailability(): self
    {
        return new self('Esa hora no está dentro de la disponibilidad del entrenador.', 'outside_availability');
    }

    public static function blocked(string $kind): self
    {
        return new self('El entrenador tiene esa franja bloqueada.', 'blocked', 409, ['block_kind' => $kind]);
    }

    public static function classConflict(string $className): self
    {
        return new self("El entrenador tiene una clase a esa hora ({$className}).", 'class_conflict', 409, ['class_name' => $className]);
    }

    public static function slotTaken(): self
    {
        return new self('El entrenador ya tiene otra valoración a esa hora.', 'slot_taken');
    }

    public static function memberOverlap(): self
    {
        return new self('El socio ya tiene otra valoración a esa hora.', 'member_overlap');
    }

    public static function requestConflict(): self
    {
        return new self('Esta solicitud ya se usó para agendar otra cita. Vuelve a abrir el asistente.', 'request_conflict');
    }

    /** @param  array<string,mixed>|null  $actual  la cita como está ahora */
    public static function changed(?array $actual): self
    {
        return new self('La cita cambió mientras la mirabas. Revisa cómo quedó antes de seguir.', 'appointment_changed', 409, $actual !== null ? ['data' => $actual] : []);
    }

    public static function invalidTransition(string $desde, string $accion): self
    {
        return new self("Una cita {$desde} no admite «{$accion}».", 'invalid_transition');
    }

    public static function notDue(string $message): self
    {
        return new self($message, 'not_due');
    }

    public static function tooLate(string $message): self
    {
        return new self($message, 'too_late');
    }

    public static function trainerInactive(): self
    {
        return new self('El entrenador no está activo.', 'trainer_inactive', 422);
    }

    public static function memberNotFound(): self
    {
        return new self('El socio no existe.', 'member_not_found', 422);
    }

    public static function rangeTooWide(int $dias): self
    {
        return new self("El rango no puede superar {$dias} días.", 'range_too_wide', 422);
    }

    /** @param  list<array<string,mixed>>  $citas  uuid y horas, sin nombres */
    public static function blockOverlapsAppointments(array $citas): self
    {
        return new self(
            'El bloqueo pisa citas activas. Muévelas o cancélalas, o crea el bloqueo igualmente (force) sabiendo que NO se cancelan.',
            'block_overlaps_appointments',
            409,
            ['appointments' => $citas],
        );
    }

    /** @param  array<string,list<string>>  $errors */
    public static function rulesOverlap(array $errors): self
    {
        return new self('Hay franjas que se solapan el mismo día y en la misma vigencia.', 'rules_overlap', 422, ['errors' => $errors]);
    }
}
