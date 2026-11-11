<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\VoiceSecretList;
use App\Services\Voice\Dto\VoiceWebhookList;

/** El lector de los webhooks por defecto (auditoría B-T5): no habla con nadie. Siempre «desconocido», sin red. */
final class NullVoiceWebhookObserver implements VoiceWebhookObserver
{
    public function describeWebhooks(): VoiceWebhookList
    {
        return VoiceWebhookList::unknown();
    }

    public function describeSecrets(): VoiceSecretList
    {
        return VoiceSecretList::unknown();
    }
}
