<?php

namespace Tests\Feature\Caja;

use App\Models\Admin;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobro repartido entre varios medios de pago.
 *
 * El caso real: el socio paga 60.000 con 20.000 en efectivo, 20.000 por Nequi y
 * 20.000 con tarjeta. Antes había que elegir UN método y los otros dos se
 * perdían, con dos consecuencias que estos tests fijan:
 *
 *  - el desglose por medio de los reportes mentía;
 *  - y sobre todo, el arqueo de caja esperaba en el cajón dinero que nunca
 *    entró, porque los 60.000 se atribuían enteros a un solo medio.
 *
 * El invariante que lo sostiene todo: la suma de las partes es EXACTAMENTE el
 * importe del cobro. Sin eso, el desglose sería una anotación decorativa.
 */
class MixedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Cajera',
            'email' => 'mixto-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_ADMINISTRADOR,
            'status' => 'active',
        ]);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Socio Mixto',
            'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
    }

    private function abrirGym(array $headers): void
    {
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'gym'], $headers)->assertStatus(201);
    }

    /** Cierra el turno y devuelve sus totales, que es donde se congelan. */
    private function totalesAlCerrar(array $headers): array
    {
        $res = $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym'], $headers)->assertOk();

        return $res->json('results.gym.shift');
    }

    /** El ejemplo del enunciado: 20 + 20 + 20. */
    private function cobroMixto(array $headers, User $u): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/payments', [
            'user_id' => $u->id,
            'amount' => 60000,
            'status' => 'paid',
            'splits' => [
                ['method' => 'cash', 'amount' => 20000],
                ['method' => 'nequi', 'amount' => 20000, 'reference' => 'NEQ-991'],
                ['method' => 'card', 'amount' => 20000],
            ],
        ], $headers);
    }

    // ── Registro ────────────────────────────────────────────────────────────

    public function test_registra_las_tres_partes_y_marca_el_cobro_como_mixto(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h);
        $u = $this->user();

        $res = $this->cobroMixto($h, $u)->assertStatus(201);

        $pago = Payment::firstWhere('user_id', $u->id);
        $this->assertNotNull($pago);
        $this->assertSame(Payment::MIXED_METHOD, $pago->method);
        $this->assertEquals(60000, (float) $pago->amount);

        // Las tres partes, con su referencia propia.
        $this->assertCount(3, $pago->splits);
        $this->assertEquals(20000, (float) $pago->splits->firstWhere('method', 'cash')->amount);
        $this->assertSame('NEQ-991', $pago->splits->firstWhere('method', 'nequi')->reference);

        // Y viajan en la respuesta, para que el CRM las pinte sin otra llamada.
        $res->assertJsonCount(3, 'splits');
    }

    public function test_rechaza_un_desglose_que_no_suma_el_total(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h);
        $u = $this->user();

        $this->postJson('/api/payments', [
            'user_id' => $u->id,
            'amount' => 60000,
            'status' => 'paid',
            'splits' => [
                ['method' => 'cash', 'amount' => 20000],
                ['method' => 'card', 'amount' => 30000],   // faltan 10.000
            ],
        ], $h)->assertStatus(422)->assertJsonValidationErrors('splits');

        // Y no deja el cobro a medias.
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_splits', 0);
    }

    public function test_una_sola_linea_no_es_un_pago_mixto(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h);

        $this->postJson('/api/payments', [
            'user_id' => $this->user()->id,
            'amount' => 20000,
            'status' => 'paid',
            'splits' => [['method' => 'cash', 'amount' => 20000]],
        ], $h)->assertStatus(422)->assertJsonValidationErrors('splits');
    }

    // ── Arqueo de caja ──────────────────────────────────────────────────────

    /**
     * El test que justifica todo el trabajo: en el cajón solo hay que encontrar
     * los 20.000 en efectivo, no los 60.000 del cobro.
     */
    public function test_el_arqueo_solo_espera_en_el_cajon_la_parte_en_efectivo(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h);
        $this->cobroMixto($h, $this->user())->assertStatus(201);

        $t = $this->totalesAlCerrar($h);

        // 20.000 en efectivo, 20.000 en tarjeta y Nequi cae en transferencia.
        $this->assertEqualsWithDelta(20000.0, $t['cash_total'], 0.001, 'solo la parte en efectivo');
        $this->assertEqualsWithDelta(20000.0, $t['card_total'], 0.001);
        $this->assertEqualsWithDelta(20000.0, $t['transfer_total'], 0.001, 'Nequi es transferencia');
        $this->assertEqualsWithDelta(60000.0, $t['gross_total'], 0.001, 'el cobro entero sigue contando');

        // Apertura en cero + solo el efectivo. Si contara el total, la caja
        // aparecería con 40.000 de sobrante cada vez.
        $this->assertEqualsWithDelta(20000.0, $t['expected_cash'], 0.001);
    }

    public function test_un_cobro_repartido_cuenta_como_una_sola_operacion(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h);
        $this->cobroMixto($h, $this->user())->assertStatus(201);

        $this->assertSame(1, (int) $this->totalesAlCerrar($h)['operations_count']);
    }

    public function test_los_cobros_de_un_solo_medio_siguen_cuadrando_igual(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h);

        $this->postJson('/api/payments', [
            'user_id' => $this->user()->id,
            'amount' => 50000,
            'method' => 'cash',
            'status' => 'paid',
        ], $h)->assertStatus(201);

        $t = $this->totalesAlCerrar($h);
        $this->assertEqualsWithDelta(50000.0, $t['cash_total'], 0.001);
        $this->assertEqualsWithDelta(50000.0, $t['expected_cash'], 0.001);
    }

    // ── El cobro tiene que dar el precio del plan ───────────────────────────

    private function plan(float $precio = 70000): Plan
    {
        return Plan::create([
            'name' => 'Mensual',
            'price' => $precio,
            'duration_days' => 30,
            'benefits' => '',
            'active' => true,
        ]);
    }

    /** El caso del enunciado: plan de 70.000 pagado 50 en efectivo y 20 por Nequi. */
    public function test_el_desglose_puede_sumar_el_precio_del_plan(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h);
        $plan = $this->plan(70000);

        $this->postJson('/api/payments', [
            'user_id' => $this->user()->id,
            'plan_id' => $plan->id,
            'amount' => 70000,
            'status' => 'paid',
            'splits' => [
                ['method' => 'cash', 'amount' => 50000],
                ['method' => 'nequi', 'amount' => 20000],
            ],
        ], $h)->assertStatus(201);

        $t = $this->totalesAlCerrar($h);
        $this->assertEqualsWithDelta(50000.0, $t['cash_total'], 0.001);
        $this->assertEqualsWithDelta(20000.0, $t['transfer_total'], 0.001);
        $this->assertEqualsWithDelta(50000.0, $t['expected_cash'], 0.001, 'en el cajón solo los 50.000');
    }

    public function test_no_se_puede_cobrar_menos_de_lo_que_vale_el_plan(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h);
        $plan = $this->plan(70000);

        // El desglose cuadra consigo mismo (40 + 20 = 60), pero el plan vale 70.
        $this->postJson('/api/payments', [
            'user_id' => $this->user()->id,
            'plan_id' => $plan->id,
            'amount' => 60000,
            'status' => 'paid',
            'splits' => [
                ['method' => 'cash', 'amount' => 40000],
                ['method' => 'nequi', 'amount' => 20000],
            ],
        ], $h)->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_splits', 0);
    }

    public function test_un_importe_distinto_sigue_siendo_posible_si_se_justifica(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h);
        $plan = $this->plan(70000);

        // La vía de escape que ya existía: queda auditada, no es un silencio.
        $this->postJson('/api/payments', [
            'user_id' => $this->user()->id,
            'plan_id' => $plan->id,
            'amount' => 60000,
            'status' => 'paid',
            'amount_override' => true,
            'override_reason' => 'Descuento acordado con el socio por renovación anticipada.',
            'splits' => [
                ['method' => 'cash', 'amount' => 40000],
                ['method' => 'nequi', 'amount' => 20000],
            ],
        ], $h)->assertStatus(201);
    }

    public function test_un_cobro_sin_plan_no_queda_atado_a_ningun_precio(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h);

        // Una multa, una venta suelta… sin plan no hay precio que comparar.
        $this->postJson('/api/payments', [
            'user_id' => $this->user()->id,
            'amount' => 12345,
            'method' => 'cash',
            'status' => 'paid',
        ], $h)->assertStatus(201);
    }

    // ── Reportes ────────────────────────────────────────────────────────────

    public function test_el_reporte_de_ingresos_reparte_el_cobro_entre_sus_medios(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h);
        $this->cobroMixto($h, $this->user())->assertStatus(201);

        $hoy = now()->toDateString();
        $res = $this->getJson("/api/admin/earnings?from={$hoy}&to={$hoy}&source=gym", $h)->assertOk();

        $porMedio = collect($res->json('by_method'))->pluck('amount', 'method');

        $this->assertEquals(20000, $porMedio['cash'] ?? null);
        $this->assertEquals(20000, $porMedio['nequi'] ?? null);
        $this->assertEquals(20000, $porMedio['card'] ?? null);

        // Y NO aparece un bloque de 60.000 bajo «mixed», que es lo que salía
        // antes y hacía inútil el desglose.
        $this->assertArrayNotHasKey(Payment::MIXED_METHOD, $porMedio->all());

        // El total del período no cambia: se reparte, no se duplica.
        $this->assertEquals(60000, $res->json('totals.gym_revenue'));
    }
}
