<?php

namespace App\Services\Marketing;

use App\Jobs\AnalyzeInboundMessage;
use App\Jobs\DownloadWhatsappMedia;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Services\Meta\MetaMediaService;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\Context;

/**
 * Enruta un mensaje ENTRANTE (ya registrado) hacia el cerebro comercial, con
 * gating de seguridad. Laravel es la autoridad:
 *
 *   - do_not_contact=true          → NO llama al cerebro; registra bloqueo.
 *   - human_takeover manual        → NO llama al cerebro; registra skipped.
 *   - inbound.auto_analyze=false   → NO analiza.
 *   - tipo no analizable (audio,
 *     imagen, documento, ubicación) → se registra y se escala a un humano.
 *   - auto_execute = agent_enabled && inbound.auto_execute (ambos false hoy) →
 *     la decisión queda 'proposed'; no se ejecutan herramientas ni se envía nada.
 *
 * Nunca activa membresías ni aprueba pagos. El envío real sigue gated por META.
 */
class MarketingInboundMessageRouter
{
    public function __construct(private readonly SalesAgentOrchestratorService $orchestrator) {}

    /**
     * Punto de entrada desde el webhook. Decide, según lo que llegó, si esto es
     * algo que el cerebro comercial puede leer o algo que necesita ojos humanos.
     *
     * @param  array<string,mixed>  $parsed  Evento normalizado por MetaWebhookService.
     */
    public function route(
        MarketingLead $lead,
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $parsed,
    ): array {
        $type = (string) ($parsed['message_type'] ?? 'text');

        // Meta nos dijo explícitamente que no pudo entregarnos el contenido, o
        // es un tipo que no existía cuando se escribió este código.
        if (! empty($parsed['unsupported'])) {
            return $this->escalate($lead, $conversation, $message, $type, 'unsupported_message_type');
        }

        // Una reacción (un 👍 a un mensaje nuestro) no es una consulta: se
        // registra para el inbox, pero no despierta al agente ni merece una
        // respuesta automática. Contestar a un emoji sería exactamente el tipo
        // de insistencia que el agente tiene prohibida.
        if ($type === 'reaction') {
            return ['skipped' => true, 'reason' => 'reaction_not_actionable'];
        }

        $analyzable = (array) config('marketing.inbound.supported_message_types', ['text']);

        // Un medio CON pie de foto sí trae una pregunta legible ("¿este plan
        // sirve para mí?" bajo una captura). El texto se analiza; el archivo
        // sigue su camino y queda para el humano.
        $hasReadableText = is_string($parsed['text'] ?? null) && trim((string) $parsed['text']) !== '';

        if (! in_array($type, $analyzable, true) && ! $hasReadableText) {
            return $this->escalate($lead, $conversation, $message, $type, 'needs_human_review');
        }

        return $this->analyze($lead, $conversation, $message);
    }

