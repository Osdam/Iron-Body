<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    use ResolvesPagination;

    /** Mayoría de edad (años): por debajo se exige acudiente. Espejo de la app. */
    private const LEGAL_ADULT_AGE = 18;

    /**
     * Listado paginado de miembros. Filtrado y búsqueda se resuelven en el
     * servidor (`status`, `search`, `per_page`) para que el CRM cargue SOLO la
     * página visible en vez de recorrer todas las páginas.
     */
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('status') && Schema::hasColumn('users', 'status')) {
            $query->where('status', $request->input('status'));
        }

        // Búsqueda server-side sobre los campos que el CRM muestra en la tabla.
        // `ilike` en PostgreSQL (LIKE distingue mayúsculas ahí); en MySQL/SQLite
        // el collation por defecto ya es insensible a mayúsculas.
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $operator = $this->likeOperator($query->getConnection()->getDriverName());
            $like = $this->likeTerm($search);
            $query->where(function ($q) use ($operator, $like): void {
                $q->where('name', $operator, $like)
                    ->orWhere('email', $operator, $like)
                    ->orWhere('document', $operator, $like)
                    ->orWhere('phone', $operator, $like);
            });
        }

        $page = $query
            ->select($this->memberFields())
            ->with('appMember.guardian')
            ->orderByDesc('created_at')
            ->paginate($this->resolvePerPage($request));

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
            'userId' => (string) $user->id,
            'planId' => $plan ? (string) $plan->id : null,
            'planName' => $plan ? $plan->name : $user->plan,
            'features' => $features,
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
            // Obligatoria SOLO al crear una cuenta nueva desde el CRM: sin ella
            // no se puede aplicar el mínimo de edad y el registro quedaría
            // marcado como adulto por omisión. No afecta a cuentas históricas
            // (no se revalidan) ni al registro desde la app, donde la fecha
            // procede del OCR y puede fallar legítimamente.
            'birthDate' => 'required|date',
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

        // Edad / menor de edad: SIEMPRE se calcula en el servidor, nunca se
        // acepta del cliente. Una fecha futura o no parseable devuelve null
        // (dato no fiable), no una edad de 0.
        $birthDateInput = $validated['birthDate'] ?? null;
        $age = $this->ageFromBirthDate($birthDateInput);

        // Fecha presente pero no interpretable (p. ej. futura): se rechaza en
        // vez de dejarla entrar con la edad en null.
        if (filled($birthDateInput) && $age === null) {
            return response()->json([
                'message' => 'La fecha de nacimiento no es válida.',
            ], 422);
        }

        if ($age !== null && $age < Member::minRegistrationAge()) {
            return response()->json([
                'message' => 'El registro no está disponible para menores de '
                    .Member::minRegistrationAge().' años.',
            ], 422);
        }

        // En este punto la fecha es obligatoria (ver reglas del request), así
        // que `$age` es fiable y `is_minor` se deriva de ella. Nunca se acepta
        // `is_minor` del cliente.
        $isMinor = $age !== null && $age < Member::legalAdultAge();

        // Si es menor, el acudiente (nombre + documento) es OBLIGATORIO.
        $guardian = $request->validate([
            'guardianFullName' => [$isMinor ? 'required' : 'nullable', 'string', 'max:255'],
            'guardianDocument' => [$isMinor ? 'required' : 'nullable', 'string', 'max:50'],
            'guardianPhone' => ['nullable', 'string', 'max:30'],
            'guardianEmail' => ['nullable', 'email', 'max:255'],
            'guardianRelationship' => ['nullable', 'string', 'max:80'],
            'guardianAccepts' => ['sometimes', 'boolean'],
        ], [
            'guardianFullName.required' => 'El nombre del acudiente es obligatorio para menores de edad.',
            'guardianDocument.required' => 'El documento del acudiente es obligatorio para menores de edad.',
        ]);

        $user = DB::transaction(function () use ($validated, $guardian, $document, $isMinor): User {
            $user = User::create([
                'name' => $validated['fullName'],
                'email' => $validated['email'] ?? 'user-'.time().'-'.mt_rand(1000, 9999).'@ironbody.local',
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
        app(NotificationService::class)
            ->notifyMemberCreated($user, $user->name, $user->document);

        app(AuditTrail::class)->record($request, [
            'action' => 'create', 'module' => 'Miembros', 'entity' => 'cliente',
            'entity_id' => $user->id, 'target_name' => $user->name,
            'summary' => "Dio de alta a {$user->name}",
            // Sin documento ni contacto: la traza dice QUÉ pasó y sobre quién,
            // no reproduce los datos personales del socio.
            'metadata' => ['plan_id' => $user->plan_id],
        ]);

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

        // El documento es la llave de acceso del miembro y es ÚNICO en `members`.
        // Al editar hay que comprobarlo igual que al crear: sin esta guarda, un
        // documento ya usado se escribía en `users.document` (esa columna no
        // tiene índice único) y estallaba después al replicarlo en
        // `members.document_number`, dejando las dos tablas desincronizadas y
        // devolviendo un 500 en vez de un mensaje claro.
        if (array_key_exists('document', $validated) && filled($validated['document'])) {
            $document = Member::normalizeDocumentNumber($validated['document']);

            if ($document === null) {
                return response()->json(['message' => 'El documento no es válido.'], 422);
            }

            $takenByAnotherMember = Member::where('document_number', $document)
                ->when($user->appMember, fn ($q) => $q->whereKeyNot($user->appMember->getKey()))
                ->exists();
            $takenByAnotherUser = User::where('document', $document)
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($takenByAnotherMember || $takenByAnotherUser) {
                return response()->json([
                    'message' => 'Ya existe un miembro registrado con ese documento.',
                ], 422);
            }

            // Se guarda normalizado en AMBAS tablas (como hace `store`), para que
            // el login por documento encuentre siempre al miembro.
            $validated['document'] = $document;
        }

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

            $notifier = app(NotificationService::class);

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

        app(AuditTrail::class)->record($request, [
            'action' => 'update', 'module' => 'Miembros', 'entity' => 'cliente',
            'entity_id' => $user->id, 'target_name' => $user->name,
            'summary' => "Modificó la ficha de {$user->name}",
            'changes' => array_map(static fn (string $c) => ['field' => $c], array_keys($request->all())),
        ]);

        return response()->json($this->serialize($user));
    }

    public function destroy(Request $request, User $user)
    {
        // Auditoría: miembro eliminado (ANTES de borrar, conserva nombre/doc).
        app(NotificationService::class)
            ->notifyMemberDeleted($user, $user->name, $user->document);

        // La traza también se escribe antes: después de `delete()` el nombre ya
        // no está, y una baja sin nombre no sirve para auditar nada.
        app(AuditTrail::class)->record($request, [
            'action' => 'delete', 'module' => 'Miembros', 'entity' => 'cliente',
            'entity_id' => $user->id, 'target_name' => $user->name,
            'summary' => "Dio de baja a {$user->name}",
        ]);

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
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'document' => $user->document,
            'phone' => $user->phone,
            'birthDate' => $user->birth_date ? substr((string) $user->birth_date, 0, 10) : null,
            'gender' => $user->gender,
            'address' => $user->address,
            'emergencyContact' => $user->emergency_contact,
            'notes' => $user->notes,
            'isMinor' => (bool) ($member?->is_minor),
            'guardian' => $this->guardianArray($member?->guardian),
            'status' => $user->status,
            'plan' => $user->plan,
            'membershipStartDate' => $user->membershipStartDate,
            'membershipEndDate' => $user->membershipEndDate,
            'features' => $this->featuresFor($user),
            'created_at' => $user->created_at,
        ];
    }

    /** Edad en años cumplidos a partir de la fecha de nacimiento (o null). */
    /**
     * Edad cumplida, o `null` cuando no se puede determinar (ausente, no
     * parseable o futura). Delega en el modelo para que exista UNA sola
     * definición de "edad" en el backend.
     */
    private function ageFromBirthDate(?string $birthDate): ?int
    {
        return Member::ageFromBirthDate($birthDate);
    }

    /**
     * Crea/actualiza el acudiente del miembro (formato de menores). Igual que la
     * app: se guarda si hay nombre de responsable; si no, no se crea nada.
     *
     * @param  array<string,mixed>  $guardian  campos camelCase del CRM.
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
