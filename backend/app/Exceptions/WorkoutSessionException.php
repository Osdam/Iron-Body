<?php

namespace App\Exceptions;

use App\Enums\WorkoutExerciseStatus;
use App\Enums\WorkoutSkipReason;
use RuntimeException;

/**
 * La sesión que llega no se puede dar por terminada.
 *
 * Es una excepción y no un `false` por lo mismo que {@see ReceivableException}:
 * un valor de retorno que el llamador ignore acabaría registrando el
 * entrenamiento igual, que es justo lo que hay que impedir.
 *
 * Solo aplica al CONTRATO NUEVO, el que declara estado por ejercicio. Las apps
 * que aún mandan el payload antiguo no pueden expresar ese estado, así que
 * exigírselo las dejaría sin poder cerrar un entrenamiento; para ellas la
 * política sigue siendo la de siempre. Ver `LEGACY_COMPLETION_POLICY` en
 * {@see \App\Services\WorkoutSessionService}.
 */
class WorkoutSessionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $code_ = 'workout_session_error',
    ) {
        parent::__construct($message);
    }

    /**
     * Quedan ejercicios sin terminar.
     *
     * El mensaje nombra CUÁNTOS y en qué estado: quien está en la sala necesita
     * saber qué le falta, y un «no se puede finalizar» a secas obliga a
     * adivinarlo.
     */
    public static function incomplete(int $pending): self
    {
        return new self(
            $pending === 1
                ? 'Te queda 1 ejercicio por completar antes de terminar la rutina.'
                : "Te quedan {$pending} ejercicios por completar antes de terminar la rutina.",
            'workout_incomplete',
        );
    }

    /**
     * Payload a medias: unos ejercicios declaran estado y otros no.
     *
     * Se rechaza en vez de rellenar los que faltan. Adivinar el estado de un
     * ejercicio es exactamente el error que esta fase viene a corregir, y un
     * payload así solo puede venir de un cliente roto.
     */
    public static function mixedContract(): self
    {
        return new self(
            'El entrenamiento llegó incompleto. Actualiza la aplicación e inténtalo de nuevo.',
            'workout_mixed_contract',
        );
    }

    /** Estado que no existe. La lista va en el mensaje para depurar el cliente. */
    public static function invalidStatus(string $value): self
    {
        $valid = implode(', ', WorkoutExerciseStatus::values());

        return new self(
            "Estado de ejercicio no válido: «{$value}». Valores admitidos: {$valid}.",
            'workout_invalid_status',
        );
    }

    /** Motivo de salto fuera del catálogo cerrado. */
    public static function invalidSkipReason(string $value): self
    {
        $valid = implode(', ', WorkoutSkipReason::values());

        return new self(
            "Motivo de salto no válido: «{$value}». Valores admitidos: {$valid}.",
            'workout_invalid_skip_reason',
        );
    }
}
