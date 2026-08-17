<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Volumen semanal" con contrato EXPLÍCITO.
 *
 * La app no debe deducir si hubo entrenamiento mirando los kilos: una sesión de
 * peso corporal levanta 0 kg y aun así es un entrenamiento. `has_sessions` y
 * `total_sessions` viajan aparte del volumen.
 *
 * La semana va de lunes a domingo en America/Bogota. El backend corre en UTC,
 * así que un entrenamiento del domingo por la noche (03:00 UTC del lunes) tiene
 * que caer en el domingo local, no en la semana siguiente.
 */
class WeeklyTrainingTest extends TestCase
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
            'full_name' => 'Socio Semana',
            'document_number' => '440440440',
            'phone' => '+573004404404',
            'access_hash' => 'tok-440',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    /**
     * Crea una sesión dando la hora LOCAL de Bogotá.
     *
     * Las series son reales porque el volumen se deriva de ellas, no del
     * contador de la sesión: la primera carga todo el peso pedido y el resto
     * quedan completadas sin carga, como una serie de peso corporal.
     */
    private function sessionAtLocal(string $localDateTime, float $volume, int $sets = 3): void
    {
        $at = Carbon::parse($localDateTime, 'America/Bogota')->utc();

        $session = WorkoutSession::create([
            'member_id' => $this->member->id,
            'client_session_id' => 'ws-'.uniqid('', true),
            'completed_at' => $at,
            'duration_seconds' => 1800,
            'total_volume_kg' => $volume,
            'total_sets' => $sets,
            'total_exercises' => 2,
        ]);

        for ($i = 1; $i <= $sets; $i++) {
            WorkoutSessionSet::create([
                'workout_session_id' => $session->id,
                'exercise_name' => 'Sentadilla',
                'exercise_key' => 'sentadilla',
                'exercise_order' => 0,
                'set_number' => $i,
                'reps' => 1,
                'weight_kg' => $i === 1 ? $volume : 0,
                'completed' => true,
                'performed_at' => $at,
            ]);
        }
    }

    private function weekly(): array
    {
        return $this->getJson('/api/app/progress/summary', $this->auth())
            ->assertOk()->json('data.weekly_training');
    }

    private function day(array $weekly, string $label): array
    {
        foreach ($weekly['days'] as $d) {
            if ($d['label'] === $label && $d['weekday'] !== 'martes') {
                return $d;
            }
        }

        return $weekly['days'][0];
    }

    /** 1 — cero sesiones esta semana → estado vacío. */
    public function test_sin_sesiones_la_semana_esta_vacia(): void
    {
        $w = $this->weekly();

        $this->assertFalse($w['has_sessions']);
        $this->assertSame(0, $w['total_sessions']);
        $this->assertEqualsWithDelta(0, $w['total_volume_kg'], 0.01);
        $this->assertCount(7, $w['days'], 'siempre vienen los 7 días');
    }

    /** 2 — una sesión hoy con volumen > 0 → una barra hoy. */
    public function test_una_sesion_hoy_con_carga_pinta_su_barra(): void
    {
        $this->sessionAtLocal('2026-08-20 10:00:00', 2450);

        $w = $this->weekly();

        $this->assertTrue($w['has_sessions']);
        $this->assertSame(1, $w['total_sessions']);
        $this->assertEqualsWithDelta(2450, $w['total_volume_kg'], 0.01);

        $hoy = collect($w['days'])->firstWhere('is_today', true);
        $this->assertSame('jueves', $hoy['weekday']);
        $this->assertSame(1, $hoy['sessions']);
        $this->assertEqualsWithDelta(2450, $hoy['volume_kg'], 0.01);
    }

    /** 3 — una sesión hoy con volumen 0 → NO estado vacío. */
    public function test_una_sesion_de_peso_corporal_no_es_semana_vacia(): void
    {
        $this->sessionAtLocal('2026-08-20 10:00:00', 0, sets: 4);

        $w = $this->weekly();

        $this->assertTrue($w['has_sessions'], '0 kg no significa que no entrenó');
        $this->assertSame(1, $w['total_sessions']);
        $this->assertEqualsWithDelta(0, $w['total_volume_kg'], 0.01);

        $hoy = collect($w['days'])->firstWhere('is_today', true);
        $this->assertSame(1, $hoy['sessions']);
        $this->assertSame(4, $hoy['sets'], 'las series sí constan aunque el volumen sea 0');
    }

    /** 4 — dos sesiones el mismo día → se suman. */
    public function test_dos_sesiones_el_mismo_dia_se_suman(): void
    {
        $this->sessionAtLocal('2026-08-20 08:00:00', 1200, sets: 6);
        $this->sessionAtLocal('2026-08-20 19:00:00', 800, sets: 4);

        $w = $this->weekly();

        $this->assertSame(2, $w['total_sessions']);
        $this->assertEqualsWithDelta(2000, $w['total_volume_kg'], 0.01);

        $hoy = collect($w['days'])->firstWhere('is_today', true);
        $this->assertSame(2, $hoy['sessions']);
        $this->assertEqualsWithDelta(2000, $hoy['volume_kg'], 0.01);
        $this->assertSame(10, $hoy['sets']);
    }

    /** 5 — sesiones en dos días → dos barras. */
    public function test_sesiones_en_dos_dias_dan_dos_barras(): void
    {
        $this->sessionAtLocal('2026-08-18 10:00:00', 1000); // martes
        $this->sessionAtLocal('2026-08-20 10:00:00', 1500); // jueves

        $w = $this->weekly();

        $conSesion = collect($w['days'])->where('sessions', '>', 0)->values();
        $this->assertCount(2, $conSesion);
        $this->assertSame('martes', $conSesion[0]['weekday']);
        $this->assertSame('jueves', $conSesion[1]['weekday']);
        $this->assertEqualsWithDelta(2500, $w['total_volume_kg'], 0.01);
    }

    /**
     * 6 — un entrenamiento del domingo por la noche cae en el DOMINGO local.
     *
     * A las 21:00 del domingo en Bogotá ya son las 02:00 del lunes en UTC: sin
     * convertir la zona, la sesión se iría a la semana siguiente.
     */
    public function test_el_domingo_por_la_noche_cae_en_el_domingo_local(): void
    {
        // Nos situamos el domingo 23/08 a las 22:00 de Bogotá (03:00 UTC del 24).
        Carbon::setTestNow(Carbon::parse('2026-08-24 03:00:00', 'UTC'));
        $this->sessionAtLocal('2026-08-23 21:00:00', 3000);

        $w = $this->weekly();

        $this->assertTrue($w['has_sessions']);
        $this->assertSame('2026-08-17', $w['week_start']);
        $this->assertSame('2026-08-23', $w['week_end']);

        $domingo = collect($w['days'])->firstWhere('weekday', 'domingo');
        $this->assertSame(1, $domingo['sessions']);
        $this->assertTrue($domingo['is_today']);
        $this->assertEqualsWithDelta(3000, $domingo['volume_kg'], 0.01);
    }

    /**
     * Con entrenamientos previos, la semana vacía debe poder decirlo: a quien
     * ya entrenó no se le puede sugerir que empiece.
     */
    public function test_una_semana_vacia_distingue_si_ya_entreno_antes(): void
    {
        $this->sessionAtLocal('2026-08-16 19:10:00', 4900); // domingo anterior

        $w = $this->weekly();

        $this->assertFalse($w['has_sessions'], 'esta semana no hay');
        $this->assertTrue($w['has_previous_sessions'], 'pero sí entrenó antes');
        $this->assertNotNull($w['last_session_at']);
    }

    /** Un socio recién llegado no tiene nada previo. */
    public function test_un_socio_sin_historial_no_tiene_sesiones_previas(): void
    {
        $w = $this->weekly();

        $this->assertFalse($w['has_sessions']);
        $this->assertFalse($w['has_previous_sessions']);
        $this->assertNull($w['last_session_at']);
    }

    /** 7 — una sesión de la semana anterior no entra en la actual. */
    public function test_la_semana_anterior_no_se_cuela(): void
    {
        // Domingo 16/08 a las 19:10 de Bogotá: semana pasada respecto al jueves 20.
        $this->sessionAtLocal('2026-08-16 19:10:00', 4900);

        $w = $this->weekly();

        $this->assertFalse($w['has_sessions']);
        $this->assertSame(0, $w['total_sessions']);
        $this->assertSame('2026-08-17', $w['week_start']);
    }

    /** El lunes a las 00:30, lo entrenado el domingo ya es semana pasada. */
    public function test_pasada_la_medianoche_del_lunes_empieza_semana_nueva(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 05:30:00', 'UTC')); // lunes 00:30 Bogotá
        $this->sessionAtLocal('2026-08-23 19:10:00', 4900); // domingo anterior

        $w = $this->weekly();

        $this->assertFalse(
            $w['has_sessions'],
            'una vista "esta semana" no puede seguir mostrando lo del domingo anterior',
        );
        $this->assertSame('2026-08-24', $w['week_start']);
    }
}
