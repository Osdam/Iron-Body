<?php

namespace App\Http\Controllers\Api\Nutrition;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\NutritionEntry;
use App\Models\NutritionFood;
use App\Services\Nutrition\NutritionEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Entradas de tracking (agregar/listar/eliminar). Macros los calcula el backend. */
class NutritionEntryController extends Controller
{
    public function __construct(private NutritionEntryService $entries)
    {
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('auth_member');
    }

    /** POST /api/nutrition/entries */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'food_uuid'  => 'required|string',
            'meal_type'  => 'required|in:breakfast,lunch,dinner,snack',
            'entry_date' => 'nullable|date',
            'quantity'   => 'required|numeric|gt:0',
            'unit'       => 'required|string|in:g,gr,ml,serving,porcion,unit,unidad,tbsp,tsp,cup,oz',
        ]);
        $member = $this->member($request);

        $food = NutritionFood::where('uuid', $data['food_uuid'])
            ->where(function ($q) use ($member) {
                $q->where('is_public', true)->orWhere('created_by_member_id', $member->id);
            })->first();
        if (! $food) {
            return response()->json(['ok' => false, 'message' => 'Alimento no encontrado.'], 404);
        }

        // No se permite agregar un alimento con macros incompletos: el usuario
        // debe completarlos antes (la app abre el flujo de completar información).
        if (! $food->isMacroComplete()) {
            return response()->json([
                'ok'      => false,
                'code'    => 'food_macros_incomplete',
                'message' => 'Completa la información nutricional antes de agregar este alimento.',
                'food'    => $food->toApiArray(),
            ], 422);
        }

        $entry = $this->entries->addEntry(
            $member, $food, $data['meal_type'],
            $data['entry_date'] ?? null, (float) $data['quantity'], $data['unit']
        );
        $entry = $entry->fresh('food');

        $date = $entry->entry_date instanceof \Carbon\Carbon
            ? $entry->entry_date->toDateString() : (string) $entry->entry_date;

        return response()->json([
            'ok'      => true,
            'message' => 'Alimento agregado a ' . self::mealLabel($entry->meal_type) . '.',
            'data'    => self::present($entry), // compat
            'entry'   => self::present($entry),
            'summary' => $this->entries->summaryPayload($member, $date),
        ], 201);
    }

    private static function mealLabel(string $meal): string
    {
        return [
            'breakfast' => 'desayuno', 'lunch' => 'almuerzo',
            'dinner' => 'cena', 'snack' => 'merienda',
        ][$meal] ?? 'comida';
    }

    /** GET /api/nutrition/entries?date=YYYY-MM-DD */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['date' => 'nullable|date']);
        $member = $this->member($request);
        $date = $request->query('date') ?: $this->entries->today();

        $entries = NutritionEntry::where('member_id', $member->id)
            ->whereDate('entry_date', $date)
            ->with('food')->orderBy('id')->get()
            ->map(fn (NutritionEntry $e) => self::present($e))->values();

        return response()->json(['ok' => true, 'date' => $date, 'data' => $entries]);
    }

    /** DELETE /api/nutrition/entries/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $member = $this->member($request);
        $entry = NutritionEntry::where('uuid', $uuid)->where('member_id', $member->id)->first();
        if (! $entry) {
            return response()->json(['ok' => false, 'message' => 'Entrada no encontrada.'], 404);
        }
        $this->entries->deleteEntry($member, $entry);
        return response()->json(['ok' => true]);
    }

    /** Formato unificado de entrada. */
    public static function present(NutritionEntry $e): array
    {
        return [
            'uuid'       => $e->uuid,
            'meal_type'  => $e->meal_type,
            'entry_date' => $e->entry_date instanceof \Carbon\Carbon
                ? $e->entry_date->toDateString() : (string) $e->entry_date,
            'quantity'   => (float) $e->quantity,
            'unit'       => $e->unit,
            'food'       => $e->food?->toApiArray(),
            'macros'     => [
                'calories' => (float) $e->calories,
                'protein'  => (float) $e->protein,
                'carbs'    => (float) $e->carbs,
                'fat'      => (float) $e->fat,
                'sugar'    => $e->sugar,
                'fiber'    => $e->fiber,
                'sodium'   => $e->sodium,
            ],
        ];
    }
}
