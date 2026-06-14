<?php

namespace App\Services\Trainer;

use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\Trainer;

/**
 * Autoridad central de "qué miembros puede tocar un entrenador" (mínimo
 * privilegio). Por ahora el criterio es la asignación vigente
 * (`member_trainer_assignments` con status active). Centralizarlo evita IDOR y
 * checks dispersos; fases posteriores pueden sumar sede o clase.
 */
class TrainerMemberAccess
{
    public function canAccess(Trainer $trainer, Member $member): bool
    {
        if (! $trainer->isActive()) {
            return false;
        }

        return MemberTrainerAssignment::query()
            ->where('trainer_id', $trainer->getKey())
            ->where('member_id', $member->getKey())
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->exists();
    }
}
