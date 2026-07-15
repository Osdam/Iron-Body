<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use App\Services\DeviceSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BACK-006: el `access_hash` permanente ahora es revocable. Un hash revocado deja
 * de servir como bearer; "cerrar otras sesiones" (desde una sesión de
 * dispositivo) lo revoca para que un hash filtrado no siga dando acceso.
 */
class AccessHashRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Member
    {
        $user = User::create([
            'name' => 'Ana Prueba',
            'email' => 'ana'.random_int(1, 99999).'@example.com',
            'password' => 'secret',
            'document' => (string) random_int(10000000, 99999999),
            'phone' => '3001234567',
            'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Ana Prueba',
            'document_number' => (string) random_int(10000000, 99999999),
            'phone' => '3001234567',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    public function test_valid_access_hash_works(): void
    {
        $member = $this->member();

        $this->getJson('/api/member/account/status', [
            'Authorization' => 'Bearer '.$member->access_hash,
        ])->assertOk();
    }

    public function test_revoked_access_hash_is_rejected(): void
    {
        $member = $this->member();
        $member->revokeAccessHash();

        $this->getJson('/api/member/account/status', [
            'Authorization' => 'Bearer '.$member->access_hash,
        ])->assertStatus(401);
    }

    public function test_revoke_others_from_a_device_session_revokes_the_access_hash(): void
    {
        $member = $this->member();
        $issued = app(DeviceSessionService::class)->issueSession($member, ['device_id' => 'dev-1']);
        $sessionToken = $issued['token'];
        $accessHash = $member->access_hash;

        // Cerrar las demás sesiones AUTENTICADO con el session_token.
        $this->postJson('/api/member/devices/revoke-others', [], [
            'Authorization' => 'Bearer '.$sessionToken,
        ])->assertOk();

        // El session_token actual sigue sirviendo…
        $this->getJson('/api/member/account/status', [
            'Authorization' => 'Bearer '.$sessionToken,
        ])->assertOk();

        // …pero el access_hash permanente quedó revocado.
        $this->assertNotNull($member->fresh()->access_hash_revoked_at);
        $this->getJson('/api/member/account/status', [
            'Authorization' => 'Bearer '.$accessHash,
        ])->assertStatus(401);
    }

    public function test_revoke_others_via_access_hash_does_not_lock_out_current_device(): void
    {
        $member = $this->member();
        $accessHash = $member->access_hash;

        // Autenticado con el propio access_hash (sin session_token): cerrar otras
        // sesiones NO debe revocar el hash con el que estoy operando.
        $this->postJson('/api/member/devices/revoke-others', [], [
            'Authorization' => 'Bearer '.$accessHash,
        ])->assertOk();

        $this->assertNull($member->fresh()->access_hash_revoked_at);
        $this->getJson('/api/member/account/status', [
            'Authorization' => 'Bearer '.$accessHash,
        ])->assertOk();
    }
}
