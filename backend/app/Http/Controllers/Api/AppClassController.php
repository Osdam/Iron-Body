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
use App\Services\NotificationService;
use App\Services\RealtimeEvents;
use App\Services\Trainer\TrainerRealtimeEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AppClassController extends Controller
{
    use MemberClassContext;

    /** Etiquetas de los días (lunes..domingo) del planificador semanal. */
    private const WEEKDAY_LABELS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    private function member(Request $request): Member
    {
        return $request->attributes->get('auth_member');
    }

    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $classes = MyClass::query()
            ->where('status', 'active')
            ->where('allow_online_booking', true)
            ->with('trainer:id,full_name')
            ->get();

        $classIds = $classes->pluck('id');
        $today = Carbon::today();

        // Reservas vigentes/futuras (por fecha) + legacy sin fecha, en bloque.
        $reservations = ClassReservation::whereIn('class_id', $classIds)
            ->where(function ($q) use ($today): void {
                $q->whereNull('session_date')->orWhereDate('session_date', '>=', $today->toDateString());
            })
            ->get(['class_id', 'member_id', 'session_date'])
            ->groupBy('class_id');

        // Sesión vigente (en curso / recién finalizada / hoy) + asistencia de HOY
        // del miembro, en bloque (sin N+1).
        $sessions = $this->relevantSessionsFor($classIds);
        $attendance = ClassAttendance::where('member_id', $member->id)
            ->whereIn('class_id', $classIds)
            ->whereDate('session_date', $today)
            ->get()
            ->keyBy('class_id');

        return response()->json([
            'data' => $classes->map(function (MyClass $c) use ($reservations, $sessions, $attendance, $member, $today) {
                // Cupo/estado referidos a la PRÓXIMA ocurrencia de la clase.
                $occDate = optional($c->nextOccurrence())->toDateString() ?? $today->toDateString();
                $rows = ($reservations->get($c->id) ?? collect())->filter(
                    fn ($r) => $r->session_date === null || optional($r->session_date)->toDateString() === $occDate,
                );

                $c->reservations_count = $rows->count();
                $reserved = $rows->contains(fn ($r) => (int) $r->member_id === (int) $member->id);

                return new ClassResource(
                    $c,
                    $reserved,
                    $this->memberClassContext($reserved, $sessions->get($c->id), $attendance->get($c->id)),
                );
            }),
        ]);
    }

    public function reserve(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $this->member($request);

        if ($myClass->status !== 'active' || ! $myClass->allow_online_booking) {
            return response()->json(['message' => 'Clase no disponible para reservas.'], 422);
        }

        // Reserva individual = la PRÓXIMA ocurrencia de la clase (sin cambiar la UX).
        $date = optional($myClass->nextOccurrence())->toDateString() ?? Carbon::today()->toDateString();

        $outcome = $this->reserveOccurrence($member, $myClass, $date);

        if ($outcome === 'already') {
            return response()->json(['message' => 'Ya tienes una reserva para esta clase.'], 422);
        }
        if ($outcome === 'full') {
            return response()->json(['message' => 'Clase completa.'], 422);
        }

        // Notificación de clase reservada (ADITIVO; no afecta la reserva).
        $notifier = app(NotificationService::class);
        $notifier->notifyClassReserved($member, $myClass);

        $booked = $this->occurrenceBookedCount($myClass, $date);
        if ($booked >= (int) $myClass->max_capacity) {
            $notifier->notifyClassFull($myClass);
        }

        $myClass->reservations_count = $booked;
        $myClass->load('trainer:id,full_name');

        // Cupo cambió → refresca el módulo de Clases para todos en vivo (app + CRM)
        // y el portal del entrenador dueño de la clase.
        RealtimeEvents::classesChanged();
        $this->emitTrainerClassChange($myClass);

        return response()->json(['data' => $this->resourceFor($myClass, $member, true)]);
    }

    public function cancel(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $this->member($request);

        // No se puede cancelar una clase que ya inició o finalizó (sesión de hoy).
        $session = $this->currentClassSession($myClass);
        if ($session && $session->started_at) {
            return response()->json([
                'message' => $session->ended_at
                    ? 'La clase ya finalizó.'
                    : 'La clase ya está en curso.',
            ], 422);
        }

        // Cancela la PRÓXIMA ocurrencia (+ legacy sin fecha). Conserva otras
        // reservas futuras de la misma clase hechas desde "Organizar mi semana".
        $date = optional($myClass->nextOccurrence())->toDateString() ?? Carbon::today()->toDateString();
        $deleted = ClassReservation::where('class_id', $myClass->id)
            ->where('member_id', $member->id)
            ->where(function ($q) use ($date): void {
                $q->whereNull('session_date')->orWhereDate('session_date', $date);
            })
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'No tienes reserva en esta clase.'], 422);
        }

        // Notificación de reserva cancelada (ADITIVO; no afecta la cancelación).
        app(NotificationService::class)->notifyClassReservationCancelled($member, $myClass);

        $myClass->reservations_count = $this->occurrenceBookedCount($myClass, $date);
        $myClass->load('trainer:id,full_name');

        // Cupo liberado → refresca el módulo de Clases para todos en vivo.
        RealtimeEvents::classesChanged();
        $this->emitTrainerClassChange($myClass);

        return response()->json(['data' => $this->resourceFor($myClass, $member, false)]);
    }

    /**
     * "ORGANIZAR MI SEMANA" — planificación semanal. Devuelve las ocurrencias
     * REALES de las clases activas (creadas en el CRM) para la semana pedida
     * (?week_start=YYYY-MM-DD; por defecto la semana en curso, lunes a domingo),
     * con cupo por fecha, estado de la reserva del miembro y si ya pasó/cerró.
     */
    public function weeklyPlan(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $weekStart = $this->resolveWeekStart($request);
        $weekEnd = $weekStart->copy()->addDays(6);
        $now = Carbon::now();

        $classes = MyClass::query()
            ->where('status', 'active')
            ->where('allow_online_booking', true)
            ->with('trainer:id,full_name')
            ->get();

        $classIds = $classes->pluck('id');

        // Reservas del miembro + conteos por (clase, fecha) dentro de la semana, en bloque.
        $myReserved = ClassReservation::where('member_id', $member->id)
            ->whereIn('class_id', $classIds)
            ->whereBetween('session_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get(['class_id', 'session_date'])
            ->map(fn ($r) => $r->class_id.'|'.optional($r->session_date)->toDateString())
            ->flip();

        $counts = ClassReservation::whereIn('class_id', $classIds)
            ->whereBetween('session_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->selectRaw('class_id, session_date, COUNT(*) as c')
            ->groupBy('class_id', 'session_date')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->class_id.'|'.Carbon::parse($r->session_date)->toDateString() => (int) $r->c]);

        $sessions = ClassSession::whereIn('class_id', $classIds)
            ->whereBetween('session_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get()
            ->keyBy(fn ($s) => $s->class_id.'|'.optional($s->session_date)->toDateString());

        // Construye las ocurrencias agrupadas por día (lunes..domingo).
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $days[$i] = [
                'date' => $date->toDateString(),
                'weekday' => $date->isoWeekday(), // 1=lunes
                'label' => self::WEEKDAY_LABELS[$i],
                'classes' => [],
            ];
        }

        foreach ($classes as $class) {
            $occ = $class->occurrenceDateTimeInWeek($weekStart);
            if ($occ === null) {
                continue;
            }
            $idx = (int) round(($occ->copy()->startOfDay()->timestamp - $weekStart->copy()->startOfDay()->timestamp) / 86400);
            if ($idx < 0 || $idx > 6) {
                continue;
            }
            $dateStr = $occ->toDateString();
            $key = $class->id.'|'.$dateStr;
            $booked = $counts[$key] ?? 0;
            $capacity = (int) $class->max_capacity;
            $session = $sessions->get($key);
            $isReserved = $myReserved->has($key);

            // Estado OFICIAL desde la SESIÓN (entrenador/backend), igual que Clases
            // normal y el detalle. NUNCA se finaliza/cierra por hora local: una
            // clase en curso (live) o futura no debe verse cerrada por reloj.
            $sessionStatus = $this->sessionStatusLabel($session);
            $isClosed = $sessionStatus === 'finished'; // cerrada/finalizada por el entrenador
            $isLive = $sessionStatus === 'live';       // en curso (entrenador la inició)
            $isFull = $booked >= $capacity;

            // "Vencida" SOLO si la ocurrencia ya pasó por completo y NO hay sesión
            // viva ni cerrada (el entrenador no la abrió). Excluye live/finished y
            // futuras, evitando el falso "Finalizada" por cálculo de hora.
            $isPast = ! $isLive && ! $isClosed && $this->occurrenceEnd($class, $occ)->isPast();

            $state = match (true) {
                $isReserved => 'reserved',
                $isClosed   => 'unavailable', // cierre/finalización real del entrenador
                $isLive     => 'live',        // en curso (precede a cualquier cálculo de hora)
                $isFull     => 'full',
                $isPast     => 'unavailable', // ocurrencia realmente vencida (no abierta)
                ($capacity - $booked) <= 3 => 'few_spots',
                default     => 'available',
            };

            $days[$idx]['classes'][] = [
                'reservation_key' => $key,
                'class_id'        => (int) $class->id,
                'session_date'    => $dateStr,
                'name'            => $class->name,
                'type'            => $class->type,
                'instructor'      => $class->instructor ?? $class->trainer?->full_name ?? '',
                'start_time'      => $class->start_time,
                'end_time'        => $class->end_time,
                'date_time'       => $occ->toIso8601String(),
                'duration_minutes' => $class->duration_minutes,
                'location'        => $class->location,
                'total_spots'     => $capacity,
                'booked_spots'    => $booked,
                'available_spots' => max(0, $capacity - $booked),
                'is_reserved'     => $isReserved,
                'is_full'         => $isFull,
                'is_past'         => $isPast,
                'is_closed'       => $isClosed,
                'is_live'         => $isLive,
                'state'           => $state,
                'can_reserve'     => ! $isReserved && ! $isFull && ! $isClosed && ! $isLive && ! $isPast,
            ];
        }

        foreach ($days as $i => $day) {
            usort($days[$i]['classes'], fn ($a, $b) => strcmp((string) $a['start_time'], (string) $b['start_time']));
        }

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'week_end'   => $weekEnd->toDateString(),
            'days'       => array_values($days),
        ]);
    }

    /**
     * "ORGANIZAR MI SEMANA" — reserva en LOTE. Recibe varias ocurrencias y crea
     * las reservas válidas con resultado PARCIAL (no tumba todo si una falla):
     * informa reservadas, ya reservadas, llenas, no disponibles, vencidas,
     * cerradas y conflictos de horario. Cada reserva es transaccional con lock
     * para no sobrepasar cupos ante concurrencia.
     */
    public function reserveWeek(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $data = $request->validate([
            'items'                => ['required', 'array', 'min:1', 'max:40'],
            'items.*.class_id'     => ['required', 'integer'],
            'items.*.session_date' => ['required', 'date'],
        ]);

        $results = [
            'reserved' => [], 'already' => [], 'full' => [],
            'unavailable' => [], 'past' => [], 'closed' => [], 'conflict' => [],
        ];
        $acceptedSlots = []; // [date => [[start,end], ...]] para detectar conflictos en la selección
        $affectedTrainers = [];

        foreach ($data['items'] as $item) {
            $class = MyClass::find($item['class_id']);
            $date = Carbon::parse($item['session_date'])->toDateString();
            $tag = ['class_id' => (int) $item['class_id'], 'session_date' => $date, 'name' => $class?->name];

            if (! $class || $class->status !== 'active' || ! $class->allow_online_booking) {
                $results['unavailable'][] = $tag;
                continue;
            }

            $occStart = Carbon::parse($date.' '.($class->start_time ?: '00:00'));
            $occEnd = $this->occurrenceEnd($class, $occStart);
            if ($occEnd->isPast()) {
                $results['past'][] = $tag;
                continue;
            }

            $session = ClassSession::where('class_id', $class->id)->whereDate('session_date', $date)->first();
            if ($session && $session->ended_at) {
                $results['closed'][] = $tag;
                continue;
            }

            // Conflicto de horario dentro de la propia selección (mismo día, solape).
            if ($this->conflictsWithSelection($acceptedSlots, $date, $occStart, $occEnd)) {
                $results['conflict'][] = $tag;
                continue;
            }

            $outcome = $this->reserveOccurrence($member, $class, $date);
            $results[$outcome === 'reserved' ? 'reserved' : $outcome][] = $tag;

            if ($outcome === 'reserved') {
                $acceptedSlots[$date][] = [$occStart, $occEnd];
                if ($class->trainer_id) {
                    $affectedTrainers[(int) $class->trainer_id] = true;
                }
                $booked = $this->occurrenceBookedCount($class, $date);
                if ($booked >= (int) $class->max_capacity) {
                    app(NotificationService::class)->notifyClassFull($class);
                }
            }
        }

        $reservedCount = count($results['reserved']);
        $failedCount = count($results['already']) + count($results['full']) + count($results['unavailable'])
            + count($results['past']) + count($results['closed']) + count($results['conflict']);

        // Notificación interna de confirmación (resumen del plan semanal).
        app(NotificationService::class)->notifyWeeklyPlanConfirmed($member, $reservedCount, $failedCount);

        // Realtime: refresca Clases para todos y el portal de cada entrenador tocado.
        if ($reservedCount > 0) {
            RealtimeEvents::classesChanged();
            foreach (array_keys($affectedTrainers) as $trainerId) {
                TrainerRealtimeEvents::emit($trainerId, TrainerRealtimeEvents::CLASS_EVENT, ['classes']);
            }
        }

        return response()->json([
            'summary' => [
                'reserved'    => $reservedCount,
                'failed'      => $failedCount,
                'already'     => count($results['already']),
                'full'        => count($results['full']),
                'unavailable' => count($results['unavailable']),
                'past'        => count($results['past']),
                'closed'      => count($results['closed']),
                'conflict'    => count($results['conflict']),
            ],
            'results' => $results,
        ]);
    }

    /**
     * AUTO CHECK-IN: el miembro marca su propia asistencia (presente) cuando la
     * clase está EN CURSO. Requiere reserva y que el entrenador haya iniciado la
     * clase (sesión con `started_at` y sin `ended_at`).
     */
    public function checkIn(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $this->member($request);
        $today = Carbon::today();

        $reserved = ClassReservation::where('class_id', $myClass->id)
            ->where('member_id', $member->id)
            ->exists();
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

        $sessionDate = optional($session->session_date)->toDateString() ?? $today->toDateString();
        ClassAttendance::updateOrCreate(
            [
                'class_id' => $myClass->id,
                'member_id' => $member->id,
                'session_date' => $sessionDate,
            ],
            [
                'status' => ClassAttendance::STATUS_PRESENT,
                'marked_at' => now(),
            ],
        );

        $myClass->loadCount('reservations')->load('trainer:id,full_name');

        // Realtime: el entrenador ve la asistencia entrar en vivo en su portal.
        if ($myClass->trainer_id) {
            TrainerRealtimeEvents::emit((int) $myClass->trainer_id, TrainerRealtimeEvents::ATTENDANCE, ['classes']);
        }

        return response()->json([
            'message' => 'Asistencia registrada.',
            'data' => $this->resourceFor($myClass, $member, true),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** ClassResource con el contexto del miembro recalculado (reserva/cancela/etc.). */
    private function resourceFor(MyClass $class, Member $member, bool $reserved): ClassResource
    {
        $session = $this->currentClassSession($class);
        $sessionDate = optional($session?->session_date)->toDateString() ?? Carbon::today()->toDateString();
        $attendance = ClassAttendance::where('class_id', $class->id)
            ->where('member_id', $member->id)
            ->whereDate('session_date', $sessionDate)
            ->first();

        return new ClassResource($class, $reserved, $this->memberClassContext($reserved, $session, $attendance));
    }

    /** Señal real-time al portal del entrenador dueño de la clase (best-effort). */
    private function emitTrainerClassChange(MyClass $class): void
    {
        if ($class->trainer_id) {
            TrainerRealtimeEvents::emit((int) $class->trainer_id, TrainerRealtimeEvents::CLASS_EVENT, ['classes']);
        }
    }

    /** Lunes (00:00) de la semana pedida (?week_start) o la semana en curso. */
    private function resolveWeekStart(Request $request): Carbon
    {
        $raw = $request->query('week_start');
        $base = $raw !== null ? Carbon::parse((string) $raw) : Carbon::now();

        return $base->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /** Fin de la ocurrencia: usa end_time si existe, si no start + duración. */
    private function occurrenceEnd(MyClass $class, Carbon $start): Carbon
    {
        if ($class->end_time) {
            [$h, $m] = array_pad(explode(':', (string) $class->end_time), 2, '0');
            $end = $start->copy()->setTime((int) $h, (int) $m, 0);
            if ($end->lessThanOrEqualTo($start)) {
                $end = $start->copy()->addMinutes((int) ($class->duration_minutes ?: 60));
            }

            return $end;
        }

        return $start->copy()->addMinutes((int) ($class->duration_minutes ?: 60));
    }

    /** ¿La ocurrencia [start,end] solapa con alguna ya aceptada ese mismo día? */
    private function conflictsWithSelection(array $accepted, string $date, Carbon $start, Carbon $end): bool
    {
        foreach ($accepted[$date] ?? [] as [$s, $e]) {
            if ($start->lessThan($e) && $end->greaterThan($s)) {
                return true;
            }
        }

        return false;
    }
}
