<?php

namespace App\Services;

use App\Models\Member;
use App\Models\NutritionFoodItem;
use App\Models\NutritionGoal;
use App\Models\NutritionMealItem;
use App\Models\NutritionMealLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lógica real de nutrición (PostgreSQL es la fuente de verdad).
 *
 * Calcula el día, los macros consumidos vs meta, la racha nutricional y el
 * historial semanal — todo desde la BD, con timezone America/Bogota. Reemplaza
 * el cálculo local en SharedPreferences que tenía la app.
 */
class NutritionService
{
    public const TZ = 'America/Bogota';
    public const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snacks'];

    /** Meta por defecto si el miembro aún no configuró una (no se persiste). */
    private const DEFAULT_GOAL = [
        'daily_calories' => 2200,
        'protein_g' => 150,
        'carbs_g' => 250,
        'fat_g' => 70,
        'goal_type' => null,
    ];

    public function today(string $tz): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /** Resumen completo de un día (meta, macros, comidas, racha, historial). */
    public function dayPayload(Member $member, ?string $date = null): array
    {
        $day = $date !== null
            ? CarbonImmutable::parse($date, self::TZ)->startOfDay()
            : $this->today(self::TZ);
        $dateStr = $day->toDateString();

        $goal = $this->activeGoal($member);
        $meals = $this->mealsForDay($member, $dateStr);

        // Totales del día.
        $totals = ['calories' => 0.0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0];
        foreach ($meals as $meal) {
            foreach ($meal['items'] as $item) {
                $totals['calories'] += $item['calories'];
                $totals['protein_g'] += $item['protein_g'];
                $totals['carbs_g'] += $item['carbs_g'];
                $totals['fat_g'] += $item['fat_g'];
            }
        }

        $hasAnyItem = collect($meals)->contains(fn ($m) => count($m['items']) > 0);

        return [
            'date' => $dateStr,
            'goal' => $goal,
            'consumed' => [
                'calories' => round($totals['calories'], 1),
                'protein_g' => round($totals['protein_g'], 1),
                'carbs_g' => round($totals['carbs_g'], 1),
                'fat_g' => round($totals['fat_g'], 1),
            ],
            'remaining' => [
                'calories' => round($goal['daily_calories'] - $totals['calories'], 1),
                'protein_g' => round($goal['protein_g'] - $totals['protein_g'], 1),
                'carbs_g' => round($goal['carbs_g'] - $totals['carbs_g'], 1),
                'fat_g' => round($goal['fat_g'] - $totals['fat_g'], 1),
            ],
            'meals' => $meals,
            'has_data' => $hasAnyItem,
            'streak' => $this->nutritionStreak($member, $day),
            'weekly_history' => $this->weeklyHistory($member, $day, $goal['daily_calories']),
        ];
    }

    /** Meta activa del miembro, o la default si no tiene. */
    public function activeGoal(Member $member): array
    {
        $goal = NutritionGoal::query()
            ->where('member_id', $member->id)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        return $goal?->toPublicArray() ?? self::DEFAULT_GOAL;
    }

