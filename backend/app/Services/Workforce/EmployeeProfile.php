<?php

namespace App\Services\Workforce;

use App\Models\Identity;
use App\Models\StaffProfile;
use App\Models\Trainer;
use App\Support\Workforce\Positions;

/**
 * Lo que el sistema sabe de una persona que trabaja aquí, en una foto: su
 * identidad canónica, sus perfiles ACTIVOS y lo que eso le permite.
 *
 * Una sola persona, aunque tenga varios cargos: los cargos son la unión de sus
 * roles de entrenador y los de su perfil de personal.
 */
final class EmployeeProfile
{
    /**
     * @param  list<string>  $positions
     */
    public function __construct(
        public readonly Identity $identity,
        public readonly string $fullName,
        public readonly array $positions,
        public readonly ?Trainer $trainer,
        public readonly ?StaffProfile $staff,
    ) {}

    public function isActive(): bool
    {
        return $this->trainer !== null || $this->staff !== null;
    }

    public function registersWorkday(): bool
    {
        return Positions::workdayPosition($this->positions) !== null;
    }

    /** Opera clases: entrenador activo con permiso de asistencia (rol funcional). */
    public function operatesClasses(): bool
    {
        return $this->trainer !== null && $this->trainer->hasPermission('attendance.create');
    }

    /** Pantalla compartida: nombre y la inicial del apellido. */
    public function shortName(): string
    {
        $parts = preg_split('/\s+/', trim($this->fullName)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1).'.' : '';

        return trim($first.' '.$last);
    }

    /** @return array{workday: bool, classes: bool} */
    public function capabilities(): array
    {
        return ['workday' => $this->registersWorkday(), 'classes' => $this->operatesClasses()];
    }

    /** @return list<array{key:string, label:string, source:?string}> */
    public function describedPositions(): array
    {
        return Positions::describe($this->positions);
    }
}
