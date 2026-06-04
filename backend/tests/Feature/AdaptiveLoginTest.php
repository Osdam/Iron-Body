<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberDeviceBinding;
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

    public function test_prefer_otp_forces_otp_on_trusted_device(): void
    {
        $member = $this->member();
        MemberDeviceBinding::create([
            'device_id' => 'device-confiable',
            'member_id' => $member->id,
            'bound_at' => now(),
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
