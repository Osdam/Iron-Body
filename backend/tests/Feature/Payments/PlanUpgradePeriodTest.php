<?php

namespace Tests\Feature\Payments;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Caja\CashShiftReport;
use App\Services\Caja\CashShiftService;
use App\Services\Payments\MembershipPeriod;
use App\Services\Payments\MembershipRebuilder;
use App\Services\RealtimeEvents;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PlanUpgradePeriodTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    private Admin $admin;

    private Plan $monthly;

    private Plan $quarterly;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setDay('2026-10-10');
        config(['commercial.events_enabled' => false, 'billing.enabled' => false]);
        $this->admin = Admin::create([
            'name' => 'Recepción', 'email' => 'mejora-periodo@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->headers = $this->actingAsAdmin($this->admin);
        $this->monthly = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30,
            'active' => true, 'features' => ['nutrition' => false]]);
        $this->quarterly = Plan::create(['name' => 'Trimestre', 'price' => 210000, 'duration_days' => 90,
            'active' => true, 'features' => ['nutrition' => true]]);
        app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function setDay(string $date): void
    {
        $day = CarbonImmutable::parse($date, MembershipPeriod::TZ)->setTime(12, 0);
        Carbon::setTestNow($day);
        CarbonImmutable::setTestNow($day);
    }

    private function member(string $start, bool $withPayment = true): User
    {
        $begin = CarbonImmutable::parse($start, MembershipPeriod::TZ);
        $user = User::create(['name' => 'Socio de mejora', 'email' => uniqid().'@ironbody.test',
            'password' => 'secret', 'status' => 'active', 'plan' => $this->monthly->name,
            'membership_start_date' => $start, 'membership_end_date' => $begin->addDays(30)->toDateString()]);
        if ($withPayment) {
            $this->payment($user, $this->monthly, $start, $begin->addDays(30)->toDateString());
        }

        return $user;
    }

    private function payment(User $user, Plan $plan, string $start, string $end): Payment
    {
        return Payment::create(['user_id' => $user->id, 'plan_id' => $plan->id,
            'amount' => $plan->price, 'method' => 'cash', 'status' => 'paid',
            'paid_at' => CarbonImmutable::parse($start, MembershipPeriod::TZ)->setTime(12, 0),
            'starts_on' => $start, 'period_start' => $start, 'period_end' => $end, 'origin' => 'counter']);
    }

    private function upgrade(User $user, array $extra = [])
    {
        return $this->postJson("/api/users/{$user->id}/plan-upgrade", array_merge([
            'plan_id' => $this->quarterly->id, 'method' => 'cash',
        ], $extra), $this->headers);
    }

    public static function periodCases(): array
    {
        return [
            'días consumidos' => ['2026-10-01', '2026-10-10', 9, '2026-12-30'],
            'mismo día' => ['2026-10-10', '2026-10-10', 0, '2027-01-08'],
            'un día restante' => ['2026-09-11', '2026-10-10', 29, '2026-12-10'],
            'enero a febrero' => ['2026-01-31', '2026-02-10', 10, '2026-05-01'],
            'febrero bisiesto' => ['2028-02-28', '2028-03-02', 3, '2028-05-28'],
        ];
    }

    #[DataProvider('periodCases')]
    public function test_descuenta_dias_calendario_del_periodo_y_congela_la_vigencia(string $start, string $today, int $used, string $end): void
    {
        $this->setDay($today);
        $this->headers = $this->actingAsAdmin($this->admin);
        $user = $this->member($start);
        $original = $user->payments()->firstOrFail();
        $oldEnd = $user->membershipEndDate;
        $this->getJson("/api/users/{$user->id}/plan-upgrade?plan_id={$this->quarterly->id}", $this->headers)
            ->assertOk()->assertJsonPath('starts_on', $start)
            ->assertJsonPath('previous_ends_on', $oldEnd)->assertJsonPath('days_consumed', $used)
            ->assertJsonPath('duration_days', 90)->assertJsonPath('days_left', 90 - $used)
            ->assertJsonPath('ends_on', $end)->assertJsonPath('amount', 130000);

        $this->upgrade($user, ['amount' => 1, 'ends_on' => '2099-01-01'])
            ->assertCreated()->assertJsonPath('amount', 130000)->assertJsonPath('user.membershipEndDate', $end);
        $payment = Payment::whereNotNull('upgraded_from_plan_id')->firstOrFail();
        $this->assertSame($start, $payment->period_start->toDateString());
        $this->assertSame($end, $payment->period_end->toDateString());
        $this->assertSame($used, $payment->upgrade_snapshot['days_consumed']);
        $this->assertSame($original->id, $payment->upgrade_snapshot['source_payment_id']);
        $this->assertSame($oldEnd, $original->refresh()->period_end->toDateString());
        $this->assertSame($start, $user->refresh()->membershipStartDate);
    }

    public function test_una_renovacion_usa_el_inicio_del_periodo_actual_y_no_el_inicio_historico(): void
    {
        $user = $this->member('2026-08-02');
        $this->payment($user, $this->monthly, '2026-09-01', '2026-10-01');
        $active = $this->payment($user, $this->monthly, '2026-10-01', '2026-10-31');
        $user->forceFill(['membership_end_date' => '2026-10-31'])->save();
        $this->upgrade($user)->assertCreated()->assertJsonPath('preview.starts_on', '2026-10-01')
            ->assertJsonPath('preview.days_consumed', 9)->assertJsonPath('user.membershipEndDate', '2026-12-30');
        $this->assertSame('2026-08-02', $user->refresh()->membershipStartDate);
        $payment = Payment::whereNotNull('upgraded_from_plan_id')->firstOrFail();
        $this->assertSame($active->id, $payment->upgrade_snapshot['source_payment_id']);
        $this->assertSame('2026-12-30', app(MembershipRebuilder::class)->preview($user)['end']);
    }

    public function test_caja_beneficios_historial_auditoria_y_senales_sse_corresponden_al_cambio(): void
    {
        $user = $this->member('2026-10-01');
        Member::create(['full_name' => 'Otro socio', 'document_number' => '991001', 'status' => 'active']);
        $member = Member::create(['user_id' => $user->id, 'full_name' => $user->name,
            'document_number' => '991002', 'status' => 'active']);
        $this->upgrade($user)->assertCreated();
        $this->getJson("/api/users/{$user->id}/plan-features", $this->headers)
            ->assertOk()->assertJsonPath('features.nutrition', true);
        $this->getJson("/api/admin/users/{$user->id}/payment-history", $this->headers)
            ->assertOk()->assertJsonPath('payments.0.is_upgrade', true)
            ->assertJsonPath('payments.0.upgraded_from.name', 'Mensual')
            ->assertJsonPath('payments.0.upgrade.days_consumed', 9)
            ->assertJsonPath('payments.0.period_end', '2026-12-30');
        $report = app(CashShiftReport::class)->for(app(CashShiftService::class)->requireOpen(CashShiftType::GYM));
        $this->assertSame(130000.0, (float) $report['consistency']['detail_total']);
        $this->assertStringContainsString('mejora desde Mensual', $report['transactions'][0]['plan']);
        $audit = AuditLog::where('entity', 'mejora de plan')->firstOrFail();
        $this->assertSame('membership.plan.upgraded', $audit->metadata['event']);
        $this->assertSame($user->id, $audit->metadata['user_id']);
        $events = MemberRealtimeEvent::where('member_id', $member->id)->get();
        $this->assertCount(3, $events);
        $this->assertEqualsCanonicalizing([RealtimeEvents::PAYMENT, RealtimeEvents::MEMBERSHIP, RealtimeEvents::APP_STATE], $events->pluck('type')->all());
        $this->assertSame(0, MemberRealtimeEvent::where('member_id', $user->id)->count());
    }

    public function test_si_falla_la_auditoria_no_quedan_cobro_vigencia_ni_eventos(): void
    {
        $user = $this->member('2026-10-01');
        Member::create(['user_id' => $user->id, 'full_name' => $user->name, 'document_number' => '991003', 'status' => 'active']);
        $this->mock(AuditTrail::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('auditoría no disponible'));
        $this->upgrade($user)->assertStatus(422);
        $this->assertSame(1, Payment::count());
        $this->assertSame('Mensual', $user->refresh()->plan);
        $this->assertSame('2026-10-31', $user->membershipEndDate);
        $this->assertSame(0, MemberRealtimeEvent::count());
        $this->assertSame(0, AuditLog::where('entity', 'mejora de plan')->count());
    }

    public function test_no_cobra_dos_veces_una_mejora_reintentada(): void
    {
        $user = $this->member('2026-10-01');
        $this->upgrade($user)->assertCreated();
        $this->upgrade($user)->assertStatus(422);
        $this->assertSame(1, Payment::whereNotNull('upgraded_from_plan_id')->count());
    }

    public function test_bloquea_fechas_ambiguas_futuras_y_planes_sin_duracion_antes_de_cobrar(): void
    {
        $user = $this->member('2026-10-01');
        $future = $this->payment($user, $this->monthly, '2026-10-31', '2026-11-30');
        $user->forceFill(['membership_end_date' => '2026-11-30'])->save();
        $this->upgrade($user)->assertStatus(422)->assertJsonPath('message', 'Tiene renovaciones futuras ya pagadas. Revisa esos periodos antes de mejorar el plan.');
        $this->assertSame('2026-11-30', $future->refresh()->period_end->toDateString());
        $future->delete();
        $user->forceFill(['membership_start_date' => null, 'membership_end_date' => '2026-10-31'])->save();
        $this->upgrade($user)->assertStatus(422);
        $user->forceFill(['membership_start_date' => '2026-10-15', 'membership_end_date' => '2026-11-14'])->save();
        $this->upgrade($user)->assertStatus(422);
        $user->forceFill(['membership_start_date' => '2026-10-01', 'membership_end_date' => '2026-10-31'])->save();
        $this->quarterly->forceFill(['duration_days' => 0])->save();
        $this->upgrade($user)->assertStatus(422);
        $this->assertSame(0, Payment::whereNotNull('upgraded_from_plan_id')->count());
    }

    public function test_anular_reconfirmar_y_reconstruir_preserva_el_mensual_original(): void
    {
        $user = $this->member('2026-10-01');
        $original = Payment::firstOrFail();
        $this->upgrade($user)->assertCreated();
        $payment = Payment::whereNotNull('upgraded_from_plan_id')->firstOrFail();
        $this->assertSame('2026-12-30', app(MembershipRebuilder::class)->preview($user->refresh())['end']);
        $this->patchJson("/api/payments/{$payment->id}", ['status' => 'cancelled'], $this->headers)->assertOk();
        $this->assertSame('Mensual', $user->refresh()->plan);
        $this->assertSame('2026-10-31', $user->membershipEndDate);
        $this->assertSame('paid', $original->refresh()->status);
        $this->assertSame('2026-10-31', $original->period_end->toDateString());
        $this->assertSame('2026-10-31', app(MembershipRebuilder::class)->preview($user)['end']);
        $this->patchJson("/api/payments/{$payment->id}", ['status' => 'paid'], $this->headers)->assertOk();
        $this->assertSame('Trimestre', $user->refresh()->plan);
        $this->assertSame('2026-12-30', $user->membershipEndDate);
        $this->patchJson("/api/payments/{$payment->id}", ['status' => 'paid'], $this->headers)->assertOk();
        $this->assertSame('2026-12-30', $user->refresh()->membershipEndDate);
        $this->assertSame(2, Payment::count());
    }

    public function test_no_usa_fechas_de_user_para_mejorar_un_periodo_con_cobro_anulado(): void
    {
        $user = $this->member('2026-10-01');
        $original = Payment::firstOrFail();
        $original->forceFill(['status' => 'cancelled'])->save();
        $this->getJson("/api/users/{$user->id}/plan-upgrade?plan_id={$this->quarterly->id}", $this->headers)
            ->assertOk()->assertJsonPath('can_upgrade', false)
            ->assertJsonPath('reason', 'El cobro de este periodo no está pagado. Revisa su estado antes de mejorar el plan.');
        $this->upgrade($user)->assertStatus(422);
        $this->assertSame(0, Payment::whereNotNull('upgraded_from_plan_id')->count());
        $this->assertSame('2026-10-31', $user->refresh()->membershipEndDate);
        $this->assertSame('cancelled', $original->refresh()->status);
    }

    public function test_legacy_mejora_no_suma_dias_al_reconstruir_o_anular(): void
    {
        $user = $this->member('2026-10-01');
        $legacy = Payment::create(['user_id' => $user->id, 'plan_id' => $this->quarterly->id,
            'upgraded_from_plan_id' => $this->monthly->id, 'amount' => 130000, 'method' => 'cash',
            'status' => 'paid', 'paid_at' => now(), 'starts_on' => '2026-10-10',
            'period_start' => '2026-10-10', 'period_end' => '2026-10-31', 'origin' => 'counter']);
        $user->forceFill(['plan' => 'Trimestre'])->save();
        $this->assertSame('2026-10-31', app(MembershipRebuilder::class)->preview($user)['end']);
        $this->patchJson("/api/payments/{$legacy->id}", ['status' => 'cancelled'], $this->headers)->assertOk();
        $this->assertSame('2026-10-31', $user->refresh()->membershipEndDate);
        $this->assertSame('Mensual', $user->plan);
    }

    public function test_anular_mejora_recoloca_renovacion_posterior_sin_quitar_lo_pagado(): void
    {
        $user = $this->member('2026-10-01');
        $this->upgrade($user)->assertCreated();
        $upgrade = Payment::whereNotNull('upgraded_from_plan_id')->firstOrFail();
        $renewal = $this->payment($user, $this->quarterly, '2026-12-30', '2027-03-30');
        $renewal->forceFill(['starts_on' => '2026-10-10', 'paid_at' => now()])->save();
        $user->forceFill(['membership_end_date' => '2027-03-30'])->save();
        $this->patchJson("/api/payments/{$upgrade->id}", ['status' => 'cancelled'], $this->headers)->assertOk();
        $this->assertSame('2026-10-31', $renewal->refresh()->period_start->toDateString());
        $this->assertSame('2027-01-29', $renewal->period_end->toDateString());
        $this->assertSame('2027-01-29', $user->refresh()->membershipEndDate);
        $this->assertSame('Trimestre', $user->plan);
    }
}
