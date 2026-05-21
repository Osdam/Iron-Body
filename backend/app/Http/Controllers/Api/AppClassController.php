<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClassResource;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppClassController extends Controller
{
    private function member(Request $request): Member
    {
        return $request->attributes->get('auth_member');
    }

    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $classes = MyClass::query()
            ->where('status', 'active')
            ->where('allow_online_booking', true)
            ->with('trainer:id,full_name')
            ->withCount('reservations')
            ->get();

        $reservedIds = ClassReservation::where('member_id', $member->id)
            ->whereIn('class_id', $classes->pluck('id'))
            ->pluck('class_id')
            ->flip();

        return response()->json([
            'data' => $classes->map(
                fn (MyClass $c) => new ClassResource($c, $reservedIds->has($c->id))
            ),
        ]);
    }

    public function reserve(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $this->member($request);

        if ($myClass->status !== 'active' || ! $myClass->allow_online_booking) {
            return response()->json(['message' => 'Clase no disponible para reservas.'], 422);
        }

        $bookedSpots = $myClass->reservations()->count();
        if ($bookedSpots >= $myClass->max_capacity) {
            return response()->json(['message' => 'Clase completa.'], 422);
        }

        if (ClassReservation::where('class_id', $myClass->id)->where('member_id', $member->id)->exists()) {
            return response()->json(['message' => 'Ya tienes una reserva para esta clase.'], 422);
        }

        ClassReservation::create([
            'class_id'    => $myClass->id,
            'member_id'   => $member->id,
            'reserved_at' => now(),
        ]);

        $myClass->loadCount('reservations')->load('trainer:id,full_name');

        return response()->json(['data' => new ClassResource($myClass, true)]);
    }

    public function cancel(Request $request, MyClass $myClass): JsonResponse
    {
        $member = $this->member($request);

        $deleted = ClassReservation::where('class_id', $myClass->id)
            ->where('member_id', $member->id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'No tienes reserva en esta clase.'], 422);
        }

        $myClass->loadCount('reservations')->load('trainer:id,full_name');

        return response()->json(['data' => new ClassResource($myClass, false)]);
    }
}
