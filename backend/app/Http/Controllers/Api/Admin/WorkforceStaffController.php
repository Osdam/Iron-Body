<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\WorkforceException;
use App\Http\Controllers\Controller;
use App\Models\Identity;
use App\Models\StaffProfile;
use App\Models\Trainer;
use App\Models\Workday;
use App\Models\WorkforceAuthorizationCode;
use App\Services\Audit\AuditTrail;
use App\Services\Identity\IdentityLinkService;
use App\Services\Workforce\AuthorizationCodeService;
use App\Services\Workforce\EmployeeDirectory;
use App\Services\Workforce\EmployeeProfile;
use App\Services\Workforce\FaceReferenceService;
use App\Services\Workforce\WorkforceEvents;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use App\Support\Workforce\Positions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Personal (CRM, pestaña «Personal y biometría»). Contrato §7.2.
 *
 * Una persona, una identidad: el alta de personal ENLAZA con la identidad del
 * documento si ya existe (un socio que empieza a trabajar aquí, un entrenador
 * que además atiende recepción), nunca crea otra.
 */
class WorkforceStaffController extends Controller
{
    public function __construct(
        private readonly EmployeeDirectory $directory,
        private readonly FaceReferenceService $faces,
        private readonly IdentityLinkService $identities,
        private readonly AuthorizationCodeService $codes,
        private readonly WorkforceEvents $events,
        private readonly AuditTrail $auditTrail,
    ) {}

