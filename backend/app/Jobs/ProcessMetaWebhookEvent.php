<?php

namespace App\Jobs;

use App\Models\MetaWebhookEvent;
use App\Services\Marketing\MarketingInboundMessageRouter;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Meta\MetaConversationService;
use App\Services\Meta\MetaLeadService;
use App\Services\Meta\MetaWebhookService;
use App\Services\Observability\ChannelLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Procesa (en cola) un evento de Meta YA PERSISTIDO y verificado por firma.
 *
 * El job recibe el id de la fila en `meta_webhook_events`, no el payload: así el
 * cuerpo original vive en un solo sitio, un reintento lee exactamente lo mismo
 * que la primera vez, y el estado del procesamiento (attempts, último error)
 * queda en la misma fila que se puede consultar desde el panel.
 *
 * Aquí ocurre el trabajo pesado: filtrar eventos, crear/actualizar lead +
 * conversación, registrar el mensaje entrante (idempotente por meta_message_id),
 * encolar la descarga de adjuntos y enrutar el texto al cerebro comercial según
 * los flags. NUNCA activa membresías ni pagos.
 */
class ProcessMetaWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Backoff creciente: si Meta o la BD tosen, no insistimos cada 20s. */
    public array $backoff = [20, 60, 180];

    public function __construct(public int $eventId)
    {
        // El carril P0. Se fija en el constructor y no en cada `dispatch`
        // porque este job se encola desde cuatro sitios —webhook, replay,
        // remediación y simulación— y una barrera que hay que acordarse de
        // poner en cuatro sitios es una barrera que algún día falta en uno.
        $lane = (array) config('queue.lanes.whatsapp');
        $this->onQueue($lane['queue'] ?? 'whatsapp-high');
    }

    /**
     * Un evento se procesa una sola vez a la vez. Sin esto, un reintento que se
     * solapa con la ejecución original puede duplicar acciones del agente.
     */
    public function uniqueId(): string
    {
        return 'meta-webhook-event:'.$this->eventId;
    }

    public function handle(
        MetaWebhookService $webhook,
        MetaLeadService $leads,
        MetaConversationService $conversations,
        MarketingInboundMessageRouter $router,
        MarketingMessageDispatcher $dispatcher,
    ): void {
        $event = MetaWebhookEvent::find($this->eventId);

        if ($event === null) {
            ChannelLog::warning('meta.event.missing', ['event_id' => $this->eventId]);

            return;
        }

        // El correlation_id del request original manda, incluso si este job se
        // ejecuta días después por un replay.
        Context::add('correlation_id', $event->correlation_id);

        if ($event->status === MetaWebhookEvent::STATUS_PROCESSED) {
            ChannelLog::info('meta.event.skipped', [
                'reason' => 'already_processed',
                'event_id' => $event->id,
            ]);

            return;
        }

        $event->markProcessing();

        try {
            $this->process($event, $webhook, $leads, $conversations, $router, $dispatcher);
            $event->markProcessed();
        } catch (Throwable $e) {
            // ¿Queda algún reintento por delante? Si no, el evento queda 'dead'
            // y visible: nadie tiene que descubrirlo leyendo failed_jobs.
            $exhausted = $this->attempts() >= $this->tries;
            $event->markFailed(class_basename($e), $e->getMessage(), $exhausted);

            ChannelLog::error('meta.event.failed', [
                'event_id' => $event->id,
                'attempt' => $this->attempts(),
                'exhausted' => $exhausted,
                'error_class' => class_basename($e),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function process(
        MetaWebhookEvent $event,
        MetaWebhookService $webhook,
        MetaLeadService $leads,
        MetaConversationService $conversations,
        MarketingInboundMessageRouter $router,
        MarketingMessageDispatcher $dispatcher,
    ): void {
        if (! (bool) config('marketing.inbound.meta_enabled', true)) {
            ChannelLog::info('meta.event.skipped', [
                'reason' => 'inbound_meta_disabled',
                'event_id' => $event->id,
            ]);

            return; // procesamiento de entrantes deshabilitado (modo registro off).
        }

        $expectedPhoneId = (string) config('meta.whatsapp_phone_number_id');
        $events = $webhook->parseEvents((array) $event->payload);

        ChannelLog::info('meta.event.started', [
            'event_id' => $event->id,
            'attempt' => $this->attempts(),
            'parsed_events' => count($events),
        ]);

        foreach ($events as $parsed) {
            // Seguridad multi-número: si el número configurado no coincide, ignorar.
            if ($expectedPhoneId !== '' && ! empty($parsed['phone_number_id'])
                && ! hash_equals($expectedPhoneId, (string) $parsed['phone_number_id'])) {
                ChannelLog::warning('meta.event.skipped', [
                    'reason' => 'phone_number_mismatch',
                    'event_id' => $event->id,
                    'received_phone_number_id' => (string) $parsed['phone_number_id'],
                ]);

                continue;
            }

            if (str_starts_with((string) $parsed['kind'], 'status:')) {
                $this->handleStatus($conversations, $parsed);

                continue;
            }

            // Solo mensajes con remitente identificable.
            if ($parsed['kind'] !== 'message' || empty($parsed['meta_user_id'])) {
                ChannelLog::info('meta.event.skipped', [
                    'reason' => 'not_a_message_or_no_sender',
                    'kind' => $parsed['kind'] ?? null,
                ]);

                continue;
            }

            $this->handleMessage($event, $parsed, $leads, $conversations, $router, $dispatcher);
        }
    }

    /**
     * Status callback (sent/delivered/read/failed). Se reconcilian fuera de
     * orden: un 'sent' que llega después de un 'read' no puede retroceder el
     * estado del mensaje.
     */
    private function handleStatus(MetaConversationService $conversations, array $parsed): void
    {
        $status = substr((string) $parsed['kind'], 7);

        $applied = $conversations->recordStatus(
            $parsed['message_id'],
            $status,
            $parsed['status_error'] ?? null,
            $parsed['timestamp'] ?? null,
        );

        ChannelLog::info('meta.status.recorded', [
            'meta_message_id' => $parsed['message_id'] ?? null,
            'status' => $status,
            'applied' => $applied,
            'error_code' => $parsed['status_error']['code'] ?? null,
        ]);
    }

    private function handleMessage(
        MetaWebhookEvent $event,
        array $parsed,
        MetaLeadService $leads,
        MetaConversationService $conversations,
        MarketingInboundMessageRouter $router,
        MarketingMessageDispatcher $dispatcher,
    ): void {
        $type = (string) ($parsed['message_type'] ?? 'text');

        $lead = $leads->resolveLead($parsed['channel'], $parsed['meta_user_id'], $parsed['name']);

        // Asegura el teléfono del lead (WhatsApp) para envíos futuros.
        if ($parsed['channel'] === 'whatsapp' && empty($lead->phone) && ! empty($parsed['wa_id'])) {
            $phone = $dispatcher->normalizePhone((string) $parsed['wa_id']);
            if ($phone !== null) {
                $lead->forceFill(['phone' => $phone])->save();
            }
        }

        $conversation = $leads->ensureConversation($lead, $parsed['channel']);
        Context::add('conversation_id', $conversation->id);

        /*
         * Atribucion: de donde vino esta persona.
         *
         * Se registra AQUI, con el lead y la conversacion ya resueltos, y antes
         * de enrutar el mensaje. Hasta ahora el bloque `referral` -el que dice
         * que anuncio toco alguien antes de escribir- solo quedaba dentro del
         * metadata del mensaje: util para mirarlo de a uno e inservible para
         * agrupar por campana o saber que pauta trae ventas.
         *
         * Es best-effort a proposito: perder una atribucion es infinitamente
         * preferible a perder el mensaje de un prospecto.
         */
        app(\App\Services\Marketing\LeadAttributionService::class)->record(
            leadId: $lead->id,
            referral: $parsed['referral'] ?? null,
            conversationId: $conversation->id,
            contactId: $parsed['wa_id'] ?? $parsed['meta_user_id'] ?? null,
        );

        // Mensajes de una misma conversación se procesan en orden y sin
        // solaparse: dos entregas casi simultáneas no pueden intercalar sus
        // efectos sobre el mismo hilo. Si en 10s no se consigue el turno, se
        // deja escapar LockTimeoutException y el job entero se reintenta, que
        // es preferible a procesar fuera de orden.
        $result = Cache::lock('marketing:conversation:'.$conversation->id, 30)->block(
            10,
            fn () => $this->recordAndRoute($event, $parsed, $type, $lead, $conversation, $conversations, $router),
        );

        /*
         * El agente se encola con el cerrojo YA SOLTADO.
         *
         * El job del agente pide este mismo cerrojo para no analizar dos
         * mensajes de la misma persona a la vez. Encolarlo desde dentro se
         * bloquea contra sí mismo en cuanto la cola es síncrona —la simulación,
         * `dispatchSync`, la suite—, y con una cola real solo funciona por
         * casualidad de que el job corra más tarde. Fuera del cerrojo es
         * correcto en los dos casos.
         */
        MarketingInboundMessageRouter::flushDeferred((array) $result);
    }

    private function recordAndRoute(
        MetaWebhookEvent $event,
        array $parsed,
        string $type,
        $lead,
        $conversation,
        MetaConversationService $conversations,
        MarketingInboundMessageRouter $router,
    ): array {
        $message = $conversations->recordInbound(
            $conversation,
            $parsed['message_id'],
            $parsed['text'],
            $this->messageMetadata($event, $parsed),
        );

        // Idempotencia: si el mensaje ya existía, no re-analizar.
        if ($message === null || ! $message->wasRecentlyCreated) {
            ChannelLog::info('meta.message.skipped', [
                'reason' => 'duplicate_message',
                'conversation_id' => $conversation->id,
                'meta_message_id' => $parsed['message_id'] ?? null,
            ]);

            return [];
        }

        ChannelLog::info('meta.message.saved', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'lead_id' => $lead->id,
            'message_type' => $type,
            'has_media' => ! empty($parsed['media']),
        ]);

        // Adjuntos: la descarga va a su propia cola. El mensaje ya existe en el
        // inbox aunque el archivo tarde o falle; el humano ve que llegó algo.
        $router->attachMedia($message, $parsed);

        $result = $router->route($lead, $conversation, $message, $parsed);

        ChannelLog::info('meta.message.routed', [
            'channel' => $parsed['channel'],
            'conversation_id' => $conversation->id,
            'message_type' => $type,
            'skipped' => $result['skipped'] ?? false,
            'reason' => $result['reason'] ?? null,
            'agent_queued' => isset($result['analyze_async']),
        ]);

        return $result;
    }

    /** Metadatos saneados del mensaje (sin datos sensibles). */
    private function messageMetadata(MetaWebhookEvent $event, array $parsed): array
    {
        $meta = array_filter([
            'wa_id' => $parsed['wa_id'] ?? null,
            'phone_number_id' => $parsed['phone_number_id'] ?? null,
            'display_phone_number' => $parsed['display_phone_number'] ?? null,
            'message_type' => $parsed['message_type'] ?? null,
            'timestamp' => $parsed['timestamp'] ?? null,
            'correlation_id' => $event->correlation_id,
            'webhook_event_id' => $event->id,
            // Respuesta a un mensaje citado: el inbox lo dibuja como cita.
            'context' => $parsed['context'] ?? null,
            // Payload de botón/lista pulsado, ubicación, contactos, reacción…
            'interactive' => $parsed['interactive'] ?? null,
            'location' => $parsed['location'] ?? null,
            'contacts' => $parsed['contacts'] ?? null,
            'reaction' => $parsed['reaction'] ?? null,
            'referral' => $parsed['referral'] ?? null,
            'system' => $parsed['system'] ?? null,
            'errors' => $parsed['errors'] ?? null,
        ], fn ($v) => $v !== null && $v !== []);

        if (! empty($parsed['unsupported'])) {
            $meta['unsupported_message'] = true;
        }

        if ((bool) config('marketing.inbound.store_raw_payload', false)) {
            $meta['raw_event'] = $parsed['raw'] ?? null;
        }

        return $meta;
    }
}
