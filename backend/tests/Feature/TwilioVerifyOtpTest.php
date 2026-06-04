<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\User;
use App\Services\OtpService;
use App\Services\Sms\TwilioVerifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Twilio Verify (integración real, sin SMS en tests). Twilio genera/envía/valida
 * el código; el backend orquesta start/check. Se valida con Http::fake.
 */
class TwilioVerifyOtpTest extends TestCase
{
    use RefreshDatabase;

    private function enableVerify(): void
    {
        config([
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'ACxxxxxxxxxxxxxxxx',
            'otp.twilio.token' => 'authtoken-secreto',
            'otp.twilio.verify_service_sid' => 'VAxxxxxxxxxxxxxxxx',
            'otp.twilio.verify_base' => 'https://verify.twilio.com',
            'otp.default_country_code' => '57',
        ]);
    }

    private function member(string $phone): Member
    {
        $user = User::create([
            'name' => 'Ana', 'email' => 'ana@e.com', 'password' => 'secret',
            'document' => '1010', 'phone' => $phone, 'status' => 'active',
        ]);
        return Member::create([
            'user_id' => $user->id, 'full_name' => 'Ana', 'email' => 'ana@e.com',
            'document_number' => '1010', 'phone' => $phone,
            'access_hash' => 'tok-1010', 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    public function test_to_e164_normalizes_colombia(): void
    {
        $svc = app(TwilioVerifyService::class);
        $this->assertSame('+573001234567', $svc->toE164('3001234567'));
        $this->assertSame('+573001234567', $svc->toE164('+57 300 123 4567'));
        $this->assertSame('+573001234567', $svc->toE164('573001234567'));
        $this->assertNull($svc->toE164(null));
    }

    public function test_is_active_requires_full_credentials(): void
    {
        $this->enableVerify();
        $this->assertTrue(app(TwilioVerifyService::class)->isActive());

        config(['otp.twilio.verify_service_sid' => null]);
        $this->assertFalse(app(TwilioVerifyService::class)->isActive());
    }

    public function test_start_posts_to_twilio_verify(): void
    {
        $this->enableVerify();
        Http::fake(['*Verifications' => Http::response(['status' => 'pending'], 201)]);

        $this->assertTrue(app(TwilioVerifyService::class)->start('3001234567'));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/Verifications')
            && $r['To'] === '+573001234567'
            && $r['Channel'] === 'sms');
    }

    public function test_check_approved(): void
    {
        $this->enableVerify();
        Http::fake(['*VerificationCheck' => Http::response(['status' => 'approved'], 200)]);
        $this->assertTrue(app(TwilioVerifyService::class)->check('3001234567', '123456'));
    }

    public function test_check_pending_is_rejected(): void
    {
        $this->enableVerify();
        Http::fake(['*VerificationCheck' => Http::response(['status' => 'pending'], 200)]);
        $this->assertFalse(app(TwilioVerifyService::class)->check('3001234567', '000000'));
    }

    public function test_otp_service_login_uses_verify(): void
    {
        $this->enableVerify();
        Http::fake([
            '*VerificationCheck' => Http::response(['status' => 'approved'], 200),
            '*Verifications' => Http::response(['status' => 'pending'], 201),
        ]);

        $member = $this->member('3001234567');
        $otp = app(OtpService::class);

        $res = $otp->startChallenge($member, []);
        $this->assertTrue($res['sent']);

        // El código que mande el usuario lo valida Twilio (faked: approved).
        $out = $otp->verify($res['challenge']->uuid, '999999', []);
        $this->assertNotNull($out['member']);
        $this->assertSame(MemberAuthChallenge::STATUS_VERIFIED, $out['challenge']->fresh()->status);
    }

    public function test_otp_service_rejects_when_twilio_not_approved(): void
    {
        $this->enableVerify();
        Http::fake([
            '*VerificationCheck' => Http::response(['status' => 'pending'], 200),
            '*Verifications' => Http::response(['status' => 'pending'], 201),
        ]);

        $member = $this->member('3001234567');
        $otp = app(OtpService::class);
        $res = $otp->startChallenge($member, []);

        $this->expectException(\App\Exceptions\OtpException::class);
        $otp->verify($res['challenge']->uuid, '000000', []);
    }
}
