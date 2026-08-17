<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\Member;
use App\Models\Routine;
use App\Models\RoutineCompletion;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionSet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registra una sesión de entrenamiento ejecutada y todo lo que se deriva de
 * ella: series, volumen, duración, racha y récords personales.
 *
 * IDEMPOTENCIA
 * ------------
 * La app genera un `client_session_id` al EMPEZAR el entrenamiento y lo reenvía
 * en cada intento. Si la sesión ya existe se devuelve tal cual, sin volver a
 * crear series, sin sumar otra racha y sin reevaluar récords. Así un doble
 * toque, un reintento por timeout o un reenvío tras perder red no pueden
 * duplicar el entrenamiento.
 *
 * COMPATIBILIDAD
 * --------------
 * Se sigue creando la fila de `routine_completions` que ya consumen Progreso,
 * el contexto de IRON IA, las notificaciones y los comandos proactivos. La
 * sesión apunta a ella, de modo que ninguna funcionalidad previa cambia de
 * fuente y ambas vistas quedan trazables entre sí.
 */
class WorkoutSessionService
{
    /** Zona horaria operativa del gimnasio. */
    public const TZ = 'America/Bogota';

    public function __construct(
        private readonly PersonalRecordService $records,
        private readonly WeeklyStreakService $streak,
    ) {}

    /**
     * @param  array{client_session_id:string, routine_id?:string|int|null, routine_name?:string|null, started_at?:string|null, completed_at?:string|null, notes?:string|null, exercises?:array}  $payload
     * @return array{session: WorkoutSession, created: bool, records: \Illuminate\Support\Collection}
     */
    public function complete(Member $member, array $payload): array
    {
        $clientId = trim((string) $payload['client_session_id']);

        // Reentrada: la sesión ya se registró en un intento anterior.
        $existing = WorkoutSession::query()
            ->with('sets')
            ->where('member_id', $member->id)
            ->where('client_session_id', $clientId)
            ->first();

        if ($existing !== null) {
            return ['session' => $existing, 'created' => false, 'records' => collect()];
        }

        $routine = $this->resolveRoutine($member, $payload['routine_id'] ?? null);

        $session = DB::transaction(function () use ($member, $payload, $clientId, $routine): WorkoutSession {
            $completedAt = $this->parseTime($payload['completed_at'] ?? null) ?? CarbonImmutable::now();
            $startedAt = $this->parseTime($payload['started_at'] ?? null);

            // La duración sale de timestamps reales, nunca del cronómetro de la
            // pantalla. Si la app no mandó inicio, no se inventa: queda en 0.
            $duration = 0;
            if ($startedAt !== null) {
                $duration = max(0, $startedAt->diffInSeconds($completedAt));
            }

            $completion = null;
            if ($routine !== null) {
                $completion = RoutineCompletion::create([
                    'member_id' => $member->id,
                    'routine_id' => $routine->id,
                    'completed_at' => $completedAt,
                    'source' => 'app',
                    'notes' => $payload['notes'] ?? null,
                ]);
            }

            $session = WorkoutSession::create([
                'member_id' => $member->id,
                'routine_id' => $routine?->id,
                'routine_completion_id' => $completion?->id,
                'client_session_id' => $clientId,
                'routine_name' => $payload['routine_name'] ?? $routine?->name,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'duration_seconds' => $duration,
                'source' => 'app',
                'notes' => $payload['notes'] ?? null,
            ]);

            $this->storeSets($session, $payload['exercises'] ?? [], $completedAt);
            $this->refreshTotals($session);

            return $session;
        });

        $session->load('sets.exercise');

        // Fuera de la transacción y best-effort: nada de esto puede tumbar un
        // entrenamiento que el socio ya terminó.
        $records = collect();
        try {
            $records = $this->records->evaluateSession($member, $session);
        } catch (\Throwable $e) {
            Log::warning('workout.records_failed', ['session' => $session->id, 'error' => $e->getMessage()]);
        }

        try {
            // Entrenar cuenta como día activo. `touch` es idempotente por
            // (miembro, fecha) en hora de Bogotá: dos rutinas el mismo día no
            // suman dos días de racha.
            $this->streak->touch($member, 'workout');
        } catch (\Throwable $e) {
            Log::warning('workout.streak_failed', ['session' => $session->id, 'error' => $e->getMessage()]);
        }

        if ($session->completion !== null) {
            try {
                app(NotificationService::class)
                    ->notifyRoutineCompleted($member, $session->routine, $session->routine_completion_id);
            } catch (\Throwable $e) {
                Log::warning('workout.notify_failed', ['session' => $session->id, 'error' => $e->getMessage()]);
            }
        }

        return ['session' => $session, 'created' => true, 'records' => $records];
    }

