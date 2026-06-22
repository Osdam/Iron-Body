<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('status') && Schema::hasColumn('users', 'status')) {
            $query->where('status', $request->input('status'));
        }

        return $query
            ->select($this->memberFields())
            ->orderByDesc('created_at')
            ->paginate(20);
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

        $user = User::create([
            'name' => $validated['fullName'],
            'email' => $validated['email'] ?? 'user-' . time() . '-' . mt_rand(1000, 9999) . '@ironbody.local',
            'password' => bcrypt('default-password'),
            'document' => $validated['document'],
            'phone' => $validated['phone'],
            'birth_date' => $validated['birthDate'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'address' => $validated['address'] ?? null,
            'emergency_contact' => $validated['emergencyContact'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => 'active',
            // plan / membresía NO se fijan al crear: se otorgan con pagos.
        ]);

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
            }
            if (($validated['status'] ?? null) === 'active') {
                $memberUpdates['status'] = Member::STATUS_ACTIVE;
            }

            if ($memberUpdates !== []) {
                $user->appMember()->update($memberUpdates);
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
            'status'              => $user->status,
            'plan'                => $user->plan,
            'membershipStartDate' => $user->membershipStartDate,
            'membershipEndDate'   => $user->membershipEndDate,
            'features'            => $this->featuresFor($user),
            'created_at'          => $user->created_at,
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
