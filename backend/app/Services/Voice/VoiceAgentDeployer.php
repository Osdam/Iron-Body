<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceDeployResult;

/**
 * Desplegar agentes de voz en un proveedor (contrato H8, D8-3 y D8-7). Una
 * interfaz SEGREGADA: el puerto del H6 ({@see VoiceAgentProvider}) no cambia.
 *
 * Ninguna implementación lanza por la red: los fallos son resultados. Ninguna
 * sigue redirecciones ni reintenta.
 */
interface VoiceAgentDeployer
{
    /**
     * Crea un agente NUEVO con la carga compilada (sus herramientas primero).
     * Nunca actualiza uno existente: un agente que atendió llamadas no se toca
     * (D8-7). Un tiempo agotado o un 5xx es `ambiguous`: el agente puede existir.
     */
    public function createAgent(VoiceAgentPayload $payload): VoiceDeployResult;

    /** Solo GET: la proyección observada, para compararla con la compilada (deriva). */
    public function describeAgent(string $agentId): VoiceAgentObservation;

    /**
     * Asigna el número importado al agente que atiende las entrantes; null lo
     * deja sin agente (voz apagada, D8-20 nivel 2). Asignar un agente abre las
     * entrantes: con las llamadas cerradas en el código es `not_attempted`, sin
     * red. Dejarlo sin agente siempre se puede.
     */
    public function assignNumber(string $providerNumberId, ?string $agentId): VoiceDeployResult;
}