    public function positions(): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => Positions::toArray()]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'position' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $search = mb_strtolower(trim((string) ($data['search'] ?? '')));
        $open = Workday::query()->open()->pluck('clock_in_at', 'identity_id');

        $rows = $this->directory->everyone()
            ->filter(fn (EmployeeProfile $p) => $search === '' || str_contains(mb_strtolower($p->fullName), $search)
                || str_contains((string) $p->identity->document_normalized, $search))
            ->filter(fn (EmployeeProfile $p) => empty($data['position']) || in_array($data['position'], $p->positions, true))
            ->filter(fn (EmployeeProfile $p) => empty($data['status']) || ($data['status'] === 'active') === $p->isActive())
            ->sortBy(fn (EmployeeProfile $p) => mb_strtolower($p->fullName))
            ->map(fn (EmployeeProfile $p) => $this->row($p, $open[$p->identity->getKey()] ?? null))
            ->values();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:160'],
            'document' => ['required', 'string', 'min:4', 'max:30'],
            'phone' => ['nullable', 'string', 'max:40'],
            'positions' => ['required', 'array', 'min:1'],
            'positions.*' => ['string', Rule::in(Positions::staffKeys())],
        ]);

        // Fuera de la transacción: ante dos altas a la vez, `ensureIdentity`
        // relee la identidad ganadora, y en PostgreSQL esa relectura falla
        // dentro de una transacción ya abortada por la violación de unicidad.
        $identity = $this->identities->ensureIdentity($data['document'], $data['phone'] ?? null);

        try {
            $profile = DB::transaction(fn (): StaffProfile => $this->createProfile($identity, $data, $request));
        } catch (UniqueConstraintViolationException) {
            // La otra alta simultánea de la misma persona ganó.
            throw WorkforceException::staffProfileExists();
        }

        $person = $this->directory->profile($profile->identity()->firstOrFail());

        return response()->json(['ok' => true, 'data' => $this->row($person, null)], 201);
    }

    /** @param  array<string, mixed>  $data */
    private function createProfile(Identity $identity, array $data, Request $request): StaffProfile
    {
        if (StaffProfile::query()->where('identity_id', $identity->getKey())->exists()) {
            throw WorkforceException::staffProfileExists();
        }

        $profile = StaffProfile::create([
            'identity_id' => $identity->getKey(),
            'full_name' => trim($data['full_name']),
            'phone' => $data['phone'] ?? null,
            'positions' => array_values(array_unique($data['positions'])),
            'status' => StaffProfile::STATUS_ACTIVE,
            'created_by' => AdminActor::id($request),
            'created_by_name' => AdminActor::name($request),
        ]);
        $this->events->publish(WorkforceEvents::STAFF_UPDATED, 'created', (string) $identity->uuid, WorkforceEvents::SOURCE_CRM);
        $this->auditTrail->record($request, [
            'action' => 'create',
            'module' => 'workforce',
            'entity' => 'staff_profile',
            'entity_id' => $profile->uuid,
            'target_name' => $profile->full_name,
            'summary' => 'Dio de alta a una persona del personal',
            'metadata' => ['positions' => $profile->positions],
        ]);

        return $profile;
    }

    public function update(Request $request, string $identity): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'min:3', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'positions' => ['sometimes', 'array', 'min:1'],
            'positions.*' => ['string', Rule::in(Positions::staffKeys())],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
        $person = Identity::query()->where('uuid', $identity)->firstOrFail();
        $profile = StaffProfile::query()->where('identity_id', $person->getKey())->firstOrFail();

        DB::transaction(function () use ($data, $profile, $person, $request): void {
            $changes = array_intersect_key($data, array_flip(['full_name', 'phone', 'positions', 'status']));
            if (isset($changes['positions'])) {
                $changes['positions'] = array_values(array_unique($changes['positions']));
            }
            if (($changes['status'] ?? null) === StaffProfile::STATUS_INACTIVE && $profile->isActive()) {
                $changes['deactivated_at'] = now();
            }
            if (($changes['status'] ?? null) === StaffProfile::STATUS_ACTIVE) {
                $changes['deactivated_at'] = null;
            }
            $before = $profile->getOriginal();
            // El observer revoca y borra la plantilla si la persona ya no
            // conserva ningún perfil activo.
            $profile->fill($changes)->save();

            $reason = ($changes['status'] ?? null) === StaffProfile::STATUS_INACTIVE ? 'deactivated' : 'updated';
            $this->events->publish(WorkforceEvents::STAFF_UPDATED, $reason, (string) $person->uuid, WorkforceEvents::SOURCE_CRM);
            $this->auditTrail->record($request, [
                'action' => isset($changes['status']) ? 'status' : 'update',
                'module' => 'workforce',
                'entity' => 'staff_profile',
                'entity_id' => $profile->uuid,
                'target_name' => $profile->full_name,
                'summary' => 'Editó una persona del personal',
                'changes' => $this->auditTrail->changesOf($profile, $before, ['full_name', 'positions', 'status']),
            ]);
        });

        $open = Workday::query()->open()->where('identity_id', $person->getKey())->value('clock_in_at');

        return response()->json(['ok' => true, 'data' => $this->row($this->directory->profile($person), $open)]);
    }

    /** Código de respaldo para UNA operación sin rostro (la cámara falló). */
    public function fallbackCode(Request $request, string $identity): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'in:workday,class'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        // Un código de clases abre también el portal del entrenador (sus socios):
        // emitirlo es suplantar a un entrenador, así que exige además
        // `trainers.manage`, no solo gestionar personal (revisión 2026-10-11, F5).
        if ($data['purpose'] === 'class' && ! CrmPermission::allows(AdminActor::from($request), 'trainers.manage')) {
            return response()->json([
                'ok' => false,
                'code' => 'forbidden',
                'message' => 'Generar un código de clases exige además el permiso de administrar entrenadores.',
                'required_permission' => 'trainers.manage',
            ], 403);
        }
        $person = Identity::query()->where('uuid', $identity)->firstOrFail();
        if (! $this->directory->profile($person)->isActive()) {
            throw WorkforceException::employeeNotFound();
        }

        $purpose = $data['purpose'] === 'workday' ? WorkforceAuthorizationCode::PURPOSE_FALLBACK_WORKDAY : WorkforceAuthorizationCode::PURPOSE_FALLBACK_CLASS;
        $issued = $this->codes->issue($person, $purpose, $data['reason'], AdminActor::id($request), AdminActor::label($request));

        $this->faces->audit(FaceReferenceService::EVENT_CODE_ISSUED, null, [
            'identity_id' => $person->getKey(),
            'purpose' => $purpose,
            'reason' => $data['reason'],
            'by' => AdminActor::label($request),
        ], $request, AdminActor::id($request));
        $this->auditTrail->record($request, [
            'action' => 'create',
            'module' => 'workforce',
            'entity' => 'authorization_code',
            'entity_id' => (string) $issued['model']->getKey(),
            'summary' => 'Generó un código de respaldo ('.$data['purpose'].')',
            'metadata' => ['purpose' => $purpose, 'identity' => $person->uuid],
        ]);

        return response()->json([
            'ok' => true,
            'code' => $issued['code'],
            'expires_at' => $issued['model']->expires_at->toIso8601String(),
            'purpose' => $data['purpose'],
        ], 201);
    }

    private function row(EmployeeProfile $p, mixed $openSince): array
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
                'enrolled_at' => $ref?->enrolled_at?->toIso8601String(),
                'updated_at' => $ref?->updated_at?->toIso8601String(),
            ],
            // Dato personal: solo para quien puede editar la ficha.
            'phone' => CrmPermission::allows(AdminActor::from(request()), 'workforce.manage')
                ? StaffProfile::query()->where('identity_id', $p->identity->getKey())->value('phone')
                : null,
            'workday' => [
                'open' => $openSince !== null,
                'since' => $openSince !== null ? Carbon::parse($openSince)->toIso8601String() : null,
            ],
        ];
    }
}
