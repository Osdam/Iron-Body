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
}
