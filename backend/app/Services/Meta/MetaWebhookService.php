<?php

namespace App\Services\Meta;

/**
 * Verificación y parseo de webhooks de Meta. NO depende de tokens de Graph:
 * el reto (challenge) usa META_VERIFY_TOKEN y la firma usa META_WEBHOOK_SECRET,
 * por eso es 100% testeable en local aunque META_ENABLED esté en false.
 *
 * El parseo cubre toda la superficie que WhatsApp Cloud API entrega hoy: texto,
 * medios (imagen, audio, nota de voz, video, documento, sticker), respuestas
 * interactivas de botón y lista, botones de plantilla, reacciones, ubicación,
 * contactos, pedidos, mensajes de sistema, referidos de anuncios click-to-chat,
 * citas a un mensaje anterior y tipos que Meta marca como no soportados.
 *
 * Un tipo desconocido NO se descarta: se normaliza con `unsupported=true` para
 * que el inbox lo muestre y un humano decida. Perder el mensaje de un prospecto
 * porque Meta añadió un tipo nuevo sería el peor fallo posible de esta capa.
 */
class MetaWebhookService
{
    /** Tipos con archivo adjunto y la clave donde WhatsApp lo anida. */
    private const MEDIA_TYPES = ['image', 'audio', 'video', 'document', 'sticker'];

    /**
     * Verificación del webhook (GET). Devuelve el challenge si el verify_token
     * coincide; null si no. (Meta espera el challenge en texto plano.)
     */
    public function verifyChallenge(?string $mode, ?string $token, ?string $challenge): ?string
    {
        $expected = (string) config('meta.verify_token');
        if ($expected === '' || $mode !== 'subscribe' || $token === null) {
            return null;
        }

        return hash_equals($expected, $token) ? $challenge : null;
    }