    /**
     * Interpreta un instante enviado por la app. Llega en ISO-8601 CON offset
     * (la app manda su hora local con zona), así que se normaliza a UTC, que es
     * como se almacena. Una fecha ilegible se descarta en vez de romper el
     * registro de un entrenamiento ya terminado.
     */
    private function parseTime(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Solo rutinas propias o asignadas al miembro. */
    private function resolveRoutine(Member $member, string|int|null $routineId): ?Routine
    {
        if (blank($routineId)) {
            return null;
        }

        $routine = Routine::find($routineId);
        if ($routine === null) {
            return null;
        }

        $owns = (int) $routine->member_id === (int) $member->id
            || DB::table('member_routine_assignments')
                ->where('routine_id', $routine->id)
                ->where('member_id', $member->id)
                ->exists();

        return $owns ? $routine : null;
    }

    /**
     * Persiste las series. El nombre del ejercicio se guarda como snapshot y se
     * intenta resolver el id del catálogo por id explícito o por nombre
     * normalizado, para que los récords agrupen bien aunque la rutina traiga el
     * ejercicio incompleto.
     */
    private function storeSets(WorkoutSession $session, array $exercises, CarbonImmutable $completedAt): void
    {
        foreach (array_values($exercises) as $exIndex => $exercise) {
            if (! is_array($exercise)) {
                continue;
            }

            $name = trim((string) ($exercise['name'] ?? ''));
            $key = WorkoutSessionSet::normalizeKey($name);
            $exerciseId = $this->resolveExerciseId($exercise['exercise_id'] ?? null, $name);
            $order = (int) ($exercise['order'] ?? $exIndex);

            foreach (array_values($exercise['sets'] ?? []) as $setIndex => $set) {
                if (! is_array($set)) {
                    continue;
                }

                WorkoutSessionSet::create([
                    'workout_session_id' => $session->id,
                    'exercise_id' => $exerciseId,
                    'exercise_name' => $name !== '' ? $name : 'Ejercicio',
                    'exercise_key' => $key !== '' ? $key : 'ejercicio',
                    'exercise_order' => $order,
                    'set_number' => (int) ($set['set_number'] ?? $setIndex + 1),
                    'reps' => isset($set['reps']) ? max(0, (int) $set['reps']) : null,
                    'weight_kg' => isset($set['weight_kg']) ? max(0, (float) $set['weight_kg']) : null,
                    'rpe' => isset($set['rpe']) ? max(0, min(10, (int) $set['rpe'])) : null,
                    'completed' => (bool) ($set['completed'] ?? false),
                    'performed_at' => $completedAt,
                ]);
            }
        }
    }

    private function resolveExerciseId(string|int|null $explicitId, string $name): ?int
    {
        if (filled($explicitId) && Exercise::whereKey($explicitId)->exists()) {
            return (int) $explicitId;
        }

        if ($name === '') {
            return null;
        }

        return Exercise::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id');
    }

    /** Recalcula y persiste los totales desde las series ya guardadas. */
    private function refreshTotals(WorkoutSession $session): void
    {
        $sets = $session->sets()->get();

        $volume = $sets->sum(fn (WorkoutSessionSet $s) => $s->volume());

        $session->forceFill([
            'total_volume_kg' => round((float) $volume, 2),
            // "Series" cuenta las ejecutadas, no las prescritas.
            'total_sets' => $sets->where('completed', true)->count(),
            'total_exercises' => $sets->pluck('exercise_key')->unique()->count(),
        ])->save();
    }

    /**
     * Resumen de una sesión ya registrada (para reabrir la pantalla o
     * consultarla más tarde).
     */
    public function findForMember(Member $member, string $clientSessionId): ?WorkoutSession
    {
        return WorkoutSession::query()
            ->with('sets.exercise')
            ->where('member_id', $member->id)
            ->where('client_session_id', $clientSessionId)
            ->first();
    }
}
