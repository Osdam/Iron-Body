<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use App\Services\MembershipService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renovación / cancelación de membresía (Bloque 3). Cubre el ciclo de vida del
 * servicio y los endpoints admin del CRM. La cancelación conserva el acceso
 * hasta el fin del periodo y nunca borra datos.
 */
class MembershipCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithPlan(string $endDate): Member
    {
        $user = User::create([
            'name' => 'Ana Prueba',
            'email' => 'ana@example.com',
            'password' => 'secret',
            'document' => '1010101010',
            'phone' => '3001234567',
            'status' => 'active',
            'plan' => 'Plan Total',
            'membership_start_date' => Carbon::today()->subMonth()->toDateString(),
            'membership_end_date' => $endDate,
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Ana Prueba',
            'email' => 'ana@example.com',
            'document_number' => '1010101010',
            'phone' => '3001234567',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function svc(): MembershipService
    {
        return app(MembershipService::class);
    }

    public function test_active_membership_status(): void
    {
        $m = $this->memberWithPlan(Carbon::today()->addDays(20)->toDateString());
        $snap = $this->svc()->snapshot($m->user);

        $this->assertSame('active', $snap['status']);
        $this->assertTrue($snap['is_active']);
        $this->assertTrue($snap['auto_renew']);
    }

    public function test_request_cancellation_keeps_access_until_period_end(): void
    {
        $m = $this->memberWithPlan(Carbon::today()->addDays(20)->toDateString());
        $snap = $this->svc()->requestCancellation($m->user);

        $this->assertSame('cancel_requested', $snap['status']);
        $this->assertTrue($snap['is_active']); // conserva acceso
        $this->assertFalse($snap['auto_renew']);
        $this->assertNotNull($snap['cancellation_requested_at']);
    }

    public function test_cancelled_after_period_ends(): void
    {
        $m = $this->memberWithPlan(Carbon::today()->subDay()->toDateString());
        $this->svc()->requestCancellation($m->user);
        $snap = $this->svc()->snapshot($m->user->fresh());

        $this->assertSame('cancelled', $snap['status']);
        $this->assertFalse($snap['is_active']);
    }

    public function test_expired_without_cancellation(): void
    {
        $m = $this->memberWithPlan(Carbon::today()->subDay()->toDateString());

        $this->assertSame('expired', $this->svc()->status($m->user));
    }

    public function test_reactivate_undoes_cancellation(): void
    {
        $m = $this->memberWithPlan(Carbon::today()->addDays(20)->toDateString());
        $this->svc()->requestCancellation($m->user);
        $snap = $this->svc()->reactivate($m->user->fresh());

        $this->assertSame('active', $snap['status']);
        $this->assertTrue($snap['auto_renew']);
    }

    public function test_admin_cancel_immediate_revokes_access(): void
    {
        $m = $this->memberWithPlan(Carbon::today()->addDays(20)->toDateString());

        $r = $this->postJson("/api/admin/memberships/{$m->id}/cancel", ['immediate' => true]);

        $r->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame('cancelled', $r->json('data.status'));
    }

    public function test_admin_cancel_scheduled_keeps_access(): void
    {
        $m = $this->memberWithPlan(Carbon::today()->addDays(20)->toDateString());

        $r = $this->postJson("/api/admin/memberships/{$m->id}/cancel");

        $r->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.status', 'cancel_requested');
    }

    public function test_admin_reactivate(): void
    {
        $m = $this->memberWithPlan(Carbon::today()->addDays(20)->toDateString());
        $this->postJson("/api/admin/memberships/{$m->id}/cancel");

        $r = $this->postJson("/api/admin/memberships/{$m->id}/reactivate");

        $r->assertOk()->assertJsonPath('data.status', 'active');
    }
}
