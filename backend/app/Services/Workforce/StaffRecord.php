<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\EmployeeAccess;
use App\Models\Identity;
use App\Models\Member;
use App\Models\StaffProfile;
use App\Models\Trainer;
use App\Models\User;
use App\Models\Workday;
use App\Services\Identity\IdentityLinkService;
use App\Services\Identity\MemberOwnershipLink;
use App\Services\Membership\MembershipAccess;
use App\Support\Workforce\Positions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * La ficha laboral de UNA persona (CRM, Personal y biometría). Contrato §7.2.
 *
 * Reúne lo que hasta ahora estaba repartido en pantallas distintas: la fila
 * del listado, sus perfiles (personal y entrenador) en cualquier estado, su
 * jornada y —solo con `members.view`— su ficha de socio: membresía pagada,
 * acceso gratuito y Story Live. Son tres cosas distintas y la ficha las da
 * separadas: el cargo laboral no da permisos en el CRM, el acceso gratuito no
 * es una membresía y la membresía no la toca nadie desde aquí.
 *
 * Solo lee. No crea identidades ni vincula nada (eso es {@see MemberOwnershipLink}).
 * Los permisos los decide el controlador y llegan aquí como booleanos.
 */
class StaffRecord
{
    public function __construct(
        private readonly EmployeeDirectory $directory,
        private readonly FaceReferenceService $faces,
        private readonly WorkdayService $workdays,
        private readonly IdentityLinkService $identities,
        private readonly MemberOwnershipLink $links,
        private readonly MembershipAccess $access,
    ) {}

    /**
     * La identidad de alguien del personal: con perfil de personal o de
     * entrenador, en cualquier estado. Una identidad que solo es de un socio
     * responde igual que una que no existe: la ficha no sirve para recorrer
     * identidades de socios.
     */
    public function resolve(string $uuid): Identity
    {
        $identity = Identity::query()->where('uuid', $uuid)->first();

        if ($identity === null
            || (! StaffProfile::query()->where('identity_id', $identity->getKey())->exists()
                && ! Trainer::query()->where('identity_id', $identity->getKey())->exists())) {
            throw WorkforceException::staffRecordNotFound();
        }

        return $identity;
    }

    /** La fila del listado más los bloques de la ficha. */
    public function record(Identity $identity, bool $withPhone, bool $withMember): array
    {
        $person = $this->directory->profile($identity);
        $open = Workday::query()->open()->where('identity_id', $identity->getKey())->value('clock_in_at');
        $staff = StaffProfile::query()->where('identity_id', $identity->getKey())->first();
        $trainer = $this->trainerOf($identity, $person);

        return $this->row($person, $open, $withPhone) + [
            'staff' => $staff ? [
                'status' => $staff->status,
                'created_at' => $staff->created_at?->toIso8601String(),
                'created_by_name' => $staff->created_by_name,
                'deactivated_at' => $staff->deactivated_at?->toIso8601String(),
            ] : null,
            'trainer' => $trainer ? [
                'id' => $trainer->id,
                'status' => $this->trainerStatus($trainer),
                'positions' => $this->trainerPositions($trainer),
            ] : null,
            'workday_state' => $this->workdays->state($identity),
            'member' => $withMember ? $this->memberBlock($identity) : null,
        ];
    }

    /**
     * Comprobación previa del alta: ¿esta persona ya existe? NO crea identidades
     * ni vincula nada; responde con lo que hay.
     */
    public function lookup(string $document, bool $withMember): array
    {
        $identity = $this->identities->findByDocument($document);
        $staff = $identity ? StaffProfile::query()->where('identity_id', $identity->getKey())->first() : null;
        $trainer = $identity ? $this->trainerOf($identity, null) : null;

        $member = null;
        if ($withMember) {
            $socio = $identity !== null
                ? $this->links->situation($identity)['member']
                : $this->identities->memberForDocument($document);
            $member = $socio ? [
                'match' => true,
                'linked' => $identity !== null && (int) $socio->identity_id === (int) $identity->getKey(),
                'full_name' => $socio->full_name,
            ] : null;
        }

        // Una identidad sin perfil de personal ni de entrenador solo existe por
        // ser de un socio: decir que existe es decir que el documento es de un
        // socio, y eso es para quien puede ver socios (revisión 2026-10-15, B4).
        // Sin ese permiso se responde como a una persona nueva; el alta sin
        // `identity_id` sigue enlazando por documento como siempre.
        $visible = $identity !== null && ($staff !== null || $trainer !== null || $withMember);

        return [
            'identity_id' => $visible ? $identity->uuid : null,
            'full_name' => ($staff?->full_name ?: $trainer?->full_name) ?: null,
            'staff_profile' => $staff ? ['status' => $staff->status] : null,
            'trainer' => $trainer ? ['status' => $this->trainerStatus($trainer), 'positions' => $this->trainerPositions($trainer)] : null,
            'member' => $member,
        ];
    }

