<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Routine;
use Illuminate\Http\Request;

class RoutineController extends Controller
{
    public function index(Request $request)
    {
        $query = Routine::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('objective', 'like', $term)
                  ->orWhere('trainer_name', 'like', $term)
                  ->orWhere('assigned_member_name', 'like', $term);
            });
        }

        $items = $query->orderByDesc('created_at')->get();

        return response()->json($items->map(fn ($r) => $this->serialize($r)));
    }

    public function show(Routine $routine)
    {
        return response()->json($this->serialize($routine));
    }

    public function store(Request $request)
    {
        $data = $this->validateInput($request, true);
        $routine = Routine::create($this->mapInput($data));
        return response()->json($this->serialize($routine), 201);
    }

    public function update(Request $request, Routine $routine)
    {
        $data = $this->validateInput($request, false);
        $routine->fill($this->mapInput($data));
        $routine->save();
        return response()->json($this->serialize($routine));
    }

    public function destroy(Routine $routine)
    {
        $routine->delete();
        return response()->json(null, 204);
    }

    public function assign(Request $request, Routine $routine)
    {
        $validated = $request->validate([
            'assignedMemberName' => 'nullable|string|max:255',
            'assignedMemberId' => 'nullable|integer',
        ]);
        $routine->assigned_member_name = $validated['assignedMemberName'] ?? 'Plantilla general';
        $routine->assigned_member_id = $validated['assignedMemberId'] ?? null;
        $routine->save();
        return response()->json($this->serialize($routine));
    }

    private function validateInput(Request $request, bool $required): array
    {
        return $request->validate([
            'name' => $required ? 'required|string|max:255' : 'sometimes|string|max:255',
            'objective' => 'sometimes|nullable|string|max:255',
            'level' => 'sometimes|nullable|string|max:50',
            'durationMinutes' => 'sometimes|nullable|integer|min:0|max:1440',
            'daysPerWeek' => 'sometimes|nullable|integer|min:0|max:7',
            'trainerName' => 'sometimes|nullable|string|max:255',
            'trainerId' => 'sometimes|nullable|integer',
            'assignedMemberName' => 'sometimes|nullable|string|max:255',
            'assignedMemberId' => 'sometimes|nullable|integer',
            'status' => 'sometimes|nullable|string|max:50',
            'description' => 'sometimes|nullable|string',
            'notes' => 'sometimes|nullable|string',
            'exercises' => 'sometimes|nullable|array',
            'exercises.*.name' => 'sometimes|string|max:255',
            'exercises.*.muscleGroup' => 'sometimes|nullable|string|max:100',
            'exercises.*.sets' => 'sometimes|nullable|integer|min:0',
            'exercises.*.reps' => 'sometimes|nullable|integer|min:0',
            'exercises.*.suggestedWeight' => 'sometimes|nullable|string|max:50',
            'exercises.*.restSeconds' => 'sometimes|nullable|integer|min:0',
            'exercises.*.notes' => 'sometimes|nullable|string',
            'exercises.*.order' => 'sometimes|nullable|integer',
        ]);
    }

    private function mapInput(array $data): array
    {
        $out = [];
        foreach (['name', 'objective', 'level', 'status', 'description', 'notes'] as $f) {
            if (array_key_exists($f, $data)) $out[$f] = $data[$f];
        }
        if (array_key_exists('durationMinutes', $data)) $out['duration_minutes'] = (int) $data['durationMinutes'];
        if (array_key_exists('daysPerWeek', $data)) $out['days_per_week'] = (int) $data['daysPerWeek'];
        if (array_key_exists('trainerName', $data)) $out['trainer_name'] = $data['trainerName'];
        if (array_key_exists('trainerId', $data)) $out['trainer_id'] = $data['trainerId'];
        if (array_key_exists('assignedMemberName', $data)) $out['assigned_member_name'] = $data['assignedMemberName'];
        if (array_key_exists('assignedMemberId', $data)) $out['assigned_member_id'] = $data['assignedMemberId'];
        if (array_key_exists('exercises', $data)) {
            $out['exercises'] = array_map(function ($ex, $i) {
                return [
                    'id' => $ex['id'] ?? ('ex-' . ($i + 1)),
                    'name' => $ex['name'] ?? '',
                    'muscleGroup' => $ex['muscleGroup'] ?? '',
                    'sets' => (int) ($ex['sets'] ?? 0),
                    'reps' => (int) ($ex['reps'] ?? 0),
                    'suggestedWeight' => $ex['suggestedWeight'] ?? '',
                    'restSeconds' => (int) ($ex['restSeconds'] ?? 0),
                    'notes' => $ex['notes'] ?? '',
                    'order' => (int) ($ex['order'] ?? ($i + 1)),
                ];
            }, $data['exercises'] ?? [], array_keys($data['exercises'] ?? []));
        }
        return $out;
    }

    private function serialize(Routine $r): array
    {
        return [
            'id' => (string) $r->id,
            'name' => $r->name,
            'objective' => $r->objective ?? '',
            'level' => $r->level ?? '',
            'durationMinutes' => (int) $r->duration_minutes,
            'daysPerWeek' => (int) $r->days_per_week,
            'trainerId' => $r->trainer_id ? (string) $r->trainer_id : null,
            'trainerName' => $r->trainer_name,
            'assignedMemberId' => $r->assigned_member_id ? (string) $r->assigned_member_id : null,
            'assignedMemberName' => $r->assigned_member_name,
            'status' => $r->status ?? 'Activa',
            'description' => $r->description ?? '',
            'notes' => $r->notes ?? '',
            'exercises' => $r->exercises ?? [],
            'createdAt' => optional($r->created_at)->toIso8601String(),
            'updatedAt' => optional($r->updated_at)->toIso8601String(),
        ];
    }
}
