<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Observers\Commercial\ComplaintCommercialEmitter;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\DB;

/**
 * Resolución de la marca staff_review. CRÍTICO: resolver NO apaga la IA ni
 * cambia human_takeover — staff_review es solo una alerta para el equipo.
 */
class MarketingStaffReviewService
{
    /** El motivo de la revisión que marca una queja (`StaffReviewAuthority`). */
    public const REASON_COMPLAINT = 'complaint';

    public function resolve(MarketingConversation $conversation, ?int $adminId, ?string $note = null): MarketingConversation
    {
        $conversation->forceFill([
            'staff_review_pending' => false,
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

    /**
     * Q1-8 (Enmienda Q1-8 del contrato H9): resolver una revisión marcada como
     * QUEJA con su categoría cerrada ({@see ComplaintCommercialEmitter::REVIEW_CATEGORIES}).
     *
     *  - `not_a_complaint`: las quejas que marcó la IA en ESTA conversación
     *    desde la resolución anterior de su revisión (lo ya resuelto no se
     *    toca) dejan de frenar: `complaint_dismissed` por cada una, con quién lo
     *    decidió. No borra nada. Las quejas posteriores, las del personal y las
     *    de otra conversación siguen frenando. Solo con la revisión PENDIENTE
     *    (si no, 409 `staff_review_not_pending`, NB1).
     *  - `complaint_confirmed` y `other`: siguen frenando; se resuelve como hoy.
     *
     * Todo o nada, con la fila de la conversación bloqueada: si un descarte no
     * queda registrado, la revisión sigue pendiente (503) y se puede repetir
     * (cada descarte es idempotente por la clave de su queja). Sin categoría se
     * usa {@see resolve()}, como siempre.
     *
     * @return int cuántas quejas de la IA quedaron descartadas
     *
     * @throws StaffReviewComplaintException
     */
    public function resolveComplaint(MarketingConversation $conversation, ?int $adminId, string $category, ?string $note = null): int
    {
        if ($adminId === null) {
            throw new StaffReviewComplaintException('person_required', 403, 'Descartar o confirmar una queja lo decide una persona: entra con tu cuenta del CRM.');
        }
        if (! in_array($category, ComplaintCommercialEmitter::REVIEW_CATEGORIES, true)) {
            throw new StaffReviewComplaintException('complaint_category_invalid', 422, 'La categoría no es válida.');
        }

        $descartadas = DB::transaction(function () use ($conversation, $adminId, $category): int {
            $c = MarketingConversation::query()->lockForUpdate()->find($conversation->id);
            if ($c === null) {
                throw new StaffReviewComplaintException('not_found', 404, 'Conversación no encontrada.');
            }
            if ($c->staff_review_reason !== self::REASON_COMPLAINT) {
                throw new StaffReviewComplaintException('complaint_category_not_applicable', 422, 'Esta revisión no está marcada como queja: resuélvela sin categoría.');
            }
            $n = 0;
            if ($category === ComplaintCommercialEmitter::NOT_A_COMPLAINT) {
                // NB1 (revisión de API de Q1-8): solo se descarta lo que alguien está revisando. Con la revisión ya
                // resuelta no hay nada pendiente: un descarte alcanzaría a una queja posterior que nadie ha visto.
                if (! (bool) $c->staff_review_pending) {
                    throw new StaffReviewComplaintException('staff_review_not_pending', 409, 'Esta revisión ya está resuelta: no hay ninguna queja pendiente que descartar.');
                }
                if (! ComplaintCommercialEmitter::enabled()) {
                    throw new StaffReviewComplaintException('commercial_events_disabled', 409, 'El registro de hechos comerciales está apagado en el servidor: la queja no se puede descartar ahora. La revisión sigue pendiente.');
                }
                try {
                    $n = app(ComplaintCommercialEmitter::class)->dismissAiComplaints((int) $c->id, (int) $c->lead_id, $c->staff_review_resolved_at, now(), $adminId);
                } catch (\RuntimeException $e) {
                    ChannelLog::error('commercial.complaint.dismiss_failed', ['conversation_id' => (int) $c->id, 'exception' => class_basename($e)]);

                    throw new StaffReviewComplaintException('complaint_dismissal_failed', 503, 'No se pudo descartar la queja: la revisión sigue pendiente. Inténtalo de nuevo en un momento.');
                }
            }
            $c->forceFill([
                'staff_review_pending' => false,
                'staff_review_resolved_at' => now(),
                'staff_review_resolved_by' => $adminId,
            ])->save();

            return $n;
        });
        $conversation->refresh();

        // Nota interna opcional, como en resolve().
        if ($note !== null && trim($note) !== '') {
            app(MarketingConversationNoteService::class)->add(
                $conversation,
                '[staff_review resuelto] '.trim($note),
                $adminId,
            );
        }

        return $descartadas;
    }
}