    /**
     * La fila de `GET admin/workforce/staff`. Vive aquí y no en el controlador
     * para que el listado y la ficha no puedan decir cosas distintas.
     */
    public function row(EmployeeProfile $p, mixed $openSince, bool $withPhone): array
    {
        $ref = $this->faces->referenceForIdentity($p->identity);
        $doc = (string) $p->identity->document_normalized;

        return [
            'identity_id' => $p->identity->uuid,
            'full_name' => $p->fullName,
            'document_masked' => $doc !== '' ? '•••• '.substr($doc, -4) : null,
            'positions' => $p->describedPositions(),
            'status' => $p->isActive() ? 'active' : 'inactive',
            'trainer_id' => $p->trainer?->id ?? Trainer::query()->where('identity_id', $p->identity->getKey())->value('id'),
            'staff_profile' => StaffProfile::query()->where('identity_id', $p->identity->getKey())->exists(),
            'capabilities' => $p->capabilities(),
            'biometric' => [
                'status' => $this->faces->status($ref),
                'verifiable' => $this->faces->verifiable($ref),
                'enrolled_at' => $ref?->enrolled_at?->toIso8601String(),
                'updated_at' => $ref?->updated_at?->toIso8601String(),
            ],
            // Dato personal: solo para quien puede editar la ficha.
            'phone' => $withPhone
                ? StaffProfile::query()->where('identity_id', $p->identity->getKey())->value('phone')
                : null,
            'workday' => [
                'open' => $openSince !== null,
                'since' => $openSince !== null ? Carbon::parse($openSince)->toIso8601String() : null,
            ],
        ];
    }

    /**
     * La ficha de socio. Membresía, Story Live y acceso gratuito SOLO si está
     * vinculada: coincidir por documento no prueba que sea de esta persona, y
     * enseñar el acceso de una ficha ajena sería darlo por suyo.
     */
    private function memberBlock(Identity $identity): array
    {
        $situation = $this->links->situation($identity);
        /** @var Member|null $member */
        $member = $situation['member'];
        $linked = $situation['link'] === MemberOwnershipLink::LINKED;
        $user = $linked ? $member?->user : null;

        return [
            'link' => $situation['link'],
            'link_blocked_reason' => $situation['reason'],
            'member_id' => $member?->id,
            'user_id' => $member?->user_id,
            'full_name' => $member?->full_name,
            // El teléfono PROPIO de la ficha de socio: el único al que iría el
            // código (el del usuario del CRM no prueba titularidad).
            'phone_masked' => $member ? MemberOwnershipLink::maskPhone($this->links->verifiablePhone($member)) : null,
            'can_verify' => $situation['link'] === MemberOwnershipLink::UNLINKED && $situation['reason'] === null,
            'membership' => $user ? ['plan' => $user->plan, 'end_date' => $user->membership_end_date] : null,
            'story_live' => $linked ? (bool) $member?->is_staff : null,
            'employee_access' => $user ? $this->employeeAccess($user) : null,
        ];
    }

    /**
     * Lo mismo que `access.employee` de `GET users/{user}/employee-access`, más
     * de dónde sale: a mano o abierto por un piso de comisiones pagado.
     */
    private function employeeAccess(User $user): ?array
    {
        $row = EmployeeAccess::query()->where('user_id', $user->id)->first();
        if ($row === null) {
            return null;
        }
        $state = $this->access->state($user)['employee'] ?? null;

        return $state === null ? null : ['source' => $row->source] + $state;
    }

    /** El perfil de entrenador: el activo si lo hay, si no el primero. */
    private function trainerOf(Identity $identity, ?EmployeeProfile $person): ?Trainer
    {
        return $person?->trainer
            ?? Trainer::query()->where('identity_id', $identity->getKey())->with('roleAssignments')->orderBy('id')->first();
    }

    /** `activo` es la forma histórica de `active`. */
    private function trainerStatus(Trainer $trainer): string
    {
        return in_array(Str::lower((string) $trainer->status), ['active', 'activo'], true) ? 'active' : 'inactive';
    }

    /** @return list<array{key:string, label:string}> */
    private function trainerPositions(Trainer $trainer): array
    {
        return array_map(
            fn (array $p): array => ['key' => $p['key'], 'label' => $p['label']],
            Positions::describe($trainer->roleNames()),
        );
    }
}
