<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\ReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El panel de Analítica cuenta el dinero que entró, no el que se prometió.
 *
 * LO QUE SE PROTEGE. Vender un plan a plazos crea un `Payment` por el importe
 * COMPLETO con `method = 'receivable'`. Es el contrato —activa la membresía y
 * hace que la venta exista— pero ese dinero no entró. El panel lo sumaba, así
 * que reconocía los 80.000 de un plan en cuanto entraban los primeros 40.000; y
 * los abonos siguientes no sumaban nada, porque llegan por otra tabla.
 *
 * El resultado era un panel que subía de golpe el día de la venta y luego se
 * quedaba plano mientras el socio pagaba de verdad. Caja y Ganancias ya usaban
 * `CashReceipts`; esta pantalla se había quedado atrás, y dos tarjetas del mismo
 * CRM daban cifras distintas para la misma pregunta.
 */
class DashboardCashBasisTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Recepción',
            'email' => 'panel-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    /** Un socio con un plan de 80.000 a plazos: contrato + 60.000 cobrados. */
    private function planAPlazos(): Receivable
    {
        $user = User::create([
            'name' => 'Alejandro Casas',
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'plan' => 'Premium',
            'membershipEndDate' => now()->addDays(30)->toDateString(),
        ]);
        $socio = Member::create([
            'user_id' => $user->id,
            'full_name' => 'Alejandro Casas',
            'document_number' => (string) random_int(10000000, 99999999),
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);

        // El asiento de devengo: el plan entero, como lo escribe la venta.
        Payment::create([
            'user_id' => $user->id, 'amount' => 80000, 'method' => 'receivable',
            'status' => 'paid', 'paid_at' => now(),
        ]);

        app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);
        $cuenta = app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER,
            debtorId: $socio->id,
            type: CashShiftType::GYM,
            concept: 'Plan Premium',
            total: Money::fromAmount(80000),
            actor: $this->cajero,
        );
        app(ReceivableService::class)->settle(
            receivable: $cuenta, amount: Money::fromAmount(60000), method: 'cash', actor: $this->cajero,
        );

        return $cuenta->fresh();
    }

    private function panel(): array
    {
        return $this->getJson('/api/admin/reports/overview', $this->actingAsAdmin($this->cajero))
            ->assertOk()->json();
    }

    public function test_el_panel_no_reconoce_el_contrato_como_ingreso(): void
    {
        $this->planAPlazos();

        $panel = $this->panel();

        // 60.000 entraron. Los 80.000 del contrato NO son ingreso: si lo fueran,
        // el panel diría 140.000 —el plan entero más lo abonado— por un socio
        // que ha pagado 60.000.
        $this->assertEqualsWithDelta(60000, $panel['kpis']['total_revenue'], 0.01);
        $this->assertEqualsWithDelta(60000, $panel['kpis']['period_revenue'], 0.01);
    }

    public function test_cada_abono_sube_el_ingreso_cuando_entra(): void
    {
        $cuenta = $this->planAPlazos();

        app(ReceivableService::class)->settle(
            receivable: $cuenta, amount: Money::fromAmount(20000), method: 'cash', actor: $this->cajero,
        );

        // Y al terminar de pagar, el panel llega a 80.000: ni antes ni de más.
        $this->assertEqualsWithDelta(80000, $this->panel()['kpis']['total_revenue'], 0.01);
    }

    public function test_la_serie_diaria_cuenta_el_abono_el_dia_que_entro(): void
    {
        $this->planAPlazos();

        $serie = collect($this->panel()['revenue_series']);
        $hoy = $serie->firstWhere('date', now()->toDateString());

        $this->assertNotNull($hoy, 'La serie debe llegar hasta hoy.');
        $this->assertEqualsWithDelta(60000, $hoy['revenue'], 1,
            'El abono de hoy tiene que aparecer en el día de hoy, no el día de la venta.');
    }

    public function test_un_cobro_normal_sigue_contando_igual(): void
    {
        // La corrección no puede dejar fuera el dinero que sí entró por la vía
        // de siempre: un cobro de mostrador con método real.
        $user = User::create([
            'name' => 'Contado', 'email' => 'contado-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
        ]);
        Payment::create([
            'user_id' => $user->id, 'amount' => 50000, 'method' => 'cash',
            'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->assertEqualsWithDelta(50000, $this->panel()['kpis']['total_revenue'], 0.01);
    }

    public function test_el_abono_revertido_deja_de_contar(): void
    {
        $cuenta = $this->planAPlazos();
        $abono = $cuenta->payments()->firstOrFail();

        app(ReceivableService::class)->reverse($abono, 'Se digitó el importe equivocado.', $this->cajero);

        $this->assertEqualsWithDelta(0, $this->panel()['kpis']['total_revenue'], 0.01,
            'Anular el abono devuelve el ingreso a cero: ese dinero no se quedó.');
    }
}
