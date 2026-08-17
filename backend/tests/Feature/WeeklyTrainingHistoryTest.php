<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Historial de volumen semanal navegable.
 *
 * Hasta ahora "esta semana" era lo único consultable: cada lunes la gráfica
 * empezaba de cero y lo anterior dejaba de verse, aunque siguiera en la base de
 * datos. Aquí se comprueba que el histórico se puede recorrer y que sale de las
 * SERIES realmente ejecutadas, no de un contador aparte.
 *
 * Calendario: lunes→domingo en America/Bogota, siempre.
 */
class WeeklyTrainingHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** Jueves 20/08/2026 15:00 en Bogotá = 20:00 UTC. */
    private const NOW_UTC = '2026-08-20 20:00:00';

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));

        $this->member = Member::create([
            'full_name' => 'Socio Historial',
            'document_number' => '770770770',
            'phone' => '+573007707707',
            'access_hash' => 'tok-770',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function auth(?Member $m = null): array
    {
        return ['Authorization' => 'Bearer '.($m ?? $this->member)->access_hash];
    }

    /**
     * Crea una sesión con SERIES reales, dando la hora local de Bogotá.
     *
     * `$sets` es una lista de [reps, peso, completada]. El volumen esperado sale
     * de multiplicarlos, no de un total escrito a mano.
     */
    private function sessionAtLocal(string $localDateTime, array $sets, ?Member $m = null): WorkoutSession
    {
        $member = $m ?? $this->member;
        $at = Carbon::parse($localDateTime, 'America/Bogota')->utc();

        $session = WorkoutSession::create([
            'member_id' => $member->id,
            'client_session_id' => 'ws-'.uniqid('', true),
            'completed_at' => $at,
            'started_at' => $at->copy()->subMinutes(30),
            'duration_seconds' => 1800,
            // Deliberadamente en 0: el endpoint debe derivar de las series y no
            // leer este contador. Si lo leyera, todos estos tests darían 0.
            'total_volume_kg' => 0,
            'total_sets' => 0,
            'total_exercises' => 1,
        ]);

        foreach (array_values($sets) as $i => [$reps, $weight, $completed]) {
            WorkoutSessionSet::create([
                'workout_session_id' => $session->id,
                'exercise_name' => 'Sentadilla',
                'exercise_key' => 'sentadilla',
                'exercise_order' => 0,
                'set_number' => $i + 1,
                'reps' => $reps,
                'weight_kg' => $weight,
                'completed' => $completed,
                'performed_at' => $at,
            ]);
        }

        return $session;
    }

    /** Una sesión de peso corporal: series hechas, sin carga. */
    private function bodyweightAtLocal(string $localDateTime, int $sets = 3): WorkoutSession
    {
        return $this->sessionAtLocal(
            $localDateTime,
            array_fill(0, $sets, [15, 0, true]),
        );
    }

    private function weekly(?string $weekStart = null, ?Member $m = null): array
    {
        $url = '/api/app/progress/weekly'.($weekStart !== null ? '?week_start='.$weekStart : '');

        return $this->getJson($url, $this->auth($m))->assertOk()->json('data');
    }

    private function day(array $w, string $weekday): array
    {
        return collect($w['days'])->firstWhere('weekday', $weekday);
    }

    // ─────────────────────────────────────────────────────────── 1. semana vacía

    public function test_una_semana_vacia_devuelve_siete_dias_en_cero(): void
    {
        $w = $this->weekly();

        $this->assertFalse($w['has_sessions']);
        $this->assertSame(0, $w['total_sessions']);
        $this->assertSame(0, $w['total_sets']);
        $this->assertEqualsWithDelta(0, $w['total_volume_kg'], 0.01);
        $this->assertCount(7, $w['days']);
        $this->assertSame(
            ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'],
            array_column($w['days'], 'weekday'),
        );
        foreach ($w['days'] as $d) {
            $this->assertSame(0, $d['sessions']);
            $this->assertSame(0, $d['sets']);
            $this->assertEqualsWithDelta(0, $d['volume_kg'], 0.01);
        }
    }

    // ──────────────────────────────────────────────────────── 2. una sesión lunes

    public function test_una_sesion_el_lunes_pinta_solo_esa_barra(): void
    {
        $this->sessionAtLocal('2026-08-17 10:00:00', [[10, 60, true], [10, 60, true]]);

        $w = $this->weekly();

        $this->assertTrue($w['has_sessions']);
        $this->assertSame(1, $w['total_sessions']);
        $this->assertEqualsWithDelta(1200, $w['total_volume_kg'], 0.01);

        $lunes = $this->day($w, 'lunes');
        $this->assertSame(1, $lunes['sessions']);
        $this->assertSame(2, $lunes['sets']);
        $this->assertEqualsWithDelta(1200, $lunes['volume_kg'], 0.01);

        foreach (['martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'] as $otro) {
            $this->assertSame(0, $this->day($w, $otro)['sessions'], "$otro debe quedar en cero");
        }
    }

    // ────────────────────────────────────── 3. varias sesiones el mismo lunes

    public function test_dos_sesiones_el_mismo_lunes_se_suman_en_una_barra(): void
    {
        // 1200 kg por la mañana …
        $this->sessionAtLocal('2026-08-17 08:00:00', [[10, 60, true], [10, 60, true]]);
        // … y 800 kg por la tarde.
        $this->sessionAtLocal('2026-08-17 19:00:00', [[10, 40, true], [10, 40, true]]);

        $w = $this->weekly();

        $this->assertSame(2, $w['total_sessions'], 'son dos entrenamientos');
        $lunes = $this->day($w, 'lunes');
        $this->assertSame(1, collect($w['days'])->where('sessions', '>', 0)->count(), 'pero una sola barra');
        $this->assertSame(2, $lunes['sessions']);
        $this->assertSame(4, $lunes['sets']);
        $this->assertEqualsWithDelta(2000, $lunes['volume_kg'], 0.01);
    }

    // ─────────────────────────────────────────── 4. sesiones lunes y miércoles

    public function test_sesiones_en_lunes_y_miercoles_dan_dos_barras(): void
    {
        $this->sessionAtLocal('2026-08-17 10:00:00', [[10, 50, true]]);   // 500
        $this->sessionAtLocal('2026-08-19 10:00:00', [[10, 100, true]]);  // 1000

        $w = $this->weekly();

        $conSesion = collect($w['days'])->where('sessions', '>', 0)->values();
        $this->assertCount(2, $conSesion);
        $this->assertSame('lunes', $conSesion[0]['weekday']);
        $this->assertSame('miércoles', $conSesion[1]['weekday']);
        $this->assertEqualsWithDelta(1500, $w['total_volume_kg'], 0.01);
    }

    // ────────────────────────────────────────────────────── 5. sesión con 0 kg

    public function test_una_sesion_de_peso_corporal_no_deja_la_semana_vacia(): void
    {
        $this->bodyweightAtLocal('2026-08-17 10:00:00', sets: 4);

        $w = $this->weekly();

        $this->assertTrue($w['has_sessions'], '0 kg no significa que no entrenó');
        $this->assertSame(1, $w['total_sessions']);
        $this->assertEqualsWithDelta(0, $w['total_volume_kg'], 0.01);

        $lunes = $this->day($w, 'lunes');
        $this->assertSame(1, $lunes['sessions']);
        $this->assertSame(4, $lunes['sets'], 'las series constan aunque el volumen sea 0');
        $this->assertEqualsWithDelta(0, $lunes['volume_kg'], 0.01);
    }

    // ──────────────────────────────────────────── 6. suma correcta peso × reps

    public function test_el_volumen_es_la_suma_de_peso_por_repeticiones(): void
    {
        $this->sessionAtLocal('2026-08-17 10:00:00', [
            [10, 60, true],   //  600
            [8, 70.5, true],  //  564
            [6, 80, true],    //  480
        ]);

        $w = $this->weekly();

        $this->assertEqualsWithDelta(1644, $w['total_volume_kg'], 0.01);
        $this->assertSame(3, $this->day($w, 'lunes')['sets']);
    }

    public function test_las_series_sin_marcar_o_sin_carga_no_suman_volumen(): void
    {
        $this->sessionAtLocal('2026-08-17 10:00:00', [
            [10, 60, true],    // 600 → cuenta
            [10, 60, false],   // no marcada → no cuenta
            [10, 0, true],     // sin carga → serie sí, volumen no
            [0, 60, true],     // sin reps → serie sí, volumen no
        ]);

        $w = $this->weekly();

        $this->assertEqualsWithDelta(600, $w['total_volume_kg'], 0.01);
        $this->assertSame(3, $this->day($w, 'lunes')['sets'], 'series completadas, con carga o sin ella');
    }

    // ──────────────────────────────────────────── 7 y 8. semana anterior/actual

    public function test_la_semana_actual_se_identifica_como_tal(): void
    {
        $w = $this->weekly();

        $this->assertTrue($w['is_current_week']);
        $this->assertSame('2026-08-17', $w['week_start']);
        $this->assertSame('2026-08-23', $w['week_end']);
        $this->assertSame('America/Bogota', $w['timezone']);
        $this->assertFalse($w['can_go_next'], 'no se navega al futuro');
        $this->assertTrue($this->day($w, 'jueves')['is_today']);
    }

    public function test_la_semana_anterior_es_consultable_y_conserva_sus_datos(): void
    {
        // Martes de la semana pasada.
        $this->sessionAtLocal('2026-08-11 10:00:00', [[10, 90, true]]); // 900
        // Y algo esta semana, para probar que no se mezclan.
        $this->sessionAtLocal('2026-08-17 10:00:00', [[10, 50, true]]); // 500

        $anterior = $this->weekly('2026-08-10');

        $this->assertFalse($anterior['is_current_week']);
        $this->assertSame('2026-08-10', $anterior['week_start']);
        $this->assertSame('2026-08-16', $anterior['week_end']);
        $this->assertTrue($anterior['has_sessions'], 'lo de la semana pasada sigue ahí');
        $this->assertEqualsWithDelta(900, $anterior['total_volume_kg'], 0.01);
        $this->assertSame(1, $this->day($anterior, 'martes')['sessions']);
        $this->assertTrue($anterior['can_go_next'], 'desde una semana pasada sí se avanza');

        foreach ($anterior['days'] as $d) {
            $this->assertFalse($d['is_today'], 'en una semana histórica no hay "hoy"');
        }

        // Y la actual sigue siendo la suya.
        $actual = $this->weekly();
        $this->assertEqualsWithDelta(500, $actual['total_volume_kg'], 0.01);
    }

    /** Cualquier día de la semana pedida resuelve a su lunes. */
    public function test_cualquier_dia_resuelve_al_lunes_de_su_semana(): void
    {
        $this->sessionAtLocal('2026-08-11 10:00:00', [[10, 90, true]]);

        foreach (['2026-08-10', '2026-08-13', '2026-08-16'] as $fecha) {
            $w = $this->weekly($fecha);
            $this->assertSame('2026-08-10', $w['week_start'], "$fecha debe caer en su semana");
            $this->assertEqualsWithDelta(900, $w['total_volume_kg'], 0.01);
        }
    }

    // ──────────────────────────────────────────── 9. navegación 2 semanas atrás

    public function test_se_puede_navegar_dos_semanas_atras(): void
    {
        $this->sessionAtLocal('2026-08-04 10:00:00', [[10, 30, true]]);  // 300, hace 2 semanas
        $this->sessionAtLocal('2026-08-11 10:00:00', [[10, 90, true]]);  // 900, hace 1 semana
        $this->sessionAtLocal('2026-08-17 10:00:00', [[10, 50, true]]);  // 500, esta semana

        $dosAtras = $this->weekly('2026-08-03');

        $this->assertSame('2026-08-03', $dosAtras['week_start']);
        $this->assertEqualsWithDelta(300, $dosAtras['total_volume_kg'], 0.01);
        $this->assertFalse($dosAtras['is_current_week']);
        $this->assertTrue($dosAtras['can_go_previous'], 'sigue siendo pasado reciente');

        // La comparación de esa semana mira a la anterior a ella, que está vacía.
        $this->assertFalse($dosAtras['previous_week']['has_sessions']);
        $this->assertNull($dosAtras['volume_change_pct']);
    }

    /**
     * El pasado reciente se puede mirar aunque esté vacío: "¿entrené la semana
     * pasada?" es una pregunta legítima y "no" es una respuesta útil. Más atrás
     * la flecha se apaga si nunca hubo nada, para no caminar indefinidamente
     * hacia un pasado vacío.
     */
    public function test_el_pasado_reciente_se_puede_mirar_aunque_este_vacio(): void
    {
        $this->assertTrue(
            $this->weekly()['can_go_previous'],
            'un socio sin historial puede asomarse a la semana pasada',
        );

        // 12 semanas atrás es el límite: más allá hace falta historial real.
        $lejos = $this->weekly('2026-05-25');
        $this->assertFalse($lejos['can_go_previous'], 'ahí atrás nunca hubo nada');
    }

    public function test_con_historial_antiguo_se_puede_seguir_retrocediendo(): void
    {
        // Un entrenamiento muy anterior al límite de pasado reciente.
        $this->sessionAtLocal('2026-01-15 10:00:00', [[10, 90, true]]);

        $this->assertTrue(
            $this->weekly('2026-05-25')['can_go_previous'],
            'hay historial detrás: la flecha sigue viva',
        );
    }

    // ──────────────────────────────────────────── 10 y 11. fronteras de Bogotá

    public function test_el_domingo_a_las_23_59_pertenece_a_esa_semana(): void
    {
        // Domingo 23/08 23:59 Bogotá = lunes 24/08 04:59 UTC.
        $this->sessionAtLocal('2026-08-23 23:59:00', [[10, 70, true]]); // 700

        $w = $this->weekly();

        $this->assertSame('2026-08-23', $w['week_end']);
        $this->assertTrue($w['has_sessions']);
        $domingo = $this->day($w, 'domingo');
        $this->assertSame(1, $domingo['sessions'], 'en UTC ya es lunes, pero el socio entrenó el domingo');
        $this->assertEqualsWithDelta(700, $domingo['volume_kg'], 0.01);

        // Y no se derrama a ningún otro día ni a la semana anterior.
        $this->assertSame(
            1,
            collect($w['days'])->where('sessions', '>', 0)->count(),
            'una sola barra, la del domingo',
        );
        $this->assertFalse($this->weekly('2026-08-10')['has_sessions']);
    }

    /**
     * Y visto desde la semana siguiente, aquel domingo se queda donde estaba.
     * Es el corte que el desfase de zona rompía.
     */
    public function test_desde_la_semana_siguiente_el_domingo_anterior_ya_no_cuenta(): void
    {
        $this->sessionAtLocal('2026-08-23 23:59:00', [[10, 70, true]]);

        // Nos situamos el lunes 24/08 a las 00:30 de Bogotá (05:30 UTC).
        Carbon::setTestNow(Carbon::parse('2026-08-24 05:30:00', 'UTC'));

        $w = $this->weekly();

        $this->assertSame('2026-08-24', $w['week_start']);
        $this->assertFalse($w['has_sessions'], 'la semana nueva empieza vacía');
        $this->assertTrue($w['has_previous_sessions'], 'pero el socio ya entrenó antes');
        $this->assertTrue($w['can_go_previous'], 'y puede ir a verlo');

        // Lo de aquel domingo sigue consultable donde ocurrió.
        $anterior = $this->weekly('2026-08-17');
        $this->assertEqualsWithDelta(700, $anterior['total_volume_kg'], 0.01);
        $this->assertSame(1, $this->day($anterior, 'domingo')['sessions']);
    }

    public function test_el_lunes_a_las_00_01_abre_la_semana_nueva(): void
    {
        // Lunes 17/08 00:01 Bogotá = 05:01 UTC.
        $this->sessionAtLocal('2026-08-17 00:01:00', [[10, 80, true]]); // 800

        $w = $this->weekly();

        $this->assertSame('2026-08-17', $w['week_start']);
        $lunes = $this->day($w, 'lunes');
        $this->assertSame(1, $lunes['sessions'], 'la madrugada del lunes ya es semana nueva');
        $this->assertEqualsWithDelta(800, $lunes['volume_kg'], 0.01);

        // La semana anterior no se lo lleva.
        $this->assertFalse($this->weekly('2026-08-10')['has_sessions']);
    }

    // ──────────────────────────────────────────── 12 y 13. comparación semanal

    public function test_compara_con_la_semana_anterior(): void
    {
        // Semana pasada: 7910 kg. Esta semana: 8420 kg. → +6,4 %
        $this->sessionAtLocal('2026-08-11 10:00:00', [[100, 79.1, true]]);
        $this->sessionAtLocal('2026-08-18 10:00:00', [[100, 84.2, true]]);

        $w = $this->weekly();

        $this->assertEqualsWithDelta(8420, $w['total_volume_kg'], 0.01);
        $this->assertSame('2026-08-10', $w['previous_week']['week_start']);
        $this->assertSame('2026-08-16', $w['previous_week']['week_end']);
        $this->assertTrue($w['previous_week']['has_sessions']);
        $this->assertEqualsWithDelta(7910, $w['previous_week']['total_volume_kg'], 0.01);
        $this->assertSame(1, $w['previous_week']['total_sessions']);
        $this->assertEqualsWithDelta(6.4, $w['volume_change_pct'], 0.05);
    }

    public function test_una_bajada_se_reporta_en_negativo(): void
    {
        $this->sessionAtLocal('2026-08-11 10:00:00', [[10, 100, true]]); // 1000
        $this->sessionAtLocal('2026-08-18 10:00:00', [[10, 75, true]]);  //  750

        $this->assertEqualsWithDelta(-25.0, $this->weekly()['volume_change_pct'], 0.05);
    }

    public function test_sin_volumen_la_semana_anterior_no_se_divide_por_cero(): void
    {
        $this->sessionAtLocal('2026-08-18 10:00:00', [[10, 84.2, true]]);

        $w = $this->weekly();

        $this->assertEqualsWithDelta(842, $w['total_volume_kg'], 0.01);
        $this->assertEqualsWithDelta(0, $w['previous_week']['total_volume_kg'], 0.01);
        $this->assertNull($w['volume_change_pct'], 'sin base no hay porcentaje que enseñar');
    }

    /**
     * La semana pasada SÍ entrenó, pero solo peso corporal: no hay porcentaje
     * comparable y aun así hubo entrenamientos. Son dos cosas distintas y la
     * respuesta las separa.
     */
    public function test_distingue_entrenar_de_levantar_kilos_al_comparar(): void
    {
        $this->bodyweightAtLocal('2026-08-11 10:00:00');
        $this->sessionAtLocal('2026-08-18 10:00:00', [[10, 50, true]]);

        $w = $this->weekly();

        $this->assertTrue($w['previous_week']['has_sessions'], 'entrenó');
        $this->assertEqualsWithDelta(0, $w['previous_week']['total_volume_kg'], 0.01, 'pero sin carga externa');
        $this->assertNull($w['volume_change_pct']);
    }

    // ────────────────────────────────────────────────────────── 14. autorización

    public function test_un_socio_no_puede_ver_las_estadisticas_de_otro(): void
    {
        $otro = Member::create([
            'full_name' => 'Socio Ajeno',
            'document_number' => '771771771',
            'phone' => '+573007717717',
            'access_hash' => 'tok-771',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $this->sessionAtLocal('2026-08-17 10:00:00', [[10, 100, true]], m: $otro); // 1000 del ajeno

        // Con mi token veo lo mío, que está vacío.
        $mio = $this->weekly();
        $this->assertFalse($mio['has_sessions']);
        $this->assertEqualsWithDelta(0, $mio['total_volume_kg'], 0.01);

        // Y no hay forma de pedir lo suyo: el parámetro ni siquiera se lee.
        $intento = $this->getJson(
            '/api/app/progress/weekly?member_id='.$otro->id.'&member='.$otro->id,
            $this->auth(),
        )->assertOk()->json('data');

        $this->assertFalse($intento['has_sessions']);
        $this->assertEqualsWithDelta(0, $intento['total_volume_kg'], 0.01);

        // Él sí ve lo suyo.
        $suyo = $this->weekly(null, $otro);
        $this->assertEqualsWithDelta(1000, $suyo['total_volume_kg'], 0.01);
    }

    public function test_sin_token_no_se_responde(): void
    {
        $this->getJson('/api/app/progress/weekly')->assertUnauthorized();
    }

    public function test_una_fecha_con_formato_invalido_se_rechaza(): void
    {
        $this->getJson('/api/app/progress/weekly?week_start=hola', $this->auth())
            ->assertStatus(422);
    }

    /** Una semana futura no es un error: se devuelve la actual. */
    public function test_una_semana_futura_cae_en_la_semana_actual(): void
    {
        $w = $this->weekly('2027-01-04');

        $this->assertSame('2026-08-17', $w['week_start']);
        $this->assertTrue($w['is_current_week']);
    }

    // ────────────────────────────────────────────────────────── 15. sin N+1

    /**
     * La agregación no puede escalar con el número de sesiones. Con 12 sesiones
     * repartidas en dos semanas el endpoint debe hacer el mismo número de
     * consultas que con una: cargar las sesiones y luego pedir las series de
     * cada una sería el N+1 clásico.
     */
    public function test_la_agregacion_no_crece_con_el_numero_de_sesiones(): void
    {
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->weekly();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->sessionAtLocal('2026-08-17 10:00:00', [[10, 60, true]]);
        $base = $contar();

        for ($d = 17; $d <= 22; $d++) {
            $this->sessionAtLocal("2026-08-$d 09:00:00", [[10, 60, true], [10, 60, true]]);
            $this->sessionAtLocal("2026-08-$d 18:00:00", [[10, 60, true], [10, 60, true]]);
        }

        $this->assertSame(13, $this->weekly()['total_sessions'], 'el escenario tiene 13 sesiones');
        $this->assertSame($base, $contar(), 'el número de consultas no depende de cuántas sesiones haya');
    }

    // ──────────────────────────────────────── el resumen sigue siendo coherente

    public function test_el_resumen_sigue_trayendo_la_semana_en_curso(): void
    {
        $this->sessionAtLocal('2026-08-17 10:00:00', [[10, 60, true], [10, 60, true]]);

        $data = $this->getJson('/api/app/progress/summary', $this->auth())
            ->assertOk()->json('data');

        $w = $data['weekly_training'];
        $this->assertTrue($w['is_current_week']);
        $this->assertTrue($w['has_sessions']);
        $this->assertEqualsWithDelta(1200, $w['total_volume_kg'], 0.01);

        // Y el gráfico antiguo dice exactamente lo mismo.
        $lunes = collect($data['weekly_volume'])->first();
        $this->assertSame(1200, $lunes['value']);
        $this->assertSame(1, $lunes['sessions']);
        $this->assertCount(7, $data['weekly_volume']);
    }
}
