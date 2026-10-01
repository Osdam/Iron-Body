<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClassAttendance;
use App\Models\ClassSession;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Services\Classes\ClassBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Supervisión de cumplimiento de horarios de clases (CRM admin). Muestra, por
 * sesión real, el horario PROGRAMADO (classes.start_time/end_time) frente al
 * horario REAL en que el entrenador inició/finalizó (con rostro). Patrón /admin/*
 * del CRM. Solo lectura.
 */
class ClassSupervisionController extends Controller
{
    public function __construct(private readonly ClassBookingService $booking) {}

    public function index(Request $request): JsonResponse
    {
        // Una fecha mal formada daba 500 al parsearla.
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'trainer_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
        ]);

        // Días del gimnasio: con el reloj UTC, desde las 19:00 «hoy» era mañana.
        $hoy = Carbon::parse($this->booking->todayDate());
        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->toDateString()
            : $hoy->copy()->subDays(7)->toDateString();
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->toDateString()
            : $hoy->toDateString();

        // Un tramo acotado: sin él, un rango de años cargaba en memoria toda la
        // asistencia de ese periodo.
        if ($from > $to) {
            return response()->json(['message' => 'La fecha inicial no puede ser posterior a la final.', 'errors' => ['from' => ['La fecha inicial no puede ser posterior a la final.']]], 422);
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 93) {
            return response()->json(['message' => 'Consulta como mucho 93 días seguidos.', 'errors' => ['to' => ['Consulta como mucho 93 días seguidos.']]], 422);
        }

        $query = ClassSession::query()
            ->whereDate('session_date', '>=', $from)
            ->whereDate('session_date', '<=', $to)
            ->with(['gymClass.trainer', 'startedByTrainer']);

        if ($request->filled('trainer_id')) {
            $query->whereHas('gymClass', fn ($q) => $q->where('trainer_id', $request->query('trainer_id')));
        }
        if ($request->filled('class_id')) {
            $query->where('class_id', $request->query('class_id'));
        }

        $sessions = $query->orderByDesc('session_date')
            ->orderByDesc('started_at')
            ->limit(500)
            ->get();

        // Cupo y asistencia de TODAS las sesiones en dos consultas, no tres por
        // fila (hasta 1.500 con el límite de 500 sesiones).
        $classIds = $sessions->pluck('class_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
        $reservas = $this->booking->countsByDate($classIds, $from, $to);
        $asistencia = $this->attendanceBySession($classIds, $from, $to);

        $rows = $sessions->map(fn (ClassSession $s) => $this->row($s, $reservas, $asistencia));

        return response()->json(['ok' => true, 'data' => $rows->values()]);
    }

    /**
     * @param  list<int>  $classIds
     * @return array<string, array{roster:int, attended:int}> "clase|fecha" => cifras
     */
    private function attendanceBySession(array $classIds, string $from, string $to): array
    {
        if ($classIds === []) {
            return [];
        }

        $out = [];
        ClassAttendance::query()
            ->whereIn('class_id', $classIds)
            ->whereDate('session_date', '>=', $from)
            ->whereDate('session_date', '<=', $to)
            ->get(['class_id', 'member_id', 'session_date', 'status'])
            ->groupBy(fn (ClassAttendance $a) => $a->class_id.'|'.Carbon::parse($a->session_date)->toDateString())
            ->each(function ($filas, string $clave) use (&$out): void {
                $out[$clave] = [
                    'roster' => $filas->pluck('member_id')->unique()->count(),
                    'attended' => $filas->whereIn('status', ['present', 'late'])->count(),
                ];
            });

        return $out;
    }

    /**
     * @param  array<int, array<string, int>>  $reservas
     * @param  array<string, array{roster:int, attended:int}>  $asistencia
     */
    private function row(ClassSession $s, array $reservas, array $asistencia): array
    {
        /** @var MyClass|null $class */
        $class = $s->gymClass;
        /** @var Trainer|null $trainer */
        $trainer = $class?->trainer;

        $scheduledStart = $class?->start_time;
        $scheduledEnd = $class?->end_time;

        // Personas: inscritos y cuántos asistieron en esa sesión.
        // "Inscritos" = máximo entre las reservas de ESA sesión (las de su fecha
        // y las heredadas sin fecha, como el cupo) y la lista de asistencia de esa
        // fecha. Así el historial sigue siendo correcto aunque la clase ya haya
        // RENOVADO (la renovación limpia reservas pero conserva la asistencia).
        // Antes se contaban TODAS las reservas de la clase, de cualquier semana.
        $sessionDate = optional($s->session_date)->toDateString();
        $porFecha = $reservas[(int) $s->class_id] ?? [];
        $reservadas = ($porFecha[$sessionDate] ?? 0) + ($porFecha['*'] ?? 0);
        $cifras = $asistencia[$s->class_id.'|'.$sessionDate] ?? ['roster' => 0, 'attended' => 0];
        $enrolled = max($reservadas, $cifras['roster']);
        $attended = $cifras['attended'];

        // Puntualidad: minutos de diferencia entre el inicio real y el programado,
        // este a la hora del gimnasio. Leído en UTC, un inicio puntual salía con
        // 300 minutos de retraso.
        $startDelayMin = null;
        $scheduled = ($class && $sessionDate) ? $this->booking->occurrenceStart($class, $sessionDate) : null;
        if ($scheduled && $s->started_at) {
            $startDelayMin = (int) round($scheduled->diffInSeconds($s->started_at, false) / 60);
        }

        return [
            'class_id' => $s->class_id,
            'class_name' => $class?->name,
            'trainer_id' => $trainer?->id,
            'trainer_name' => $trainer?->full_name,
            'started_by_name' => $s->startedByTrainer?->full_name,
            'session_date' => optional($s->session_date)->toDateString(),
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
            'real_start' => optional($s->started_at)->toIso8601String(),
            'real_end' => optional($s->ended_at)->toIso8601String(),
            'enrolled' => $enrolled,
            'attended' => $attended,
            'start_delay_minutes' => $startDelayMin, // + tarde, - antes
            'start_face_verified' => (bool) $s->start_face_verified,
            'end_face_verified' => (bool) $s->end_face_verified,
            'is_live' => $s->isLive(),
            'completed' => $s->ended_at !== null,
        ];
    }
}
