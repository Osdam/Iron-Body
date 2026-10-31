<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CashRefund;
use App\Models\CashShift;
use App\Models\ElectronicInvoice;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\Plan;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\CashShiftTotalsService;
use App\Services\Payments\MembershipPeriod;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcciones administrativas de la Caja de Gimnasio y de Créditos
 * (docs/ops/CORRECCIONES-ADMINISTRATIVAS.md).
 *
 * Cada prueba que fija un bug corregido dice en su comentario qué hacía 2425eef.
 */
class CorreccionesCajaGimnasioTest extends TestCase
{
    use RefreshDatabase;

    private Admin $super;

    private const MOTIVO = 'Corrección registrada en la prueba';

    protected function setUp(): void
    {
        parent::setUp();

        // Mediodía en Bogotá, como instante UTC (ver relojes de prueba en UTC).
        Carbon::setTestNow(Carbon::parse('2026-10-27 11:30', 'America/Bogota')->utc());

        $this->super = Admin::create([
            'name' => 'Fredy Supervisión',
            'email' => 'correcciones-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        app(CashShiftService::class)->open($this->super, CashShiftType::GYM);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function h(): array
    {
        return $this->actingAsAdmin($this->super);
    }

    private function socio(): User
    {
        $user = User::create([
            'name' => 'Socio '.uniqid(),
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        Member::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'document_number' => (string) random_int(10000000, 99999999),
            'is_staff' => false,
        ]);

        return $user;
    }

    private function mensual(): Plan
    {
        return Plan::firstOrCreate(['name' => 'Mensual'], ['price' => 80000, 'duration_days' => 30, 'active' => true]);
    }

    private function cobrar(?User $socio = null, array $extra = []): Payment
    {
        $socio ??= $this->socio();
        $id = $this->postJson('/api/payments', array_merge([
            'user_id' => $socio->id, 'plan_id' => $this->mensual()->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid',
        ], $extra), $this->h())->assertStatus(201)->json('id');

        return Payment::findOrFail($id);
    }

    private function turnoGym(): CashShift
    {
        return CashShift::currentOfType(CashShiftType::GYM);
    }

    private function totales(CashShift $turno): array
    {
        return app(CashShiftTotalsService::class)->for($turno->fresh());
    }

    private function hoy(): string
    {
        return MembershipPeriod::today()->toDateString();
    }

    // ── A · Créditos ────────────────────────────────────────────────────────

    /**
     * CASO ADCEL, sintético. Plan de 624.000 a plazos, abono real de 100.000
     * por transferencia y un abono FALSO de 524.000 en efectivo que no entró.
     * La corrección es anular el abono falso: el saldo vuelve a 524.000, el
     * efectivo esperado del turno baja 524.000, el abono real queda intacto, el
     * historial lo cuenta todo y la membresía no se toca.
     */
    public function test_escenario_adcel_se_corrige_anulando_el_abono_falso(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Anualidad', 'price' => 624000, 'duration_days' => 365, 'active' => true]);
        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'first_payment' => 100000, 'method' => 'transfer',
        ], $this->h())->assertStatus(201);
        $deuda = Receivable::findOrFail($r->json('data.id'));
        $cobro = Payment::findOrFail($r->json('payment_id'));
        $real = ReceivablePayment::findOrFail($r->json('first_payment.id'));
        $finMembresia = $socio->fresh()->membership_end_date;
        $turno = $this->turnoGym();

        $falso = $this->postJson("/api/admin/receivables/{$deuda->id}/payments", [
            'amount' => 524000, 'method' => 'cash',
        ], $this->h())->assertStatus(201)->json('data.id');
        $this->assertSame(Receivable::STATUS_PAID, $deuda->fresh()->status);
        $this->assertSame('524000.00', $this->totales($turno)['expected_cash']);

        $this->postJson("/api/admin/receivables/payments/{$falso}/reverse", [
            'reason' => 'El abono de 524.000 en efectivo no entró: era una nota de pagos futuros',
        ], $this->h())->assertOk();

        $deuda->refresh();
        $this->assertSame('524000.00', $deuda->balance()->toDatabase());
        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $deuda->status);
        $this->assertSame('0.00', $this->totales($turno)['expected_cash'], 'el efectivo esperado baja 524.000');
        $this->assertSame(ReceivablePayment::STATUS_APPLIED, $real->fresh()->status, 'el abono real no se toca');
        $this->assertSame('paid', $cobro->fresh()->status);
        $this->assertSame($finMembresia, $socio->fresh()->membership_end_date, 'la membresía no se toca');

        // El detalle enseña quién lo anuló y el historial cuenta las cuatro cosas.
        $abonos = collect($this->getJson("/api/admin/receivables/{$deuda->id}", $this->h())->assertOk()->json('data.payments'))->keyBy('id');
        $this->assertSame('Fredy Supervisión', $abonos[$falso]['reversed_by_name']);
        $this->assertSame(['id' => $turno->id, 'status' => 'open'], $abonos[$falso]['cash_shift']);
        $this->assertArrayHasKey('receipt_number', $abonos[$falso]);
        $historia = $this->getJson("/api/admin/receivables/{$deuda->id}/history", $this->h())->assertOk()->json('data');
        $this->assertSame(
            [['cuenta por cobrar', 'create'], ['abono', 'create'], ['abono', 'create'], ['abono', 'status']],
            array_map(fn ($f) => [$f['entity'], $f['action']], $historia),
        );
        $this->assertStringContainsString('no entró', (string) end($historia)['reason']);
    }

