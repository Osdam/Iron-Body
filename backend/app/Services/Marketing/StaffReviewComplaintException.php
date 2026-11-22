<?php

namespace App\Services\Marketing;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Lo que no se permite al resolver una revisión marcada como queja con su
 * categoría (Q1-8): un código ESTABLE y el estado HTTP, con la forma de error
 * del Inbox (`ok`, `code`, `message`). Los mensajes no llevan internals ni
 * datos de la persona. Un rechazo previsto no va al log de errores; un fallo
 * del registro (503), sí.
 */
class StaffReviewComplaintException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public function report(): bool
    {
        return $this->status < 500;
    }

    public function render(): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => $this->errorCode, 'message' => $this->getMessage()], $this->status);
    }
}
