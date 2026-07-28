<?php

namespace App\Services\Moderation;

use App\Models\Admin;
use App\Models\Member;
use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\ModerationAuditLog;
use App\Support\Moderation\ModerationPermission;
use App\Support\Moderation\ReportStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Apelaciones de los miembros sancionados.
 *
 * Reglas duras:
 *  - Solo el miembro OBJETIVO de la acción puede apelarla (anti-IDOR: la
 *    acción se resuelve por su `public_id` + pertenencia, nunca por id crudo).
 *  - Una apelación abierta por acción (verificado con lock, no con una
 *    consulta optimista).
 *  - Límite diario para evitar spam.
 *  - Resolver una apelación a favor REVOCA la sanción de verdad; no basta con
 *    cambiar una etiqueta.
 */
class AppealService
{
    public function __construct(
        private ModerationAudit $audit,
        private ModerationNotifier $notifier,
        private ModerationDecisionService $decisions,
    ) {}

    /**
     * El miembro apela una acción.
     *
     * @throws RuntimeException `appeals_disabled`, `action_not_found`,
     *                          `not_appealable`, `appeal_already_open`, `rate_limited`.
     */
    public function submit(
        Member $member,
        string $actionPublicId,
        string $text,
        ?Request $request = null,
    ): ModerationAppeal {
        if (! config('ugc.appeals_enabled', true)) {
            throw new RuntimeException('appeals_disabled');
        }

        // Pertenencia comprobada en la propia consulta: un `public_id` ajeno
        // simplemente no existe para este miembro.
        $action = ModerationAction::query()
            ->where('public_id', $actionPublicId)
            ->where('target_member_id', $member->id)
            ->first();

        if (! $action) {
            throw new RuntimeException('action_not_found');
        }

        if (! $action->isAppealable()) {
            throw new RuntimeException('not_appealable');
        }

        $this->assertWithinRateLimit((int) $member->id);

        $clean = $this->sanitizeAppealText($text);
        if ($clean === null) {
            throw new RuntimeException('empty_appeal');
        }

        /** @var ModerationAppeal $appeal */
        $appeal = DB::transaction(function () use ($action, $member, $clean): ModerationAppeal {
            // Lock sobre TODAS las apelaciones de la acción: dos peticiones
            // simultáneas no pueden crear dos apelaciones abiertas.
            $previous = ModerationAppeal::query()
                ->where('moderation_action_id', $action->id)
                ->lockForUpdate()
                ->get();

            if ($previous->contains(fn (ModerationAppeal $a) => $a->isOpen())) {
                throw new RuntimeException('appeal_already_open');
            }

            // Una medida ya apelada y resuelta no se vuelve a apelar: sin esto,
            // el límite diario permitiría reabrir el mismo caso en bucle.
            if ($previous->contains(
                fn (ModerationAppeal $a) => in_array(
                    $a->status,
                    ModerationAppeal::resolvedStatuses(),
                    true,
                )
            )) {
                throw new RuntimeException('appeal_already_resolved');
            }

            $appeal = ModerationAppeal::create([
                'moderation_action_id' => $action->id,
                'member_id' => $member->id,
                'appeal_text' => $clean,
                'status' => ModerationAppeal::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);

            // El caso asociado pasa a `appealed` si la máquina lo permite.
            $report = $action->report;
            if ($report && ReportStatus::canTransition($report->status, ReportStatus::APPEALED)) {
                $report->forceFill([
                    'status' => ReportStatus::APPEALED,
                    'lock_version' => $report->lock_version + 1,
                ])->save();
            }

            return $appeal;
        });

        $this->audit->member(
            (int) $member->id,
            ModerationAuditLog::ACTION_APPEAL_SUBMITTED,
            'moderation_appeal',
            (int) $appeal->id,
            [
                'action_public_id' => $action->public_id,
                'appeal_public_id' => $appeal->public_id,
            ],
            $request,
        );

        $this->notifier->appealReceived($appeal);

        return $appeal;
    }

    /**
     * Un moderador resuelve la apelación.
     *
     * `granted` revoca la sanción realmente (delegando en
     * {@see ModerationDecisionService::revoke()}), lo que además notifica al
     * miembro y restaura el contenido si procedía.
     */
    public function resolve(
        ModerationAppeal $appeal,
        ?Admin $actor,
        string $status,
        ?string $internalNotes = null,
        ?string $publicResolution = null,
        ?Request $request = null,
    ): ModerationAppeal {
        if (! ModerationPermission::allows($actor, ModerationPermission::RESOLVE_APPEALS)) {
            throw new RuntimeException('forbidden:'.ModerationPermission::RESOLVE_APPEALS);
        }

        $allowed = [
            ModerationAppeal::STATUS_UNDER_REVIEW,
            ModerationAppeal::STATUS_UPHELD,
            ModerationAppeal::STATUS_GRANTED,
            ModerationAppeal::STATUS_REJECTED,
        ];

        if (! in_array($status, $allowed, true)) {
            throw new RuntimeException('invalid_status');
        }

        $before = $appeal->status;

        /** @var ModerationAppeal $fresh */
        $fresh = DB::transaction(function () use (
            $appeal,
            $actor,
            $status,
            $internalNotes,
            $publicResolution
        ): ModerationAppeal {
            /** @var ModerationAppeal $locked */
            $locked = ModerationAppeal::whereKey($appeal->id)->lockForUpdate()->firstOrFail();

            // Doble resolución concurrente: la segunda no vuelve a aplicar nada.
            if (! $locked->isOpen()) {
                throw new RuntimeException('appeal_already_resolved');
            }

            $locked->forceFill([
                'status' => $status,
                'reviewed_by_admin_id' => $actor?->id,
                'resolution_notes' => $this->sanitize($internalNotes, 2000),
                'public_resolution' => $this->sanitize($publicResolution, 300),
                'resolved_at' => in_array($status, [
                    ModerationAppeal::STATUS_UPHELD,
                    ModerationAppeal::STATUS_GRANTED,
                    ModerationAppeal::STATUS_REJECTED,
                ], true) ? now() : null,
            ])->save();

            return $locked;
        });

        // Dar la razón al miembro debe tener efecto REAL sobre su cuenta.
        if ($status === ModerationAppeal::STATUS_GRANTED && $fresh->action) {
            $this->decisions->revoke(
                $fresh->action,
                $actor,
                'Apelación aceptada',
                $request,
            );
        }

        // Cierre del caso cuando la apelación termina.
        $report = $fresh->action?->report;
        if ($report && in_array($status, [
            ModerationAppeal::STATUS_UPHELD,
            ModerationAppeal::STATUS_GRANTED,
            ModerationAppeal::STATUS_REJECTED,
        ], true) && ReportStatus::canTransition($report->status, ReportStatus::CLOSED)) {
            $report->forceFill([
                'status' => ReportStatus::CLOSED,
                'resolved_at' => $report->resolved_at ?? now(),
                'lock_version' => $report->lock_version + 1,
            ])->save();
        }

        $this->audit->admin(
            $actor,
            ModerationAuditLog::ACTION_APPEAL_RESOLVED,
            'moderation_appeal',
            (int) $fresh->id,
            ['status' => $before],
            ['status' => $status],
            $request,
        );

        if ($status !== ModerationAppeal::STATUS_UNDER_REVIEW) {
            $this->notifier->appealResolved($fresh->fresh());
        }

        return $fresh;
    }

    /** Apelaciones del miembro (para su pantalla de estado de cuenta). */
    public function listForMember(Member $member)
    {
        return ModerationAppeal::query()
            ->where('member_id', $member->id)
            ->with('action')
            ->orderByDesc('submitted_at')
            ->get();
    }

    private function assertWithinRateLimit(int $memberId): void
    {
        $limit = (int) config('ugc.appeal_rate_limit_per_day', 5);
        if ($limit <= 0) {
            return;
        }

        $recent = ModerationAppeal::query()
            ->where('member_id', $memberId)
            ->where('submitted_at', '>=', now()->subDay())
            ->count();

        if ($recent >= $limit) {
            throw new RuntimeException('rate_limited');
        }
    }

    private function sanitizeAppealText(string $text): ?string
    {
        return $this->sanitize($text, (int) config('ugc.appeal_text_max_length', 1000));
    }

    private function sanitize(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(strip_tags($value));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
