<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * La generación de un plan integral no pudo completarse.
 *
 * Excepción y no un `null` a propósito: cada motivo lleva su código, y el
 * entrenador necesita saber si puede volver a intentarlo —el modelo tardó, la
 * cuota del día se agotó— o si hay algo que corregir antes.
 *
 * Lo que NUNCA ocurre es quedarse a medias: si algo de esto se lanza, no hay
 * borrador de nutrición ni de rutina. Ver IntegralPlanGenerationService.
 */
class IntegralPlanException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $code_ = 'integral_plan_error',
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    /** Iron IA está apagado o sin credencial. Reintentar no ayuda. */
    public static function disabled(): self
    {
        return new self('Iron IA no está disponible en este momento.', 'ai_disabled');
    }

    /** Se agotó la cuota del día. Mañana sí. */
    public static function costGuard(string $reason): self
    {
        return new self(
            'Se alcanzó el límite de generaciones de hoy. Inténtalo mañana o escribe el plan a mano.',
            $reason,
        );
    }

    /** El modelo no respondió o respondió mal. Volver a intentar es razonable. */
    public static function upstream(?string $detail = null): self
    {
        return new self(
            'Iron IA no pudo generar el plan. Vuelve a intentarlo.',
            'ai_upstream_error',
            retryable: true,
        );
    }

    /**
     * Ya hay una generación en curso para este socio (A1). Reintentable: en
     * cuanto termine la otra, ésta puede ir. HTTP 409.
     */
    public static function busy(): self
    {
        return new self(
            'Iron IA ya está preparando un plan para este socio. Espera a que termine y vuelve a intentarlo.',
            'generation_in_progress',
            retryable: true,
        );
    }

    /**
     * El entrenador superó su tope de generaciones por ventana (A1). No es
     * reintentable YA: reintentar en seguida vuelve a chocar. HTTP 429.
     */
    public static function trainerRateLimited(int $seconds): self
    {
        $minutos = max(1, (int) ceil($seconds / 60));

        return new self(
            "Generaste muchos planes seguidos con Iron IA. Inténtalo de nuevo en {$minutos} min.",
            'trainer_rate_limited',
        );
    }

    /**
     * La generación no cupo en su plazo (potencias 2..5). La app corta a los
     * 20 s; un plan que llega después nadie lo ve, así que se falla antes y
     * sin guardar nada. Reintentable: la siguiente puede caber.
     *
     * El mensaje nombra a Iron IA a propósito: la app clasifica el error por
     * el texto y así lo enseña como fallo de la IA, con su «reintentar».
     */
    public static function deadline(): self
    {
        return new self(
            'Iron IA no alcanzó a preparar el plan a tiempo. Vuelve a intentarlo.',
            'ai_deadline',
            retryable: true,
        );
    }

    /**
     * Lo que llegó no cumple el contrato. Es reintentable porque el modelo
     * puede acertar a la siguiente, pero NO se guarda nada de lo que mandó.
     */
    public static function malformed(string $detail): self
    {
        return new self(
            'Iron IA devolvió un plan incompleto: '.$detail.'. Vuelve a intentarlo.',
            'ai_invalid_output',
            retryable: true,
        );
    }

    /** No hay con qué generar: el gimnasio no tiene ejercicios cargados. */
    public static function emptyCatalog(): self
    {
        return new self(
            'No hay ejercicios en el catálogo con los que armar una rutina.',
            'empty_exercise_catalog',
        );
    }
}
