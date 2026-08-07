<?php

namespace App\Services\Marketing;

use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Services\Meta\MetaMediaService;
use App\Services\Meta\MetaMessagingService;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\Context;

/**
 * Entrega de un mensaje saliente ya registrado, con reintentos acotados.
 *
 * La regla que sostiene todo esto: **si el mensaje ya tiene `meta_message_id`,
 * Meta lo aceptó y no se vuelve a enviar jamás.** Cloud API no ofrece claves de
 * idempotencia, así que esa comprobación es la única defensa real contra
 * escribirle dos veces a la misma persona cuando un reintento se cruza con un
 * envío que sí había salido.
 *
 * Un fallo no es un fallo cualquiera. Un 429 por límite de tasa es pasajero y
 * merece otro intento; un número que no tiene WhatsApp no mejora por insistir,
 * y reintentarlo solo gasta cuota y ensucia el inbox. `MetaMessagingService`
 * decide cuál es cuál y aquí se actúa en consecuencia.
 */
class WhatsappOutboxService
{
    /** Estado inicial de un saliente que aún no ha salido. */
    public const STATUS_QUEUED = 'queued';

    /** Meta lo aceptó. Los callbacks lo llevarán a delivered/read. */
    public const STATUS_SENT = 'sent';

    /** Falló y aún quedan intentos por delante. */
    public const STATUS_FAILED = 'failed';

    /** Se agotaron los intentos o el fallo no tiene arreglo: mira un humano. */
    public const STATUS_DEAD = 'dead';

    /** Meta apagado: se preparó pero no salió. Sigue siendo un éxito de flujo. */
    public const STATUS_DRY_RUN = 'dry_run';

    public function __construct(private readonly MetaMessagingService $messaging) {}

    /**
     * Intenta entregar un mensaje. Idempotente: llamarlo dos veces sobre el
     * mismo mensaje no lo envía dos veces.
     *
     * @return array{sent:bool,provider_message_id:?string,reason:?string,retryable:bool}
     */
    public function deliver(MarketingMessage $message, string $to): array
    {
        // Ya salió. Este es el punto que impide el doble envío.
        if (! empty($message->meta_message_id)) {
            ChannelLog::info('outbox.skipped', [
                'reason' => 'already_delivered',
                'message_id' => $message->id,
            ]);

            return [
                'sent' => true,
                'provider_message_id' => $message->meta_message_id,
                'reason' => 'already_delivered',
                'retryable' => false,
            ];
        }

        if ($message->status === self::STATUS_DEAD) {
            return ['sent' => false, 'provider_message_id' => null, 'reason' => 'dead', 'retryable' => false];
        }

        $attempt = (int) $message->send_attempts + 1;

        // El intento se registra ANTES de salir a la red. Si el proceso muere
        // en mitad del POST, al volver sabemos que hubo un intento y no
        // partimos de cero creyendo que nunca se intentó.
        $message->forceFill([
            'send_attempts' => $attempt,
            'next_attempt_at' => null,
        ])->save();

        // Un mensaje con archivo no viaja como texto: hay que subir el binario a
        // Meta, obtener su id y mandar ESE id. Si la subida falla, el envío no
        // se intenta —mandar el pie de foto sin la foto es peor que no mandar
        // nada, porque el cliente lee "mira esto" y no hay nada que mirar—.
        $attachment = $this->attachmentFor($message);

        $result = $attachment === null
            ? $this->messaging->sendWhatsappText($to, (string) $message->body, $this->quotedIdFor($message))
            : $this->deliverMedia($message, $attachment, $to);

        if ($result['ok'] && $result['message_id'] !== null) {
            $message->forceFill([
                'status' => self::STATUS_SENT,
                'meta_message_id' => $result['message_id'],
                'last_error_code' => null,
                'last_error_message' => null,
                'next_attempt_at' => null,
            ])->save();

            ChannelLog::info('outbox.sent', [
                'message_id' => $message->id,
                'attempt' => $attempt,
            ]);

            return [
                'sent' => true,
                'provider_message_id' => $result['message_id'],
                'reason' => null,
                'retryable' => false,
            ];
        }

        return $this->recordFailure($message, $result, $attempt);
    }

    /**
     * Decide qué hacer con el fallo: programar otro intento o rendirse y
     * dejarlo visible.
     *
     * @param  array<string,mixed>  $result
     * @return array{sent:bool,provider_message_id:?string,reason:?string,retryable:bool}
     */
    private function recordFailure(MarketingMessage $message, array $result, int $attempt): array
    {
        $maxAttempts = max(1, (int) config('marketing.outbox.max_attempts', 4));
        $retryable = (bool) ($result['retryable'] ?? false);
        $hasAttemptsLeft = $attempt < $maxAttempts;
        $willRetry = $retryable && $hasAttemptsLeft;

        $message->forceFill([
            'status' => $willRetry ? self::STATUS_FAILED : self::STATUS_DEAD,
            'last_error_code' => $result['error_code'] ?? null,
            'last_error_message' => $this->errorSummary($result),
            'next_attempt_at' => $willRetry ? $this->nextAttemptAt($attempt) : null,
            'metadata' => array_merge((array) $message->metadata, [
                'failure' => array_filter([
                    'code' => $result['error_code'] ?? null,
                    'title' => $result['error_title'] ?? null,
                    'message' => $result['error_message'] ?? null,
                    'http_status' => $result['http_status'] ?? null,
                ], fn ($v) => $v !== null),
            ]),
        ])->save();

        ChannelLog::warning($willRetry ? 'outbox.retry_scheduled' : 'outbox.dead', [
            'message_id' => $message->id,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'error_code' => $result['error_code'] ?? null,
            'retryable' => $retryable,
            'next_attempt_at' => $message->next_attempt_at?->toIso8601String(),
        ]);

        return [
            'sent' => false,
            'provider_message_id' => null,
            'reason' => $result['reason'] ?? 'provider_send_failed',
            'retryable' => $willRetry,
        ];
    }

