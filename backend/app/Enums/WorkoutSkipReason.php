<?php

namespace App\Enums;

/**
 * Por qué el socio aplaza un ejercicio.
 *
 * Catálogo cerrado y no texto libre: el motivo se pulsa con el móvil en la mano
 * entre series, y sirve para leerlo agregado («la prensa siempre está ocupada a
 * las 7») — un campo abierto daría mil variantes de la misma frase y ninguna
 * consulta útil.
 *
 * OJO: un motivo NO convierte el salto en definitivo. El ejercicio sigue
 * pendiente. Ver {@see WorkoutExerciseStatus::SKIPPED_TEMPORARILY}.
 */
enum WorkoutSkipReason: string
{
    case MACHINE_BUSY = 'machine_busy';
    case DISCOMFORT = 'discomfort';
    case TIME_CONSTRAINT = 'time_constraint';
    case OTHER = 'other';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $r) => $r->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::MACHINE_BUSY => 'La máquina estaba ocupada',
            self::DISCOMFORT => 'Molestia o dolor',
            self::TIME_CONSTRAINT => 'Sin tiempo',
            self::OTHER => 'Otro motivo',
        };
    }
}
