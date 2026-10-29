<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Un traslado de socios entre planes que no se puede hacer, dicho para el CRM.
 *
 * Excepción y no booleano, por la misma razón que {@see ReceivableException}:
 * un `false` que nadie mira es un traslado a medias. Y se lanza DENTRO de la
 * transacción cuando hace falta deshacer: al salir de ella no queda escrito ni
 * el lote, ni un ítem, ni un solo `users.plan`.
 *
 * Lleva su código HTTP y, cuando la simulación quedó vieja, la simulación
 * NUEVA: quien la recibe tiene que poder enseñarla sin pedir otra.
 */
class PlanTransferException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $simulation
     */
    public function __construct(
        string $message,
        public readonly string $code_,
        public readonly int $status = 422,
        public readonly ?array $simulation = null,
    ) {
        parent::__construct($message);
    }

    public static function samePlan(): self
    {
        return new self('El plan origen y el plan destino son el mismo.', 'same_plan');
    }

    public static function destinationArchived(): self
    {
        return new self('El plan destino está archivado: no se traslada a nadie a un plan que ya no se vende.', 'to_plan_archived');
    }

    public static function destinationInactive(): self
    {
        return new self('El plan destino está inactivo. Actívalo antes de trasladar socios a él.', 'to_plan_inactive');
    }

    public static function destinationWithoutDuration(): self
    {
        return new self('El plan destino no tiene una duración válida.', 'to_plan_invalid_duration');
    }

    /** El nombre no cabe en `users.plan` (ni en el lote) tal como está escrito. */
    public static function nameTooLong(string $code, int $max): self
    {
        return new self(
            ($code === 'to_plan_name_too_long' ? 'El nombre del plan destino' : 'El nombre del plan origen').
            ' tiene más de '.$max.' caracteres y no cabe en la ficha del socio. Acórtalo antes de trasladar.',
            $code,
        );
    }

    /**
     * Dos planes que se escriben igual salvo tildes o mayúsculas. El acceso
     * resuelve `users.plan` por nombre normalizado y se quedaría con uno de los
     * dos al azar: trasladar ahí sería no saber a dónde.
     *
     * @param  list<string>  $nombres
     */
    public static function ambiguousName(array $nombres): self
    {
        return new self(
            'Hay planes que se escriben igual salvo tildes o mayúsculas ('.implode(', ', $nombres).
            '). Renombra uno antes de trasladar: el acceso no sabría cuál es cuál.',
            'ambiguous_plan_name',
        );
    }

    public static function nothingToTransfer(string $message = 'Ninguno de los socios elegidos se puede trasladar.'): self
    {
        return new self($message, 'nothing_to_transfer');
    }

    public static function warningsNotConfirmed(): self
    {
        return new self('Hay advertencias sin confirmar. Revísalas y marca que las leíste antes de ejecutar.', 'warnings_not_confirmed');
    }

    /** @param  array<string, mixed>  $simulation */
    public static function stale(array $simulation): self
    {
        return new self(
            'Algo cambió desde que simulaste. Revisa la simulación nueva antes de confirmar.',
            'simulation_stale',
            409,
            $simulation,
        );
    }

    public static function idempotencyConflict(): self
    {
        return new self(
            'Esa operación ya se usó para otro traslado. Vuelve a simular para obtener una nueva.',
            'idempotency_conflict',
            409,
        );
    }

    public static function notRevertible(): self
    {
        return new self('Una reversión no se revierte. Si hace falta, haz un traslado nuevo.', 'reversal_not_revertible');
    }

    /** @param  list<int>  $ids */
    public static function notInBatch(array $ids): self
    {
        return new self('Estos socios no están en el lote: '.implode(', ', $ids).'.', 'not_in_batch');
    }
}
