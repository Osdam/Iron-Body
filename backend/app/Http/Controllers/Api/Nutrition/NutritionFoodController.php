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

    /**
     * POST /api/nutrition/foods — crear alimento.
     *
     * - SIN barcode → alimento privado del usuario (solo él).
     * - CON barcode nuevo → CONTRIBUCIÓN COMUNITARIA (visibility=community): queda
     *   disponible para otros usuarios, marcado como aportado (no verificado).
     * - CON barcode existente → NO duplica: completa el incompleto o devuelve el
     *   que ya existe (idempotente). Usa transacción + lockForUpdate para evitar
     *   duplicados en creación concurrente del mismo barcode.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:160',
            'brand'        => 'nullable|string|max:120',
            'barcode'      => 'nullable|string|max:32',
            'category'     => 'nullable|string|max:120',
            'serving_size' => 'required|numeric|gt:0',
            'serving_unit' => 'nullable|string|max:20',
            'calories'     => 'required|numeric|min:0|max:9000',
            'protein'      => 'required|numeric|min:0|max:1000',
            'carbs'        => 'required|numeric|min:0|max:1000',
            'fat'          => 'required|numeric|min:0|max:1000',
            'sugar'        => 'nullable|numeric|min:0|max:1000',
            'fiber'        => 'nullable|numeric|min:0|max:1000',
            'sodium'       => 'nullable|numeric|min:0|max:100000',
            'image_url'    => 'nullable|url|max:1024',
        ]);
        $member = $this->member($request);
        $barcode = isset($data['barcode']) ? (preg_replace('/\D/', '', $data['barcode']) ?: null) : null;

        $size = (float) $data['serving_size'];
        $factor = 100 / $size;
        $perServing = [];
        $per100 = [];
        foreach (['calories', 'protein', 'carbs', 'fat', 'sugar', 'fiber', 'sodium'] as $k) {
            $val = isset($data[$k]) && $data[$k] !== null ? max(0.0, (float) $data[$k]) : null;
            $perServing[$k . '_per_serving'] = $val === null ? null : round($val, 2);
            $per100[$k . '_per_100g'] = $val === null ? null : round($val * $factor, 2);
        }

        // ── Con barcode: anti-duplicado concurrente (lock por barcode) ──────────
        if ($barcode) {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($member, $data, $barcode, $size, $perServing, $per100) {
                $existing = NutritionFood::where('barcode', $barcode)->lockForUpdate()->first();
                if ($existing) {
                    // Ya existe: si está incompleto y es completable, se completa;
                    // si ya está completo, se devuelve (idempotente, sin duplicar).
                    if (! $existing->isMacroComplete()) {
                        $this->applyMacros($existing, $data, $size, $perServing, $per100);
                        $existing->source = $existing->source === 'iron_body' ? 'iron_body' : 'community';
                        $existing->visibility = NutritionFood::VIS_COMMUNITY;
                        $existing->verification_status = NutritionFood::VS_COMMUNITY;
                        $existing->is_public = true;
                        $existing->version = (int) $existing->version + 1;
                        $existing->save();
                    }
                    return response()->json([
                        'ok' => true, 'data' => $existing->fresh()->toApiArray(),
                        'deduplicated' => true,
                    ], 200);
                }

                $food = $this->createCommunityFood($member, $data, $barcode, $size, $perServing, $per100);
                return response()->json(['ok' => true, 'data' => $food->toApiArray()], 201);
            });
        }

        // ── Sin barcode: privado del usuario (con dedupe anti doble-tap) ────────
        $window = (int) config('nutrition.community.idempotency_window_seconds', 15);
        $dupe = NutritionFood::where('created_by_member_id', $member->id)
            ->where('normalized_name', NutritionFood::normalize($data['name']))
            ->where('calories_per_serving', $perServing['calories_per_serving'])
            ->where('created_at', '>=', now()->subSeconds($window))
            ->first();
        if ($dupe) {
            return response()->json(['ok' => true, 'data' => $dupe->toApiArray(), 'deduplicated' => true], 200);
        }

        $food = NutritionFood::create(array_merge([
            'source'               => 'user',
            'name'                 => $data['name'],
            'brand'                => $data['brand'] ?? null,
            'barcode'              => null,
            'category'             => $data['category'] ?? null,
            'serving_size'         => $size,
            'serving_unit'         => $data['serving_unit'] ?? 'g',
            'image_url'            => $data['image_url'] ?? null,
            'created_by_member_id' => $member->id,
            'is_public'            => false, // privado por defecto
            'visibility'           => NutritionFood::VIS_PRIVATE,
            'verification_status'  => NutritionFood::VS_PRIVATE,
            'verified'             => false,
            'confidence_score'     => 1.0, // datos provistos por el usuario
        ], $perServing, $per100));

        \Illuminate\Support\Facades\Log::info('nutrition.food.created', [
            'member_id' => $member->id, 'food' => $food->uuid, 'source' => 'user', 'visibility' => 'private',
        ]);

        return response()->json(['ok' => true, 'data' => $food->toApiArray()], 201);
    }

    /** Crea un alimento comunitario (barcode nuevo) disponible para todos. */
    private function createCommunityFood(Member $member, array $data, string $barcode, float $size, array $perServing, array $per100): NutritionFood
    {
        $food = NutritionFood::create(array_merge([
            'source'               => 'community',
            'name'                 => $data['name'],
            'brand'                => $data['brand'] ?? null,
            'barcode'              => $barcode,
            'category'             => $data['category'] ?? null,
            'serving_size'         => $size,
            'serving_unit'         => $data['serving_unit'] ?? 'g',
            'image_url'            => $data['image_url'] ?? null,
            'created_by_member_id' => $member->id,
            'is_public'            => true,
            'visibility'           => NutritionFood::VIS_COMMUNITY,
            'verification_status'  => NutritionFood::VS_COMMUNITY,
            'verified'             => false,
            'confidence_score'     => 0.85, // comunitario sin verificar
        ], $perServing, $per100));

        \Illuminate\Support\Facades\Log::info('nutrition.food.created', [
            'member_id' => $member->id, 'food' => $food->uuid,
            'source' => 'community', 'visibility' => 'community',
        ]);
        return $food;
    }

    /** Aplica macros (por porción + per 100g) a un alimento existente. */
    private function applyMacros(NutritionFood $food, array $data, float $size, array $perServing, array $per100): void
    {
        $food->serving_size = $size;
        $food->serving_unit = $data['serving_unit'] ?? ($food->serving_unit ?: 'g');
        if (! empty($data['name'])) {
            $food->name = $data['name'];
        }
        foreach ($perServing as $col => $val) {
            $food->{$col} = $val;
        }
        foreach ($per100 as $col => $val) {
            $food->{$col} = $val;
        }
    }

    /** PUT /api/nutrition/foods/{uuid} — solo alimentos creados por el miembro. */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $member = $this->member($request);
        $food = NutritionFood::where('uuid', $uuid)->first();
        // Se puede editar: un alimento propio, O completar uno externo público
        // que llegó SIN macros (completar datos del producto, no duplicar).
        $canEdit = $food
            && ($food->created_by_member_id === $member->id
                || ($food->is_public && ! $food->isMacroComplete()));
        if (! $canEdit) {
            return response()->json(['ok' => false, 'message' => 'No puedes editar este alimento.'], 404);
        }
        $completingExternal = $food->created_by_member_id !== $member->id;
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
        // Al completar un externo con datos del usuario, sube la confianza.
        if ($completingExternal && $food->isMacroComplete()) {
            $food->confidence_score = max((float) ($food->confidence_score ?? 0), 0.9);
        }
        $food->save();
        return response()->json(['ok' => true, 'data' => $food->fresh()->toApiArray()]);
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

    /** POST /api/nutrition/foods/{uuid}/report — reporta datos incorrectos. */
    public function report(Request $request, string $uuid): JsonResponse
    {
        $member = $this->member($request);
        $food = $this->findVisible($uuid, $member);
        if (! $food) {
            return response()->json(['ok' => false, 'message' => 'Alimento no encontrado.'], 404);
        }
        // Un alimento verificado por staff no se oculta por reportes (sí se cuenta).
        $food->increment('reports_count');
        $hidden = $food->reports_count >= NutritionFood::reportsHideThreshold()
            && $food->verification_status !== NutritionFood::VS_VERIFIED;
        \Illuminate\Support\Facades\Log::info('nutrition.food.reported', [
            'member_id' => $member->id, 'food' => $food->uuid,
            'reports' => $food->reports_count, 'hidden' => $hidden,
        ]);
        return response()->json([
            'ok' => true, 'message' => 'Gracias, revisaremos este producto.', 'hidden' => $hidden,
        ]);
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
