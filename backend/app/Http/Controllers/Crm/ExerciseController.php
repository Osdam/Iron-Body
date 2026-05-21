<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use Illuminate\Http\Request;

class ExerciseController extends Controller
{
    public function index(Request $request)
    {
        $query = Exercise::query();

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('muscle_group', 'like', $term)
                  ->orWhere('body_part', 'like', $term);
            });
        }
        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->input('difficulty'));
        }
        if ($request->filled('muscle_group')) {
            $query->where('muscle_group', $request->input('muscle_group'));
        }

        $exercises = $query->orderBy('name')->paginate(30)->withQueryString();

        return view('crm.exercises.index', compact('exercises'));
    }

    public function create()
    {
        return view('crm.exercises.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateInput($request);
        Exercise::create($this->mapInput($data));

        return redirect()->route('crm.exercises.index')
            ->with('success', 'Ejercicio creado correctamente.');
    }

    public function edit(Exercise $exercise)
    {
        return view('crm.exercises.edit', compact('exercise'));
    }

    public function update(Request $request, Exercise $exercise)
    {
        $data = $this->validateInput($request);
        $exercise->fill($this->mapInput($data));
        $exercise->save();

        return redirect()->route('crm.exercises.index')
            ->with('success', 'Ejercicio actualizado correctamente.');
    }

    public function destroy(Exercise $exercise)
    {
        $exercise->delete();

        return redirect()->route('crm.exercises.index')
            ->with('success', 'Ejercicio eliminado.');
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'name'          => 'required|string|max:255',
            'muscle_group'  => 'nullable|string|max:100',
            'body_part'     => 'nullable|string|max:100',
            'difficulty'    => 'nullable|string|max:50',
            'equipment'     => 'nullable|string|max:100',
            'description'   => 'nullable|string',
            'steps'         => 'nullable|string',
            'tips'          => 'nullable|string',
            'muscles_worked'=> 'nullable|string',
            'gif_url'       => 'nullable|url|max:1000',
            'thumbnail_url' => 'nullable|url|max:1000',
        ]);
    }

    private function mapInput(array $data): array
    {
        return [
            'name'           => $data['name'],
            'muscle_group'   => $data['muscle_group'] ?? null,
            'body_part'      => $data['body_part'] ?? null,
            'difficulty'     => $data['difficulty'] ?? null,
            'equipment'      => $data['equipment'] ?? null,
            'description'    => $data['description'] ?? null,
            'steps'          => $this->parseLines($data['steps'] ?? null),
            'tips'           => $this->parseLines($data['tips'] ?? null),
            'muscles_worked' => $this->parseLines($data['muscles_worked'] ?? null),
            'gif_url'        => $data['gif_url'] ?? null,
            'thumbnail_url'  => $data['thumbnail_url'] ?? null,
            'provider'       => 'manual',
        ];
    }

    private function parseLines(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        return array_values(array_filter(array_map('trim', explode("\n", $value))));
    }
}
