<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberRiskLock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suspensión de cuenta por seguridad (Bloque 3a / Fase 10): un bloqueo activo
 * impide login y sesiones; el desbloqueo del CRM lo restaura.
 */
class AccountSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Member
    {
        $user = User::create([
            'name' => 'Ana Prueba',
            'email' => 'ana@example.com',
            'password' => 'secret',
            'document' => '1010101010',
            'phone' => '3001234567',
            'status' => 'active',
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

    public function test_admin_suspend_blocks_login_and_sessions_then_unlock_restores(): void
    {
        $member = $this->member();
        $auth = ['Authorization' => 'Bearer '.$member->access_hash];

        // Login normal funciona antes de suspender.
        $this->postJson('/api/members/login', ['document_number' => '1010101010'])
            ->assertOk()->assertJsonPath('data.requires_otp', true);

        // Admin suspende 3 días.
        $this->postJson("/api/admin/members/{$member->id}/suspend", [
            'reason' => 'Actividad sospechosa',
            'days' => 3,
        ])->assertOk();

        $member->refresh();
        $this->assertSame(Member::STATUS_SUSPENDED, $member->status);
        $this->assertTrue($member->isSuspended());

        // Login bloqueado con código estable.
        $this->postJson('/api/members/login', ['document_number' => '1010101010'])
            ->assertStatus(423)
            ->assertJsonPath('code', 'account_suspended');

        // Endpoint autenticado bloqueado por el middleware.
        $this->getJson('/api/members/devices', $auth)
            ->assertStatus(401)
            ->assertJsonPath('code', 'account_suspended');

        // Admin desbloquea.
        $this->postJson("/api/admin/members/{$member->id}/unlock", [
            'note' => 'Identidad validada en recepción',
        ])->assertOk();

        $member->refresh();
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertFalse($member->isSuspended());
        $this->assertSame(
            MemberRiskLock::STATUS_RESOLVED,
            $member->riskLocks()->latest('id')->first()->status,
        );

        // Login vuelve a funcionar.
        $this->postJson('/api/members/login', ['document_number' => '1010101010'])
            ->assertOk()->assertJsonPath('data.requires_otp', true);
    }

    public function test_expired_lock_does_not_block(): void
    {
        $member = $this->member();
        MemberRiskLock::create([
            'member_id' => $member->id,
            'reason' => 'viejo',
            'status' => MemberRiskLock::STATUS_ACTIVE,
            'locked_until' => now()->subDay(), // ya venció
            'created_by' => MemberRiskLock::BY_SYSTEM,
        ]);

        $this->assertFalse($member->fresh()->isSuspended());
        $this->postJson('/api/members/login', ['document_number' => '1010101010'])
            ->assertOk()->assertJsonPath('data.requires_otp', true);
    }
}
