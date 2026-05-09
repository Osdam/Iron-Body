<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        return Plan::query()->paginate(20);
    }

    public function show(Plan $plan)
    {
        return $plan;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'price' => 'required|numeric',
            'duration_days' => 'required|integer',
        ]);

        $plan = Plan::create($data + $request->only(['benefits','access_classes','reservations_limit','access_locations','restrictions','active']));

        return response()->json($plan, 201);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name'               => 'sometimes|required|string|max:255',
            'price'              => 'sometimes|required|numeric|min:0',
            'duration_days'      => 'sometimes|required|integer|min:1',
            'benefits'           => 'nullable|string',
            'access_classes'     => 'nullable|boolean',
            'reservations_limit' => 'nullable|integer|min:0',
            'access_locations'   => 'nullable|string',
            'restrictions'       => 'nullable|string',
            'active'             => 'nullable|boolean',
        ]);

        $plan->update($data);
        return response()->json($plan);
    }

    public function destroy(Plan $plan)
    {
        $plan->delete();
        return response()->json(null, 204);
    }
}
