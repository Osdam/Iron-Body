<?php

namespace App\Services\Trainer;

use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Services\NotificationService;

/**
 * Asignación entrenador ↔ miembro (reutiliza `member_trainer_assignments`). Un
 * miembro tiene UN entrenador vigente a la vez: asignar a un entrenador cierra
 * la asignación activa previa del miembro. Idempotente (no duplica), notifica al
 * miembro y deja auditoría profesional. Es el backend la autoridad; la app solo
 * consume los asignados vía `/trainer/members`.
 */
class MemberAssignmentService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TrainerAuditService $audit,
    ) {}

    /**
     * Asigna el miembro al entrenador. Si ya está activo con ESTE entrenador,
     * no hace nada (anti-duplicado). Devuelve true si creó una asignación nueva.
     */
    public function assign(Trainer $trainer, Member $member, ?string $assignedBy = null): bool
    {
        $alreadyActive = MemberTrainerAssignment::query()
            ->where('member_id', $member->getKey())
            ->where('trainer_id', $trainer->getKey())
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->exists();

        if ($alreadyActive) {
            return false;
        }

        // Cierra cualquier asignación activa previa del miembro (un entrenador
        // vigente a la vez); el histórico queda como inactive + ended_at.
        MemberTrainerAssignment::query()
            ->where('member_id', $member->getKey())
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->update(['status' => MemberTrainerAssignment::STATUS_INACTIVE, 'ended_at' => now()]);

        MemberTrainerAssignment::create([
            'member_id' => $member->getKey(),
            'trainer_id' => $trainer->getKey(),
            'assigned_by' => $assignedBy,
            'status' => MemberTrainerAssignment::STATUS_ACTIVE,
            'assigned_at' => now(),
        ]);

        $this->notifications->notifyTrainerAssigned($member, $trainer);
        $this->audit->record(
            TrainerAuditLog::EVENT_MEMBER_ASSIGNED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_ADMIN,
            metadata: ['member_id' => $member->getKey(), 'source' => 'crm_blade'],
        );

        return true;
    }

    /**
     * Quita al miembro de ESTE entrenador (cierra su asignación activa). Conserva
     * el histórico. Devuelve true si había una asignación activa que cerrar.
     */
    public function unassign(Trainer $trainer, Member $member): bool
    {
        $active = MemberTrainerAssignment::query()
            ->where('member_id', $member->getKey())
            ->where('trainer_id', $trainer->getKey())
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->latest()
            ->first();

        if ($active === null) {
            return false;
        }

        $active->update(['status' => MemberTrainerAssignment::STATUS_INACTIVE, 'ended_at' => now()]);

        $this->notifications->notifyTrainerUnassigned($member, $trainer);
        $this->audit->record(
            TrainerAuditLog::EVENT_MEMBER_UNASSIGNED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_ADMIN,
            metadata: ['member_id' => $member->getKey(), 'source' => 'crm_blade'],
        );

        return true;
    }

    /** Miembros actualmente asignados (activos) al entrenador. */
    public function assignedMembers(Trainer $trainer)
    {
        return Member::query()
            ->whereIn('id', MemberTrainerAssignment::query()
                ->where('trainer_id', $trainer->getKey())
                ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
                ->pluck('member_id'))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'document_number', 'phone']);
    }

    /**
     * Busca miembros ACTIVOS por nombre, documento o teléfono, excluyendo los ya
     * asignados a este entrenador. Para el buscador del CRM.
     */
    public function searchAssignable(Trainer $trainer, string $term, int $limit = 10)
    {
        $term = trim($term);
        if ($term === '') {
            return collect();
        }

        $alreadyAssigned = MemberTrainerAssignment::query()
            ->where('trainer_id', $trainer->getKey())
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->pluck('member_id');

        $like = '%'.$term.'%';

        return Member::query()
            ->where('status', Member::STATUS_ACTIVE)
            ->whereNotIn('id', $alreadyAssigned)
            ->where(function ($q) use ($like) {
                $q->where('full_name', 'like', $like)
                    ->orWhere('document_number', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            })
            ->orderBy('full_name')
            ->limit($limit)
            ->get(['id', 'full_name', 'document_number', 'phone']);
    }
}
