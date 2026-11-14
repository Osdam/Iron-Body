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

    // ── «Organizar automáticamente» (punto F): arrange() ─────────────────────

    /** @return list<array{tags: list<string>, day: ?string, pinned: bool}> */
    private function items(array $grupos, ?array $dias = null, array $fijados = []): array
    {
        return array_map(fn ($g, $i) => [
            'tags' => $g === null ? [] : $this->m->tagsOf(['muscle_group' => $g]),
            'day' => $dias[$i] ?? null,
            'pinned' => in_array($i, $fijados, true),
        ], $grupos, array_keys($grupos));
    }

    /** @return list<list<string>> grupos de cada día */
    private function porDia(array $grupos, array $r): array
    {
        return array_map(fn ($idx) => array_map(fn ($i) => $grupos[$i], $idx), $r['days']);
    }

    public function test_de_seis_a_cuatro_dias_reparte_torso_y_pierna_sin_perder_ni_duplicar(): void
    {
        $grupos = ['Pecho', 'Hombros', 'Tríceps', 'Espalda', 'Bíceps', 'Piernas', 'Glúteos', 'Pecho', 'Hombros', 'Espalda', 'Brazos', 'Femorales', 'Pantorrillas', 'Core'];
        $antes = array_merge(...array_map(fn ($d) => array_fill(0, 2, $d['name']), $this->m->dayPlan(6, 'intermedio')));
        $r = $this->m->arrange($this->items($grupos, $antes), 4, 'intermedio');

        $this->assertSame(array_column($this->m->dayPlan(4, 'intermedio'), 'name'), array_column($r['plan'], 'name'));
        $todos = array_merge(...$r['days']);
        sort($todos);
        $this->assertSame(array_keys($grupos), $todos, 'los mismos ejercicios, ni uno más ni uno menos');
        $dias = $this->porDia($grupos, $r);
        foreach ([0, 2] as $torso) {
            $this->assertEmpty(array_intersect($dias[$torso], ['Piernas', 'Glúteos', 'Femorales', 'Pantorrillas']), 'un día de torso no lleva pierna');
        }
        foreach ([1, 3] as $pierna) {
            $this->assertEmpty(array_intersect($dias[$pierna], ['Pecho', 'Espalda', 'Hombros', 'Bíceps', 'Tríceps']), 'un día de pierna no lleva torso');
        }
        foreach ($r['days'] as $idx) {
            $this->assertNotEmpty($idx);
            $this->assertSame($idx, array_values(array_unique($idx)));
            $ordenado = $idx;
            sort($ordenado);
            $this->assertSame($ordenado, $idx, 'dentro de cada día se conserva el orden que traían');
        }
        // Equilibrio entre días del MISMO tipo (9 de torso y 5 de pierna no caben en días iguales sin mezclar grupos).
        $this->assertLessThanOrEqual(1, abs(count($r['days'][0]) - count($r['days'][2])), 'Torso A y Torso B equilibrados');
        $this->assertLessThanOrEqual(1, abs(count($r['days'][1]) - count($r['days'][3])), 'Pierna A y Pierna B equilibradas');
    }

    public function test_lo_que_ya_esta_bien_no_se_mueve_y_es_determinista(): void
    {
        $plan = array_column($this->m->dayPlan(4, 'intermedio'), 'name');
        $grupos = ['Pecho', 'Espalda', 'Piernas', 'Core', 'Espalda', 'Bíceps', 'Femorales', 'Pantorrillas'];
        $dias = [$plan[0], $plan[0], $plan[1], $plan[1], $plan[2], $plan[2], $plan[3], $plan[3]];

        $r = $this->m->arrange($this->items($grupos, $dias), 4, 'intermedio');
        $this->assertSame([[0, 1], [2, 3], [4, 5], [6, 7]], $r['days']);
        $this->assertSame($r, $this->m->arrange($this->items($grupos, $dias), 4, 'intermedio'));
    }

    public function test_un_fijado_se_queda_y_un_dia_vacio_no_se_rellena_con_otro_grupo(): void
    {
        $plan = array_column($this->m->dayPlan(4, 'intermedio'), 'name');
        // Un ejercicio de pecho fijado a mano en un día de pierna: se respeta.
        $r = $this->m->arrange($this->items(['Pecho', 'Pecho', 'Piernas', 'Femorales'], [$plan[1], null, null, null], [0]), 4, 'intermedio');
        $this->assertContains(0, $r['days'][1]);

        // Solo pierna en 4 días: los de torso quedan vacíos (quien llama avisa), no se llenan con pierna.
        $r = $this->m->arrange($this->items(['Piernas', 'Glúteos', 'Femorales', 'Pantorrillas']), 4, 'intermedio');
        $this->assertSame([], $r['days'][0]);
        $this->assertSame([], $r['days'][2]);
        $this->assertCount(4, array_merge(...$r['days']));
    }

    public function test_sin_grupo_va_al_dia_menos_cargado_y_mas_dias_que_ejercicios_se_rechaza(): void
    {
        $r = $this->m->arrange($this->items(['Pecho', 'Pecho', 'Pecho', null]), 2, 'intermedio');
        $this->assertSame(4, count(array_merge(...$r['days'])));
        $this->assertNotEmpty($r['days'][1], 'el que no tiene grupo no se amontona en el día ya cargado');

        $this->expectException(\InvalidArgumentException::class);
        $this->m->arrange($this->items(['Pecho', 'Espalda']), 3, 'intermedio');
    }

    public function test_un_principiante_con_tres_dias_recibe_cuerpo_completo(): void
    {
        $r = $this->m->arrange($this->items(['Pecho', 'Espalda', 'Piernas', 'Hombros', 'Core', 'Glúteos']), 3, 'principiante');
        $this->assertSame(['Día 1 · Cuerpo completo A', 'Día 2 · Cuerpo completo B', 'Día 3 · Cuerpo completo C'], array_column($r['plan'], 'name'));
    }

    public function test_organizar_dos_veces_da_lo_mismo_que_una_tambien_con_cardio(): void
    {
        // Revisión del 14-11 (M-1): el cardio no tiene día en ningún reparto y rotaba en cada clic.
        $grupos = ['Cardio', 'Pecho', 'Cardio', 'Piernas', 'Cardio', 'Espalda', 'Cardio', 'Femorales', 'Cuello'];
        $uno = $this->m->arrange($this->items($grupos), 4, 'intermedio');
        $dias = [];
        foreach ($uno['days'] as $k => $idx) {
            foreach ($idx as $i) {
                $dias[$i] = $uno['plan'][$k]['name'];
            }
        }
        ksort($dias);
        $dos = $this->m->arrange($this->items($grupos, array_values($dias)), 4, 'intermedio');
        $this->assertSame($uno['days'], $dos['days'], 'f(f(x)) == f(x)');
    }

    public function test_un_compuesto_cuenta_tambien_su_musculo_secundario(): void
    {
        // Revisión del 14-11 (B-1): peso muerto = Espalda + glutes. En un día de pierna está bien.
        $plan = array_column($this->m->dayPlan(6, 'intermedio'), 'name');
        $item = fn (string $g, ?string $t, string $dia) => ['tags' => $this->m->tagsOf(['muscle_group' => $g, 'target' => $t]), 'day' => $dia, 'pinned' => false];
        $items = [$item('Pecho', 'pectorals', $plan[0]), $item('Espalda', 'lats', $plan[1]), $item('Piernas', 'quads', $plan[2]),
            $item('Hombros', 'delts', $plan[3]), $item('Espalda', 'upper back', $plan[4]), $item('Espalda', 'glutes', $plan[5]), $item('Femoral', 'hamstrings', $plan[5])];

        $r = $this->m->arrange($items, 6, 'intermedio');
        $this->assertContains(5, $r['days'][5], 'el peso muerto se queda en Pierna B');
        $this->assertSame([[0], [1], [2], [3], [4], [5, 6]], $r['days']);
    }
}
