<?php

namespace App\Services\Marketing;

use App\Models\MarketingAgentAction;
use App\Models\MarketingConversation;
use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ejecuta una acción CRM del agente de forma SEGURA: whitelist estricta por
 * action_type (sin eval/dispatch dinámico), validación de payload, y registro
 * de result/failed_reason. NUNCA envía WhatsApp real, ni toca pagos, membresías
 * o facturación. Reutiliza los servicios existentes del CRM.
 */
class MarketingAgentActionExecutionService
{
    public function __construct(
        private readonly MarketingConversationNoteService $notes,
        private readonly MarketingConversationTagService $tags,
        private readonly MarketingConversationAssignmentService $assignment,
        private readonly MarketingManualTakeoverService $takeover,
        private readonly MarketingAppointmentService $appointments,
    ) {
    }

    /**
     * Ejecuta la acción. Devuelve el modelo actualizado (executed o failed).
     */
    public function execute(MarketingAgentAction $action, ?int $executedByAdminId): MarketingAgentAction
    {
        $conversation = $action->conversation;
        $payload = $action->payload ?? [];

        $error = $this->validatePayload($action->action_type, $payload, $conversation);
        if ($error !== null) {
            return $this->fail($action, $error, $executedByAdminId);
        }

        try {
            $result = match ($action->action_type) {
                MarketingAgentAction::TYPE_CREATE_NOTE         => $this->execCreateNote($conversation, $payload, $executedByAdminId),
                MarketingAgentAction::TYPE_ADD_TAG             => $this->execAddTag($conversation, $payload, $executedByAdminId),
                MarketingAgentAction::TYPE_DRAFT_REPLY         => $this->execDraftReply($payload),
                MarketingAgentAction::TYPE_SUGGEST_APPOINTMENT => $this->execSuggestAppointment($payload),
                MarketingAgentAction::TYPE_CREATE_APPOINTMENT  => $this->execCreateAppointment($action, $payload, $executedByAdminId),
                MarketingAgentAction::TYPE_CREATE_FOLLOW_UP    => $this->execCreateFollowUp($action, $payload, $executedByAdminId),
                MarketingAgentAction::TYPE_ASSIGN_CONVERSATION => $this->execAssign($conversation, $payload, $executedByAdminId),
                MarketingAgentAction::TYPE_REQUEST_STAFF_REVIEW => $this->execRequestStaffReview($conversation, $payload),
                MarketingAgentAction::TYPE_PAUSE_AI            => $this->execPauseAi($conversation, $payload, $executedByAdminId),
                MarketingAgentAction::TYPE_RELEASE_AI          => $this->execReleaseAi($conversation, $executedByAdminId),
                MarketingAgentAction::TYPE_UPDATE_LEAD_PROFILE => $this->execUpdateLeadProfile($action, $payload),
                default                                        => null,
            };

            if ($result === null) {
                return $this->fail($action, 'unknown_action_type', $executedByAdminId);
            }

            $action->forceFill([
                'status'               => MarketingAgentAction::STATUS_EXECUTED,
                'result'               => $result,
                'executed_by_admin_id' => $executedByAdminId,
                'executed_at'          => now(),
                'failed_reason'        => null,
            ])->save();

            Log::info('marketing.agent_action.executed', [
                'id' => $action->id, 'type' => $action->action_type, 'admin_id' => $executedByAdminId,
            ]);

            return $action;
        } catch (Throwable $e) {
            Log::warning('marketing.agent_action.failed', [
                'id' => $action->id, 'type' => $action->action_type, 'error' => $e->getMessage(),
            ]);

            return $this->fail($action, 'execution_error', $executedByAdminId);
        }
    }

    /** Validación de payload por tipo. Devuelve mensaje de error o null. */
    public function validatePayload(string $type, array $payload, ?MarketingConversation $conversation): ?string
    {
        $needsConversation = in_array($type, [
            MarketingAgentAction::TYPE_CREATE_NOTE, MarketingAgentAction::TYPE_ADD_TAG,
            MarketingAgentAction::TYPE_ASSIGN_CONVERSATION, MarketingAgentAction::TYPE_REQUEST_STAFF_REVIEW,
            MarketingAgentAction::TYPE_PAUSE_AI, MarketingAgentAction::TYPE_RELEASE_AI,
        ], true);

        if ($needsConversation && $conversation === null) {
            return 'conversation_required';
        }

        return match ($type) {
            MarketingAgentAction::TYPE_CREATE_NOTE => $this->str($payload, 'body') ? null : 'note_body_required',
            MarketingAgentAction::TYPE_ADD_TAG     => $this->str($payload, 'tag') ? null : 'tag_required',
            MarketingAgentAction::TYPE_DRAFT_REPLY => $this->str($payload, 'draft') ? null : 'draft_required',
            MarketingAgentAction::TYPE_CREATE_APPOINTMENT => $this->validateAppointment($payload),
            MarketingAgentAction::TYPE_CREATE_FOLLOW_UP   => $this->str($payload, 'due_at') ? null : 'due_at_required',
            MarketingAgentAction::TYPE_ASSIGN_CONVERSATION => isset($payload['assigned_to_admin_id']) ? null : 'assignee_required',
            MarketingAgentAction::TYPE_UPDATE_LEAD_PROFILE => (isset($payload['temperature']) || isset($payload['stage'])) ? null : 'profile_field_required',
            // suggest_appointment / request_staff_review / pause_ai / release_ai: sin payload obligatorio
            default => null,
        };
    }

