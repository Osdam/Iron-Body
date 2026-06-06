<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\Routine;
use App\Models\RoutineExercise;
use App\Services\RealtimeEvents;
use Illuminate\Http\Request;

class MemberRoutineController extends Controller
{
    /** Lista de miembros con buscador; al seleccionar → sus rutinas. */
    public function index(Request $request)
    {
        $search  = $request->input('search');
        $members = Member::query()
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $term = '%' . $search . '%';
                $q->where('full_name', 'like', $term)
                  ->orWhere('document_number', 'like', $term);
            }))
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString();

        $selectedMember = null;
        $routines       = collect();

        if ($request->filled('member_id')) {
            $selectedMember = Member::find($request->integer('member_id'));
            if ($selectedMember) {
                $viaAssignment = Routine::whereHas(
                    'assignments',
                    fn ($q) => $q->where('member_id', $selectedMember->id)
                )->with(['routineExercises.exercise'])->get();

                $viaMemberId = Routine::where('member_id', $selectedMember->id)
                    ->where('is_assigned', true)
                    ->with(['routineExercises.exercise'])
                    ->get();

                $routines = $viaAssignment->merge($viaMemberId)->unique('id')->values();
            }
        }

        return view('crm.member-routines.index', compact('members', 'selectedMember', 'routines'));
    }

    /** Formulario para crear rutina asignada a un miembro. */
    public function create(Request $request)
    {
        $member    = Member::findOrFail($request->integer('member_id'));
        $exercises = Exercise::orderBy('muscle_group')->orderBy('name')->get();
        return view('crm.member-routines.create', compact('member', 'exercises'));
    }

    /** Guarda la rutina y la asigna al miembro. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'member_id'         => 'required|integer|exists:members,id',
            'name'              => 'required|string|max:255',
            'level'             => 'nullable|in:Principiante,Intermedio,Avanzado',
            'muscle_group'      => 'nullable|string|max:100',
            'estimated_minutes' => 'nullable|integer|min:0|max:1440',
            'description'       => 'nullable|string',
            'notes'             => 'nullable|string',
            'exercise_ids'      => 'nullable|array',
            'exercise_ids.*'    => 'exists:exercises,id',
            'sets_list'         => 'nullable|array',
            'reps_list'         => 'nullable|array',
            'weight_list'       => 'nullable|array',
            'ex_notes_list'     => 'nullable|array',
        ]);

        $member = Member::findOrFail($data['member_id']);

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

        $this->syncExercises($routine, $request);

        MemberRoutineAssignment::firstOrCreate(
            ['member_id' => $member->id, 'routine_id' => $routine->id],
            ['assigned_at' => now()]
        );

        // Refresco en vivo: la app del miembro ve la rutina sin reiniciar.
        RealtimeEvents::routine($member->id);

        return redirect()->route('crm.member-routines.index', ['member_id' => $member->id])
            ->with('success', "Rutina \"{$routine->name}\" asignada a {$member->full_name}.");
    }

    /** Formulario de edición de una rutina asignada. */
    public function edit(Request $request, Routine $routine)
    {
        $member       = Member::findOrFail($request->integer('member_id', $routine->member_id));
        $exercises    = Exercise::orderBy('muscle_group')->orderBy('name')->get();
        $routineItems = $routine->routineExercises()->with('exercise')->orderBy('sort_order')->get();
        return view('crm.member-routines.edit', compact('routine', 'member', 'exercises', 'routineItems'));
    }

    /** Actualiza la rutina asignada. */
    public function update(Request $request, Routine $routine)
    {
        $data = $request->validate([
            'member_id'         => 'required|integer|exists:members,id',
            'name'              => 'required|string|max:255',
            'level'             => 'nullable|in:Principiante,Intermedio,Avanzado',
            'muscle_group'      => 'nullable|string|max:100',
            'estimated_minutes' => 'nullable|integer|min:0|max:1440',
            'description'       => 'nullable|string',
            'notes'             => 'nullable|string',
            'exercise_ids'      => 'nullable|array',
            'exercise_ids.*'    => 'exists:exercises,id',
            'sets_list'         => 'nullable|array',
            'reps_list'         => 'nullable|array',
            'weight_list'       => 'nullable|array',
            'ex_notes_list'     => 'nullable|array',
        ]);

        $member = Member::findOrFail($data['member_id']);

        $routine->fill([
            'name'              => $data['name'],
            'level'             => $data['level'] ?? $routine->level,
            'muscle_group'      => $data['muscle_group'] ?? null,
            'estimated_minutes' => (int) ($data['estimated_minutes'] ?? 0),
            'description'       => $data['description'] ?? null,
            'notes'             => $data['notes'] ?? null,
        ]);
        $routine->save();

        $this->syncExercises($routine, $request);

        // Refresco en vivo de la rutina editada.
        RealtimeEvents::routine($member->id);

        return redirect()->route('crm.member-routines.index', ['member_id' => $member->id])
            ->with('success', "Rutina \"{$routine->name}\" actualizada.");
    }

    /** Elimina la asignación (y la rutina si es exclusiva del miembro). */
    public function destroy(Request $request, Routine $routine)
    {
        $memberId = $request->integer('member_id', $routine->member_id);
        $routine->assignments()->where('member_id', $memberId)->delete();

        if ((int) $routine->member_id === $memberId && $routine->assignments()->count() === 0) {
            $routine->delete();
        }

        // Refresco en vivo: la rutina desaparece de la app sin reiniciar.
        RealtimeEvents::routine($memberId);

        return redirect()->route('crm.member-routines.index', ['member_id' => $memberId])
            ->with('success', 'Rutina eliminada.');
    }

    private function syncExercises(Routine $routine, Request $request): void
    {
        $exerciseIds = array_values((array) $request->input('exercise_ids', []));
        $sets        = array_values((array) $request->input('sets_list', []));
        $reps        = array_values((array) $request->input('reps_list', []));
        $weights     = array_values((array) $request->input('weight_list', []));
        $notes       = array_values((array) $request->input('ex_notes_list', []));

        $routine->routineExercises()->delete();

        $order = 0;
        foreach ($exerciseIds as $i => $exerciseId) {
            if (! $exerciseId) {
                continue;
            }
            $ex = Exercise::find($exerciseId);
            RoutineExercise::create([
                'routine_id'  => $routine->id,
                'exercise_id' => (int) $exerciseId,
                'sets'        => (int) ($sets[$i] ?? $ex?->suggested_sets ?? 3),
                'reps'        => ($reps[$i] ?? '') ?: ($ex?->suggested_reps ?? '8-12'),
                'weight'      => ($weights[$i] ?? '') ?: null,
                'notes'       => ($notes[$i] ?? '') ?: null,
                'sort_order'  => $order++,
            ]);
        }
    }
}
