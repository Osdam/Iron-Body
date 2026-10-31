<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Archivar o reactivar un plan que su estado REAL no admite. Se pinta sola
 * (Laravel llama a `render()`), como CorrectionException: el CRM decide qué
 * enseñar por el `code` y por la lista de motivos, no por el texto.
 */
class PlanArchiveException extends RuntimeException
{
    /**
     * @param  list<array{code: string, message: string}>  $reasons
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
}
