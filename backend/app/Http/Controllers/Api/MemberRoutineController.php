<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoutineResource;
use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\Routine;
use App\Models\RoutineExercise;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberRoutineController extends Controller
{
    /** GET /api/members/{member}/routines */
    public function index(Member $member): JsonResponse
    {
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

    /** POST /api/members/{member}/routines */
    public function store(Request $request, Member $member): JsonResponse
    {
        $data = $this->validateRoutine($request, true);

        $routine = Routine::create([
            'name'              => $data['name'],
            'level'             => $data['level'] ?? 'Principiante',
            'muscle_group'      => $data['muscle_group'] ?? null,
            'estimated_minutes' => (int) ($data['estimated_minutes'] ?? 0),
            'description'       => $data['description'] ?? null,
            'notes'             => $data['notes'] ?? null,
            'member_id'         => $member->id,
            'is_assigned'       => true,
            'created_by_admin'  => true,
            'status'            => 'Activa',
        ]);

        $this->syncExercises($routine, $data);

        $assignment = MemberRoutineAssignment::firstOrCreate(
            ['member_id' => $member->id, 'routine_id' => $routine->id],
            ['assigned_at' => now()]
        );

        // Notificación de rutina asignada (ADITIVO; solo en asignación nueva).
        if ($assignment->wasRecentlyCreated) {
            app(NotificationService::class)->notifyRoutineAssigned($member, $routine);
        }

        $routine->load('routineExercises.exercise');

        return response()->json([
            'ok'   => true,
            'data' => new RoutineResource($routine),
        ], 201);
    }

    /** PUT /api/members/{member}/routines/{routine} */
    public function update(Request $request, Member $member, Routine $routine): JsonResponse
    {
        $data = $this->validateRoutine($request, false);

        $routine->fill(array_filter([
            'name'              => $data['name']              ?? null,
            'level'             => $data['level']             ?? null,
            'muscle_group'      => $data['muscle_group']      ?? null,
            'estimated_minutes' => isset($data['estimated_minutes']) ? (int) $data['estimated_minutes'] : null,
            'description'       => $data['description']       ?? null,
            'notes'             => $data['notes']             ?? null,
        ], fn ($v) => $v !== null));
        $routine->save();

        if (isset($data['exercises'])) {
            $this->syncExercises($routine, $data);
        }

        // Notificación de rutina actualizada (ADITIVO; no afecta la actualización).
        app(NotificationService::class)->notifyRoutineUpdated($member, $routine);

        $routine->load('routineExercises.exercise');

        return response()->json([
            'ok'   => true,
            'data' => new RoutineResource($routine),
        ]);
    }

    /** DELETE /api/members/{member}/routines/{routine} */
    public function destroy(Member $member, Routine $routine): JsonResponse
    {
        // Avisa al miembro que su rutina asignada ya no estará disponible.
        app(NotificationService::class)->notifyRoutineDeleted($routine, $member);

        $routine->assignments()->where('member_id', $member->id)->delete();

        // Only hard-delete if this routine belongs exclusively to this member
        if ((int) $routine->member_id === (int) $member->id && $routine->assignments()->count() === 0) {
            $routine->delete();
        }

        return response()->json(['ok' => true]);
    }

    private function validateRoutine(Request $request, bool $required): array
    {
        $r = $required ? 'required' : 'sometimes';
        return $request->validate([
            'name'                        => "$r|string|max:255",
            'level'                       => 'nullable|in:Principiante,Intermedio,Avanzado',
            'muscle_group'                => 'nullable|string|max:100',
            'estimated_minutes'           => 'nullable|integer|min:0|max:1440',
            'description'                 => 'nullable|string',
            'notes'                       => 'nullable|string',
            'exercises'                   => 'nullable|array',
            'exercises.*.exercise_id'     => 'required_with:exercises|exists:exercises,id',
            'exercises.*.sets'            => 'nullable|integer|min:1|max:20',
            'exercises.*.reps'            => 'nullable|string|max:20',
            'exercises.*.weight'          => 'nullable|numeric|min:0',
            'exercises.*.notes'           => 'nullable|string|max:500',
        ]);
    }

    private function syncExercises(Routine $routine, array $data): void
    {
        $routine->routineExercises()->delete();

        foreach ($data['exercises'] ?? [] as $i => $ex) {
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
    }
}
