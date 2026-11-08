<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\VoiceNumberAssignment;

/** El lector del número por defecto (contrato H8, E4-8): no habla con nadie. Siempre «desconocido», sin red. */
final class NullVoiceNumberObserver implements VoiceNumberObserver
{
    public function describeNumber(string $phoneNumberId): VoiceNumberAssignment
    {
        return VoiceNumberAssignment::unknown();
    }
}
