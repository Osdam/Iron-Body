<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\CashShiftTotalsService;
use App\Services\Payments\MembershipPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * COBRO MIXTO DONDE NO LO HABÍA: la caja de productos y la mejora de plan.
 *
 * «Te pago 20.000 en efectivo y 30.000 con tarjeta» ya se podía registrar en
 * Pagos —{@see MixedPaymentTest} cubre esa parte— pero no al vender un
 * producto ni al cobrar la diferencia de una mejora. Lo que se hacía era
 * elegir UN medio y perder el otro: el cajón cuadraba en total pero NO por
 * medio, así que el arqueo daba por faltante un efectivo que sí estaba.
 *
 * La regla es la misma en los tres sitios, y por eso vive en una sola clase:
 * el cobro cuenta por sus PARTES, no por su medio, y la suma se compara en
 * centavos enteros contra el total que calcula el servidor.
 */
class MixedPaymentInSalesAndUpgradesTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Recepción',
            'email' => 'mixto2-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        $this->h = $this->actingAsAdmin($this->cajero);
    }

    private function producto(float $precio = 50000): Product
    {
        $iva = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => 'Proteína', 'category' => 'Suplementos',
            'sale_price' => $precio, 'cost_price' => $precio / 2,
            'stock' => 50, 'min_stock' => 0, 'active' => true, 'visible_in_app' => true,
            'tax_rate_id' => $iva->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    /** @return array{0: User, 1: Plan, 2: Plan} */
    private function socioConMensual(): array
    {
        $mensual = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $total = Plan::create(['name' => 'Total Access', 'price' => 150000, 'duration_days' => 30, 'active' => true]);
        $inicio = MembershipPeriod::today()->subDays(7);

        $user = User::create([
            'name' => 'Socio Mensual',
            'email' => 'mix-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
            'plan' => $mensual->name,
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $inicio->addDays(30)->toDateString(),
        ]);

        return [$user, $mensual, $total];
    }

    // ── Caja de productos ───────────────────────────────────────────────────

    public function test_una_venta_se_reparte_entre_dos_medios(): void
    {
        $turno = app(CashShiftService::class)->open($this->cajero, CashShiftType::PRODUCTS);
        $p = $this->producto(50000);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'splits' => [
                ['method' => 'cash', 'amount' => 20000],
                ['method' => 'card', 'amount' => 30000],
            ],
        ], $this->h)->assertStatus(201);

        $venta = ProductSale::firstOrFail();
        $this->assertSame('mixed', $venta->payment_method);
        $this->assertSame(2, $venta->splits()->count());

        // EL ARQUEO ATRIBUYE CADA PARTE A SU MEDIO. Es lo que estaba roto:
        // antes los 50.000 enteros aparecían bajo un solo medio.
        $totales = app(CashShiftTotalsService::class)->for($turno->refresh());
        $this->assertSame('20000.00', $totales['cash_total']);
        $this->assertSame('30000.00', $totales['card_total']);
        $this->assertSame('50000.00', $totales['gross_total']);
    }

    public function test_un_desglose_que_no_cuadra_con_la_venta_se_rechaza(): void
    {
        app(CashShiftService::class)->open($this->cajero, CashShiftType::PRODUCTS);
        $p = $this->producto(50000);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'splits' => [
                ['method' => 'cash', 'amount' => 20000],
                ['method' => 'card', 'amount' => 20000],
            ],
        ], $this->h)->assertStatus(422);

        $this->assertSame(0, ProductSale::count());
    }

    public function test_una_venta_de_un_solo_medio_sigue_contando_igual(): void
    {
        $turno = app(CashShiftService::class)->open($this->cajero, CashShiftType::PRODUCTS);
        $p = $this->producto(50000);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ], $this->h)->assertStatus(201);

        $totales = app(CashShiftTotalsService::class)->for($turno->refresh());
        $this->assertSame('50000.00', $totales['cash_total']);
        $this->assertSame('0.00', $totales['card_total']);
    }

    /**
     * `receivable` marca el devengo de un plan a plazos, no un medio: como
     * parte de un desglose caería en «Otros» y contaría como dinero que entró,
     * sin deuda que cobrar.
     */
    public function test_una_parte_del_desglose_no_puede_ser_receivable(): void
    {
        app(CashShiftService::class)->open($this->cajero, CashShiftType::PRODUCTS);
        $p = $this->producto(50000);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'splits' => [
                ['method' => 'cash', 'amount' => 20000],
                ['method' => 'Receivable', 'amount' => 30000],
            ],
        ], $this->h)->assertStatus(422)->assertJsonValidationErrors('splits.1.method');

        $this->assertSame(0, ProductSale::count());
    }

    // ── Mejora de plan ──────────────────────────────────────────────────────

    public function test_la_diferencia_de_una_mejora_no_se_reparte_con_receivable(): void
    {
        app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);
        [$user, $mensual, $total] = $this->socioConMensual();

        $this->postJson("/api/users/{$user->id}/plan-upgrade", [
            'plan_id' => $total->id,
            'method' => 'cash',
            'splits' => [
                ['method' => 'cash', 'amount' => 50000],
                ['method' => 'receivable', 'amount' => 20000],
            ],
        ], $this->h)->assertStatus(422)->assertJsonValidationErrors('splits.1.method');

        $this->assertSame($mensual->name, $user->fresh()->plan);
    }

    public function test_la_diferencia_de_una_mejora_tambien_se_reparte(): void
    {
        $turno = app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);
        [$user, , $total] = $this->socioConMensual();

        $this->postJson("/api/users/{$user->id}/plan-upgrade", [
            'plan_id' => $total->id,
            'method' => 'cash',
            // La diferencia son 70.000.
            'splits' => [
                ['method' => 'cash', 'amount' => 50000],
                ['method' => 'transfer', 'amount' => 20000],
            ],
        ], $this->h)->assertStatus(201);

        $totales = app(CashShiftTotalsService::class)->for($turno->refresh());
        $this->assertSame('50000.00', $totales['cash_total']);
        $this->assertSame('20000.00', $totales['transfer_total']);
        $this->assertSame('70000.00', $totales['gross_total']);
    }

    public function test_el_desglose_de_una_mejora_se_mide_contra_lo_que_calcula_el_servidor(): void
    {
        // Si se midiera contra un total enviado, un desglose «cuadrado» podría
        // acabar cobrando otra cosa.
        app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);
        [$user, , $total] = $this->socioConMensual();

        $this->postJson("/api/users/{$user->id}/plan-upgrade", [
            'plan_id' => $total->id,
            'method' => 'cash',
            'splits' => [
                ['method' => 'cash', 'amount' => 10000],
                ['method' => 'card', 'amount' => 10000],
            ],
        ], $this->h)->assertStatus(422);

        $this->assertSame('Mensual', $user->refresh()->plan, 'y el plan no se cambió');
    }
}
