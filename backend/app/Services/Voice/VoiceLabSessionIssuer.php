<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\VoiceLabSession;

/**
 * Emitir la URL firmada de una sesión del laboratorio hablado (contrato H8,
 * D8-3 y D8-23): voz en el navegador contra un agente de laboratorio, sin
 * teléfono. La clave nunca sale del servidor; la URL caduca (≤ 15 min).
 */
interface VoiceLabSessionIssuer
{
    public function signedSessionUrl(string $agentId): VoiceLabSession;
}
