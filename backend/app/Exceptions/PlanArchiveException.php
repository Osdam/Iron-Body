<?php

namespace App\Exceptions;

use App\Support\Access\PlanCommercialText;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Archivar o reactivar un plan que su estado REAL no admite. Se pinta sola
 * (Laravel llama a `render()`), como CorrectionException: el CRM decide qué
 * enseñar por el `code` y por la lista de motivos, no por el texto.
 */
class PlanArchiveException extends RuntimeException
{
    /** PT-14: los textos del 403 `plan_commercial_text_forbidden`, por motivo (contrato §5 A). */
    private const COMMERCIAL_TEXT_MESSAGES = [
        'text_changed' => 'Cambiar el nombre o los beneficios de un plan que se ofrece fuera (ULTRON, Sofía, enlaces de pago y la app) pide el permiso “Nombre y beneficios” de Planes. Guarda el resto sin tocarlos, o pide a quien lo tenga que los cambie.',
        'becomes_sellable' => 'Poner a la venta un plan (crearlo o activarlo con “Se ofrece fuera: Sí”, darle precio o vigencia, o reactivarlo) pide el permiso “Nombre y beneficios” de Planes: así ningún texto llega a la venta sin que lo decida quien tiene ese permiso. Puedes guardarlo con “Se ofrece fuera: No”.',
    ];

    /**
     * @param  list<array{code: string, message: string}>|list<string>  $reasons
     */
    public function __construct(
        string $message,
        public readonly string $code_,
        public readonly int $status = 422,
        public readonly array $reasons = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'ok' => false,
            'code' => $this->code_,
            'message' => $this->getMessage(),
            'reasons' => $this->reasons ?: null,
        ], static fn ($v) => $v !== null), $this->status);
    }

    /** @param  list<array{code: string, message: string}>  $reasons */
    public static function notReactivable(array $reasons): self
    {
        return new self(
            'No se puede reactivar: '.implode(' ', array_column($reasons, 'message')),
            'plan_not_reactivable',
            422,
            $reasons,
        );
    }

    public static function archivedEdit(): self
    {
        return new self(
            'El plan está archivado: no se activa ni se enseña en la app editándolo. Usa «Reactivar plan».',
            'plan_archived',
            422,
        );
    }

    /**
     * PT-14 · A: la escritura pide «Nombre y beneficios» (`plans.commercial_text`)
     * y quien la hace no lo tiene. `reasons`: `text_changed` o `becomes_sellable`
     * ({@see PlanCommercialText}).
     */
    public static function commercialTextForbidden(string $reason): self
    {
        return new self(
            self::COMMERCIAL_TEXT_MESSAGES[$reason],
            'plan_commercial_text_forbidden',
            403,
            [$reason],
        );
    }
}
