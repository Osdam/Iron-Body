<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Exceptions\AttendanceException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrainerClassResource;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Services\Classes\ClassBookingService;
use App\Services\Trainer\ClassAttendanceService;
use App\Services\Trainer\ClassSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Agenda y asistencia de clases del entrenador FUNCIONAL. Mínimo privilegio: un
 * entrenador solo ve y gestiona SUS propias clases (classes.trainer_id). La
 * autorización se compone: feature flag + auth.trainer + permiso por acción +
 * propiedad de la clase + participante inscrito.
 */
class TrainerClassController extends Controller
{
    // Fecha de la sesión, cupo y lista salen de ClassBookingService: la MISMA
    // fuente que "Clases", "Organizar mi semana" y el CRM.

    public function __construct(
        private readonly ClassAttendanceService $attendance,
        private readonly ClassSessionService $sessions,
        private readonly ClassBookingService $booking,
    ) {}

    /** Agenda: las clases del entrenador autenticado, con aforo real. */
    public function index(Request $request): JsonResponse
    {
        $trainer = $this->trainer($request);

        // Orden de CALENDARIO (lunes → domingo) y hora. `orderBy('day_of_week')`
        // ordenaba el nombre alfabéticamente: Domingo, Jueves, Lunes…
        $classes = MyClass::query()
            ->where('trainer_id', $trainer->getKey())
            ->get()
            ->sortBy(fn (MyClass $c) => sprintf('%d %s', MyClass::WEEK_DAY_INDEX[$c->day_of_week] ?? 9, (string) $c->start_time))
            ->values();

        // Inscritos/cupo = reservas de la OCURRENCIA operativa real de cada clase
        // (su session_date vigente + legacy sin fecha), NO todas las reservas
        // históricas. Antes withCount('reservations') sumaba todas las semanas.
        foreach ($classes as $class) {
            $date = $this->booking->operationalDate($class);
            $class->reservations_count = $this->booking->bookedCount($class, $date);
            $class->setAttribute('booked_session_date', $date);
        }

        return response()->json([
            'ok' => true,
            'data' => TrainerClassResource::collection($classes),
        ]);
    }

    /**
     * Detalle de una clase propia + participantes con su asistencia.
     *
     * La fecha la resuelve el dominio de reservas: la que se pida si es un día
     * real de la clase y, si no, la PRÓXIMA ocurrencia, que es la que reservan
     * la app y el CRM. La app del entrenador pide la fecha de hoy del
     * dispositivo; consultar un miércoles una clase de los lunes devolvía
     * «Sin inscritos» mientras la app de los socios contaba 1/20.
     * `session_date` en la respuesta dice qué sesión se está enseñando.
     */
    public function show(Request $request, MyClass $class): JsonResponse
    {
        $this->assertOwner($this->trainer($request), $class);
        $pedida = $request->query('session_date');
        $sessionDate = Carbon::parse($this->sessionToShow($class, is_string($pedida) ? $pedida : null));
        // Inscritos/cupo de la SESIÓN que se está viendo (coherente con la lista
        // de participantes de esa misma fecha), no el histórico de la clase.
        $class->reservations_count = $this->booking->bookedCount($class, $sessionDate->toDateString());
        $class->setAttribute('booked_session_date', $sessionDate->toDateString());

        return response()->json([
            'ok' => true,
            'data' => new TrainerClassResource($class),
            'session_date' => $sessionDate->toDateString(),
            'requested_session_date' => is_string($pedida) ? $pedida : null,
            'is_today' => $sessionDate->toDateString() === $this->booking->todayDate(),
            'participants' => $this->attendance->participants($class, $sessionDate),
            'session' => $this->sessions->forDate($class, $sessionDate)?->toPublicArray(),
        ]);
    }

    public function markAttendance(Request $request, MyClass $class): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwner($trainer, $class);

        $data = $this->validateAttendance($request);

        try {
            $this->attendance->mark(
                $class,
                $data['member_id'],
                Carbon::parse($data['session_date']),
                $data['status'],
                $trainer,
            );
        } catch (AttendanceException $e) {
            return $this->error($e);
        }

        // El aviso (al socio marcado, al entrenador y al CRM) lo emite el
        // servicio de asistencia, una vez y tras guardar.

