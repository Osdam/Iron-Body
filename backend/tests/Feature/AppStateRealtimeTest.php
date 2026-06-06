<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sincronización near-real-time: el snapshot de /member/app-state expone una
 * `versions.membership` que cambia cuando la membresía cambia en el backend/CRM.
 * Así la app detecta cambios (activar membresía, pago, renovación) y refresca SIN
 * cerrar sesión ni reiniciar.
 */
class AppStateRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $plan, string $endDate): Member
    {
        $user = User::create([
            'name' => 'Ana Prueba',
            'email' => 'ana@example.com',
            'password' => 'secret',
            'document' => '1010101010',
            'phone' => '3001234567',
            'status' => 'active',
            'plan' => $plan,
            'membership_end_date' => $endDate,
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Ana Prueba',
            'email' => 'ana@example.com',
            'document_number' => '1010101010',
            'phone' => '3001234567',
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    public function test_app_state_version_reflects_membership_changes(): void
    {
        $member = $this->member('PLAN TOTAL', now()->addDays(20)->toDateString());
        $auth = ['Authorization' => 'Bearer '.$member->access_hash];

        $r1 = $this->getJson('/api/member/app-state', $auth);
        $r1->assertOk()
            ->assertJsonPath('membership.is_active', true)
            ->assertJsonPath('membership.plan_name', 'PLAN TOTAL');
        $v1 = $r1->json('versions.membership');
        $this->assertIsInt($v1);

        // Cambio de membresía desde el CRM/VPS (renovación: nueva fecha de fin).
        $member->user->forceFill([
            'membership_end_date' => now()->addDays(60)->toDateString(),
            'updated_at' => now()->addMinutes(5),
        ])->save();

        $r2 = $this->getJson('/api/member/app-state', $auth);
        $r2->assertOk()->assertJsonPath('membership.is_active', true);
        $v2 = $r2->json('versions.membership');

        // La versión cambió → la app sabe que debe reflejar el nuevo estado.
        $this->assertNotSame($v1, $v2);
    }

    public function test_app_state_membership_reflects_expiration(): void
    {
        // Membresía ya vencida → app-state la reporta inactiva en tiempo real.
        $member = $this->member('PLAN TOTAL', now()->subDay()->toDateString());

        $this->getJson('/api/member/app-state', [
            'Authorization' => 'Bearer '.$member->access_hash,
        ])->assertOk()->assertJsonPath('membership.is_active', false);
    }
}
