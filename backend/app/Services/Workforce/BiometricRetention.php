<?php

namespace App\Services\Workforce;

use App\Models\StaffProfile;
use App\Models\Trainer;
use App\Models\TrainerFaceReference;
use App\Support\Access\AdminActor;

/**
 * Minimización del dato biométrico: cuando una persona deja de trabajar aquí,
 * su plantilla facial se revoca y se BORRA (decisión del negocio 2026-10-10,
 * Ley 1581: el dato sensible no se conserva sin finalidad). Queda la traza.
 *
 * «Deja de trabajar aquí» = no conserva NINGÚN perfil laboral activo: un
 * entrenador que además es recepcionista y deja de dictar clases sigue siendo
 * empleado y conserva su plantilla.
 */
class BiometricRetention
{
    public function __construct(
        private readonly FaceReferenceService $faces,
        private readonly EmployeeDirectory $directory,
    ) {}

    public function afterTrainerDeactivated(Trainer $trainer): void
    {
        $identity = $trainer->identity_id ? $trainer->identity()->first() : null;
        if ($identity !== null) {
            if ($this->directory->profile($identity)->isActive()) {
                return;
            }
            $ref = $this->faces->referenceForIdentity($identity);
        } else {
            $ref = TrainerFaceReference::query()->where('trainer_id', $trainer->getKey())->first();
        }

        $this->revoke($ref, 'Perfil de entrenador desactivado');
    }

    public function afterStaffDeactivated(StaffProfile $staff): void
    {
        $identity = $staff->identity()->first();
        if ($identity === null || $this->directory->profile($identity)->isActive()) {
            return;
        }

        $this->revoke($this->faces->referenceForIdentity($identity), 'Perfil de personal desactivado');
    }

    private function revoke(?TrainerFaceReference $ref, string $reason): void
    {
        if ($ref === null || ! $ref->isActive()) {
            return;
        }
        $request = request();
        $this->faces->revoke(
            $ref,
            $reason.' (retención de datos biométricos)',
            $request ? AdminActor::id($request) : null,
            ($request ? AdminActor::name($request) : null) ?? 'Sistema',
            WorkforceEvents::SOURCE_SYSTEM,
            $request,
        );
    }
}
