<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('status') && Schema::hasColumn('users', 'status')) {
            $query->where('status', $request->input('status'));
        }

        return $query->select('id','name','email','created_at')->paginate(20);
    }

    public function show(User $user)
    {
        // cargar relaciones mínimas
        $user->load([]);
        return $user;
    }

    public function store(Request $request)
    {
        // Validar datos requeridos mínimos
        $validated = $request->validate([
            'fullName' => 'required|string|max:255',
            'document' => 'required|string|max:50',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'status' => 'nullable|string|in:active,inactive,pending,expired',
        ]);

        // Crear usuario con los datos del request
        // TODO: Extender tabla users con campos adicionales si es necesario
        // Campos adicionales disponibles en $request: birthDate, gender, address, plan, etc.
        $user = User::create([
            'name' => $validated['fullName'],
            'email' => $validated['email'] ?? 'user-' . time() . '@ironbody.local',
            'password' => bcrypt('default-password'), // TODO: Generar contraseña aleatoria
            'document' => $validated['document'],
            'phone' => $validated['phone'],
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json($user->only(['id', 'name', 'email', 'document', 'phone', 'status', 'created_at']), 201);
    }
}
