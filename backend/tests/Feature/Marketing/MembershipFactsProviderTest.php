<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\MembershipSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Services\Marketing\Ultron\MembershipFactsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `context.membership`: hechos de la membresía de quien escribe, solo con
 * identidad (lead.member_id), sin datos personales, con lo que no existe
 * marcado como SOURCE_NOT_AVAILABLE y con lo que el asistente no resuelve solo
 * declarado en team_only.
 */
class MembershipFactsProviderTest extends TestCase
{
    use RefreshDatabase;

    private MembershipFactsProvider $provider;

    private Plan $mensual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = app(MembershipFactsProvider::class);
        $this->mensual = Plan::create([
            'name' => 'Plan Mensual', 'price' => 90000, 'duration_days' => 30, 'active' => true, 'sellable' => true,
        ]);
    }

    private function lead(?int $memberId = null): MarketingLead
    {
        return MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026',
            'name' => 'Carla Prueba', 'status' => MarketingLead::STATUS_INTERESTED, 'member_id' => $memberId,
        ]);
    }

    /** Socia de pago único: users.plan es un NOMBRE, sin fila en membership_subscriptions. */
    private function socia(int $startedDaysAgo, int $endsInDays, string $planName = 'Plan Mensual'): Member
    {
        $user = User::create([
            'name' => 'Carla Prueba', 'email' => 'carla-'.uniqid().'@ironbody.test', 'password' => bcrypt('secret-password'),
            'document' => '10'.random_int(10000000, 99999999), 'status' => 'active', 'plan' => $planName,
            'membership_start_date' => now()->subDays($startedDaysAgo)->toDateString(),
            'membership_end_date' => now()->addDays($endsInDays)->toDateString(),
        ]);

        return Member::create([
            'user_id' => $user->id, 'full_name' => 'Carla Prueba', 'email' => $user->email,
            'document_number' => $user->document, 'phone' => '3150536026',
        ]);
    }

    public function test_without_a_linked_member_nothing_is_asserted(): void
    {
        $m = $this->provider->forPrompt($this->lead());

        $this->assertSame(MembershipFactsProvider::STATUS_UNKNOWN, $m['status']);
        $this->assertFalse($m['identity']);
        $this->assertNull($m['plan']);
        $this->assertNull($m['ends_on']);
        $this->assertSame(MembershipFactsProvider::SOURCE_NOT_AVAILABLE, $m['attendance']);
        $this->assertSame(MembershipFactsProvider::SOURCE_NOT_AVAILABLE, $m['autopay']);
        $this->assertFalse($m['renewal']['window_open']);
        $this->assertSame(MembershipFactsProvider::TEAM_ONLY, $m['team_only']);
    }

    public function test_a_null_lead_is_unknown_and_never_throws(): void
    {
        $this->assertSame(MembershipFactsProvider::STATUS_UNKNOWN, $this->provider->forPrompt(null)['status']);
    }

    public function test_an_active_member_gets_her_plan_and_dates_as_facts(): void
    {
        $member = $this->socia(startedDaysAgo: 10, endsInDays: 20);
        $m = $this->provider->forPrompt($this->lead($member->id));

        $this->assertSame(MembershipFactsProvider::STATUS_ACTIVE, $m['status']);
        $this->assertTrue($m['identity']);
        $this->assertSame($this->mensual->id, $m['plan']['id']);
        $this->assertSame('Plan Mensual', $m['plan']['name']);
        $this->assertSame(30, $m['plan']['duration_days']);
        $this->assertTrue($m['plan']['sellable']);
        $this->assertSame(now()->addDays(20)->toDateString(), $m['ends_on']);
        $this->assertEqualsWithDelta(20, $m['days_to_expiry'], 1);
        $this->assertNull($m['days_since_expiry']);
        $this->assertSame(now()->subDays(10)->toDateString(), $m['started_on']);
        $this->assertSame(0, $m['attendance']['last_30_days']);
        $this->assertSame(['enabled' => false, 'status' => 'none', 'next_charge_on' => null], $m['autopay']);
        $this->assertFalse($m['renewal']['window_open']);
        $this->assertNull($m['renewal']['plan_id'], 'fuera de ventana no hay plan que renovar');
    }

    public function test_inside_the_renewal_window_the_current_sellable_plan_is_the_renewal(): void
    {
        $member = $this->socia(startedDaysAgo: 25, endsInDays: 5);
        $m = $this->provider->forPrompt($this->lead($member->id));

        $this->assertSame(MembershipFactsProvider::STATUS_EXPIRING, $m['status']);
        $this->assertTrue($m['renewal']['window_open']);
        $this->assertSame($this->mensual->id, $m['renewal']['plan_id']);
    }

    public function test_a_lapsed_member_is_expired_with_her_last_plan(): void
    {
        $member = $this->socia(startedDaysAgo: 60, endsInDays: -12);
        $m = $this->provider->forPrompt($this->lead($member->id));

        $this->assertSame(MembershipFactsProvider::STATUS_EXPIRED, $m['status']);
        $this->assertNull($m['days_to_expiry']);
        $this->assertEqualsWithDelta(12, $m['days_since_expiry'], 1);
        $this->assertSame('Plan Mensual', $m['plan']['name']);
        $this->assertFalse($m['renewal']['window_open']);
        $this->assertSame($this->mensual->id, $m['renewal']['plan_id'], 'recuperar a un exsocio propone su último plan si aún se vende');
    }

    public function test_a_plan_that_is_no_longer_sold_is_named_but_never_proposed_for_renewal(): void
    {
        $this->mensual->forceFill(['sellable' => false])->save();
        $member = $this->socia(startedDaysAgo: 25, endsInDays: 3);
        $m = $this->provider->forPrompt($this->lead($member->id));

        $this->assertSame(MembershipFactsProvider::STATUS_EXPIRING, $m['status']);
        $this->assertSame('Plan Mensual', $m['plan']['name']);
        $this->assertFalse($m['plan']['sellable']);
        $this->assertNull($m['renewal']['plan_id']);
    }

    public function test_a_plan_name_unknown_to_the_catalog_travels_as_crm_text_without_id(): void
    {
        $member = $this->socia(startedDaysAgo: 5, endsInDays: 25, planName: 'Convenio Empresa X');
        $m = $this->provider->forPrompt($this->lead($member->id));

        $this->assertNull($m['plan']['id']);
        $this->assertSame('Convenio Empresa X', $m['plan']['name']);
        $this->assertFalse($m['plan']['sellable']);
    }

    public function test_autopay_comes_from_the_latest_subscription(): void
    {
        $member = $this->socia(startedDaysAgo: 10, endsInDays: 20);
        MembershipSubscription::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $member->id, 'user_id' => $member->user_id,
            'plan_id' => $this->mensual->id, 'status' => MembershipSubscription::STATUS_ACTIVE, 'price_snapshot' => 90000,
            'currency' => 'COP', 'interval_days' => 30, 'method' => 'card', 'next_charge_at' => now()->addDays(20),
            'current_period_start' => now()->subDays(10)->toDateString(), 'current_period_end' => now()->addDays(20)->toDateString(),
        ]);

        $m = $this->provider->forPrompt($this->lead($member->id));

        $this->assertTrue($m['autopay']['enabled']);
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $m['autopay']['status']);
        $this->assertSame(now()->addDays(20)->toDateString(), $m['autopay']['next_charge_on']);
    }

    public function test_a_paused_subscription_is_not_charging(): void
    {
        $member = $this->socia(startedDaysAgo: 10, endsInDays: 20);
        MembershipSubscription::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $member->id, 'user_id' => $member->user_id,
            'plan_id' => $this->mensual->id, 'status' => MembershipSubscription::STATUS_PAUSED, 'price_snapshot' => 90000,
            'currency' => 'COP', 'interval_days' => 30, 'method' => 'card', 'next_charge_at' => now()->addDays(20),
        ]);

        $m = $this->provider->forPrompt($this->lead($member->id));

        $this->assertFalse($m['autopay']['enabled']);
        $this->assertSame(MembershipSubscription::STATUS_PAUSED, $m['autopay']['status']);
        $this->assertNull($m['autopay']['next_charge_on']);
    }

    public function test_the_block_never_carries_personal_data(): void
    {
        $member = $this->socia(startedDaysAgo: 10, endsInDays: 20);
        $json = json_encode($this->provider->forPrompt($this->lead($member->id)));

        $this->assertStringNotContainsString('Carla', $json);
        $this->assertStringNotContainsString('3150536026', $json);
        $this->assertStringNotContainsString($member->document_number, $json);
        $this->assertStringNotContainsString('@ironbody.test', $json);
    }
}
