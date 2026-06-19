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
 * Fase 4 — Login OTP profesional. Verifica el flujo access→verify→sesión, la
 * anti-enumeración, que el OTP no concede el rol, el aislamiento de scope frente
 * a la sesión de miembro y que la desactivación corta el acceso.
 */
class TrainerAuthOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // El portal vive detrás de un feature flag; lo activamos para el piloto
        // de pruebas. OTP corre con driver `dev` (código expuesto, sin SMS real).
        config(['trainer.flags.trainer_auth_enabled' => true]);
    }

    private function activeTrainer(string $document = '100200300', array $roles = [TrainerRole::FLOOR]): Trainer
    {
        $trainer = Trainer::create([
            'full_name' => 'Pro Trainer',
            'document' => $document,
            'phone' => '+573009998877',
            'status' => 'active',
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $trainer->refresh();
        $trainer->syncRoles($roles);

        return $trainer->fresh('roleAssignments');
    }

    private function login(Trainer $trainer, string $document): string
    {
        $access = $this->postJson('/api/trainer/auth/access', [
            'document' => $document,
            'device_id' => 'dev-1',
        ])->assertOk();

        $verify = $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $access->json('challenge_id'),
            'code' => $access->json('dev_code'),
            'device_id' => 'dev-1',
        ])->assertOk();

        return $verify->json('token');
    }

    public function test_routes_hidden_when_feature_flag_off(): void
    {
        config(['trainer.flags.trainer_auth_enabled' => false, 'trainer.pilot_identities' => []]);

        $this->postJson('/api/trainer/auth/access', ['document' => '1'])->assertStatus(404);
    }

    public function test_active_trainer_can_login_and_use_token(): void
    {
        $trainer = $this->activeTrainer();
        $token = $this->login($trainer, '100200300');

        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('trainer_device_sessions', ['trainer_id' => $trainer->id]);

        $this->getJson('/api/trainer/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('trainer.id', $trainer->id)
            ->assertJsonFragment(['members.view_assigned']);
    }

    public function test_access_is_uniform_for_unknown_document(): void
    {
        $response = $this->postJson('/api/trainer/auth/access', ['document' => '000-does-not-exist'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Devuelve un challenge señuelo y el MISMO mensaje genérico, sin filtrar.
        $this->assertNotEmpty($response->json('challenge_id'));
        $this->assertArrayNotHasKey('dev_code', $response->json());
        // Verificar el señuelo falla de forma neutral.
        $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $response->json('challenge_id'),
            'code' => '123456',
        ])->assertStatus(422);
    }

    public function test_inactive_trainer_cannot_login(): void
    {
        $trainer = $this->activeTrainer();
        $trainer->update(['status' => 'inactive']);

        $access = $this->postJson('/api/trainer/auth/access', ['document' => '100200300'])->assertOk();
        // Trainer inactivo ⇒ señuelo (sin dev_code).
        $this->assertArrayNotHasKey('dev_code', $access->json());
    }

    public function test_otp_does_not_grant_role(): void
    {
        // Entrenador SIN roles: el OTP confirma el teléfono pero no da permisos.
        $trainer = $this->activeTrainer(roles: []);
        $token = $this->login($trainer, '100200300');

        $this->getJson('/api/trainer/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('trainer.permissions', []);
    }

    public function test_wrong_code_decrements_attempts(): void
    {
        $this->activeTrainer();
        $access = $this->postJson('/api/trainer/auth/access', ['document' => '100200300'])->assertOk();

        $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $access->json('challenge_id'),
            'code' => '000000',
        ])->assertStatus(422)->assertJsonPath('remaining', 4);
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->activeTrainer();
        $access = $this->postJson('/api/trainer/auth/access', ['document' => '100200300'])->assertOk();

        $this->travel(11)->minutes();

        $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $access->json('challenge_id'),
            'code' => $access->json('dev_code'),
        ])->assertStatus(410);
    }

    public function test_code_cannot_be_reused(): void
    {
        $trainer = $this->activeTrainer();
        $access = $this->postJson('/api/trainer/auth/access', ['document' => '100200300'])->assertOk();

        $payload = [
            'challenge_id' => $access->json('challenge_id'),
            'code' => $access->json('dev_code'),
            'device_id' => 'dev-1',
        ];

        $this->postJson('/api/trainer/auth/verify', $payload)->assertOk();
        // Segundo intento con el mismo reto: ya consumido.
        $this->postJson('/api/trainer/auth/verify', $payload)->assertStatus(409);
    }

    public function test_member_token_cannot_access_trainer_routes(): void
    {
        // Aislamiento de scope: un token de sesión de miembro no abre el portal.
        $member = Member::create([
            'full_name' => 'A Member',
            'document_number' => '777',
            'phone' => '+573001112233',
            'status' => Member::STATUS_ACTIVE,
        ]);
        $issued = app(DeviceSessionService::class)
            ->issueSession($member, ['device_id' => 'm-dev']);

        $this->getJson('/api/trainer/auth/me', ['Authorization' => "Bearer {$issued['token']}"])
            ->assertStatus(401);
    }

    public function test_deactivation_revokes_active_sessions(): void
    {
        $trainer = $this->activeTrainer();
        $token = $this->login($trainer, '100200300');

        $this->getJson('/api/trainer/auth/me', ['Authorization' => "Bearer {$token}"])->assertOk();

        // El CRM desactiva al entrenador.
        $this->adminPostJson("/api/admin/trainers/{$trainer->id}/deactivate")->assertOk();

        $this->getJson('/api/trainer/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(401);
    }

    public function test_me_requires_token(): void
    {
        $this->getJson('/api/trainer/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'token_required');
    }
}
