<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El teléfono es dato VERIFICADO (OTP/2FA): no se cambia desde la edición normal
 * de perfil, solo por el flujo seguro "Cambiar número".
 */
class ProfilePhoneProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Member
    {
        $user = User::create([
            'name' => 'Ana', 'email' => 'ana@e.com', 'password' => 'secret',
            'document' => '1010', 'phone' => '+573150000000', 'status' => 'active',
        ]);
        return Member::create([
            'user_id' => $user->id, 'full_name' => 'Ana', 'email' => 'ana@e.com',
            'document_number' => '1010', 'phone' => '+573150000000',
            'access_hash' => 'tok-1010', 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    public function test_changing_phone_from_profile_is_blocked(): void
    {
        $m = $this->member();

        $this->patchJson('/api/member/profile',
            ['full_name' => 'Ana Nueva', 'phone' => '+573150000999'], $this->auth($m))
            ->assertStatus(422);

        // El teléfono NO cambió.
        $this->assertSame('+573150000000', $m->fresh()->phone);
    }

    public function test_same_phone_does_not_break_update(): void
    {
        $m = $this->member();

        $this->patchJson('/api/member/profile',
            ['full_name' => 'Ana Nueva', 'phone' => '+573150000000'], $this->auth($m))
            ->assertOk();

        $this->assertSame('Ana Nueva', $m->fresh()->full_name);
    }

    public function test_update_without_phone_works(): void
    {
        $m = $this->member();

        $this->patchJson('/api/member/profile', ['goal' => 'Fuerza'], $this->auth($m))
            ->assertOk();

        $this->assertSame('+573150000000', $m->fresh()->phone);
    }
}
