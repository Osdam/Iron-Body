<?php

namespace Tests\Support;

use App\Models\MarketingAutomationEvent;
use App\Models\MarketingMessage;

/**
 * El turno de un entrante, tal y como lo abre producción.
 *
 * `UltronDecideService::supersededBy()` sólo cede el turno a un mensaje que
 * vaya a tener turno propio, y eso se mide por la fila de
 * `marketing_automation_events` que escribe `UltronEventEmitter::emit()`. Un
 * mensaje insertado a mano en una prueba no la tiene: para reproducir un relevo
 * de verdad hay que abrir también su turno, porque un relevo sin turno es
 * exactamente el fallo que se corrigió (una nota de voz mataba la pregunta
 * anterior y nadie contestaba ninguna de las dos).
 */
trait UltronTurnEvents
{
    /** Abre el turno de ULTRON para ese entrante: la fila que lo hace relevable. */
    protected function abreTurno(
        MarketingMessage $message,
        string $status = MarketingAutomationEvent::STATUS_SENT,
    ): MarketingAutomationEvent {
        return MarketingAutomationEvent::create([
            'event_type' => MarketingAutomationEvent::TYPE_MESSAGE_RECEIVED,
            'lead_id' => $message->conversation?->lead_id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'payload_json' => ['message_id' => $message->id],
            'status' => $status,
            'idempotency_key' => 'evt:test:'.$message->id,
        ]);
    }
}
