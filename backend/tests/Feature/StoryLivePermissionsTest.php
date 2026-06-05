<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato de permisos de Story Live (Bloque extra): el backend decide
 * can_create/can_view según is_staff (otorgado por el CRM) y si LiveKit está
 * configurado. La app solo renderiza.
 */
class StoryLivePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function configureLiveKit(): void
    {
        config([
            'live.enabled' => true,
            'live.livekit.url' => 'wss://test.livekit.cloud',
            'live.livekit.api_key' => 'APIkey123',
            'live.livekit.api_secret' => 'secretsecretsecretsecretsecret123',
        ]);
    }

    private function member(string $doc, bool $staff): Member
    {
        $user = User::create([
            'name' => 'U'.$doc, 'email' => $doc.'@e.com', 'password' => 'secret',
            'document' => $doc, 'phone' => '300'.$doc, 'status' => 'active',
        ]);
        return Member::create([
            'user_id' => $user->id, 'full_name' => 'U'.$doc, 'email' => $doc.'@e.com',
            'document_number' => $doc, 'phone' => '300'.$doc,
            'access_hash' => 'tok-'.$doc, 'status' => Member::STATUS_ACTIVE,
            'is_staff' => $staff,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    public function test_staff_member_can_create_live(): void
    {
        $this->configureLiveKit();
        $staff = $this->member('1001', true);

        $this->getJson('/api/member/app-state', $this->auth($staff))
            ->assertOk()
            ->assertJsonPath('live.enabled', true)
            ->assertJsonPath('live.is_staff', true)
            ->assertJsonPath('live.can_create', true)
            ->assertJsonPath('live.can_view', true);
    }

    public function test_normal_member_cannot_create_but_can_view(): void
    {
        $this->configureLiveKit();
        $normal = $this->member('1002', false);

        $this->getJson('/api/member/app-state', $this->auth($normal))
            ->assertOk()
            ->assertJsonPath('live.is_staff', false)
            ->assertJsonPath('live.can_create', false)
            ->assertJsonPath('live.can_view', true);
    }

    public function test_livekit_disabled_does_not_crash(): void
    {
        // Sin configureLiveKit() → proveedor no configurado.
        $staff = $this->member('1003', true);

        $this->getJson('/api/member/app-state', $this->auth($staff))
            ->assertOk()
            ->assertJsonPath('live.enabled', false)
            ->assertJsonPath('live.can_create', false)
            ->assertJsonPath('live.can_view', false);
    }

    public function test_admin_grants_and_revokes_staff_access(): void
    {
        $member = $this->member('1004', false);

        $this->patchJson("/api/admin/members/{$member->id}/staff-access", ['is_staff' => true])
            ->assertOk()->assertJsonPath('data.is_staff', true);
        $this->assertTrue($member->fresh()->is_staff);

        $this->getJson("/api/admin/members/{$member->id}")
            ->assertOk()->assertJsonPath('data.is_staff', true);

        $this->patchJson("/api/admin/members/{$member->id}/staff-access", ['is_staff' => false])
            ->assertOk()->assertJsonPath('data.is_staff', false);
        $this->assertFalse($member->fresh()->is_staff);
    }
}
