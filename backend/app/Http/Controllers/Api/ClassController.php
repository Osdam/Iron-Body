<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClassResource;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\NotificationService;
use App\Services\RealtimeEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassController extends Controller
{
    public function index(Request $request)
    {
        // Optional member context: app sends access_hash as Bearer token.
        $member   = $this->resolveMember($request);
        $memberId = $member?->id;

        $query = MyClass::query()->with('trainer:id,full_name')->withCount('reservations');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('day_of_week')) {
            $query->where('day_of_week', $request->input('day_of_week'));
        }
        if ($request->filled('trainer_id')) {
            $query->where('trainer_id', $request->input('trainer_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('location', 'like', "%{$search}%"));
        }

        $paginated = $query->paginate(20);

        $reservedIds = $memberId
            ? ClassReservation::where('member_id', $memberId)
                ->whereIn('class_id', $paginated->pluck('id'))
                ->pluck('class_id')
                ->flip()
            : collect();

        $paginated->getCollection()->transform(
            fn (MyClass $c) => new ClassResource($c, $memberId !== null && $reservedIds->has($c->id))
        );

        return $paginated;
    }

    public function show(MyClass $myClass, Request $request)
    {
        $member = $this->resolveMember($request);
        $myClass->loadCount('reservations')->load('trainer:id,full_name');

        $isReserved = $member
            && ClassReservation::where('class_id', $myClass->id)
                ->where('member_id', $member->id)
                ->exists();

        return new ClassResource($myClass, (bool) $isReserved);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                 => 'required|string|max:255',
            'type'                 => 'required|string|max:100',
            'day_of_week'          => 'required|string|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
            'start_time'           => 'required|date_format:H:i',
            'end_time'             => 'required|date_format:H:i|after:start_time',
            'duration_minutes'     => 'nullable|integer|min:15',
            'max_capacity'         => 'required|integer|min:1',
            'location'             => 'nullable|string|max:255',
            'status'               => 'nullable|string|in:active,inactive,finished',
            'trainer_id'           => 'nullable|exists:trainers,id',
            'instructor'           => 'nullable|string|max:255',
            'date_time'            => 'nullable|date',
            'description'          => 'nullable|string',
            'notes'                => 'nullable|string',
            'is_recurring'         => 'nullable|boolean',
            'allow_online_booking' => 'nullable|boolean',
            'requires_active_plan' => 'nullable|boolean',
        ]);

        if (empty($validated['duration_minutes'])) {
            $start = \DateTime::createFromFormat('H:i', $validated['start_time']);
            $end   = \DateTime::createFromFormat('H:i', $validated['end_time']);
            $validated['duration_minutes'] = $end->diff($start)->i + ($end->diff($start)->h * 60);
        }

        $class = MyClass::create($validated);

        // Asignar una clase implica que ese entrenador la gestione en su portal:
        // le garantizamos el rol que habilita el portal de clases.
        $this->ensureClassTrainerRole($validated['trainer_id'] ?? null);

        // Notificación de clase creada (ADITIVO; no afecta la creación).
        app(NotificationService::class)->notifyClassCreated($class);

        // Refresco en vivo del módulo de Clases para todos los miembros.
        RealtimeEvents::classesChanged();

        return (new ClassResource($class->load('trainer:id,full_name')))->response()->setStatusCode(201);
    }

    public function update(Request $request, MyClass $myClass)
    {
        $validated = $request->validate([
            'name'                 => 'sometimes|string|max:255',
            'type'                 => 'sometimes|string|max:100',
            'day_of_week'          => 'sometimes|string|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
            'start_time'           => 'sometimes|date_format:H:i',
            'end_time'             => 'sometimes|date_format:H:i|after:start_time',
            'duration_minutes'     => 'nullable|integer|min:15',
            'max_capacity'         => 'sometimes|integer|min:1',
            'enrolled_count'       => 'nullable|integer|min:0',
            'location'             => 'nullable|string|max:255',
            'status'               => 'sometimes|string|in:active,inactive,finished',
            'trainer_id'           => 'nullable|exists:trainers,id',
            'instructor'           => 'nullable|string|max:255',
            'date_time'            => 'nullable|date',
            'description'          => 'nullable|string',
            'notes'                => 'nullable|string',
            'is_recurring'         => 'nullable|boolean',
            'allow_online_booking' => 'nullable|boolean',
            'requires_active_plan' => 'nullable|boolean',
        ]);

        if (
            empty($validated['duration_minutes']) &&
            ! empty($validated['start_time']) &&
            ! empty($validated['end_time'])
        ) {
            $start = \DateTime::createFromFormat('H:i', $validated['start_time']);
            $end   = \DateTime::createFromFormat('H:i', $validated['end_time']);
            if ($start && $end && $end > $start) {
                $validated['duration_minutes'] = $end->diff($start)->i + ($end->diff($start)->h * 60);
            }
        }

        $myClass->update($validated);

        // Si se (re)asignó a un entrenador, garantízale el rol del portal de clases.
        $this->ensureClassTrainerRole($myClass->trainer_id);

        // Notifica a los miembros inscritos de los cambios (ADITIVO).
        $members = $myClass->reservations()->with('member')->get()
            ->pluck('member')->filter()->values();
        app(NotificationService::class)->notifyClassUpdated($myClass, $members);

        // Refresco en vivo (horario/cupo/estado) para todos los miembros.
        RealtimeEvents::classesChanged();

        return new ClassResource($myClass->loadCount('reservations')->load('trainer:id,full_name'));
    }

    /**
     * Al asignar una clase a un entrenador, le garantizamos el rol que habilita
     * el portal de clases (`trainer_functional` → classes.view/manage/attendance),
     * sin quitarle los que ya tenga. Si no, la clase no le aparecería en su portal.
     */
    private function ensureClassTrainerRole(?int $trainerId): void
    {
        if (! $trainerId) {
            return;
        }
        $trainer = Trainer::find($trainerId);
        if ($trainer === null || $trainer->hasPermission('classes.view')) {
            return;
        }
        $trainer->syncRoles(array_values(array_unique([
            ...$trainer->roleNames(),
            TrainerRole::FUNCTIONAL,
        ])));
    }

    public function destroy(MyClass $myClass)
    {
        // Captura inscritos ANTES de eliminar para poder avisarles (ADITIVO).
        $members = $myClass->reservations()->with('member')->get()
            ->pluck('member')->filter()->values();
        app(NotificationService::class)->notifyClassCancelled($myClass, $members);

        $myClass->delete();

        // Refresco en vivo: la clase desaparece del módulo para todos.
        RealtimeEvents::classesChanged();

        return response()->json(['message' => 'Clase eliminada correctamente'], 200);
    }

    /** GET /api/classes/{myClass}/reservations — lista de reservas para el CRM */
    public function reservations(MyClass $myClass): JsonResponse
    {
        $reservations = $myClass->reservations()
            ->with('member:id,member_uuid,full_name,email,phone')
            ->orderBy('reserved_at')
            ->get()
            ->map(fn ($r) => [
                'reservation_id' => $r->id,
                'reserved_at'    => $r->reserved_at->toIso8601String(),
                'member'         => [
                    'id'        => $r->member->id,
                    'uuid'      => $r->member->member_uuid,
                    'full_name' => $r->member->full_name,
                    'email'     => $r->member->email,
                    'phone'     => $r->member->phone,
                ],
            ]);

        return response()->json([
            'class_id'     => $myClass->id,
            'class_name'   => $myClass->name,
            'max_capacity' => $myClass->max_capacity,
            'booked_spots' => $reservations->count(),
            'reservations' => $reservations,
        ]);
    }

    /** POST /api/classes/{myClass}/reserve — reservar (requiere auth.member) */
    public function reserve(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        if ($myClass->status !== 'active' || ! $myClass->allow_online_booking) {
            return response()->json(['message' => 'Clase no disponible para reservas.'], 422);
        }

        $bookedSpots = $myClass->reservations()->count();
        if ($bookedSpots >= $myClass->max_capacity) {
            return response()->json(['message' => 'No hay cupos disponibles.'], 422);
        }

        if (ClassReservation::where('class_id', $myClass->id)->where('member_id', $member->id)->exists()) {
            return response()->json(['message' => 'Ya tienes esta clase reservada.'], 422);
        }

        ClassReservation::create([
            'class_id'    => $myClass->id,
            'member_id'   => $member->id,
            'reserved_at' => now(),
        ]);

        $myClass->loadCount('reservations')->load('trainer:id,full_name');

        // Cupo cambió → refresca el módulo de Clases para todos en vivo.
        RealtimeEvents::classesChanged();

        return response()->json(['data' => new ClassResource($myClass, true)]);
    }

    /** POST /api/classes/{myClass}/cancel — cancelar reserva (requiere auth.member) */
    public function cancel(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $deleted = ClassReservation::where('class_id', $myClass->id)
            ->where('member_id', $member->id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'No tienes reserva en esta clase.'], 422);
        }

        $myClass->loadCount('reservations')->load('trainer:id,full_name');

        // Cupo liberado → refresca el módulo de Clases para todos en vivo.
        RealtimeEvents::classesChanged();

        return response()->json(['data' => new ClassResource($myClass, false)]);
    }

    private function resolveMember(Request $request): ?Member
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }
        return Member::resolveByToken($token);
    }
}
