<?php

namespace App\Services\Outreach\Run\Channels;

use App\Services\Outreach\Run\LiveStrategy;
use DateTimeImmutable;

/**
 * La PUERTA VACÍA de WhatsApp por plantillas (contrato H9, D9-9.3, paquete
 * W9; §13.1 #18–#20): el 15-11 NO EXISTE camino hacia Meta. La escalera ya
 * lo niega antes (`channel_not_live` en vivo; aviso en sombra, L9-16) y la
 * constante `OutreachSendGate::SENDS_ENABLED_IN_CODE` lo cierra por encima;
 * si un trabajo llegara aquí, falla al empezar con `channel_not_live`, su
 * resultado es `failed` con ese detalle y la reserva se libera: nunca queda
 * vivo ni se reintenta fuera de la regla.
 *
 * W9 la implementará POR LA ÚNICA PUERTA de WhatsApp
 * (`MarketingMessageDispatcher::dispatchWhatsappTemplate` → outbox, con
 * `metadata.outreach_job_uuid` y la regla de `meta_message_id`): este espacio
 * nunca nombra a Meta ni al outbox (protección 4).
 */
final class WhatsappTemplateChannel implements OutreachChannel
{
    public const NAME = 'whatsapp_template';

    public const NOT_LIVE = 'channel_not_live';

    public function name(): string
    {
        return self::NAME;
    }

    public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt
    {
        return ChannelAttempt::failed(self::NOT_LIVE);
    }
}
