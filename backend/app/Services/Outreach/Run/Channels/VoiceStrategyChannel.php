<?php

namespace App\Services\Outreach\Run\Channels;

use App\Services\Outreach\Run\LiveStrategy;
use DateTimeImmutable;

/**
 * La PUERTA VACÍA de la voz por estrategia (contrato H9, D9-9.4, paquete V9
 * como Enmienda 16 del H8; §13.1 #18, #19): el 15-11 «una automatización no
 * llama». La escalera lo niega antes (`channel_not_live`, `budget_zero`) y la
 * constante lo cierra por encima; un trabajo que llegara aquí falla al
 * empezar con `channel_not_live` y su reserva se libera. V9 la implementará
 * por `OutboundGate::requestForStrategy` (la misma `decide()` y los 26
 * motivos de `OutboundPolicy`), nunca llamando a Twilio ni a ElevenLabs desde
 * este espacio (protección 4).
 */
final class VoiceStrategyChannel implements OutreachChannel
{
    public const NAME = 'voice_call';

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
