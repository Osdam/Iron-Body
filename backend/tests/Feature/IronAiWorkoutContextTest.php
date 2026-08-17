<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\PersonalRecord;
use App\Models\WorkoutSession;
use App\Services\IronAiUserContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El contexto que recibe IRON IA debe traer entrenamiento REAL, y distinguir
 * "no entrenó" de "no lo sabemos": los agregados sin datos viajan como null,
 * nunca como un 0 que la IA leería como un hecho.
 */
class IronAiWorkoutContextTest extends TestCase
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
            'full_name' => 'Socio IA',
            'document_number' => '660660660',
            'phone' => '+573006606606',
            'access_hash' => 'tok-660',
            'status' => Member::STATUS_ACTIVE,
            'gender' => 'Masculino',
            'goal' => 'Hipertrofia muscular',
            'training_level' => 'Principiante',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function context(array $modules): array
    {
        return app(IronAiUserContextService::class)->build($this->member, $modules);
    }

    public function test_el_onboarding_llega_al_contexto_de_la_ia(): void
    {
        $profile = $this->context(['profile'])['profile'];

        $this->assertSame('Masculino', $profile['gender']);
        $this->assertSame('Hipertrofia muscular', $profile['goal']);
        $this->assertSame('Principiante', $profile['training_level']);
    }

    public function test_sin_entrenamientos_los_agregados_son_null_no_cero(): void
    {
        $workouts = $this->context(['workouts'])['workouts'];

        $this->assertSame(0, $workouts['completed_this_week']);
        $this->assertNull($workouts['volume_kg_this_week'], 'sin datos no se afirma 0 kg');
        $this->assertNull($workouts['avg_duration_minutes_last_30d']);
        $this->assertSame([], $workouts['personal_records']);
    }

    public function test_el_entrenamiento_de_la_noche_entra_en_la_semana(): void
    {
        // 22:29 en Bogotá del domingo: ya es día 17 en UTC.
        WorkoutSession::create([
            'member_id' => $this->member->id,
            'client_session_id' => 'ctx-1',
            'completed_at' => Carbon::parse('2026-08-17 03:29:00', 'UTC'),
            'duration_seconds' => 3000,
            'total_volume_kg' => 1500,
            'total_sets' => 9,
            'total_exercises' => 3,
        ]);

        $workouts = $this->context(['workouts'])['workouts'];

        $this->assertSame(1, $workouts['completed_this_week']);
        $this->assertEqualsWithDelta(1500, $workouts['volume_kg_this_week'], 0.01);
        $this->assertSame(50, $workouts['avg_duration_minutes_last_30d']);
    }

    public function test_los_records_reales_llegan_a_la_ia(): void
    {
        PersonalRecord::create([
            'member_id' => $this->member->id,
            'exercise_name' => 'Press de banca',
            'exercise_key' => 'press de banca',
            'metric' => PersonalRecord::METRIC_MAX_WEIGHT,
            'value' => 65,
            'unit' => 'kg',
            'achieved_at' => Carbon::parse('2026-08-16 12:00:00', 'UTC'),
            'source' => PersonalRecord::SOURCE_WORKOUT,
        ]);

        $records = $this->context(['workouts'])['workouts']['personal_records'];

        $this->assertCount(1, $records);
        $this->assertSame('Press de banca', $records[0]['exercise']);
        $this->assertEqualsWithDelta(65, $records[0]['value'], 0.01);
    }

    public function test_el_contexto_no_incluye_datos_sensibles(): void
    {
        $ctx = $this->context(['profile', 'workouts']);
        $flat = json_encode($ctx);

        foreach (['access_hash', 'tok-660', 'password', 'document_number', '660660660'] as $secret) {
            $this->assertStringNotContainsString($secret, $flat);
        }
    }
}
