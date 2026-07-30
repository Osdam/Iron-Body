<?php

namespace App\Services\Moderation;

use App\Models\Admin;
use App\Models\ContentReport;
use App\Models\MemberSuspension;
use App\Models\ModerationAction;
use App\Models\ModerationAuditLog;
use App\Models\Story;
use App\Support\Moderation\ActionType;
use App\Support\Moderation\ModerationPermission;
use App\Support\Moderation\ModerationScope;
use App\Support\Moderation\ReportStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Decisiones administrativas sobre un caso de moderación.
 *
 * Garantías que da esta clase (y que ningún controlador reimplementa):
 *  - Cada acción exige el permiso que declara {@see ActionType::requiredPermission()}.
 *  - Las transiciones siguen el grafo de {@see ReportStatus}; nada arbitrario.
 *  - Todo ocurre dentro de una transacción con `lockForUpdate` sobre el caso.
 *  - Control OPTIMISTA con `lock_version`: si dos moderadores resuelven el
 *    mismo caso a la vez, el segundo recibe `concurrent_modification` en lugar
 *    de aplicar una sanción duplicada.
 *  - `idempotency_key` impide que un reintento de red aplique dos veces la
 *    misma decisión.
 *  - Nada de esto toca membresías, pagos, Wompi ni facturación electrónica.
 */
class ModerationDecisionService
{
    public function __construct(
        private ModerationAudit $audit,
        private ModerationNotifier $notifier,
        private EvidenceService $evidence,
    ) {}

    // ── Asignación ────────────────────────────────────────────────────────

    public function assign(
        ContentReport $report,
        ?Admin $actor,
        ?int $assigneeAdminId,
        ?Request $request = null,
    ): ContentReport {
        $this->assertPermission($actor, ModerationPermission::ASSIGN);

        if ($report->isClosed()) {
            throw new RuntimeException('report_closed');
        }

        if ($assigneeAdminId !== null && ! Admin::whereKey($assigneeAdminId)->exists()) {
            throw new RuntimeException('assignee_not_found');
        }

        $before = ['assigned_admin_id' => $report->assigned_admin_id];

        $report->forceFill([
            'assigned_admin_id' => $assigneeAdminId,
            'lock_version' => $report->lock_version + 1,
        ])->save();

        $this->audit->admin(
            $actor,
            ModerationAuditLog::ACTION_REPORT_ASSIGNED,
            'content_report',
            (int) $report->id,
            $before,
            ['assigned_admin_id' => $assigneeAdminId],
            $request,
        );

        return $report->refresh();
    }

    // ── Transiciones de estado ────────────────────────────────────────────

    /**
     * Cambia el estado del caso respetando la máquina de estados.
     *
     * `$expectedVersion` implementa el control optimista: el CRM manda la
     * versión que tenía en pantalla; si otro moderador ya movió el caso, esto
     * falla en vez de pisar su trabajo.
     */
    public function transition(
        ContentReport $report,
        ?Admin $actor,
        string $toStatus,
        ?int $expectedVersion = null,
        ?string $notes = null,
        ?Request $request = null,
    ): ContentReport {
        $this->assertPermission($actor, ModerationPermission::REVIEW);

        if (! in_array($toStatus, ReportStatus::all(), true)) {
            throw new RuntimeException('invalid_status');
        }

        return DB::transaction(function () use (
            $report,
            $actor,
            $toStatus,
            $expectedVersion,
            $notes,
            $request
        ): ContentReport {
            /** @var ContentReport $fresh */
            $fresh = ContentReport::whereKey($report->id)->lockForUpdate()->firstOrFail();

            $this->assertVersion($fresh, $expectedVersion);

            $from = $fresh->status;

            // Idempotencia: pedir el estado que ya tiene no es un error.
            if ($from === $toStatus) {
                return $fresh;
            }

            if (! ReportStatus::canTransition($from, $toStatus)) {
                throw new RuntimeException('invalid_transition');
            }

            $this->applyStatus($fresh, $toStatus, $notes);
            $fresh->save();

            $this->audit->admin(
                $actor,
                ModerationAuditLog::ACTION_REPORT_STATUS_CHANGED,
                'content_report',
                (int) $fresh->id,
                ['status' => $from],
                ['status' => $toStatus],
                $request,
            );

            if ($toStatus === ReportStatus::CLOSED || $toStatus === ReportStatus::DISMISSED) {
                $this->evidence->scheduleRetention($fresh);
                $this->notifier->reportClosed($fresh);
            }

            return $fresh;
        });
    }

