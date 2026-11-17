<?php

namespace Tests\Feature\Payments;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentReclassification;
use App\Models\Plan;
use App\Models\ReceivablePayment;
use App\Models\Trainer;
use App\Models\TrainerCommissionCharge;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EN PAGOS, UN COBRO RECLASIFICADO DICE QUE LO ES.
 *
 * Reclasificar deja el cobro original ANULADO, con su id, su recibo y su
 * historia. Sin la bandera, en Pagos se lee como cualquier anulación —dinero
 * que se devolvió o que nunca entró— cuando entró y se registró de nuevo como
 * lo que era: el piso de un entrenador. La forma es la del contrato
 * (RECLASIFICACION-DE-COBROS.md §5) y la constancia entera —motivo, cifras de
 * antes y después, quién— no viaja en un listado.
 */
class PaymentReclassificationFlagTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $h;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00', 'America/Bogota')->utc());
        $this->admin = Admin::create(['name' => 'Dueño', 'email' => 'bandera-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $this->h = $this->actingAsAdmin($this->admin);
        $this->openCashShift($this->admin, CashShiftType::GYM);
        $this->plan = Plan::create(['name' => 'Comisión de personalizados', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_listado_y_el_detalle_dicen_que_el_cobro_se_reclasifico(): void
    {
        [$pago, $r] = $this->reclasificado('Carlos Entrenador');
        $normal = $this->cobro($this->socio());

        $filas = collect($this->getJson('/api/payments', $this->h)->assertOk()->json('data'))->keyBy('id');

        $esperado = [
            'id' => $r->id,
            'label' => 'Reclasificado a piso de entrenador',
            'kind' => 'trainer_commission',
            'performed_at' => $r->performed_at->toIso8601String(),
            'economic_at' => $r->economic_at->toIso8601String(),
            'trainer_name' => 'Carlos Entrenador',
            'period_label' => 'noviembre de 2026',
            'charge_id' => $r->charge_id,
            'receivable_payment_id' => $r->receivable_payment_id,
        ];
        $this->assertTrue($filas[$pago->id]['reclassified']);
        $this->assertSame($esperado, $filas[$pago->id]['reclassification']);
        $this->assertFalse($filas[$normal->id]['reclassified']);
        $this->assertNull($filas[$normal->id]['reclassification']);
        // El resto de la fila, como siempre.
        $this->assertArrayHasKey('invoice_summary', $filas[$pago->id]);
        $this->assertArrayNotHasKey('electronic_invoice', $filas[$pago->id]);

        // El detalle, para una persona: lo mismo, más lo que cuelga del cobro.
        $detalle = $this->getJson("/api/payments/{$pago->id}", $this->h)->assertOk()->json();
        $this->assertTrue($detalle['reclassified']);
        $this->assertSame($esperado, $detalle['reclassification']);
        $this->assertArrayHasKey('links', $detalle);

        // El token de automatizaciones ve la bandera (no lleva motivo ni autor),
        // pero no el nombre del entrenador, que es un tercero (H-4), ni lo que
        // cuelga del cobro. En el detalle y en el listado.
        $sinNombre = array_replace($esperado, ['trainer_name' => null]);
        $token = $this->getJson("/api/payments/{$pago->id}", $this->sharedTokenHeaders())->assertOk()->json();
        $this->assertTrue($token['reclassified']);
        $this->assertSame($sinNombre, $token['reclassification']);
        $this->assertArrayNotHasKey('links', $token);
        $filasToken = collect($this->getJson('/api/payments', $this->sharedTokenHeaders())->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($sinNombre, $filasToken[$pago->id]['reclassification']);

        // Un cobro normal también lleva las dos claves, a null/false.
        $this->getJson("/api/payments/{$normal->id}", $this->h)->assertOk()
            ->assertJsonPath('reclassified', false)
            ->assertJsonPath('reclassification', null);
    }

    public function test_el_listado_no_hace_una_consulta_por_fila(): void
    {
        $this->reclasificado('Entrenador Uno');
        $una = $this->consultasDelListado();

        $this->reclasificado('Entrenador Dos');
        $this->reclasificado('Entrenador Tres');
        $this->reclasificado('Entrenador Cuatro');
        $cuatro = $this->consultasDelListado();

        $this->assertSame(count($una), count($cuatro), 'con una o con cuatro reclasificaciones, las mismas consultas');
        $this->assertCount(1, array_filter($cuatro, fn (string $sql) => str_contains($sql, 'from "payment_reclassifications"')));
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function consultasDelListado(): array
    {
        $consultas = [];
        $grabando = true;
        DB::listen(function (QueryExecuted $q) use (&$consultas, &$grabando): void {
            if ($grabando) {
                $consultas[] = $q->sql;
            }
        });
        $this->getJson('/api/payments?per_page=50', $this->h)->assertOk();
        $grabando = false;

        return $consultas;
    }

    private function socio(string $nombre = 'Socia'): Member
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => $nombre.' '.$n, 'email' => 'flag-'.$n.'-'.uniqid().'@ironbody.test',
            'password' => 'secret', 'status' => 'active']);

        return Member::create(['user_id' => $user->id, 'full_name' => $user->name, 'email' => $user->email,
            'document_number' => (string) (77000000 + $n), 'access_hash' => 'tok-flag-'.$n.'-'.uniqid(),
            'status' => Member::STATUS_ACTIVE]);
    }

    private function cobro(Member $socio): Payment
    {
        return Payment::findOrFail($this->postJson('/api/payments', ['user_id' => $socio->user_id, 'plan_id' => $this->plan->id,
            'amount' => 50000, 'method' => 'cash', 'status' => 'paid'], $this->h)->assertCreated()->json('id'));
    }

    /**
     * Las piezas por los caminos reales (cobro, acuerdo, mes, abono) y la
     * constancia como la escribe el procedimiento de reclasificación.
     *
     * @return array{0: Payment, 1: PaymentReclassification}
     */
    private function reclasificado(string $entrenador): array
    {
        $socia = $this->socio();
        $pago = $this->cobro($socia);
        $trainer = Trainer::create(['full_name' => $entrenador, 'document' => (string) random_int(10_000_000, 99_999_999),
            'email' => 'flag-trainer-'.uniqid().'@ironbody.test', 'status' => 'active']);
        $acuerdoId = $this->postJson('/api/admin/commissions/agreements', ['trainer_id' => $trainer->id, 'member_id' => $socia->id,
            'amount' => 50000, 'payer' => 'member'], $this->h)->assertCreated()->json('id');
        $cobro = TrainerCommissionCharge::findOrFail($this->postJson("/api/admin/commissions/agreements/{$acuerdoId}/charge",
            ['period' => '2026-11', 'method' => 'cash'], $this->h)->assertCreated()->json('charge.id'));

        $r = PaymentReclassification::create([
            'payment_id' => $pago->id,
            'kind' => PaymentReclassification::KIND_TRAINER_COMMISSION,
            'amount' => '50000.00',
            'method' => 'cash',
            'economic_at' => $pago->paid_at,
            'cash_shift_id' => $pago->cash_shift_id,
            'agreement_id' => $acuerdoId,
            'charge_id' => $cobro->id,
            'receivable_id' => $cobro->receivable_id,
            'receivable_payment_id' => ReceivablePayment::query()->where('receivable_id', $cobro->receivable_id)->value('id'),
            'reason' => 'El piso del entrenador se cobró como venta de un plan.',
            'performed_by' => $this->admin->id,
            'performed_by_name' => $this->admin->name,
            'performed_at' => now()->addHour(),
            'procedure' => 'prueba',
            'before_snapshot' => ['shift_total' => 100],
        ]);

        // Como en la realidad: el procedimiento deja el cobro original anulado.
        $pago->forceFill(['status' => 'cancelled'])->saveQuietly();

        return [$pago->refresh(), $r->refresh()];
    }

    public function test_tras_la_compensacion_el_cobro_reconfirmado_deja_de_pintarse_como_reclasificado(): void
    {
        [$pago] = $this->reclasificado('Entrenador Compensado');

        // Compensación documentada: el cobro vuelve a estar pagado. La constancia
        // sigue (es inmutable), pero ya no está EN VIGOR.
        $pago->forceFill(['status' => 'paid'])->saveQuietly();

        $fila = collect($this->getJson('/api/payments', $this->h)->assertOk()->json('data'))->keyBy('id')[$pago->id];
        $this->assertFalse($fila['reclassified']);
        $this->assertNull($fila['reclassification']);
        $this->assertFalse($this->getJson("/api/payments/{$pago->id}", $this->h)->assertOk()->json('reclassified'));
    }
}
