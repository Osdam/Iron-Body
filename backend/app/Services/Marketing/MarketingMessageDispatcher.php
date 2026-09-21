<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Services\Meta\MetaAuthService;

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
        private readonly MetaAuthService $auth,
        private readonly WhatsappOutboxService $outbox,
    ) {}

    /**
     * @param  string  $senderType  Autor del outbound (ai por defecto; human para envío manual del Inbox).
     * @param  int|null  $senderUserId  Admin/asesor que envía (solo para sender_type=human).
     * @param  MarketingMessageAttachment|null  $attachment  Archivo que acompaña al mensaje (borrador ya subido).
     * @return array{ok:bool,sent:bool,dry_run:bool,safe_to_send:bool,message_id:?int,provider_message_id:?string,reason:?string,conversation_id:?int,will_retry:bool}
     */
    public function dispatchWhatsapp(
        MarketingLead $lead,
        string $channel,
        string $body,
        array $metadata = [],
        string $senderType = MarketingMessage::SENDER_AI,
        ?int $senderUserId = null,
        ?MarketingMessageAttachment $attachment = null,
        ?MarketingConversation $conversation = null,
    ): array {
        $base = [
            'ok' => true, 'sent' => false, 'dry_run' => false, 'safe_to_send' => false,
            'message_id' => null, 'provider_message_id' => null, 'reason' => null,
            'conversation_id' => null, 'will_retry' => false,
        ];

        // Guardrail: do_not_contact o consentimiento denegado.
        if (! $lead->canReplyReactively()) {
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

        /*
         * EL OPT-OUT ES DEL TELÉFONO, NO DE LA FILA.
         *
         * La guardia de arriba pregunta por la fila de lead que resolvió el
         * webhook, y esa fila se elige por (canal, meta_user_id): el teléfono
         * no entra nunca en la resolución. Así que un mismo número puede tener
         * varias filas —la que resuelve Meta hoy y las históricas— y el
         * `do_not_contact` quedarse en una mientras el mensaje sale por otra.
         *
         * En esta base ya pasa: el mismo número está guardado con diez dígitos
         * en una fila y con el 57 delante en otra, de modo que ni siquiera una
         * igualdad de texto las une. Por eso se compara sobre el número
         * NORMALIZADO, que es exactamente el que se le pasa a Meta y la única
         * identidad que la persona comparte entre filas.
         *
         * Basta UNA hermana cerrada para no escribir. Falla hacia el lado que
         * protege a quien pidió que no le escriban, y eso tiene un coste que
         * conviene no esconder: dos personas que compartan número de verdad
         * —una familia, un número reciclado— quedan calladas las dos. Entre
         * escribirle a quien dijo que no y no escribirle a quien sí quería, el
         * daño reparable es el segundo.
         */
        if ($this->phoneOptedOut($to, (int) $lead->id)) {
            return array_merge($base, ['reason' => 'do_not_contact_sibling']);
        }

        // El recipiente normalizado usado para Meta queda en metadata (no se
        // sobrescribe el teléfono guardado del lead).
        $metadata = array_merge($metadata, ['recipient' => $to]);

        /*
         * LA CONVERSACIÓN LA PONE QUIEN LA SABE.
         *
         * Aquí había un `firstOrCreate(['lead_id' => ..., 'channel' => ...])`,
         * que devuelve la PRIMERA fila por orden de inserción. Para un lead con
         * más de una conversación eso es la más ANTIGUA —normalmente cerrada—,
         * así que la respuesta llegaba bien a la persona (Meta solo necesita el
         * teléfono) y se archivaba en el hilo equivocado.
         *
         * No es cosmético: el modelo lee `recent_messages` de SU conversación,
         * de modo que dejaba de ver sus propias respuestas. Contestaba a ciegas
         * sobre lo que ya había dicho. Se vio en el canario físico: siete
         * entrantes en la 19 y cuatro salientes archivados en la 2.
         *
         * Quien ya resolvió la conversación —el commit de ULTRON, el envío
         * manual, el webhook— la pasa. Para quien no puede saberla, el respaldo
         * usa la misma regla del dominio que el entrante
         * ({@see \App\Services\Meta\MetaLeadService::ensureConversation()}):
         * abierta primero, luego la del último mensaje, y solo entonces una
         * nueva.
         */
        $conversation = $conversation
            ?? MarketingConversation::query()
                ->where('lead_id', $lead->id)
                ->where('channel', $channel)
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
                ->orderByRaw('CASE WHEN last_message_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first()
            ?? MarketingConversation::create([
                'lead_id' => $lead->id,
                'channel' => $channel,
                'status' => 'open',
                'ai_enabled' => true,
                'human_takeover' => false,
                'last_message_at' => now(),
            ]);

        // META deshabilitado o sin credenciales → dry_run (prepara, no entrega).
        if (! $this->auth->isConfigured()) {
            $message = $this->recordOutbound($conversation, $body, 'dry_run', null, $metadata, $senderType, $senderUserId);
            $this->linkAttachment($message, $attachment);

            return array_merge($base, [
                'dry_run' => true,
                'safe_to_send' => true,
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'reason' => 'meta_disabled_or_unconfigured',
            ]);
        }

        // El mensaje se registra ANTES de salir a la red. Si el proceso muere
        // en mitad del POST a Meta, el mensaje existe, el inbox lo muestra y el
        // outbox puede retomarlo; antes desaparecía sin dejar rastro.
        $message = $this->recordOutbound(
            $conversation,
            $body,
            WhatsappOutboxService::STATUS_QUEUED,
            null,
            $metadata,
            $senderType,
            $senderUserId,
        );

        // El archivo se ata al mensaje ANTES de salir a la red: el outbox
        // decide por él si esto es un envío de texto o de media, y un reintento
        // que lo encontrara suelto mandaría el pie de foto sin la foto.
        $this->linkAttachment($message, $attachment);

        $delivery = $this->outbox->deliver($message, $to);

        return array_merge($base, [
            'sent' => $delivery['sent'],
            'safe_to_send' => true,
            'message_id' => $message->id,
            'provider_message_id' => $delivery['provider_message_id'],
            'conversation_id' => $conversation->id,
            'reason' => $delivery['reason'],
            // Un fallo con reintento programado no es lo mismo que uno
            // definitivo: quien llama puede decidir si avisar o esperar.
            'will_retry' => $delivery['retryable'],
        ]);
    }

    /**
     * Convierte un borrador en el archivo de este mensaje.
     *
     * Es aquí donde el adjunto deja de ser de quien lo subió y pasa a ser del
     * mensaje. La condición `whereNull('message_id')` es la que impide que dos
     * envíos simultáneos reclamen el mismo borrador y el cliente reciba la foto
     * dos veces: el segundo no actualiza ninguna fila.
     */
    private function linkAttachment(MarketingMessage $message, ?MarketingMessageAttachment $attachment): void
    {
        if ($attachment === null) {
            return;
        }

        MarketingMessageAttachment::query()
            ->where('id', $attachment->id)
            ->whereNull('message_id')
            ->update(['message_id' => $message->id, 'updated_at' => now()]);
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
            'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => $senderType,
            'sender_user_id' => $senderUserId,
            'body' => $body,
            'meta_message_id' => $providerId,
            'status' => $status,
            'metadata' => $metadata ?: null,
            // Cose la respuesta con el mensaje entrante que la provocó: en el
            // log, un solo id lleva del webhook hasta lo que salió de vuelta.
            'correlation_id' => WhatsappOutboxService::currentCorrelationId(),
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
    /**
     * ¿Alguna OTRA fila de lead con este mismo número pidió que no le escriban?
     *
     * Se filtra por la BANDERA primero, no por el teléfono. Parece un detalle
     * de rendimiento y es lo que hace que la guardia funcione: aquí había un
     * `LIKE '%últimos-dígitos'` sobre la columna CRUDA, y una hermana guardada
     * como «+57 315 053 6026» no casaba nunca —los separadores la tiraban antes
     * de llegar a la comparación normalizada—. La guardia fallaba ABIERTA, que
     * es la peor forma de fallar para un opt-out: se medió, y el mensaje salía.
     *
     * Y esa columna se llena a mano, desde el panel o desde importaciones
     * —`MetaLeadService` ni siquiera la rellena—, así que los `+57`, los
     * guiones y los espacios son la norma y no la excepción.
     *
     * Filtrando por `do_not_contact` la lista es diminuta —quien pide que no le
     * escriban es una minoría— y se puede normalizar en PHP, que es la única
     * forma de comparar que vale igual en PostgreSQL y en SQLite. Se acabaron
     * las coincidencias de texto sobre un dato que nadie normalizó.
     */
    private function phoneOptedOut(string $normalizado, int $exceptoLeadId): bool
    {
        return MarketingLead::query()
            ->where('do_not_contact', true)
            ->where('id', '!=', $exceptoLeadId)
            ->whereNotNull('phone')
            ->get(['id', 'phone'])
            ->contains(fn (MarketingLead $hermana) => $this->normalizePhone($hermana->phone) === $normalizado);
    }

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
