<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberDeviceBinding;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Diagnóstico/recuperación de account_mismatch + reset seguro de device trust,
 * y contrato de features premium (Plan Total) sin hardcode en Flutter.
 */
class DeviceTrustResetTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $doc, string $phone, ?string $plan = null, ?string $end = null): Member
    {
        $user = User::create([
            'name' => 'M '.$doc,
            'email' => 'm'.$doc.'@example.com',
            'password' => 'secret',
            'document' => $doc,
            'phone' => $phone,
            'status' => 'active',
            'plan' => $plan,
            'membership_end_date' => $end,
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'M '.$doc,
            'email' => 'm'.$doc.'@example.com',
            'document_number' => $doc,
            'phone' => $phone,
            'access_hash' => 'tok-'.$doc,
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    public function test_account_mismatch_when_device_bound_to_another_member(): void
    {
        $owner = $this->member('1111', '3000000001');
        $other = $this->member('2222', '3000000002');

        MemberDeviceBinding::create([
            'device_id' => 'iphone-XYZ',
            'member_id' => $owner->id,
            'device_name' => 'iPhone',
            'bound_at' => now(),
        ]);

        $r = $this->postJson('/api/members/login', [
            'document_number' => '2222',
            'device_id' => 'iphone-XYZ',
        ]);

        $r->assertStatus(403)
            ->assertJsonPath('code', 'account_mismatch')
            ->assertJsonPath('reason_code', 'device_bound_to_another_member')
            ->assertJsonPath('data.recovery_options.support_report', true)
            ->assertJsonPath('data.recovery_options.can_reset_local_session', true)
            ->assertJsonPath('data.recovery_options.can_request_rebind', false);
        // No revela datos del titular real.
        $this->assertStringNotContainsString('1111', json_encode($r->json()));
    }

    public function test_reset_device_trust_frees_binding_and_allows_login(): void
    {
        $owner = $this->member('1111', '3000000001');
        MemberDeviceBinding::create([
            'device_id' => 'iphone-XYZ', 'member_id' => $owner->id,
            'device_name' => 'iPhone', 'bound_at' => now(),
        ]);
        $other = $this->member('2222', '3000000002');

        $this->artisan('dev:reset-device-trust', [
            '--device-name' => ['iPhone'],
            '--clear-bindings' => true,
            '--revoke-sessions' => true,
            '--include-active' => true,
            '--force' => true,
        ])->assertExitCode(0);

        // Binding liberado y datos base intactos.
        $this->assertDatabaseMissing('member_device_bindings', ['device_id' => 'iphone-XYZ']);
        $this->assertDatabaseHas('members', ['id' => $owner->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->user_id]);

        // Ahora el otro miembro entra en ese iPhone (pide OTP, no account_mismatch).
        $this->postJson('/api/members/login', [
            'document_number' => '2222', 'device_id' => 'iphone-XYZ',
        ])->assertOk()->assertJsonPath('data.requires_otp', true);
    }

    public function test_reset_by_member_does_not_remove_other_member_binding(): void
    {
        $a = $this->member('1111', '3000000001');
        $b = $this->member('2222', '3000000002');
        MemberDeviceBinding::create(['device_id' => 'dev-A', 'member_id' => $a->id, 'device_name' => 'A', 'bound_at' => now()]);
        MemberDeviceBinding::create(['device_id' => 'dev-B', 'member_id' => $b->id, 'device_name' => 'B', 'bound_at' => now()]);

        $this->artisan('dev:reset-device-trust', [
            '--member-id' => [(string) $b->id],
            '--clear-bindings' => true,
            '--include-active' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('member_device_bindings', ['device_id' => 'dev-A', 'member_id' => $a->id]);
        $this->assertDatabaseMissing('member_device_bindings', ['device_id' => 'dev-B']);
    }

    public function test_reset_preserves_membership_and_plan(): void
    {
        $m = $this->member('1111', '3000000001', 'PLAN TOTAL', now()->addMonth()->toDateString());
        MemberDeviceBinding::create(['device_id' => 'dev-A', 'member_id' => $m->id, 'device_name' => 'A', 'bound_at' => now()]);

        $this->artisan('dev:reset-device-trust', [
            '--member-id' => [(string) $m->id],
            '--clear-bindings' => true,
            '--revoke-sessions' => true,
            '--clear-otp' => true,
            '--clear-risk' => true,
            '--include-active' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $m->user->refresh();
        $this->assertSame('PLAN TOTAL', $m->user->plan);
        $this->assertNotNull($m->user->membership_end_date);
        $this->assertSame(Member::STATUS_ACTIVE, $m->fresh()->status);
    }

    public function test_dry_run_does_not_change_anything(): void
    {
        $m = $this->member('1111', '3000000001');
        MemberDeviceBinding::create(['device_id' => 'dev-A', 'member_id' => $m->id, 'device_name' => 'A', 'bound_at' => now()]);

        $this->artisan('dev:reset-device-trust', [
            '--member-id' => [(string) $m->id],
            '--clear-bindings' => true,
            '--include-active' => true,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('member_device_bindings', ['device_id' => 'dev-A']);
    }

    public function test_plan_total_active_unlocks_full_app_via_app_state(): void
    {
        Plan::create(['name' => 'PLAN TOTAL', 'features' => [
            'iron_ia' => true, 'workouts' => true, 'custom_routines' => true,
            'ranking' => false, 'classes' => true, 'progress' => true, 'nutrition' => true,
        ]]);
        $m = $this->member('1111', '3000000001', 'PLAN TOTAL', now()->addMonth()->toDateString());

        $r = $this->getJson('/api/member/app-state', ['Authorization' => 'Bearer '.$m->access_hash]);

        $r->assertOk()
            ->assertJsonPath('membership.is_active', true)
            ->assertJsonPath('membership.plan_name', 'PLAN TOTAL')
            ->assertJsonPath('features.can_use_full_app', true)
            ->assertJsonPath('features.can_use_ai', true)
            ->assertJsonPath('features.can_use_training', true)
            ->assertJsonPath('features.can_use_progress', true)
            ->assertJsonPath('features.can_use_exercise_library', true)
            ->assertJsonPath('features.can_use_stories', true)
            ->assertJsonPath('features.can_use_security_devices', true)
            ->assertJsonPath('features.can_use_membership_details', true);
    }

    public function test_no_plan_does_not_unlock_full_app(): void
    {
        // Sin plan ni pago: member no-activo no accede a Home premium.
        $user = User::create([
            'name' => 'NoPlan', 'email' => 'np@example.com', 'password' => 'secret',
            'document' => '3333', 'phone' => '3000000003', 'status' => 'created',
        ]);
        $m = Member::create([
            'user_id' => $user->id, 'full_name' => 'NoPlan', 'email' => 'np@example.com',
            'document_number' => '3333', 'phone' => '3000000003',
            'access_hash' => 'tok-3333', 'status' => Member::STATUS_INCOMPLETE,
        ]);

        $this->getJson('/api/member/app-state', ['Authorization' => 'Bearer '.$m->access_hash])
            ->assertOk()
            ->assertJsonPath('features.can_use_full_app', false)
            ->assertJsonPath('features.requires_activation', true);
    }
}
