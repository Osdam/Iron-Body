<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\VoiceWebhookVerdict;

/**
 * Verificar un webhook post-llamada (contrato H8, D8-3 y D8-16): firma + sobre
 * → evento neutro. Pura salvo el secreto del webhook, que lee su
 * implementación. Sin red, sin base y sin registro.
 *
 * Nunca conserva la transcripción ni el audio: el veredicto lleva solo lo que
 * enumera el contrato, y los turnos del agente (para la guarda posterior)
 * viven en memoria.
 */
interface VoiceEventVerifier
{
    /** @param  int  $now  segundos Unix de quien recibe (la telefonía; aquí no hay reloj) */
    public function verifyPostCall(string $rawBody, ?string $signatureHeader, int $now): VoiceWebhookVerdict;
}
