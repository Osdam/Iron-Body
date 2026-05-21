<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoutineResource;
use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\Routine;
use App\Models\RoutineExercise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppRoutineController extends Controller
{
    /**
     * Rutinas asignadas al miembro por el administrador.
     * Incluye rutinas vinculadas vía member_routine_assignments
     * o rutinas con member_id + is_assigned = true.
     */
    public function assigned(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $viaAssignment = Routine::whereHas('assignments', fn ($q) => $q->where('member_id', $member->id))
            ->with(['routineExercises.exercise'])
            ->get();

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
     */
    public function store(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'objective'         => 'nullable|string|max:255',
            'level'             => 'nullable|string|max:50',
            'muscle_group'      => 'nullable|string|max:100',
            'estimated_minutes' => 'nullable|integer|min:0|max:1440',
            'days_per_week'     => 'nullable|integer|min:0|max:7',
            'description'       => 'nullable|string',
            'notes'             => 'nullable|string',
            'exercise_ids'      => 'nullable|array',
            'exercise_ids.*'    => 'integer|exists:exercises,id',
            'exercises'         => 'nullable|array',
            'exercises.*.exercise_id' => 'required_with:exercises|integer|exists:exercises,id',
            'exercises.*.sets'        => 'nullable|integer|min:1|max:20',
            'exercises.*.reps'        => 'nullable|integer|min:1|max:200',
            'exercises.*.weight'      => 'nullable|string|max:50',
            'exercises.*.notes'       => 'nullable|string|max:500',
            'exercises.*.sort_order'  => 'nullable|integer|min:0',
        ]);

        $routine = Routine::create([
            'name'              => $data['name'],
            'objective'         => $data['objective'] ?? null,
            'level'             => $data['level'] ?? null,
            'muscle_group'      => $data['muscle_group'] ?? null,
            'estimated_minutes' => $data['estimated_minutes'] ?? 0,
            'days_per_week'     => $data['days_per_week'] ?? 0,
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
     * El miembro elimina una rutina personalizada propia.
     */
    public function destroy(Request $request, Routine $routine): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        if ((int) $routine->member_id !== (int) $member->id || $routine->is_assigned) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $routine->delete();

        return response()->json(null, 204);
    }

    private function syncExercises(Routine $routine, array $data): void
    {
        if (isset($data['exercises']) && is_array($data['exercises'])) {
            $routine->routineExercises()->delete();
            foreach ($data['exercises'] as $i => $ex) {
                RoutineExercise::create([
                    'routine_id'  => $routine->id,
                    'exercise_id' => $ex['exercise_id'],
                    'sets'        => $ex['sets'] ?? 3,
                    'reps'        => $ex['reps'] ?? 10,
                    'weight'      => $ex['weight'] ?? null,
                    'notes'       => $ex['notes'] ?? null,
                    'sort_order'  => $ex['sort_order'] ?? $i,
                ]);
            }
        } elseif (isset($data['exercise_ids']) && is_array($data['exercise_ids'])) {
            $routine->routineExercises()->delete();
            foreach ($data['exercise_ids'] as $i => $exerciseId) {
                RoutineExercise::create([
                    'routine_id'  => $routine->id,
                    'exercise_id' => $exerciseId,
                    'sets'        => 3,
                    'reps'        => 10,
                    'sort_order'  => $i,
                ]);
            }
        }
    }
}
