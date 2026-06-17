<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Exceptions\AttendanceException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrainerClassResource;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Services\RealtimeEvents;
use App\Services\Trainer\ClassAttendanceService;
use App\Services\Trainer\ClassSessionService;
use App\Services\Trainer\TrainerRealtimeEvents;
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
    public function __construct(
        private readonly ClassAttendanceService $attendance,
        private readonly ClassSessionService $sessions,
    ) {}

    /** Agenda: las clases del entrenador autenticado, con aforo real. */
    public function index(Request $request): JsonResponse
    {
        $trainer = $this->trainer($request);

        $classes = MyClass::query()
            ->where('trainer_id', $trainer->getKey())
            ->withCount('reservations')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => TrainerClassResource::collection($classes),
        ]);
    }

    /** Detalle de una clase propia + participantes con su asistencia del día. */
    public function show(Request $request, MyClass $class): JsonResponse
    {
        $this->assertOwner($this->trainer($request), $class);
        $sessionDate = $this->sessionDate($request);
        $class->loadCount('reservations');

        return response()->json([
            'ok' => true,
            'data' => new TrainerClassResource($class),
            'session_date' => $sessionDate->toDateString(),
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

        // Realtime: el miembro ve "Presente/Tarde/Ausente" en Clases y en
        // "Organizar mi semana" sin recargar; el portal del entrenador se refresca.
        $this->emitAttendanceRealtime($class);

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

        $this->emitAttendanceRealtime($class);

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

        $sessionDate = isset($data['session_date']) ? Carbon::parse($data['session_date']) : Carbon::now();
        $session = $this->sessions->start($class, $trainer, $sessionDate, true);

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

        $sessionDate = isset($data['session_date']) ? Carbon::parse($data['session_date']) : Carbon::now();
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
            'participants' => $this->attendance->participants($class, $sessionDate),
        ]);
    }

    /** Señal real-time a los miembros (Clases/semana) y al portal del entrenador. */
    private function emitAttendanceRealtime(MyClass $class): void
    {
        RealtimeEvents::classesChanged();
        if ($class->trainer_id) {
            TrainerRealtimeEvents::emit((int) $class->trainer_id, TrainerRealtimeEvents::ATTENDANCE, ['classes']);
        }
    }

    private function trainer(Request $request): Trainer
    {
        return $request->attributes->get('auth_trainer');
    }

    private function assertOwner(Trainer $trainer, MyClass $class): void
    {
        abort_unless((int) $class->trainer_id === (int) $trainer->getKey(), 403, 'Esta clase no es tuya.');
    }

    private function sessionDate(Request $request): Carbon
    {
        $raw = $request->query('session_date');

        return $raw !== null ? Carbon::parse((string) $raw) : Carbon::now();
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
