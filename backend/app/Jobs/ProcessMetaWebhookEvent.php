<?php

namespace App\Jobs;

use App\Services\Meta\MetaConversationService;
use App\Services\Meta\MetaLeadService;
use App\Services\Meta\MetaWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Procesa (en cola) un payload de webhook de Meta ya verificado por firma.
 *
 * El webhook responde 200 de inmediato; aquí ocurre el trabajo pesado: parsear
 * eventos, crear/actualizar lead + conversación y registrar el mensaje entrante
 * (idempotente por meta_message_id). NO llama a OpenAI ni a Graph: la respuesta
 * comercial IA la orquesta n8n a partir del lead creado (fase posterior).
 */
class ProcessMetaWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 20;

    /** @param array<string,mixed> $payload Payload crudo (ya sin secretos). */
    public function __construct(public array $payload)
    {
    }

    public function handle(
        MetaWebhookService $webhook,
        MetaLeadService $leads,
        MetaConversationService $conversations,
    ): void {
        $events = $webhook->parseEvents($this->payload);

        foreach ($events as $event) {
            // Estados de entrega de WhatsApp → solo actualizar estado.
            if (str_starts_with((string) $event['kind'], 'status:')) {
                $conversations->recordStatus($event['message_id'], substr($event['kind'], 7));
                continue;
            }

            // Solo procesamos mensajes con remitente identificable.
            if ($event['kind'] !== 'message' || empty($event['meta_user_id'])) {
                continue;
            }

            $lead = $leads->resolveLead($event['channel'], $event['meta_user_id'], $event['name']);
            $conversation = $leads->ensureConversation($lead, $event['channel']);
            $conversations->recordInbound(
                $conversation,
                $event['message_id'],
                $event['text'],
            );

            Log::info('meta.webhook.lead_message', [
                'channel'         => $event['channel'],
                'lead_id'         => $lead->id,
                'conversation_id' => $conversation->id,
                // NUNCA logueamos el cuerpo del mensaje ni datos personales.
            ]);
        }
    }
}
