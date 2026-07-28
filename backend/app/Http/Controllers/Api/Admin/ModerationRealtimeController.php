<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ModerationAuditLog;
use App\Services\Moderation\ModerationAudit;
use App\Support\Moderation\ModerationPermission;
use App\Support\SseStream;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Canal real-time del módulo de Moderación para el CRM (SSE).
 *
 * **Por qué no hay tabla nueva.** Cada hecho de moderación ya escribe una fila
 * append-only en `moderation_audit_logs` con id monotónico y payload saneado
 * ({@see ModerationAudit}). Ese log ES el flujo de
 * eventos: se transmite con un cursor sobre su `id`. Añadir una segunda tabla
 * de eventos habría creado dos fuentes que pueden desincronizarse.
 *
 * **Reutiliza la infraestructura existente**: mismo {@see SseStream} que los
 * canales de miembro y de notificaciones (heartbeat `: ping`, `retry:`, `id:`
 * para reanudar). No se introduce Reverb, Pusher ni WebSockets.
 *
 * **Anonimato del reportante — la regla crítica.** Los registros de
 * `report_submitted` y `appeal_submitted` tienen `actor_type=member` y
 * `actor_id=<id del miembro>`. Transmitir eso al CRM revelaría quién reportó,
 * que es justo lo que el sistema promete no hacer. {@see sanitize()} elimina el
 * `actor_id` de todo actor de tipo `member` antes de emitir.
 *
 * **El evento nunca sustituye a la API.** Solo dice "algo cambió, con este id";
 * el CRM refetchea por los endpoints normales, que revalidan permisos. Un
 * evento no entrega datos que el moderador no pudiera pedir por sí mismo.
 */
class ModerationRealtimeController extends Controller
{
    /**
     * Mapa de acción de auditoría → nombre de evento público del canal.
     * Cubre los 14 eventos autoritativos del contrato de tiempo real.
     *
     * @var array<string, string>
     */
    private const EVENT_MAP = [
        ModerationAuditLog::ACTION_REPORT_SUBMITTED => 'story.reported',
        ModerationAuditLog::ACTION_MEMBER_BLOCKED => 'user.blocked',
        ModerationAuditLog::ACTION_MEMBER_UNBLOCKED => 'user.unblocked',
        ModerationAuditLog::ACTION_REPORT_ASSIGNED => 'moderation.report.assigned',
        ModerationAuditLog::ACTION_REPORT_STATUS_CHANGED => 'moderation.report.updated',
        ModerationAuditLog::ACTION_CONTENT_QUARANTINED => 'content.hidden',
        ModerationAuditLog::ACTION_CONTENT_RESTORED => 'content.restored',
        ModerationAuditLog::ACTION_ACTION_APPLIED => 'moderation.action.applied',
        ModerationAuditLog::ACTION_ACTION_REVOKED => 'moderation.action.revoked',
        ModerationAuditLog::ACTION_APPEAL_SUBMITTED => 'moderation.appeal.created',
        ModerationAuditLog::ACTION_APPEAL_RESOLVED => 'moderation.appeal.resolved',
        ModerationAuditLog::ACTION_EVIDENCE_VIEWED => 'moderation.evidence.viewed',
        ModerationAuditLog::ACTION_EVIDENCE_PURGED => 'moderation.evidence.purged',
        ModerationAuditLog::ACTION_GUIDELINES_ACCEPTED => 'moderation.guidelines.accepted',
    ];

    /**
     * GET /api/admin/moderation/stream
     *
     * `?after_id=` permite reanudar sin perder eventos tras una reconexión; sin
     * él se parte del último id (solo lo nuevo). El id de cada evento SSE es el
     * del registro de auditoría, así que el cliente deduplica de forma trivial.
     */
    public function stream(Request $request): SymfonyResponse
    {
        /** @var Admin|null $admin */
        $admin = $request->attributes->get('auth_admin');
        $admin = $admin instanceof Admin ? $admin : null;

        // El canal exige el mismo permiso que ver la cola. Sin él no se abre.
        if (! ModerationPermission::allows($admin, ModerationPermission::VIEW)) {
            return response()->json([
                'ok' => false,
                'code' => 'forbidden',
                'message' => 'No tienes permiso para el canal de moderación.',
                'required_permission' => ModerationPermission::VIEW,
            ], 403);
        }

        $cursor = $request->filled('after_id')
            ? (int) $request->query('after_id')
            : (int) (ModerationAuditLog::max('id') ?? 0);

        return SseStream::response(function () use (&$cursor): void {
            $items = ModerationAuditLog::query()
                ->where('id', '>', $cursor)
                ->whereIn('action', array_keys(self::EVENT_MAP))
                ->orderBy('id')
                ->limit(50)
                ->get();

            foreach ($items as $log) {
                SseStream::emit(
                    'moderation',
                    $this->sanitize($log),
                    $log->id,
                );
                $cursor = (int) $log->id;
            }
        }, 25, 1500); // ~25 s por conexión; el cliente reconecta solo.
    }

    /**
     * Payload público del evento.
     *
     * Contiene lo mínimo para que el CRM decida QUÉ refetchear. Nunca el
     * contenido reportado, ni notas internas, ni —sobre todo— la identidad del
     * reportante.
     *
     * @return array<string, mixed>
     */
    private function sanitize(ModerationAuditLog $log): array
    {
        $isAdminActor = $log->actor_type === 'admin';

        return [
            'type' => self::EVENT_MAP[$log->action] ?? $log->action,
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            // Solo se identifica al actor cuando es un ADMIN (útil para no
            // refrescar en el mismo navegador que originó el cambio). Si el
            // actor es un miembro, su id NO viaja: sería revelar al reportante.
            'actor_type' => $log->actor_type,
            'actor_admin_id' => $isAdminActor ? $log->actor_id : null,
            // Timestamp del SERVIDOR: el cliente no impone su reloj.
            'timestamp' => $log->created_at?->toIso8601String(),
        ];
    }
}
