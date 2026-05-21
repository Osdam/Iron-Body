<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\Routine;
use App\Models\RoutineExercise;
use Illuminate\Http\Request;

class RoutineController extends Controller
{
    public function index(Request $request)
    {
        $query = Routine::query()->where('created_by_admin', true);

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('objective', 'like', $term)
                  ->orWhere('muscle_group', 'like', $term);
            });
        }
        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        $routines = $query->withCount('routineExercises')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('crm.routines.index', compact('routines'));
    }

    public function create()
    {
        $exercises = Exercise::orderBy('muscle_group')->orderBy('name')->get();
        return view('crm.routines.create', compact('exercises'));
    }

    public function store(Request $request)
    {
        $data = $this->validateInput($request, true);

        [$member, $memberError] = $this->resolveMember($request);
        if ($memberError) {
            return back()->withInput()->withErrors(['member_document' => $memberError]);
        }

        $routine = Routine::create(array_merge($this->mapInput($data), [
            'created_by_admin' => true,
            'is_assigned'      => $member !== null,
            'member_id'        => $member?->id,
            'status'           => 'Activa',
        ]));

        $this->syncExercises($routine, $request);

        if ($member) {
            MemberRoutineAssignment::firstOrCreate([
                'member_id'  => $member->id,
                'routine_id' => $routine->id,
            ], ['assigned_at' => now()]);
        }

        return redirect()->route('crm.routines.index')
            ->with('success', 'Rutina creada' . ($member ? " y asignada a {$member->full_name}." : ' correctamente.'));
    }

    public function edit(Routine $routine)
    {
        $exercises    = Exercise::orderBy('muscle_group')->orderBy('name')->get();
        $routineItems = $routine->routineExercises()->with('exercise')->orderBy('sort_order')->get();
        $assignments  = $routine->assignments()->with('member')->get();
        return view('crm.routines.edit', compact('routine', 'exercises', 'routineItems', 'assignments'));
    }

    public function update(Request $request, Routine $routine)
    {
        $data = $this->validateInput($request, false);

        [$member, $memberError] = $this->resolveMember($request);
        if ($memberError) {
            return back()->withInput()->withErrors(['member_document' => $memberError]);
        }

        $routine->fill($this->mapInput($data));
        if ($member) {
            $routine->is_assigned = true;
            $routine->member_id   = $member->id;
        }
        $routine->save();

        $this->syncExercises($routine, $request);

        if ($member) {
            MemberRoutineAssignment::firstOrCreate([
                'member_id'  => $member->id,
                'routine_id' => $routine->id,
            ], ['assigned_at' => now()]);
        }

        return redirect()->route('crm.routines.index')
            ->with('success', 'Rutina actualizada' . ($member ? " y asignada a {$member->full_name}." : ' correctamente.'));
    }

    public function destroy(Routine $routine)
    {
        $routine->delete();
        return redirect()->route('crm.routines.index')
            ->with('success', 'Rutina eliminada.');
    }

    public function assign(Request $request, Routine $routine)
    {
        $validated = $request->validate([
            'member_id' => 'required|integer|exists:members,id',
        ]);

        MemberRoutineAssignment::firstOrCreate([
            'member_id'  => $validated['member_id'],
            'routine_id' => $routine->id,
        ], ['assigned_at' => now()]);

        $routine->is_assigned = true;
        $routine->save();

        return redirect()->back()->with('success', 'Rutina asignada al miembro.');
    }

    public function customIndex(Request $request)
    {
        $routines = Routine::where('created_by_admin', false)
            ->whereNotNull('member_id')
            ->with('routineExercises')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('crm.routines.custom', compact('routines'));
    }

    private function validateInput(Request $request, bool $required): array
    {
        $r = $required ? 'required' : 'sometimes';
        return $request->validate([
            'name'              => "$r|string|max:255",
            'objective'         => 'nullable|string|max:255',
            'level'             => 'nullable|string|max:50',
            'muscle_group'      => 'nullable|string|max:100',
            'estimated_minutes' => 'nullable|integer|min:0|max:1440',
            'days_per_week'     => 'nullable|integer|min:0|max:7',
            'description'       => 'nullable|string',
            'notes'             => 'nullable|string',
            'member_document'   => 'nullable|string|max:50',
        ]);
    }

    private function mapInput(array $data): array
    {
        return [
            'name'              => $data['name'] ?? '',
            'objective'         => $data['objective'] ?? null,
            'level'             => $data['level'] ?? null,
            'muscle_group'      => $data['muscle_group'] ?? null,
            'estimated_minutes' => (int) ($data['estimated_minutes'] ?? 0),
            'duration_minutes'  => (int) ($data['estimated_minutes'] ?? 0),
            'days_per_week'     => (int) ($data['days_per_week'] ?? 0),
            'description'       => $data['description'] ?? null,
            'notes'             => $data['notes'] ?? null,
        ];
    }

    /** Returns [Member|null, errorMessage|null]. */
    private function resolveMember(Request $request): array
    {
        $doc = trim((string) $request->input('member_document', ''));
        if ($doc === '') {
            return [null, null];
        }

        $member = Member::where('document_number', $doc)
            ->orWhere('full_name', 'like', '%' . $doc . '%')
            ->first();

        if (! $member) {
            return [null, "No se encontró ningún miembro con documento o nombre: \"{$doc}\""];
        }

        return [$member, null];
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
            RoutineExercise::create([
                'routine_id'  => $routine->id,
                'exercise_id' => (int) $exerciseId,
                'sets'        => (int) ($sets[$i] ?? 3),
                'reps'        => (int) ($reps[$i] ?? 10),
                'weight'      => ($weights[$i] ?? '') ?: null,
                'notes'       => ($notes[$i] ?? '') ?: null,
                'sort_order'  => $order++,
            ]);
        }
    }
}
