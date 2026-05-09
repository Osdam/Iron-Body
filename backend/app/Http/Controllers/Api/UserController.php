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

        return $query
            ->select($this->memberFields())
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    public function show(User $user)
    {
        return $user->only(array_merge($this->memberFields(), ['membershipStartDate', 'membershipEndDate']));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'fullName' => 'required|string|max:255',
            'document' => 'required|string|max:50',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'status' => 'nullable|string|in:active,inactive,pending,expired',
            'plan' => 'nullable|string|max:100',
            'membershipStartDate' => 'nullable|date',
            'membershipEndDate' => 'nullable|date',
        ]);

        $user = User::create([
            'name' => $validated['fullName'],
            'email' => $validated['email'] ?? 'user-' . time() . '-' . mt_rand(1000, 9999) . '@ironbody.local',
            'password' => bcrypt('default-password'),
            'document' => $validated['document'],
            'phone' => $validated['phone'],
            'status' => $validated['status'] ?? 'active',
            'plan' => $validated['plan'] ?? null,
            'membership_start_date' => $validated['membershipStartDate'] ?? null,
            'membership_end_date' => $validated['membershipEndDate'] ?? null,
        ]);

        return response()->json($this->serialize($user), 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'document' => 'sometimes|nullable|string|max:50',
            'phone' => 'sometimes|nullable|string|max:20',
            'status' => 'sometimes|nullable|string|in:active,inactive,pending,expired',
            'plan' => 'sometimes|nullable|string|max:100',
            'membershipStartDate' => 'sometimes|nullable|date',
            'membershipEndDate' => 'sometimes|nullable|date',
        ]);

        foreach (['name', 'email', 'document', 'phone', 'status', 'plan'] as $field) {
            if (array_key_exists($field, $validated)) {
                $user->{$field} = $validated[$field];
            }
        }

        if (array_key_exists('membershipStartDate', $validated)) {
            $user->membership_start_date = $validated['membershipStartDate'];
        }
        if (array_key_exists('membershipEndDate', $validated)) {
            $user->membership_end_date = $validated['membershipEndDate'];
        }

        $user->save();

        return response()->json($this->serialize($user));
    }

    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(null, 204);
    }

    /**
     * Columns selected for member listings.
     */
    private function memberFields(): array
    {
        return [
            'id',
            'name',
            'email',
            'document',
            'phone',
            'status',
            'plan',
            'membership_start_date',
            'membership_end_date',
            'created_at',
        ];
    }

    /**
     * Serialize a user using the camelCase membership keys.
     */
    private function serialize(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'document' => $user->document,
            'phone' => $user->phone,
            'status' => $user->status,
            'plan' => $user->plan,
            'membershipStartDate' => $user->membershipStartDate,
            'membershipEndDate' => $user->membershipEndDate,
            'created_at' => $user->created_at,
        ];
    }
}
