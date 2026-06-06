<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sesión post-registro: tras crear la cuenta, el bearer del miembro es su
 * `access_hash` (aún no hay session_token de dispositivo). Debe autenticar los
 * módulos del miembro EXACTAMENTE como una sesión de login. Así Stories no
 * muestra "Inicia sesión para publicar un story" justo después del registro.
 */
class PostRegistrationSessionTest extends TestCase
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
            'email' => 'ana@example.com',
            'document_number' => '1010101010',
            'phone' => '3001234567',
            'access_hash' => 'reg-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    public function test_access_hash_authenticates_member_stories_after_registration(): void
    {
        $member = $this->member();

        // Mismo bearer que deja el registro (access_hash, sin device session).
        $this->getJson('/api/app/stories', [
            'Authorization' => 'Bearer '.$member->access_hash,
        ])->assertOk();
    }

    public function test_stories_require_a_valid_session(): void
    {
        // Sin bearer → no autenticado (Stories SÍ exige sesión; el fix no la salta).
        $this->getJson('/api/app/stories')->assertStatus(401);
    }

    public function test_invalid_bearer_is_rejected(): void
    {
        $this->getJson('/api/app/stories', [
            'Authorization' => 'Bearer not-a-real-token',
        ])->assertStatus(401);
    }
}
