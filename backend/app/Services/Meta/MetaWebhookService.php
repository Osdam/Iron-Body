<?php

namespace App\Services\Meta;

/**
 * Verificación y parseo de webhooks de Meta. NO depende de tokens de Graph:
 * el reto (challenge) usa META_VERIFY_TOKEN y la firma usa META_WEBHOOK_SECRET,
 * por eso es 100% testeable en local aunque META_ENABLED esté en false.
 */
class MetaWebhookService
{
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
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Normaliza el payload de Meta a una lista de eventos simples por canal.
     * Soporta Instagram/Facebook (object=page|instagram, entry[].messaging[]) y
     * WhatsApp (object=whatsapp_business_account, entry[].changes[].value).
     *
     * @return array<int, array{channel:string, meta_user_id:?string, message_id:?string, text:?string, name:?string, kind:string, raw:array}>
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
                    'channel'      => $channel,
                    'meta_user_id' => $m['sender']['id'] ?? null,
                    'message_id'   => $msg['mid'] ?? null,
                    'text'         => $msg['text'] ?? null,
                    'name'         => null,
                    'kind'         => $msg ? 'message' : 'event',
                    'message_type' => $msg ? 'text' : null,
                    'raw'          => $m,
                ]);
            }

            // WhatsApp Cloud API.
            foreach (($entry['changes'] ?? []) as $change) {
                $value   = $change['value'] ?? [];
                $waMeta  = $value['metadata'] ?? [];
                $contactName = $value['contacts'][0]['profile']['name'] ?? null;

                foreach (($value['messages'] ?? []) as $wa) {
                    $events[] = $this->event([
                        'channel'              => 'whatsapp',
                        'meta_user_id'         => $wa['from'] ?? null,
                        'wa_id'                => $wa['from'] ?? null,
                        'message_id'           => $wa['id'] ?? null,
                        'text'                 => $wa['text']['body'] ?? null,
                        'name'                 => $contactName,
                        'kind'                 => 'message',
                        'message_type'         => $wa['type'] ?? 'text',
                        'timestamp'            => $wa['timestamp'] ?? null,
                        'phone_number_id'      => $waMeta['phone_number_id'] ?? null,
                        'display_phone_number' => $waMeta['display_phone_number'] ?? null,
                        'raw'                  => $wa,
                    ]);
                }
                // Estados de entrega (sent/delivered/read) → solo trazas.
                foreach (($value['statuses'] ?? []) as $st) {
                    $events[] = $this->event([
                        'channel'              => 'whatsapp',
                        'meta_user_id'         => $st['recipient_id'] ?? null,
                        'wa_id'                => $st['recipient_id'] ?? null,
                        'message_id'           => $st['id'] ?? null,
                        'text'                 => null,
                        'name'                 => null,
                        'kind'                 => 'status:' . ($st['status'] ?? 'unknown'),
                        'timestamp'            => $st['timestamp'] ?? null,
                        'phone_number_id'      => $waMeta['phone_number_id'] ?? null,
                        'display_phone_number' => $waMeta['display_phone_number'] ?? null,
                        'raw'                  => $st,
                    ]);
                }
            }
        }

        return $events;
    }

    /** Normaliza un evento con todas las claves esperadas (defaults null). */
    private function event(array $e): array
    {
        return array_merge([
            'channel'              => null,
            'meta_user_id'         => null,
            'wa_id'                => null,
            'message_id'           => null,
            'text'                 => null,
            'name'                 => null,
            'kind'                 => 'event',
            'message_type'         => null,
            'timestamp'            => null,
            'phone_number_id'      => null,
            'display_phone_number' => null,
            'raw'                  => [],
        ], $e);
    }
}