    private function validateAppointment(array $payload): ?string
    {
        if (! $this->str($payload, 'scheduled_at')) {
            return 'scheduled_at_required';
        }
        if (strtotime((string) $payload['scheduled_at']) === false) {
            return 'scheduled_at_invalid';
        }
        if (! $this->str($payload, 'title')) {
            return 'title_required';
        }

        return null;
    }

    // ── Efectos (reusan servicios existentes) ────────────────────────────────

    private function execCreateNote(MarketingConversation $c, array $p, ?int $admin): array
    {
        $note = $this->notes->add($c, (string) $p['body'], $admin);

        return ['note_id' => $note->id];
    }

    private function execAddTag(MarketingConversation $c, array $p, ?int $admin): array
    {
        $tags = $this->tags->apply($c, [(string) $p['tag']], [], $admin);

        return ['tags' => $tags];
    }

    /** draft_reply: NUNCA envía WhatsApp. Solo deja la respuesta sugerida. */
    private function execDraftReply(array $p): array
    {
        return ['draft' => (string) $p['draft'], 'sent' => false];
    }

    private function execSuggestAppointment(array $p): array
    {
        // Sugerencia informativa: NO crea cita automáticamente.
        return ['suggested' => true, 'hint' => $p['hint'] ?? null, 'created_appointment' => false];
    }

    private function execCreateAppointment(MarketingAgentAction $action, array $p, ?int $admin): array
    {
        $appointment = $this->appointments->create([
            'type'                      => $p['type'] ?? 'visit',
            'title'                     => $p['title'],
            'scheduled_at'              => $p['scheduled_at'],
            'duration_minutes'          => $p['duration_minutes'] ?? 45,
            'location'                  => $p['location'] ?? null,
            'notes'                     => $p['notes'] ?? null,
            'marketing_lead_id'         => $action->marketing_lead_id,
            'marketing_conversation_id' => $action->marketing_conversation_id,
        ], $admin);

        return ['appointment_id' => $appointment->id];
    }

    private function execCreateFollowUp(MarketingAgentAction $action, array $p, ?int $admin): array
    {
        $followup = MarketingFollowup::create([
            'lead_id'                   => $action->marketing_lead_id,
            'marketing_conversation_id' => $action->marketing_conversation_id,
            'assigned_to_admin_id'      => $p['assigned_to_admin_id'] ?? $admin,
            'due_at'                    => $p['due_at'],
            'type'                      => $p['type'] ?? 'task',
            'status'                    => MarketingFollowup::STATUS_PENDING,
            'reason'                    => $p['reason'] ?? null,
            'message_template'          => $p['message_template'] ?? null,
        ]);

        return ['followup_id' => $followup->id];
    }

    private function execAssign(MarketingConversation $c, array $p, ?int $admin): array
    {
        $this->assignment->assign($c, $p['assigned_to_admin_id'] ?? null, $admin);

        return ['assigned_to_admin_id' => $p['assigned_to_admin_id'] ?? null];
    }

    private function execRequestStaffReview(MarketingConversation $c, array $p): array
    {
        // Marca revisión SIN apagar la IA (alerta para el equipo).
        $c->forceFill([
            'staff_review_pending' => true,
            'staff_review_reason'  => $p['reason'] ?? 'agent_requested',
        ])->save();

        return ['staff_review_pending' => true, 'ai_enabled' => (bool) $c->ai_enabled];
    }

    private function execPauseAi(MarketingConversation $c, array $p, ?int $admin): array
    {
        // Un humano confirma la pausa → takeover manual (la IA no se apaga sola).
        $this->takeover->takeover($c, $admin, 'agent_suggestion: '.(string) ($p['reason'] ?? 'sensible'));

        return ['human_takeover' => true, 'origin' => 'agent_suggestion'];
    }

    private function execReleaseAi(MarketingConversation $c, ?int $admin): array
    {
        $this->takeover->release($c, $admin);

        return ['human_takeover' => false];
    }

    private function execUpdateLeadProfile(MarketingAgentAction $action, array $p): array
    {
        $lead = $action->marketing_lead_id ? MarketingLead::find($action->marketing_lead_id) : null;
        $conversation = $action->conversation;
        $applied = [];

        // temperatura → columna real marketing_leads.temperature (validada).
        if (isset($p['temperature']) && in_array($p['temperature'], ['cold', 'warm', 'hot'], true)) {
            if ($lead) {
                $lead->forceFill(['temperature' => $p['temperature']])->save();
            }
            $applied['temperature'] = $p['temperature'];
        }

        // etapa comercial → marketing_conversations.lead_stage (columna real, string libre).
        if (isset($p['stage']) && is_string($p['stage']) && $p['stage'] !== '') {
            if ($conversation) {
                $conversation->forceFill(['lead_stage' => $p['stage']])->save();
            }
            $applied['stage'] = $p['stage'];
        }

        // Copia íntegra en metadata del lead para trazabilidad (no asume columnas).
        if ($lead) {
            $meta = $lead->metadata ?? [];
            $meta['agent_profile'] = array_merge($meta['agent_profile'] ?? [], $applied, ['at' => now()->toIso8601String()]);
            $lead->forceFill(['metadata' => $meta])->save();
        }

        return ['applied' => $applied];
    }

    private function fail(MarketingAgentAction $action, string $reason, ?int $admin): MarketingAgentAction
    {
        $action->forceFill([
            'status'               => MarketingAgentAction::STATUS_FAILED,
            'failed_reason'        => $reason,
            'executed_by_admin_id' => $admin,
            'executed_at'          => now(),
        ])->save();

        return $action;
    }

    private function str(array $payload, string $key): bool
    {
        return isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '';
    }
}