    /** Analiza (dry_run) un mensaje de texto entrante. Devuelve resultado/omisión. */
    public function analyze(MarketingLead $lead, MarketingConversation $conversation, MarketingMessage $message): array
    {
        if (! (bool) config('marketing.inbound.auto_analyze', true)) {
            return $this->skip($lead, $conversation, 'auto_analyze_disabled');
        }

        // Respeta el opt-out del usuario: do_not_contact o consentimiento denegado.
        if (! $lead->canReplyReactively()) {
            return $this->skip($lead, $conversation, 'do_not_contact');
        }

        // SOLO un takeover MANUAL desde el CRM pausa la IA. Cualquier otro estado
        // (takeover automático viejo, ai_enabled=false heredado) se RECUPERA: la IA
        // nunca queda muda por una acción que ella misma generó.
        if ($conversation->human_takeover && $conversation->human_takeover_source === 'manual') {
            return $this->skip($lead, $conversation, 'skipped_manual_takeover');
        }
        if ($conversation->human_takeover || ! $conversation->ai_enabled) {
            $conversation->forceFill([
                'human_takeover' => false,
                'human_takeover_source' => null,
                'ai_enabled' => true,
            ])->save();
        }

        // Ejecutar herramientas requiere AMBOS flags (hoy false → proposed).
        $autoExecute = (bool) config('marketing.agent_enabled', false)
            && (bool) config('marketing.inbound.auto_execute', false);

        /*
         * Aquí termina el trabajo urgente y empieza el caro.
         *
         * Todo lo anterior —resolver el lead, guardar el mensaje, decidir si
         * hay que analizarlo— es rápido y determinista: cuesta milisegundos y
         * es lo que hace que el cliente aparezca en el inbox. Lo que viene
         * después es una llamada a un modelo que puede tardar quince segundos.
         *
         * Hacerlo aquí mismo significaba que el worker que acababa de guardar
         * este mensaje se quedaba esperando al modelo, y el siguiente cliente
         * que escribiera no existía en el sistema hasta que OpenAI contestara
         * sobre el anterior. Se despacha a su propio carril y este worker
         * queda libre en el acto.
         *
         * `dispatchSync` cuando el análisis se pide en línea (el comando de
         * simulación, y las pruebas que afirman sobre la decisión en la misma
         * petición): ahí no hay nadie esperando al otro lado del teléfono.
         */
        if ($this->analyzeInline()) {
            return $this->orchestrator->handle(
                $lead, $conversation, $message->id, (string) $message->body, null, $autoExecute,
            );
        }

        /*
         * NO se despacha aquí: se DEVUELVE la intención de despachar.
         *
         * Quien llama tiene tomado el cerrojo de esta conversación mientras
         * dura el enrutado, y el job del agente vuelve a pedir ese mismo
         * cerrojo para no analizar dos mensajes de la misma persona a la vez.
         * Encolarlo dentro del cerrojo funciona con una cola de verdad —el job
         * corre después, en otro proceso— pero se bloquea contra sí mismo en
         * cuanto la cola es síncrona: la simulación, `dispatchSync` y la suite
         * entera. Lo descubrió la suite tardando treinta segundos por prueba en
         * lugar de fallar, que es la forma más cara de enterarse.
         *
         * Devolverlo deja el despacho fuera del cerrojo sin perder el orden: la
         * cola es FIFO y el agente vuelve a tomarlo por su cuenta.
         */
        return [
            'analyze_async' => [
                'message_id' => $message->id,
                'auto_execute' => $autoExecute,
                'correlation_id' => Context::get('correlation_id'),
            ],
            'lane' => (string) config('queue.lanes.agent.queue', 'agent'),
        ];
    }

    /**
     * Despacha lo que `route()` dejó pendiente. Se llama FUERA del cerrojo.
     *
     * @param  array<string,mixed>  $result  lo devuelto por route()
     */
    public static function flushDeferred(array $result): void
    {
        $pending = $result['analyze_async'] ?? null;

        if (! is_array($pending)) {
            return;
        }

        AnalyzeInboundMessage::dispatch(
            (int) $pending['message_id'],
            (bool) $pending['auto_execute'],
            $pending['correlation_id'] ?? null,
        );
    }

    /**
     * ¿El análisis corre aquí mismo en vez de encolarse?
     *
     * Solo cuando quien llama necesita la decisión de vuelta: el comando de
     * simulación y las pruebas que comprueban el razonamiento del agente sobre
     * una petición concreta. En el camino real —un webhook de Meta— nunca:
     * ahí lo que importa es soltar el worker cuanto antes.
     */
    private function analyzeInline(): bool
    {
        return (bool) config('marketing.inbound.analyze_inline', false);
    }

