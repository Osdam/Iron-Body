<?php

namespace App\Services\Meta;

use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageStatus;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Registro de mensajes en una conversación comercial. Idempotente por
 * meta_message_id (un webhook reentregado no duplica el mensaje).
 */
class MetaConversationService
{
    /**
     * Guarda un mensaje entrante. Devuelve el mensaje (nuevo o existente).
     *
     * La idempotencia se apoya en el índice ÚNICO de la columna, no solo en el
     * SELECT previo: dos entregas simultáneas del mismo mensaje pueden pasar
     * ambas la comprobación, y entonces gana el índice y la segunda recupera la
     * fila que creó la primera.
     */
    public function recordInbound(
        MarketingConversation $conversation,
        ?string $metaMessageId,
        ?string $body,
        array $metadata = [],
    ): ?MarketingMessage {
        if ($metaMessageId !== null) {
            $existing = MarketingMessage::where('meta_message_id', $metaMessageId)->first();
            if ($existing !== null) {
                return $existing; // ya procesado
            }
        }

        try {
            $message = MarketingMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => MarketingMessage::DIRECTION_INBOUND,
                'sender_type' => MarketingMessage::SENDER_LEAD,
                'body' => $body,
                'meta_message_id' => $metaMessageId,
                'metadata' => $metadata ?: null,
                /*
                 * El identificador que nace en el webhook y cose todo el
                 * recorrido: evento, job, mensaje, decision y envio de vuelta.
                 *
                 * Faltaba SOLO en el mensaje entrante -el saliente si lo
                 * llevaba-, y sin el no se puede ir del webhook al mensaje que
                 * produjo. Es justo lo que hace falta cuando alguien pregunta
                 * por que no se contesto a una persona concreta.
                 */
                'correlation_id' => Context::get('correlation_id'),
            ]);
        } catch (Throwable $e) {
            // Choque contra el índice único → otra entrega ganó la carrera.
            if ($metaMessageId !== null) {
                $raced = MarketingMessage::where('meta_message_id', $metaMessageId)->first();
                if ($raced !== null) {
                    ChannelLog::info('meta.message.race_resolved', [
                        'meta_message_id' => $metaMessageId,
                        'conversation_id' => $conversation->id,
                    ]);

                    return $raced;
                }
            }

            throw $e;
        }

        // Bookkeeping del Inbox (aditivo): avanza timestamps y suma no-leídos.
        $conversation->forceFill([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'unread_count' => (int) $conversation->getAttribute('unread_count') + 1,
        ])->save();

        return $message;
    }

    /**
     * Registra el estado de entrega de un mensaje saliente (WhatsApp).
     *
     * Meta NO garantiza el orden de los callbacks: es normal recibir 'read'
     * antes que 'delivered', o un 'sent' rezagado minutos después. Solo se
     * permite AVANZAR en la escala sent → delivered → read; un callback tardío
     * se guarda en el historial con `applied=false` pero no toca el estado.
     *
     * 'failed' es la excepción: describe el resultado del envío, así que solo
     * aplica si el mensaje aún no había sido entregado ni leído.
     *
     * @param  array{code?:int,title?:string,message?:string,details?:string}|null  $error
     * @return bool ¿Cambió el estado actual del mensaje?
     */
    public function recordStatus(
        ?string $metaMessageId,
        string $status,
        ?array $error = null,
        int|string|null $occurredAt = null,
        array $metadata = [],
    ): bool {
        if ($metaMessageId === null) {
            return false;
        }

        $message = MarketingMessage::where('meta_message_id', $metaMessageId)->first();
        if ($message === null) {
            // Callback de un mensaje que no es nuestro (o que aún no terminó de
            // guardarse). No es un error: se deja constancia y se sigue.
            ChannelLog::info('meta.status.orphan', [
                'meta_message_id' => $metaMessageId,
                'status' => $status,
            ]);

            return false;
        }

        $currentRank = MarketingMessageStatus::rank($message->status);
        $incomingRank = MarketingMessageStatus::rank($status);

        $applies = $incomingRank > $currentRank
            || ($status === 'failed' && $currentRank <= 1 && $message->status !== 'failed');

        // Historial y estado se mueven juntos o no se mueven.
        DB::transaction(function () use ($message, $status, $applies, $error, $occurredAt, $metadata): void {
            MarketingMessageStatus::create([
                'message_id' => $message->id,
                'status' => $status,
                'applied' => $applies,
                'error_code' => $error['code'] ?? null,
                'error_title' => $error['title'] ?? null,
                'error_message' => $error['message'] ?? $error['details'] ?? null,
                'occurred_at' => $this->toTimestamp($occurredAt),
                'correlation_id' => Context::get('correlation_id'),
                'metadata' => $metadata ?: null,
            ]);

            if (! $applies) {
                return;
            }

            $changes = ['status' => $status];
            if ($status === 'failed' && ! empty($error)) {
                // El motivo legible se guarda junto al mensaje para que el
                // inbox no tenga que consultar el historial para pintarlo.
                $changes['metadata'] = array_merge((array) $message->metadata, [
                    'failure' => array_filter([
                        'code' => $error['code'] ?? null,
                        'title' => $error['title'] ?? null,
                        'message' => $error['message'] ?? null,
                        'details' => $error['details'] ?? null,
                    ], fn ($v) => $v !== null),
                ]);
            }

            $message->forceFill($changes)->save();
        });

        if (! $applies) {
            ChannelLog::info('meta.status.out_of_order', [
                'message_id' => $message->id,
                'current' => $message->status,
                'incoming' => $status,
            ]);
        }

        return $applies;
    }

    /** El epoch que reporta Meta; si no viene o es inválido, no se inventa. */
    private function toTimestamp(int|string|null $value): ?Carbon
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        try {
            return Carbon::createFromTimestamp((int) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
