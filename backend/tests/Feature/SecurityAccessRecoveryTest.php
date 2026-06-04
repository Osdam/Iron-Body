<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\SupportSecurityReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recuperación de acceso (Bloque 2): reporte de soporte público desde el login
 * (Fase 9) y cambio de número con OTP al número nuevo (Fase 5).
 */
class SecurityAccessRecoveryTest extends TestCase
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

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    public function test_public_support_report_is_created_and_links_member(): void
    {
        $member = $this->member();

        $res = $this->postJson('/api/security/support-report', [
            'report_type' => SupportSecurityReport::TYPE_STOLEN_DEVICE,
            'document_number' => '1010101010',
            'name' => 'Ana Prueba',
            'phone' => '3009998877',
            'description' => 'Me robaron el celular.',
        ]);

        $res->assertOk()->assertJsonPath('ok', true);
        // La respuesta es genérica: no revela si la cuenta existe.
        $this->assertStringNotContainsString('Ana', $res->json('message'));

        $report = SupportSecurityReport::latest('id')->first();
        $this->assertNotNull($report);
        $this->assertSame(SupportSecurityReport::TYPE_STOLEN_DEVICE, $report->report_type);
        $this->assertSame($member->id, $report->member_id);
        $this->assertSame(SupportSecurityReport::STATUS_PENDING, $report->status);
    }

    public function test_support_report_rejects_invalid_type(): void
    {
        $this->postJson('/api/security/support-report', [
            'report_type' => 'not_a_type',
        ])->assertStatus(422);
    }

    public function test_support_report_works_for_unknown_document(): void
    {
        // No existe ese documento: igual se crea el ticket (sin member) y la
        // respuesta es idéntica (no se filtra existencia).
        $this->postJson('/api/security/support-report', [
            'report_type' => SupportSecurityReport::TYPE_LOST_ACCESS,
            'document_number' => '9999999999',
        ])->assertOk();

        $report = SupportSecurityReport::latest('id')->first();
        $this->assertNull($report->member_id);
    }

    public function test_phone_change_requires_otp_on_new_number(): void
    {
        $member = $this->member();

        $req = $this->postJson('/api/member/security/phone-change/request', [
            'new_phone' => '3019998877',
        ], $this->auth($member));
        $req->assertOk()->assertJsonPath('requires_otp', true);
        $challengeId = $req->json('challenge_id');
        $code = $req->json('dev_code');
        $this->assertNotEmpty($code);

        // Código equivocado → no cambia el teléfono.
        $this->postJson('/api/member/security/phone-change/verify', [
            'challenge_id' => $challengeId,
            'code' => '000000',
        ], $this->auth($member))->assertStatus(422);
        $this->assertSame('3001234567', $member->fresh()->phone);

        // Código correcto → actualiza el teléfono.
        $this->postJson('/api/member/security/phone-change/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ], $this->auth($member))->assertOk();
        $this->assertSame('3019998877', $member->fresh()->phone);
    }

    public function test_phone_change_rejects_number_used_by_other_member(): void
    {
        $other = $this->member('2020202020', '3055556666');
        $member = $this->member('3030303030', '3001112222');

        $this->postJson('/api/member/security/phone-change/request', [
            'new_phone' => '3055556666',
        ], $this->auth($member))->assertStatus(422);
    }
}
