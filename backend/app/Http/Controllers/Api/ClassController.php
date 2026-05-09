<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MyClass;
use Illuminate\Http\Request;

class ClassController extends Controller
{
    public function index(Request $request)
    {
        $query = MyClass::query();

        // Filtrar por estado si se proporciona
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filtrar por día de la semana
        if ($request->filled('day_of_week')) {
            $query->where('day_of_week', $request->input('day_of_week'));
        }

        // Filtrar por entrenador
        if ($request->filled('trainer_id')) {
            $query->where('trainer_id', $request->input('trainer_id'));
        }

        // Filtrar por tipo de clase
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        // Búsqueda por nombre
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
        }

        // Cargar relación con entrenador
        $query->with('trainer:id,full_name');

        return $query->select('id', 'name', 'type', 'trainer_id', 'day_of_week', 'start_time', 'end_time', 'duration_minutes', 'max_capacity', 'enrolled_count', 'location', 'status', 'description', 'created_at')->paginate(20);
    }

    public function show(MyClass $myClass)
    {
        $myClass->load('trainer:id,full_name');
        return $myClass;
    }

    public function store(Request $request)
    {
        // Validar datos requeridos
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:100',
            'day_of_week' => 'required|string|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'duration_minutes' => 'nullable|integer|min:15',
            'max_capacity' => 'required|integer|min:1',
            'location' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive,finished',
            'trainer_id' => 'nullable|exists:trainers,id',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_recurring' => 'nullable|boolean',
            'allow_online_booking' => 'nullable|boolean',
            'requires_active_plan' => 'nullable|boolean',
        ]);

        // Calcular duración en minutos si no se proporciona
        if (empty($validated['duration_minutes'])) {
            $start = \DateTime::createFromFormat('H:i', $validated['start_time']);
            $end = \DateTime::createFromFormat('H:i', $validated['end_time']);
            $validated['duration_minutes'] = $end->diff($start)->i + ($end->diff($start)->h * 60);
        }

        $classe = MyClass::create($validated);

        return response()->json($classe->load('trainer:id,full_name'), 201);
    }

    public function update(Request $request, MyClass $myClass)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|max:100',
            'day_of_week' => 'sometimes|string|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'duration_minutes' => 'nullable|integer|min:15',
            'max_capacity' => 'sometimes|integer|min:1',
            'enrolled_count' => 'nullable|integer|min:0',
            'location' => 'nullable|string|max:255',
            'status' => 'sometimes|string|in:active,inactive,finished',
            'trainer_id' => 'nullable|exists:trainers,id',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_recurring' => 'nullable|boolean',
            'allow_online_booking' => 'nullable|boolean',
            'requires_active_plan' => 'nullable|boolean',
        ]);

        $myClass->update($validated);

        return $myClass->load('trainer:id,full_name');
    }

    public function destroy(MyClass $myClass)
    {
        $myClass->delete();
        return response()->json(['message' => 'Clase eliminada correctamente'], 200);
    }
}
