<?php

namespace Tests\Feature;

use App\Models\LiveStream;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story Live (Bloque 5): solo staff crea/transmite, miembros miran, token
 * server-side y degradación segura sin credenciales del proveedor.
 */
class LiveStreamTest extends TestCase
{
    use RefreshDatabase;

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

    private function configureLiveKit(): void
    {
        config([
            'live.enabled' => true,
            'live.livekit.url' => 'wss://test.livekit.cloud',
            'live.livekit.api_key' => 'APIkey123',
            'live.livekit.api_secret' => 'secretsecretsecretsecretsecret123',
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    public function test_feature_unavailable_without_provider(): void
    {
        $staff = $this->member('1', true);

        $this->postJson('/api/member/live/create', ['title' => 'X'], $this->auth($staff))
            ->assertStatus(503)
            ->assertJsonPath('code', 'live_unavailable');
    }

    public function test_non_staff_cannot_create(): void
    {
        $this->configureLiveKit();
        $user = $this->member('2', false);

        $this->postJson('/api/member/live/create', ['title' => 'X'], $this->auth($user))
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_staff_creates_starts_and_gets_publish_token(): void
    {
        $this->configureLiveKit();
        $staff = $this->member('3', true);

        $create = $this->postJson('/api/member/live/create',
            ['title' => 'Clase en vivo'], $this->auth($staff));
        $create->assertCreated()->assertJsonPath('data.status', 'scheduled');
        $id = $create->json('data.id');

        $this->postJson("/api/member/live/{$id}/start", [], $this->auth($staff))
            ->assertOk()->assertJsonPath('data.status', 'live');

        $this->getJson('/api/member/live/active', $this->auth($staff))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('enabled', true);

        $token = $this->postJson("/api/member/live/{$id}/join-token", [], $this->auth($staff));
        $token->assertOk()->assertJsonPath('data.can_publish', true);
        $this->assertNotEmpty($token->json('data.token'));
        $this->assertCount(3, explode('.', $token->json('data.token'))); // JWT
        $this->assertSame('wss://test.livekit.cloud', $token->json('data.url'));
    }

    public function test_viewer_gets_subscribe_only_token(): void
    {
        $this->configureLiveKit();
        $staff = $this->member('4', true);
        $viewer = $this->member('5', false);

        $live = LiveStream::create([
            'title' => 'En vivo', 'host_member_id' => $staff->id,
            'status' => LiveStream::STATUS_LIVE, 'started_at' => now(),
        ]);

        $this->postJson("/api/member/live/{$live->id}/join-token", [], $this->auth($viewer))
            ->assertOk()->assertJsonPath('data.can_publish', false);
    }

    public function test_admin_can_end_live(): void
    {
        $staff = $this->member('6', true);
        $live = LiveStream::create([
            'title' => 'En vivo', 'host_member_id' => $staff->id,
            'status' => LiveStream::STATUS_LIVE, 'started_at' => now(),
        ]);

        $this->postJson("/api/admin/lives/{$live->id}/end")
            ->assertOk()->assertJsonPath('data.status', 'ended');
    }
}
