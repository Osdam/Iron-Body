<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use App\Services\DeviceSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión de BACK-008: las sesiones de dispositivo no expiraban nunca
 * (scopeActive solo miraba revoked_at). Ahora hay un TTL deslizante por
 * inactividad: una sesión sin actividad más allá de `otp.session.ttl_days`
 * deja de resolver y el bearer devuelve 401.
 */
class DeviceSessionTtlTest extends TestCase
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
            'document_number' => '1010101010',
            'phone' => '3001234567',
            // access_hash distinto del session token: así el 401 prueba la
            // expiración de la sesión y no un fallback silencioso al hash.
            'access_hash' => 'permanent-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    public function test_idle_session_beyond_ttl_is_rejected(): void
    {
        config(['otp.session.ttl_days' => 30]);
        $member = $this->member();

        $issued = app(DeviceSessionService::class)->issueSession($member, ['device_id' => 'dev-1']);
        $token = $issued['token'];

        // Envejecer la sesión más allá del TTL (sin actividad reciente).
        $issued['session']->forceFill(['last_seen_at' => now()->subDays(31)])->saveQuietly();

        $this->getJson('/api/member/account/status', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(401);
    }

    public function test_recently_active_session_is_accepted(): void
    {
        config(['otp.session.ttl_days' => 30]);
        $member = $this->member();

        $issued = app(DeviceSessionService::class)->issueSession($member, ['device_id' => 'dev-1']);
        $token = $issued['token'];

        // Actividad de hace 5 días: dentro del TTL → sigue válida.
        $issued['session']->forceFill(['last_seen_at' => now()->subDays(5)])->saveQuietly();

        $this->getJson('/api/member/account/status', ['Authorization' => 'Bearer '.$token])
            ->assertOk();
    }
}
