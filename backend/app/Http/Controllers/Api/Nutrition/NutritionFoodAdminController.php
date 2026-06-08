<?php

namespace App\Http\Controllers\Api\Nutrition;

use App\Http\Controllers\Controller;
use App\Models\NutritionFood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moderación de alimentos comunitarios (CRM admin — mismo patrón abierto que el
 * resto del CRM interno). Staff verifica, rechaza o fusiona duplicados. No
 * expone datos sensibles; registra auditoría con el admin que revisa.
 */
class NutritionFoodAdminController extends Controller
{
    /** GET /api/admin/nutrition/foods/pending — comunitarios por revisar. */
    public function pending(Request $request): JsonResponse
    {
        $foods = NutritionFood::query()
            ->whereIn('verification_status', [NutritionFood::VS_COMMUNITY, NutritionFood::VS_PENDING])
            ->whereNull('canonical_food_id')
            ->orderByDesc('reports_count')
            ->orderByDesc('community_confirmations_count')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (NutritionFood $f) => $this->adminRow($f));

        return response()->json(['ok' => true, 'data' => $foods]);
    }

    /** GET /api/admin/nutrition/foods/{uuid} */
    public function show(string $uuid): JsonResponse
    {
        $food = NutritionFood::where('uuid', $uuid)->first();
        if (! $food) {
            return response()->json(['ok' => false, 'message' => 'Alimento no encontrado.'], 404);
        }
        return response()->json(['ok' => true, 'data' => $this->adminRow($food)]);
    }

    /** POST /api/admin/nutrition/foods/{uuid}/verify */
    public function verify(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['admin_id' => 'nullable|integer']);
        $food = NutritionFood::where('uuid', $uuid)->first();
        if (! $food) {
            return response()->json(['ok' => false, 'message' => 'Alimento no encontrado.'], 404);
        }
        if (! $food->isMacroComplete()) {
            return response()->json([
                'ok' => false, 'message' => 'No se puede verificar un alimento con datos incompletos.',
            ], 422);
        }
        $food->verification_status = NutritionFood::VS_VERIFIED;
        $food->visibility = NutritionFood::VIS_VERIFIED;
        $food->verified = true;
        $food->is_public = true;
        $food->verified_by_admin_id = $data['admin_id'] ?? null;
        $food->verified_at = now();
        $food->confidence_score = max((float) ($food->confidence_score ?? 0), 0.95);
        $food->save();

        Log::info('nutrition.admin.verify', ['food' => $food->uuid, 'admin' => $data['admin_id'] ?? null]);
        return response()->json(['ok' => true, 'data' => $this->adminRow($food->fresh())]);
    }

    /** POST /api/admin/nutrition/foods/{uuid}/reject */
    public function reject(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'admin_id' => 'nullable|integer',
            'reason'   => 'nullable|string|max:255',
        ]);
        $food = NutritionFood::where('uuid', $uuid)->first();
        if (! $food) {
            return response()->json(['ok' => false, 'message' => 'Alimento no encontrado.'], 404);
        }
        $food->verification_status = NutritionFood::VS_REJECTED;
        $food->is_public = false; // fuera de búsquedas generales
        $food->verified_by_admin_id = $data['admin_id'] ?? null;
        $food->verified_at = now();
        $food->save();

        Log::info('nutrition.admin.reject', [
            'food' => $food->uuid, 'admin' => $data['admin_id'] ?? null,
        ]);
        return response()->json(['ok' => true, 'data' => $this->adminRow($food->fresh())]);
    }

    /**
     * POST /api/admin/nutrition/foods/{uuid}/merge — fusiona un duplicado dentro
     * de un alimento canónico. Re-apunta entradas/favoritos/recientes y marca el
     * duplicado como fusionado (canonical_food_id) y fuera de búsquedas.
     */
    public function merge(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'canonical_uuid' => 'required|string',
            'admin_id'       => 'nullable|integer',
        ]);
        $dup = NutritionFood::where('uuid', $uuid)->first();
        $canonical = NutritionFood::where('uuid', $data['canonical_uuid'])->first();
        if (! $dup || ! $canonical) {
            return response()->json(['ok' => false, 'message' => 'Alimento no encontrado.'], 404);
        }
        if ($dup->id === $canonical->id) {
            return response()->json(['ok' => false, 'message' => 'No se puede fusionar consigo mismo.'], 422);
        }

        DB::transaction(function () use ($dup, $canonical, $data) {
            // Re-apuntar referencias del duplicado al canónico.
            DB::table('nutrition_entries')->where('food_id', $dup->id)->update(['food_id' => $canonical->id]);
            // Favoritos/recientes: re-apuntar evitando choque con uniques existentes.
            foreach (['nutrition_favorites', 'nutrition_recent_foods'] as $tbl) {
                $rows = DB::table($tbl)->where('food_id', $dup->id)->get();
                foreach ($rows as $row) {
                    $exists = DB::table($tbl)
                        ->where('member_id', $row->member_id)
                        ->where('food_id', $canonical->id)->exists();
                    if ($exists) {
                        DB::table($tbl)->where('id', $row->id)->delete();
                    } else {
                        DB::table($tbl)->where('id', $row->id)->update(['food_id' => $canonical->id]);
                    }
                }
            }
            $canonical->community_confirmations_count =
                (int) $canonical->community_confirmations_count + (int) $dup->community_confirmations_count;
            $canonical->save();

            $dup->canonical_food_id = $canonical->id;
            $dup->verification_status = NutritionFood::VS_REJECTED;
            $dup->is_public = false;
            $dup->verified_by_admin_id = $data['admin_id'] ?? null;
            $dup->verified_at = now();
            $dup->save();
        });

        Log::info('nutrition.admin.merge', [
            'dup' => $dup->uuid, 'canonical' => $canonical->uuid, 'admin' => $data['admin_id'] ?? null,
        ]);
        return response()->json(['ok' => true, 'data' => $this->adminRow($canonical->fresh())]);
    }

    /** Fila admin (incluye metadatos de moderación, sin datos sensibles). */
    private function adminRow(NutritionFood $food): array
    {
        return array_merge($food->toApiArray(), [
            'id'                            => $food->id,
            'created_by_member_id'          => $food->created_by_member_id,
            'reports_count'                 => (int) $food->reports_count,
            'community_confirmations_count' => (int) $food->community_confirmations_count,
            'verified_at'                   => optional($food->verified_at)->toIso8601String(),
            'canonical_food_id'             => $food->canonical_food_id,
            'version'                       => (int) $food->version,
        ]);
    }
}