    /**
     * Registra los adjuntos del mensaje y encola su descarga.
     *
     * La ficha se crea SIEMPRE, aunque la descarga esté apagada o falle: así el
     * inbox puede decir "llegó una nota de voz" en lugar de mostrar un mensaje
     * vacío que nadie sabe interpretar.
     *
     * @param  array<string,mixed>  $parsed
     */
    public function attachMedia(MarketingMessage $message, array $parsed): ?MarketingMessageAttachment
    {
        $media = $parsed['media'] ?? null;
        if (! is_array($media) || empty($media['media_id'])) {
            return null;
        }

        // Idempotencia: un reproceso del mismo evento no duplica el adjunto.
        $existing = MarketingMessageAttachment::where('message_id', $message->id)
            ->where('media_id', (string) $media['media_id'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $declaredMime = $media['declared_mime_type'] ?? null;

        $attachment = MarketingMessageAttachment::create([
            'message_id' => $message->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'kind' => (string) ($media['kind'] ?? 'document'),
            'media_id' => (string) $media['media_id'],
            'declared_mime_type' => $declaredMime,
            'meta_sha256' => $media['meta_sha256'] ?? null,
            // El nombre que puso el cliente se guarda SANEADO y solo se muestra;
            // nunca participa en la ruta del archivo.
            'original_filename' => isset($media['filename'])
                ? MetaMediaService::sanitizeFilename((string) $media['filename'])
                : null,
            'voice' => (bool) ($media['voice'] ?? false),
            'status' => MarketingMessageAttachment::STATUS_PENDING,
            'metadata' => array_filter([
                'caption' => $parsed['caption'] ?? null,
                'animated' => $media['animated'] ?? null,
            ], fn ($v) => $v !== null) ?: null,
        ]);

        ChannelLog::info('media.registered', [
            'attachment_id' => $attachment->id,
            'message_id' => $message->id,
            'kind' => $attachment->kind,
            'declared_mime' => $declaredMime,
        ]);

        // Fuera del camino del webhook: la URL de Meta caduca en minutos, así
        // que se encola de inmediato y con prioridad propia.
        DownloadWhatsappMedia::dispatch($attachment->id, Context::get('correlation_id'));

        return $attachment;
    }

    /**
     * Mensaje que la IA no debe interpretar sola (audio, imagen sin texto,
     * documento, ubicación, tipo desconocido): se registra para revisión humana
     * sin llamar al modelo. Conservador a propósito: es preferible que un
     * asesor conteste una foto a que el agente adivine qué muestra.
     */
    public function escalate(
        MarketingLead $lead,
        MarketingConversation $conversation,
        MarketingMessage $message,
        string $type,
        string $reason,
    ): array {
        MarketingAiAction::create([
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'action_type' => 'unsupported_message',
            'reason' => $reason,
            'status' => 'proposed',
            'metadata' => [
                'message_type' => $type,
                'message_id' => $message->id,
                'recommended_action' => 'escalate_human',
            ],
        ]);

        // Marca visible en el inbox: hay algo esperando a una persona.
        $conversation->forceFill(['staff_review_pending' => true])->save();

        ChannelLog::info('inbound.escalated', [
            'conversation_id' => $conversation->id,
            'message_type' => $type,
            'reason' => $reason,
        ]);

        return ['skipped' => true, 'reason' => $reason, 'message_type' => $type];
    }

    /**
     * Compatibilidad: el nombre viejo de escalate() para tipos no soportados.
     *
     * @deprecated Usar route(), que decide el motivo correcto.
     */
    public function recordUnsupported(MarketingLead $lead, MarketingConversation $conversation, MarketingMessage $message, string $type): array
    {
        return $this->escalate($lead, $conversation, $message, $type, 'unsupported_message_type');
    }

    /** Registra una omisión de análisis (auditoría) y devuelve el motivo. */
    private function skip(MarketingLead $lead, MarketingConversation $conversation, string $reason): array
    {
        MarketingAiAction::create([
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'action_type' => 'inbound_skipped',
            'reason' => $reason,
            'status' => 'skipped',
            'metadata' => ['reason' => $reason],
        ]);

        return ['skipped' => true, 'reason' => $reason];
    }
}
