<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;

/**
 * Resolución de la marca staff_review. CRÍTICO: resolver NO apaga la IA ni
 * cambia human_takeover — staff_review es solo una alerta para el equipo.
 */
class MarketingStaffReviewService
{
    public function resolve(MarketingConversation $conversation, ?int $adminId, ?string $note = null): MarketingConversation
    {
        $conversation->forceFill([
            'staff_review_pending'     => false,
            'staff_review_resolved_at' => now(),
            'staff_review_resolved_by' => $adminId,
        ])->save();

        // Nota interna opcional con el detalle de la resolución.
        if ($note !== null && trim($note) !== '') {
            app(MarketingConversationNoteService::class)->add(
                $conversation,
                '[staff_review resuelto] '.trim($note),
                $adminId,
            );
        }

        return $conversation;
    }
}
