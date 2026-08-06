<?php

namespace App\Services\Meta;

use App\Models\MetaWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Puerta de entrada duradera del canal: convierte un cuerpo crudo ya verificado
 * por firma en una fila de `meta_webhook_events` antes de que ocurra cualquier
 * trabajo pesado.
 *
 * Todo lo que sigue (parseo, lead, conversación, cerebro comercial, envío) puede
 * fallar y reintentarse porque el hecho original ya está guardado. Si Meta
 * reentrega el mismo cuerpo, `payload_hash` lo reconoce y NO se crea un segundo
 * evento: el webhook responde 200 igual, pero no se reprocesa nada.
 */
class MetaWebhookIngestService
{
    /**
     * Registra el evento crudo. Idempotente por SHA-256 del cuerpo.
     *
     * @param  string  $rawBody  Cuerpo exacto que firmó Meta.
     * @param  array<string,mixed>  $payload  El mismo cuerpo ya decodificado.
     * @return array{event:?MetaWebhookEvent, duplicate:bool}
     */
    public function record(string $rawBody, array $payload, string $correlationId): array
    {
        $hash = hash('sha256', $rawBody);

        $existing = MetaWebhookEvent::where('payload_hash', $hash)->first();
        if ($existing !== null) {
            return ['event' => $existing, 'duplicate' => true];
        }

        $summary = $this->summarize($payload);

        try {
            $event = MetaWebhookEvent::create([
                'correlation_id' => $correlationId,
                'payload_hash' => $hash,
                'object' => $summary['object'],
                'phone_number_id' => $summary['phone_number_id'],
                'payload' => $payload,
                'payload_bytes' => strlen($rawBody),
                'messages_count' => $summary['messages_count'],
                'statuses_count' => $summary['statuses_count'],
                'status' => MetaWebhookEvent::STATUS_PENDING,
            ]);
        } catch (Throwable $e) {
            // Carrera: dos entregas simultáneas del mismo cuerpo. La segunda
            // choca contra el índice único y se resuelve como duplicado, que es
            // exactamente lo que es.
            $raced = MetaWebhookEvent::where('payload_hash', $hash)->first();
            if ($raced !== null) {
                return ['event' => $raced, 'duplicate' => true];
            }

            throw $e;
        }

        return ['event' => $event, 'duplicate' => false];
    }

    /**
     * Resumen barato del payload para poder filtrar sin abrir el JSONB entero.
     * Recorre TODAS las entries/changes: Meta puede agrupar varias en un POST.
     *
     * @return array{object:?string, phone_number_id:?string, messages_count:int, statuses_count:int}
     */
    private function summarize(array $payload): array
    {
        $messages = 0;
        $statuses = 0;
        $phoneNumberId = null;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $messages += count((array) ($value['messages'] ?? []));
                $statuses += count((array) ($value['statuses'] ?? []));
                $phoneNumberId ??= $value['metadata']['phone_number_id'] ?? null;
            }
            // Instagram / Messenger cuentan como mensajes para el mismo resumen.
            $messages += count((array) ($entry['messaging'] ?? []));
        }

        return [
            'object' => isset($payload['object']) ? (string) $payload['object'] : null,
            'phone_number_id' => $phoneNumberId !== null ? (string) $phoneNumberId : null,
            'messages_count' => $messages,
            'statuses_count' => $statuses,
        ];
    }

    /** Identificador del hilo completo, desde este POST hasta el envío de vuelta. */
    public function newCorrelationId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Eventos que quedaron sin procesar y ya no tienen un job vivo detrás.
     * Es la cola de rescate que consume marketing:replay-webhooks.
     *
     * @return \Illuminate\Support\Collection<int, MetaWebhookEvent>
     */
    public function stuck(int $olderThanMinutes = 10, int $limit = 100)
    {
        return MetaWebhookEvent::query()
            ->whereIn('status', [MetaWebhookEvent::STATUS_PENDING, MetaWebhookEvent::STATUS_PROCESSING, MetaWebhookEvent::STATUS_FAILED])
            ->where('created_at', '<=', now()->subMinutes($olderThanMinutes))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Retención: los eventos crudos guardan texto de prospectos, así que no se
     * conservan para siempre. Solo se purga lo ya procesado.
     */
    public function purgeProcessedOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return DB::table('meta_webhook_events')
            ->where('status', MetaWebhookEvent::STATUS_PROCESSED)
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
