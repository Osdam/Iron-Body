<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\ReceivableService;
use App\Services\Payments\MembershipPeriod;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El cobro de un plan a plazos y su cuenta por cobrar cuentan la misma venta.
 *
 * CASO REAL (27-10-2026). Recepción vendió una Anualidad de 624.000 a plazos
 * con un primer abono de 100.000 y, por error, registró otro de 524.000 en
 * efectivo que no entró. Para «deshacerlo», una administradora ANULÓ EL COBRO
 * del plan desde Pagos: el socio perdió los 365 días y la deuda siguió
 * «pagada», colgando de una venta anulada, con el efectivo falso en el arqueo.
 * Lo que había que anular era el ABONO, en Cuentas por cobrar.
 *
 * Y deshacer esa anulación por la misma vía tampoco servía: reconfirmar el
 * cobro le ponía la fecha de hoy —el periodo empezaba otro día— y encolaba
 * una factura que la venta a plazos nunca tuvo. Hubo que corregirlo a mano.
 *
 * La tercera puerta al mismo estado roto era reabrir, desde «Anuladas», la
 * deuda de un cobro ya anulado.
 */
class PlanAPlazosCobroCoherenteTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Mediodía en Bogotá, como instante UTC: el día del gimnasio sale del
        // código, no de la zona del reloj de la prueba.
        Carbon::setTestNow(Carbon::parse('2026-10-27 11:30', 'America/Bogota')->utc());

        $this->admin = Admin::create([
            'name' => 'Fredy Supervisión',
            'email' => 'coherente-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function h(): array
    {
        return $this->actingAsAdmin($this->admin);
    }

    private function socio(): User
    {
        $user = User::create([
            'name' => 'Socio '.uniqid(),
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        \App\Models\Member::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'document_number' => (string) random_int(10000000, 99999999),
            'is_staff' => false,
        ]);

        return $user;
    }

    private function anualidad(): Plan
    {
        return Plan::create(['name' => 'Anualidad', 'price' => 624000, 'duration_days' => 365, 'active' => true]);
    }

    /** Vende la Anualidad a plazos con 100.000 de entrada: [socio, cobro, deuda]. */
    private function venderAPlazos(): array
    {
        $socio = $this->socio();
        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->id,
            'plan_id' => $this->anualidad()->id,
            'first_payment' => 100000,
            'method' => 'transfer',
        ], $this->h())->assertStatus(201);

        return [$socio, Payment::findOrFail($r->json('payment_id')), Receivable::findOrFail($r->json('data.id'))];
    }

    private function hoy(): string
    {
        return MembershipPeriod::today()->toDateString();
    }

    // ── 1 · El cobro no se anula por debajo de su deuda ─────────────────────

    /** @return array<string, array{0: string}> */
    public static function estadosQueQuitanCobertura(): array
    {
        return ['anulado' => ['cancelled'], 'devuelto' => ['refunded'], 'pendiente' => ['pending'], 'fallido' => ['failed']];
    }

    #[DataProvider('estadosQueQuitanCobertura')]
    public function test_el_cobro_de_un_plan_a_plazos_con_su_deuda_viva_no_se_anula(string $estado): void
    {
        [$socio, $cobro, $deuda] = $this->venderAPlazos();
        $finAntes = $socio->fresh()->membership_end_date;

        $r = $this->patchJson("/api/payments/{$cobro->id}", ['status' => $estado], $this->h())
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // El CRM enseña `message`: tiene que decir qué hacer en su lugar.
        $this->assertStringContainsString('Cuentas por cobrar', (string) $r->json('message'));
        $this->assertStringContainsString("#{$deuda->id}", (string) $r->json('message'));

        $this->assertSame('paid', $cobro->fresh()->status);
        $this->assertSame($finAntes, $socio->fresh()->membership_end_date, 'la membresía no se toca');
        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $deuda->fresh()->status);
    }

    /** El caso real: el abono falso dejó la deuda «pagada». Tampoco. */
    public function test_con_la_deuda_saldada_tampoco_se_anula_el_cobro(): void
    {
        [$socio, $cobro, $deuda] = $this->venderAPlazos();
        app(ReceivableService::class)->settle($deuda, \App\Services\Billing\Money::fromAmount(524000), 'cash', $this->admin);
        $this->assertSame(Receivable::STATUS_PAID, $deuda->fresh()->status);

        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled'], $this->h())->assertStatus(422);

        $this->assertSame('paid', $cobro->fresh()->status);
        $this->assertSame('2027-10-27', $socio->fresh()->membership_end_date);
    }

    /**
     * La vía buena para deshacer la venta sigue abierta: primero se anula la
     * deuda (Cuentas por cobrar) y después el cobro, que devuelve los días.
     */
    public function test_con_la_deuda_anulada_el_cobro_se_anula_y_devuelve_los_dias(): void
    {
        [$socio, $cobro, $deuda] = $this->venderAPlazos();
        app(ReceivableService::class)->cancel($deuda, 'La venta se registró al socio equivocado', $this->admin);

        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled'], $this->h())->assertOk();

        $this->assertSame('cancelled', $cobro->fresh()->status);
        $this->assertSame($this->hoy(), $socio->fresh()->membership_end_date, 'los 365 días vuelven');
        // El abono cobrado sigue cobrado: anular no es devolver dinero.
        $this->assertSame(ReceivablePayment::STATUS_APPLIED, ReceivablePayment::where('receivable_id', $deuda->id)->value('status'));
    }

    /** Un cobro normal, sin deuda detrás, se sigue anulando como siempre. */
    public function test_un_cobro_sin_deuda_se_anula_como_siempre(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $id = $this->postJson('/api/payments', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid',
        ], $this->h())->assertStatus(201)->json('id');

        $this->patchJson("/api/payments/{$id}", ['status' => 'cancelled'], $this->h())->assertOk();

        $this->assertSame('cancelled', Payment::find($id)->status);
    }

    // ── 2 · Reconfirmar un cobro anulado deshace la anulación ───────────────

    /**
     * El dinero cambió de manos cuando se cobró, y en el turno de entonces:
     * reconfirmar al día siguiente no lo convierte en un cobro de hoy ni corre
     * el inicio del periodo.
     */
    public function test_reconfirmar_un_cobro_anulado_conserva_su_fecha_y_su_periodo(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $id = $this->postJson('/api/payments', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid',
        ], $this->h())->assertStatus(201)->json('id');
        $cobradoEl = Payment::find($id)->getRawOriginal('paid_at');
        $finOriginal = $socio->fresh()->membership_end_date;
        $turno = Payment::find($id)->cash_shift_id;

        $this->patchJson("/api/payments/{$id}", ['status' => 'cancelled'], $this->h())->assertOk();
        $this->assertSame($this->hoy(), $socio->fresh()->membership_end_date);

        $this->travel(1)->days();
        $this->patchJson("/api/payments/{$id}", ['status' => 'paid'], $this->h())->assertOk();

        $cobro = Payment::find($id);
        $this->assertSame($cobradoEl, $cobro->getRawOriginal('paid_at'), 'la fecha de cobro no se reescribe');
        $this->assertSame($turno, $cobro->cash_shift_id);
        $this->assertSame($finOriginal, $socio->fresh()->membership_end_date, 'el periodo vuelve a ser el que se vendió');
    }

    /**
     * Ni un `paid_at` vacío al reconfirmar, ni una edición de un cobro pagado
     * que repite `status: paid`, le cambian la fecha: ni hoy ni nula.
     */
    public function test_la_fecha_de_cobro_no_se_borra_ni_se_mueve(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $id = $this->postJson('/api/payments', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid',
        ], $this->h())->assertStatus(201)->json('id');
        $cobradoEl = Payment::find($id)->getRawOriginal('paid_at');

        $this->travel(1)->days();
        $this->patchJson("/api/payments/{$id}", ['status' => 'paid', 'reference' => 'REC-9'], $this->h())->assertOk();
        $this->assertSame($cobradoEl, Payment::find($id)->getRawOriginal('paid_at'), 'editar un cobro pagado');

        $this->patchJson("/api/payments/{$id}", ['status' => 'cancelled'], $this->h())->assertOk();
        $this->patchJson("/api/payments/{$id}", ['status' => 'paid', 'paid_at' => null], $this->h())->assertOk();
        $this->assertSame($cobradoEl, Payment::find($id)->getRawOriginal('paid_at'), 'reconfirmar con paid_at vacío');
    }

    /** Lo que nunca se cobró sí se fecha al confirmarlo: eso no cambia. */
    public function test_un_pendiente_se_sigue_fechando_al_confirmarlo(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $id = $this->postJson('/api/payments', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'pending',
        ], $this->h())->assertStatus(201)->json('id');
        $this->assertNull(Payment::find($id)->paid_at);

        $this->travel(2)->days();
        $this->patchJson("/api/payments/{$id}", ['status' => 'paid'], $this->h())->assertOk();

        $this->assertSame(now()->toDateTimeString(), Payment::find($id)->getRawOriginal('paid_at'));
    }

    /**
     * La venta a plazos no encola factura al venderse (storePlan no lo hace):
     * reconfirmar su cobro tampoco. Y el periodo vuelve a ser el vendido.
     */
    public function test_reconfirmar_el_cobro_de_un_plan_a_plazos_no_encola_factura(): void
    {
        [$socio, $cobro, $deuda] = $this->venderAPlazos();
        $cobradoEl = $cobro->getRawOriginal('paid_at');
        $this->assertSame(0, ElectronicInvoice::where('source_type', $cobro->getMorphClass())->where('source_id', $cobro->id)->count());

        app(ReceivableService::class)->cancel($deuda, 'Se anuló la venta para revisarla', $this->admin);
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled'], $this->h())->assertOk();

        $this->travel(1)->days();
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'paid'], $this->h())->assertOk();

        $this->assertSame(0, ElectronicInvoice::where('source_type', $cobro->getMorphClass())->where('source_id', $cobro->id)->count(),
            'una factura que la venta a plazos nunca tuvo');
        $cobro->refresh();
        $this->assertSame('paid', $cobro->status);
        $this->assertSame($cobradoEl, $cobro->getRawOriginal('paid_at'));
        $this->assertSame('2027-10-27', $socio->fresh()->membership_end_date);
    }

    // ── 3 · La deuda no se reabre por debajo de su cobro ────────────────────

    /**
     * Deshecha la venta (deuda anulada y después cobro anulado), reabrir la
     * deuda desde «Anuladas» le cobraría al socio un plan que ya no tiene y,
     * vencida, lo retendría. El CRM enseña `message`.
     */
    public function test_la_deuda_de_un_cobro_anulado_no_se_reabre(): void
    {
        [$socio, $cobro, $deuda] = $this->venderAPlazos();
        app(ReceivableService::class)->cancel($deuda, 'La venta se registró al socio equivocado', $this->admin);
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled'], $this->h())->assertOk();

        $r = $this->postJson("/api/admin/receivables/{$deuda->id}/reopen", [
            'reason' => 'Se anuló por error, hay que cobrarla',
        ], $this->h())->assertStatus(422)->assertJsonPath('code', 'receivable_source_cancelled');

        $this->assertStringContainsString("#{$cobro->id}", (string) $r->json('message'));
        $this->assertSame(Receivable::STATUS_CANCELLED, $deuda->fresh()->status);
        $this->assertSame($this->hoy(), $socio->fresh()->membership_end_date);
    }

    /** Para rehacer la venta: primero el cobro, después la deuda. */
    public function test_reconfirmado_el_cobro_la_deuda_se_reabre(): void
    {
        [$socio, $cobro, $deuda] = $this->venderAPlazos();
        app(ReceivableService::class)->cancel($deuda, 'La venta se registró al socio equivocado', $this->admin);
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled'], $this->h())->assertOk();

        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'paid'], $this->h())->assertOk();
        $this->postJson("/api/admin/receivables/{$deuda->id}/reopen", [
            'reason' => 'La venta sí era de este socio',
        ], $this->h())->assertOk();

        $deuda->refresh();
        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $deuda->status);
        $this->assertSame('524000.00', $deuda->balance()->toDatabase());
        $this->assertSame('2027-10-27', $socio->fresh()->membership_end_date);
    }

    // ── 4 · El medio de pago no cruza la marca de devengo ───────────────────

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cambiosDeMedio(): array
    {
        return [
            'a efectivo' => [['method' => 'cash']],
            'a transferencia' => [['method' => 'transfer']],
            // `null` también quita la marca: CashReceipts cuenta como dinero lo que no la lleva.
            'a vacío' => [['method' => null]],
            'con desglose' => [['splits' => [
                ['method' => 'cash', 'amount' => 312000],
                ['method' => 'transfer', 'amount' => 312000],
            ]]],
        ];
    }

    /**
     * El cobro de un plan a plazos es el contrato, no dinero: pasado a efectivo,
     * los informes de lo recibido contarían los 624.000 además de los abonos.
     */
    #[DataProvider('cambiosDeMedio')]
    public function test_el_cobro_de_un_plan_a_plazos_no_cambia_de_medio(array $cambio): void
    {
        [, $cobro] = $this->venderAPlazos();

        $this->patchJson("/api/payments/{$cobro->id}", $cambio, $this->h())
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');

        $this->assertSame('receivable', $cobro->fresh()->method);
        $this->assertSame(0, \App\Models\PaymentSplit::where('payment_id', $cobro->id)->count());
    }

    /** Reenviar el mismo medio —un cliente que manda el cobro entero— no es un cambio. */
    public function test_reenviar_el_mismo_medio_no_es_un_cambio(): void
    {
        [, $cobro] = $this->venderAPlazos();

        $this->patchJson("/api/payments/{$cobro->id}", ['method' => 'receivable', 'reference' => 'PLAZOS-1'], $this->h())
            ->assertOk();
        $this->assertSame('PLAZOS-1', $cobro->fresh()->reference);

        // Ni con otra grafía: la marca se queda como la escribió la venta.
        $this->patchJson("/api/payments/{$cobro->id}", ['method' => 'Receivable'], $this->h())->assertOk();
        $this->assertSame('receivable', $cobro->fresh()->method);
    }

    /**
     * Y al revés: un cobro en efectivo marcado como devengo saldría del arqueo
     * de su turno con el dinero en el cajón.
     */
    public function test_un_cobro_normal_no_se_convierte_en_devengo(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $id = $this->postJson('/api/payments', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid',
        ], $this->h())->assertStatus(201)->json('id');
        $turno = \App\Models\CashShift::findOrFail(Payment::find($id)->cash_shift_id);
        $esperado = app(\App\Services\Caja\CashShiftTotalsService::class)->for($turno)['expected_cash'];

        $this->patchJson("/api/payments/{$id}", ['method' => 'Receivable'], $this->h())
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');

        $this->assertSame('cash', Payment::find($id)->method);
        $this->assertSame($esperado, app(\App\Services\Caja\CashShiftTotalsService::class)->for($turno->fresh())['expected_cash']);
    }

    /** @return array<string, array{0: string}> */
    public static function grafiasDelDevengo(): array
    {
        return ['tal cual' => ['receivable'], 'con mayúsculas y espacios' => [' Receivable ']];
    }

    /**
     * Crear el cobro ya marcado como devengo activaría la membresía sin dinero
     * en ningún arqueo y sin deuda que cobrar: un plan gratis que no se ve.
     */
    #[DataProvider('grafiasDelDevengo')]
    public function test_un_cobro_no_se_crea_como_devengo(string $medio): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);

        $this->postJson('/api/payments', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => $medio, 'status' => 'paid',
        ], $this->h())->assertStatus(422)->assertJsonValidationErrors('method');

        $this->assertSame(0, Payment::where('user_id', $socio->id)->count());
        $this->assertNull($socio->fresh()->membership_end_date, 'sin cobro no hay membresía');
    }

    /**
     * Tampoco como parte de un desglose: el cobro queda `mixed`, cuenta entero
     * como dinero recibido y esa parte cae en «Otros» del arqueo, sin deuda.
     */
    public function test_una_parte_del_desglose_no_puede_ser_receivable(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $desglose = [['method' => 'cash', 'amount' => 40000], ['method' => 'receivable', 'amount' => 40000]];

        $this->postJson('/api/payments', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid', 'splits' => $desglose,
        ], $this->h())->assertStatus(422)->assertJsonValidationErrors('splits.1.method');
        $this->assertSame(0, Payment::where('user_id', $socio->id)->count());

        $id = $this->postJson('/api/payments', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid',
        ], $this->h())->assertStatus(201)->json('id');

        $this->patchJson("/api/payments/{$id}", ['splits' => $desglose], $this->h())
            ->assertStatus(422)->assertJsonValidationErrors('splits.1.method');
        $this->assertSame('cash', Payment::find($id)->method);
        $this->assertSame(0, \App\Models\PaymentSplit::where('payment_id', $id)->count());
    }

    /** Entre medios de dinero se sigue corrigiendo como siempre. */
    public function test_un_cobro_normal_sigue_cambiando_de_medio(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $id = $this->postJson('/api/payments', [
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid',
        ], $this->h())->assertStatus(201)->json('id');

        $this->patchJson("/api/payments/{$id}", ['method' => 'transfer'], $this->h())->assertOk();

        $this->assertSame('transfer', Payment::find($id)->method);
    }
}
