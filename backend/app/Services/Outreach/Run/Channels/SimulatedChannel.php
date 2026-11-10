<?php

namespace App\Services\Outreach\Run\Channels;

use App\Services\Outreach\Run\LiveStrategy;
use DateTimeImmutable;

/**
 * El canal SIMULADO (contrato H9, D9-8 «Sombra», D9-9.1): NADA SALE. El
 * trabajo pasa a `simulated` y su resultado es `not_attempted` (fuente
 * `simulated`), que no abre ningún paso: la inscripción espera su ventana y
 * vence (`no_response`). Un trabajo simulado nunca cuenta como contacto ni
 * reserva presupuesto.
 */
final class SimulatedChannel implements OutreachChannel
{
    public const NAME = 'simulated';

    public function name(): string
    {
        return self::NAME;
    }

    public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt
    {
        return ChannelAttempt::simulated();
    }
}