    /** Crea/actualiza la meta activa (desactiva la anterior). */
    public function saveGoal(Member $member, array $data): array
    {
        return DB::transaction(function () use ($member, $data) {
            NutritionGoal::query()
                ->where('member_id', $member->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $goal = NutritionGoal::create([
                'member_id' => $member->id,
                'daily_calories' => (int) $data['daily_calories'],
                'protein_g' => (int) $data['protein_g'],
                'carbs_g' => (int) $data['carbs_g'],
                'fat_g' => (int) $data['fat_g'],
                'goal_type' => $data['goal_type'] ?? null,
                'is_active' => true,
            ]);

            return $goal->toPublicArray();
        });
    }

    /** Comidas del día (las 4 categorías siempre presentes, con sus items). */
    public function mealsForDay(Member $member, string $dateStr): array
    {
        $logs = NutritionMealLog::query()
            ->with(['items.foodItem'])
            ->where('member_id', $member->id)
            ->whereDate('log_date', $dateStr)
            ->get()
            ->keyBy('meal_type');

        $out = [];
        foreach (self::MEAL_TYPES as $type) {
            $log = $logs->get($type);
            $items = $log
                ? $log->items->map(fn (NutritionMealItem $i) => $i->toPublicArray())->all()
                : [];
            $out[] = [
                'meal_type' => $type,
                'items' => $items,
                'totals' => $this->sumItems($items),
            ];
        }
        return $out;
    }

    private function sumItems(array $items): array
    {
        $c = $p = $ca = $f = 0.0;
        foreach ($items as $i) {
            $c += $i['calories'];
            $p += $i['protein_g'];
            $ca += $i['carbs_g'];
            $f += $i['fat_g'];
        }
        return [
            'calories' => round($c, 1),
            'protein_g' => round($p, 1),
            'carbs_g' => round($ca, 1),
            'fat_g' => round($f, 1),
        ];
    }

    /**
     * Agrega un alimento a una comida del día (crea el meal_log si no existe).
     * Calcula el snapshot de macros para la cantidad. quantity = nº de porciones.
     */
    public function addItem(Member $member, string $mealType, array $data): array
    {
        $dateStr = $this->today(self::TZ)->toDateString();

        $log = NutritionMealLog::firstOrCreate([
            'member_id' => $member->id,
            'log_date' => $dateStr,
            'meal_type' => $mealType,
        ]);

        $qty = (float) ($data['quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1;
        }

        // Si viene food_item_id, tomamos sus macros base × cantidad.
        $food = isset($data['food_item_id'])
            ? NutritionFoodItem::find($data['food_item_id'])
            : null;

        if ($food !== null) {
            $calories = $food->calories * $qty;
            $protein = $food->protein_g * $qty;
            $carbs = $food->carbs_g * $qty;
            $fat = $food->fat_g * $qty;
            $name = $food->name;
            $serving = $food->serving_label;
        } else {
            // Alimento libre: macros vienen en el payload (snapshot directo).
            $calories = (float) ($data['calories'] ?? 0);
            $protein = (float) ($data['protein_g'] ?? 0);
            $carbs = (float) ($data['carbs_g'] ?? 0);
            $fat = (float) ($data['fat_g'] ?? 0);
            $name = $data['custom_name'] ?? 'Alimento';
            $serving = $data['serving_label'] ?? null;
        }

        $item = NutritionMealItem::create([
            'meal_log_id' => $log->id,
            'food_item_id' => $food?->id,
            'custom_name' => $food !== null ? null : $name,
            'quantity' => $qty,
            'serving_label' => $serving,
            'calories' => round($calories, 2),
            'protein_g' => round($protein, 2),
            'carbs_g' => round($carbs, 2),
            'fat_g' => round($fat, 2),
        ]);

        return $item->fresh('foodItem')->toPublicArray();
    }

    /** Elimina un item, validando que pertenezca al miembro. Devuelve true si borró. */
    public function deleteItem(Member $member, int $itemId): bool
    {
        $item = NutritionMealItem::query()
            ->whereHas('mealLog', fn ($q) => $q->where('member_id', $member->id))
            ->find($itemId);

        if ($item === null) {
            return false;
        }
        $item->delete();
        return true;
    }

    /**
     * Racha nutricional: días consecutivos (hasta hoy/ayer) con al menos un
     * alimento registrado. Real desde la BD.
     */
    public function nutritionStreak(Member $member, CarbonImmutable $today): array
    {
        // Fechas con al menos un item registrado (últimos 60 días para acotar).
        $since = $today->subDays(60)->toDateString();
        $dates = NutritionMealLog::query()
            ->where('member_id', $member->id)
            ->whereDate('log_date', '>=', $since)
            ->whereHas('items')
            ->orderBy('log_date')
            ->pluck('log_date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->unique()
            ->values()
            ->all();

        if (empty($dates)) {
            return ['current' => 0, 'has_logged_today' => false];
        }

        $set = array_flip($dates);
        $current = 0;
        $cursor = $today;
        if (!isset($set[$today->toDateString()])) {
            $cursor = $today->subDay(); // hoy sin registro → racha vive desde ayer
        }
        while (isset($set[$cursor->toDateString()])) {
            $current++;
            $cursor = $cursor->subDay();
        }

        return [
            'current' => $current,
            'has_logged_today' => isset($set[$today->toDateString()]),
        ];
    }

    /**
     * Historial semanal real (lun-dom): calorías consumidas por día vs meta.
     */
    public function weeklyHistory(Member $member, CarbonImmutable $today, int $goalCalories): array
    {
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(6);

        // Suma de calorías por fecha en la semana.
        $rows = NutritionMealItem::query()
            ->join('nutrition_meal_logs', 'nutrition_meal_items.meal_log_id', '=', 'nutrition_meal_logs.id')
            ->where('nutrition_meal_logs.member_id', $member->id)
            ->whereBetween('nutrition_meal_logs.log_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->groupBy('nutrition_meal_logs.log_date')
            ->selectRaw('nutrition_meal_logs.log_date as d, SUM(nutrition_meal_items.calories) as cal')
            ->pluck('cal', 'd');

        $labels = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->addDays($i);
            $cal = (float) ($rows[$date->toDateString()] ?? 0);
            $out[] = [
                'label' => $labels[$i],
                'date' => $date->toDateString(),
                'calories' => round($cal, 1),
                'goal_met' => $goalCalories > 0 && $cal >= $goalCalories * 0.9 && $cal <= $goalCalories * 1.1,
                'is_today' => $date->toDateString() === $today->toDateString(),
            ];
        }
        return $out;
    }
}