    /**
     * Espera creciente con dispersión.
     *
     * El crecimiento evita martillear a Meta cuando ya nos ha dicho que vamos
     * demasiado rápido. La dispersión evita que veinte mensajes fallidos a la
     * vez vuelvan todos juntos en el mismo segundo y provoquen exactamente el
     * límite de tasa del que estábamos saliendo.
     */
    private function nextAttemptAt(int $attempt): \Illuminate\Support\Carbon
    {
        $baseSeconds = (int) config('marketing.outbox.retry_base_seconds', 30);
        $delay = $baseSeconds * (2 ** max(0, $attempt - 1));
        $delay = min($delay, (int) config('marketing.outbox.retry_max_seconds', 1800));

        $jitter = random_int(0, max(1, (int) ($delay * 0.25)));

        return now()->addSeconds($delay + $jitter);
    }

    /**
     * Mensajes que toca reintentar ahora.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MarketingMessage>
     */
    public function due(int $limit = 50)
    {
        return MarketingMessage::query()
            ->where('status', self::STATUS_FAILED)
            ->whereNull('meta_message_id')          // cinturón: nunca lo ya entregado
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();
    }

    /**
     * El archivo que lleva este mensaje, si lleva alguno.
     *
     * Un saliente carga como mucho uno: WhatsApp entrega un archivo por
     * mensaje, así que adjuntar tres fotos son tres mensajes y no uno con tres.
     * Modelarlo de otra forma haría que el inbox mostrara algo distinto de lo
     * que le llegó al cliente.
     */
    private function attachmentFor(MarketingMessage $message): ?MarketingMessageAttachment
    {
        return MarketingMessageAttachment::query()
            ->where('message_id', $message->id)
            ->where('direction', 'outbound')
            ->orderBy('id')
            ->first();
    }

    /**
     * Sube el archivo y manda el mensaje que lo cita.
     *
     * La subida se hace aquí y no al adjuntar a propósito: entre que alguien
     * suelta la foto en el compositor y pulsa enviar pueden pasar minutos, y
     * subir a Meta lo que quizá nunca se mande gasta cuota y deja archivos
     * nuestros en su servidor sin motivo.
     *
     * `media_id` queda guardado en la ficha, así que un reintento reutiliza la
     * subida en vez de crear un archivo nuevo en Meta.
     *
     * @return array{ok:bool,message_id:?string,http_status:?int,error_code:?int,error_title:?string,error_message:?string,retryable:bool,reason:?string}
     */
    private function deliverMedia(MarketingMessage $message, MarketingMessageAttachment $attachment, string $to): array
    {
        $mediaId = app(MetaMediaService::class)->upload($attachment);

        if ($mediaId === null) {
            ChannelLog::warning('outbox.media_upload_failed', [
                'message_id' => $message->id,
                'attachment_id' => $attachment->id,
            ]);

            // Reintentable: casi siempre es red o un 5xx de Meta. Si es el
            // archivo el que está mal, los intentos se agotan y queda 'dead'
            // con el motivo a la vista, que es lo que necesita quien atiende.
            return [
                'ok' => false, 'message_id' => null, 'http_status' => null,
                'error_code' => null, 'error_title' => 'media_upload_failed',
                'error_message' => 'No se pudo subir el archivo a WhatsApp.',
                'retryable' => true, 'reason' => 'media_upload_failed',
            ];
        }

        // Audio y sticker no admiten pie de foto en Cloud API: mandarlo es un
        // 400. El texto del asesor ya viajó como mensaje aparte.
        $caption = in_array($attachment->kind, ['image', 'video', 'document'], true)
            ? (trim((string) $message->body) ?: null)
            : null;

        return $this->messaging->sendWhatsappMedia(
            $to,
            (string) $attachment->kind,
            $mediaId,
            $caption,
            $attachment->kind === 'document' ? $attachment->original_filename : null,
        );
    }

    /**
     * Si este saliente responde a un mensaje concreto del cliente, se envía
     * como cita. En un hilo largo, ver a qué contesta el gimnasio evita
     * malentendidos.
     */
    private function quotedIdFor(MarketingMessage $message): ?string
    {
        $quoted = data_get($message->metadata, 'reply_to_meta_message_id');

        return is_string($quoted) && $quoted !== '' ? $quoted : null;
    }

    /** @param array<string,mixed> $result */
    private function errorSummary(array $result): ?string
    {
        $parts = array_filter([
            $result['error_title'] ?? null,
            $result['error_message'] ?? null,
        ]);

        return $parts === [] ? null : mb_substr(implode(': ', $parts), 0, 1000);
    }

    /** El correlation_id vigente, para coser el saliente con lo que lo provocó. */
    public static function currentCorrelationId(): ?string
    {
        $id = Context::get('correlation_id');

        return is_string($id) ? $id : null;
    }
}
