<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMetaWebhookEvent;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use Illuminate\Console\Command;

/**
 * Simulador del canal de WhatsApp. Inyecta un mensaje entrante por el MISMO
 * pipeline que usa Meta en producción — mismo payload, mismo job, mismos
 * servicios — sin depender de Meta ni de la red:
 *
 *   payload Cloud API → ProcessMetaWebhookEvent → MetaWebhookService::parseEvents
 *   → resolveLead → ensureConversation → recordInbound (idempotente)
 *   → MarketingInboundMessageRouter → SalesAgentOrchestratorService
 *   → herramientas → memoria → respuesta → seguimiento → auditoría
 *
 * Lo único que no ejerce es el controlador HTTP y la firma HMAC, que ya están
 * cubiertos por WebhookMetaController y sus pruebas.
 *
 * SEGURO POR CONSTRUCCIÓN: el envío real sigue gated por META_ENABLED, así que
 * con Meta apagado la respuesta se registra como dry_run y no sale nada.
 */
class SimulateMarketingInbound extends Command
{
    protected $signature = 'marketing:simulate-inbound
        {--from=573001112233 : wa_id del remitente simulado}
        {--text=Hola, cuánto vale la mensualidad? : Texto del mensaje entrante}
        {--name=Prospecto Simulado : Nombre del contacto}
        {--message-id= : meta_message_id (repetir el mismo prueba la idempotencia)}
        {--analyze : Fuerza el análisis del agente aunque auto_analyze esté en false}
        {--execute : Fuerza la ejecución de herramientas aunque auto_execute esté en false}
        {--json : Salida en JSON}';

