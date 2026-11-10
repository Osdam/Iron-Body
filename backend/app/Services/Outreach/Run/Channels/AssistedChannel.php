<?php

namespace App\Services\Outreach\Run\Channels;

use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\LiveStrategy;
use App\Services\Outreach\Run\OutreachConfig;
use DateTimeImmutable;

/**
 * El canal ASISTIDO (contrato H9, D9-9.5; §13.1 #22): una PERSONA ejecuta lo
 * que ULTRON decidió. «Salir» (`started`) es que el trabajo aparezca en la
 * bandeja asistida con la estrategia, el objetivo, el motivo (la traza), el
 * guion de la plantilla y el plazo; la persona lo reclama (una vez) y
 * registra el resultado del conjunto cerrado (fuente `staff`): eso lo hace
 * {@see AssistedQueue}. La política ya aplicó
 * horario, frecuencia y consentimiento DEL MEDIO (WhatsApp o voz); la persona
 * sigue siendo responsable del contacto real. Ningún teléfono viaja por aquí.
 *
 * La capacidad diaria la reservó la etapa 5 (`channel:assisted`, el cupo de
 * `COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP`); con la bandera del canal
 * apagada entre planificar y despachar, no sale (`channel_disabled`).
 */
final class AssistedChannel implements OutreachChannel
{
    public const NAME = 'assisted';

    public function name(): string
    {
        return self::NAME;
    }

    public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt
    {
        if (! OutreachConfig::isLive() || ! OutreachConfig::assistedEnabled()) {
            return ChannelAttempt::failed('channel_disabled');
        }
        if (! in_array($job->medium, ['whatsapp', 'voice'], true)) {
            return ChannelAttempt::failed('assisted_medium_missing');
        }

        // La tarea ES la fila del trabajo en `started`: la bandeja la lee de ahí (sin teléfonos).
        return ChannelAttempt::started();
    }
}
