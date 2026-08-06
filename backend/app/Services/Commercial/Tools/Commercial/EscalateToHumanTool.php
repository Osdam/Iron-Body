<?php

namespace App\Services\Commercial\Tools\Commercial;

use App\Models\CommercialOpportunity;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\Tools\BaseTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolResult;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\DB;

/**
 * Ceder la conversación a una persona.
 *
 * Es la única herramienta del conjunto que quita capacidades en vez de darlas,
 * y por eso tiene dos rasgos que ninguna otra tiene:
 *
 *  · **No exige que la autonomía esté encendida.** Callarse nunca necesita
 *    permiso. Si hiciera falta el flag, un cliente enfadado durante la fase de
 *    pruebas se quedaría hablando con un robot precisamente en el momento en
 *    que menos lo tolera.
 *  · **Es irreversible desde el lado del agente.** El agente puede apartarse;
 *    volver a entrar lo decide una persona desde el inbox.
 *
 * Además cierra las oportunidades abiertas como BLOQUEADAS, no como perdidas:
 * el caso no fracasó, se apartó, y tiene que poder retomarse sin haber
 * registrado una derrota falsa.
 */
class EscalateToHumanTool extends BaseTool
{
    public const REASONS = [
        'customer_request',   // lo pidió explícitamente
        'frustration',        // molestia detectada
        'complaint',          // queja formal
        'complex_case',       // fuera de lo que el agente sabe resolver
        'sensitive_topic',    // salud, lesión, dinero en disputa
        'payment_dispute',
    ];

    public function name(): string
    {
        return 'escalate_to_human';
    }

    public function description(): string
    {
        return 'Cede la conversación a una persona del equipo y deja de responder. '
            .'Úsala en cuanto alguien la pida, se moleste, ponga una queja o el caso te supere. '
            .'Ante la duda, escala: es preferible molestar a un asesor que a un cliente.';
    }

    public function schema(): array
    {
        return $this->strictSchema([
            'reason' => $this->stringProp('Motivo del escalado.', self::REASONS),
            'summary' => $this->stringProp('Resumen breve para que el asesor entienda el caso sin leerlo todo.'),
        ], ['reason']);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'in:'.implode(',', self::REASONS)],
            'summary' => ['sometimes', 'string', 'max:500'],
        ];
    }

    public function featureFlag(): ?string
    {
        // Sin flag a propósito: apartarse siempre tiene que estar disponible.
        return null;
    }

    /** Ver el docblock de la clase: callarse nunca necesita permiso. */
    public function requiresAutonomy(): bool
    {
        return false;
    }

    public function timeoutSeconds(): int
    {
        return 5;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $conversation = $context->conversation;
        $lead = $context->lead;

        if ($conversation === null) {
            return ToolResult::failed('no_conversation', 'No hay conversación que ceder.');
        }

        $reason = (string) $arguments['reason'];
        $summary = $arguments['summary'] ?? null;

        if ($conversation->human_takeover) {
            return ToolResult::skipped('La conversación ya estaba en manos de una persona.');
        }

        DB::transaction(function () use ($conversation, $lead, $reason, $summary): void {
            $conversation->forceFill([
                'human_takeover' => true,
                'human_takeover_source' => 'agent_escalation',
                // Se apaga la IA además de marcar el traspaso. Son dos banderas
                // distintas y confiar solo en una deja el hueco por el que el
                // agente sigue contestando.
                'ai_enabled' => false,
                'staff_review_pending' => true,
                'staff_review_reason' => $reason,
            ])->save();

            if ($lead !== null) {
                $lead->forceFill([
                    'status' => \App\Models\MarketingLead::STATUS_NEEDS_HUMAN,
                    'last_human_takeover_at' => now(),
                    'human_takeover_reason' => $reason,
                ])->save();
            }

            // Las oportunidades abiertas se bloquean, no se pierden.
            CommercialOpportunity::query()
                ->whereIn('status', V::OPEN_STATUSES)
                ->where(function ($q) use ($lead, $conversation): void {
                    if ($lead !== null) {
                        $q->orWhere('marketing_lead_id', $lead->id);
                    }
                    $q->orWhere('marketing_conversation_id', $conversation->id);
                })
                ->get()
                ->each(fn (CommercialOpportunity $o) => $o->close(
                    V::STATUS_BLOCKED,
                    'escalated_to_human',
                ));
        });

        ChannelLog::info('commercial.escalated', [
            'conversation_id' => $conversation->id,
            'lead_id' => $lead?->id,
            'reason' => $reason,
        ]);

        return ToolResult::ok([
            'conversation_id' => $conversation->id,
            'reason' => $reason,
            'summary' => $summary,
            'ai_paused' => true,
        ], 'La conversación quedó en manos del equipo. No vuelvas a responder en este hilo.');
    }
}
