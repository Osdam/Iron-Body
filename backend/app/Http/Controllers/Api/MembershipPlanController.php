<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class MembershipPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get()
            ->map(fn (Plan $plan): array => $this->serialize($plan))
            ->values();

        return response()->json([
            'ok' => true,
            'data' => $plans,
        ]);
    }

    public function show(Plan $plan): JsonResponse
    {
        if (! $plan->active) {
            abort(404);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->serialize($plan),
        ]);
    }

    private function serialize(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'tier' => $plan->tier ?: 'lite',
            'period' => $plan->period,
            'months' => $plan->months,
            'price' => (float) $plan->price,
            'original_price' => $plan->original_price !== null ? (float) $plan->original_price : null,
            'benefits' => $plan->benefitsArray(),
            'is_recommended' => (bool) $plan->is_recommended,
            'badge' => $plan->badge,
            'status' => $plan->active ? 'active' : 'inactive',
        ];
    }
}
