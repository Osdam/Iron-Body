<?php

namespace App\Services\Marketing;

use App\Models\Admin;
use App\Models\MarketingAgentAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Gestión de las acciones CRM del agente: consulta filtrada, presentación y
 * transiciones de estado (approve/reject/cancel). La ejecución vive en
 * MarketingAgentActionExecutionService; las sugerencias en RecommendationService.
 */
class MarketingAgentActionService
{
    public function __construct(private readonly MarketingAgentActionAuthorizationService $authz)
    {
    }

    public function list(Request $request, ?Admin $viewer): LengthAwarePaginator
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);

        $query = MarketingAgentAction::query()
            ->with(['conversation:id,lead_id,assigned_to_admin_id', 'lead:id,name,phone'])
            ->latest('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $query->where('action_type', $type);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }
        if (($conv = $request->query('marketing_conversation_id')) !== null && $conv !== '') {
            $query->where('marketing_conversation_id', (int) $conv);
        }
        if ($from = $request->query('date_from')) {
            $query->where('created_at', '>=', $from);
        }

        // Ownership: comercial solo ve acciones de sus conversaciones o sin asignar.
        if (! $this->authz->isFull($viewer) && $viewer instanceof Admin) {
            $query->where(function (Builder $q) use ($viewer): void {
                $q->whereNull('marketing_conversation_id')
                    ->orWhereHas('conversation', fn (Builder $c) => $c
                        ->whereNull('assigned_to_admin_id')
                        ->orWhere('assigned_to_admin_id', $viewer->id));
            });
        }

        return $query->paginate($perPage);
    }

    /** Crea una sugerencia (usado por el motor de recomendaciones). */
    public function createSuggestion(array $data): MarketingAgentAction
    {
        return MarketingAgentAction::create(array_merge([
            'suggested_by'      => 'ai',
            'status'            => MarketingAgentAction::STATUS_SUGGESTED,
            'priority'          => 'normal',
            'requires_approval' => true,
        ], $data));
    }

    public function approve(MarketingAgentAction $action, ?int $adminId): MarketingAgentAction
    {
        $action->forceFill([
            'status'               => MarketingAgentAction::STATUS_APPROVED,
            'approved_by_admin_id' => $adminId,
            'approved_at'          => now(),
        ])->save();

        return $action;
    }

    public function reject(MarketingAgentAction $action, ?int $adminId, ?string $reason): MarketingAgentAction
    {
        $action->forceFill([
            'status'               => MarketingAgentAction::STATUS_REJECTED,
            'rejected_by_admin_id' => $adminId,
            'rejected_at'          => now(),
            'rejection_reason'     => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
        ])->save();

        return $action;
    }

    public function cancel(MarketingAgentAction $action, ?int $adminId): MarketingAgentAction
    {
        $action->forceFill([
            'status'               => MarketingAgentAction::STATUS_CANCELLED,
            'rejected_by_admin_id' => $adminId,
            'rejected_at'          => now(),
        ])->save();

        return $action;
    }

    /** @return array<string,mixed> */
    public function present(MarketingAgentAction $a): array
    {
        return [
            'id'                        => $a->id,
            'uuid'                      => $a->uuid,
            'marketing_lead_id'         => $a->marketing_lead_id,
            'marketing_conversation_id' => $a->marketing_conversation_id,
            'suggested_by'              => $a->suggested_by,
            'action_type'               => $a->action_type,
            'status'                    => $a->status,
            'priority'                  => $a->priority,
            'title'                     => $a->title,
            'reason'                    => $a->reason,
            'payload'                   => $a->payload,
            'result'                    => $a->result,
            'confidence'                => $a->confidence,
            'requires_approval'         => (bool) $a->requires_approval,
            'rejection_reason'          => $a->rejection_reason,
            'failed_reason'             => $a->failed_reason,
            'executed_at'               => $a->executed_at?->toIso8601String(),
            'created_at'                => $a->created_at?->toIso8601String(),
            'lead'                      => $a->lead ? ['id' => $a->lead->id, 'name' => $a->lead->name, 'phone' => $a->lead->phone] : null,
        ];
    }
}
