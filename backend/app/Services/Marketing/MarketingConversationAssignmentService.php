<?php

namespace App\Services\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;

/**
 * Asignación de una conversación a un asesor/administrador. NO toca la IA.
 * Mantiene compatibilidad con el string legado `lead.assigned_to`.
 */
class MarketingConversationAssignmentService
{
    /**
     * @param  int|null $assigneeId  Admin a asignar; null = desasignar.
     */
    public function assign(MarketingConversation $conversation, ?int $assigneeId, ?int $actorAdminId): MarketingConversation
    {
        $assignee = $assigneeId !== null ? Admin::find($assigneeId) : null;

        $conversation->forceFill([
            'assigned_to_admin_id' => $assignee?->id,
            'assigned_at'          => $assignee ? now() : null,
            'assigned_by'          => $actorAdminId,
        ])->save();

        // Sincroniza el string legado del lead por compatibilidad (no rompe el
        // módulo de mercadeo actual). No activa nada ni cambia ai_enabled.
        if ($conversation->lead) {
            $conversation->lead->forceFill([
                'assigned_to' => $assignee?->name,
            ])->save();
        }

        return $conversation;
    }
}
