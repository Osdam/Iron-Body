<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Violación de una regla de negocio de las guías nutricionales (editar una
 * publicada, corregir un borrador, publicar sin plan…). Lleva el código HTTP a
 * devolver para que el portal explique qué pasó en vez de un 500 genérico.
 *
 * Gemela de {@see AssessmentException}: mismo dominio, mismas garantías.
 */
class NutritionGuideException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function notEditable(): self
    {
        return new self('Esta guía ya fue publicada y no se puede editar. Crea una corrección.', 409);
    }

    public static function notPublishable(): self
    {
        return new self('Solo un borrador puede publicarse.', 409);
    }

    public static function notAmendable(): self
    {
        return new self('Solo una guía publicada puede corregirse.', 409);
    }

    public static function amendmentReasonRequired(): self
    {
        return new self('Indica el motivo de la corrección.', 422);
    }

    public static function voidReasonRequired(): self
    {
        return new self('Indica el motivo de la anulación.', 422);
    }

    public static function notVoidable(): self
    {
        return new self('Solo una guía publicada puede anularse.', 409);
    }

    /**
     * Publicar una guía sin plan de alimentación deja al socio con un documento
     * que no le dice qué comer, que es lo único que venía a buscar.
     */
    public static function emptyMealPlan(): self
    {
        return new self('La guía necesita al menos una comida en el plan de alimentación.', 422);
    }

    public static function objectiveRequired(): self
    {
        return new self('Indica el objetivo de la guía antes de publicarla.', 422);
    }
}
