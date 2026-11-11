<?php

namespace App\Services\Marketing;

use App\Models\MarketingLead;
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

    /**
     * Hito 9 · W9 (SC-10): por qué un REINTENTO de una plantilla de la
     * automatización comercial ya no sale (los mismos códigos que la puerta,
     * {@see MarketingMessageDispatcher::dispatchWhatsappTemplate()}) y cómo lo
     * lee quien atiende el Inbox.
     *
     * @var array<string, array{title: string, message: string}>
     */
    public const OUTREACH_TEMPLATE_REFUSALS = [
        'do_not_contact' => [
            'title' => 'No se envió: la persona pidió que no le escriban',
            'message' => 'Este mensaje automático esperaba un reintento y se canceló: el contacto está marcado como «no contactar» o rechazó el tratamiento de sus datos.',
        ],
        'no_proactive_consent' => [
            'title' => 'No se envió: ya no consta la autorización para escribirle primero',
            'message' => 'Este mensaje automático esperaba un reintento y se canceló: la autorización de la persona ya no está vigente.',
        ],
        'do_not_contact_sibling' => [
            'title' => 'No se envió: este número pidió que no le escriban',
            'message' => 'Este mensaje automático esperaba un reintento y se canceló: otro registro con el mismo número está marcado como «no contactar».',
        ],
    ];

    public function __construct(
        private readonly MetaMessagingService $messaging,
        private readonly MarketingAgentSwitch $agentSwitch = new MarketingAgentSwitch,
    ) {}

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

        // Una persona manda sobre la IA, también hacia atrás.
        if ($this->supersededByHuman($message)) {
            $message->forceFill([
                'status' => self::STATUS_DEAD,
                'last_error_message' => 'superseded_by_human_takeover',
                'next_attempt_at' => null,
            ])->save();

            ChannelLog::info('outbox.cancelled', [
                'reason' => 'human_takeover',
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
            ]);

            return [
                'sent' => false,
                'provider_message_id' => null,
                'reason' => 'human_takeover',
                'retryable' => false,
            ];
        }

        /*
         * El agente pausado, también hacia atrás: un saliente de la máquina que
         * esperaba su reintento, o que se redactó justo antes de pausar, no sale
         * ni durante la pausa ni al reactivar —se juzga por cuándo NACIÓ—. Queda
         * muerto y visible en el Inbox con su motivo. Va aquí porque este es el
         * único punto por el que sale un mensaje, también desde retry-outbox.
         */
        if ($this->agentSwitch->holdsOutbound(
            (string) $message->sender_type,
            is_array($message->metadata) ? $message->metadata : null,
            $message->created_at,
        )) {
            $message->forceFill([
                'status' => self::STATUS_DEAD,
                // Sin el código ni el fallo de Meta de un intento anterior: a
                // este saliente no lo paró Meta sino la pausa. IRON GUARD titula
                // sus incidentes con ese código, y el Inbox le enseñaría al
                // asesor aquel fallo como si fuera el motivo.
                'last_error_code' => null,
                'last_error_message' => MarketingAgentSwitch::REASON,
                'next_attempt_at' => null,
                'metadata' => array_merge((array) $message->metadata, [
                    'failure' => [
                        'code' => MarketingAgentSwitch::REASON,
                        'title' => 'No se envió: el agente IA estaba en pausa',
                        'message' => 'Esta respuesta automática se canceló al pausar el agente IA. Si hace falta, contesta a mano.',
                    ],
                ]),
            ])->save();

            ChannelLog::info('outbox.cancelled', [
                'reason' => MarketingAgentSwitch::REASON,
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
            ]);

            return [
                'sent' => false,
                'provider_message_id' => null,
                'reason' => MarketingAgentSwitch::REASON,
                'retryable' => false,
            ];
        }

        /*
         * Hito 9 · W9 (SC-10): una PLANTILLA de la automatización comercial que
         * vuelve por un REINTENTO pasa otra vez las guardas de la persona que
         * pasó al nacer en la puerta (`dispatchWhatsappTemplate`). Entre el
         * primer intento y el último pasan minutos, y en ese tiempo la persona
         * pudo retirar su autorización o pedir que no le escriban —ella o una
         * hermana con su mismo número—. Si ya no se puede, no sale: queda muerta
         * y visible en el Inbox con su motivo, como en la pausa. Solo plantillas
         * del H9 y solo reintentos: el texto libre de hoy y el primer intento,
         * que la puerta acaba de comprobar, no cambian.
         */
        $refusal = $this->outreachTemplateRefusal($message, $to);
        if ($refusal !== null) {
            $message->forceFill([
                'status' => self::STATUS_DEAD,
                // Como en la pausa: no lo paró Meta, y el Inbox enseñaría un fallo anterior de Meta como el motivo.
                'last_error_code' => null,
                'last_error_message' => $refusal,
                'next_attempt_at' => null,
                'metadata' => array_merge((array) $message->metadata, [
                    'failure' => ['code' => $refusal] + self::OUTREACH_TEMPLATE_REFUSALS[$refusal],
                ]),
            ])->save();

            ChannelLog::info('outbox.cancelled', [
                'reason' => $refusal,
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
            ]);

            return [
                'sent' => false,
                'provider_message_id' => null,
                'reason' => $refusal,
                'retryable' => false,
            ];
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

        // Hito 9 · W9 (contrato H9, D9-9.3): un saliente de PLANTILLA de la
        // automatización comercial sale como plantilla —también en cada
        // reintento— y nunca como texto libre.
        $template = $this->outreachTemplateResult($message, $to);

        $result = $template ?? ($attachment === null
            ? $this->messaging->sendWhatsappText($to, (string) $message->body, $this->quotedIdFor($message))
            : $this->deliverMedia($message, $attachment, $to));

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
     * ¿Una persona tomó el control después de que la IA escribiera esto?
     *
     * El caso que obliga a esta comprobación no tiene nada roto dentro: la IA
     * redactó una respuesta, Meta la rechazó con un 429, y quedó esperando su
     * turno en la cola de reintentos. Media hora después una persona entró en
     * la conversación —normalmente porque el cliente se quejó o porque el
     * agente se equivocó— y está hablando ella. Si ese reintento sale ahora, el
     * cliente recibe al bot justo después de que un humano le dijera que a
     * partir de ahora le atiende él, y con un texto que responde a una
     * conversación que ya no existe.
     *
     * El corte es por AUTOR, no por conversación: el mensaje que escribió el
     * propio asesor sí se reintenta. Cancelarlo también sería quitarle al
     * humano su mensaje justo cuando acaba de tomar el mando, que es el
     * problema contrario y igual de malo.
     *
     * Va aquí y no en el comando de reintentos porque este es el único punto
     * por el que sale un mensaje: una barrera que hay que acordarse de poner en
     * cada sitio es una barrera que algún día falta.
     */
    private function supersededByHuman(MarketingMessage $message): bool
    {
        if ($message->sender_type !== MarketingMessage::SENDER_AI) {
            return false;
        }

        $conversation = $message->conversation;

        return $conversation !== null
            && (bool) $conversation->human_takeover
            && $conversation->human_takeover_source === 'manual';
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
     * Hito 9 · W9: la rama de PLANTILLAS. null si el mensaje no es una
     * plantilla de la automatización comercial (todo lo de hoy sigue igual).
     *
     * Si lo es, sale por `sendWhatsappTemplate` con la plantilla que guardó
     * la puerta ({@see OutreachTemplateMessage::fromStored()}). Fuera de la
     * ventana de 24 h Meta solo admite plantillas aprobadas, y dentro de ella
     * mandar el texto como libre sería mandar algo que nadie aprobó: si lo
     * guardado no tiene la forma del contrato, el mensaje NO sale (fallo
     * definitivo, queda `dead` y visible en el Inbox con su motivo).
     *
     * @return array{ok:bool,message_id:?string,http_status:?int,error_code:?int,error_title:?string,error_message:?string,retryable:bool,reason:?string}|null
     */
    private function outreachTemplateResult(MarketingMessage $message, string $to): ?array
    {
        if (! OutreachTemplateMessage::isTemplate($message->metadata)) {
            return null;
        }

        $template = OutreachTemplateMessage::fromStored($message->metadata, $message->body);
        if ($template === null) {
            ChannelLog::warning('outbox.template_invalid', ['message_id' => $message->id]);

            return [
                'ok' => false, 'message_id' => null, 'http_status' => null,
                'error_code' => null, 'error_title' => 'template_metadata_invalid',
                'error_message' => 'La plantilla guardada no tiene la forma del contrato: no se envía.',
                'retryable' => false, 'reason' => 'template_metadata_invalid',
            ];
        }

        return $this->messaging->sendWhatsappTemplate($to, $template->name, $template->language, $template->components());
    }

    /**
     * Hito 9 · W9 (SC-10): el motivo por el que un REINTENTO de una plantilla
     * de la automatización comercial ya no puede salir, o null si puede (o si
     * no es una plantilla del H9, o es su primer intento). Las guardas de la
     * persona de la puerta, en su orden: `canReplyReactively()`
     * (`do_not_contact`), `canContactProactively()` (`no_proactive_consent`;
     * sin prospecto no consta) y la baja de las hermanas con el mismo número
     * NORMALIZADO, como `MarketingMessageDispatcher::phoneOptedOut()`
     * (`do_not_contact_sibling`).
     */
    private function outreachTemplateRefusal(MarketingMessage $message, string $to): ?string
    {
        if (! OutreachTemplateMessage::isTemplate($message->metadata) || (int) $message->send_attempts < 1) {
            return null;
        }

        $lead = $message->conversation?->lead;
        if ($lead === null) {
            return 'no_proactive_consent';
        }
        if (! $lead->canReplyReactively()) {
            return 'do_not_contact';
        }
        if (! $lead->canContactProactively()) {
            return 'no_proactive_consent';
        }

        // La misma regla que la puerta: filtrar por la bandera y comparar el número normalizado en PHP.
        $hermanas = MarketingLead::query()
            ->where('do_not_contact', true)
            ->where('id', '!=', $lead->id)
            ->whereNotNull('phone')
            ->get(['id', 'phone']);
        if ($hermanas->isEmpty()) {
            return null;
        }
        $telefonos = app(MarketingMessageDispatcher::class);

        return $hermanas->contains(fn (MarketingLead $hermana): bool => $telefonos->normalizePhone($hermana->phone) === $to)
            ? 'do_not_contact_sibling'
            : null;
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
