<?php

namespace App\Observers\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mantiene en la conversación el texto de su último mensaje.
 *
 * Es un observador y no una línea dentro del servicio de envío porque los
 * mensajes nacen por seis caminos distintos —webhook de Meta, envío manual del
 * inbox, agente comercial, endpoint interno, reintentos del outbox y lo que
 * venga— y basta que uno se olvide para que la lista enseñe un texto viejo. Un
 * observador escucha la TABLA: ningún camino, ni los futuros, puede saltárselo.
 *
 * Nunca lanza. Una previsualización es comodidad para la lista; que falle no
 * puede tumbar la recepción de un mensaje de un cliente.
 */
class ConversationPreviewObserver
{
    /** Lo que cabe en una fila de la lista sin desbordarla. */
    private const LENGTH = 160;

    public function created(MarketingMessage $message): void
    {
        $this->refresh($message);
    }

    /**
     * Un mensaje editado cambia la previsualización solo si sigue siendo el
     * último. Comprobarlo cuesta una consulta y evita que un cambio de estado
     * en un mensaje viejo reescriba la lista con texto atrasado.
     */
    public function updated(MarketingMessage $message): void
    {
        if (! $message->wasChanged('body')) {
            return;
        }

        $this->refresh($message);
    }

    /**
     * Al borrar el último mensaje, la previsualización queda apuntando a algo
     * que ya no existe. Se recalcula desde la tabla.
     */
    public function deleted(MarketingMessage $message): void
    {
        $this->recompute((int) $message->conversation_id);
    }

    private function refresh(MarketingMessage $message): void
    {
        $conversationId = (int) $message->conversation_id;

        if ($conversationId === 0) {
            return;
        }

        try {
            $latest = MarketingMessage::query()
                ->where('conversation_id', $conversationId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['id', 'body']);

            // El mensaje que acaba de llegar no siempre es el último: un
            // reproceso puede insertar uno con fecha anterior. Se escribe el
            // que de verdad manda.
            if ($latest === null || (int) $latest->id !== (int) $message->id) {
                $this->write($conversationId, $latest?->body);

                return;
            }

            $this->write($conversationId, $message->body);
        } catch (Throwable) {
            // Silencio deliberado: ver la lista con un texto de hace un minuto
            // es infinitamente mejor que perder el mensaje de un cliente.
        }
    }

    private function recompute(int $conversationId): void
    {
        if ($conversationId === 0) {
            return;
        }

        try {
            $body = MarketingMessage::query()
                ->where('conversation_id', $conversationId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('body');

            $this->write($conversationId, $body);
        } catch (Throwable) {
            // Igual que arriba.
        }
    }

    /**
     * Se escribe con una sentencia directa y no con el modelo a propósito: no
     * debe disparar los observadores de la conversación ni tocar
     * `updated_at`, que aquí significa «alguien cambió la conversación» y no
     * «llegó un mensaje».
     */
    private function write(int $conversationId, ?string $body): void
    {
        DB::table('marketing_conversations')
            ->where('id', $conversationId)
            ->update([
                'last_message_preview' => $body === null
                    ? null
                    : mb_substr($body, 0, self::LENGTH),
            ]);
    }
}
