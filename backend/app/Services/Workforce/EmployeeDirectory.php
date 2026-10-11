<?php

namespace App\Services\Workforce;

use App\Models\Identity;
use App\Models\StaffProfile;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\Identity\IdentityLinkService;
use Illuminate\Support\Collection;

/**
 * Quién trabaja aquí. Resuelve una identidad (o un documento) a su
 * {@see EmployeeProfile}: perfiles activos, cargos y capacidades.
 *
 * No crea identidades. Encontrar a alguien por documento IDENTIFICA, no
 * verifica: la verificación es el rostro o un código de administrador.
 */
class EmployeeDirectory
{
    public function __construct(private readonly IdentityLinkService $identities) {}

    public function findByDocument(string $document): ?EmployeeProfile
    {
        $identity = $this->identities->findByDocument($document);
        if ($identity === null) {
            return null;
        }
        $profile = $this->profile($identity);

        return $profile->isActive() ? $profile : null;
    }

    public function profile(Identity $identity): EmployeeProfile
    {
        $trainer = Trainer::query()
            ->where('identity_id', $identity->getKey())
            ->whereIn('status', ['active', 'activo'])
            ->with('roleAssignments')
            ->orderBy('id')
            ->first();

        $staff = StaffProfile::query()
            ->where('identity_id', $identity->getKey())
            ->where('status', StaffProfile::STATUS_ACTIVE)
            ->first();

        $positions = [];
        foreach ($trainer?->roleNames() ?? [] as $role) {
            if (in_array($role, TrainerRole::ALL, true)) {
                $positions[] = $role;
            }
        }
        foreach ($staff?->positionKeys() ?? [] as $key) {
            $positions[] = $key;
        }

        $name = $staff?->full_name ?: ($trainer?->full_name ?: $this->anyName($identity));

        return new EmployeeProfile(
            $identity,
            (string) $name,
            $this->ordered(array_values(array_unique($positions))),
            $trainer,
            $staff,
        );
    }

    /** El perfil de quien tiene esta ficha de entrenador (aunque no tenga identidad). */
    public function forTrainer(Trainer $trainer): ?EmployeeProfile
    {
        $identity = $trainer->identity_id ? Identity::find($trainer->identity_id) : null;

        return $identity ? $this->profile($identity) : null;
    }

    /**
     * Todas las personas con algún perfil laboral (activo o no), para el CRM.
     *
     * @return Collection<int, EmployeeProfile>
     */
    public function everyone(bool $onlyActive = false): Collection
    {
        $ids = Trainer::query()->whereNotNull('identity_id')->pluck('identity_id')
            ->merge(StaffProfile::query()->pluck('identity_id'))
            ->unique()
            ->values();

        return Identity::query()->whereIn('id', $ids)->get()
            ->map(fn (Identity $i) => $this->profile($i))
            ->filter(fn (EmployeeProfile $p) => ! $onlyActive || $p->isActive())
            ->values();
    }

    /**
     * Personas activas que pueden hacer algo en el kiosko (jornada o clases).
     *
     * @return Collection<int, EmployeeProfile>
     */
    public function roster(): Collection
    {
        return $this->everyone(onlyActive: true)
            ->filter(fn (EmployeeProfile $p) => $p->registersWorkday() || $p->operatesClasses())
            ->sortBy(fn (EmployeeProfile $p) => mb_strtolower($p->fullName))
            ->values();
    }

    /** Nombre aunque el perfil esté inactivo (para historiales). */
    private function anyName(Identity $identity): string
    {
        return (string) (StaffProfile::query()->where('identity_id', $identity->getKey())->value('full_name')
            ?? Trainer::query()->where('identity_id', $identity->getKey())->value('full_name')
            ?? '');
    }

    /**
     * Orden del catálogo, no de llegada.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function ordered(array $keys): array
    {
        $catalog = array_keys((array) config('workforce.positions', []));

        return array_values(array_filter($catalog, fn (string $k) => in_array($k, $keys, true)));
    }
}
