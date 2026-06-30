<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Meta\MetaAuthService;
use App\Services\Meta\MetaMessagingService;

/**
 * Despacho de mensajes salientes de marketing por WhatsApp, con guardrails y
 * SIN entrega real cuando Meta está deshabilitado/sin credenciales (dry_run).
 * Compartido por el endpoint send-message, payment-links/send y el cerebro IA.
 *
 * Nunca lanza por falta de config Meta. No activa membresías. Logs sin secretos.
 */
class MarketingMessageDispatcher
{
    public function __construct(
        private readonly MetaMessagingService $messaging,
        private readonly MetaAuthService $auth,
    ) {
    }

    /**
     * @param  string   $senderType    Autor del outbound (ai por defecto; human para envío manual del Inbox).
     * @param  int|null $senderUserId  Admin/asesor que envía (solo para sender_type=human).
     * @return array{ok:bool,sent:bool,dry_run:bool,safe_to_send:bool,message_id:?int,provider_message_id:?string,reason:?string,conversation_id:?int}
     */
    public function dispatchWhatsapp(
        MarketingLead $lead,
        string $channel,
        string $body,
        array $metadata = [],
        string $senderType = MarketingMessage::SENDER_AI,
        ?int $senderUserId = null,
    ): array {
        $base = [
            'ok' => true, 'sent' => false, 'dry_run' => false, 'safe_to_send' => false,
            'message_id' => null, 'provider_message_id' => null, 'reason' => null, 'conversation_id' => null,
        ];

        // Guardrail: do_not_contact.
        if (! $lead->isContactable()) {
            return array_merge($base, ['reason' => 'do_not_contact']);
        }

        // Solo WhatsApp implementado hoy (IG/FB en fase viva).
        if ($channel !== 'whatsapp') {
            return array_merge($base, ['reason' => 'channel_not_supported']);
        }

        // Guardrail: WhatsApp exige un teléfono válido del lead.
        $to = $this->normalizePhone($lead->phone);
        if ($to === null) {
            return array_merge($base, ['reason' => 'lead_without_phone']);
        }

        // El recipiente normalizado usado para Meta queda en metadata (no se
        // sobrescribe el teléfono guardado del lead).
        $metadata = array_merge($metadata, ['recipient' => $to]);

        $conversation = MarketingConversation::firstOrCreate(
            ['lead_id' => $lead->id, 'channel' => $channel],
            ['status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'last_message_at' => now()],
        );

        // META deshabilitado o sin credenciales → dry_run (prepara, no entrega).
        if (! $this->auth->isConfigured()) {
            $message = $this->recordOutbound($conversation, $body, 'dry_run', null, $metadata, $senderType, $senderUserId);
            return array_merge($base, [
                'dry_run'         => true,
                'safe_to_send'    => true,
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'reason'          => 'meta_disabled_or_unconfigured',
            ]);
        }

        // Envío real (best-effort; sendWhatsappText nunca lanza ni loguea secretos).
        $providerId = $this->messaging->sendWhatsappText($to, $body);
        $message = $this->recordOutbound(
            $conversation,
            $body,
            $providerId !== null ? 'sent' : 'failed',
            $providerId,
            $metadata,
            $senderType,
            $senderUserId,
        );

        return array_merge($base, [
            'sent'                => $providerId !== null,
            'safe_to_send'        => true,
            'message_id'          => $message->id,
            'provider_message_id' => $providerId,
            'conversation_id'     => $conversation->id,
            'reason'              => $providerId !== null ? null : 'provider_send_failed',
        ]);
    }

    /** Registra el mensaje saliente y avanza los timestamps de la conversación. */
    private function recordOutbound(
        MarketingConversation $conversation,
        string $body,
        string $status,
        ?string $providerId,
        array $metadata,
        string $senderType = MarketingMessage::SENDER_AI,
        ?int $senderUserId = null,
    ): MarketingMessage {
        $message = MarketingMessage::create([
            'conversation_id' => $conversation->id,
            'direction'       => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type'     => $senderType,
            'sender_user_id'  => $senderUserId,
            'body'            => $body,
            'meta_message_id' => $providerId,
            'status'          => $status,
            'metadata'        => $metadata ?: null,
        ]);

        // Bookkeeping del Inbox (aditivo, no cambia el comportamiento de envío):
        // avanza last_message_at/last_outbound_at y marca la primera respuesta.
        $changes = ['last_message_at' => now(), 'last_outbound_at' => now()];
        if ($conversation->getAttribute('first_response_at') === null) {
            $changes['first_response_at'] = now();
        }
        $conversation->forceFill($changes)->save();

        return $message;
    }

    /**
     * Normaliza un teléfono al formato que espera WhatsApp Cloud API (dígitos,
     * con indicativo de país, SIN '+'). Colombia: 10 dígitos empezando por 3 →
     * antepone 57. Valida E.164 (11–15 dígitos). Inválido → null (bloquea envío).
     * No modifica el teléfono guardado del lead.
     */
    public function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            $digits = '57'.$digits;
        }
        if (strlen($digits) < 11 || strlen($digits) > 15) {
            return null;
        }
        return $digits;
    }
}
