<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\PhysicalEvaluation;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Progreso conectado a los entrenamientos reales.
 *
 * El backend corre en UTC y el gimnasio opera en America/Bogota. Los límites de
 * rango se construían en hora local y Laravel los serializaba TAL CUAL contra
 * columnas UTC: un entrenamiento de las 22:32 en Bogotá (03:32 UTC del día
 * siguiente) quedaba fuera de "hoy" y de "este mes", así que Progreso seguía
 * diciendo "Entrenamientos: 0" y "Aún no registras entrenamientos esta semana".
 */
class ProgressSummaryTest extends TestCase
{
    use RefreshDatabase;

    /** Domingo 16/08/2026 22:30 en Bogotá = lunes 17/08 03:30 UTC. */
    private const NOW_UTC = '2026-08-17 03:30:00';

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));

        $this->member = Member::create([
            'full_name' => 'Socio Progreso',
            'document_number' => '990990990',
            'phone' => '+573009909909',
            'access_hash' => 'tok-990',
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
     * Sesión con SERIES reales: el volumen semanal se deriva de ellas, no del
     * contador de la sesión, así que una sesión de mentira sin series valdría 0.
     * La primera serie carga todo el peso pedido; las demás quedan sin carga
     * para que el conteo de series siga siendo 6.
     */
    private function makeSession(string $completedAtUtc, float $volume, string $clientId): WorkoutSession
    {
        $at = Carbon::parse($completedAtUtc, 'UTC');

        $session = WorkoutSession::create([
            'member_id' => $this->member->id,
            'client_session_id' => $clientId,
            'completed_at' => $at,
            'duration_seconds' => 1800,
            'total_volume_kg' => $volume,
            'total_sets' => 6,
            'total_exercises' => 2,
        ]);

        for ($i = 1; $i <= 6; $i++) {
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

        return $session;
    }

    public function test_un_entrenamiento_de_la_noche_cuenta_en_el_mes(): void
    {
        // Entrenó hace un minuto: 22:29 en Bogotá, ya día 17 en UTC.
        $this->makeSession('2026-08-17 03:29:00', 1500, 'sess-noche');

        $this->getJson('/api/app/progress/summary', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.workouts_count_month', 1);
    }

    public function test_el_volumen_semanal_usa_las_sesiones_reales(): void
    {
        $this->makeSession('2026-08-17 03:29:00', 1500, 'sess-domingo');

        $bars = $this->getJson('/api/app/progress/summary', $this->auth())
            ->assertOk()->json('data.weekly_volume');

        $this->assertCount(7, $bars);
        // Domingo es el último día de la semana lunes→domingo.
        $domingo = $bars[6];
        $this->assertSame(1500, $domingo['value']);
        $this->assertSame(1, $domingo['sessions']);
        $this->assertTrue($domingo['highlight'], 'domingo es hoy en hora local');
    }

    public function test_una_semana_sin_carga_no_parece_semana_sin_entrenar(): void
    {
        // Entrenamiento solo de peso corporal: volumen 0 pero sesión real.
        $this->makeSession('2026-08-17 03:29:00', 0, 'sess-bodyweight');

        $bars = $this->getJson('/api/app/progress/summary', $this->auth())
            ->assertOk()->json('data.weekly_volume');

        $this->assertSame(0, $bars[6]['value']);
        $this->assertSame(1, $bars[6]['sessions'], 'la app debe poder distinguir 0 kg de 0 entrenamientos');
    }

    public function test_sin_entrenamientos_todo_queda_en_cero_honesto(): void
    {
        $data = $this->getJson('/api/app/progress/summary', $this->auth())
            ->assertOk()->json('data');

        $this->assertSame(0, $data['workouts_count_month']);
        $this->assertSame(0, collect($data['weekly_volume'])->sum('sessions'));
        $this->assertNull($data['current_weight_kg']);
        $this->assertNull($data['bmi']);
        $this->assertFalse($data['has_evaluation']);
    }

    public function test_el_peso_y_el_imc_salen_de_la_evaluacion_fisica(): void
    {
        PhysicalEvaluation::create([
            'member_id' => $this->member->id,
            'weight_kg' => 74.5,
            'height_cm' => 175,
        ]);

        $data = $this->getJson('/api/app/progress/summary', $this->auth())
            ->assertOk()->json('data');

        $this->assertEqualsWithDelta(74.5, $data['current_weight_kg'], 0.01);
        $this->assertNotNull($data['bmi'], 'con peso y estatura sí se puede calcular');
        $this->assertTrue($data['has_evaluation']);
    }

    public function test_sin_estatura_el_imc_queda_pendiente(): void
    {
        PhysicalEvaluation::create([
            'member_id' => $this->member->id,
            'weight_kg' => 74.5,
        ]);

        $data = $this->getJson('/api/app/progress/summary', $this->auth())
            ->assertOk()->json('data');

        $this->assertEqualsWithDelta(74.5, $data['current_weight_kg'], 0.01);
        $this->assertNull($data['bmi'], 'no se inventa un IMC sin estatura');
    }

    public function test_los_records_llegan_a_progreso(): void
    {
        $session = $this->makeSession('2026-08-17 03:29:00', 500, 'sess-record');
        \App\Models\PersonalRecord::create([
            'member_id' => $this->member->id,
            'exercise_name' => 'Sentadilla',
            'exercise_key' => 'sentadilla',
            'metric' => \App\Models\PersonalRecord::METRIC_MAX_WEIGHT,
            'value' => 100,
            'unit' => 'kg',
            'reps' => 5,
            'weight_kg' => 100,
            'achieved_at' => Carbon::parse('2026-08-17 03:29:00', 'UTC'),
            'source' => \App\Models\PersonalRecord::SOURCE_WORKOUT,
            'workout_session_id' => $session->id,
        ]);

        $records = $this->getJson('/api/app/progress/summary', $this->auth())
            ->assertOk()->json('data.personal_records');

        $this->assertCount(1, $records);
        $this->assertSame('Sentadilla', $records[0]['name']);
        $this->assertEqualsWithDelta(100, $records[0]['value'], 0.01);
        $this->assertSame('Carga máxima', $records[0]['metric_label']);
    }
}
