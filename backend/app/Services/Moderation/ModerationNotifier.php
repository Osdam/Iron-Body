<?php

namespace App\Services\Moderation;

use App\Models\ContentReport;
use App\Models\Member;
use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Services\NotificationService;
use App\Services\RealtimeEvents;
use App\Support\Moderation\ActionType;
use App\Support\Moderation\ModerationScope;
use App\Support\Moderation\ReportReason;
use App\Support\Moderation\ReportStatus;

/**
 * Notificaciones del sistema de moderación.
 *
 * Reglas de privacidad que esta clase hace cumplir:
 *  - Al REPORTANTE se le confirma la recepción y el cierre del caso, nunca la
 *    sanción concreta que recibió el otro usuario.
 *  - Al REPORTADO no se le avisa por cada reporte (sería un canal de acoso):
 *    solo cuando existe una acción administrativa real.
 *  - A los MODERADORES se les avisa de casos críticos con un enlace al CRM,
 *    nunca con el contenido sensible en el push o el correo.
 *
 * Se apoya en {@see NotificationService}, que ya gestiona idempotencia por
 * `event_key`, colas y push. Si notificar falla, no rompe la moderación.
 */
class ModerationNotifier
{
    public function __construct(private NotificationService $notifications) {}

    /** Acuse de recibo para quien reportó. Confidencial y sin detalles. */
    public function reportReceived(Member $reporter, ContentReport $report): void
    {
        $this->notifications->createMemberNotification($reporter, [
            'type' => 'system',
            'title' => 'Recibimos tu reporte',
            'message' => 'Nuestro equipo revisará el contenido. Tu reporte es confidencial: '
                .'la otra persona no sabrá quién lo envió.',
            'priority' => 'low',
            'should_popup' => false,
            'action_type' => 'moderation_report',
            'action_payload' => ['report_id' => $report->public_id],
            'metadata' => ['report_public_id' => $report->public_id],
            'event_key' => "ugc_report_received_{$report->public_id}",
        ]);
    }

    /**
     * Cierre del caso para quien reportó. NO revela qué sanción se aplicó ni a
     * quién: solo que se revisó y se tomó (o no) una medida.
     */
    public function reportClosed(ContentReport $report): void
    {
        $reporter = $report->reporter;
        if (! $reporter instanceof Member) {
            return;
        }

        $tookAction = $report->resolution_code !== null
            && $report->resolution_code !== ReportStatus::RESOLUTION_NO_VIOLATION;

        $this->notifications->createMemberNotification($reporter, [
            'type' => 'system',
            'title' => 'Revisamos tu reporte',
            'message' => $tookAction
                ? 'Revisamos el contenido que reportaste y tomamos medidas según nuestros '
                    .'lineamientos de comunidad. Gracias por ayudarnos a cuidar la comunidad.'
                : 'Revisamos el contenido que reportaste y no encontramos una infracción de '
                    .'nuestros lineamientos de comunidad. Gracias por avisarnos.',
            'priority' => 'low',
            'should_popup' => false,
            'action_type' => 'moderation_report',
            'action_payload' => ['report_id' => $report->public_id],
            'metadata' => ['report_public_id' => $report->public_id],
            'event_key' => "ugc_report_closed_{$report->public_id}",
        ]);

        $report->forceFill(['reporter_notified_at' => now()])->saveQuietly();
    }

    /**
     * Aviso al usuario SANCIONADO. Incluye motivo público, alcance, duración y
     * si puede apelar. Nunca notas internas ni identidad del reportante.
     */
    public function actionApplied(ModerationAction $action): void
    {
        $member = $action->targetMember;
        if (! $member instanceof Member) {
            return;
        }

        $title = match ($action->action_type) {
            ActionType::WARN => 'Advertencia sobre tu contenido',
            ActionType::HIDE_CONTENT => 'Retiramos un estado del feed',
            ActionType::REMOVE_CONTENT => 'Eliminamos un estado',
            ActionType::RESTRICT_POSTING => 'Restringimos la publicación de estados',
            ActionType::SUSPEND_SOCIAL => 'Suspendimos tus funciones sociales',
            ActionType::SUSPEND_FULL => 'Restringimos el acceso a la app',
            default => 'Actualización de moderación',
        };

        $parts = [];
        if ($action->reason) {
            $parts[] = $action->reason;
        }
        if (ActionType::createsSuspension($action->action_type)) {
            $parts[] = ModerationScope::memberExplanation($action->scope);
        }
        if ($action->ends_at) {
            $parts[] = 'La restricción termina el '
                .$action->ends_at->timezone(Member::BUSINESS_TZ)->format('d/m/Y \a \l\a\s H:i').'.';
        }
        if ($action->isAppealable() && config('ugc.appeals_enabled', true)) {
            $parts[] = 'Puedes apelar esta decisión desde Configuración → Moderación y apelaciones.';
        }

        $this->notifications->createMemberNotification($member, [
            'type' => 'system',
            'title' => $title,
            'message' => implode(' ', $parts) ?: 'Se aplicó una medida de moderación a tu cuenta.',
            'priority' => 'high',
            'should_popup' => true,
            'action_type' => 'moderation_status',
            'action_payload' => ['action_id' => $action->public_id],
            'metadata' => [
                'action_public_id' => $action->public_id,
                'scope' => $action->scope,
            ],
            'event_key' => "ugc_action_applied_{$action->public_id}",
        ]);

        // El estado de moderación cambió → la app refresca sin esperar polling.
        RealtimeEvents::emit((int) $member->id, RealtimeEvents::APP_STATE, ['moderation']);
    }

