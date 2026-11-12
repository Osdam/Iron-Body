<?php

namespace Tests\Unit;

use App\Services\Trainer\SessionTimeBudget;
use PHPUnit\Framework\TestCase;

/**
 * La estimación de la duración de una sesión (supuestos en SessionTimeBudget).
 *
 * Lo que se vigila: que las cuentas sean las declaradas, que el descanso sea el
 * que la app aplica de verdad (90 s), y que el ajuste se PARE en cuanto cabe.
 * Cada caso fija CUÁNTO queda: comprobar solo que «cabe» no distingue un
 * ajuste justo de uno que deja tres ejercicios de dos series (revisión 11-11).
 */
class SessionTimeBudgetTest extends TestCase
{
    public function test_el_trabajo_de_una_serie_sale_de_como_se_escriben_las_repeticiones(): void
    {
        $this->assertSame(30, SessionTimeBudget::workSeconds('10'));
        $this->assertSame(27, SessionTimeBudget::workSeconds('8-10'));      // media 9 × 3 s
        $this->assertSame(45, SessionTimeBudget::workSeconds('al fallo'));  // sin número
        $this->assertSame(30, SessionTimeBudget::workSeconds('30 s'));
        $this->assertSame(60, SessionTimeBudget::workSeconds('10 por lado'));   // 10 × 3 s × 2 lados
        $this->assertSame(60, SessionTimeBudget::workSeconds('1 min'));
        $this->assertSame(75, SessionTimeBudget::workSeconds('2 min'));     // tope 75 s
        $this->assertSame(20, SessionTimeBudget::workSeconds('3'));         // mínimo 20 s
    }

    public function test_el_descanso_es_el_que_aplica_la_app_nunca_menos_de_90_s(): void
    {
        $this->assertSame(90, SessionTimeBudget::effectiveRestSeconds(60));   // la app arranca 90 s igual
        $this->assertSame(90, SessionTimeBudget::effectiveRestSeconds(null));
        $this->assertSame(120, SessionTimeBudget::effectiveRestSeconds(120)); // la nota pide más: se cuenta
    }

    public function test_la_estimacion_suma_calentamiento_series_descansos_y_cambios(): void
    {
        // 45 min ⇒ 8 de calentamiento. 3×10 con 90 s: 3×30 + 2×90 + 60 = 330 s = 5,5 min.
        $this->assertSame(13.5, SessionTimeBudget::estimateMinutes([['sets' => 3, 'reps' => '10', 'rest_seconds' => 90]], 45));
        // 60 s escritos cuentan como los 90 s de la app.
        $this->assertSame(13.5, SessionTimeBudget::estimateMinutes([['sets' => 3, 'reps' => '10', 'rest_seconds' => 60]], 45));
        // 120 s: 3×30 + 2×120 + 60 = 390 s = 6,5 min.
        $this->assertSame(14.5, SessionTimeBudget::estimateMinutes([['sets' => 3, 'reps' => '10', 'rest_seconds' => 120]], 45));
        // 30 min ⇒ 5 de calentamiento.
        $this->assertSame(10.5, SessionTimeBudget::estimateMinutes([['sets' => 3, 'reps' => '10']], 30));
    }

    public function test_el_tope_de_ejercicios_sale_del_tiempo(): void
    {
        // Por ejercicio típico: 3×30 + 2×90 + 60 = 330 s.
        $this->assertSame(6, SessionTimeBudget::exerciseCap(45));   // (45 − 8) × 60 = 2220 s
        $this->assertSame(4, SessionTimeBudget::exerciseCap(30));   // (30 − 5) × 60 = 1500 s
        $this->assertSame(9, SessionTimeBudget::exerciseCap(60));   // (60 − 8) × 60 = 3120 s
    }

