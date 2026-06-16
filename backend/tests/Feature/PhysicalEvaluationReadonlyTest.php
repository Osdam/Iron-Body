<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\PhysicalEvaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La evaluación física profesional la gestiona el entrenador. El miembro NO
 * puede crearla/editarla (ni desde Flutter ni llamando directo al API), pero SÍ
 * puede consultar su historial. No se borra ningún dato histórico.
 */
class PhysicalEvaluationReadonlyTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Member
    {
        return Member::create([
            'full_name' => 'Member', 'document_number' => '700',
            'phone' => '3001112233', 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => "Bearer {$m->access_hash}"];
    }

    public function test_member_cannot_create_physical_evaluation_via_api(): void
    {
        $member = $this->member();

        $this->postJson('/api/app/physical-evaluations', [
            'weight_kg' => 80, 'height_cm' => 175,
        ], $this->auth($member))
            ->assertStatus(403)
            ->assertJsonPath('code', 'evaluation_member_readonly');

        $this->assertDatabaseCount('physical_evaluations', 0);
    }

    public function test_member_can_still_read_history(): void
    {
        $member = $this->member();
        PhysicalEvaluation::create([
            'member_id' => $member->id, 'weight_kg' => 78, 'height_cm' => 175,
        ]);

        $this->getJson('/api/app/physical-evaluations/latest', $this->auth($member))
            ->assertOk();
        $this->getJson('/api/app/physical-evaluations', $this->auth($member))
            ->assertOk();

        // El histórico se conserva intacto.
        $this->assertDatabaseCount('physical_evaluations', 1);
    }
}
