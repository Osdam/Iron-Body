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
use App\Services\Classes\ClassBookingService;
use App\Services\Classes\ClassEventBus;
use App\Services\NotificationService;
use App\Services\Trainer\ClassAttendanceService;
use App\Support\ClassStateResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AppClassController extends Controller
{
    use MemberClassContext;

    /** Etiquetas de los días (lunes..domingo) del planificador semanal. */
    private const WEEKDAY_LABELS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    /**
     * Zona horaria operativa del gimnasio. El backend corre en UTC
     * (config/app.timezone), por eso —igual que Nutrición/Racha/Progreso— el día
     * y "hoy" del planificador se calculan en America/Bogota. Evita que una clase
     * de la tarde se vea "vencida" ~5 h antes por comparar contra UTC.
     */
    private const TZ = self::OPERATIONAL_TZ;

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
        $today = $this->operationalToday();
        $booking = app(ClassBookingService::class);

        // Cupo y reserva propia de la PRÓXIMA ocurrencia, con la misma condición
        // que el cupo al reservar (servicio único), en bloque. Y las próximas
        // sesiones también en bloque: sin precalcular, el recurso las pedía
        // clase a clase (dos consultas más por clase).
        $filas = $booking->operationalRowsFor($classes);
        $proximas = $booking->upcomingSessionsFor($classes);

        // Sesión vigente (en curso / recién finalizada / hoy) + asistencia de HOY
        // del miembro, en bloque (sin N+1).
        $sessions = $this->relevantSessionsFor($classIds);
        $attendance = ClassAttendance::where('member_id', $member->id)
            ->whereIn('class_id', $classIds)
            ->whereDate('session_date', $today)
            ->get()
            ->keyBy('class_id');

        return response()->json([
            'data' => $classes->map(function (MyClass $c) use ($filas, $proximas, $sessions, $attendance, $member) {
                // Cupo/estado referidos a la PRÓXIMA ocurrencia de la clase.
                $rows = $filas[$c->id] ?? collect();
                $c->setAttribute('upcoming_sessions', $proximas[$c->id] ?? []);

                $c->reservations_count = $rows->count();
                $myRow = $rows->first(fn ($r) => (int) $r->member_id === (int) $member->id);
                $reserved = $myRow !== null;

                return new ClassResource(
                    $c,
                    $reserved,
                    $this->memberClassContext($reserved, $sessions->get($c->id), $attendance->get($c->id)),
                    $myRow?->id,
                );
            }),
        ]);
    }

    public function reserve(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $this->member($request);
        $booking = app(ClassBookingService::class);

        // Reglas, bloqueo, cupo y aviso: el dominio de reservas (el mismo que el
        // CRM). Aquí solo se traducen a los textos de esta puerta.
        $resultado = $booking->reserveForMember($member, $myClass);

        if (! $resultado->ok) {
            if ($resultado->outcome === ClassBookingService::NOT_IN_PLAN) {
                return $this->classesNotInPlanResponse();
            }

            return response()->json(['message' => match ($resultado->outcome) {
                ClassBookingService::ALREADY => 'Ya tienes una reserva para esta clase.',
                ClassBookingService::FULL => 'Clase completa.',
                ClassBookingService::OCCURRENCE_LIVE => 'La clase ya está en curso.',
                ClassBookingService::OCCURRENCE_CLOSED => 'La clase ya finalizó.',
                ClassBookingService::OCCURRENCE_PAST => 'Esta clase ya pasó.',
                default => 'Clase no disponible para reservas.',
            }], 422);
        }

        // Notificación de clase reservada (ADITIVO; no afecta la reserva), por
        // SESIÓN: cada reserva de cada semana se avisa.
        $notifier = app(NotificationService::class);
        $notifier->notifyClassReserved($member, $myClass, $resultado->date);

        $booked = $booking->bookedCount($myClass, $resultado->date);
        if ($booked >= (int) $myClass->max_capacity) {
            $notifier->notifyClassFull($myClass, $resultado->date);
        }

        $myClass->reservations_count = $booked;
        $myClass->load('trainer:id,full_name');

        return response()->json(['data' => $this->resourceFor($myClass, $member, true)]);
    }

    public function cancel(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $this->member($request);

        // "Organizar mi semana" puede cancelar una OCURRENCIA concreta (futura)
        // enviando `session_date`. Sin él = comportamiento histórico: la próxima
        // ocurrencia del ciclo (+ legacy sin fecha). Aditivo: conserva las demás
        // reservas futuras de la misma clase.
        $data = $request->validate(['session_date' => ['nullable', 'date']]);
        $explicit = isset($data['session_date'])
            ? Carbon::parse($data['session_date'])->toDateString()
            : null;

        $booking = app(ClassBookingService::class);
        $resultado = $booking->cancelForMember($member, $myClass, $explicit);

        if (! $resultado->ok) {
            return response()->json(['message' => match ($resultado->outcome) {
                ClassBookingService::OCCURRENCE_CLOSED => 'La clase ya finalizó.',
                ClassBookingService::OCCURRENCE_LIVE => 'La clase ya está en curso.',
                default => 'No tienes reserva en esta clase.',
            }], 422);
        }

        // Notificación de reserva cancelada (ADITIVO; no afecta la cancelación).
        app(NotificationService::class)->notifyClassReservationCancelled($member, $myClass);

        $myClass->reservations_count = $booking->bookedCount($myClass, $resultado->date);
        $myClass->load('trainer:id,full_name');

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
        // "Hoy" operativo en zona del gimnasio (no UTC) para decidir el día vencido.
        $todayStr = Carbon::today(self::TZ)->toDateString();

        $classes = MyClass::query()
            ->where('status', 'active')
            ->where('allow_online_booking', true)
            ->with('trainer:id,full_name')
            ->get();

        $classIds = $classes->pluck('id');
        // Por día (`whereDate`) y no `whereBetween`: en SQLite la fecha se guarda
        // con hora y el domingo quedaba fuera del rango.
        $desde = $weekStart->toDateString();
        $hasta = $weekEnd->toDateString();
        $enLaSemana = fn ($q) => $q->whereDate('session_date', '>=', $desde)->whereDate('session_date', '<=', $hasta);

        // Reservas del miembro (con id, para reservation_id) por (clase, fecha)
        // dentro de la semana, en bloque. Y las heredadas sin fecha, que ocupan
        // TODAS las sesiones de su clase (como en el cupo): sin ellas el plan
        // ofrecía «Reservar» en una clase que el socio ya tenía y la reserva
        // contestaba «ya reservada».
        $misFilas = ClassReservation::where('member_id', $member->id)
            ->whereIn('class_id', $classIds)
            ->where(fn ($q) => $q->whereNull('session_date')->orWhere($enLaSemana))
            ->get(['id', 'class_id', 'session_date']);
        $myReserved = $misFilas->whereNotNull('session_date')
            ->keyBy(fn ($r) => $r->class_id.'|'.$r->session_date->toDateString());
        $myLegacy = $misFilas->whereNull('session_date')->keyBy('class_id');

        // Asistencia del miembro por (clase, fecha) de la semana: present/late/absent.
        // Es el historial real (sobrevive aunque la reserva se limpie al renovar).
        $myAttendance = ClassAttendance::where('member_id', $member->id)
            ->whereIn('class_id', $classIds)
            ->where($enLaSemana)
            ->get(['class_id', 'session_date', 'status'])
            ->keyBy(fn ($a) => $a->class_id.'|'.optional($a->session_date)->toDateString());

        // Cupo por (clase, fecha) con la MISMA regla que al reservar: reservas
        // de esa fecha + heredadas sin fecha (antes estas no contaban aquí).
        $conteos = app(ClassBookingService::class)->countsByDate(
            $classIds->map(fn ($id) => (int) $id)->all(),
            $weekStart->toDateString(),
            $weekEnd->toDateString(),
        );

        $sessions = ClassSession::whereIn('class_id', $classIds)
            ->where($enLaSemana)
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
            $booked = ($conteos[$class->id][$dateStr] ?? 0) + ($conteos[$class->id]['*'] ?? 0);
            $capacity = (int) $class->max_capacity;
            $available = max(0, $capacity - $booked);
            $session = $sessions->get($key);
            $reservation = $myReserved->get($key) ?? $myLegacy->get($class->id);
            $isReserved = $reservation !== null;
            $attendanceStatus = $myAttendance->get($key)?->status; // present|late|absent|null

            // Estado OFICIAL desde la SESIÓN (entrenador/backend), igual que Clases
            // normal y el detalle. NUNCA se finaliza/cierra por hora local: una
            // clase en curso (live) o futura no debe verse cerrada por reloj.
            $sessionStatus = $this->sessionStatusLabel($session); // scheduled|live|finished
            $isClosed = $sessionStatus === 'finished'; // cerrada/finalizada por el entrenador
            $isLive = $sessionStatus === 'live';       // en curso (entrenador la inició)
            $isFull = $available <= 0;

            // "Vencida" por DÍA OPERATIVO (Bogotá), NUNCA por hora: el cierre por
            // tiempo lo controla el entrenador. Un día anterior a hoy ya pasó; el
            // día de hoy y los futuros NO se bloquean por reloj. Excluye live/closed.
            $isPast = ! $isLive && ! $isClosed && $dateStr < $todayStr;

            // Estado RESUELTO (fuente única, prioridad fija): finalizada/asistencia
            // > en curso > reservada > llena > vencida > pocos cupos > disponible.
            $state = ClassStateResolver::displayState($sessionStatus, $isReserved, $attendanceStatus, $available, $isPast);
            $reservationStatus = ClassStateResolver::reservationStatus($isReserved, $attendanceStatus);
            $canReserve = ClassStateResolver::canReserve($sessionStatus, $isReserved, $available, $isPast);
            $canCancel = ClassStateResolver::canCancel($sessionStatus, $isReserved, $isPast);

            // Razón de bloqueo trazable (null si es seleccionable). Fuente única.
            $blockedReason = match (true) {
                $isClosed => 'closed',
                $isLive => 'live',
                $isReserved => 'reserved',
                $isFull => 'full',
                $isPast => 'past',
                default => null,
            };

            $days[$idx]['classes'][] = [
                'reservation_key' => $key,
                'class_id' => (int) $class->id,
                'session_date' => $dateStr,
                'name' => $class->name,
                'type' => $class->type,
                // El entrenador asignado manda (ver ClassResource).
                'instructor' => $class->trainer?->full_name ?? $class->instructor ?? '',
                'start_time' => $class->start_time,
                'end_time' => $class->end_time,
                'date_time' => $occ->toIso8601String(),
                'duration_minutes' => $class->duration_minutes,
                'location' => $class->location,
                'total_spots' => $capacity,
                'booked_spots' => $booked,
                'available_spots' => $available,
                'is_reserved' => $isReserved,
                'is_full' => $isFull,
                'is_past' => $isPast,
                'is_closed' => $isClosed,
                'is_live' => $isLive,
                'is_finished' => $isClosed, // cierre del entrenador = finalizada (mismo origen)
                'reservation_id' => $reservation?->id,
                'session_status' => $sessionStatus,          // scheduled|live|finished
                'reservation_status' => $reservationStatus,   // none|reserved|attended|late|absent
                'attendance_status' => $attendanceStatus,    // present|late|absent|null
                'display_state' => $state,                  // estado resuelto (alias de state)
                'state' => $state,                  // back-compat con clientes previos
                'blocked_reason' => $blockedReason,
                'can_reserve' => $canReserve,
                'can_cancel' => $canCancel,
            ];
        }

        foreach ($days as $i => $day) {
            usort($days[$i]['classes'], fn ($a, $b) => strcmp((string) $a['start_time'], (string) $b['start_time']));
        }

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'days' => array_values($days),
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
        $booking = app(ClassBookingService::class);

        if (! $booking->hasClassesFeature($member)) {
            return $this->classesNotInPlanResponse();
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.class_id' => ['required', 'integer'],
            'items.*.session_date' => ['required', 'date'],
        ]);

        $results = [
            'reserved' => [], 'already' => [], 'full' => [],
            'unavailable' => [], 'past' => [], 'closed' => [], 'live' => [], 'conflict' => [],
        ];
        $acceptedSlots = []; // [date => [[start,end], ...]] para detectar conflictos en la selección

        // Un aviso por envío, no uno por reserva: cada aviso hace que cada app
        // abierta vuelva a pedir sus clases.
        app(ClassEventBus::class)->batch(function () use ($data, $booking, $member, &$results, &$acceptedSlots): void {
            foreach ($data['items'] as $item) {
                $class = MyClass::find($item['class_id']);
                $date = Carbon::parse($item['session_date'])->toDateString();
                $tag = ['class_id' => (int) $item['class_id'], 'session_date' => $date, 'name' => $class?->name];

                // El plan del socio ya se comprobó arriba; aquí solo cuenta la clase.
                // Y la fecha tiene que ser un día REAL de la clase: sin esto, una
                // llamada directa reservaba un miércoles una clase de los lunes, en
                // una fila que no sale en ningún roster.
                if (! $class
                    || ! $booking->isOpenFor($class, ClassBookingService::CHANNEL_MEMBER)
                    || ! $booking->isOccurrence($class, $date)) {
                    $results['unavailable'][] = $tag;

                    continue;
                }

                $occStart = Carbon::parse($date.' '.($class->start_time ?: '00:00'));
                $occEnd = $this->occurrenceEnd($class, $occStart);

                // Estado OFICIAL de la sesión (entrenador/backend) con la MISMA
                // precedencia que el plan semanal: cerrada → en curso → vencida (por
                // DÍA operativo, no por hora). Así una clase en curso que ya pasó su
                // hora no se etiqueta "vencida". La regla vive en el servicio; los
                // motivos son las mismas claves que este resumen publica.
                $bloqueo = $booking->occurrenceBlock($class, $date);
                if ($bloqueo !== null) {
                    $results[$bloqueo][] = $tag;

                    continue;
                }

                // Conflicto de horario dentro de la propia selección (mismo día, solape).
                if ($this->conflictsWithSelection($acceptedSlots, $date, $occStart, $occEnd)) {
                    $results['conflict'][] = $tag;

                    continue;
                }

                $outcome = $booking->reserveOccurrence($member, $class, $date);
                $results[$outcome][] = $tag;

                if ($outcome === ClassBookingService::RESERVED) {
                    $acceptedSlots[$date][] = [$occStart, $occEnd];
                    $booked = $booking->bookedCount($class, $date);
                    if ($booked >= (int) $class->max_capacity) {
                        app(NotificationService::class)->notifyClassFull($class, $date);
                    }
                }
            }
        });

        $reservedCount = count($results['reserved']);
        $failedCount = count($results['already']) + count($results['full']) + count($results['unavailable'])
            + count($results['past']) + count($results['closed']) + count($results['live'])
            + count($results['conflict']);

        // Notificación interna de confirmación (resumen del plan semanal).
        app(NotificationService::class)->notifyWeeklyPlanConfirmed($member, $reservedCount, $failedCount);

        return response()->json([
            'summary' => [
                'reserved' => $reservedCount,
                'failed' => $failedCount,
                'already' => count($results['already']),
                'full' => count($results['full']),
                'unavailable' => count($results['unavailable']),
                'past' => count($results['past']),
                'closed' => count($results['closed']),
                'live' => count($results['live']),
                'conflict' => count($results['conflict']),
            ],
            'results' => $results,
        ]);
    }

    /**
     * AUTO CHECK-IN: el miembro marca su propia asistencia (presente) cuando la
     * clase está EN CURSO. Requiere reserva de ESA sesión; si el entrenador ya
     * marcó la asistencia, se respeta (ver ClassAttendanceService::selfCheckIn).
     */
    public function checkIn(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $this->member($request);
        $resultado = app(ClassAttendanceService::class)->selfCheckIn($member, $myClass);

        [$codigo, $mensaje] = ClassAttendanceService::checkInReply($resultado);
        if ($codigo !== 200) {
            return response()->json(['message' => $mensaje], $codigo);
        }

        // Cupo de ESA sesión, no la suma de todas las semanas.
        $myClass->reservations_count = app(ClassBookingService::class)->bookedCount($myClass, $resultado['date']);
        $myClass->load('trainer:id,full_name');

        return response()->json([
            'message' => $mensaje,
            'data' => $this->resourceFor($myClass, $member, true),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** ClassResource con el contexto del miembro recalculado (reserva/cancela/etc.). */
    private function resourceFor(MyClass $class, Member $member, bool $reserved): ClassResource
    {
        $session = $this->currentClassSession($class);
        $sessionDate = optional($session?->session_date)->toDateString() ?? $this->operationalToday()->toDateString();
        $attendance = ClassAttendance::where('class_id', $class->id)
            ->where('member_id', $member->id)
            ->whereDate('session_date', $sessionDate)
            ->first();

        return new ClassResource($class, $reserved, $this->memberClassContext($reserved, $session, $attendance));
    }

    /** Lunes (00:00) de la semana pedida (?week_start) o la semana en curso. */
    private function resolveWeekStart(Request $request): Carbon
    {
        $raw = $request->query('week_start');
        $base = $raw !== null ? Carbon::parse((string) $raw, self::TZ) : Carbon::now(self::TZ);

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
