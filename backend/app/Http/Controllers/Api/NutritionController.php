<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NutritionAiRecommendation;
use App\Models\NutritionFoodItem;
use App\Services\NutritionAiCoachService;
use App\Services\NutritionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Nutrición real para la app (member autenticado). PostgreSQL es la fuente de
 * verdad: metas, comidas, alimentos e historial se guardan en BD (no local).
 *
 * member_id SIEMPRE de auth.member; la app nunca lo manda. Fechas en TZ Bogotá.
 */
class NutritionController extends Controller
{
    public function __construct(private readonly NutritionService $service)
    {
    }

    /** GET /api/app/nutrition/today */
    public function today(Request $request): JsonResponse
    {
        $member = $this->member($request);
        if (!$member) {
            return $this->unauth();
        }
        return response()->json(['success' => true, 'data' => $this->service->dayPayload($member)]);
    }

    /** GET /api/app/nutrition/day?date=YYYY-MM-DD */
    public function day(Request $request): JsonResponse
    {
        $member = $this->member($request);
        if (!$member) {
            return $this->unauth();
        }
        $request->validate(['date' => 'nullable|date']);
        return response()->json([
            'success' => true,
            'data' => $this->service->dayPayload($member, $request->query('date')),
        ]);
    }

    /** GET /api/app/nutrition/goals */
    public function getGoals(Request $request): JsonResponse
    {
        $member = $this->member($request);
        if (!$member) {
            return $this->unauth();
        }
        return response()->json(['success' => true, 'data' => $this->service->activeGoal($member)]);
    }

    /** POST /api/app/nutrition/goals */
    public function saveGoals(Request $request): JsonResponse
    {
        $member = $this->member($request);
        if (!$member) {
            return $this->unauth();
        }
        $data = $request->validate([
            'daily_calories' => 'required|integer|min:500|max:10000',
            'protein_g' => 'required|integer|min:0|max:1000',
            'carbs_g' => 'required|integer|min:0|max:2000',
            'fat_g' => 'required|integer|min:0|max:1000',
            'goal_type' => 'nullable|string|in:lose_fat,maintain,gain_muscle',
        ]);
        return response()->json(['success' => true, 'data' => $this->service->saveGoal($member, $data)]);
    }

    /** POST /api/app/nutrition/meals/{mealType}/items */
    public function addItem(Request $request, string $mealType): JsonResponse
    {
        $member = $this->member($request);
        if (!$member) {
            return $this->unauth();
        }
        if (!in_array($mealType, NutritionService::MEAL_TYPES, true)) {
            return response()->json(['success' => false, 'message' => 'Tipo de comida inválido.'], 422);
        }

        $data = $request->validate([
            'food_item_id' => 'nullable|integer|exists:nutrition_food_items,id',
            'custom_name' => 'nullable|string|max:120',
            'quantity' => 'nullable|numeric|min:0.1|max:100',
            'serving_label' => 'nullable|string|max:60',
            'calories' => 'nullable|numeric|min:0|max:10000',
            'protein_g' => 'nullable|numeric|min:0|max:1000',
            'carbs_g' => 'nullable|numeric|min:0|max:2000',
            'fat_g' => 'nullable|numeric|min:0|max:1000',
        ]);

        // Debe venir food_item_id o un alimento libre con nombre.
        if (empty($data['food_item_id']) && empty($data['custom_name'])) {
            return response()->json(['success' => false, 'message' => 'Indica un alimento.'], 422);
        }

        $item = $this->service->addItem($member, $mealType, $data);
        return response()->json(['success' => true, 'data' => $item], 201);
    }

    /** DELETE /api/app/nutrition/meals/items/{id} */
    public function deleteItem(Request $request, int $id): JsonResponse
    {
        $member = $this->member($request);
        if (!$member) {
            return $this->unauth();
        }
        $ok = $this->service->deleteItem($member, $id);
        if (!$ok) {
            return response()->json(['success' => false, 'message' => 'No encontrado.'], 404);
        }
        return response()->json(['success' => true]);
    }

