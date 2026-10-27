<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\Payments\MembershipPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lo que entra por la API como medio de pago es un medio que la caja sabe contar.
 *
 * `PaymentMethodKind::normalize()` manda a OTHER todo lo que no reconoce, para
 * que un texto raro nunca infle el efectivo. Pero en la ENTRADA eso convertía
 * cualquier texto —«credito», «fiado»— en dinero recibido en «Otros», sin
 * deuda detrás: un cobro que no se pagó contado como cobrado. Ahora la API
 * solo acepta grafías reconocidas, `other` dicho a propósito y `mixed` con su
 * desglose. Reenviar el medio que un cobro ya tenía no es un cambio: los
 * importados con `manual` se siguen pudiendo editar.
 */
class MediosDePagoReconocidosTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Laura Mostrador',
            'email' => 'medios-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);
        $this->plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
    }

    private function h(): array
    {
        return $this->actingAsAdmin($this->admin);
    }

    private function socio(): User
    {
        return User::create([
            'name' => 'Socio '.uniqid(),
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
    }

    private function cobro(User $u, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/payments', array_merge([
            'user_id' => $u->id, 'plan_id' => $this->plan->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid',
        ], $extra), $this->h());
    }

    // ── El medio de un cobro ────────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function textosQueNoSonMedios(): array
    {
        return [
            'crédito' => ['credito'],
            'fiado' => ['fiado'],
            // Solo lo produce la importación del sistema anterior.
            'manual' => ['manual'],
            'mixto sin desglose' => ['mixed'],
        ];
    }

    #[DataProvider('textosQueNoSonMedios')]
    public function test_un_cobro_no_nace_con_un_medio_que_la_caja_no_sabe_contar(string $medio): void
    {
        $u = $this->socio();

        $this->cobro($u, ['method' => $medio])->assertStatus(422)->assertJsonValidationErrors('method');

        $this->assertSame(0, Payment::where('user_id', $u->id)->count());
        $this->assertNull($u->fresh()->membership_end_date, 'sin cobro no hay membresía');
    }

    /** @return array<string, array{0: string}> */
    public static function mediosReconocidos(): array
    {
        // Lo que ofrece el CRM, las grafías de siempre y `other` a propósito.
        return [
            'cash' => ['cash'], 'transfer' => ['transfer'], 'card' => ['card'],
            'pse' => ['pse'], 'nequi' => ['nequi'], 'daviplata' => ['daviplata'],
            'efectivo' => ['Efectivo'], 'datáfono' => ['datafono'], 'other' => ['other'],
        ];
    }

    #[DataProvider('mediosReconocidos')]
    public function test_los_medios_reconocidos_se_siguen_cobrando(string $medio): void
    {
        $this->cobro($this->socio(), ['method' => $medio])->assertStatus(201);
    }

    public function test_un_cobro_no_cambia_a_un_medio_desconocido(): void
    {
        $id = $this->cobro($this->socio())->assertStatus(201)->json('id');

        $this->patchJson("/api/payments/{$id}", ['method' => 'fiado'], $this->h())
            ->assertStatus(422)->assertJsonValidationErrors('method');

        $this->assertSame('cash', Payment::find($id)->method);
    }

    /** El importado con `manual` se sigue editando: reenviar su medio no es cambiarlo. */
    public function test_un_cobro_importado_conserva_su_medio_al_editarse(): void
    {
        $migrado = Payment::create([
            'user_id' => $this->socio()->id, 'amount' => 80000, 'method' => 'manual',
            'reference' => 'MIGR-1', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->patchJson("/api/payments/{$migrado->id}", ['method' => 'manual', 'reference' => 'MIGR-1-B'], $this->h())
            ->assertOk();
        $this->assertSame('MIGR-1-B', $migrado->fresh()->reference);

        // Y se le puede poner el medio de verdad cuando se sepa.
        $this->patchJson("/api/payments/{$migrado->id}", ['method' => 'transfer'], $this->h())->assertOk();
        $this->assertSame('transfer', $migrado->fresh()->method);
    }

    // ── Las partes de un desglose ───────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function partesQueNoSonMedios(): array
    {
        return ['fiado' => ['fiado'], 'manual' => ['manual'], 'mixto dentro de mixto' => ['mixed']];
    }

    #[DataProvider('partesQueNoSonMedios')]
    public function test_una_parte_del_desglose_es_un_medio_reconocido(string $medio): void
    {
        $u = $this->socio();
        $desglose = [['method' => 'cash', 'amount' => 40000], ['method' => $medio, 'amount' => 40000]];

        $this->cobro($u, ['method' => 'mixed', 'splits' => $desglose])
            ->assertStatus(422)->assertJsonValidationErrors('splits.1.method');
        $this->assertSame(0, Payment::where('user_id', $u->id)->count());

        $id = $this->cobro($u)->assertStatus(201)->json('id');
        $this->patchJson("/api/payments/{$id}", ['splits' => $desglose], $this->h())
            ->assertStatus(422)->assertJsonValidationErrors('splits.1.method');
        $this->assertSame(0, PaymentSplit::where('payment_id', $id)->count());
    }

    public function test_un_mixto_con_su_desglose_se_sigue_cobrando(): void
    {
        $id = $this->cobro($this->socio(), ['method' => 'mixed', 'splits' => [
            ['method' => 'cash', 'amount' => 30000], ['method' => 'nequi', 'amount' => 50000],
        ]])->assertStatus(201)->json('id');

        $this->assertSame('mixed', Payment::find($id)->method);
        $this->assertSame(2, PaymentSplit::where('payment_id', $id)->count());
    }

    public function test_una_venta_de_caja_no_reparte_con_un_medio_desconocido(): void
    {
        app(CashShiftService::class)->open($this->admin, CashShiftType::PRODUCTS);
        $iva = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );
        $p = Product::create([
            'name' => 'Proteína', 'category' => 'Suplementos', 'sale_price' => 50000, 'cost_price' => 25000,
            'stock' => 50, 'min_stock' => 0, 'active' => true, 'visible_in_app' => true,
            'tax_rate_id' => $iva->id, 'pricing_mode' => 'legacy_inclusive',
        ]);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'splits' => [['method' => 'cash', 'amount' => 20000], ['method' => 'fiado', 'amount' => 30000]],
        ], $this->h())->assertStatus(422)->assertJsonValidationErrors('splits.1.method');

        $this->assertSame(0, ProductSale::count());
    }

    // ── La mejora de plan ───────────────────────────────────────────────────

    public function test_la_diferencia_de_una_mejora_no_se_cobra_con_un_medio_desconocido(): void
    {
        $total = Plan::create(['name' => 'Total Access', 'price' => 150000, 'duration_days' => 30, 'active' => true]);
        $inicio = MembershipPeriod::today()->subDays(7);
        $u = User::create([
            'name' => 'Socio Mensual', 'email' => 'mej-'.uniqid().'@example.com', 'password' => 'secret',
            'status' => 'active', 'plan' => $this->plan->name,
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $inicio->addDays(30)->toDateString(),
        ]);

        // Tampoco `receivable`: aquí no hay quien lo juzgue después y
        // normalize() lo dejaría en «Otros» como dinero recibido.
        foreach (['fiado', 'receivable'] as $medio) {
            $this->postJson("/api/users/{$u->id}/plan-upgrade", ['plan_id' => $total->id, 'method' => $medio], $this->h())
                ->assertStatus(422)->assertJsonValidationErrors('method');
        }
        $this->assertSame($this->plan->name, $u->fresh()->plan);

        // Nequi, que es lo que ofrece el CRM en la mejora, sigue entrando.
        $this->postJson("/api/users/{$u->id}/plan-upgrade", ['plan_id' => $total->id, 'method' => 'nequi'], $this->h())
            ->assertStatus(201);
    }
}
