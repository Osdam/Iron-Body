<?php

namespace Tests\Feature;

use App\Models\ContractTemplate;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\Plan;
use App\Models\User;
use App\Services\Contracts\MemberContractService;
use App\Services\IronAiMembershipAccessService;
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

    public function test_command_unlocks_all_iron_ai_capabilities(): void
    {
        $this->runCommand();
        $member = Member::where('document_number', self::DEMO_DOC)->first();

        $svc = app(IronAiMembershipAccessService::class);
        $membership = $svc->getCurrentMembership($member, $member->user);
        $this->assertTrue($membership['active']);

        $caps = $svc->getAiCapabilities($membership);
        foreach ([
            'ai_enabled',
            'ai_chat_enabled',
            'ai_voice_chat_enabled',
            'ai_realtime_voice_enabled',
            'ai_image_analysis_enabled',
            'ai_file_upload_enabled',
        ] as $flag) {
            $this->assertTrue((bool) ($caps[$flag] ?? false), "IA bloqueada: {$flag}");
        }
        // Sin topes que fuercen "actualiza tu plan".
        $this->assertNull($caps['monthly_messages_limit']);
        $this->assertNull($caps['daily_messages_limit']);
    }

    public function test_iron_ai_access_endpoint_reports_full_access_for_demo(): void
    {
        $this->runCommand();
        $member = Member::where('document_number', self::DEMO_DOC)->first();

        $this->withHeader('Authorization', 'Bearer '.$member->access_hash)
            ->getJson('/api/iron-ai/access')
            ->assertOk()
            ->assertJsonPath('has_active_membership', true)
            ->assertJsonPath('can_use_chat', true)
            ->assertJsonPath('upgrade_required', false)
            ->assertJsonPath('voice_chat_enabled', true)
            ->assertJsonPath('realtime_voice_enabled', true)
            ->assertJsonPath('image_analysis_enabled', true)
            ->assertJsonPath('file_upload_enabled', true);
    }

    public function test_real_user_does_not_get_demo_ai_capabilities(): void
    {
        // Plan real SIN capacidades premium (no hay fila membership_ai_capabilities,
        // el resolver cae al default/free → sin voz/realtime/imagen).
        $realUser = User::create([
            'name' => 'Real', 'email' => 'realai@example.com', 'password' => 'secret',
            'document' => '1002003004', 'phone' => '3011112222', 'status' => 'active',
            'plan' => 'Plan Real Basico', 'membership_end_date' => now()->addYear()->toDateString(),
        ]);
        $realMember = Member::create([
            'user_id' => $realUser->id, 'full_name' => 'Real',
            'document_number' => '1002003004', 'phone' => '3011112222',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $this->runCommand(); // crea la cuenta/plan/caps demo, NO al usuario real

        $svc = app(IronAiMembershipAccessService::class);
        $caps = $svc->getAiCapabilities($svc->getCurrentMembership($realMember, $realUser));
        // El usuario real NO hereda voz/realtime/imagen del plan demo.
        $this->assertFalse((bool) ($caps['ai_realtime_voice_enabled'] ?? false));
        $this->assertFalse((bool) ($caps['ai_voice_chat_enabled'] ?? false));
        $this->assertFalse((bool) ($caps['ai_image_analysis_enabled'] ?? false));
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
