<?php

namespace App\Jobs;

use App\Services\Marketing\MarketingInboundMessageRouter;
use App\Services\Marketing\MarketingMessageDispatcher;
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
 * El webhook responde 200 de inmediato; aquí ocurre el trabajo pesado: filtrar
 * eventos, crear/actualizar lead + conversación, registrar el mensaje entrante
 * (idempotente por meta_message_id) y ENRUTAR el texto al cerebro comercial en
 * modo seguro (Fase 4-A): se analiza, pero NO se ejecutan herramientas ni se
 * envía nada mientras los flags estén en false. NUNCA activa membresías ni pagos.
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
        MarketingInboundMessageRouter $router,
        MarketingMessageDispatcher $dispatcher,
    ): void {
        if (! (bool) config('marketing.inbound.meta_enabled', true)) {
            Log::info('meta.webhook.skipped', ['reason' => 'inbound_meta_disabled']);
            return; // procesamiento de entrantes deshabilitado (modo registro off).
        }

        $supportedTypes = (array) config('marketing.inbound.supported_message_types', ['text']);
        $expectedPhoneId = (string) config('meta.whatsapp_phone_number_id');

        $events = $webhook->parseEvents($this->payload);
        Log::info('meta.webhook.job_started', [
            'queue_connection' => (string) config('queue.default'),
            'events'           => is_countable($events) ? count($events) : null,
        ]);

        foreach ($webhook->parseEvents($this->payload) as $event) {
            // Seguridad multi-número: si el número configurado no coincide, ignorar.
            if ($expectedPhoneId !== '' && ! empty($event['phone_number_id'])
                && ! hash_equals($expectedPhoneId, (string) $event['phone_number_id'])) {
                Log::warning('meta.webhook.skipped', [
                    'reason'                   => 'phone_number_mismatch',
                    'received_phone_number_id' => (string) $event['phone_number_id'],
                    'expected_phone_number_id' => $expectedPhoneId,
                ]);
                continue;
            }

            // Estados de entrega (sent/delivered/read) → solo actualizar estado.
            if (str_starts_with((string) $event['kind'], 'status:')) {
                Log::info('meta.webhook.status_detected', [
                    'message_id' => $event['message_id'] ?? null,
                    'status'     => substr((string) $event['kind'], 7),
                ]);
                $conversations->recordStatus($event['message_id'], substr((string) $event['kind'], 7));
                continue;
            }

            // Solo mensajes con remitente identificable.
            if ($event['kind'] !== 'message' || empty($event['meta_user_id'])) {
                Log::info('meta.webhook.skipped', [
                    'reason' => 'not_a_message_or_no_sender',
                    'kind'   => $event['kind'] ?? null,
                ]);
                continue;
            }

            Log::info('meta.webhook.message_detected', [
                'type'       => $event['message_type'] ?? 'text',
                'wa_id'      => $event['wa_id'] ?? null,
                'message_id' => $event['message_id'] ?? null,
                'has_text'   => ! empty($event['text']),
            ]);

            $type = (string) ($event['message_type'] ?? 'text');
            $supported = in_array($type, $supportedTypes, true);

            $lead = $leads->resolveLead($event['channel'], $event['meta_user_id'], $event['name']);
            // Asegura el teléfono del lead (WhatsApp) para envíos futuros.
            if ($event['channel'] === 'whatsapp' && empty($lead->phone) && ! empty($event['wa_id'])) {
                $phone = $dispatcher->normalizePhone((string) $event['wa_id']);
                if ($phone !== null) {
                    $lead->forceFill(['phone' => $phone])->save();
                }
            }

            $conversation = $leads->ensureConversation($lead, $event['channel']);

            $message = $conversations->recordInbound(
                $conversation,
                $event['message_id'],
                $supported ? $event['text'] : null,
                $this->messageMetadata($event, $supported),
            );

            // Idempotencia: si el mensaje ya existía, no re-analizar.
            if ($message === null || ! $message->wasRecentlyCreated) {
                Log::info('meta.webhook.skipped', [
                    'reason'          => 'duplicate_message',
                    'conversation_id' => $conversation->id,
                ]);
                continue;
            }

            Log::info('meta.webhook.inbound_saved', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'lead_id'         => $lead->id,
            ]);

            if (! $supported) {
                // Media no soportada → registrar para humano, sin OpenAI.
                Log::info('meta.webhook.skipped', ['reason' => 'unsupported_message_type', 'type' => $type]);
                $router->recordUnsupported($lead, $conversation, $message, $type);
                continue;
            }

            // Texto soportado → cerebro comercial (dry_run / proposed según flags).
            $result = $router->analyze($lead, $conversation, $message);

            Log::info('meta.webhook.auto_analyze_dispatched', [
                'channel'         => $event['channel'],
                'lead_id'         => $lead->id,
                'conversation_id' => $conversation->id,
                'skipped'         => $result['skipped'] ?? false,
                'reason'          => $result['skipped'] ?? false ? ($result['reason'] ?? null) : null,
            ]);
        }
    }

    /** Metadatos saneados del mensaje (sin datos sensibles). */
    private function messageMetadata(array $event, bool $supported): array
    {
        $meta = array_filter([
            'wa_id'                => $event['wa_id'] ?? null,
            'phone_number_id'      => $event['phone_number_id'] ?? null,
            'display_phone_number' => $event['display_phone_number'] ?? null,
            'message_type'         => $event['message_type'] ?? null,
            'timestamp'            => $event['timestamp'] ?? null,
        ], fn ($v) => $v !== null);

        if (! $supported) {
            $meta['unsupported_message'] = true;
        }
        if ((bool) config('marketing.inbound.store_raw_payload', false)) {
            $meta['raw_event'] = $event['raw'] ?? null;
        }

        return $meta;
    }
}
