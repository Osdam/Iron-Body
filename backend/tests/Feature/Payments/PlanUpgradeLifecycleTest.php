<?php

namespace Tests\Feature\Payments;

use App\Enums\CashShiftType;
use App\Http\Controllers\Api\Admin\EarningsController;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Payments\MembershipPeriod;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanUpgradeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    private User $user;

    private Payment $upgrade;

    protected function setUp(): void
    {
        parent::setUp();
        $day = CarbonImmutable::parse('2026-10-10 12:00', MembershipPeriod::TZ);
        Carbon::setTestNow($day);
        CarbonImmutable::setTestNow($day);
        config(['commercial.events_enabled' => false, 'billing.enabled' => false]);
        $this->headers = $this->adminHeaders();
        $this->openCashShift(type: CashShiftType::GYM);
        $monthly = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $quarter = Plan::create(['name' => 'Trimestre', 'price' => 210000, 'duration_days' => 90, 'active' => true]);
        $this->user = User::create(['name' => 'Socio ciclo mejora', 'email' => 'ciclo@ironbody.test', 'password' => 'secret',
            'plan' => $monthly->name, 'status' => 'active',
            'membership_start_date' => '2026-10-01', 'membership_end_date' => '2026-10-31']);
        Member::create(['user_id' => $this->user->id, 'full_name' => $this->user->name, 'document_number' => '995001', 'status' => 'active']);
        Payment::create(['user_id' => $this->user->id, 'plan_id' => $monthly->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid', 'paid_at' => '2026-10-01 12:00',
            'starts_on' => '2026-10-01', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
        $paymentId = $this->postJson("/api/users/{$this->user->id}/plan-upgrade", [
            'plan_id' => $quarter->id, 'method' => 'cash',
        ], $this->headers)->assertCreated()->json('payment_id');
        $this->upgrade = Payment::findOrFail($paymentId);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function update(array $data)
    {
        return $this->patchJson("/api/payments/{$this->upgrade->id}", $data, $this->headers);
    }

    public function test_repetir_la_anulacion_no_publica_otra_mutacion(): void
    {
        $this->update(['status' => 'cancelled'])->assertOk();
        $events = MemberRealtimeEvent::count();
        $audits = AuditLog::where('entity', 'mejora de plan')->count();
        $this->assertSame(6, $events);
        $this->update(['status' => 'cancelled'])->assertOk();
        $this->assertSame($events, MemberRealtimeEvent::count());
        $this->assertSame($audits, AuditLog::where('entity', 'mejora de plan')->count());
        $this->assertSame('2026-10-31', $this->user->refresh()->membershipEndDate);
        $this->assertSame('membership.plan.upgrade.cancelled', AuditLog::where('entity', 'mejora de plan')->latest('id')->first()->metadata['event']);
    }

    public function test_caja_detecta_anulacion_y_restauracion_en_el_mismo_segundo(): void
    {
        $paidSignature = EarningsController::financialSignature();
        $timestamp = $this->upgrade->updated_at->toDateTimeString();
        $count = Payment::count();
        $this->update(['status' => 'cancelled'])->assertOk();
        $cancelledSignature = EarningsController::financialSignature();
        $this->assertNotSame($paidSignature, $cancelledSignature);
        $this->assertSame($timestamp, $this->upgrade->refresh()->updated_at->toDateTimeString());
        $this->assertSame($count, Payment::count());

        $this->update(['status' => 'paid'])->assertOk();
        $this->assertNotSame($cancelledSignature, EarningsController::financialSignature());
        $this->assertSame($paidSignature, EarningsController::financialSignature());
        $this->assertSame($timestamp, $this->upgrade->refresh()->updated_at->toDateTimeString());
        $this->assertSame($count, Payment::count());
    }

    public function test_el_importe_de_una_mejora_no_se_puede_reescribir_como_pago_libre(): void
    {
        $this->update(['amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(130000.0, $this->upgrade->refresh()->amount);
        $this->assertSame('2026-12-30', $this->user->refresh()->membershipEndDate);
        $this->assertSame(3, MemberRealtimeEvent::count());
    }

    public function test_no_se_anula_el_mensual_que_sostiene_una_mejora_pagada(): void
    {
        $source = Payment::findOrFail($this->upgrade->upgrade_snapshot['source_payment_id']);
        $this->patchJson("/api/payments/{$source->id}", ['status' => 'cancelled'], $this->headers)
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('paid', $source->refresh()->status);
        $this->assertSame('paid', $this->upgrade->refresh()->status);
        $this->assertSame('2026-12-30', $this->user->refresh()->membershipEndDate);
        $this->assertSame(3, MemberRealtimeEvent::count());
    }

    public function test_anular_una_mejora_con_otra_dependiente_falla_sin_tocar_cobro_ni_cobertura(): void
    {
        $semester = Plan::create(['name' => 'Semestre', 'price' => 390000, 'duration_days' => 180, 'active' => true]);
        $this->postJson("/api/users/{$this->user->id}/plan-upgrade", [
            'plan_id' => $semester->id, 'method' => 'cash',
        ], $this->headers)->assertCreated();
        $this->update(['status' => 'cancelled'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('paid', $this->upgrade->refresh()->status);
        $this->assertSame('2027-03-30', $this->user->refresh()->membershipEndDate);
        $this->assertSame('Semestre', $this->user->plan);
        $this->assertSame(6, MemberRealtimeEvent::count());
        $this->assertSame(2, AuditLog::where('entity', 'mejora de plan')->count());
    }

    public function test_fallo_de_auditoria_al_anular_revierte_pago_cobertura_y_eventos(): void
    {
        $this->mock(AuditTrail::class)->shouldReceive('record')->once()
            ->andThrow(ValidationException::withMessages(['status' => ['Fallo de auditoría de prueba.']]));
        $this->update(['status' => 'cancelled'])->assertUnprocessable();
        $this->assertSame('paid', $this->upgrade->refresh()->status);
        $this->assertSame('2026-12-30', $this->user->refresh()->membershipEndDate);
        $this->assertSame('Trimestre', $this->user->plan);
        $this->assertSame(3, MemberRealtimeEvent::count());
        $this->assertSame(1, AuditLog::where('entity', 'mejora de plan')->count());
    }

    public function test_reconfirmar_no_pisa_un_ajuste_posterior_y_conserva_el_pago_anulado(): void
    {
        $this->update(['status' => 'cancelled'])->assertOk();
        $this->user->forceFill(['membership_end_date' => '2026-11-05'])->save();
        $this->update(['status' => 'paid'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('cancelled', $this->upgrade->refresh()->status);
        $this->assertSame('2026-11-05', $this->user->refresh()->membershipEndDate);
        $this->assertSame('Mensual', $this->user->plan);
        $this->assertSame(6, MemberRealtimeEvent::count());
    }

    public function test_reconfirmar_no_cobra_si_el_plan_destino_fue_eliminado(): void
    {
        $this->update(['status' => 'cancelled'])->assertOk();
        Plan::findOrFail($this->upgrade->plan_id)->delete();
        $this->update(['status' => 'paid'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('cancelled', $this->upgrade->refresh()->status);
        $this->assertSame('Mensual', $this->user->refresh()->plan);
        $this->assertSame('2026-10-31', $this->user->membershipEndDate);
        $this->assertSame(6, MemberRealtimeEvent::count());
    }

    public function test_reconfirmar_no_cobra_si_el_pago_origen_ya_no_sostiene_la_cobertura(): void
    {
        $this->update(['status' => 'cancelled'])->assertOk();
        Payment::findOrFail($this->upgrade->upgrade_snapshot['source_payment_id'])
            ->forceFill(['status' => 'cancelled'])->save();
        // Caso histórico: la anulación anterior conservó las fechas de User.
        $this->update(['status' => 'paid'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('cancelled', $this->upgrade->refresh()->status);
        $this->assertSame('Mensual', $this->user->refresh()->plan);
        $this->assertSame('2026-10-31', $this->user->membershipEndDate);
        $this->assertSame(6, MemberRealtimeEvent::count());
    }

    public static function replacementSources(): array
    {
        return ['pago origen' => [false], 'periodo importado' => [true]];
    }

    #[DataProvider('replacementSources')]
    public function test_reconfirmar_no_duplica_una_mejora_recomprada_sobre_el_mismo_origen(bool $imported): void
    {
        if ($imported) {
            $snapshot = $this->upgrade->upgrade_snapshot;
            Payment::findOrFail($snapshot['source_payment_id'])->delete();
            $snapshot['source_payment_id'] = null;
            $this->upgrade->forceFill(['upgrade_snapshot' => $snapshot])->save();
        }
        $this->update(['status' => 'cancelled'])->assertOk();
        $replacementId = $this->postJson("/api/users/{$this->user->id}/plan-upgrade", [
            'plan_id' => $this->upgrade->plan_id, 'method' => 'cash',
        ], $this->headers)->assertCreated()->json('payment_id');

        $this->update(['status' => 'paid'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('cancelled', $this->upgrade->refresh()->status);
        $this->assertSame('paid', Payment::findOrFail($replacementId)->status);
        $this->assertSame('2026-12-30', $this->user->refresh()->membershipEndDate);
        $this->assertSame(130000.0, (float) Payment::whereNotNull('upgraded_from_plan_id')->where('status', 'paid')->sum('amount'));
        $this->assertSame(9, MemberRealtimeEvent::count());
    }

    public function test_reconfirmar_la_segunda_mejora_conserva_la_primera_como_origen(): void
    {
        $semester = Plan::create(['name' => 'Semestre', 'price' => 390000, 'duration_days' => 180, 'active' => true]);
        $secondId = $this->postJson("/api/users/{$this->user->id}/plan-upgrade", [
            'plan_id' => $semester->id, 'method' => 'cash',
        ], $this->headers)->assertCreated()->json('payment_id');
        $this->patchJson("/api/payments/{$secondId}", ['status' => 'cancelled'], $this->headers)->assertOk();
        $this->patchJson("/api/payments/{$secondId}", ['status' => 'paid'], $this->headers)->assertOk();

        $this->assertSame('paid', $this->upgrade->refresh()->status);
        $this->assertSame('paid', Payment::findOrFail($secondId)->status);
        $this->assertSame('2027-03-30', $this->user->refresh()->membershipEndDate);
        $this->assertSame('Semestre', $this->user->plan);
    }

    public function test_reconfirmar_legacy_no_duplica_ni_pisa_una_mejora_posterior(): void
    {
        $this->upgrade->forceFill(['upgrade_snapshot' => null,
            'starts_on' => '2026-10-10', 'period_start' => '2026-10-10', 'period_end' => '2026-10-31'])->save();
        $this->user->refresh()->forceFill(['membership_end_date' => '2026-10-31'])->save();
        $this->update(['status' => 'cancelled'])->assertOk();
        $semester = Plan::create(['name' => 'Semestre', 'price' => 390000, 'duration_days' => 180, 'active' => true]);
        $replacementId = $this->postJson("/api/users/{$this->user->id}/plan-upgrade", [
            'plan_id' => $semester->id, 'method' => 'cash',
        ], $this->headers)->assertCreated()->json('payment_id');

        $this->update(['status' => 'paid'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('cancelled', $this->upgrade->refresh()->status);
        $this->assertSame('paid', Payment::findOrFail($replacementId)->status);
        $this->assertSame('Semestre', $this->user->refresh()->plan);
        $this->assertSame('2027-03-30', $this->user->membershipEndDate);
        $this->assertSame(9, MemberRealtimeEvent::count());
    }
}