        return $this->participantsResponse($class, Carbon::parse($data['session_date']), 'Asistencia registrada.');
    }

    public function correctAttendance(Request $request, MyClass $class): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwner($trainer, $class);

        $data = $this->validateAttendance($request, withNote: true);

        try {
            $this->attendance->correct(
                $class,
                $data['member_id'],
                Carbon::parse($data['session_date']),
                $data['status'],
                $data['note'] ?? null,
                $trainer,
            );
        } catch (AttendanceException $e) {
            return $this->error($e);
        }

        return $this->participantsResponse($class, Carbon::parse($data['session_date']), 'Asistencia corregida.');
    }

    /**
     * Inicia la clase del día: registra la hora real, exige rostro del entrenador
     * (`face_verified`) y avisa a los inscritos. Idempotente (no reenvía avisos).
     */
    public function startSession(Request $request, MyClass $class): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwner($trainer, $class);

        $data = $request->validate([
            'session_date' => ['nullable', 'date'],
            'face_verified' => ['required', 'boolean'],
        ]);

        if (! $data['face_verified']) {
            return response()->json([
                'ok' => false,
                'code' => 'face_required',
                'message' => 'Debes verificar tu rostro para iniciar la clase.',
            ], 422);
        }

        // Se inicia la sesión de HOY en el gimnasio (Bogotá), nunca la de
        // `now()` en UTC: pasadas las 19:00 esa ya es mañana y la sesión quedaba
        // guardada en otra fecha, invisible para la app y para la lista.
        $fecha = $this->booking->resolveSessionDate($class, $data['session_date'] ?? null);
        if ($fecha !== $this->booking->todayDate()) {
            return response()->json([
                'ok' => false,
                'code' => 'not_today',
                'message' => 'Esta clase no se dicta hoy: se inicia el día de la clase.',
                'session_date' => $fecha,
            ], 422);
        }
        $session = $this->sessions->start($class, $trainer, Carbon::parse($fecha), true);

        return response()->json([
            'ok' => true,
            'message' => 'Clase iniciada.',
            'session' => $session->toPublicArray(),
        ]);
    }

    /** Finaliza la clase del día: registra la hora real (también con rostro). */
    public function endSession(Request $request, MyClass $class): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwner($trainer, $class);

        $data = $request->validate([
            'session_date' => ['nullable', 'date'],
            'face_verified' => ['required', 'boolean'],
        ]);

        if (! $data['face_verified']) {
            return response()->json([
                'ok' => false,
                'code' => 'face_required',
                'message' => 'Debes verificar tu rostro para finalizar la clase.',
            ], 422);
        }

        // Se finaliza la sesión EN CURSO. Con la fecha pedida si esa es la que
        // está en curso; si no —una clase que pasa de la medianoche y el
        // dispositivo ya dice «mañana»—, la que el entrenador tenga abierta.
        $pedida = $this->booking->plainDate($data['session_date'] ?? null);
        $sessionDate = $pedida !== null && $this->sessions->isLive($class, Carbon::parse($pedida))
            ? Carbon::parse($pedida)
            : ($this->sessions->liveDate($class) ?? Carbon::parse($pedida ?? $this->booking->todayDate()));
        $session = $this->sessions->end($class, $trainer, $sessionDate, true);

        if ($session === null) {
            return response()->json([
                'ok' => false,
                'code' => 'not_started',
                'message' => 'La clase no se ha iniciado todavía.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Clase finalizada.',
            'session' => $session->toPublicArray(),
        ]);
    }

    /**
     * La sesión del detalle: la pedida si es un día real de la clase; si no,
     * la que el entrenador tiene EN CURSO (de hoy o de ayer) y, si no hay, la
     * próxima ocurrencia.
     *
     * Una clase que pasa de la medianoche: a las 00:10 el teléfono pide la
     * fecha del martes, que no es día de la clase, y sin esto se enseñaba la
     * del lunes siguiente mientras la de anoche seguía abierta.
     */
    private function sessionToShow(MyClass $class, ?string $pedida): string
    {
        $fecha = $this->booking->plainDate($pedida);
        if ($fecha !== null && $this->booking->isOccurrence($class, $fecha)) {
            return $fecha;
        }

        $enCurso = $this->sessions->liveDate($class)?->toDateString();
        if ($enCurso !== null && $enCurso >= $this->booking->liveSince()) {
            return $enCurso;
        }

        return $this->booking->operationalDate($class);
    }

    private function validateAttendance(Request $request, bool $withNote = false): array
    {
        return $request->validate([
            'member_id' => ['required', 'integer'],
            'session_date' => ['required', 'date'],
            'status' => ['required', 'string', 'in:present,absent,late'],
            'note' => [$withNote ? 'nullable' : 'prohibited', 'string', 'max:255'],
        ]);
    }

    private function participantsResponse(MyClass $class, Carbon $sessionDate, string $message): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'message' => $message,
            'session_date' => $sessionDate->toDateString(),
            'is_today' => $sessionDate->toDateString() === $this->booking->todayDate(),
            'participants' => $this->attendance->participants($class, $sessionDate),
        ]);
    }

    private function trainer(Request $request): Trainer
    {
        return $request->attributes->get('auth_trainer');
    }

    private function assertOwner(Trainer $trainer, MyClass $class): void
    {
        abort_unless((int) $class->trainer_id === (int) $trainer->getKey(), 403, 'Esta clase no es tuya.');
    }

    private function error(AttendanceException $e): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'attendance_error',
            'message' => $e->getMessage(),
        ], $e->status);
    }
}
