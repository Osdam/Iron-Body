<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceso demo de App Review: OTP fijo válido SÓLO para el documento demo y SÓLO
 * con el flag activo. Cubre además que /api/members/login ya no queda detrás del
 * token de registro y que el login/OTP normal sigue intacto.
 */
class AppReviewDemoLoginTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO_DOC = '9999999999';
    private const DEMO_OTP = '123456';

    private function enableDemo(): void
    {
        config([
            'services.app_review_demo.enabled'  => true,
            'services.app_review_demo.document' => self::DEMO_DOC,
            'services.app_review_demo.otp'      => self::DEMO_OTP,
        ]);
    }

    private function makeMember(string $document, string $phone): Member
    {
        $user = User::create([
            'name'                => 'Tester',
            'email'               => "u{$document}@example.com",
            'password'            => 'secret',
            'document'            => $document,
            'phone'               => $phone,
            'status'              => 'active',
            'plan'                => 'Mensual',
            'membership_end_date' => now()->addYear()->toDateString(),
        ]);

        return Member::create([
            'user_id'         => $user->id,
            'full_name'       => 'Tester',
            'email'           => "u{$document}@example.com",
            'document_number' => $document,
            'phone'           => $phone,
            'status'          => Member::STATUS_ACTIVE,
        ]);
    }

    public function test_demo_otp_grants_session_for_demo_document_when_enabled(): void
    {
        $this->enableDemo();
        $this->makeMember(self::DEMO_DOC, '3000000000');

        $login = $this->postJson('/api/members/login', ['document_number' => self::DEMO_DOC]);
        $login->assertOk()
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonPath('data.token', null);
        // El acceso demo NUNCA expone el código en la respuesta.
        $this->assertNull($login->json('data.dev_code'));
        $challengeId = $login->json('data.challenge_id');
        $this->assertNotNull($challengeId);

        $verify = $this->postJson('/api/members/login/verify', [
            'challenge_id' => $challengeId,
            'code'         => self::DEMO_OTP,
        ]);
        $verify->assertOk()->assertJsonPath('ok', true);
        $this->assertNotNull($verify->json('data.token'));
    }

    public function test_demo_otp_does_not_work_for_a_real_user(): void
    {
        $this->enableDemo();
        // Usuario real distinto al demo.
        $this->makeMember('1004301550', '3215542105');

        $login = $this->postJson('/api/members/login', ['document_number' => '1004301550']);
        $login->assertOk()->assertJsonPath('data.requires_otp', true);
        $challengeId = $login->json('data.challenge_id');

        // El OTP fijo 123456 NO debe emitir sesión para un usuario real.
        $verify = $this->postJson('/api/members/login/verify', [
            'challenge_id' => $challengeId,
            'code'         => self::DEMO_OTP,
        ]);
        $this->assertNotEquals(200, $verify->status());
        $this->assertNull($verify->json('data.token'));
    }

    public function test_demo_otp_does_not_work_when_disabled(): void
    {
        config([
            'services.app_review_demo.enabled'  => false,
            'services.app_review_demo.document' => self::DEMO_DOC,
            'services.app_review_demo.otp'      => self::DEMO_OTP,
        ]);
        $this->makeMember(self::DEMO_DOC, '3000000000');

        $login = $this->postJson('/api/members/login', ['document_number' => self::DEMO_DOC]);
        $login->assertOk()->assertJsonPath('data.requires_otp', true);
        $challengeId = $login->json('data.challenge_id');

        // Con el flag apagado el OTP fijo no aplica ni para el documento demo.
        $verify = $this->postJson('/api/members/login/verify', [
            'challenge_id' => $challengeId,
            'code'         => self::DEMO_OTP,
        ]);
        $this->assertNotEquals(200, $verify->status());
        $this->assertNull($verify->json('data.token'));
    }

    public function test_login_is_not_blocked_by_registration_token(): void
    {
        // Aunque el token de registro esté configurado, el login público no debe
        // exigirlo (antes quedaba atrapado en ese grupo → 401/503).
        config(['services.member_registration.token' => 'super-secret-token']);
        $this->makeMember('1004301550', '3215542105');

        $this->postJson('/api/members/login', ['document_number' => '1004301550'])
            ->assertOk()
            ->assertJsonPath('data.requires_otp', true);
    }

    public function test_normal_login_still_works_with_real_otp_code(): void
    {
        $this->makeMember('1004301550', '3215542105');

        $login = $this->postJson('/api/members/login', ['document_number' => '1004301550']);
        $login->assertOk()->assertJsonPath('data.requires_otp', true);
        // Driver dev: el código real viaja en dev_code.
        $code = $login->json('data.dev_code');
        $this->assertNotNull($code);

        $this->postJson('/api/members/login/verify', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code'         => $code,
        ])->assertOk()->assertJsonPath('ok', true);
    }
}
