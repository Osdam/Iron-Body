<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_login_with_normalized_document_number(): void
    {
        $user = User::create([
            'name' => 'Oscar Mancipe',
            'email' => 'oscar@example.com',
            'password' => 'secret',
            'document' => '1004301550',
            'phone' => '3215542105',
            'status' => 'active',
            'plan' => 'Mensual',
            'membership_end_date' => '2026-06-18',
        ]);

        $member = Member::create([
            'user_id' => $user->id,
            'full_name' => 'Oscar Mancipe',
            'email' => 'oscar@example.com',
            'document_number' => '1004301550',
            'phone' => '3215542105',
            'goal' => 'Bienestar general',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/api/members/login', [
            'document_number' => '1.004 301-550',
        ]);

        // El documento con separadores se normaliza y encuentra al miembro.
        // Como tiene teléfono, el login emite el reto de verificación (OTP/2FA)
        // en lugar de una sesión directa: aún no hay token ni payload de miembro.
        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.token', null)
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonPath('data.channel', 'sms');
        $this->assertNotNull($response->json('data.challenge_id'));
    }

    public function test_member_login_returns_404_when_document_does_not_exist(): void
    {
        $this->postJson('/api/members/login', [
            'document_number' => '9999999999',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Documento no encontrado.');
    }
}
