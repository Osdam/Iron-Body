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

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.token', null)
            ->assertJsonPath('data.member.id', $member->id)
            ->assertJsonPath('data.member.document_number', '1004301550')
            ->assertJsonPath('data.member.plan_name', 'Mensual')
            ->assertJsonPath('data.member.membership_expiry', '2026-06-18')
            ->assertJsonPath('data.member.status', Member::STATUS_ACTIVE);
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
