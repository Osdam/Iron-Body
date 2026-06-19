<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\DeviceSessionService;
use App\Services\Identity\IdentityLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 5 — Sesiones, scopes y cambio de espacio. Verifica el bootstrap como
 * fuente de verdad de espacios, la rotación biométrica de token, la revocación
 * desde el CRM, el aislamiento de scope y que el miembro normal no descubre el
 * portal profesional.
 */
class TrainerWorkspaceSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.workspace_switching_enabled' => true,
        ]);
    }

    private function activeTrainer(string $document, array $roles = [TrainerRole::FLOOR]): Trainer
    {
        $trainer = Trainer::create([
            'full_name' => 'Pro',
            'document' => $document,
            'phone' => '+573009998877',
            'status' => 'active',
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $trainer->refresh();
        $trainer->syncRoles($roles);

        return $trainer->fresh('roleAssignments');
    }

    private function loginToken(string $document): string
    {
        $access = $this->postJson('/api/trainer/auth/access', ['document' => $document, 'device_id' => 'd1'])->assertOk();

        return $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $access->json('challenge_id'),
            'code' => $access->json('dev_code'),
            'device_id' => 'd1',
        ])->assertOk()->json('token');
    }

    public function test_bootstrap_returns_trainer_only_workspace(): void
    {
        $trainer = $this->activeTrainer('111');
        $token = $this->loginToken('111');

        $this->getJson('/api/trainer/auth/bootstrap', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('workspaces', ['trainer'])
            ->assertJsonPath('identity_id', $trainer->identity_id)
            ->assertJsonPath('trainer.id', $trainer->id);
    }

    public function test_bootstrap_includes_member_workspace_for_dual_profile(): void
    {
        $trainer = $this->activeTrainer('222');
        // Misma persona también es miembro.
        Member::create([
            'full_name' => 'Dual',
            'document_number' => '222',
            'phone' => '+573001112233',
            'status' => Member::STATUS_ACTIVE,
        ]);
        app(IdentityLinkService::class)->backfillExisting();

        $token = $this->loginToken('222');

        $this->getJson('/api/trainer/auth/bootstrap', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('workspaces', ['trainer', 'member']);
    }

    public function test_biometric_unlock_rotates_token_and_invalidates_old(): void
    {
        $this->activeTrainer('333');
        $oldToken = $this->loginToken('333');

        $new = $this->postJson('/api/trainer/auth/biometric-unlock', [], ['Authorization' => "Bearer {$oldToken}"])
            ->assertOk();
        $newToken = $new->json('token');

        $this->assertNotSame($oldToken, $newToken);
        // El token nuevo funciona; el viejo ya no resuelve (fue rotado).
        $this->getJson('/api/trainer/auth/me', ['Authorization' => "Bearer {$newToken}"])->assertOk();
        $this->getJson('/api/trainer/auth/me', ['Authorization' => "Bearer {$oldToken}"])->assertStatus(401);
    }

    public function test_admin_can_list_and_revoke_devices(): void
    {
        $trainer = $this->activeTrainer('444');
        $token = $this->loginToken('444');

        $devices = $this->adminGetJson("/api/admin/trainers/{$trainer->id}/devices")->assertOk();
        $uuid = $devices->json('data.0.uuid');
        $this->assertNotNull($uuid);

        $this->adminPostJson("/api/admin/trainers/{$trainer->id}/devices/{$uuid}/revoke")->assertOk();

        // La sesión revocada deja de resolver.
        $this->getJson('/api/trainer/auth/me', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_admin_revoke_all_sessions(): void
    {
        $trainer = $this->activeTrainer('555');
        $token = $this->loginToken('555');

        $this->adminPostJson("/api/admin/trainers/{$trainer->id}/sessions/revoke-all")
            ->assertOk()
            ->assertJsonPath('revoked', 1);

        $this->getJson('/api/trainer/auth/me', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_normal_member_does_not_discover_trainer_portal(): void
    {
        $member = Member::create([
            'full_name' => 'Normal',
            'document_number' => '666',
            'phone' => '+573001112233',
            'status' => Member::STATUS_ACTIVE,
        ]);
        $token = app(DeviceSessionService::class)->issueSession($member, ['device_id' => 'm1'])['token'];

        $this->getJson('/api/member/workspaces', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'workspaces' => ['member'],
                'has_trainer_portal' => false,
            ]);
    }

    public function test_dual_member_discovers_trainer_portal_when_flag_on(): void
    {
        $this->activeTrainer('777');
        $member = Member::create([
            'full_name' => 'Dual',
            'document_number' => '777',
            'phone' => '+573001112233',
            'status' => Member::STATUS_ACTIVE,
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $token = app(DeviceSessionService::class)->issueSession($member->fresh(), ['device_id' => 'm1'])['token'];

        $this->getJson('/api/member/workspaces', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('has_trainer_portal', true)
            ->assertJsonPath('workspaces', ['member', 'trainer']);

        // Con el flag apagado para esa identidad, deja de descubrirse.
        config(['trainer.flags.workspace_switching_enabled' => false, 'trainer.pilot_identities' => []]);
        $this->getJson('/api/member/workspaces', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('has_trainer_portal', false);
    }
}
