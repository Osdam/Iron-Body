<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoutineResource;
use App\Models\MemberRoutineAssignment;
use App\Models\Plan;
use App\Models\Routine;
use App\Models\RoutineCompletion;
use App\Models\RoutineExercise;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppRoutineController extends Controller
{
    /**
     * Rutinas asignadas al miembro por el administrador,
     * incluyendo las que el propio miembro creó con is_assigned = true.
     */
    public function assigned(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $viaAssignment = Routine::whereHas(
            'assignments',
            fn ($q) => $q->where('member_id', $member->id)
        )->with(['routineExercises.exercise'])->get();

        $viaMemberId = Routine::where('member_id', $member->id)
            ->where('is_assigned', true)
            ->with(['routineExercises.exercise'])
            ->get();

        $routines = $viaAssignment->merge($viaMemberId)->unique('id')->values();

        return response()->json([
            'ok'   => true,
            'data' => RoutineResource::collection($routines),
        ]);
    }

    /**
     * "Entrenamiento de hoy" del miembro autenticado. Toma sus rutinas
     * asignadas (mismas que assigned()) y selecciona una de forma determinista
     * por día, de modo que el miembro recorra su programa día a día. Sin
     * rutinas asignadas devuelve has_workout=false (estado vacío real, sin mock).
     */
    public function today(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $viaAssignment = Routine::whereHas(
            'assignments',
            fn ($q) => $q->where('member_id', $member->id)
        )->with(['routineExercises.exercise'])->get();

        $viaMemberId = Routine::where('member_id', $member->id)
            ->where('is_assigned', true)
            ->with(['routineExercises.exercise'])
            ->get();

        $routines = $viaAssignment->merge($viaMemberId)
            ->unique('id')->sortBy('id')->values();

        if ($routines->isEmpty()) {
            return response()->json([
                'ok'          => true,
                'has_workout' => false,
                'message'     => 'Aún no tienes entrenamiento asignado para hoy.',
            ]);
        }

        // Selección determinista por día (rota el programa asignado).
        $index = ((int) now()->dayOfYear) % $routines->count();

        return response()->json([
            'ok'          => true,
            'has_workout' => true,
            'workout'     => new RoutineResource($routines[$index]),
        ]);
    }

    /**
     * Catálogo público de rutinas pre-hechas (plantillas) que cualquier miembro
     * puede explorar y adoptar. Filtra opcionalmente por nivel y/o género.
     * GET /api/app/routines/templates?level=Principiante&gender=Mujer
     */
    public function templates(Request $request): JsonResponse
    {
        $query = Routine::where('is_template', true)
            ->with(['routineExercises.exercise']);

        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }
        if ($request->filled('gender')) {
            $query->where('gender', $request->input('gender'));
        }

        $routines = $query
            ->orderBy('level')
            ->orderBy('gender')
            ->orderBy('name')
            ->get();

        return response()->json([
            'ok'   => true,
            'data' => RoutineResource::collection($routines),
        ]);
    }

    /**
     * El miembro adopta una plantilla: queda asignada a él (aparece en
     * "Asignadas" y en "Entrenamiento de hoy"). Idempotente.
     * POST /api/app/routines/templates/{routine}/adopt
     */
    public function adopt(Request $request, Routine $routine): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        if (! $routine->is_template) {
            return response()->json(['message' => 'Esta rutina no es una plantilla.'], 422);
        }

        $assignment = MemberRoutineAssignment::firstOrCreate(
            ['routine_id' => $routine->id, 'member_id' => $member->id],
            ['assigned_at' => now()]
        );

        if ($assignment->wasRecentlyCreated) {
            app(NotificationService::class)->notifyRoutineAssigned($member, $routine);
        }

        $routine->load('routineExercises.exercise');

        return response()->json([
            'ok'      => true,
            'message' => $assignment->wasRecentlyCreated
                ? 'Rutina agregada a tus entrenamientos.'
                : 'Esta rutina ya estaba en tus entrenamientos.',
            'data'    => new RoutineResource($routine),
        ], $assignment->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Rutinas personalizadas creadas por el propio miembro.
     */
    public function custom(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $routines = Routine::where('member_id', $member->id)
            ->where('is_assigned', false)
            ->with(['routineExercises.exercise'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'ok'   => true,
            'data' => RoutineResource::collection($routines),
        ]);
    }

    /**
     * El miembro crea su propia rutina personalizada.
     * Requiere feature flag custom_routines = true en el plan.
     */
    public function store(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        if (! $this->memberCanCreateRoutines($member)) {
            return response()->json([
                'message' => 'Tu plan no incluye la creación de rutinas personalizadas.',
            ], 403);
        }

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'objective'         => 'nullable|string|max:255',
            'level'             => 'nullable|in:Principiante,Intermedio,Avanzado',
            'muscle_group'      => 'nullable|string|max:100',
            'estimated_minutes' => 'nullable|integer|min:0|max:1440',
            'description'       => 'nullable|string',
            'notes'             => 'nullable|string',
            // Simplified: just exercise IDs (app selects from catalog)
            'exercise_ids'      => 'nullable|array',
            'exercise_ids.*'    => 'exists:exercises,id',
            // Full detail (from app with sets/reps/weight)
            'exercises'                   => 'nullable|array',
            'exercises.*.exercise_id'     => 'required_with:exercises|exists:exercises,id',
            'exercises.*.sets'            => 'nullable|integer|min:1|max:20',
            'exercises.*.reps'            => 'nullable|string|max:20',
            'exercises.*.weight'          => 'nullable|numeric|min:0',
            'exercises.*.notes'           => 'nullable|string|max:500',
        ]);

        $routine = Routine::create([
            'name'              => $data['name'],
            'objective'         => $data['objective'] ?? null,
            'level'             => $data['level'] ?? 'Principiante',
            'muscle_group'      => $data['muscle_group'] ?? null,
            'estimated_minutes' => (int) ($data['estimated_minutes'] ?? 0),
            'description'       => $data['description'] ?? null,
            'notes'             => $data['notes'] ?? null,
            'member_id'         => $member->id,
            'is_assigned'       => false,
            'created_by_admin'  => false,
            'status'            => 'Activa',
        ]);

        $this->syncExercises($routine, $data);

        $routine->load('routineExercises.exercise');

        return response()->json([
            'ok'      => true,
            'message' => 'Rutina creada.',
            'data'    => new RoutineResource($routine),
        ], 201);
    }

    /**
     * El miembro elimina su propia rutina personalizada.
     * Acepta DELETE y POST (alias para clientes que no soporten DELETE).
     */
    public function destroy(Request $request, Routine $routine): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        if ((int) $routine->member_id !== (int) $member->id || $routine->is_assigned) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $routine->delete();

        return response()->json(['ok' => true], 200);
    }

    /**
     * POST /api/app/routines/{routine}/complete
     * Registra que el miembro completó la rutina y dispara la notificación/push
     * "Rutina completada". Solo rutinas propias o asignadas al miembro.
     */
    public function complete(Request $request, Routine $routine): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $owns = (int) $routine->member_id === (int) $member->id
            || MemberRoutineAssignment::where('routine_id', $routine->id)
                ->where('member_id', $member->id)
                ->exists();

        if (! $owns) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $completion = RoutineCompletion::create([
            'member_id'    => $member->id,
            'routine_id'   => $routine->id,
            'completed_at' => now(),
            'source'       => 'app',
            'notes'        => $request->input('notes'),
        ]);

        // Notificación + push interno por CADA guardado real (event_key con el
        // id de la finalización → cada entrenamiento es un evento nuevo).
        app(NotificationService::class)->notifyRoutineCompleted($member, $routine, $completion->id);

        return response()->json([
            'ok'   => true,
            'data' => [
                'completion_id' => $completion->id,
                'completed_at'  => $completion->completed_at->toIso8601String(),
            ],
        ], 201);
    }

    private function memberCanCreateRoutines($member): bool
    {
        if (! $member->user_id) {
            return false;
        }

        $user = $member->user ?? \App\Models\User::find($member->user_id);
        if (! $user || ! $user->plan) {
            return false;
        }

        $plan = Plan::where('name', $user->plan)->first();

        return $plan && ($plan->resolvedFeatures()['custom_routines'] ?? false);
    }

    private function syncExercises(Routine $routine, array $data): void
    {
        $routine->routineExercises()->delete();

        if (! empty($data['exercises'])) {
            foreach ($data['exercises'] as $i => $ex) {
                RoutineExercise::create([
                    'routine_id'  => $routine->id,
                    'exercise_id' => $ex['exercise_id'],
                    'sets'        => (int) ($ex['sets'] ?? 3),
                    'reps'        => (string) ($ex['reps'] ?? '10'),
                    'weight'      => isset($ex['weight']) ? (float) $ex['weight'] : null,
                    'notes'       => $ex['notes'] ?? null,
                    'sort_order'  => $i,
                ]);
            }
        } elseif (! empty($data['exercise_ids'])) {
            foreach ($data['exercise_ids'] as $i => $exerciseId) {
                $ex = \App\Models\Exercise::find($exerciseId);
                RoutineExercise::create([
                    'routine_id'  => $routine->id,
                    'exercise_id' => $exerciseId,
                    'sets'        => $ex?->suggested_sets ?? 3,
                    'reps'        => $ex?->suggested_reps ?? '8-12',
                    'sort_order'  => $i,
                ]);
            }
        }
    }
}
