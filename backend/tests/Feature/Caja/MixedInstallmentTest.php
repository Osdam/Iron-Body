<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\Plan;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\Reports\MoneyLedger;
use App\Services\Reports\ReportRange;
use App\Support\Caja\PaymentMethodKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UN PAGO PARCIAL TAMBIÉN SE PUEDE REPARTIR.
 *
 * «Te doy 50.000 en efectivo y 30.000 por Nequi, y el resto el viernes» es una
 * frase normal en el mostrador. En Pagos, al elegir «pago parcial» el medio
 * «Mixto» desaparecía sin decir por qué: el endpoint de plan a plazos registraba
 * UN abono con UN medio.
 *
 * Y no se podía arreglar solo en la pantalla. El medio de un abono se guarda ya
 * normalizado a un medio canónico ({@see PaymentMethodKind}), así que escribir
 * «mixto» ahí lo habría convertido en «Otros»: el arqueo esperaría ese efectivo
 * en otro sitio del cajón y el turno saldría descuadrado por el importe entero,
 * exactamente el descuadre que ya se arregló en cobros y en ventas.
 *
 * Lo que fijan estas pruebas: lo que se reparte es LO QUE ENTRA HOY —el saldo no
 * ha entrado por ningún medio—, el arqueo atribuye cada peso a su medio, el
 * informe de dinero también, y un desglose que no cuadra se rechaza.
 */
class MixedInstallmentTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Recepción',
            'email' => 'mixto-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    private function member(): Member
    {
        $user = User::create([
            'name' => 'Alejandro Casas',
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Alejandro Casas',
            'document_number' => (string) random_int(10000000, 99999999),
            'is_staff' => false,
        ]);
    }

    private function plan(float $precio = 200000): Plan
    {
        return Plan::create([
            'name' => 'Premium', 'price' => $precio,
            'duration_days' => 30, 'active' => true,
        ]);
    }

    private function openShift(float $apertura = 0): CashShift
    {
        $turno = app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);
        $turno->opening_amount = $apertura;
        $turno->save();

        return $turno;
    }

    /** Vende el plan a plazos pagando hoy 80.000 repartidos. */
    private function venderRepartido(array $cuerpo = []): array
    {
        $plan = $this->plan();
        $socio = $this->member();
        $turno = $this->openShift(apertura: 100000);

        $r = $this->postJson('/api/admin/receivables/plan', array_merge([
            'user_id' => $socio->user_id,
            'plan_id' => $plan->id,
            'first_payment' => 80000,
            'method' => 'mixed',
            'splits' => [
                ['method' => 'cash', 'amount' => 50000],
                ['method' => 'nequi', 'amount' => 30000, 'reference' => 'NQ-991'],
            ],
        ], $cuerpo), $this->actingAsAdmin($this->cajero));

        return [$r, $turno, $socio, $plan];
    }

    // ── El caso central ─────────────────────────────────────────────────────

    public function test_lo_que_se_reparte_es_lo_que_entra_hoy(): void
    {
        [$r] = $this->venderRepartido();
        $r->assertStatus(201);

        // La deuda es la de siempre: el plan completo menos lo que entró.
        $this->assertEquals(200000, $r->json('data.total_amount'));
        $this->assertEquals(80000, $r->json('data.paid_amount'));
        $this->assertEquals(120000, $r->json('data.balance'));

        $abono = ReceivablePayment::sole();
        $this->assertSame('mixed', $abono->method);
        $this->assertEqualsWithDelta(80000, (float) $abono->amount, 0.01);

        // Las partes, con su referencia.
        $partes = $abono->splits()->orderBy('id')->get();
        $this->assertCount(2, $partes);
        $this->assertSame(['cash', 'nequi'], $partes->pluck('method')->all());
        $this->assertEqualsWithDelta(50000, (float) $partes[0]->amount, 0.01);
        $this->assertSame('NQ-991', $partes[1]->reference);
    }

    public function test_el_arqueo_pone_cada_parte_en_su_medio(): void
    {
        [, $turno] = $this->venderRepartido();

        $t = $turno->fresh()->computeTotals();

        // 50.000 en el cajón, no 80.000 ni cero: Nequi es transferencia.
        $this->assertSame('50000.00', $t['cash_total']);
        $this->assertSame('30000.00', $t['transfer_total']);
        $this->assertSame('0.00', $t['other_total'], 'un abono mixto no es «Otros»');
        $this->assertSame('150000.00', $t['expected_cash'], 'fondo 100.000 + 50.000');

        // Un abono repartido en dos medios sigue siendo UNA operación.
        $this->assertSame(1, $t['operations_count']);
    }

    public function test_el_informe_de_dinero_tambien_lo_reparte(): void
    {
        $this->venderRepartido();

        $libro = new MoneyLedger(ReportRange::fromRequest([]), []);
        $porMedio = collect($libro->byMethod())->keyBy('key');

        $this->assertEqualsWithDelta(50000, $porMedio['cash']['amount'], 0.01);
        $this->assertEqualsWithDelta(30000, $porMedio['transfer']['amount'], 0.01);
        $this->assertEqualsWithDelta(0, $porMedio['other']['amount'], 0.01);
    }

    public function test_el_crm_lo_enseña_como_mixto_con_su_desglose(): void
    {
        [$r] = $this->venderRepartido();

        $abono = $r->json('first_payment');
        $this->assertSame('Mixto', $abono['method_label'], 'no «Otros»');
        $this->assertCount(2, $abono['splits']);
        $this->assertSame('Efectivo', $abono['splits'][0]['method_label']);
    }

    // ── Lo que se rechaza ───────────────────────────────────────────────────

    public function test_un_desglose_que_no_cuadra_con_lo_de_hoy_se_rechaza(): void
    {
        // Suma el PLAN entero (200.000) en vez de lo que se paga hoy. Es el
        // error que se cometería al reutilizar el objetivo del cobro completo.
        [$r] = $this->venderRepartido([
            'splits' => [
                ['method' => 'cash', 'amount' => 150000],
                ['method' => 'card', 'amount' => 50000],
            ],
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('splits');
        $this->assertSame(0, ReceivablePayment::count(), 'no se registró nada');
    }

    public function test_mixto_sin_desglose_se_rechaza(): void
    {
        [$r] = $this->venderRepartido(['splits' => null]);

        $r->assertStatus(422)->assertJsonValidationErrors('splits');
    }

    public function test_un_desglose_con_un_medio_simple_se_rechaza(): void
    {
        // Mandar partes con `method: cash` sería guardar un abono en efectivo
        // con un desglose que nadie mira: dos verdades sobre el mismo dinero.
        [$r] = $this->venderRepartido(['method' => 'cash']);

        $r->assertStatus(422)->assertJsonValidationErrors('method');
    }

    public function test_una_sola_parte_no_es_un_cobro_mixto(): void
    {
        [$r] = $this->venderRepartido([
            'splits' => [['method' => 'cash', 'amount' => 80000]],
        ]);

        $r->assertStatus(422)->assertJsonValidationErrors('splits');
    }

    // ── Lo que no cambia ────────────────────────────────────────────────────

    public function test_un_abono_de_un_solo_medio_sigue_igual(): void
    {
        $plan = $this->plan();
        $socio = $this->member();
        $turno = $this->openShift(apertura: 100000);

        $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 80000, 'method' => 'cash',
        ], $this->actingAsAdmin($this->cajero))->assertStatus(201);

        $abono = ReceivablePayment::sole();
        $this->assertSame('cash', $abono->method);
        $this->assertCount(0, $abono->splits);
        $this->assertSame('80000.00', $turno->fresh()->computeTotals()['cash_total']);
    }

    public function test_anular_un_abono_mixto_lo_saca_del_arqueo(): void
    {
        [$r, $turno] = $this->venderRepartido();
        $abono = ReceivablePayment::sole();

        $this->postJson(
            "/api/admin/receivables/payments/{$abono->id}/reverse",
            ['reason' => 'Se registró en la caja equivocada'],
            $this->actingAsAdmin($this->cajero),
        )->assertOk();

        // El dinero deja de contarse por los dos medios, no por uno.
        $t = $turno->fresh()->computeTotals();
        $this->assertSame('0.00', $t['cash_total']);
        $this->assertSame('0.00', $t['transfer_total']);
        $this->assertSame('100000.00', $t['expected_cash'], 'solo el fondo');
    }
}
