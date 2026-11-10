<?php

namespace App\Services\Outreach\Run;

use RuntimeException;

/** La reserva no cupo en algún alcance (D9-8, etapa 5): se deshace la transacción del trabajo y la inscripción se aplaza (`budget_exhausted`). */
final class BudgetExhausted extends RuntimeException
{
    public function __construct(public readonly string $scope)
    {
        parent::__construct('budget_exhausted:'.$scope);
    }
}
