<?php

namespace App\Http\Controllers\Api\Nutrition;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\NutritionFavorite;
use App\Models\NutritionFood;
use App\Models\NutritionRecentFood;
use App\Services\Nutrition\NutritionBarcodeService;
use App\Services\Nutrition\NutritionFoodSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alimentos: búsqueda, barcode, detalle, creación manual, edición y borrado.
 * Todas las rutas bajo auth.member. Macros y caché los gobierna el backend.
 */
class NutritionFoodController extends Controller
{
    public function __construct(
        private NutritionFoodSearchService $searchService,
        private NutritionBarcodeService $barcodeService,
    ) {
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('auth_member');
    }

    /** GET /api/nutrition/foods/search?q= */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|max:120']);
        $foods = $this->searchService->search($request->query('q'), $this->member($request));
        return response()->json(['ok' => true, 'data' => $foods]);
    }

    /** GET /api/nutrition/foods/barcode/{barcode} */
    public function barcode(Request $request, string $barcode): JsonResponse
    {
        $result = $this->barcodeService->lookup($barcode, $this->member($request));
        $status = $result['status'] === 'error' ? 200 : 200; // siempre JSON controlado
        return response()->json(array_merge(['ok' => $result['status'] === 'found'], $result), $status);
    }

    /** GET /api/nutrition/foods/{uuid} */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $food = $this->findVisible($uuid, $this->member($request));
        if (! $food) {
            return response()->json(['ok' => false, 'message' => 'Alimento no encontrado.'], 404);
        }
        return response()->json(['ok' => true, 'data' => $food->toApiArray()]);
    }

    /** POST /api/nutrition/foods — crear alimento manual (privado por defecto). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:160',
            'brand'        => 'nullable|string|max:120',
            'barcode'      => 'nullable|string|max:20',
            'category'     => 'nullable|string|max:120',
            'serving_size' => 'required|numeric|gt:0',
            'serving_unit' => 'nullable|string|max:20',
            'calories'     => 'required|numeric|min:0',
            'protein'      => 'required|numeric|min:0',
            'carbs'        => 'required|numeric|min:0',
            'fat'          => 'required|numeric|min:0',
            'sugar'        => 'nullable|numeric|min:0',
            'fiber'        => 'nullable|numeric|min:0',
            'sodium'       => 'nullable|numeric|min:0',
            'image_url'    => 'nullable|url|max:1024',
        ]);
        $member = $this->member($request);

        $size = (float) $data['serving_size'];
        $factor = 100 / $size;
        $per100 = [];
        foreach (['calories', 'protein', 'carbs', 'fat', 'sugar', 'fiber', 'sodium'] as $k) {
            $val = isset($data[$k]) ? (float) $data[$k] : null;
            $per100[$k . '_per_100g'] = $val === null ? null : round($val * $factor, 2);
            $data[$k . '_per_serving'] = $val === null ? null : round($val, 2);
        }

        $food = NutritionFood::create(array_merge([
            'source'               => 'user',
            'name'                 => $data['name'],
            'brand'                => $data['brand'] ?? null,
            'barcode'              => $data['barcode'] ?? null,
            'category'             => $data['category'] ?? null,
            'serving_size'         => $size,
            'serving_unit'         => $data['serving_unit'] ?? 'g',
            'image_url'            => $data['image_url'] ?? null,
            'created_by_member_id' => $member->id,
            'is_public'            => false, // privado por defecto
            'verified'             => false,
            'confidence_score'     => 1.0, // datos provistos por el usuario
            'calories_per_serving' => $data['calories_per_serving'],
            'protein_per_serving'  => $data['protein_per_serving'],
            'carbs_per_serving'    => $data['carbs_per_serving'],
            'fat_per_serving'      => $data['fat_per_serving'],
            'sugar_per_serving'    => $data['sugar_per_serving'],
            'fiber_per_serving'    => $data['fiber_per_serving'],
            'sodium_per_serving'   => $data['sodium_per_serving'],
        ], $per100));

        \Illuminate\Support\Facades\Log::info('nutrition.food.created', [
            'member_id' => $member->id, 'food' => $food->uuid, 'source' => 'user',
        ]);

        return response()->json(['ok' => true, 'data' => $food->toApiArray()], 201);
    }

    /** PUT /api/nutrition/foods/{uuid} — solo alimentos creados por el miembro. */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $member = $this->member($request);
        $food = NutritionFood::where('uuid', $uuid)
            ->where('created_by_member_id', $member->id)->first();
        if (! $food) {
            return response()->json(['ok' => false, 'message' => 'No puedes editar este alimento.'], 404);
        }
        $data = $request->validate([
            'name'         => 'sometimes|string|max:160',
            'brand'        => 'nullable|string|max:120',
            'category'     => 'nullable|string|max:120',
            'serving_size' => 'sometimes|numeric|gt:0',
            'serving_unit' => 'nullable|string|max:20',
            'calories'     => 'sometimes|numeric|min:0',
            'protein'      => 'sometimes|numeric|min:0',
            'carbs'        => 'sometimes|numeric|min:0',
            'fat'          => 'sometimes|numeric|min:0',
            'sugar'        => 'nullable|numeric|min:0',
            'fiber'        => 'nullable|numeric|min:0',
            'sodium'       => 'nullable|numeric|min:0',
        ]);

        foreach (['name', 'brand', 'category', 'serving_unit'] as $k) {
            if (array_key_exists($k, $data)) {
                $food->{$k} = $data[$k];
            }
        }
        $size = (float) ($data['serving_size'] ?? $food->serving_size ?? 100);
        $food->serving_size = $size;
        $factor = $size > 0 ? 100 / $size : 1;
        foreach (['calories', 'protein', 'carbs', 'fat', 'sugar', 'fiber', 'sodium'] as $k) {
            if (array_key_exists($k, $data)) {
                $val = $data[$k] === null ? null : (float) $data[$k];
                $food->{$k . '_per_serving'} = $val === null ? null : round($val, 2);
                $food->{$k . '_per_100g'} = $val === null ? null : round($val * $factor, 2);
            }
        }
        $food->save();
        return response()->json(['ok' => true, 'data' => $food->toApiArray()]);
    }

    /** DELETE /api/nutrition/foods/{uuid} — solo alimentos del miembro. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $member = $this->member($request);
        $food = NutritionFood::where('uuid', $uuid)
            ->where('created_by_member_id', $member->id)->first();
        if (! $food) {
            return response()->json(['ok' => false, 'message' => 'No puedes eliminar este alimento.'], 404);
        }
        $food->delete();
        return response()->json(['ok' => true]);
    }

    /** POST /api/nutrition/foods/{uuid}/favorite */
    public function favorite(Request $request, string $uuid): JsonResponse
    {
        $member = $this->member($request);
        $food = $this->findVisible($uuid, $member);
        if (! $food) {
            return response()->json(['ok' => false, 'message' => 'Alimento no encontrado.'], 404);
        }
        NutritionFavorite::firstOrCreate(['member_id' => $member->id, 'food_id' => $food->id]);
        return response()->json(['ok' => true]);
    }

    /** DELETE /api/nutrition/foods/{uuid}/favorite */
    public function unfavorite(Request $request, string $uuid): JsonResponse
    {
        $member = $this->member($request);
        $food = NutritionFood::where('uuid', $uuid)->first();
        if ($food) {
            NutritionFavorite::where('member_id', $member->id)->where('food_id', $food->id)->delete();
        }
        return response()->json(['ok' => true]);
    }

    /** GET /api/nutrition/favorites */
    public function favorites(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $foods = NutritionFavorite::where('member_id', $member->id)
            ->with('food')->latest()->limit(50)->get()
            ->map(fn ($f) => $f->food?->toApiArray())->filter()->values();
        return response()->json(['ok' => true, 'data' => $foods]);
    }

    /** GET /api/nutrition/recent */
    public function recent(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $foods = NutritionRecentFood::where('member_id', $member->id)
            ->with('food')->orderByDesc('last_used_at')->limit(30)->get()
            ->map(fn ($r) => $r->food?->toApiArray())->filter()->values();
        return response()->json(['ok' => true, 'data' => $foods]);
    }

    /** Alimento visible para el miembro: público o creado por él. */
    private function findVisible(string $uuid, Member $member): ?NutritionFood
    {
        return NutritionFood::where('uuid', $uuid)
            ->where(function ($q) use ($member) {
                $q->where('is_public', true)->orWhere('created_by_member_id', $member->id);
            })->first();
    }
}
