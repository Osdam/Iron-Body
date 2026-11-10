<?php

namespace App\Services\UltronStudio\Stream;

use App\Support\Access\CrmPermission;

/**
 * El tema `voice.reconcile`: lo que deja la conciliación (`reconciled`,
 * `cost_recorded`, `unknown_provider_call`). Permiso: el de
 * `GET api/admin/voice/status` (`telephony.view`), el panel que enseña las
 * ambiguas, las atascadas y el estado del freno.
 */
final class VoiceReconcileTopic extends VoiceCallEventsTopic
{
    public const NAME = 'voice.reconcile';

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
        return 'GET api/admin/voice/status';
    }

    protected function kinds(): array
    {
        return self::RECONCILE_KINDS;
    }

    protected function prefix(): string
    {
        return 'voice_reconcile';
    }
}