    /** Aviso de que se levantó una sanción. */
    public function actionRevoked(ModerationAction $action): void
    {
        $member = $action->targetMember;
        if (! $member instanceof Member) {
            return;
        }

        $this->notifications->createMemberNotification($member, [
            'type' => 'system',
            'title' => 'Se levantó la restricción',
            'message' => 'Revisamos tu caso y retiramos la restricción aplicada a tu cuenta. '
                .'Ya puedes usar las funciones sociales con normalidad.',
            'priority' => 'high',
            'should_popup' => true,
            'action_type' => 'moderation_status',
            'action_payload' => ['action_id' => $action->public_id],
            'metadata' => ['action_public_id' => $action->public_id],
            'event_key' => "ugc_action_revoked_{$action->public_id}",
        ]);

        RealtimeEvents::emit((int) $member->id, RealtimeEvents::APP_STATE, ['moderation']);
    }

    /** Resolución de una apelación. Estado + mensaje público, nada interno. */
    public function appealResolved(ModerationAppeal $appeal): void
    {
        $member = $appeal->member;
        if (! $member instanceof Member) {
            return;
        }

        $granted = $appeal->status === ModerationAppeal::STATUS_GRANTED;

        $this->notifications->createMemberNotification($member, [
            'type' => 'system',
            'title' => $granted ? 'Aceptamos tu apelación' : 'Resolvimos tu apelación',
            'message' => $appeal->public_resolution
                ?: ($granted
                    ? 'Revisamos tu apelación y retiramos la restricción.'
                    : 'Revisamos tu apelación y mantenemos la decisión original.'),
            'priority' => 'high',
            'should_popup' => true,
            'action_type' => 'moderation_status',
            'action_payload' => ['appeal_id' => $appeal->public_id],
            'metadata' => ['appeal_public_id' => $appeal->public_id],
            'event_key' => "ugc_appeal_resolved_{$appeal->public_id}",
        ]);

        RealtimeEvents::emit((int) $member->id, RealtimeEvents::APP_STATE, ['moderation']);
    }

    /**
     * Aviso al CRM. Solo casos de severidad alta o crítica para no saturar la
     * bandeja. NUNCA incluye el contenido reportado — solo el identificador
     * público para abrir el caso en el CRM autenticado.
     */
    public function notifyModerators(ContentReport $report): void
    {
        if (! in_array($report->severity, [
            ReportReason::SEVERITY_HIGH,
            ReportReason::SEVERITY_CRITICAL,
        ], true)) {
            return;
        }

        $this->notifications->createAdminNotification([
            'type' => 'system',
            'title' => $report->severity === ReportReason::SEVERITY_CRITICAL
                ? 'Reporte CRÍTICO de contenido'
                : 'Nuevo reporte de contenido',
            'message' => 'Motivo: '.ReportReason::labelFor($report->reason_code)
                .'. Revísalo en Moderación de comunidad.',
            'priority' => $report->severity === ReportReason::SEVERITY_CRITICAL ? 'high' : 'medium',
            'should_popup' => $report->severity === ReportReason::SEVERITY_CRITICAL,
            'action_type' => 'moderation_case',
            'action_payload' => ['report_id' => $report->public_id],
            'metadata' => [
                'report_public_id' => $report->public_id,
                'reason_code' => $report->reason_code,
                'severity' => $report->severity,
            ],
            'event_key' => "ugc_admin_report_{$report->public_id}",
        ]);
    }

    /** Aviso al miembro de que su apelación entró en revisión. */
    public function appealReceived(ModerationAppeal $appeal): void
    {
        $member = $appeal->member;
        if (! $member instanceof Member) {
            return;
        }

        $this->notifications->createMemberNotification($member, [
            'type' => 'system',
            'title' => 'Recibimos tu apelación',
            'message' => 'Un moderador revisará tu caso. Te avisaremos cuando haya una decisión.',
            'priority' => 'medium',
            'should_popup' => false,
            'action_type' => 'moderation_status',
            'action_payload' => ['appeal_id' => $appeal->public_id],
            'metadata' => ['appeal_public_id' => $appeal->public_id],
            'event_key' => "ugc_appeal_received_{$appeal->public_id}",
        ]);
    }
}
