<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberDeviceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2FA por OTP en acciones sensibles autenticadas (Bloque 1, Fases 6-7):
 * eliminar cuenta y revocar dispositivos exigen un código válido.
 */
class SensitiveActionTwoFactorTest extends TestCase
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
            'access_hash' => 'test-token-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    public function test_account_deletion_requires_valid_otp(): void
    {
        $member = $this->member();

        $req = $this->postJson('/api/member/account/delete-request', [], $this->auth($member));
        $req->assertOk()
            ->assertJsonPath('requires_otp', true);
        $challengeId = $req->json('challenge_id');
        $code = $req->json('dev_code');
        $this->assertNotEmpty($challengeId);
        $this->assertNotEmpty($code, 'El driver dev debe exponer el código en testing.');

        // Código equivocado → rechazado, la cuenta sigue activa.
        $this->postJson('/api/member/account/delete-confirm', [
            'challenge_id' => $challengeId,
            'code' => '000000',
        ], $this->auth($member))->assertStatus(422);
        $this->assertSame(Member::STATUS_ACTIVE, $member->fresh()->status);

        // Sin OTP → falla validación.
        $this->postJson('/api/member/account/delete-confirm', [], $this->auth($member))
            ->assertStatus(422);

        // Código correcto → borra/anonimiza.
        $this->postJson('/api/member/account/delete-confirm', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ], $this->auth($member))->assertOk();
        $this->assertSame(Member::STATUS_DELETED, $member->fresh()->status);
    }

    public function test_device_revoke_requires_valid_otp(): void
    {
        $member = $this->member();

        $target = MemberDeviceSession::create([
            'member_id' => $member->id,
            'device_id' => 'other-device-123',
            'device_name' => 'iPhone ajeno',
            'platform' => 'ios',
            'token_hash' => hash('sha256', 'other-token'),
            'last_seen_at' => now(),
        ]);

        $req = $this->postJson("/api/members/devices/{$target->uuid}/revoke-request", [], $this->auth($member));
        $req->assertOk()->assertJsonPath('requires_otp', true);
        $code = $req->json('dev_code');
        $challengeId = $req->json('challenge_id');

        // Código equivocado → la sesión sigue viva.
        $this->postJson("/api/members/devices/{$target->uuid}/revoke-confirm", [
            'challenge_id' => $challengeId,
            'code' => '111111',
        ], $this->auth($member))->assertStatus(422);
        $this->assertNull($target->fresh()->revoked_at);

        // Código correcto → revoca.
        $this->postJson("/api/members/devices/{$target->uuid}/revoke-confirm", [
            'challenge_id' => $challengeId,
            'code' => $code,
        ], $this->auth($member))->assertOk();
        $this->assertNotNull($target->fresh()->revoked_at);
    }

    public function test_purpose_isolation_login_challenge_cannot_delete_account(): void
    {
        $member = $this->member();

        // Un reto de propósito LOGIN no debe servir para confirmar un borrado.
        $login = $this->postJson('/api/member/account/delete-request', [], $this->auth($member));
        $challengeId = $login->json('challenge_id');

        // Forzamos el propósito del reto a 'login' para simular reuso cruzado.
        MemberAuthChallenge::where('uuid', $challengeId)->update(['purpose' => MemberAuthChallenge::PURPOSE_LOGIN]);

        $this->postJson('/api/member/account/delete-confirm', [
            'challenge_id' => $challengeId,
            'code' => $login->json('dev_code'),
        ], $this->auth($member))->assertStatus(404);
        $this->assertSame(Member::STATUS_ACTIVE, $member->fresh()->status);
    }
}
