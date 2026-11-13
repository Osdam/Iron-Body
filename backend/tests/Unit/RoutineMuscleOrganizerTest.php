<?php

namespace Tests\Unit;

use App\Services\Trainer\IntegralPlanStrategy;
use App\Services\Trainer\PlanSafetyLexicon;
use App\Services\Trainer\RoutineMuscleOrganizer;
use Tests\TestCase;

/**
 * El único sistema de organización muscular de Iron IA (docs/iron-ia/ORGANIZACION-MUSCULAR.md).
 */
class RoutineMuscleOrganizerTest extends TestCase
{
    private RoutineMuscleOrganizer $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new RoutineMuscleOrganizer(new PlanSafetyLexicon);
    }

    /** @return array<int, array<string,mixed>> */
    private function catalogo(): array
    {
        $grupos = [1 => 'Pecho', 2 => 'Pecho', 3 => 'Hombro', 4 => 'Tríceps', 5 => 'Espalda', 6 => 'Espalda', 7 => 'Bíceps',
            8 => 'Piernas', 9 => 'Glúteo', 10 => 'Femoral', 11 => 'Core', 12 => 'Brazos', 13 => 'Pantorrilla', 14 => 'General'];
        $out = [];
        foreach ($grupos as $id => $g) {
            $out[$id] = ['id' => $id, 'muscle_group' => $g, 'target' => null];
        }

        return $out;
    }

    private function dia(array $ids): array
    {
        return ['exercises' => array_map(fn ($id) => ['exercise_id' => $id], $ids)];
    }

    public function test_seis_dias_es_empuje_traccion_pierna_a_y_b(): void
    {
        $this->assertSame(
            ['Día 1 · Empuje A', 'Día 2 · Tracción A', 'Día 3 · Pierna A', 'Día 4 · Empuje B', 'Día 5 · Tracción B', 'Día 6 · Pierna B'],
            array_column($this->m->dayPlan(6, 'intermedio'), 'name'),
        );
    }

    public function test_la_estrategia_reparte_con_las_mismas_tablas(): void
    {
        $estrategia = new IntegralPlanStrategy(new PlanSafetyLexicon, $this->m);
        foreach (range(1, 6) as $dias) {
            foreach (['principiante', 'intermedio', 'avanzado'] as $nivel) {
                $this->assertSame($this->m->split($dias, $nivel), $estrategia->split($dias, $nivel), "{$dias} días, {$nivel}");
            }
        }
        // Principiante con 3 días sigue siendo cuerpo completo.
        $this->assertSame('Cuerpo completo A', $this->m->split(3, 'principiante')[0][0]);
        // Más de 6 se queda en 6; menos de 1, en 1.
        $this->assertCount(6, $this->m->split(9, 'avanzado'));
        $this->assertCount(1, $this->m->split(0, 'avanzado'));
    }

    public function test_clasifica_por_grupo_en_espanol_y_en_ingles(): void
    {
        $this->assertSame(['chest'], $this->m->tagsOf(['muscle_group' => 'Pecho']));
        $this->assertSame(['legs'], $this->m->tagsOf(['muscle_group' => 'Glúteo']));
        $this->assertSame(['back'], $this->m->tagsOf(['muscle_group' => null, 'target' => 'lats']));
        $this->assertSame([], $this->m->tagsOf(['muscle_group' => 'General']));
    }

    public function test_un_dia_coherente_pasa_y_uno_mezclado_no(): void
    {
        $plan = $this->m->dayPlan(6, 'intermedio');
        $buenos = [$this->dia([1, 2, 3, 4, 11]), $this->dia([5, 6, 7, 12, 1]), $this->dia([8, 9, 10, 11, 13]),
            $this->dia([3, 1, 4, 2, 12]), $this->dia([5, 7, 12, 6, 11]), $this->dia([10, 9, 13, 11, 8])];
        $ok = $this->m->review($buenos, $plan, $this->catalogo(), 0.6);
        $this->assertTrue($ok['ok'], implode('; ', $ok['problems']));
        $this->assertSame(0.8, $ok['days'][0]['share']);

        // Tracción A con 2 de 5 de espalda/bíceps: no pasa, y se dice cuál y por qué.
        $malos = $buenos;
        $malos[1] = $this->dia([5, 7, 8, 9, 1]);
        $mal = $this->m->review($malos, $plan, $this->catalogo(), 0.6);
        $this->assertFalse($mal['ok']);
        $this->assertSame(['el día 2 («Tracción A») trae 2 de 5 ejercicios de espalda y bíceps'], $mal['problems']);
    }

    public function test_brazos_vale_en_dias_de_biceps_o_triceps_y_pantorrilla_en_pierna(): void
    {
        $plan = [['name' => 'Día 1 · Tracción A', 'focus' => 'Tracción A', 'muscles' => 'espalda y bíceps', 'tags' => ['back', 'biceps']],
            ['name' => 'Día 2 · Pierna A', 'focus' => 'Pierna A', 'muscles' => 'cuádriceps y glúteo', 'tags' => ['legs']]];
        $r = $this->m->review([$this->dia([5, 12, 12]), $this->dia([8, 13])], $plan, $this->catalogo(), 1.0);
        $this->assertTrue($r['ok'], implode('; ', $r['problems']));
    }

    public function test_un_ejercicio_sin_grupo_cuenta_como_de_otro_grupo(): void
    {
        $plan = $this->m->dayPlan(1, 'intermedio');
        $r = $this->m->review([$this->dia([14, 14, 1])], $plan, $this->catalogo(), 0.6);
        $this->assertFalse($r['ok']);
    }

    public function test_un_numero_de_dias_distinto_del_reparto_no_pasa(): void
    {
        $r = $this->m->review([$this->dia([1, 2, 3])], $this->m->dayPlan(3, 'intermedio'), $this->catalogo(), 0.6);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('la rutina trae 1 días y el reparto tiene 3', $r['problems'][0]);
    }

    public function test_la_regla_del_prompt_nombra_cada_dia_con_sus_grupos(): void
    {
        $regla = $this->m->promptRule($this->m->dayPlan(6, 'intermedio'));
        $this->assertStringStartsWith('Reparte los días EXACTAMENTE en este orden', $regla);
        $this->assertStringContainsString('Día 1 · Empuje A (pecho, hombro y tríceps); Día 2 · Tracción A (espalda y bíceps)', $regla);
        $this->assertStringEndsWith('Día 6 · Pierna B (femoral, glúteo y core).', $regla);
    }
}