    public function test_una_sesion_que_cabe_no_se_toca(): void
    {
        $ejercicios = array_fill(0, 5, ['sets' => 3, 'reps' => '10', 'rest_seconds' => 90]);
        $f = SessionTimeBudget::fit($ejercicios, 45, 3);

        $this->assertTrue($f['fits']);
        $this->assertSame($ejercicios, $f['exercises']);
        $this->assertSame(['rest' => 0, 'sets' => 0, 'removed' => 0], $f['changes']);
    }

    public function test_si_basta_con_bajar_dos_descansos_no_se_tocan_las_series(): void
    {
        // 6 × (3×10, 150 s) = 53,0 min para 45 (tope 49,5). Cada descanso a 90 s ahorra 120 s.
        $ejercicios = array_fill(0, 6, ['sets' => 3, 'reps' => '10', 'rest_seconds' => 150, 'notes' => 'Descanso 150 s']);
        $f = SessionTimeBudget::fit($ejercicios, 45, 3);

        $this->assertSame(53.0, $f['before']);
        $this->assertSame(49.0, $f['after']);
        $this->assertSame(['rest' => 2, 'sets' => 0, 'removed' => 0], $f['changes']);
        $this->assertSame([150, 150, 150, 150, 90, 90], array_column($f['exercises'], 'rest_seconds'), 'desde el último');
        $this->assertSame('Descanso 90 s', $f['exercises'][5]['notes']);
        $this->assertSame('Descanso 150 s', $f['exercises'][0]['notes']);
        $this->assertSame([3, 3, 3, 3, 3, 3], array_column($f['exercises'], 'sets'));
    }

    public function test_el_caso_realista_de_la_revision_quita_lo_justo(): void
    {
        // 6 × (4×«8-10», 90 s) = 51,8 min para 45. Bajar una serie ahorra 27 + 90 = 117 s:
        // con dos basta (47,9). Antes del arreglo quedaban 3 ejercicios de 2 series (17,5 min).
        $ejercicios = array_fill(0, 6, ['sets' => 4, 'reps' => '8-10', 'rest_seconds' => 90]);
        $f = SessionTimeBudget::fit($ejercicios, 45, 3);

        $this->assertSame(51.8, $f['before']);
        $this->assertSame(47.9, $f['after']);
        $this->assertSame(['rest' => 0, 'sets' => 2, 'removed' => 0], $f['changes']);
        $this->assertSame([4, 4, 4, 4, 3, 3], array_column($f['exercises'], 'sets'));
    }

    public function test_un_exceso_grande_baja_descansos_y_series_pero_no_quita_ejercicios_si_no_hace_falta(): void
    {
        // 8 × (5×10, 150 s) = 116 min para 45: descansos a 90 (84 min), series a 3 (52 min)
        // y dos series a 2 (48 min). Los 8 ejercicios se quedan.
        $ejercicios = array_fill(0, 8, ['sets' => 5, 'reps' => '10', 'rest_seconds' => 150]);
        $f = SessionTimeBudget::fit($ejercicios, 45, 3);

        $this->assertSame(116.0, $f['before']);
        $this->assertSame(48.0, $f['after']);
        $this->assertTrue($f['fits']);
        $this->assertSame(['rest' => 8, 'sets' => 10, 'removed' => 0], $f['changes']);
        $this->assertCount(8, $f['exercises']);
        $this->assertSame([3, 3, 3, 3, 3, 3, 2, 2], array_column($f['exercises'], 'sets'));
    }

    public function test_si_ni_ajustada_cabe_lo_dice(): void
    {
        // 20 min con 6 ejercicios largos y un mínimo de 6: no hay de dónde quitar.
        $ejercicios = array_fill(0, 6, ['sets' => 4, 'reps' => '1 min', 'rest_seconds' => 120]);
        $f = SessionTimeBudget::fit($ejercicios, 20, 6);

        $this->assertFalse($f['fits']);
        $this->assertCount(6, $f['exercises']);
        $this->assertSame(32.0, $f['after']);
        $this->assertSame(['rest' => 6, 'sets' => 12, 'removed' => 0], $f['changes']);
    }
}
