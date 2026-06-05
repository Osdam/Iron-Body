<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberDeviceBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recuperación SEGURA de número desde el login ("Ya no tengo este número"):
 * solo un dispositivo CONFIABLE (vínculo previo) puede iniciar el cambio; la app
 * pone la biometría local; el OTP va al número NUEVO y solo al validarlo se
 * actualiza member.phone. Dispositivo no confiable → no se cambia (soporte).
 */
class PhoneRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $doc = '1010101010', string $phone = '3001234567'): Member
    {
        $user = User::create([
            'name' => 'Ana Prueba',
            'email' => 'ana'.$doc.'@example.com',
            'password' => 'secret',
            'document' => $doc,
            'phone' => $phone,
            'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Ana Prueba',
            'email' => 'ana'.$doc.'@example.com',
            'document_number' => $doc,
            'phone' => $phone,
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function trust(Member $m, string $deviceId): void
    {
        MemberDeviceBinding::create([
            'device_id' => $deviceId,
            'member_id' => $m->id,
            'device_name' => 'iPhone de Ana',
            'platform' => 'ios',
            'bound_at' => now(),
        ]);
    }

    public function test_trusted_device_can_start_phone_self_recovery(): void
    {
        $member = $this->member();
        $this->trust($member, 'dev-trusted');

        $res = $this->postJson('/api/member/phone-recovery/can-self-recover', [
            'document' => '1010101010',
            'device_id' => 'dev-trusted',
        ]);
        $res->assertOk()->assertJsonPath('can_self_recover', true)
            ->assertJsonPath('reason', 'trusted_device');

        $start = $this->postJson('/api/member/phone-recovery/start', [
            'document' => '1010101010',
            'device_id' => 'dev-trusted',
        ]);
        $start->assertOk()->assertJsonPath('can_self_recover', true);
        $this->assertNotEmpty($start->json('recovery_ticket'));
    }

    public function test_untrusted_device_cannot_self_recover(): void
    {
        $member = $this->member();
        // Sin vínculo de dispositivo: no es confiable.
        $res = $this->postJson('/api/member/phone-recovery/can-self-recover', [
            'document' => '1010101010',
            'device_id' => 'unknown-device',
        ]);
        $res->assertOk()->assertJsonPath('can_self_recover', false)
            ->assertJsonPath('reason', 'untrusted_device');

        // start tampoco emite ticket.
        $this->postJson('/api/member/phone-recovery/start', [
            'document' => '1010101010',
            'device_id' => 'unknown-device',
        ])->assertOk()->assertJsonPath('can_self_recover', false);

        // request con ticket inventado → rechazado.
        $this->postJson('/api/member/phone-recovery/request', [
            'recovery_ticket' => 'no-existe',
            'new_phone' => '3019998877',
            'device_id' => 'unknown-device',
        ])->assertStatus(422);
    }

    public function test_unknown_document_does_not_leak(): void
    {
        $this->postJson('/api/member/phone-recovery/can-self-recover', [
            'document' => '9999999999',
            'device_id' => 'whatever',
        ])->assertOk()->assertJsonPath('can_self_recover', false)
            ->assertJsonPath('reason', 'untrusted_device');
    }

    public function test_recovery_ticket_expires(): void
    {
        $member = $this->member();
        $this->trust($member, 'dev-trusted');

        $ticket = $this->postJson('/api/member/phone-recovery/start', [
            'document' => '1010101010', 'device_id' => 'dev-trusted',
        ])->json('recovery_ticket');

        // Forzamos expiración del ticket.
        MemberAuthChallenge::where('uuid', $ticket)->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/member/phone-recovery/request', [
            'recovery_ticket' => $ticket,
            'new_phone' => '3019998877',
            'device_id' => 'dev-trusted',
        ])->assertStatus(422);

        $this->assertSame('3001234567', $member->fresh()->phone);
    }

    public function test_recovery_ticket_is_single_use(): void
    {
        $member = $this->member();
        $this->trust($member, 'dev-trusted');

        $ticket = $this->postJson('/api/member/phone-recovery/start', [
            'document' => '1010101010', 'device_id' => 'dev-trusted',
        ])->json('recovery_ticket');

        // Primer uso: OK (dispara OTP).
        $this->postJson('/api/member/phone-recovery/request', [
            'recovery_ticket' => $ticket,
            'new_phone' => '3019998877',
            'device_id' => 'dev-trusted',
        ])->assertOk()->assertJsonPath('requires_otp', true);

        // Segundo uso del MISMO ticket: rechazado.
        $this->postJson('/api/member/phone-recovery/request', [
            'recovery_ticket' => $ticket,
            'new_phone' => '3017776655',
            'device_id' => 'dev-trusted',
        ])->assertStatus(422);
    }

    public function test_new_phone_cannot_be_duplicated(): void
    {
        $other = $this->member('2020202020', '3055556666');
        $member = $this->member('3030303030', '3001112222');
        $this->trust($member, 'dev-trusted');

        $ticket = $this->postJson('/api/member/phone-recovery/start', [
            'document' => '3030303030', 'device_id' => 'dev-trusted',
        ])->json('recovery_ticket');

        $this->postJson('/api/member/phone-recovery/request', [
            'recovery_ticket' => $ticket,
            'new_phone' => '3055556666',
            'device_id' => 'dev-trusted',
        ])->assertStatus(422);
    }

    public function test_phone_updates_only_after_otp_verified(): void
    {
        $member = $this->member();
        $this->trust($member, 'dev-trusted');

        $ticket = $this->postJson('/api/member/phone-recovery/start', [
            'document' => '1010101010', 'device_id' => 'dev-trusted',
        ])->json('recovery_ticket');

        $req = $this->postJson('/api/member/phone-recovery/request', [
            'recovery_ticket' => $ticket,
            'new_phone' => '3019998877',
            'device_id' => 'dev-trusted',
        ]);
        $req->assertOk();
        $challengeId = $req->json('challenge_id');
        $code = $req->json('dev_code');
        $this->assertNotEmpty($code);

        // Código equivocado → NO cambia el teléfono.
        $this->postJson('/api/member/phone-recovery/verify', [
            'challenge_id' => $challengeId,
            'code' => '000000',
            'device_id' => 'dev-trusted',
        ])->assertStatus(422);
        $this->assertSame('3001234567', $member->fresh()->phone);

        // Código correcto → actualiza el teléfono (member y user).
        $this->postJson('/api/member/phone-recovery/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
            'device_id' => 'dev-trusted',
        ])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('3019998877', $member->fresh()->phone);
        $this->assertSame('3019998877', $member->fresh()->user->phone);
    }

    public function test_verify_requires_trusted_device(): void
    {
        $member = $this->member();
        $this->trust($member, 'dev-trusted');

        $ticket = $this->postJson('/api/member/phone-recovery/start', [
            'document' => '1010101010', 'device_id' => 'dev-trusted',
        ])->json('recovery_ticket');

        $challengeId = $this->postJson('/api/member/phone-recovery/request', [
            'recovery_ticket' => $ticket,
            'new_phone' => '3019998877',
            'device_id' => 'dev-trusted',
        ])->json('challenge_id');

        // Verificar desde un dispositivo NO confiable → rechazado.
        $this->postJson('/api/member/phone-recovery/verify', [
            'challenge_id' => $challengeId,
            'code' => '123456',
            'device_id' => 'otro-device',
        ])->assertStatus(404);
        $this->assertSame('3001234567', $member->fresh()->phone);
    }
}