    /**
     * Valida la firma X-Hub-Signature-256 (HMAC-SHA256 del cuerpo crudo con el
     * app secret / webhook secret). Si no hay secreto configurado, no se puede
     * validar y se rechaza por seguridad.
     */
    public function validateSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) config('meta.webhook_secret');
        if ($secret === '' || $signatureHeader === null) {
            return false;
        }
        // Header: "sha256=<hex>"
        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Normaliza el payload de Meta a una lista de eventos simples por canal.
     * Soporta Instagram/Facebook (object=page|instagram, entry[].messaging[]) y
     * WhatsApp (object=whatsapp_business_account, entry[].changes[].value).
     *
     * Recorre TODAS las entries y TODOS los changes: Meta agrupa varios eventos
     * en un mismo POST y quedarse con el primero pierde mensajes reales.
     *
     * @return array<int, array<string,mixed>>
     */
    public function parseEvents(array $payload): array
    {
        $object = (string) ($payload['object'] ?? '');
        $events = [];

        foreach (($payload['entry'] ?? []) as $entry) {
            // Instagram / Facebook Messenger.
            foreach (($entry['messaging'] ?? []) as $m) {
                $channel = $object === 'instagram' ? 'instagram' : 'facebook';
                $msg = $m['message'] ?? null;
                $events[] = $this->event([
                    'channel' => $channel,
                    'meta_user_id' => $m['sender']['id'] ?? null,
                    'message_id' => $msg['mid'] ?? null,
                    'text' => $msg['text'] ?? null,
                    'name' => null,
                    'kind' => $msg ? 'message' : 'event',
                    'message_type' => $msg ? 'text' : null,
                    'raw' => $m,
                ]);
            }

            // WhatsApp Cloud API.
            foreach (($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $waMeta = (array) ($value['metadata'] ?? []);
                $contactName = $value['contacts'][0]['profile']['name'] ?? null;

                foreach (($value['messages'] ?? []) as $wa) {
                    $events[] = $this->parseWhatsappMessage((array) $wa, $waMeta, $contactName);
                }

                foreach (($value['statuses'] ?? []) as $st) {
                    $events[] = $this->parseWhatsappStatus((array) $st, $waMeta);
                }

                // Errores a nivel de cuenta (p. ej. número deshabilitado). No
                // pertenecen a ninguna conversación, pero IRON GUARD debe verlos.
                foreach (($value['errors'] ?? []) as $err) {
                    $events[] = $this->event([
                        'channel' => 'whatsapp',
                        'kind' => 'account_error',
                        'phone_number_id' => $waMeta['phone_number_id'] ?? null,
                        'errors' => [$this->normalizeError((array) $err)],
                        'raw' => $err,
                    ]);
                }
            }
        }

        return $events;
    }

    /**
     * Un mensaje entrante de WhatsApp, sea del tipo que sea.
     *
     * `text` guarda lo que el prospecto realmente "dijo": el cuerpo del texto,
     * el título del botón o de la opción de lista que pulsó. Es lo que puede
     * entrar al cerebro comercial. El pie de foto (caption) va aparte porque
     * acompaña a un archivo y no siempre es una intención completa.
     */
    private function parseWhatsappMessage(array $wa, array $waMeta, ?string $contactName): array
    {
        $type = (string) ($wa['type'] ?? 'text');

        $base = [
            'channel' => 'whatsapp',
            'meta_user_id' => $wa['from'] ?? null,
            'wa_id' => $wa['from'] ?? null,
            'message_id' => $wa['id'] ?? null,
            'name' => $contactName,
            'kind' => 'message',
            'message_type' => $type,
            'timestamp' => $wa['timestamp'] ?? null,
            'phone_number_id' => $waMeta['phone_number_id'] ?? null,
            'display_phone_number' => $waMeta['display_phone_number'] ?? null,
            'raw' => $wa,
        ];

        // Cita a un mensaje anterior / reenvío. Se conserva siempre que venga.
        if (! empty($wa['context'])) {
            $ctx = (array) $wa['context'];
            $base['context'] = array_filter([
                'quoted_meta_message_id' => $ctx['id'] ?? null,
                'from' => $ctx['from'] ?? null,
                'forwarded' => $ctx['forwarded'] ?? null,
                'frequently_forwarded' => $ctx['frequently_forwarded'] ?? null,
            ], fn ($v) => $v !== null);
        }

        // Anuncio click-to-WhatsApp: de dónde vino este prospecto. Vale oro
        // para atribución y el agente puede saludar sabiendo qué vio.
        if (! empty($wa['referral'])) {
            $ref = (array) $wa['referral'];
            $base['referral'] = array_filter([
                'source_type' => $ref['source_type'] ?? null,
                'source_id' => $ref['source_id'] ?? null,
                'source_url' => $ref['source_url'] ?? null,
                'headline' => $ref['headline'] ?? null,
                'body' => $ref['body'] ?? null,
                'media_type' => $ref['media_type'] ?? null,
                'ctwa_clid' => $ref['ctwa_clid'] ?? null,
            ], fn ($v) => $v !== null);
        }

        return $this->event(array_merge($base, $this->parseMessageBody($wa, $type)));
    }

    /**
     * Extrae el contenido según el tipo. Devuelve solo las claves que aplican.
     *
     * @return array<string,mixed>
     */
    private function parseMessageBody(array $wa, string $type): array
    {
        if ($type === 'text') {
            return ['text' => $wa['text']['body'] ?? null];
        }

        if (in_array($type, self::MEDIA_TYPES, true)) {
            return $this->parseMedia($wa, $type);
        }

        return match ($type) {
            'interactive' => $this->parseInteractive((array) ($wa['interactive'] ?? [])),

            // Botón de respuesta rápida de una plantilla: el texto del botón
            // ES la respuesta del prospecto.
            'button' => [
                'text' => $wa['button']['text'] ?? null,
                'interactive' => array_filter([
                    'kind' => 'template_button',
                    'payload' => $wa['button']['payload'] ?? null,
                    'title' => $wa['button']['text'] ?? null,
                ], fn ($v) => $v !== null),
            ],

            'reaction' => [
                'reaction' => array_filter([
                    'emoji' => $wa['reaction']['emoji'] ?? null,
                    'target_meta_message_id' => $wa['reaction']['message_id'] ?? null,
                ], fn ($v) => $v !== null),
            ],

            'location' => [
                'location' => array_filter([
                    'latitude' => $wa['location']['latitude'] ?? null,
                    'longitude' => $wa['location']['longitude'] ?? null,
                    'name' => $wa['location']['name'] ?? null,
                    'address' => $wa['location']['address'] ?? null,
                ], fn ($v) => $v !== null),
            ],

            // Tarjetas de contacto: se guarda lo mínimo y NUNCA se usa para
            // contactar a nadie por iniciativa propia.
            'contacts' => [
                'contacts' => $this->parseContacts((array) ($wa['contacts'] ?? [])),
            ],

            'order' => [
                'order' => array_filter([
                    'catalog_id' => $wa['order']['catalog_id'] ?? null,
                    'text' => $wa['order']['text'] ?? null,
                    'items' => count((array) ($wa['order']['product_items'] ?? [])),
                ], fn ($v) => $v !== null),
            ],

            // Cambios de número, cliente que se identifica, etc.
            'system' => [
                'system' => array_filter([
                    'body' => $wa['system']['body'] ?? null,
                    'type' => $wa['system']['type'] ?? null,
                    'wa_id' => $wa['system']['wa_id'] ?? null,
                ], fn ($v) => $v !== null),
                'unsupported' => true,
            ],

            // Meta declara explícitamente que no puede entregarnos el contenido.
            'unsupported' => [
                'unsupported' => true,
                'errors' => array_map(
                    fn ($e) => $this->normalizeError((array) $e),
                    (array) ($wa['errors'] ?? []),
                ),
            ],

            // Tipo que aún no existe cuando se escribió esto. Se registra tal
            // cual y se escala a un humano en lugar de descartarse.
            default => ['unsupported' => true],
        };
    }

    /**
     * Adjunto: se conserva el media_id (para descargarlo luego), el MIME que
     * DECLARA Meta y el sha256 que Meta calculó. El MIME declarado se trata
     * como una pista, no como verdad: la verdad se determina al descargar.
     */
    private function parseMedia(array $wa, string $type): array
    {
        $node = (array) ($wa[$type] ?? []);

        return array_filter([
            'caption' => $node['caption'] ?? null,
            // El pie de foto es lo único legible por el agente en un medio.
            'text' => $node['caption'] ?? null,
            'media' => array_filter([
                'kind' => $type,
                'media_id' => $node['id'] ?? null,
                'declared_mime_type' => $node['mime_type'] ?? null,
                'meta_sha256' => $node['sha256'] ?? null,
                'filename' => $node['filename'] ?? null,
                // Nota de voz vs audio adjunto: se muestran distinto en el inbox.
                'voice' => $type === 'audio' ? (bool) ($node['voice'] ?? false) : null,
                'animated' => $type === 'sticker' ? (bool) ($node['animated'] ?? false) : null,
            ], fn ($v) => $v !== null),
        ], fn ($v) => $v !== null);
    }

    /** Respuesta a un botón o a una lista: el título es lo que eligió la persona. */
    private function parseInteractive(array $interactive): array
    {
        $kind = (string) ($interactive['type'] ?? '');
        $reply = (array) ($interactive[$kind] ?? []);

        $title = $reply['title'] ?? null;

        return array_filter([
            'text' => $title,
            'interactive' => array_filter([
                'kind' => $kind !== '' ? $kind : null,
                'id' => $reply['id'] ?? null,
                'title' => $title,
                'description' => $reply['description'] ?? null,
            ], fn ($v) => $v !== null),
        ], fn ($v) => $v !== null);
    }

    /** @return array<int, array<string,mixed>> */
    private function parseContacts(array $contacts): array
    {
        $out = [];
        foreach ($contacts as $contact) {
            $contact = (array) $contact;
            $out[] = array_filter([
                'name' => $contact['name']['formatted_name'] ?? null,
                'phones' => array_values(array_filter(array_map(
                    fn ($p) => is_array($p) ? ($p['wa_id'] ?? $p['phone'] ?? null) : null,
                    (array) ($contact['phones'] ?? []),
                ))),
            ], fn ($v) => $v !== null && $v !== []);
        }

        return $out;
    }

    /**
     * Status callback. Incluye el código de error de Meta cuando el mensaje
     * falló: sin él, "failed" en el inbox no le dice nada a nadie.
     */
    private function parseWhatsappStatus(array $st, array $waMeta): array
    {
        $errors = array_map(
            fn ($e) => $this->normalizeError((array) $e),
            (array) ($st['errors'] ?? []),
        );

        return $this->event([
            'channel' => 'whatsapp',
            'meta_user_id' => $st['recipient_id'] ?? null,
            'wa_id' => $st['recipient_id'] ?? null,
            'message_id' => $st['id'] ?? null,
            'text' => null,
            'name' => null,
            'kind' => 'status:'.($st['status'] ?? 'unknown'),
            'timestamp' => $st['timestamp'] ?? null,
            'phone_number_id' => $waMeta['phone_number_id'] ?? null,
            'display_phone_number' => $waMeta['display_phone_number'] ?? null,
            // El primero basta: Meta manda uno por status fallido.
            'status_error' => $errors[0] ?? null,
            'conversation' => array_filter([
                'id' => $st['conversation']['id'] ?? null,
                'origin' => $st['conversation']['origin']['type'] ?? null,
                'expiration' => $st['conversation']['expiration_timestamp'] ?? null,
            ], fn ($v) => $v !== null) ?: null,
            'pricing' => array_filter([
                'billable' => $st['pricing']['billable'] ?? null,
                'category' => $st['pricing']['category'] ?? null,
                'model' => $st['pricing']['pricing_model'] ?? null,
            ], fn ($v) => $v !== null) ?: null,
            'raw' => $st,
        ]);
    }

    /** Forma estable de un error de Meta, venga de donde venga. */
    private function normalizeError(array $error): array
    {
        return array_filter([
            'code' => isset($error['code']) ? (int) $error['code'] : null,
            'title' => $error['title'] ?? null,
            'message' => $error['message'] ?? null,
            'details' => $error['error_data']['details'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** Normaliza un evento con todas las claves esperadas (defaults null). */
    private function event(array $e): array
    {
        return array_merge([
            'channel' => null,
            'meta_user_id' => null,
            'wa_id' => null,
            'message_id' => null,
            'text' => null,
            'caption' => null,
            'name' => null,
            'kind' => 'event',
            'message_type' => null,
            'timestamp' => null,
            'phone_number_id' => null,
            'display_phone_number' => null,
            'media' => null,
            'context' => null,
            'interactive' => null,
            'location' => null,
            'contacts' => null,
            'reaction' => null,
            'referral' => null,
            'order' => null,
            'system' => null,
            'errors' => null,
            'status_error' => null,
            'conversation' => null,
            'pricing' => null,
            'unsupported' => false,
            'raw' => [],
        ], $e);
    }
}
