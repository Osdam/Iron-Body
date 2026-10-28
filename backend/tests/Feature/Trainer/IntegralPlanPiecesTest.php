<?php

namespace Tests\Feature\Trainer;

use App\Models\MembershipAiCapability;
use App\Models\Plan;
use App\Services\IronAiMembershipAccessService;
use App\Services\Trainer\IntegralPlanAssembler;
use App\Services\Trainer\IntegralPlanEvaluator;
use App\Services\Trainer\MemberContextAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las piezas deterministas del plan por niveles, probadas sin generar nada:
 * la rúbrica, la lista de mercado, la lectura del objetivo y la resolución de
 * las potencias por módulo. Ronda final de revisión (28-10-2026).
 */
class IntegralPlanPiecesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Revisor (bloqueante): en Ana, corrida 3, 33 de 35 comidas llevaban
     * EXACTAMENTE la meta por comida. Eso no es una estimación, es una copia,
     * y la rúbrica tiene que puntuarlo mal.
     */
    public function test_la_rubrica_castiga_macros_copiados_de_la_meta(): void
    {
        $meta = ['protein_g' => 30, 'carbs_g' => 43, 'fat_g' => 14];
        $kcal = 4 * 30 + 4 * 43 + 9 * 14;
        $semana = [];
        $comidas = [];
        foreach (['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'] as $d => $dia) {
            $meals = [];
            foreach (['desayuno', 'media_manana', 'almuerzo', 'algo', 'cena'] as $j => $slot) {
                $copia = ($d * 5 + $j) >= 2; // 33 de 35
                $m = $copia ? $meta : ['protein_g' => 21.5, 'carbs_g' => 60.2, 'fat_g' => 9.1];
                $k = (int) round(4 * $m['protein_g'] + 4 * $m['carbs_g'] + 9 * $m['fat_g']);
                $meals[] = [
                    'slot' => $slot, 'title' => "Plato {$dia} {$slot}",
                    // Como Ana: el plato «da» la meta aunque sean 50 g de manzana
                    // (P+C+G = 87 g en 50 g de alimento es físicamente imposible).
                    'items' => [['food' => 'Manzana', 'amount' => $copia ? 50.0 : 150.0, 'unit' => 'g', 'cooked_g' => null, 'grams' => $copia ? 50.0 : 150.0, 'protein_g' => $m['protein_g'], 'carbs_g' => $m['carbs_g'], 'fat_g' => $m['fat_g']]],
                    'preparation' => 'x', 'substitutions' => 'y',
                    'macros' => ['kcal' => $k] + $m,
                    'target' => $meta + ['kcal' => $kcal],
                ];
                $comidas[] = ['label' => "{$dia} · {$slot}", 'time' => '07:00', 'description' => "Plato\nIngredientes: manzana 150 g.\nAporte estimado: {$k} kcal · proteína x g.\nPreparación: x.\nSustitución: y.", 'order' => count($comidas)];
            }
            $semana[] = ['day' => $dia, 'day_type' => $d, 'meals' => $meals, 'totals' => null, 'note' => null];
        }

        $eval = app(IntegralPlanEvaluator::class)->evaluate(
            ['nutrition' => ['objective' => 'Bajar grasa', 'recommendations' => 'METAS', 'restrictions' => 'x', 'meals' => $comidas], 'routine' => ['days' => []]],
            ['nutrition' => ['week' => $semana, 'shopping_list' => [['food' => 'Manzana', 'amount' => 5250.0, 'unit' => 'g', 'category' => 'Frutas']]]],
            ['targets' => null, 'restrictions' => [], 'assumptions' => [], 'safety_mode' => false, 'is_minor' => false, 'training_level' => 'intermedio', 'days_available' => 3],
            5, 1,
        );

        $checks = array_column($eval['checks'], 'ok', 'id');
        $this->assertFalse($checks['meals.macros_not_copied'], '33/35 comidas iguales a la meta son una copia');
        $this->assertFalse($checks['meals.ingredients_plausible'], '87 g de macros en 50 g de manzana no es posible');
        $this->assertLessThanOrEqual(60, $eval['scores']['coherence']);
    }

    /** Menores: agua fuera, unidades convertidas donde la conversión es clara, sinónimos y categorías. */
    public function test_la_lista_de_mercado_excluye_agua_convierte_unidades_y_une_sinonimos(): void
    {
        $item = fn (string $f, float $a, string $u) => ['food' => $f, 'amount' => $a, 'unit' => $u, 'cooked_g' => null];
        $semana = [['meals' => [['items' => [
            $item('Agua', 300, 'ml'), $item('Aceite de oliva', 10, 'ml'), $item('Aceite de oliva', 1, 'cucharada'), $item('Aceite de oliva', 9.2, 'g'),
            $item('Huevo', 2, 'unidad'), $item('Huevo', 100, 'g'), $item('Banana', 120, 'g'), $item('Banano', 100, 'g'),
            $item('Plátano maduro', 150, 'g'), $item('Pimiento rojo', 50, 'g'), $item('Pimentón rojo', 30, 'g'),
            $item('Almendras', 15, 'g'), $item('Nueces', 10, 'g'), $item('Guayaba', 100, 'g'), $item('Mandarina', 1, 'unidad'),
        ]]]]];

        $lista = app(IntegralPlanAssembler::class)->shoppingList($semana);
        $por = [];
        foreach ($lista as $l) {
            $por[mb_strtolower($l['food']).'|'.$l['unit']] = $l;
        }

        $this->assertArrayNotHasKey('agua|ml', $por, 'el agua no se compra');
        $this->assertEquals(35, $por['aceite de oliva|ml']['amount'], '10 ml + 1 cucharada (15 ml) + 9,2 g (10 ml)');
        $this->assertEquals(4, $por['huevo|unidad']['amount'], '2 unidades + 100 g (2 huevos)');
        $this->assertEquals(220, $por['banano|g']['amount'], 'banana y banano son lo mismo');
        $this->assertEquals(150, $por['plátano maduro|g']['amount'], 'el plátano NO es banano');
        $this->assertEquals(80, $por['pimentón rojo|g']['amount']);
        $this->assertSame('Frutos secos y semillas', $por['almendras|g']['category']);
        $this->assertSame('Frutos secos y semillas', $por['nueces|g']['category']);
        $this->assertSame('Frutas', $por['guayaba|g']['category']);
        $this->assertSame('Frutas', $por['mandarina|unidad']['category']);
        $this->assertSame('Frutas', $por['banano|g']['category']);
    }

    /** El balance: por rol, con límites por ingrediente (×0,6–×1,8) y redondeo práctico. */
    public function test_el_balance_escala_por_rol_con_limites_y_redondeo(): void
    {
        $it = fn (string $f, float $a, string $u, string $r, float $p, float $c, float $g, ?float $grams = null) => ['food' => $f, 'amount' => $a, 'unit' => $u, 'cooked_g' => null, 'grams' => $grams ?? $a, 'role' => $r, 'protein_g' => $p, 'carbs_g' => $c, 'fat_g' => $g];
        $meals = [
            'almuerzo' => ['slot' => 'almuerzo', 'items' => [
                $it('Pechuga de pollo', 100, 'g', 'protein', 31, 0, 3.6),
                $it('Arroz blanco', 80, 'g', 'carb', 5.6, 63.2, 0.5),
                $it('Aceite de oliva', 5, 'ml', 'fat', 0, 0, 4.6),
                $it('Brócoli', 80, 'g', 'other', 2.2, 5.6, 0.3),
                $it('Sal', 0, 'al_gusto', 'other', 0, 0, 0, 0),
            ]],
            'desayuno' => ['slot' => 'desayuno', 'items' => [
                $it('Huevo', 2, 'unidad', 'protein', 12.6, 0.8, 10, 100),
                $it('Arepa de maíz', 1, 'unidad', 'carb', 2.5, 25, 2, 60),
            ]],
        ];

        $r = app(IntegralPlanAssembler::class)->balanceDay($meals, ['protein_g' => 200, 'carbs_g' => 120, 'fat_g' => 40]);
        $por = fn (string $slot, string $f) => collect($r['meals'][$slot]['items'])->firstWhere('food', $f);

        // Proteína: pedía ×4,2; se queda en el tope ×1,8 (y redondeado).
        $this->assertEquals(180, $por('almuerzo', 'Pechuga de pollo')['amount']);
        $this->assertEquals(3.5, $por('desayuno', 'Huevo')['amount'], 'piezas de media en media');
        $this->assertEqualsWithDelta(55.8, $por('almuerzo', 'Pechuga de pollo')['protein_g'], 0.1, 'macros = tasa del modelo × cantidad nueva');
        $this->assertEquals(175, $por('desayuno', 'Huevo')['grams']);
        // Lo que no tiene rol que escalar, igual.
        $this->assertEquals(80, $por('almuerzo', 'Brócoli')['amount']);
        $this->assertEquals(0, $por('almuerzo', 'Sal')['amount']);
        // Los gramos se redondean a 5 (o a 10 por encima de 100).
        foreach ($r['meals'] as $m) {
            foreach ($m['items'] as $i) {
                if ($i['unit'] === 'g' && $i['amount'] > 0) {
                    $this->assertSame(0.0, fmod($i['amount'], $i['amount'] >= 100 ? 10 : 5), $i['food']);
                }
            }
        }
        $this->assertSame(1.8, $r['factors']['protein']);
        $suma = array_sum(array_map(fn ($m) => $m['macros']['protein_g'], $r['meals']));
        $this->assertEqualsWithDelta($suma, array_sum(array_map(fn ($m) => array_sum(array_column($m['items'], 'protein_g')), $r['meals'])), 0.2);
    }

    /** Revisor 5: ganar peso es ganar, aunque la frase hable de grasa. */
    public function test_el_objetivo_de_ganancia_se_lee_como_ganancia(): void
    {
        $a = app(MemberContextAssembler::class);
        $clave = fn (string $objetivo) => $a->buildBrief([
            'member' => ['age_years' => 30, 'gender' => 'masculino', 'is_minor' => false],
            'body_composition' => ['weight_kg' => null, 'height_cm' => null, 'age_years' => 30],
            'goal' => ['objective' => $objetivo, 'objective_description' => null],
            'training' => ['training_level' => 'intermedio', 'injuries' => null],
            'nutrition_constraints' => ['restrictions' => null],
            'trainer_notes' => ['observations' => null, 'recommendations' => null],
        ], null, null)['objective_key'];

        foreach (['Ganar peso', 'Aumentar de peso', 'Subir de peso', 'Ganar masa sin ganar grasa', 'Hipertrofia', 'Ganar masa muscular'] as $o) {
            $this->assertSame('muscle_gain', $clave($o), $o);
        }
        foreach (['Bajar grasa', 'Perder peso', 'Bajar de peso y tonificar', 'Bajar grasa y ganar músculo'] as $o) {
            $this->assertSame('fat_loss', $clave($o), $o);
        }
        $this->assertSame('strength', $clave('Ganar fuerza'));
        $this->assertSame('endurance', $clave('Mejorar resistencia para correr'));
    }

    /**
     * M1 (decisión tomada): la potencia de nutrición exige Iron IA y el módulo
     * Nutrición; la de rutina, Iron IA y el módulo Entrenar. Si no, nivel 1.
     */
    public function test_cada_potencia_exige_su_modulo_activo_en_el_plan(): void
    {
        $poderes = function (array $features): array {
            $plan = Plan::create(['name' => 'P '.uniqid(), 'price' => 1, 'duration_days' => 30, 'active' => true, 'features' => $features]);
            MembershipAiCapability::create(['membership_plan_id' => $plan->id, 'plan_code' => 'p'.$plan->id, 'is_active' => true, 'ai_enabled' => true, 'nutrition_power' => 5, 'routine_power' => 4]);
            $r = app(IronAiMembershipAccessService::class)->getPlanPowers(['active' => true, 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'plan' => $plan->fresh(), 'end_date' => null]);

            return [$r['nutrition'], $r['routine']];
        };

        $this->assertSame([5, 4], $poderes(['iron_ia' => true, 'nutrition' => true, 'workouts' => true]));
        $this->assertSame([1, 4], $poderes(['iron_ia' => true, 'nutrition' => false, 'workouts' => true]));
        $this->assertSame([5, 1], $poderes(['iron_ia' => true, 'nutrition' => true, 'workouts' => false]));
        $this->assertSame([1, 1], $poderes(['iron_ia' => false, 'nutrition' => true, 'workouts' => true]));
    }
}
