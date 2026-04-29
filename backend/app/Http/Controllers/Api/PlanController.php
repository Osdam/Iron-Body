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
        $plan->update($request->all());
        return $plan;
    }
}
