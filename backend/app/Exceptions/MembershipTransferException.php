<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Un traspaso de membresía (o su reversión) que el estado REAL no admite.
 *
 * Se lanza DENTRO de la transacción, con los socios ya bloqueados: al salir no
 * queda escrita ni la fila del traspaso ni una sola fecha. Lleva todos los
 * bloqueos, no solo el primero, para que el CRM enseñe lo mismo que habría
 * enseñado la vista previa si se hubiera pedido en ese instante. Se pinta sola
 * (Laravel llama a `render()`), como CorrectionException.
 */
class MembershipTransferException extends RuntimeException
{
    /**
     * @param  list<array{code: string, message: string}>  $blockers
     */
    public function __construct(
        string $message,
        public readonly string $code_,
        public readonly int $status = 409,
        public readonly array $blockers = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'ok' => false,
            'code' => $this->code_,
            'message' => $this->getMessage(),
            'blockers' => $this->blockers ?: null,
        ], static fn ($v) => $v !== null), $this->status);
    }

    /** @param  list<array{code: string, message: string}>  $blockers */
    public static function blocked(array $blockers): self
    {
        return new self($blockers[0]['message'] ?? 'No se puede transferir esta membresía.', 'transfer_blocked', 409, $blockers);
    }

    /** @param  list<array{code: string, message: string}>  $blockers */
    public static function revertBlocked(array $blockers): self
    {
        return new self($blockers[0]['message'] ?? 'No se puede revertir este traspaso.', 'revert_blocked', 409, $blockers);
    }

    public static function requestIdConflict(): self
    {
        return new self('Ese identificador de petición ya se usó para otro traspaso.', 'client_request_id_conflict', 422);
    }
}