    // ── Decisión (aplica una acción de moderación) ────────────────────────

    /**
     * Aplica una acción administrativa y resuelve el caso.
     *
     * @param  array{
     *     action_type: string,
     *     duration_minutes?: int|null,
     *     public_reason?: string|null,
     *     internal_notes?: string|null,
     *     idempotency_key?: string|null,
     *     expected_version?: int|null,
     * }  $input
     */
    public function decide(
        ContentReport $report,
        ?Admin $actor,
        array $input,
        ?Request $request = null,
    ): ModerationAction {
        $actionType = (string) ($input['action_type'] ?? '');

        if (! in_array($actionType, ActionType::all(), true)) {
            throw new RuntimeException('invalid_action');
        }

        // El permiso lo declara la propia acción — no hay tabla duplicada.
        $this->assertPermission($actor, ActionType::requiredPermission($actionType));

        // Un reporte de PERFIL no tiene publicación asociada: retirar, ocultar o
        // restaurar contenido no tendría sobre qué actuar. Antes esto pasaba en
        // silencio (`applyContentEffect` salía sin hacer nada) y el caso quedaba
        // cerrado como «contenido retirado» sin haberse retirado nada. Se
        // rechaza explícitamente para que el moderador elija una medida real.
        if ($report->content_type !== ContentReport::CONTENT_TYPE_STORY
            && in_array($actionType, [
                ActionType::HIDE_CONTENT,
                ActionType::REMOVE_CONTENT,
                ActionType::RESTORE_CONTENT,
            ], true)) {
            throw new RuntimeException('action_requires_content');
        }

        $durationMinutes = $input['duration_minutes'] ?? null;
        $durationMinutes = $durationMinutes === null ? null : (int) $durationMinutes;

        // Una sanción PERMANENTE (sin fecha de fin) siempre exige permiso
        // elevado, aunque el tipo de acción por sí solo no lo exigiera.
        if (ActionType::createsSuspension($actionType) && $durationMinutes === null) {
            $this->assertPermission($actor, ModerationPermission::SUSPEND_FULL_ACCESS);
        }

        $idempotencyKey = $input['idempotency_key'] ?? null;
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = ModerationAction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                // Reintento del mismo POST: se devuelve la acción original sin
                // volver a sancionar.
                return $existing;
            }
        } else {
            $idempotencyKey = null;
        }

        return DB::transaction(function () use (
            $report,
            $actor,
            $actionType,
            $durationMinutes,
            $input,
            $idempotencyKey,
            $request
        ): ModerationAction {
            /** @var ContentReport $fresh */
            $fresh = ContentReport::whereKey($report->id)->lockForUpdate()->firstOrFail();

            $this->assertVersion($fresh, $input['expected_version'] ?? null);

            if ($fresh->isClosed()) {
                throw new RuntimeException('report_closed');
            }

            $scope = ActionType::scopeFor($actionType);
            $startsAt = now();
            $endsAt = $durationMinutes !== null && $durationMinutes > 0
                ? $startsAt->copy()->addMinutes($durationMinutes)
                : null;

            $action = ModerationAction::create([
                'report_id' => $fresh->id,
                'target_member_id' => $fresh->reported_member_id,
                'target_story_id' => $fresh->content_type === ContentReport::CONTENT_TYPE_STORY
                    ? $fresh->content_id
                    : null,
                'action_type' => $actionType,
                'scope' => $scope,
                'duration_minutes' => $durationMinutes,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $this->sanitize($input['public_reason'] ?? null, 300),
                'internal_notes' => $this->sanitize($input['internal_notes'] ?? null, 2000),
                'created_by_admin_id' => $actor?->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Efecto sobre el CONTENIDO.
            $this->applyContentEffect($action, $actor, $request);

            // Efecto sobre el MIEMBRO (sanción viva consultable por la app).
            if (ActionType::createsSuspension($actionType) && $fresh->reported_member_id) {
                $this->createSuspension($action, $fresh, $actor);
            }

            // El caso pasa a su estado resuelto. Decidir IMPLICA haber
            // revisado: si el caso venía de `submitted`/`triaged` se camina
            // primero por `under_review` en vez de saltarse el grafo. Así el
            // estado nunca queda en "Nuevo" con una sanción ya aplicada, y
            // `reviewed_at` refleja el momento real de la revisión.
            $target = $actionType === ActionType::DISMISS
                ? ReportStatus::DISMISSED
                : ReportStatus::ACTIONED;

            if (! ReportStatus::canTransition($fresh->status, $target)
                && ReportStatus::canTransition($fresh->status, ReportStatus::UNDER_REVIEW)) {
                $this->applyStatus($fresh, ReportStatus::UNDER_REVIEW, null);
            }

            if (ReportStatus::canTransition($fresh->status, $target)) {
                $this->applyStatus($fresh, $target, null);
            }
            $fresh->forceFill([
                'resolution_code' => ActionType::resolutionFor($actionType),
                'lock_version' => $fresh->lock_version + 1,
            ])->save();

            $this->audit->admin(
                $actor,
                ModerationAuditLog::ACTION_ACTION_APPLIED,
                'moderation_action',
                (int) $action->id,
                null,
                [
                    'report_public_id' => $fresh->public_id,
                    'action_type' => $actionType,
                    'scope' => $scope,
                    'duration_minutes' => $durationMinutes,
                    'permanent' => $endsAt === null && ActionType::createsSuspension($actionType),
                ],
                $request,
            );

            return $action;
        });
    }

    // ── Revocación ────────────────────────────────────────────────────────

    /**
     * Revoca una acción y la suspensión que hubiera creado. Idempotente: si ya
     * estaba revocada, devuelve la acción sin volver a notificar.
     */
    public function revoke(
        ModerationAction $action,
        ?Admin $actor,
        ?string $reason = null,
        ?Request $request = null,
    ): ModerationAction {
        // Revocar exige al menos el permiso que exigía aplicar.
        $this->assertPermission($actor, ActionType::requiredPermission($action->action_type));

        $alreadyRevoked = false;

        /** @var ModerationAction $result */
        $result = DB::transaction(function () use ($action, $actor, $reason, &$alreadyRevoked): ModerationAction {
            /** @var ModerationAction $fresh */
            $fresh = ModerationAction::whereKey($action->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isRevoked()) {
                $alreadyRevoked = true;

                return $fresh;
            }

            $fresh->forceFill([
                'revoked_at' => now(),
                'revoked_by_admin_id' => $actor?->id,
                'revoke_reason' => $this->sanitize($reason, 300),
            ])->save();

            MemberSuspension::query()
                ->where('moderation_action_id', $fresh->id)
                ->where('status', MemberSuspension::STATUS_ACTIVE)
                ->update([
                    'status' => MemberSuspension::STATUS_REVOKED,
                    'revoked_at' => now(),
                    'revoked_by_admin_id' => $actor?->id,
                    'updated_at' => now(),
                ]);

            // Si la acción había ocultado contenido, restaurarlo forma parte de
            // revocar: dejarlo oculto sería mantener media sanción.
            if (in_array($fresh->action_type, [
                ActionType::HIDE_CONTENT,
                ActionType::REMOVE_CONTENT,
            ], true) && $fresh->target_story_id) {
                Story::withTrashed()
                    ->whereKey($fresh->target_story_id)
                    ->update([
                        'moderation_state' => Story::MODERATION_VISIBLE,
                        'moderated_at' => now(),
                    ]);
            }

            return $fresh;
        });

        if ($alreadyRevoked) {
            return $result;
        }

        $this->audit->admin(
            $actor,
            ModerationAuditLog::ACTION_ACTION_REVOKED,
            'moderation_action',
            (int) $result->id,
            null,
            ['action_type' => $result->action_type],
            $request,
        );

        $this->notifier->actionRevoked($result->fresh());

        return $result;
    }

    /** Revoca directamente una suspensión (sin pasar por su acción). */
    public function revokeSuspension(
        MemberSuspension $suspension,
        ?Admin $actor,
        ?string $reason = null,
        ?Request $request = null,
    ): MemberSuspension {
        $required = $suspension->scope === ModerationScope::FULL_APP_ACCESS
            ? ModerationPermission::SUSPEND_FULL_ACCESS
            : ModerationPermission::SUSPEND_SOCIAL;

        $this->assertPermission($actor, $required);

        if ($suspension->action) {
            $this->revoke($suspension->action, $actor, $reason, $request);

            return $suspension->refresh();
        }

        if ($suspension->status !== MemberSuspension::STATUS_ACTIVE) {
            return $suspension;
        }

        $suspension->forceFill([
            'status' => MemberSuspension::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by_admin_id' => $actor?->id,
        ])->save();

        $this->audit->admin(
            $actor,
            ModerationAuditLog::ACTION_ACTION_REVOKED,
            'member_suspension',
            (int) $suspension->id,
            null,
            ['scope' => $suspension->scope],
            $request,
        );

        return $suspension;
    }

    // ── Internos ──────────────────────────────────────────────────────────

    /** Efecto de la acción sobre la Story. Reversible salvo confirmación. */
    private function applyContentEffect(
        ModerationAction $action,
        ?Admin $actor,
        ?Request $request,
    ): void {
        if (! $action->target_story_id) {
            return;
        }

        $state = match ($action->action_type) {
            ActionType::HIDE_CONTENT => Story::MODERATION_QUARANTINED,
            ActionType::REMOVE_CONTENT => Story::MODERATION_REMOVED,
            ActionType::RESTORE_CONTENT => Story::MODERATION_VISIBLE,
            default => null,
        };

        if ($state === null) {
            return;
        }

        // Se actualiza el ESTADO, no se borra la fila ni el binario: mientras
        // el caso viva, la evidencia debe seguir siendo revisable.
        Story::withTrashed()
            ->whereKey($action->target_story_id)
            ->update([
                'moderation_state' => $state,
                'moderated_at' => now(),
                'moderation_reason_code' => $action->report?->reason_code,
            ]);

        $this->audit->admin(
            $actor,
            $state === Story::MODERATION_VISIBLE
                ? ModerationAuditLog::ACTION_CONTENT_RESTORED
                : ModerationAuditLog::ACTION_CONTENT_QUARANTINED,
            'story',
            (int) $action->target_story_id,
            null,
            ['moderation_state' => $state, 'automatic' => false],
            $request,
        );
    }

    /** Materializa la sanción viva que consultará la app. */
    private function createSuspension(
        ModerationAction $action,
        ContentReport $report,
        ?Admin $actor,
    ): MemberSuspension {
        return MemberSuspension::create([
            'member_id' => $report->reported_member_id,
            'scope' => $action->scope,
            'status' => MemberSuspension::STATUS_ACTIVE,
            'starts_at' => $action->starts_at,
            'ends_at' => $action->ends_at,
            'reason_code' => $report->reason_code,
            'public_reason' => $action->reason,
            'internal_reason' => $action->internal_notes,
            'moderation_action_id' => $action->id,
            'created_by_admin_id' => $actor?->id,
        ]);
    }

    /** Marca de tiempo asociada a cada estado. */
    private function applyStatus(ContentReport $report, string $status, ?string $notes): void
    {
        $report->status = $status;

        if ($status === ReportStatus::UNDER_REVIEW && $report->reviewed_at === null) {
            $report->reviewed_at = now();
        }

        if (in_array($status, [
            ReportStatus::ACTIONED,
            ReportStatus::DISMISSED,
            ReportStatus::CLOSED,
        ], true) && $report->resolved_at === null) {
            $report->resolved_at = now();
        }

        if ($notes !== null) {
            $clean = $this->sanitize($notes, 2000);
            $report->moderator_notes = $report->moderator_notes
                ? $report->moderator_notes."\n---\n".$clean
                : $clean;
        }

        $report->lock_version = $report->lock_version + 1;
    }

    private function assertVersion(ContentReport $report, ?int $expected): void
    {
        if ($expected !== null && (int) $report->lock_version !== $expected) {
            throw new RuntimeException('concurrent_modification');
        }
    }

    private function assertPermission(?Admin $actor, string $permission): void
    {
        if (! ModerationPermission::allows($actor, $permission)) {
            throw new RuntimeException('forbidden:'.$permission);
        }
    }

    /** Notas del moderador: se renderizan en el CRM → sin HTML ni scripts. */
    private function sanitize(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(strip_tags($value));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
