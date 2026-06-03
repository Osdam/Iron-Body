<?php

namespace Tests\Feature;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_reuses_pending_member_with_same_normalized_document(): void
    {
        $member = Member::create([
            'full_name' => 'Oscar Mancipe',
            'email' => 'old@example.com',
            'document_number' => '1004301550',
            'status' => Member::STATUS_PENDING_REGISTRATION,
        ]);

        $response = $this->postJson('/api/members/register', [
            'full_name' => 'Oscar Daniel Mancipe Molina',
            'email' => 'new@example.com',
            'document_number' => '1.004 301-550',
            'phone' => '3215542105',
        ]);

        // Un registro pendiente con el mismo documento normalizado se REANUDA
        // (idempotente), no se duplica ni se rechaza.
        $response
            ->assertOk()
            ->assertJsonPath('status', 'resumed')
            ->assertJsonPath('member_id', $member->id)
            ->assertJsonPath('registration_status', Member::STATUS_PENDING_REGISTRATION);

        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseHas('members', [
            'id' => $member->id,
            'document_number' => '1004301550',
            'email' => 'new@example.com',
        ]);
    }

    public function test_register_rejects_active_member_with_clear_duplicate_error(): void
    {
        $member = Member::create([
            'full_name' => 'Active Member',
            'document_number' => '1004301550',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/api/members/register', [
            'full_name' => 'Someone Else',
            'document_number' => '1004301550',
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('status', 'duplicate_document')
            ->assertJsonPath('member_id', $member->id)
            ->assertJsonPath('message', 'Ya existe una cuenta registrada con este documento o correo.');
    }
}