    protected $description = 'Simula un mensaje entrante de WhatsApp por el pipeline real (sin Meta).';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('analyze') && ! $this->option('json')) {
            $this->warn('Entorno de producción: se escribirán lead, conversación y mensaje reales.');
        }

        $waId = (string) $this->option('from');
        $text = (string) $this->option('text');
        $messageId = (string) ($this->option('message-id') ?: 'wamid.SIM.'.bin2hex(random_bytes(8)));

        // Forzar los flags SOLO en este proceso: no toca el .env ni producción.
        if ($this->option('analyze')) {
            config()->set('marketing.inbound.auto_analyze', true);
        }
        if ($this->option('execute')) {
            config()->set('marketing.agent_enabled', true);
            config()->set('marketing.inbound.auto_execute', true);
        }

        $before = $this->snapshot($waId);

        ProcessMetaWebhookEvent::dispatchSync($this->payload($waId, $text, $messageId));

        $after = $this->snapshot($waId);
        $report = $this->report($before, $after, $messageId, $waId);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($report, $text);

        return self::SUCCESS;
    }

    /** Payload idéntico al que envía WhatsApp Cloud API. */
    private function payload(string $waId, string $text, string $messageId): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => (string) config('meta.whatsapp_business_account_id'),
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => (string) config('meta.whatsapp_display_phone'),
                            'phone_number_id' => (string) config('meta.whatsapp_phone_number_id'),
                        ],
                        'contacts' => [[
                            'profile' => ['name' => (string) $this->option('name')],
                            'wa_id' => $waId,
                        ]],
                        'messages' => [[
                            'from' => $waId,
                            'id' => $messageId,
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /** @return array<string,int> */
    private function snapshot(string $waId): array
    {
        $lead = MarketingLead::where('channel', 'whatsapp')->where('meta_user_id', $waId)->first();

        return [
            'lead_id' => $lead?->id ?? 0,
            'messages' => $lead ? MarketingMessage::whereIn('conversation_id',
                MarketingConversation::where('lead_id', $lead->id)->pluck('id'))->count() : 0,
            'ai_actions' => $lead ? MarketingAiAction::where('lead_id', $lead->id)->count() : 0,
            'followups' => $lead ? MarketingFollowup::where('lead_id', $lead->id)->count() : 0,
        ];
    }

    /** @return array<string,mixed> */
    private function report(array $before, array $after, string $messageId, string $waId): array
    {
        $lead = MarketingLead::where('channel', 'whatsapp')->where('meta_user_id', $waId)->first();
        $conversation = $lead ? MarketingConversation::where('lead_id', $lead->id)->first() : null;
        $inbound = MarketingMessage::where('meta_message_id', $messageId)->first();

        $outbound = $conversation
            ? MarketingMessage::where('conversation_id', $conversation->id)
                ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()
            : null;

        $action = $lead ? MarketingAiAction::where('lead_id', $lead->id)->latest('id')->first() : null;
        $followup = $lead ? MarketingFollowup::where('lead_id', $lead->id)->latest('id')->first() : null;

        return [
            'duplicate' => $before['messages'] === $after['messages'],
            'lead' => $lead ? [
                'id' => $lead->id, 'name' => $lead->name, 'status' => $lead->status,
                'temperature' => $lead->temperature, 'objective' => $lead->objective,
                'contactable' => $lead->isContactable(),
                'can_reply_reactively' => $lead->canReplyReactively(),
                'can_contact_proactively' => $lead->canContactProactively(),
            ] : null,
            'conversation' => $conversation ? [
                'id' => $conversation->id, 'status' => $conversation->status,
                'ai_enabled' => (bool) $conversation->ai_enabled,
                'lead_stage' => $conversation->lead_stage, 'lead_score' => $conversation->lead_score,
                'primary_intent' => $conversation->primary_intent, 'last_intent' => $conversation->last_intent,
                'detected_objective' => $conversation->detected_objective,
                'summary' => $conversation->summary,
                'staff_review_pending' => (bool) $conversation->staff_review_pending,
            ] : null,
            'inbound_message_id' => $inbound?->id,
            'ai_action' => $action ? [
                'type' => $action->action_type, 'status' => $action->status, 'reason' => $action->reason,
            ] : null,
            'reply' => $outbound ? [
                'message_id' => $outbound->id, 'status' => $outbound->status,
                'body' => $outbound->body,
            ] : null,
            'followup' => $followup ? [
                'id' => $followup->id, 'type' => $followup->type, 'status' => $followup->status,
                'conversation_id' => $followup->marketing_conversation_id,
                'due_at' => optional($followup->due_at)->toIso8601String(),
            ] : null,
            'counters' => [
                'messages' => $after['messages'] - $before['messages'],
                'ai_actions' => $after['ai_actions'] - $before['ai_actions'],
                'followups' => $after['followups'] - $before['followups'],
            ],
            'flags' => [
                'meta_enabled' => (bool) config('meta.enabled'),
                'auto_analyze' => (bool) config('marketing.inbound.auto_analyze'),
                'auto_execute' => (bool) config('marketing.inbound.auto_execute'),
                'agent_enabled' => (bool) config('marketing.agent_enabled'),
                'ai_driver' => (string) config('marketing.ai.driver'),
            ],
        ];
    }

    private function render(array $r, string $text): void
    {
        $this->newLine();
        $this->line('  <fg=gray>entrante</> "'.$text.'"');

        if ($r['duplicate']) {
            $this->warn('  DUPLICADO: ese meta_message_id ya existía. No se re-analizó (idempotencia OK).');
            $this->newLine();

            return;
        }

        $rows = [];
        foreach (['lead', 'conversation', 'ai_action', 'reply', 'followup'] as $section) {
            if (empty($r[$section])) {
                $rows[] = [$section, '<fg=gray>—</>'];

                continue;
            }
            foreach ($r[$section] as $k => $v) {
                $rows[] = [$section.'.'.$k, is_bool($v) ? var_export($v, true) : (string) ($v ?? '—')];
            }
        }
        $this->table(['campo', 'valor'], $rows);

        $c = $r['counters'];
        $this->line(sprintf('  <fg=gray>creados</> mensajes:%d  acciones:%d  seguimientos:%d',
            $c['messages'], $c['ai_actions'], $c['followups']));

        $f = $r['flags'];
        $this->line(sprintf('  <fg=gray>flags</> meta:%s  analyze:%s  execute:%s  driver:%s',
            var_export($f['meta_enabled'], true), var_export($f['auto_analyze'], true),
            var_export($f['auto_execute'], true), $f['ai_driver']));

        if (! $f['meta_enabled']) {
            $this->line('  <fg=green>Meta apagado: nada salió al exterior.</>');
        }
        $this->newLine();
    }
}
