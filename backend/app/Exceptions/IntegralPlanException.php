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
