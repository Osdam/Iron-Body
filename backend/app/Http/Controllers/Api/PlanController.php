<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
        $data = $this->validatedData($request, false);

        $plan = Plan::create($data);

        return response()->json($plan, 201);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $this->validatedData($request, true);

        if (array_key_exists('features', $data)) {
            $data['features'] = array_merge(
                $plan->resolvedFeatures(),
                is_array($data['features']) ? $data['features'] : []
            );
        }

        $plan->update($data);
        return response()->json($plan);
    }

    public function destroy(Plan $plan)
    {
        $plan->delete();
        return response()->json(null, 204);
    }

    /** GET /api/plans/features — todos los planes con sus feature flags. */
    public function allFeatures()
    {
        $plans = Plan::orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'plans' => $plans->map(fn (Plan $p) => [
                'planId'   => (string) $p->id,
                'planName' => $p->name,
                'features' => $p->resolvedFeatures(),
            ])->values(),
        ]);
    }

    /** PUT /api/plans/{plan}/features — actualiza feature flags de un plan. */
    public function updateFeatures(Request $request, Plan $plan)
    {
        $allowed = array_keys(Plan::defaultFeatures());
        $featureRules = collect($allowed)
            ->mapWithKeys(fn ($k) => ["features.{$k}" => 'sometimes|boolean'])
            ->all();

        $data = $request->validate(array_merge(
            ['features' => 'required|array'],
            $featureRules
        ));

        $plan->features = array_merge($plan->resolvedFeatures(), $data['features']);
        $plan->save();

        // Marcar en cache todos los miembros activos del plan para notificación SSE
        // inmediata (sin esperar el próximo ciclo de polling).
        User::where('plan', $plan->name)
            ->whereHas('appMember')
            ->with('appMember')
            ->get()
            ->each(function (User $user): void {
                Cache::put(
                    "features_changed_{$user->appMember->id}",
                    true,
                    now()->addMinutes(5),
                );
            });

        return response()->json([
            'planId'   => (string) $plan->id,
            'planName' => $plan->name,
            'features' => $plan->resolvedFeatures(),
        ]);
    }

    private function validatedData(Request $request, bool $updating): array
    {
        $req = $updating ? ['sometimes', 'required'] : ['required'];

        $data = $request->validate([
            'name'               => [...$req, 'string', 'max:255'],
            'price'              => [...$req, 'numeric', 'min:0'],
            'original_price'     => ['nullable', 'numeric', 'min:0'],
            'duration_days'      => [$updating ? 'sometimes' : 'required_without_all:duration_months,months', 'integer', 'min:1'],
            'duration_months'    => ['sometimes', 'integer', 'min:1'],
            'months'             => ['sometimes', 'integer', 'min:1'],
            'benefits'           => ['nullable'],
            'is_recommended'     => ['nullable', 'boolean'],
            'badge'              => ['nullable', 'string', 'max:80'],
            'sort_order'         => ['nullable', 'integer', 'min:0'],
            'access_classes'     => ['nullable', 'boolean'],
            'reservations_limit' => ['nullable', 'integer', 'min:0'],
            'access_locations'   => ['nullable', 'string'],
            'restrictions'       => ['nullable', 'string'],
            'active'             => [$updating ? 'sometimes' : 'required', 'boolean'],
            'features'           => ['sometimes', 'nullable', 'array'],
            'features.*'         => ['boolean'],
        ]);

        if (! isset($data['duration_days'])) {
            $months = $data['duration_months'] ?? $data['months'] ?? null;

            if ($months !== null) {
                $data['duration_days'] = (int) $months * 30;
            }
        }

        unset($data['duration_months'], $data['months']);

        if (array_key_exists('benefits', $data) && is_array($data['benefits'])) {
            $data['benefits'] = json_encode(array_values(array_filter(array_map(
                fn (mixed $benefit): string => trim((string) $benefit),
                $data['benefits']
            ))));
        }

        return $data;
    }
}
