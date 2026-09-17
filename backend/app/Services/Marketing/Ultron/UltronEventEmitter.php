<?php

namespace App\Services\Marketing\Ultron;

use App\Jobs\SendUltronEventToN8n;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Services\Observability\ChannelLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;

/**
 * El puente por el que un mensaje entrante llega a ULTRON.
 *
 * Se engancha en el enrutado del entrante, DESPUÉS de persistir, deduplicar y
 * aplicar las barreras de siempre, y es INDEPENDIENTE de `auto_analyze`: ese
 * flag gobierna al cerebro local, que en la v1 está apagado precisamente para
 * que no haya dos agentes decidiendo sobre la misma persona.
 *
 * Lo que sale de aquí es lo mínimo para que ULTRON pueda pedir contexto: ni
 * teléfono, ni wa_id, ni identificadores de Meta más allá del `wamid`, que se
 * usa como clave de idempotencia porque es lo único que Meta garantiza estable
 * entre reentregas.
 */
class UltronEventEmitter
{
    /**
     * Motivo por el que este mensaje no se le cuenta a ULTRON, o null.
     *
     * El orden va de lo más barato a lo más caro y de lo más importante a lo
     * menos: primero el interruptor, después las decisiones de la persona,
     * después la forma del mensaje.
     */
    public function ineligibleReason(
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $parsed = [],
    ): ?string {
        if (! (bool) config('marketing.ultron.enabled', false)) {
            return 'ultron_disabled';
        }

        /*
         * Cerrojo del canario: una conversación, no «las que haya».
         *
         * Va aquí, pegado al interruptor, porque es de la misma clase: una
         * decisión de operación que se toma antes de mirar nada del mensaje.
         * Y va ANTES de crear el evento, no después, para que una conversación
         * ajena no deje ni rastro en la cola.
         *
         * No sustituye a ninguna barrera: si la conversación ES la del
         * canario, debajo siguen mandando el opt-out, el takeover y ai_enabled.
         */
        $canario = config('marketing.ultron.canary_conversation_id');
        if ($canario !== null && (int) $canario !== (int) $conversation->id) {
            return 'not_canary_conversation';
        }

        $lead = $conversation->lead;

        if ($lead === null) {
            return 'lead_missing';
        }
        if ($conversation->channel !== 'whatsapp') {
            return 'channel_not_supported';
        }
        if ($message->direction !== MarketingMessage::DIRECTION_INBOUND) {
            return 'not_inbound';
        }
        if ($message->sender_type !== MarketingMessage::SENDER_LEAD) {
            return 'not_from_lead';
        }
        if (! $lead->canReplyReactively()) {
            return 'do_not_contact';
        }
        if ($conversation->human_takeover && $conversation->human_takeover_source === 'manual') {
            return 'human_takeover';
        }
        if (! $conversation->ai_enabled) {
            return 'ai_disabled';
        }

        // Una reacción o un callback de estado no son una consulta.
        $tipo = (string) ($parsed['message_type'] ?? 'text');
        if ($tipo === 'reaction') {
            return 'reaction_not_actionable';
        }
        if (trim((string) $message->body) === '') {
            return 'no_readable_text';
        }

        return null;
    }

    /**
     * Registra el evento y lo despacha. Devuelve la fila, o null si no procedía.
     *
     * Best-effort por diseño: si esto falla, el mensaje del prospecto YA está
     * guardado y visible en el Inbox. Perder un evento hacia ULTRON es molesto;
     * perder el mensaje sería perder al cliente.
     */
    public function emit(
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $parsed = [],
    ): ?MarketingAutomationEvent {
        $reason = $this->ineligibleReason($conversation, $message, $parsed);

        if ($reason !== null) {
            // Con el flag apagado ni se registra: si no, cada mensaje dejaría
            // una fila inútil mientras ULTRON está dormido.
            if ($reason !== 'ultron_disabled') {
                ChannelLog::info('ultron.event.skipped', [
                    'reason' => $reason,
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                ]);
            }

            return null;
        }

        $key = $this->idempotencyKey($message);

        try {
            $event = MarketingAutomationEvent::create([
                'event_type' => MarketingAutomationEvent::TYPE_MESSAGE_RECEIVED,
                'lead_id' => $conversation->lead_id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'payload_json' => $this->payload($conversation, $message, $parsed),
                'status' => MarketingAutomationEvent::STATUS_PENDING,
                'idempotency_key' => $key,
                'correlation_id' => Context::get('correlation_id'),
            ]);
        } catch (QueryException) {
            // Ya existía: una reentrega de Meta o un replay del evento. La
            // unicidad la garantiza la base de datos, no una comprobación
            // previa que dos procesos pueden pasar a la vez.
            ChannelLog::info('ultron.event.duplicate', [
                'idempotency_key' => $key,
                'conversation_id' => $conversation->id,
            ]);

            return null;
        }

        SendUltronEventToN8n::dispatch($event->id);

        ChannelLog::info('ultron.event.queued', [
            'event_id' => $event->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ]);

        return $event;
    }

    /**
     * El `wamid` de Meta si lo hay; si no, el id del mensaje.
     *
     * El `wamid` es lo único estable entre reentregas del mismo webhook, así
     * que es la clave correcta. El id propio es el respaldo para los mensajes
     * que no nacieron de Meta (la simulación, las pruebas).
     */
    private function idempotencyKey(MarketingMessage $message): string
    {
        $wamid = trim((string) $message->meta_message_id);

        return $wamid !== '' ? $wamid : 'msg:'.$message->id;
    }

    /**
     * Lo mínimo. Sin teléfono, sin wa_id, sin nombre completo.
     *
     * ULTRON responde por `conversation_id` y pide el contexto que necesite a
     * `/ai/decide`; no hace falta que este evento lleve nada más, y lo que no
     * viaja no se filtra.
     *
     * @return array<string,mixed>
     */
    private function payload(
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $parsed,
    ): array {
        return [
            'event_id' => (string) (Context::get('correlation_id') ?? ('msg-'.$message->id)),
            'message_id' => (int) $message->id,
            'meta_message_id' => $message->meta_message_id,
            'conversation_id' => (int) $conversation->id,
            'contact_id' => (int) $conversation->lead_id,
            'direction' => 'INBOUND_USER',
            'message_type' => (string) ($parsed['message_type'] ?? 'text'),
            'text' => (string) $message->body,
            'timestamp' => optional($message->created_at)->toIso8601String(),
            'channel' => $conversation->channel,
        ];
    }
}
