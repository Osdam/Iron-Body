<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LOS KPIs DE DINERO CUENTAN EL DINERO QUE ENTRÓ, Y UNA SOLA VEZ.
 *
 * Dos fallos que ya existían y que la reclasificación de un cobro haría
 * visibles el mismo día:
 *
 *   · «Total recaudado» (Pagos, /admin/payments/stats) sumaba los cobros
 *     fallidos y anulados. Reclasificar anula el cobro equivocado y registra el
 *     abono del piso en el mismo turno: el mismo dinero salía dos veces.
 *   · La portada (/dashboard, `revenue`) sumaba `payments` pagados a secas: el
 *     contrato de un plan a plazos contaba entero el día de la venta, sus
 *     abonos nunca, y el abono de una reclasificación tampoco.
 *
 * La regla es la de CashReceipts, y la portada dice exactamente lo mismo que
 * «Ingresos totales» de Analítica: dos tarjetas del mismo CRM no pueden dar dos
 * cifras distintas para la misma pregunta.
 */
class DashboardRevenueTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00', 'America/Bogota')->utc());
        $this->admin = Admin::create(['name' => 'Dueño', 'email' => 'kpi-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $this->h = $this->actingAsAdmin($this->admin);
        $this->openCashShift($this->admin, CashShiftType::GYM);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_la_portada_y_analitica_dicen_el_mismo_ingreso_total(): void
    {
        $mensual = Plan::create(['name' => 'Mensual', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        $premium = Plan::create(['name' => 'Premium', 'price' => 80000, 'duration_days' => 30, 'active' => true]);

        // Un cobro de mostrador normal: entra.
        $this->cobrar($this->socio(), $mensual);

        // Un plan a plazos de 80.000 con 30.000 de entrada: el contrato no es
        // dinero, el abono sí.
        $this->postJson('/api/admin/receivables/plan', ['user_id' => $this->socio()->id, 'plan_id' => $premium->id,
            'first_payment' => 30000, 'method' => 'cash'], $this->h)->assertCreated();

        // Un cobro devuelto por la vía oficial: entró y salió.
        $devuelto = $this->cobrar($this->socio(), $mensual);
        $this->postJson("/api/payments/{$devuelto->id}/refund", ['reason' => 'Se arrepintió el mismo día', 'method' => 'cash'], $this->h)
            ->assertCreated();

        // Fallido, anulado y pendiente: ninguno es dinero.
        $otro = $this->socio();
        foreach (['failed' => 10000, 'anulado' => 20000, 'pending' => 15000] as $estado => $importe) {
            Payment::create(['user_id' => $otro->id, 'amount' => $importe, 'method' => 'cash', 'status' => $estado]);
        }

        $portada = (float) $this->getJson('/api/dashboard', $this->h)->assertOk()->json('revenue');
        $analitica = (float) $this->getJson('/api/admin/reports/overview', $this->h)->assertOk()->json('kpis.total_revenue');

        // 50.000 del cobro + 30.000 del abono; lo devuelto entra y sale.
        $this->assertEqualsWithDelta(80000, $portada, 0.01);
        $this->assertEqualsWithDelta($analitica, $portada, 0.01, 'la portada y Analítica, la misma cifra');
    }

    public function test_reclasificar_un_cobro_no_cuenta_dos_veces_el_mismo_dinero(): void
    {
        // El caso que lo motivó: 50.000 en efectivo registrados como venta del
        // plan «Comisión de personalizados». Se anula por la vía oficial y se
        // registra lo que era: el piso de la socia, saldado en el mismo turno.
        $plan = Plan::create(['name' => 'Comisión de personalizados', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        $socia = $this->socio();
        $cobro = $this->cobrar($socia, $plan);
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => 'Era el piso de un entrenador'], $this->h)
            ->assertOk();

        $ficha = Member::query()->where('user_id', $socia->id)->firstOrFail();
        $entrenador = Trainer::create(['full_name' => 'Entrenador Externo', 'document' => '70123456',
            'email' => 'externo-'.uniqid().'@ironbody.test', 'status' => 'active']);
        $acuerdoId = $this->postJson('/api/admin/commissions/agreements', ['trainer_id' => $entrenador->id, 'member_id' => $ficha->id,
            'amount' => 50000, 'payer' => 'member'], $this->h)->assertCreated()->json('id');
        $this->postJson("/api/admin/commissions/agreements/{$acuerdoId}/charge", ['period' => '2026-11', 'method' => 'cash'], $this->h)
            ->assertCreated();

        $kpis = $this->getJson('/api/admin/payments/stats', $this->h)->assertOk()->json();
        $this->assertEqualsWithDelta(50000, $kpis['total_amount'], 0.01, 'una vez: el abono, no también el cobro anulado');
        $this->assertEqualsWithDelta(50000, $kpis['paid_amount'], 0.01);
        $this->assertSame(1, $kpis['failed_count'], 'el anulado se sigue contando como anulado');

        $this->assertEqualsWithDelta(50000, (float) $this->getJson('/api/dashboard', $this->h)->json('revenue'), 0.01);
        $this->assertEqualsWithDelta(50000, (float) $this->getJson('/api/admin/reports/overview', $this->h)->json('kpis.total_revenue'), 0.01);
    }

    public function test_total_recaudado_no_suma_fallidos_ni_anulados_en_ninguna_grafia(): void
    {
        $ana = $this->socio();
        $luis = $this->socio();
        $filas = [
            [$ana, 'paid', 100000], [$ana, 'approved', 50000], [$ana, 'pending', 30000],
            [$ana, 'failed', 7000], [$ana, 'cancelled', 10000], [$luis, 'Cancelled', 11000],
            [$luis, 'anulado', 12000], [$luis, 'canceled', 13000], [$luis, 'anulada', 14000],
            // Devuelto: entró, y su salida va por `cash_refunds`. No es fallido ni
            // anulado, así que sigue en el total como hasta ahora.
            [$luis, 'refunded', 5000],
        ];
        foreach ($filas as [$socio, $estado, $importe]) {
            Payment::create(['user_id' => $socio->id, 'amount' => $importe, 'method' => 'cash', 'status' => $estado]);
        }

        $todo = $this->getJson('/api/admin/payments/stats', $this->h)->assertOk()->json();
        $this->assertSame(10, $todo['total_count'], 'los contadores siguen describiendo la tabla entera');
        $this->assertEqualsWithDelta(185000, $todo['total_amount'], 0.01);
        $this->assertEqualsWithDelta(150000, $todo['paid_amount'], 0.01);
        $this->assertEqualsWithDelta(30000, $todo['pending_amount'], 0.01);
        $this->assertSame(6, $todo['failed_count']);

        // Con los filtros de la pantalla, la misma regla.
        $this->assertEqualsWithDelta(0, $this->getJson('/api/admin/payments/stats?status=failed_or_cancelled', $this->h)
            ->json('total_amount'), 0.01);
        $this->assertEqualsWithDelta(5000, $this->getJson("/api/admin/payments/stats?user_id={$luis->id}", $this->h)
            ->json('total_amount'), 0.01);
    }

    // ────────────────────────────────────────────────────────────────────────

    private function socio(): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => 'Socio KPI '.$n, 'email' => 'kpi-'.$n.'-'.uniqid().'@ironbody.test',
            'password' => 'secret', 'document' => (string) (76000000 + $n), 'status' => 'active']);
        Member::create(['user_id' => $user->id, 'full_name' => $user->name, 'email' => $user->email,
            'document_number' => (string) (76000000 + $n), 'access_hash' => 'tok-kpi-'.$n.'-'.uniqid(),
            'status' => Member::STATUS_ACTIVE]);

        return $user->refresh();
    }

    /** Cobro de mostrador por el endpoint real. */
    private function cobrar(User $socio, Plan $plan): Payment
    {
        $id = $this->postJson('/api/payments', ['user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => $plan->price,
            'method' => 'cash', 'status' => 'paid'], $this->h)->assertCreated()->json('id');

        return Payment::findOrFail($id);
    }
}
