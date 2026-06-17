<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\MemberClassContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClassResource;
use App\Models\ClassAttendance;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\NotificationService;
use App\Services\RealtimeEvents;
use App\Services\Trainer\TrainerRealtimeEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ClassController extends Controller
{
    use MemberClassContext;

    public function index(Request $request)
    {
        // Optional member context: app sends access_hash as Bearer token.
        $member   = $this->resolveMember($request);
        $memberId = $member?->id;

        $query = MyClass::query()->with('trainer:id,full_name');

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

        $classIds = $paginated->pluck('id');
        $today = Carbon::today();

        // Reservas vigentes/futuras (por fecha) + legacy sin fecha, en bloque. El
        // cupo/estado de la card se refieren a la PRÓXIMA ocurrencia de la clase,
        // para que las reservas de semanas futuras no inflen el conteo del ciclo.
        $reservations = ClassReservation::whereIn('class_id', $classIds)
            ->where(function ($q) use ($today): void {
                $q->whereNull('session_date')->orWhereDate('session_date', '>=', $today->toDateString());
            })
            ->get(['class_id', 'member_id', 'session_date'])
            ->groupBy('class_id');

        // Sesión vigente de cada clase (en curso / recién finalizada / hoy) +
        // asistencia de HOY del miembro. Sin N+1.
        $sessions = $this->relevantSessionsFor($classIds);
        $attendance = $memberId
            ? ClassAttendance::where('member_id', $memberId)
                ->whereIn('class_id', $classIds)
                ->whereDate('session_date', Carbon::today())
                ->get()
                ->keyBy('class_id')
            : collect();

        $paginated->getCollection()->transform(function (MyClass $c) use ($memberId, $reservations, $sessions, $attendance, $today) {
            $occDate = optional($c->nextOccurrence())->toDateString() ?? $today->toDateString();
            $rows = ($reservations->get($c->id) ?? collect())->filter(
                fn ($r) => $r->session_date === null || optional($r->session_date)->toDateString() === $occDate,
            );
            $c->reservations_count = $rows->count();

            $reserved = $memberId !== null
                && $rows->contains(fn ($r) => (int) $r->member_id === (int) $memberId);
            $context = $memberId !== null
                ? $this->memberClassContext($reserved, $sessions->get($c->id), $attendance->get($c->id))
                : [];
            return new ClassResource($c, $reserved, $context);
        });

        return $paginated;
    }

    public function show(MyClass $myClass, Request $request)
    {
        $member = $this->resolveMember($request);
        $myClass->load('trainer:id,full_name');

        // Cupo/estado referidos a la próxima ocurrencia (coherente con index()).
        $date = optional($myClass->nextOccurrence())->toDateString() ?? Carbon::today()->toDateString();
        $myClass->reservations_count = $this->occurrenceBookedCount($myClass, $date);

        $isReserved = $member
            && ClassReservation::where('class_id', $myClass->id)
                ->where('member_id', $member->id)
                ->where(function ($q) use ($date): void {
                    $q->whereNull('session_date')->orWhereDate('session_date', $date);
                })
                ->exists();

        return new ClassResource($myClass, (bool) $isReserved);
    }

    public function store(Request $request)
    {
        $this->normalizeClassInput($request);
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
            'renewal_hours'        => 'nullable|integer|in:0,8,12,24,48,168',
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
        $this->normalizeClassInput($request);
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
            'renewal_hours'        => 'nullable|integer|in:0,8,12,24,48,168',
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
     * Normaliza las entradas de clase para que ediciones con datos en otro
     * formato (horas con segundos, día/estado en inglés o con mayúsculas/acentos)
     * no fallen la validación `in:`/`date_format`. Deja valores canónicos.
     */
    private function normalizeClassInput(Request $request): void
    {
        $merge = [];

        // Horas "H:i:s" → "H:i" (la validación pide H:i).
        foreach (['start_time', 'end_time'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && preg_match('/^(\d{1,2}:\d{2}):\d{2}$/', $value, $m)) {
                $merge[$field] = $m[1];
            }
        }

        // Estado → active|inactive|finished (acepta español/mayúsculas). Si llega
        // presente pero vacío o con un valor desconocido (CRM viejo), cae a
        // 'active' para no romper la validación `in:`.
        if ($request->has('status')) {
            $statusMap = [
                'active' => 'active', 'activa' => 'active', 'activo' => 'active',
                'inactive' => 'inactive', 'inactiva' => 'inactive', 'inactivo' => 'inactive',
                'finished' => 'finished', 'finalizada' => 'finished', 'finalizado' => 'finished',
            ];
            $key = $this->stripAccentsLower((string) $request->input('status'));
            $merge['status'] = $statusMap[$key] ?? 'active';
        }

        // Frecuencia de renovación: "" o no-numérico → null (no renovar), así un
        // valor vacío del CRM no falla la validación `integer|in:`.
        if ($request->has('renewal_hours')) {
            $raw = $request->input('renewal_hours');
            $merge['renewal_hours'] = ($raw === '' || $raw === null || ! is_numeric($raw))
                ? null
                : (int) $raw;
        }

        // Día → Lunes..Domingo (acepta inglés, minúsculas, sin acentos).
        if ($request->filled('day_of_week')) {
            $dayMap = [
                'lunes' => 'Lunes', 'monday' => 'Lunes',
                'martes' => 'Martes', 'tuesday' => 'Martes',
                'miercoles' => 'Miércoles', 'wednesday' => 'Miércoles',
                'jueves' => 'Jueves', 'thursday' => 'Jueves',
                'viernes' => 'Viernes', 'friday' => 'Viernes',
                'sabado' => 'Sábado', 'saturday' => 'Sábado',
                'domingo' => 'Domingo', 'sunday' => 'Domingo',
            ];
            $key = $this->stripAccentsLower((string) $request->input('day_of_week'));
            if (isset($dayMap[$key])) {
                $merge['day_of_week'] = $dayMap[$key];
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    private function stripAccentsLower(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
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

        // Reserva individual = la PRÓXIMA ocurrencia (por fecha), con lock y cupo
        // por fecha para no sobrepasar el aforo ante concurrencia.
        $date = optional($myClass->nextOccurrence())->toDateString() ?? Carbon::today()->toDateString();
        $outcome = $this->reserveOccurrence($member, $myClass, $date);

        if ($outcome === 'already') {
            return response()->json(['message' => 'Ya tienes esta clase reservada.'], 422);
        }
        if ($outcome === 'full') {
            return response()->json(['message' => 'No hay cupos disponibles.'], 422);
        }

        $myClass->reservations_count = $this->occurrenceBookedCount($myClass, $date);
        $myClass->load('trainer:id,full_name');

        // Cupo cambió → refresca Clases para todos en vivo + portal del entrenador.
        RealtimeEvents::classesChanged();
        if ($myClass->trainer_id) {
            TrainerRealtimeEvents::emit((int) $myClass->trainer_id, TrainerRealtimeEvents::CLASS_EVENT, ['classes']);
        }

        return response()->json(['data' => $this->memberResource($myClass, $member, true)]);
    }

    /** POST /api/classes/{myClass}/cancel — cancelar reserva (requiere auth.member) */
    public function cancel(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        // "Organizar mi semana" puede cancelar una OCURRENCIA concreta (futura)
        // enviando `session_date`. Sin él = comportamiento histórico de "Clases":
        // la próxima ocurrencia del ciclo (+ legacy sin fecha). Aditivo: no rompe
        // la cancelación individual ni toca otras reservas futuras.
        $data = $request->validate(['session_date' => ['nullable', 'date']]);
        $explicit = isset($data['session_date'])
            ? Carbon::parse($data['session_date'])->toDateString()
            : null;

        // Sesión a validar: la de ESA fecha (semanal) o la vigente de hoy (individual).
        // No se puede cancelar una clase que ya inició o finalizó.
        $session = $explicit !== null
            ? ClassSession::where('class_id', $myClass->id)->whereDate('session_date', $explicit)->first()
            : $this->currentClassSession($myClass);
        if ($session && $session->started_at) {
            return response()->json([
                'message' => $session->ended_at ? 'La clase ya finalizó.' : 'La clase ya está en curso.',
            ], 422);
        }

        $date = $explicit ?? (optional($myClass->nextOccurrence())->toDateString() ?? Carbon::today()->toDateString());
        $query = ClassReservation::where('class_id', $myClass->id)
            ->where('member_id', $member->id);
        if ($explicit !== null) {
            $query->whereDate('session_date', $explicit); // ocurrencia exacta de la semana
        } else {
            $query->where(function ($q) use ($date): void {
                $q->whereNull('session_date')->orWhereDate('session_date', $date);
            });
        }
        $deleted = $query->delete();

        if (! $deleted) {
            return response()->json(['message' => 'No tienes reserva en esta clase.'], 422);
        }

        $myClass->reservations_count = $this->occurrenceBookedCount($myClass, $date);
        $myClass->load('trainer:id,full_name');

        // Cupo liberado → refresca Clases para todos en vivo + portal del entrenador.
        RealtimeEvents::classesChanged();
        if ($myClass->trainer_id) {
            TrainerRealtimeEvents::emit((int) $myClass->trainer_id, TrainerRealtimeEvents::CLASS_EVENT, ['classes']);
        }

        return response()->json(['data' => $this->memberResource($myClass, $member, false)]);
    }

    /**
     * POST /api/classes/{myClass}/check-in — AUTO check-in del miembro (presente).
     * Requiere reserva y que la clase esté EN CURSO (el entrenador la inició).
     */
    public function checkIn(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $reserved = ClassReservation::where('class_id', $myClass->id)
            ->where('member_id', $member->id)->exists();
        if (! $reserved) {
            return response()->json(['message' => 'No tienes reserva en esta clase.'], 422);
        }

        $session = $this->currentClassSession($myClass);
        if (! $session || ! $session->started_at) {
            return response()->json(['message' => 'La clase aún no ha iniciado.'], 422);
        }
        if ($session->ended_at) {
            return response()->json(['message' => 'La clase ya finalizó.'], 422);
        }

        // Se guarda con la FECHA DE LA SESIÓN (no "hoy") para que coincida con la
        // vista del entrenador y la supervisión, aunque haya desfase horario.
        $sessionDate = optional($session->session_date)->toDateString() ?? Carbon::today()->toDateString();
        ClassAttendance::updateOrCreate(
            ['class_id' => $myClass->id, 'member_id' => $member->id, 'session_date' => $sessionDate],
            ['status' => ClassAttendance::STATUS_PRESENT, 'marked_at' => now()],
        );

        $myClass->loadCount('reservations')->load('trainer:id,full_name');

        // Realtime: el entrenador ve la asistencia entrar en vivo en su portal.
        if ($myClass->trainer_id) {
            TrainerRealtimeEvents::emit((int) $myClass->trainer_id, TrainerRealtimeEvents::ATTENDANCE, ['classes']);
        }

        return response()->json([
            'message' => 'Asistencia registrada.',
            'data' => $this->memberResource($myClass, $member, true),
        ]);
    }

    /** ClassResource con el contexto de la sesión vigente del miembro. */
    private function memberResource(MyClass $class, Member $member, bool $reserved): ClassResource
    {
        $session = $this->currentClassSession($class);
        $sessionDate = optional($session?->session_date)->toDateString() ?? Carbon::today()->toDateString();
        $attendance = ClassAttendance::where('class_id', $class->id)
            ->where('member_id', $member->id)
            ->whereDate('session_date', $sessionDate)
            ->first();

        return new ClassResource($class, $reserved, $this->memberClassContext($reserved, $session, $attendance));
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
