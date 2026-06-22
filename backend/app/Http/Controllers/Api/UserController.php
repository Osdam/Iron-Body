<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    /** Mayoría de edad (años): por debajo se exige acudiente. Espejo de la app. */
    private const LEGAL_ADULT_AGE = 18;

    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('status') && Schema::hasColumn('users', 'status')) {
            $query->where('status', $request->input('status'));
        }

        $page = $query
            ->select($this->memberFields())
            ->with('appMember.guardian')
            ->orderByDesc('created_at')
            ->paginate(20);

        // Adjunta menor de edad + acudiente por fila (para prefijar el editar)
        // sin exponer la relación cruda del miembro.
        $page->getCollection()->transform(function (User $u): User {
            $member = $u->appMember;
            $u->setAttribute('isMinor', (bool) ($member?->is_minor));
            $u->setAttribute('guardian', $this->guardianArray($member?->guardian));
            $u->unsetRelation('appMember');

            return $u;
        });

        return $page;
    }

    public function show(User $user)
    {
        return array_merge(
            $user->only(array_merge($this->memberFields(), ['membershipStartDate', 'membershipEndDate'])),
            ['features' => $this->featuresFor($user)]
        );
    }

    /** GET /api/users/{user}/plan-features */
    public function planFeatures(User $user)
    {
        $plan = $user->plan ? Plan::where('name', $user->plan)->first() : null;

        $expiresAt = $user->membershipEndDate
            ? Carbon::parse($user->membershipEndDate)->endOfDay()
            : null;
        $isExpired = $expiresAt && $expiresAt->isPast();

        $features = ($isExpired || ! $plan)
            ? array_merge(array_map(fn () => false, Plan::defaultFeatures()), ['workouts' => true])
            : $plan->resolvedFeatures();

        return response()->json([
            'userId'    => (string) $user->id,
            'planId'    => $plan ? (string) $plan->id : null,
            'planName'  => $plan ? $plan->name : $user->plan,
            'features'  => $features,
            'expiresAt' => $expiresAt?->toIso8601String(),
        ]);
    }

    /**
     * Crea un usuario/miembro desde el CRM. SOLO identidad y datos personales:
     * el plan y la membresía NO se fijan aquí, se otorgan exclusivamente con
     * pagos (así la app y el historial quedan sincronizados con una sola fuente).
     *
     * Crea TAMBIÉN el registro `Member` vinculado: la app inicia sesión por
     * `members.document_number`, así que sin el Member el login respondía
     * "Documento no encontrado". El documento se normaliza igual que en el login.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'fullName' => 'required|string|max:255',
            'document' => 'required|string|max:50',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'birthDate' => 'nullable|date',
            'gender' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            'emergencyContact' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $document = Member::normalizeDocumentNumber($validated['document']);

        // El documento es la llave de acceso del miembro (único en members).
        if ($document === null) {
            return response()->json(['message' => 'El documento no es válido.'], 422);
        }
        if (Member::where('document_number', $document)->exists()
            || User::where('document', $document)->exists()) {
            return response()->json([
                'message' => 'Ya existe un miembro registrado con ese documento.',
            ], 422);
        }

        // Edad / menor de edad (igual que la app): se calcula en el servidor.
        $age = $this->ageFromBirthDate($validated['birthDate'] ?? null);
        if ($age !== null && $age < Member::MIN_REGISTRATION_AGE) {
            return response()->json([
                'message' => 'El registro no está disponible para menores de '
                    . Member::MIN_REGISTRATION_AGE . ' años.',
            ], 422);
        }
        $isMinor = $age !== null && $age < self::LEGAL_ADULT_AGE;

        // Si es menor, el acudiente (nombre + documento) es OBLIGATORIO.
        $guardian = $request->validate([
            'guardianFullName'     => [$isMinor ? 'required' : 'nullable', 'string', 'max:255'],
            'guardianDocument'     => [$isMinor ? 'required' : 'nullable', 'string', 'max:50'],
            'guardianPhone'        => ['nullable', 'string', 'max:30'],
            'guardianEmail'        => ['nullable', 'email', 'max:255'],
            'guardianRelationship' => ['nullable', 'string', 'max:80'],
            'guardianAccepts'      => ['sometimes', 'boolean'],
        ], [
            'guardianFullName.required' => 'El nombre del acudiente es obligatorio para menores de edad.',
            'guardianDocument.required' => 'El documento del acudiente es obligatorio para menores de edad.',
        ]);

        $user = DB::transaction(function () use ($validated, $guardian, $document, $isMinor): User {
            $user = User::create([
                'name' => $validated['fullName'],
                'email' => $validated['email'] ?? 'user-' . time() . '-' . mt_rand(1000, 9999) . '@ironbody.local',
                'password' => bcrypt('default-password'),
                'document' => $document,
                'phone' => $validated['phone'],
                'birth_date' => $validated['birthDate'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'address' => $validated['address'] ?? null,
                'emergency_contact' => $validated['emergencyContact'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'active',
                // plan / membresía NO se fijan al crear: se otorgan con pagos.
            ]);

            // Member vinculado para que la app reconozca al miembro por documento.
            $member = Member::create([
                'user_id' => $user->id,
                'full_name' => $validated['fullName'],
                'email' => $validated['email'] ?? null,
                'document_number' => $document,
                'phone' => $validated['phone'],
                'gender' => $validated['gender'] ?? null,
                'birth_date' => $validated['birthDate'] ?? null,
                'is_minor' => $isMinor,
                'status' => Member::STATUS_ACTIVE,
            ]);

            // Acudiente: igual que en la app, se guarda si es menor o si se
            // diligenció el nombre del responsable.
            $this->syncGuardian($member, $guardian);

            return $user;
        });

        // Auditoría: miembro creado desde el CRM (ADITIVO).
        app(\App\Services\NotificationService::class)
            ->notifyMemberCreated($user, $user->name, $user->document);

        return response()->json($this->serialize($user), 201);
    }

    /**
     * Actualiza identidad / datos personales del miembro. El plan y la membresía
     * NO se tocan aquí (se gestionan con pagos); el estado CRM (active/inactive)
     * sí, porque es una bandera de gestión, no la membresía vigente de la app.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'document' => 'sometimes|nullable|string|max:50',
            'phone' => 'sometimes|nullable|string|max:20',
            'birthDate' => 'sometimes|nullable|date',
            'gender' => 'sometimes|nullable|string|max:30',
            'address' => 'sometimes|nullable|string|max:255',
            'emergencyContact' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:2000',
            'status' => 'sometimes|nullable|string|in:active,inactive,pending,expired',
            'guardianFullName' => 'sometimes|nullable|string|max:255',
            'guardianDocument' => 'sometimes|nullable|string|max:50',
            'guardianPhone' => 'sometimes|nullable|string|max:30',
            'guardianEmail' => 'sometimes|nullable|email|max:255',
            'guardianRelationship' => 'sometimes|nullable|string|max:80',
            'guardianAccepts' => 'sometimes|boolean',
        ]);

        // Estado anterior para detectar cambios reales (notificaciones).
        $originalStatus = $user->status;

        foreach (['name', 'email', 'document', 'phone', 'status'] as $field) {
            if (array_key_exists($field, $validated)) {
                $user->{$field} = $validated[$field];
            }
        }

        // Datos personales (camelCase del CRM → columnas snake_case).
        $personal = [
            'birthDate' => 'birth_date',
            'gender' => 'gender',
            'address' => 'address',
            'emergencyContact' => 'emergency_contact',
            'notes' => 'notes',
        ];
        foreach ($personal as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $user->{$column} = $validated[$input];
            }
        }

        $user->save();

        if ($user->appMember) {
            $memberUpdates = [];

            if (array_key_exists('name', $validated)) {
                $memberUpdates['full_name'] = $validated['name'];
            }
            if (array_key_exists('email', $validated)) {
                $memberUpdates['email'] = $validated['email'];
            }
            if (array_key_exists('document', $validated) && filled($validated['document'])) {
                $memberUpdates['document_number'] = Member::normalizeDocumentNumber($validated['document']);
            }
            if (array_key_exists('phone', $validated)) {
                $memberUpdates['phone'] = $validated['phone'];
            }
            // Datos personales que la app también usa (Member es su fuente).
            if (array_key_exists('gender', $validated)) {
                $memberUpdates['gender'] = $validated['gender'];
            }
            if (array_key_exists('birthDate', $validated)) {
                $memberUpdates['birth_date'] = $validated['birthDate'];
                // Recalcula menor de edad (igual que la app) desde la fecha.
                $age = $this->ageFromBirthDate($validated['birthDate']);
                $memberUpdates['is_minor'] = $age !== null && $age < self::LEGAL_ADULT_AGE;
            }
            if (($validated['status'] ?? null) === 'active') {
                $memberUpdates['status'] = Member::STATUS_ACTIVE;
            }

            if ($memberUpdates !== []) {
                $user->appMember()->update($memberUpdates);
            }

            // Acudiente (formato de menores): si el CRM mandó datos del
            // responsable, se crean/actualizan.
            $guardianKeys = [
                'guardianFullName', 'guardianDocument', 'guardianPhone',
                'guardianEmail', 'guardianRelationship', 'guardianAccepts',
            ];
            if (collect($guardianKeys)->contains(fn ($k) => array_key_exists($k, $validated))) {
                $this->syncGuardian($user->appMember, $validated);
            }

            $notifier = app(\App\Services\NotificationService::class);

            // Si el estado pasó a inactivo/vencido, notifica membresía cancelada.
            $newStatus = $validated['status'] ?? null;
            if ($newStatus !== null
                && $newStatus !== $originalStatus
                && in_array($newStatus, ['inactive', 'expired'], true)) {
                $notifier->notifyMembershipCancelled($user->appMember, $user->plan);
            }

            // Auditoría de actualización de miembro para el CRM.
            $notifier->notifyMemberUpdated($user->appMember, $user->name);
        }

        return response()->json($this->serialize($user));
    }

    public function destroy(User $user)
    {
        // Auditoría: miembro eliminado (ANTES de borrar, conserva nombre/doc).
        app(\App\Services\NotificationService::class)
            ->notifyMemberDeleted($user, $user->name, $user->document);

        if ($user->appMember) {
            $user->appMember->deleteStoredFiles();
            $user->appMember->delete();
        }

        $user->delete();
        return response()->json(null, 204);
    }

    /**
     * Columns selected for member listings.
     */
    private function memberFields(): array
    {
        return [
            'id',
            'name',
            'email',
            'document',
            'phone',
            'birth_date',
            'gender',
            'address',
            'emergency_contact',
            'notes',
            'status',
            'plan',
            'membership_start_date',
            'membership_end_date',
            'created_at',
        ];
    }

    /**
     * Serialize a user using the camelCase membership keys.
     */
    private function serialize(User $user): array
    {
        $user->loadMissing('appMember.guardian');
        $member = $user->appMember;

        return [
            'id'                  => $user->id,
            'name'                => $user->name,
            'email'               => $user->email,
            'document'            => $user->document,
            'phone'               => $user->phone,
            'birthDate'           => $user->birth_date ? substr((string) $user->birth_date, 0, 10) : null,
            'gender'              => $user->gender,
            'address'             => $user->address,
            'emergencyContact'    => $user->emergency_contact,
            'notes'               => $user->notes,
            'isMinor'             => (bool) ($member?->is_minor),
            'guardian'            => $this->guardianArray($member?->guardian),
            'status'              => $user->status,
            'plan'                => $user->plan,
            'membershipStartDate' => $user->membershipStartDate,
            'membershipEndDate'   => $user->membershipEndDate,
            'features'            => $this->featuresFor($user),
            'created_at'          => $user->created_at,
        ];
    }

    /** Edad en años cumplidos a partir de la fecha de nacimiento (o null). */
    private function ageFromBirthDate(?string $birthDate): ?int
    {
        if (! $birthDate) {
            return null;
        }
        try {
            return Carbon::parse($birthDate)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Crea/actualiza el acudiente del miembro (formato de menores). Igual que la
     * app: se guarda si hay nombre de responsable; si no, no se crea nada.
     *
     * @param array<string,mixed> $guardian campos camelCase del CRM.
     */
    private function syncGuardian(Member $member, array $guardian): void
    {
        $name = trim((string) ($guardian['guardianFullName'] ?? ''));
        $document = trim((string) ($guardian['guardianDocument'] ?? ''));

        if ($name === '' || $document === '') {
            return;
        }

        $member->guardian()->updateOrCreate(
            ['member_id' => $member->id],
            [
                'guardian_full_name' => $name,
                'guardian_document_number' => $document,
                'guardian_phone' => $guardian['guardianPhone'] ?? null,
                'guardian_email' => $guardian['guardianEmail'] ?? null,
                'guardian_relationship' => $guardian['guardianRelationship'] ?? null,
                'guardian_accepts_responsibility' => (bool) ($guardian['guardianAccepts'] ?? false),
            ]
        );
    }

    /** Serializa el acudiente (o null) para el CRM (camelCase). */
    private function guardianArray($guardian): ?array
    {
        if (! $guardian) {
            return null;
        }

        return [
            'fullName' => $guardian->guardian_full_name,
            'document' => $guardian->guardian_document_number,
            'phone' => $guardian->guardian_phone,
            'email' => $guardian->guardian_email,
            'relationship' => $guardian->guardian_relationship,
            'accepts' => (bool) $guardian->guardian_accepts_responsibility,
        ];
    }

    private function featuresFor(User $user): array
    {
        $plan = $user->plan ? Plan::where('name', $user->plan)->first() : null;

        $expiresAt = $user->membershipEndDate
            ? Carbon::parse($user->membershipEndDate)->endOfDay()
            : null;
        $isExpired = $expiresAt && $expiresAt->isPast();

        return ($isExpired || ! $plan)
            ? array_merge(array_map(fn () => false, Plan::defaultFeatures()), ['workouts' => true])
            : $plan->resolvedFeatures();
    }
}