    /** Un abono que liquida por error la deuda se anula y la deuda vuelve a cobrarse. */
    public function test_liquidacion_accidental_se_deshace_anulando_el_abono(): void
    {
        $socio = $this->socio();
        $deuda = Receivable::findOrFail($this->postJson('/api/admin/receivables', [
            'member_id' => Member::where('user_id', $socio->id)->value('id'), 'type' => 'gym',
            'concept' => 'Saldo acordado', 'total_amount' => 100000,
        ], $this->h())->assertStatus(201)->json('data.id'));

        $abono = $this->postJson("/api/admin/receivables/{$deuda->id}/payments", [
            'amount' => 100000, 'method' => 'cash',
        ], $this->h())->assertStatus(201)->json('data.id');
        $this->assertFalse($this->getJson("/api/admin/receivables/{$deuda->id}", $this->h())->json('data.can_cancel'));

        $this->postJson("/api/admin/receivables/payments/{$abono}/reverse", ['reason' => 'Se marcó pagado sin recibir el dinero'], $this->h())->assertOk();

        $r = $this->getJson("/api/admin/receivables/{$deuda->id}", $this->h())->assertOk();
        $this->assertSame(Receivable::STATUS_PENDING, $r->json('data.status'));
        $this->assertEquals(100000, $r->json('data.balance'));
        $this->assertTrue($r->json('data.can_cancel'));
    }

    /**
     * La deuda expone de qué cobro nace, y `can_reopen` es falso si ese cobro ya
     * no está pagado. En 2425eef `can_reopen` era solo «está anulada»: el CRM
     * ofrecía un botón que la API rechazaba con receivable_source_cancelled.
     */
    public function test_can_reopen_es_falso_si_el_cobro_de_origen_no_esta_pagado(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Trimestre', 'price' => 240000, 'duration_days' => 90, 'active' => true]);
        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'first_payment' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);
        $deudaId = $r->json('data.id');
        $cobroId = $r->json('payment_id');

        $this->postJson("/api/admin/receivables/{$deudaId}/cancel", ['reason' => 'Venta registrada al socio equivocado'], $this->h())->assertOk();
        $d = $this->getJson("/api/admin/receivables/{$deudaId}", $this->h())->assertOk()->json('data');
        $this->assertTrue($d['can_reopen']);
        $this->assertSame(['type' => 'payment', 'id' => $cobroId, 'label' => "Cobro #{$cobroId}", 'status' => 'paid'], $d['source']);

