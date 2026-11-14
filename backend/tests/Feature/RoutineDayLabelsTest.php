<?php

namespace Tests\Feature;

use App\Http\Resources\RoutineResource;
use App\Models\Routine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Pestañas de los días en la app del socio (`routine_days_screen.dart`: `Tab(text: d.day)` y
 * «Entrenar ${day.day}»). Las rutinas de Iron IA guardan sus días con `name`
 * («Día 1 · Torso A») y sin `day`; el recurso devolvía `day: ''` y las 53 rutinas
 * publicadas salían con las pestañas en blanco (comprobado en producción el 14-11).
 */
class RoutineDayLabelsTest extends TestCase
{
    use RefreshDatabase;

    private function dias(array $days): array
    {
        $r = Routine::create(['name' => 'Rutina', 'days' => $days, 'days_per_week' => count($days), 'is_template' => false]);

        return (new RoutineResource($r))->toArray(Request::create('/'))['days'];
    }

    public function test_las_rutinas_de_iron_ia_tienen_pestanas_con_su_dia(): void
    {
        $dias = $this->dias([
            ['name' => 'Día 1 · Torso A', 'order' => 0, 'exercises' => [['exercise_id' => 1, 'sets' => 3, 'reps' => '10']]],
            ['name' => 'Día 2 · Pierna A', 'order' => 1, 'exercises' => []],
        ]);

        $this->assertSame(['Día 1', 'Día 2'], array_column($dias, 'day'), 'la pestaña lleva el día');
        $this->assertSame(['Día 1 · Torso A', 'Día 2 · Pierna A'], array_column($dias, 'title'), 'el título no cambia');
    }

    public function test_sin_patron_usa_el_nombre_y_sin_nombre_el_numero(): void
    {
        $dias = $this->dias([
            ['name' => 'Full body', 'exercises' => []],
            ['exercises' => []],
        ]);

        $this->assertSame(['Full body', 'Día 2'], array_column($dias, 'day'));
    }

    public function test_las_rutinas_con_dia_de_la_semana_no_cambian(): void
    {
        $dias = $this->dias([
            ['day' => 'Lunes', 'title' => 'Pecho', 'exercises' => []],
            ['weekday' => 'Miércoles', 'name' => 'Espalda', 'exercises' => []],
        ]);

        $this->assertSame(['Lunes', 'Miércoles'], array_column($dias, 'day'));
        $this->assertSame(['Pecho', 'Espalda'], array_column($dias, 'title'));
    }
}