    /** GET /api/app/nutrition/history */
    public function history(Request $request): JsonResponse
    {
        $member = $this->member($request);
        if (!$member) {
            return $this->unauth();
        }
        $today = $this->service->today(NutritionService::TZ);
        $goal = $this->service->activeGoal($member);
        return response()->json([
            'success' => true,
            'data' => $this->service->weeklyHistory($member, $today, $goal['daily_calories']),
        ]);
    }

    /** GET /api/app/nutrition/foods?q= — catálogo + personalizados del miembro. */
    public function foods(Request $request): JsonResponse
    {
        $member = $this->member($request);
        if (!$member) {
            return $this->unauth();
        }
        $q = trim((string) $request->query('q', ''));

        $items = NutritionFoodItem::query()
            ->where(function ($w) use ($member) {
                $w->whereNull('member_id')->orWhere('member_id', $member->id);
            })
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', "%$q%"))
            ->orderBy('name')
            ->limit(40)
            ->get()
            ->map(fn (NutritionFoodItem $f) => $f->toPublicArray());

        return response()->json(['success' => true, 'data' => $items]);
    }

    /** POST /api/app/nutrition/foods — alimento personalizado del miembro. */
    public function createFood(Request $request): JsonResponse
    {
        $member = $this->member($request);
        if (!$member) {
            return $this->unauth();
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'brand' => 'nullable|string|max:80',
            'calories' => 'required|numeric|min:0|max:10000',
            'protein_g' => 'nullable|numeric|min:0|max:1000',
            'carbs_g' => 'nullable|numeric|min:0|max:2000',
            'fat_g' => 'nullable|numeric|min:0|max:1000',
            'serving_label' => 'nullable|string|max:60',
        ]);

        $food = NutritionFoodItem::create(array_merge($data, [
            'member_id' => $member->id,
            'source' => 'custom',
        ]));

        return response()->json(['success' => true, 'data' => $food->toPublicArray()], 201);
    }

    /**
     * POST /api/app/nutrition/ai/recommendation
     *
     * IRON IA analiza el día del usuario (contexto real) y devuelve una
     * recomendación estructurada. OpenAI se llama DESDE Laravel (la key nunca
     * sale del backend). Rate limit por miembro para controlar costo.
     */
    public function aiRecommendation(Request $request, NutritionAiCoachService $coach): JsonResponse
    {
        $member = $this->member($request);
        if (!$member) {
            return $this->unauth();
        }

        // Rate limit: 10 análisis por miembro por hora.
        $key = 'nutrition-ai:' . $member->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json([
                'success' => false,
                'message' => 'Has alcanzado el límite de análisis por ahora. Intenta más tarde.',
            ], 429);
        }
        RateLimiter::hit($key, 3600);

        if (!$coach->isEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'El coach nutricional no está disponible por ahora.',
            ], 503);
        }

        $data = $coach->recommendForToday($member);
        if ($data === null) {
            return response()->json([
                'success' => false,
                'message' => 'No pudimos generar la recomendación. Intenta de nuevo.',
            ], 502);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** GET /api/app/nutrition/ai/last — última recomendación guardada (sin gastar IA). */
    public function aiLast(Request $request): JsonResponse
    {
        $member = $this->member($request);
        if (!$member) {
            return $this->unauth();
        }

        $last = NutritionAiRecommendation::query()
            ->where('member_id', $member->id)
            ->latest('created_at')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $last ? [
                'date' => $last->recommendation_date?->toDateString(),
                'recommendation' => $last->response_json,
                'created_at' => $last->created_at?->toIso8601String(),
            ] : null,
        ]);
    }

    private function member(Request $request)
    {
        return $request->attributes->get('auth_member');
    }

    private function unauth(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
    }
}