        $this->patchJson("/api/payments/{$cobroId}", ['status' => 'cancelled', 'reason' => self::MOTIVO], $this->h())->assertOk();

        $this->assertFalse($this->getJson("/api/admin/receivables/{$deudaId}", $this->h())->json('data.can_reopen'));
        $this->postJson("/api/admin/receivables/{$deudaId}/reopen", ['reason' => 'Intento de reabrirla igual'], $this->h())
            ->assertStatus(422)->assertJsonPath('code', 'receivable_source_cancelled');
    }

    /**
     * Corregir el medio de un abono mueve el dinero de medio en el arqueo; un
     * turno cerrado exige además administrar la caja. Idempotente.
     */
    public function test_corregir_el_medio_de_un_abono(): void
    {
        $socio = $this->socio();
        $deuda = $this->postJson('/api/admin/receivables', [
            'member_id' => Member::where('user_id', $socio->id)->value('id'), 'type' => 'gym',
            'concept' => 'Saldo', 'total_amount' => 50000,
        ], $this->h())->json('data.id');
        $abono = $this->postJson("/api/admin/receivables/{$deuda}/payments", ['amount' => 30000, 'method' => 'cash'], $this->h())->json('data.id');
        $turno = $this->turnoGym();
        $this->assertSame('30000.00', $this->totales($turno)['expected_cash']);

        $this->postJson("/api/admin/receivables/payments/{$abono}/method", ['method' => 'transfer', 'reason' => 'Fue transferencia'], $this->h())
            ->assertOk()->assertJsonPath('already', false)->assertJsonPath('data.method', 'transfer');
        $t = $this->totales($turno);
        $this->assertSame('0.00', $t['expected_cash']);
        $this->assertSame('30000.00', $t['transfer_total']);
        $this->postJson("/api/admin/receivables/payments/{$abono}/method", ['method' => 'transfer', 'reason' => 'Fue transferencia'], $this->h())
            ->assertOk()->assertJsonPath('already', true);
        $this->assertSame(1, AuditLog::where('entity', 'abono')->where('entity_id', (string) $abono)->where('action', 'update')->count());

        // Turno cerrado: con receivables.manage solo, 403; con la caja, sí.
        app(CashShiftService::class)->close($this->super, CashShiftType::GYM);
        $soloCreditos = $this->adminWithPermissions(['receivables.view', 'receivables.manage']);
        $this->postJson("/api/admin/receivables/payments/{$abono}/method", ['method' => 'cash', 'reason' => 'Era efectivo'], $soloCreditos)
            ->assertStatus(403)->assertJsonPath('code', 'closed_shift_requires_manage');
        $conCaja = $this->adminWithPermissions(['receivables.view', 'receivables.manage', 'cash.gym.manage']);
        $this->postJson("/api/admin/receivables/payments/{$abono}/method", ['method' => 'cash', 'reason' => 'Era efectivo'], $conCaja)->assertOk();
        $this->assertSame('30000.00', (string) $turno->fresh()->transfer_total, 'el cierre firmado no se reescribe');

        // Un abono anulado no cambia de medio.
        $this->postJson("/api/admin/receivables/payments/{$abono}/reverse", ['reason' => 'Anulado para la prueba'], $this->h())->assertOk();
        $this->postJson("/api/admin/receivables/payments/{$abono}/method", ['method' => 'card', 'reason' => 'Era tarjeta'], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'receivable_payment_not_applied');
    }

    // ── B · Caja de Gimnasio: anular ────────────────────────────────────────

    /**
     * Anular exige motivo, devuelve los días DENTRO de la transacción y es
     * idempotente. En 2425eef la segunda anulación volvía a escribir traza
     * (`paymentUpdated`) y llamaba otra vez a revert() fuera de la transacción.
     */
    public function test_anular_un_cobro_exige_motivo_y_es_idempotente(): void
    {
        $socio = $this->socio();
        $cobro = $this->cobrar($socio);
        $this->assertSame(MembershipPeriod::today()->addDays(30)->toDateString(), $socio->fresh()->membership_end_date);

        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled'], $this->h())
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('paid', $cobro->fresh()->status);

        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => self::MOTIVO], $this->h())
            ->assertOk()->assertJsonMissingPath('already');
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => self::MOTIVO], $this->h())
            ->assertOk()->assertJsonPath('already', true);

        $this->assertSame('cancelled', $cobro->fresh()->status);
        $this->assertSame($this->hoy(), $socio->fresh()->membership_end_date, 'los días vuelven una vez');
        $trazas = AuditLog::where('entity', 'pago')->where('entity_id', (string) $cobro->id)->where('action', '!=', 'create')->get();
        $this->assertCount(1, $trazas, 'una sola traza de anulación');
        $this->assertSame(self::MOTIVO, $trazas[0]->metadata['reason']);
        $this->assertSame([['field' => 'status', 'before' => 'paid', 'after' => 'cancelled']], $trazas[0]->changes);
        $this->assertSame('0.00', $this->totales($this->turnoGym())['expected_cash'], 'deja de contar en el turno abierto');
    }

    /** Un cobro de pasarela es dinero real: no se anula (409). En 2425eef se anulaba. */
    public function test_un_cobro_de_pasarela_no_se_anula(): void
    {
        $wompi = Payment::create([
            'user_id' => $this->socio()->id, 'amount' => 80000, 'method' => 'wompi',
            'origin' => 'gateway', 'status' => 'paid', 'paid_at' => now(), 'reference' => 'WMP-9',
        ]);

        $this->patchJson("/api/payments/{$wompi->id}", ['status' => 'cancelled', 'reason' => self::MOTIVO], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'gateway_payment');
        $this->assertSame('paid', $wompi->fresh()->status);
    }

    /**
     * Factura validada → 409 con su id; con la nota crédito validada, adelante.
     * En proceso → 409. En 2425eef nadie miraba la factura al anular.
     */
    public function test_la_factura_validada_bloquea_anular_hasta_la_nota_credito(): void
    {
        $cobro = $this->cobrar();
        $factura = ElectronicInvoice::updateOrCreate(
            ['source_type' => $cobro->getMorphClass(), 'source_id' => $cobro->id, 'type' => 'invoice'],
            ['status' => 'validated', 'full_number' => 'IBFE10', 'total' => '80000.00', 'cufe' => 'cufe-10'],
        );

        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => self::MOTIVO], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'invoice_validated')->assertJsonPath('invoice_id', $factura->id);

        $nota = ElectronicInvoice::create([
            'source_type' => $cobro->getMorphClass(), 'source_id' => $cobro->id, 'type' => 'credit_note',
            'status' => 'credit_note_processing', 'total' => '80000.00', 'references_invoice_id' => $factura->id,
        ]);
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => self::MOTIVO], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'invoice_processing');

        $nota->update(['status' => 'credit_note_validated']);
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => self::MOTIVO], $this->h())->assertOk();
    }

    /**
     * Turno cerrado: anular exige además `cash.gym.manage`. El cierre firmado no
     * cambia y el informe lista la corrección como posterior al cierre.
     */
    public function test_turno_cerrado_exige_administrar_la_caja_y_el_cierre_no_cambia(): void
    {
        $cobro = $this->cobrar();
        $turno = $this->turnoGym();
        app(CashShiftService::class)->close($this->super, CashShiftType::GYM);
        $congelado = (string) $turno->fresh()->expected_amount;
        $this->assertSame('80000.00', $congelado);

        $sinCaja = $this->adminWithPermissions(['payments.view', 'payments.cancel']);
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => self::MOTIVO], $sinCaja)
            ->assertStatus(403)->assertJsonPath('code', 'closed_shift_requires_manage')
            ->assertJsonPath('required_permission', 'cash.gym.manage');

        $conCaja = $this->adminWithPermissions(['payments.view', 'payments.cancel', 'cash.gym.manage', 'cash.gym.view']);
        $this->travel(5)->minutes();
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => self::MOTIVO], $conCaja)->assertOk();

        $this->assertSame($congelado, (string) $turno->fresh()->expected_amount, 'el cierre firmado no se reescribe');
        $informe = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->h())->assertOk();
        $posteriores = $informe->json('post_close_corrections');
        $this->assertCount(1, $posteriores);
        $this->assertSame('pago', $posteriores[0]['entity']);
        $this->assertSame(self::MOTIVO, $posteriores[0]['reason']);
    }

    /**
     * De «mixto» a efectivo sin desglose: las partes viejas se borran en la
     * misma transacción. En 2425eef se quedaban y el arqueo las seguía sumando
     * además del cobro: 80.000 en efectivo + 20.000 + 60.000 de partes.
     */
    public function test_pasar_de_mixto_a_un_medio_simple_borra_las_partes(): void
    {
        $cobro = $this->cobrar(null, ['method' => 'mixed', 'splits' => [
            ['method' => 'cash', 'amount' => 20000], ['method' => 'card', 'amount' => 60000],
        ]]);
        $turno = $this->turnoGym();
        $this->assertSame('20000.00', $this->totales($turno)['cash_total']);

        $this->patchJson("/api/payments/{$cobro->id}", ['method' => 'cash', 'reason' => 'Pagó todo en efectivo'], $this->h())->assertOk();

        $this->assertSame(0, PaymentSplit::where('payment_id', $cobro->id)->count());
        $t = $this->totales($turno);
        $this->assertSame('80000.00', $t['cash_total']);
        $this->assertSame('0.00', $t['card_total']);
        $this->assertSame('80000.00', $t['gross_total']);

        $traza = AuditLog::where('entity', 'pago')->where('entity_id', (string) $cobro->id)->where('action', 'update')->latest('id')->firstOrFail();
        $campos = collect($traza->changes)->keyBy('field');
        $this->assertSame('mixed', $campos['method']['before']);
        $this->assertSame('cash', $campos['method']['after']);
        $this->assertCount(2, $campos['splits']['before']);
        $this->assertSame([], $campos['splits']['after']);
    }

    /** Cambiar el importe de un cobro repartido exige partes que cuadren. */
    public function test_cambiar_el_importe_de_un_mixto_exige_partes(): void
    {
        $cobro = $this->cobrar(null, ['method' => 'mixed', 'splits' => [
            ['method' => 'cash', 'amount' => 20000], ['method' => 'card', 'amount' => 60000],
        ]]);

        $this->patchJson("/api/payments/{$cobro->id}", ['amount' => 70000, 'reason' => 'Descuento olvidado'], $this->h())
            ->assertStatus(422)->assertJsonValidationErrors('splits');
        $this->patchJson("/api/payments/{$cobro->id}", ['amount' => 70000, 'reason' => 'Descuento olvidado', 'splits' => [
            ['method' => 'cash', 'amount' => 10000], ['method' => 'card', 'amount' => 60000],
        ]], $this->h())->assertOk();
        $this->assertEquals(70000, $cobro->fresh()->amount);
    }

    /**
     * `payments.correct` es una llave nueva: el Administrador no la trae y sin
     * ella no se cambia ni medio ni importe (403), aunque pueda anular.
     */
    public function test_corregir_medio_o_importe_exige_payments_correct(): void
    {
        $cobro = $this->cobrar();
        $administrador = $this->adminAs(Admin::ROLE_ADMINISTRADOR);

        $this->patchJson("/api/payments/{$cobro->id}", ['method' => 'transfer', 'reason' => 'Fue transferencia'], $administrador)
            ->assertStatus(403)->assertJsonPath('required_permission', 'payments.correct');
        $this->postJson("/api/payments/{$cobro->id}/method", ['method' => 'transfer', 'reason' => 'Fue transferencia'], $administrador)
            ->assertStatus(403);
        // Editar la referencia no es corregir dinero: sigue con payments.cancel.
        $this->patchJson("/api/payments/{$cobro->id}", ['reference' => 'REC-77', 'method' => 'cash', 'amount' => 80000], $administrador)->assertOk();

        $this->postJson("/api/payments/{$cobro->id}/method", ['method' => 'transfer', 'reason' => 'Fue transferencia'], $this->h())
            ->assertOk()->assertJsonPath('already', false)->assertJsonPath('data.method', 'transfer');
        $this->postJson("/api/payments/{$cobro->id}/method", ['method' => 'transfer', 'reason' => 'Fue transferencia'], $this->h())
            ->assertOk()->assertJsonPath('already', true);
    }

    /**
     * Aplicar el periodo de un cobro dos veces lo encadenaba dos veces (un mes
     * pagado, dos de membresía). Ahora apply() es idempotente bajo bloqueo. En
     * 2425eef la segunda llamada sumaba otros 30 días.
     */
    public function test_aplicar_el_periodo_dos_veces_no_lo_duplica(): void
    {
        $socio = $this->socio();
        $cobro = $this->cobrar($socio, ['status' => 'pending']);
        $this->assertNull($socio->fresh()->membership_end_date);

        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'paid'], $this->h())->assertOk();
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'paid'], $this->h())->assertOk();
        $fin = MembershipPeriod::today()->addDays(30)->toDateString();
        $this->assertSame($fin, $socio->fresh()->membership_end_date);

        app(MembershipPeriod::class)->apply(Payment::findOrFail($cobro->id));
        $this->assertSame($fin, $socio->fresh()->membership_end_date, 'reaplicar el mismo cobro no suma días');
    }

    // ── B · Devolver ────────────────────────────────────────────────────────

    /**
     * Devolver: `payments.refund` (nuevo, no por defecto), el cobro pasa a
     * `refunded`, se quitan los días y el dinero sale del turno ABIERTO. El
     * cobro sigue contando en su turno; la salida se resta. Idempotente.
     */
    public function test_devolver_un_cobro(): void
    {
        $socio = $this->socio();
        $cobro = $this->cobrar($socio);
        $turno = $this->turnoGym();

        $this->postJson("/api/payments/{$cobro->id}/refund", ['reason' => 'El socio se mudó de ciudad'], $this->adminAs(Admin::ROLE_ADMINISTRADOR))
            ->assertStatus(403);

        $r = $this->postJson("/api/payments/{$cobro->id}/refund", [
            'reason' => 'El socio se mudó de ciudad', 'client_request_id' => 'dev-1',
        ], $this->h())->assertStatus(201)->assertJsonPath('data.status', 'refunded');
        $this->assertSame($turno->id, $r->json('refund.cash_shift_id'));
        $this->assertEquals(80000, $r->json('refund.amount'));
        $this->assertSame('cash', $r->json('refund.method'));
        $this->assertSame($this->hoy(), $socio->fresh()->membership_end_date, 'sin dinero no hay plan');

        // Mismo intento otra vez: la misma devolución; otra petición: ya estaba.
        $this->postJson("/api/payments/{$cobro->id}/refund", ['reason' => 'El socio se mudó de ciudad', 'client_request_id' => 'dev-1'], $this->h())
            ->assertOk()->assertJsonPath('idempotent_replay', true)->assertJsonPath('refund.id', $r->json('refund.id'));
        $this->postJson("/api/payments/{$cobro->id}/refund", ['reason' => 'Doble clic'], $this->h())
            ->assertOk()->assertJsonPath('already', true);
        $this->assertSame(1, CashRefund::count());

        // El cobro sigue contando (entró) y la devolución se resta (salió).
        $t = $this->totales($turno);
        $this->assertSame('0.00', $t['expected_cash']);
        $this->assertSame('80000.00', $t['refunds_total']);
        $this->assertSame(1, $t['operations_count']);
        $informe = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->h())->assertOk();
        $this->assertCount(1, $informe->json('refunds'));
        $this->assertTrue($informe->json('consistency.matches'));

        // Un cobro devuelto no se anula después (contaría dos veces).
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => self::MOTIVO], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'already_refunded');
    }

    /** La devolución sale del turno ABIERTO: sin turno, 409. */
    public function test_devolver_sin_turno_abierto_es_409(): void
    {
        $cobro = $this->cobrar();
        app(CashShiftService::class)->close($this->super, CashShiftType::GYM);

        $this->postJson("/api/payments/{$cobro->id}/refund", ['reason' => 'El socio se mudó'], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'no_open_shift');
        $this->assertSame('paid', $cobro->fresh()->status);
    }

    /** Pasarela: solo con la referencia de la devolución en su panel, y sin egreso de caja. */
    public function test_devolver_un_cobro_de_pasarela_exige_referencia_y_no_sale_de_caja(): void
    {
        $wompi = Payment::create([
            'user_id' => $this->socio()->id, 'amount' => 80000, 'method' => 'wompi',
            'origin' => 'gateway', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->postJson("/api/payments/{$wompi->id}/refund", ['reason' => 'Cobro duplicado en la app'], $this->h())
            ->assertStatus(422)->assertJsonValidationErrors('external_reference');
        $r = $this->postJson("/api/payments/{$wompi->id}/refund", [
            'reason' => 'Cobro duplicado en la app', 'external_reference' => 'WOMPI-RF-1',
        ], $this->h())->assertStatus(201);

        $this->assertNull($r->json('refund.cash_shift_id'));
        $this->assertSame('WOMPI-RF-1', $r->json('refund.external_reference'));
        $this->assertSame('0.00', $this->totales($this->turnoGym())['refunds_total']);
    }

    /** El devengo de un plan a plazos no se devuelve: se hace por abonos (409). */
    public function test_el_devengo_de_un_plan_a_plazos_se_devuelve_por_abonos(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Anualidad', 'price' => 624000, 'duration_days' => 365, 'active' => true]);
        $cobro = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'first_payment' => 100000, 'method' => 'cash',
        ], $this->h())->json('payment_id');

        $this->postJson("/api/payments/{$cobro}/refund", ['reason' => 'Se arrepintió'], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'use_receivable_flow');
        $this->patchJson("/api/payments/{$cobro}", ['status' => 'refunded'], $this->h())
            ->assertStatus(422)->assertJsonPath('code', 'use_receivable_flow');
    }

    /** `refunded` no se pone a mano por PATCH: 422, se usa la devolución. */
    public function test_marcar_devuelto_por_patch_es_422(): void
    {
        $cobro = $this->cobrar();

        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'refunded', 'reason' => self::MOTIVO], $this->h())
            ->assertStatus(422)->assertJsonPath('code', 'use_refund_endpoint');
        $this->assertSame('paid', $cobro->fresh()->status);
    }

    // ── B · Alta idempotente, vista previa, enlaces e historial ─────────────

    /** El mismo `client_request_id` dos veces es UN cobro y UN periodo. */
    public function test_alta_de_cobro_con_client_request_id_repetido(): void
    {
        $socio = $this->socio();
        $cuerpo = ['user_id' => $socio->id, 'plan_id' => $this->mensual()->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid', 'client_request_id' => 'cobro-abc'];

        $uno = $this->postJson('/api/payments', $cuerpo, $this->h())->assertStatus(201)->json('id');
        $dos = $this->postJson('/api/payments', $cuerpo, $this->h())->assertOk()->assertJsonPath('idempotent_replay', true)->json('id');

        $this->assertSame($uno, $dos);
        $this->assertSame(1, Payment::where('user_id', $socio->id)->count());
        $this->assertSame(MembershipPeriod::today()->addDays(30)->toDateString(), $socio->fresh()->membership_end_date);
    }

    /** La vista previa describe bloqueos y efectos sin escribir nada. */
    public function test_la_vista_previa_no_escribe_y_explica(): void
    {
        $socio = $this->socio();
        $cobro = $this->cobrar($socio);
        $antes = [$socio->fresh()->membership_end_date, $cobro->fresh()->getAttributes(), AuditLog::count()];

        $p = $this->getJson("/api/payments/{$cobro->id}/correction-preview?action=cancel", $this->h())->assertOk()->json();
        $this->assertTrue($p['allowed']);
        $this->assertSame(30, $p['effects']['membership']['days_removed']);
        $this->assertSame($this->hoy(), $p['effects']['membership']['end_after']);
        $this->assertSame('open', $p['effects']['cash_shift']['status']);
        $this->assertFalse($p['effects']['gateway']);

        $p = $this->getJson("/api/payments/{$cobro->id}/correction-preview?action=refund", $this->adminAs(Admin::ROLE_ADMINISTRADOR))->assertOk()->json();
        $this->assertFalse($p['allowed']);
        $this->assertSame('forbidden', $p['blockers'][0]['code']);

        $this->assertSame($antes, [$socio->fresh()->membership_end_date, $cobro->fresh()->getAttributes(), AuditLog::count()]);
    }

    /** El detalle enlaza turno, devoluciones y posibles duplicados (mismo socio, importe y plan, < 10 min). */
    public function test_el_detalle_enlaza_y_avisa_de_duplicados(): void
    {
        $socio = $this->socio();
        $uno = $this->cobrar($socio);
        $this->travel(3)->minutes();
        $dos = $this->cobrar($socio);

        $links = $this->getJson("/api/payments/{$dos->id}", $this->h())->assertOk()->json('links');
        $this->assertSame($this->turnoGym()->id, $links['cash_shift']['id']);
        $this->assertSame([$uno->id], array_column($links['possible_duplicates'], 'id'));
        $this->assertNull($links['receivable']);
        $this->assertSame([], $links['refunds']);

        $this->patchJson("/api/payments/{$uno->id}", ['status' => 'cancelled', 'reason' => 'Duplicado'], $this->h())->assertOk();
        $this->assertSame([], $this->getJson("/api/payments/{$dos->id}", $this->h())->json('links.possible_duplicates'));

        $historia = $this->getJson("/api/payments/{$uno->id}/history", $this->h())->assertOk()->json('data');
        $this->assertSame(['pago', 'membership', 'pago'], array_column($historia, 'entity'));
        $this->assertSame(['create', 'update', 'status'], array_column($historia, 'action'),
            'alta, días devueltos y anulación, en el orden en que se escribieron');
    }

    // ── Permisos y etiquetas ────────────────────────────────────────────────

    public function test_permisos_nuevos_no_llegan_por_defecto_salvo_a_super_admin(): void
    {
        $nuevos = [CrmPermission::PAYMENTS_CORRECT, CrmPermission::PAYMENTS_REFUND, CrmPermission::CASH_PRODUCTS_REFUND];
        $porRol = CrmPermission::byRole();

        foreach ($nuevos as $p) {
            $this->assertContains($p, PermissionCatalog::all(), "{$p} está en la matriz");
            $this->assertContains($p, $porRol[Admin::ROLE_SUPER_ADMIN]);
            foreach ([Admin::ROLE_ADMINISTRADOR, Admin::ROLE_RECEPCION, Admin::ROLE_ADMINISTRATIVO, CrmPermission::ROLE_ENTRENADOR] as $rol) {
                $this->assertNotContains($p, $porRol[$rol], "{$rol} no trae {$p} por defecto");
            }
            $this->assertNotContains($p, CrmPermission::readOnly());
        }

        $dominios = collect(PermissionCatalog::domains())->keyBy('key');
        $this->assertSame('Caja de Gimnasio', $dominios['payments']['label']);
        $this->assertSame('Caja de Cafetería', $dominios['cash.products']['label']);
    }
}
