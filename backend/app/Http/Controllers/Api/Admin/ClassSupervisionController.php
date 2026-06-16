<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\MyClass;
use App\Models\Trainer;
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
    public function index(Request $request): JsonResponse
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->toDateString()
            : Carbon::now()->subDays(7)->toDateString();
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->toDateString()
            : Carbon::now()->toDateString();

        $query = ClassSession::query()
            ->whereBetween('session_date', [$from, $to])
            ->with(['gymClass.trainer', 'startedByTrainer']);

        if ($request->filled('trainer_id')) {
            $query->whereHas('gymClass', fn ($q) => $q->where('trainer_id', $request->query('trainer_id')));
        }
        if ($request->filled('class_id')) {
            $query->where('class_id', $request->query('class_id'));
        }

        $rows = $query->orderByDesc('session_date')
            ->orderByDesc('started_at')
            ->limit(500)
            ->get()
            ->map(fn (ClassSession $s) => $this->row($s));

        return response()->json(['ok' => true, 'data' => $rows->values()]);
    }

    private function row(ClassSession $s): array
    {
        /** @var MyClass|null $class */
        $class = $s->gymClass;
        /** @var Trainer|null $trainer */
        $trainer = $class?->trainer;

        $scheduledStart = $class?->start_time;
        $scheduledEnd = $class?->end_time;

        // Puntualidad: minutos de diferencia entre el inicio real y el programado.
        $startDelayMin = null;
        if ($scheduledStart && $s->started_at) {
            $scheduled = Carbon::parse($s->session_date->toDateString().' '.$scheduledStart);
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
            'start_delay_minutes' => $startDelayMin, // + tarde, - antes
            'start_face_verified' => (bool) $s->start_face_verified,
            'end_face_verified' => (bool) $s->end_face_verified,
            'is_live' => $s->isLive(),
            'completed' => $s->ended_at !== null,
        ];
    }
}
