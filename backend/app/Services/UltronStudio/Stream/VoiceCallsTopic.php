<?php

namespace App\Services\UltronStudio\Stream;

use App\Models\Voice\VoiceCallEvent;
use App\Support\Access\CrmPermission;

/**
 * El tema `voice.calls`: el ciclo de vida de las llamadas (pedida, marcando,
 * rechazada, ambigua, iniciación, post-llamada, herramientas, veredicto de la
 * guarda, colgado, sello…). Permiso: el de `GET api/admin/voice/calls`
 * (`telephony.view`).
 */
final class VoiceCallsTopic extends VoiceCallEventsTopic
{
    public const NAME = 'voice.calls';

    public function name(): string
    {
        return self::NAME;
    }

    public function permission(): string
    {
        return CrmPermission::TELEPHONY_VIEW;
    }

    public function listRoute(): ?string
    {
        return 'GET api/admin/voice/calls';
    }

    protected function kinds(): array
    {
        return array_values(array_diff(VoiceCallEvent::KINDS, self::RECONCILE_KINDS));
    }

    protected function prefix(): string
    {
        return 'voice_call';
    }
}
