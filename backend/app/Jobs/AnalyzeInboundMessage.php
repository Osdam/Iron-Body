<?php

namespace App\Jobs;

use App\Models\MarketingAiAction;
use App\Models\MarketingMessage;
use App\Services\Marketing\SalesAgentOrchestratorService;
use App\Services\Observability\ChannelLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

/**
 * Pone al agente comercial a pensar sobre un mensaje YA GUARDADO.
 *
 * Existe por una razón concreta y medida. Hasta ahora esto ocurría dentro de
 * `ProcessMetaWebhookEvent`, en la misma ejecución que guardaba el mensaje: el
 * worker recibía el evento, escribía el mensaje en el inbox y **se quedaba
 * bloqueado esperando a OpenAI** antes de poder atender el siguiente. Con un
 * solo proceso, eso significaba que mientras el modelo tardaba quince segundos
 * en contestar sobre el cliente A, el mensaje del cliente B no existía todavía
 * en ningún sitio. La fase F.6 lo midió: el rendimiento pasaba de 4,46 a 0,52
 * trabajos por segundo y una ráfaga de cincuenta mensajes dejaba al último
 * esperando minuto y medio.
 *
 * Separarlo cambia la propiedad importante: guardar un mensaje entrante ya no
 * depende de que un servicio externo conteste. Lo caro y lo incierto viven en
 * su propio carril; lo que una persona está esperando, no.
 *
 * La contrapartida es que entre guardar y analizar pasa un tiempo, y en ese
 * hueco pueden cambiar cosas —alguien toma la conversación, el cliente pide que
 * no le escriban—. Por eso las condiciones se vuelven a comprobar AQUÍ y no se
 * confía en las que había cuando se encoló: si un humano entró por medio, el
 * agente se calla, que es justo lo que se quiere.
 */
class AnalyzeInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Espera creciente: si el modelo está caído, no se insiste cada 20 s. */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $messageId,
        public bool $autoExecute = false,
        public ?string $correlationId = null,
    ) {
        $lane = (array) config('queue.lanes.agent');
        $this->onQueue($lane['queue'] ?? 'agent');
    }

    /** Un mensaje se analiza una sola vez a la vez. */
    public function uniqueId(): string
    {
        return 'analyze-inbound:'.$this->messageId;
    }

    public function handle(SalesAgentOrchestratorService $orchestrator): void
    {
        if ($this->correlationId !== null) {
            Context::add('correlation_id', $this->correlationId);
        }

        $message = MarketingMessage::with('conversation.lead')->find($this->messageId);

        if ($message === null) {
            ChannelLog::warning('agent.message.missing', ['message_id' => $this->messageId]);

            return;
        }

        $conversation = $message->conversation;
        $lead = $conversation?->lead;

        if ($conversation === null || $lead === null) {
            ChannelLog::warning('agent.context.missing', ['message_id' => $this->messageId]);

            return;
        }

        Context::add('conversation_id', $conversation->id);

        /*
         * Idempotencia. El job puede llegar dos veces —un reintento que se
         * cruza, un replay del evento— y analizar dos veces no es inofensivo:
         * son dos decisiones, dos registros y, con la autonomía encendida, dos
         * acciones sobre la misma persona.
         */
        $yaAnalizado = MarketingAiAction::query()
            ->where('conversation_id', $conversation->id)
            ->whereJsonContains('metadata->message_id', $message->id)
            ->exists();

        if ($yaAnalizado) {
            ChannelLog::info('agent.skipped', [
                'reason' => 'already_analyzed',
                'message_id' => $message->id,
            ]);

            return;
        }

        // Lo que pudo cambiar mientras esto esperaba turno.
        if ($skip = $this->gateReason($conversation, $lead)) {
            MarketingAiAction::create([
                'lead_id' => $lead->id,
                'conversation_id' => $conversation->id,
                'action_type' => 'inbound_skipped',
                'reason' => $skip,
                'status' => 'skipped',
                'metadata' => ['reason' => $skip, 'message_id' => $message->id],
            ]);

            ChannelLog::info('agent.skipped', [
                'reason' => $skip,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);

            return;
        }

        /*
         * Dos mensajes de la misma persona no se analizan a la vez.
         *
         * Con varios workers en el carril del agente esto deja de ser teórico:
         * quien escribe tres veces seguidas puede tener sus tres mensajes en
         * tres procesos distintos, y el agente contestaría tres veces a una
         * conversación que aún no había leído entera. El cerrojo es por
         * conversación, el mismo que ya usaba la ingesta.
         */
        Cache::lock('marketing:conversation:'.$conversation->id, 300)->block(
            30,
            fn () => $orchestrator->handle(
                $lead->fresh(),
                $conversation->fresh(),
                $message->id,
                (string) $message->body,
                null,
                $this->autoExecute,
            ),
        );

        ChannelLog::info('agent.analyzed', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'auto_execute' => $this->autoExecute,
        ]);
    }

    /**
     * ¿Sigue habiendo motivo para que hable el agente?
     *
     * Se comprueba con los datos de AHORA. Un takeover manual ocurrido entre la
     * ingesta y este momento tiene que ganar: es el mismo principio que impide
     * que un reintento del outbox pise a una persona que tomó el mando.
     */
    private function gateReason($conversation, $lead): ?string
    {
        if (! (bool) config('marketing.inbound.auto_analyze', true)) {
            return 'auto_analyze_disabled';
        }

        if (! $lead->canReplyReactively()) {
            return 'do_not_contact';
        }

        if ($conversation->human_takeover && $conversation->human_takeover_source === 'manual') {
            return 'skipped_manual_takeover';
        }

        return null;
    }

    /** Agotados los intentos, queda dicho por qué el agente no contestó. */
    public function failed(?\Throwable $e): void
    {
        ChannelLog::error('agent.analysis_failed', [
            'message_id' => $this->messageId,
            'error_class' => $e !== null ? class_basename($e) : null,
        ]);
    }
}
