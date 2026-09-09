<?php

namespace App\Enums;

/**
 * En qué punto está UN ejercicio dentro de una sesión de entrenamiento.
 *
 * Existe para separar dos cosas que hoy se confunden: por dónde va el socio
 * (navegación) y qué ha terminado de verdad (completitud). El índice del
 * ejercicio en pantalla no dice nada sobre lo segundo, y usarlo como si lo
 * dijera es lo que permitía finalizar una rutina de cinco ejercicios habiendo
 * hecho uno.
 *
 * SKIPPED_TEMPORARILY es TEMPORAL, y esa palabra es la regla entera: «lo dejo
 * para luego» no es «no lo voy a hacer». Un ejercicio saltado sigue contando
 * como pendiente y sigue impidiendo cerrar la sesión, exactamente igual que si
 * no se hubiera tocado. No existe la omisión definitiva: si existiera, bastaría
 * con saltarlo todo para cobrar el día entrenado.
 */
enum WorkoutExerciseStatus: string
{
    /** Sin empezar. */
    case PENDING = 'pending';

    /** Empezado, con series a medio marcar. */
    case IN_PROGRESS = 'in_progress';

    /** Aplazado dentro de la misma sesión. Sigue pendiente. */
    case SKIPPED_TEMPORARILY = 'skipped_temporarily';

    /** Terminado de verdad: es el ÚNICO estado que deja cerrar la sesión. */
    case COMPLETED = 'completed';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $s) => $s->value, self::cases());
    }

    /**
     * ¿Este estado impide dar la rutina por terminada?
     *
     * Todo menos COMPLETED. Está escrito como negación de un único caso a
     * propósito: si mañana se añade un estado nuevo, bloqueará por defecto en
     * vez de colarse en la lista de los que dejan cerrar.
     */
    public function blocksCompletion(): bool
    {
        return $this !== self::COMPLETED;
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::IN_PROGRESS => 'En curso',
            self::SKIPPED_TEMPORARILY => 'Saltado por ahora',
            self::COMPLETED => 'Completado',
        };
    }
}
