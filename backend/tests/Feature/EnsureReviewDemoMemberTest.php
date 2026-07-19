<?php

namespace Tests\Feature;

use App\Models\ContractTemplate;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\Plan;
use App\Models\User;
use App\Services\Contracts\MemberContractService;
use App\Support\MemberPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * La cuenta demo de App Review debe quedar lista para entrar DIRECTO al Home:
 * activa, con membresía vigente, con módulos desbloqueados y sin términos/firma
 * pendientes. Nada de esto debe filtrarse a usuarios reales.
 */
class EnsureReviewDemoMemberTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO_DOC = '9999999999';

    private function runCommand(): void
    {
        config(['services.app_review_demo.document' => self::DEMO_DOC]);
        Artisan::call('app:ensure-review-demo-member');
    }

    public function test_command_creates_active_member_with_active_membership(): void
    {
        $this->runCommand();

        $member = Member::where('document_number', self::DEMO_DOC)->first();
        $this->assertNotNull($member);
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);

        $user = $member->user;
        $this->assertNotNull($user);
        $this->assertNotEmpty($user->plan);
        $this->assertTrue(now()->lt($user->membershipEndDate), 'la membresía debe estar vigente');

        // El plan del usuario existe y desbloquea los módulos.
        $this->assertNotNull(Plan::where('name', $user->plan)->first());
    }

    public function test_command_marks_terms_and_contract_accepted(): void
    {
        $this->runCommand();
        $member = Member::where('document_number', self::DEMO_DOC)->first();

        $consent = $member->legalConsent;
        $this->assertNotNull($consent);
        $this->assertTrue((bool) $consent->terms_and_conditions);
        $this->assertTrue((bool) $consent->data_processing);
        $this->assertTrue((bool) $consent->service_contract);

        // Contrato firmado del tipo recomendado ⇒ requires_contract=false.
        $status = app(MemberContractService::class)->statusFor($member);
        $this->assertFalse($status['requires_contract']);

        $signed = $member->contracts()->where('status', MemberContract::STATUS_SIGNED)->first();
        $this->assertNotNull($signed);
    }

    public function test_command_unlocks_all_modules(): void
    {
        $this->runCommand();
        $member = Member::where('document_number', self::DEMO_DOC)->first();

        $features = MemberPayload::featuresFor($member->user);
        foreach (['iron_ia', 'ranking', 'classes', 'progress', 'nutrition', 'custom_routines', 'workouts'] as $key) {
            $this->assertTrue((bool) ($features[$key] ?? false), "módulo bloqueado: {$key}");
        }
    }

    public function test_account_status_endpoint_reports_full_access_for_demo(): void
    {
        $this->runCommand();
        $member = Member::where('document_number', self::DEMO_DOC)->first();

        $this->withHeader('Authorization', 'Bearer '.$member->access_hash)
            ->getJson('/api/member/account/status')
            ->assertOk()
            ->assertJsonPath('membership_active', true)
            ->assertJsonPath('can_access_app', true);
    }

    public function test_command_is_idempotent(): void
    {
        $this->runCommand();
        $this->runCommand();

        $this->assertSame(1, Member::where('document_number', self::DEMO_DOC)->count());
        $member = Member::where('document_number', self::DEMO_DOC)->first();
        // No duplica el contrato firmado.
        $this->assertSame(1, $member->contracts()->where('status', MemberContract::STATUS_SIGNED)->count());
    }

    public function test_command_only_touches_the_demo_document(): void
    {
        // Usuario real con contrato PENDIENTE antes de correr el comando.
        $realUser = User::create([
            'name' => 'Real', 'email' => 'real@example.com', 'password' => 'secret',
            'document' => '1004301550', 'phone' => '3215542105', 'status' => 'active',
        ]);
        $realMember = Member::create([
            'user_id' => $realUser->id, 'full_name' => 'Real',
            'document_number' => '1004301550', 'phone' => '3215542105',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $this->runCommand();

        // El usuario real NO recibe consentimiento ni contrato del bypass demo.
        $realMember->refresh();
        $this->assertNull($realMember->legalConsent);
        $this->assertSame(0, $realMember->contracts()->count());
        // Y sigue requiriendo contrato (sin bypass legal).
        $this->assertTrue(app(MemberContractService::class)->statusFor($realMember)['requires_contract']);
    }
}
