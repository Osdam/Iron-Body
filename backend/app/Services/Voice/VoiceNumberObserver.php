<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\VoiceNumberAssignment;

/**
 * Leer a qué agente apunta el número importado (contrato H8, D8-21; Enmienda
 * 2, E2-3; Enmienda 4, E4-8: la deriva del número). Una interfaz SEGREGADA,
 * como {@see VoiceAgentDeployer} lo es del puerto del H6: así el desplegador
 * no cambia de forma y nadie que lo implemente tiene que aprender a leer
 * números. La implementa {@see ElevenLabs\ElevenLabsAgentDeployer}, que ya
 * habla con `/v1/convai/phone-numbers/`.
 *
 * Solo GET. Ninguna implementación lanza por la red: los fallos son
 * resultados. Ninguna sigue redirecciones ni reintenta.
 */
interface VoiceNumberObserver
{
    /** Solo GET: el agente asignado al número (o ninguno); sin red, «desconocido». */
    public function describeNumber(string $phoneNumberId): VoiceNumberAssignment;
}
