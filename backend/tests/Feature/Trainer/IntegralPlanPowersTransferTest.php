<?php

namespace Tests\Feature\Trainer;

use App\Models\Exercise;
use App\Models\Member;
use App\Models\MembershipAiCapability;
use App\Models\MemberTrainerAssignment;
use App\Models\NutritionAiRun;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Models\User;
use App\Services\Identity\IdentityLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Members\MembershipTransferFixtures;
use Tests\Support\FakeIronIaOpenAi;
use Tests\TestCase;

/**
 * Potencias tras TRANSFERIR UNA MEMBRESÍA (docs/ops/TRANSFERENCIA-DE-MEMBRESIAS.md).
 *
 * La potencia sale del plan VIGENTE: tras el traspaso, el beneficiario tiene
 * el plan que recibió (y con él su nivel) y la titular ya no tiene membresía
 * (termina ayer), así que vuelve al nivel 1. Se usa el traspaso REAL —caja,
 * cobro y transferencia por la API—, no un `users.plan` escrito a mano: si
 * mañana el traspaso dejara el plan en otro sitio, esto lo diría.
 */
class IntegralPlanPowersTransferTest extends TestCase
{
    use MembershipTransferFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararTraspasos();
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.professional_assessments_enabled' => true,
            'trainer.flags.trainer_nutrition_guides_enabled' => true,
            'services.openai.enabled' => true,
            'services.openai.api_key' => 'sk-test',
            'services.openai.model' => 'gpt-4.1-mini',
            'services.openai.base_url' => 'https://api.openai.com',
            'iron_ai.plan_powers.model' => 'gpt-4.1-mini',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_beneficiario_genera_con_las_potencias_del_plan_recibido_y_la_titular_vuelve_al_nivel_1(): void
    {
        MembershipAiCapability::create([
            'membership_plan_id' => $this->anualidad->id, 'plan_code' => 'anualidad_elite', 'is_active' => true,
            'ai_enabled' => true, 'nutrition_power' => 4, 'routine_power' => 3,
        ]);

        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Beneficiario', ficha: ['training_level' => 'intermedio', 'goal' => 'Ganar masa muscular']);
        $this->transferir($a, $b)->assertCreated();

        $this->assertSame($this->anualidad->name, $b->refresh()->plan, 'el traspaso deja el plan recibido en users.plan');

        $token = $this->trainerFor([$a, $b]);
        foreach (range(1, 8) as $i) {
            Exercise::create([
                'name' => "Ejercicio {$i}", 'local_name' => "Ejercicio {$i}", 'muscle_group' => 'General', 'body_part' => 'General',
                'equipment' => 'mancuernas', 'difficulty' => 'intermedio', 'target' => 'General',
                'video_path' => "videos/t{$i}.mp4", 'provider' => 'local', 'external_id' => "tr-{$i}",
            ]);
        }
        FakeIronIaOpenAi::install();

        $miembroB = Member::where('user_id', $b->id)->firstOrFail();
        $this->postJson("/api/trainer/members/{$miembroB->id}/integral-plan/generate", [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(201);
        $runB = NutritionAiRun::where('member_id', $miembroB->id)->firstOrFail();
        $this->assertSame([4, 3], [$runB->nutrition_power, $runB->routine_power]);

        $miembroA = Member::where('user_id', $a->id)->firstOrFail();
        $antes = count(Http::recorded());
        $this->postJson("/api/trainer/members/{$miembroA->id}/integral-plan/generate", [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(201);
        $runA = NutritionAiRun::where('member_id', $miembroA->id)->firstOrFail();
        $this->assertSame([1, 1], [$runA->nutrition_power, $runA->routine_power]);
        $this->assertSame(1, count(Http::recorded()) - $antes, 'la titular genera con UNA llamada: nivel 1');
    }

    /** @param  list<User>  $socios */
    private function trainerFor(array $socios): string
    {
        $trainer = Trainer::create([
            'full_name' => 'Coach Traspasos', 'document' => '9800', 'phone' => '+573009990000',
            'status' => 'active', 'location' => 'Sede Norte',
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $trainer->refresh();
        $trainer->syncRoles([TrainerRole::FLOOR]);
        foreach ($socios as $u) {
            MemberTrainerAssignment::create([
                'member_id' => Member::where('user_id', $u->id)->value('id'), 'trainer_id' => $trainer->id,
                'status' => MemberTrainerAssignment::STATUS_ACTIVE,
            ]);
        }

        $a = $this->postJson('/api/trainer/auth/access', ['document' => '9800', 'device_id' => 'tt'])->assertOk();

        return $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $a->json('challenge_id'), 'code' => $a->json('dev_code'), 'device_id' => 'tt',
        ])->assertOk()->json('token');
    }
}
