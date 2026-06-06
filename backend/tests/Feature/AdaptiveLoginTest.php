<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberDeviceBinding;
use App\Models\MemberSecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Login adaptativo por riesgo (Bloque 3b). Con el flag ENCENDIDO:
 *  - dispositivo NO confiable → OTP (+ cara).
 *  - dispositivo confiable + riesgo bajo → desbloqueo local (ticket).
 *  - prefer_otp fuerza al menos OTP en dispositivo confiable.
 */
class AdaptiveLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.adaptive_login' => true]);
    }

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
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    public function test_untrusted_device_requires_otp(): void
    {
        $this->member();

        $r = $this->postJson('/api/members/login', [
            'document_number' => '1010101010',
            'device_id' => 'device-nuevo',
        ]);

        $r->assertOk()->assertJsonPath('data.requires_otp', true);
        $this->assertNull($r->json('data.requires_local_unlock'));
    }

    public function test_trusted_low_risk_device_gets_local_unlock_and_redeems_ticket(): void
    {
        $member = $this->member();
        MemberDeviceBinding::create([
            'device_id' => 'device-confiable',
            'member_id' => $member->id,
            'device_name' => 'iPhone de Ana',
            'platform' => 'ios',
            'bound_at' => now(),
            'last_otp_reauth_at' => now(), // revalidado hace poco → dentro de ventana
        ]);

        $r = $this->postJson('/api/members/login', [
            'document_number' => '1010101010',
            'device_id' => 'device-confiable',
        ]);
        $r->assertOk()
            ->assertJsonPath('data.requires_otp', false)
            ->assertJsonPath('data.requires_local_unlock', true);
        $ticket = $r->json('data.unlock_ticket');
        $this->assertNotEmpty($ticket);

        // Canje del ticket tras la biometría local → emite sesión sin SMS.
        $r2 = $this->postJson('/api/members/login/trusted-unlock', [
            'document_number' => '1010101010',
            'ticket' => $ticket,
            'device_id' => 'device-confiable',
        ]);
        $r2->assertOk()->assertJsonPath('data.requires_otp', false);
        $this->assertNotEmpty($r2->json('data.token'));

        // El ticket es de un solo uso.
        $this->postJson('/api/members/login/trusted-unlock', [
            'document_number' => '1010101010',
            'ticket' => $ticket,
            'device_id' => 'device-confiable',
        ])->assertStatus(410);
    }

    public function test_recent_new_device_event_still_allows_local_unlock(): void
    {
        // Un equipo recién vinculado SIEMPRE tiene un evento new_device reciente.
        // Esa señal benigna NO debe expulsarlo del desbloqueo local (causa del
        // "OTP repetido cada login" durante toda la ventana de riesgo).
        $member = $this->member();
        MemberDeviceBinding::create([
            'device_id' => 'device-confiable',
            'member_id' => $member->id,
            'bound_at' => now(),
            'last_otp_reauth_at' => now(),
        ]);
        MemberSecurityEvent::create([
            'member_id'  => $member->id,
            'type'       => MemberSecurityEvent::TYPE_NEW_DEVICE,
            'created_at' => now(),
        ]);

        $r = $this->postJson('/api/members/login', [
            'document_number' => '1010101010',
            'device_id' => 'device-confiable',
        ]);

        $r->assertOk()
            ->assertJsonPath('data.requires_otp', false)
            ->assertJsonPath('data.requires_local_unlock', true);
    }

    public function test_recent_adversarial_event_forces_otp_on_trusted_device(): void
    {
        // Una señal ADVERSARIAL reciente (OTP fallido) sí debe forzar al menos OTP
        // aunque el equipo sea confiable (step-up real, no se relaja).
        $member = $this->member();
        MemberDeviceBinding::create([
            'device_id' => 'device-confiable',
            'member_id' => $member->id,
            'bound_at' => now(),
            'last_otp_reauth_at' => now(),
        ]);
        MemberSecurityEvent::create([
            'member_id'  => $member->id,
            'type'       => MemberSecurityEvent::TYPE_LOGIN_FAILED,
            'created_at' => now(),
        ]);

        $r = $this->postJson('/api/members/login', [
            'document_number' => '1010101010',
            'device_id' => 'device-confiable',
        ]);

        $r->assertOk()->assertJsonPath('data.requires_otp', true);
        $this->assertNull($r->json('data.requires_local_unlock'));
    }

    public function test_prefer_otp_forces_otp_on_trusted_device(): void
    {
        $member = $this->member();
        MemberDeviceBinding::create([
            'device_id' => 'device-confiable',
            'member_id' => $member->id,
            'bound_at' => now(),
            'last_otp_reauth_at' => now(), // elegible para local, pero prefer_otp manda
        ]);

        $r = $this->postJson('/api/members/login', [
            'document_number' => '1010101010',
            'device_id' => 'device-confiable',
            'prefer_otp' => true,
        ]);

        $r->assertOk()
            ->assertJsonPath('data.requires_otp', true);
        $this->assertNull($r->json('data.requires_local_unlock'));
    }

    public function test_trusted_device_overdue_reauth_requires_otp(): void
    {
        $member = $this->member();
        MemberDeviceBinding::create([
            'device_id' => 'device-confiable',
            'member_id' => $member->id,
            'bound_at' => now()->subDays(60),
            'last_otp_reauth_at' => now()->subDays(31), // supera trusted_reauth_days
        ]);

        $r = $this->postJson('/api/members/login', [
            'document_number' => '1010101010',
            'device_id' => 'device-confiable',
        ]);

        $r->assertOk()->assertJsonPath('data.requires_otp', true);
        $this->assertNull($r->json('data.requires_local_unlock'));
    }

    public function test_trusted_device_without_reauth_mark_requires_otp(): void
    {
        $member = $this->member();
        MemberDeviceBinding::create([
            'device_id' => 'device-confiable',
            'member_id' => $member->id,
            'bound_at' => now(),
            // last_otp_reauth_at null → se considera vencido (revalidar una vez).
        ]);

        $r = $this->postJson('/api/members/login', [
            'document_number' => '1010101010',
            'device_id' => 'device-confiable',
        ]);

        $r->assertOk()->assertJsonPath('data.requires_otp', true);
        $this->assertNull($r->json('data.requires_local_unlock'));
    }

    public function test_otp_verify_refreshes_reauth_window(): void
    {
        $member = $this->member();
        $binding = MemberDeviceBinding::create([
            'device_id' => 'device-confiable',
            'member_id' => $member->id,
            'bound_at' => now()->subDays(60),
            'last_otp_reauth_at' => now()->subDays(40), // vencido
        ]);

        // Vencido → pide OTP.
        $login = $this->postJson('/api/members/login', [
            'document_number' => '1010101010',
            'device_id' => 'device-confiable',
        ]);
        $login->assertOk()->assertJsonPath('data.requires_otp', true);
        $challengeId = $login->json('data.challenge_id');
        $code = $login->json('data.dev_code');
        $this->assertNotEmpty($code);

        // Verifica el OTP → emite sesión y refresca la ventana de revalidación.
        $this->postJson('/api/members/login/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
            'device_id' => 'device-confiable',
        ])->assertOk()->assertJsonPath('data.requires_otp', false);

        $binding->refresh();
        $this->assertNotNull($binding->last_otp_reauth_at);
        $this->assertTrue($binding->last_otp_reauth_at->gt(now()->subMinute()));
    }

    public function test_trusted_unlock_disabled_when_flag_off(): void
    {
        config(['security.adaptive_login' => false]);
        $this->member();

        $this->postJson('/api/members/login/trusted-unlock', [
            'document_number' => '1010101010',
            'ticket' => 'whatever',
            'device_id' => 'device-confiable',
        ])->assertStatus(404);
    }
}
