<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;

/**
 * Envío de una respuesta manual (humana) desde el Inbox CRM. Reutiliza el
 * dispatcher existente (dry_run cuando Meta está off; envío real cuando está
 * configurado) — NO duplica lógica de Meta.
 *
 * Regla crítica: enviar un mensaje manual NO apaga la IA por defecto. Solo
 * pausa la IA si `pause_ai=true`, delegando en {@see MarketingManualTakeoverService}.
 *
 * Con archivos adjuntos aparece una segunda regla, impuesta por WhatsApp y no
 * por nosotros: **cada archivo es un mensaje**. Tres fotos son tres mensajes,
 * no uno con tres. Se modela así para que el historial del CRM enseñe
 * exactamente lo que le llegó al cliente; agruparlos en la base haría que el
 * asesor viera una cosa y el cliente otra.
 */
class MarketingManualReplyService
{
    public function __construct(
        private readonly MarketingMessageDispatcher $dispatcher,
        private readonly MarketingManualTakeoverService $takeover,
        private readonly OutboundAttachmentService $attachments,
    ) {}

    /**
     * @param  array<int,int>  $attachmentIds  Borradores ya subidos, en el orden en que deben llegar.
     * @return array{ok:bool,dispatch:array,dispatches:array<int,array>,ai_paused:bool,attachments_sent:int}
     */
    public function send(
        MarketingConversation $conversation,
        string $body,
        bool $pauseAi,
        ?int $adminId,
        array $attachmentIds = [],
        ?string $replyToMetaMessageId = null,
    ): array {
        $lead = $conversation->lead;
        $files = $this->attachments->claim($attachmentIds, $adminId);

        $baseMetadata = array_filter([
            'kind' => 'manual_reply',
            'admin_id' => $adminId,
            'reply_to_meta_message_id' => $replyToMetaMessageId,
        ], fn ($v) => $v !== null);

        $dispatches = [];

        if ($files->isEmpty()) {
            $dispatches[] = $this->dispatch($lead, $conversation, $body, $baseMetadata, $adminId, null);
        } else {
            /*
             * El texto viaja como pie del PRIMER archivo si ese archivo lo
             * admite. Audio y sticker no lo admiten en Cloud API, así que ahí
             * el texto sale como mensaje propio ANTES: el cliente lee la
             * explicación y después le llega la nota de voz, que es el orden
             * que tiene sentido. Perderlo silenciosamente no es una opción.
             */
            $text = trim($body);
            $first = $files->first();
            $captionable = in_array($first->kind, ['image', 'video', 'document'], true);

            if ($text !== '' && ! $captionable) {
                $dispatches[] = $this->dispatch($lead, $conversation, $text, $baseMetadata, $adminId, null);
                $text = '';
            }

            foreach ($files as $index => $file) {
                $dispatches[] = $this->dispatch(
                    $lead,
                    $conversation,
                    $index === 0 ? $text : '',
                    array_merge($baseMetadata, ['attachment_id' => $file->id]),
                    $adminId,
                    $file,
                    // La cita solo tiene sentido en el primer mensaje: repetirla
                    // en cada foto llenaría el chat del cliente de citas iguales.
                    $index === 0,
                );
            }
        }

        // Solo si el asesor lo pidió explícitamente: pausa manual de la IA.
        $aiPaused = false;
        if ($pauseAi) {
            $this->takeover->takeover($conversation->fresh() ?? $conversation, $adminId, 'manual_reply_pause');
            $aiPaused = true;
        }

        // `ok` es de todo el envío: si una de tres fotos no salió, el asesor
        // tiene que enterarse, no ver un visto bueno porque las otras dos sí.
        $ok = $dispatches !== [] && collect($dispatches)->every(fn (array $d) => (bool) ($d['ok'] ?? false));

        return [
            'ok' => $ok,
            // Se conserva el contrato anterior: quien solo mandaba texto sigue
            // leyendo `dispatch` sin cambiar nada.
            'dispatch' => $dispatches[0] ?? [],
            'dispatches' => $dispatches,
            'ai_paused' => $aiPaused,
            'attachments_sent' => $files->count(),
        ];
    }

    /** @param array<string,mixed> $metadata */
    private function dispatch(
        $lead,
        MarketingConversation $conversation,
        string $body,
        array $metadata,
        ?int $adminId,
        ?MarketingMessageAttachment $attachment,
        bool $withQuote = true,
    ): array {
        if (! $withQuote) {
            unset($metadata['reply_to_meta_message_id']);
        }

        return $this->dispatcher->dispatchWhatsapp(
            $lead,
            $conversation->channel,
            $body,
            $metadata,
            MarketingMessage::SENDER_HUMAN,
            $adminId,
            $attachment,
        );
    }
}
