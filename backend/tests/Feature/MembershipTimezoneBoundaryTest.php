<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión de BACK-010: la vigencia de la membresía se calculaba con
 * endOfDay() en UTC, de modo que el acceso se cortaba a las 18:59 de Colombia
 * (medianoche UTC) el último día del plan, ~5h antes de lo debido. Ahora el fin
 * de vigencia es la medianoche LOCAL (America/Bogota).
 *
 * Bogota = UTC-5. El último día '2026-07-14' debe seguir activo hasta
 * '2026-07-15 04:59:59Z' (medianoche local), no hasta '2026-07-14 23:59:59Z'.
 */
class MembershipTimezoneBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // limpia el reloj congelado
        parent::tearDown();
    }

    private function member(string $endDate): Member
    {
        $user = User::create([
            'name' => 'Ana Prueba',
            'email' => 'ana@example.com',
            'password' => 'secret',
            'document' => '1010101010',
            'phone' => '3001234567',
            'status' => 'active',
            'plan' => 'PLAN TOTAL',
            'membership_end_date' => $endDate,
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Ana Prueba',
            'email' => 'ana@example.com',
            'document_number' => '1010101010',
            'phone' => '3001234567',
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    public function test_membership_stays_active_in_the_local_evening_of_the_last_day(): void
    {
        // 2026-07-14 22:00 en Bogota = 2026-07-15 03:00 UTC. Con el bug (UTC) la
        // membresía ya figuraba vencida; con el fix sigue activa.
        Carbon::setTestNow(Carbon::parse('2026-07-15 03:00:00', 'UTC'));
        $member = $this->member('2026-07-14');
        $auth = ['Authorization' => 'Bearer '.$member->access_hash];

        $this->getJson('/api/member/app-state', $auth)
            ->assertOk()
            ->assertJsonPath('membership.is_active', true);

        $this->getJson('/api/member/account/status', $auth)
            ->assertOk()
            ->assertJsonPath('can_access_app', true)
            ->assertJsonPath('membership_active', true);
    }

    public function test_membership_expires_after_local_midnight(): void
    {
        // 2026-07-15 00:30 en Bogota = 2026-07-15 05:30 UTC: ya pasó la medianoche
        // local del último día → vencida.
        Carbon::setTestNow(Carbon::parse('2026-07-15 05:30:00', 'UTC'));
        $member = $this->member('2026-07-14');
        $auth = ['Authorization' => 'Bearer '.$member->access_hash];

        $this->getJson('/api/member/app-state', $auth)
            ->assertOk()
            ->assertJsonPath('membership.is_active', false);

        $this->getJson('/api/member/account/status', $auth)
            ->assertOk()
            ->assertJsonPath('can_access_app', false);
    }
}
