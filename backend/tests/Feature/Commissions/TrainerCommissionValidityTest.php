<?php

namespace Tests\Feature\Commissions;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentReclassification;
use App\Models\Plan;
use App\Models\Receipt;
use App\Models\ReceivablePayment;
use App\Models\Trainer;
use App\Models\TrainerCommissionAgreement;
use App\Models\TrainerCommissionCharge;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\Commissions\TrainerCommissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * EL PANEL DE PERSONALIZADOS RESPETA LA VIGENCIA Y CONSERVA EL HISTÓRICO.
 *
 * Hasta ahora `starts_on` y `ends_on` se guardaban y no decidían nada: el panel
 * enseñaba cada acuerdo activo en cualquier mes, se podía cobrar un mes de
 * antes de que existiera el trato, y un acuerdo terminado desaparecía del mes
 * en que sí se cobró. Lo que fijan estas pruebas:
 *
 *   · fuera de vigencia, el mes no sale y no se cobra (422, nada escrito);
 *   · el tramo por defecto empieza cuando empieza el trato, no el día 1;
 *   · un acuerdo terminado sale en el mes que tiene un cobro vigente, y uno
 *     con solo cobros anulados no;
 *   · con las fechas en NULL (todos los acuerdos de hoy), nada cambia;
 *   · los totales cuadran SIEMPRE: pactado = cobrado + pendiente + sin registrar;
 *   · un mes que salió de reclasificar un cobro dice de cuál.
 *
 * Reloj en UTC (17-11-2026, 10:00 en Neiva): el mes en curso es noviembre.
 */
class TrainerCommissionValidityTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00', 'America/Bogota')->utc());

        $this->admin = Admin::create(['name' => 'Dueño Iron Body', 'email' => 'vigencia-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $this->h = $this->actingAsAdmin($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Con las fechas en NULL nada cambia ──────────────────────────────────

    public function test_con_las_fechas_en_null_el_panel_ve_lo_mismo_que_antes(): void
    {
        $this->abrirCaja();
        $acuerdo = $this->acuerdo();
        $this->assertNull($acuerdo->starts_on);
        $this->assertNull($acuerdo->ends_on);

        // Cualquier mes, pasado o futuro: el trato vale siempre, como antes.
        foreach (['2025-01', '2026-10', '2026-11', '2027-06'] as $mes) {
            $filas = $this->panel($mes)['rows'];
            $this->assertCount(1, $filas, $mes);
            $this->assertTrue($filas[0]['in_force'], $mes);
            $this->assertNull($filas[0]['starts_on']);
            $this->assertNull($filas[0]['ends_on']);
            $this->assertSame('pending', $filas[0]['state'], $mes);
        }

        // Y se cobra un mes atrás, como siempre, desde el día 1.
        $cobro = $this->cobrar($acuerdo, '2026-10', ['method' => 'cash'])->assertCreated()->json('charge');
        $this->assertSame('2026-10-01', $cobro['covers_from']);
        $this->assertSame('2026-10-30', $cobro['covers_to']);
        $this->assertNull($cobro['reclassified_from']);
    }

    // ── Fuera de vigencia ───────────────────────────────────────────────────

    public function test_los_meses_fuera_de_vigencia_no_salen_ni_se_cobran(): void
    {
        $this->abrirCaja();
        $acuerdo = $this->acuerdo(['starts_on' => '2026-11-06', 'ends_on' => '2027-01-15']);

        $this->assertSame([], $this->panel('2026-10')['rows'], 'antes de empezar');
        $this->assertSame([], $this->panel('2027-02')['rows'], 'después de acabar');
        foreach (['2026-11', '2027-01'] as $mes) {
            $fila = $this->panel($mes)['rows'][0];
            $this->assertTrue($fila['in_force'], $mes);
            $this->assertSame('2026-11-06', $fila['starts_on']);
            $this->assertSame('2027-01-15', $fila['ends_on']);
        }

        foreach (['2026-10', '2027-02'] as $mes) {
            $antes = $this->huella();
            $this->cobrar($acuerdo, $mes, ['method' => 'cash'])->assertStatus(422)
                ->assertJsonPath('message', 'El acuerdo no está en vigor en '
                    .($mes === '2026-10' ? 'octubre de 2026' : 'febrero de 2027')
                    .' (vale del 06-11-2026 al 15-01-2027): ese mes no se cobra. Si el trato empezó antes o siguió después, corrige sus fechas.');
            $this->assertSame($antes, $this->huella(), "{$mes}: un 422 no escribe nada");
        }

        // Llamado al servicio directamente (como lo hará la reclasificación), igual.
        $this->expectException(RuntimeException::class);
        app(TrainerCommissions::class)->charge($acuerdo->fresh(), CarbonImmutable::parse('2026-10-01'));
    }

    public function test_el_tramo_por_defecto_empieza_cuando_empieza_el_trato(): void
    {
        $acuerdo = $this->acuerdo(['starts_on' => '2026-11-06']);

        // Empezó el 6: noviembre cubre desde el 6, no desde el 1.
        $noviembre = $this->cobrar($acuerdo, '2026-11')->assertCreated()->json('charge');
        $this->assertSame('2026-11-06', $noviembre['covers_from']);
        $this->assertSame('2026-12-05', $noviembre['covers_to']);

        // Los meses siguientes, desde el 1 como siempre.
        $diciembre = $this->cobrar($acuerdo, '2026-12')->assertCreated()->json('charge');
        $this->assertSame('2026-12-01', $diciembre['covers_from']);

        // Y quien corrige el tramo a mano sigue mandando.
        $acuerdo2 = $this->acuerdo(['starts_on' => '2026-11-06']);
        $this->assertSame('2026-11-10', $this->cobrar($acuerdo2, '2026-11', ['covers_from' => '2026-11-10'])
            ->assertCreated()->json('charge.covers_from'));
    }

    public function test_un_acuerdo_terminado_no_cobra_meses_nuevos(): void
    {
        $acuerdo = $this->acuerdo();
        $this->deleteJson("/api/admin/commissions/agreements/{$acuerdo->id}", [], $this->h)->assertOk();

        $antes = $this->huella();
        $this->cobrar($acuerdo, '2026-11')->assertStatus(422)
            ->assertJsonPath('message', 'Ese acuerdo está terminado: ya no se le cobra ningún mes. Si el trato se retomó, vuelve a pactarlo.');
        $this->assertSame($antes, $this->huella());
    }

    // ── El histórico ────────────────────────────────────────────────────────

    public function test_un_acuerdo_terminado_con_cobro_sale_en_ese_mes(): void
    {
        $this->abrirCaja();
        $acuerdo = $this->acuerdo();
        $this->cobrar($acuerdo, '2026-10', ['method' => 'cash'])->assertCreated();
        $this->deleteJson("/api/admin/commissions/agreements/{$acuerdo->id}", [], $this->h)->assertOk();

        // Octubre se cobró: sigue saliendo, marcado como fuera de vigor.
        $fila = $this->panel('2026-10')['rows'][0];
        $this->assertSame($acuerdo->id, $fila['agreement_id']);
        $this->assertFalse($fila['in_force']);
        $this->assertSame('paid', $fila['state']);
        $this->assertSame(50000.0, (float) $fila['charge']['amount']);

        // Noviembre no: terminado y sin cobro, no hay nada que ver.
        $this->assertSame([], $this->panel('2026-11')['rows']);
    }

    public function test_un_acuerdo_terminado_con_solo_cobros_anulados_no_sale(): void
    {
        $this->abrirCaja();
        $acuerdo = $this->acuerdo();
        $cobroId = $this->cobrar($acuerdo, '2026-10', ['method' => 'cash'])->assertCreated()->json('charge.id');
        $this->postJson("/api/admin/commissions/charges/{$cobroId}/cancel", ['reason' => 'Se cobró por error'], $this->h)->assertOk();
        $this->deleteJson("/api/admin/commissions/agreements/{$acuerdo->id}", [], $this->h)->assertOk();

        $this->assertSame([], $this->panel('2026-10')['rows']);
    }

    // ── Los totales ─────────────────────────────────────────────────────────

    public function test_los_totales_cuadran_siempre(): void
    {
        $this->abrirCaja();

        // A · cobrado por menos de lo pactado (mes a medias): cuenta lo cobrado.
        $a = $this->acuerdo(['amount' => 50000]);
        $this->cobrar($a, '2026-11', ['method' => 'cash', 'amount' => 25000])->assertCreated();

        // B · deuda abierta con un abono de 20.000 de 60.000.
        $b = $this->acuerdo(['amount' => 60000]);
        $deudaB = $this->cobrar($b, '2026-11', ['due_at' => '2026-11-30'])->assertCreated()->json('charge.receivable_id');
        $this->postJson("/api/admin/receivables/{$deudaB}/payments", ['amount' => 20000, 'method' => 'cash'], $this->h)->assertCreated();

        // C · sin registrar.
        $c = $this->acuerdo(['amount' => 70000]);

        // D · en vigor con solo un cobro anulado: cuenta como sin registrar.
        $d = $this->acuerdo(['amount' => 40000]);
        $cobroD = $this->cobrar($d, '2026-11', ['method' => 'cash'])->assertCreated()->json('charge.id');
        $this->postJson("/api/admin/commissions/charges/{$cobroD}/cancel", ['reason' => 'Importe equivocado'], $this->h)->assertOk();

        // E · terminado, con su noviembre cobrado: histórico.
        $e = $this->acuerdo(['amount' => 30000]);
        $this->cobrar($e, '2026-11', ['method' => 'cash'])->assertCreated();
        $this->deleteJson("/api/admin/commissions/agreements/{$e->id}", [], $this->h)->assertOk();

        // F · terminado con solo un cobro anulado, y G · aún no empieza: no salen.
        $f = $this->acuerdo(['amount' => 90000]);
        $cobroF = $this->cobrar($f, '2026-11', ['method' => 'cash'])->assertCreated()->json('charge.id');
        $this->postJson("/api/admin/commissions/charges/{$cobroF}/cancel", ['reason' => 'No iba'], $this->h)->assertOk();
        $this->deleteJson("/api/admin/commissions/agreements/{$f->id}", [], $this->h)->assertOk();
        $this->acuerdo(['amount' => 80000, 'starts_on' => '2026-12-01']);

        $panel = $this->panel('2026-11');
        $t = $panel['totals'];

        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id, $d->id, $e->id], array_column($panel['rows'], 'agreement_id'));
        $this->assertEqualsWithDelta(225000, $t['agreed'], 0.001);
        $this->assertEqualsWithDelta(75000, $t['collected'], 0.001);
        $this->assertEqualsWithDelta(40000, $t['outstanding'], 0.001);
        $this->assertEqualsWithDelta(110000, $t['unregistered_amount'], 0.001);
        $this->assertSame(2, $t['unregistered']);
        $this->assertEqualsWithDelta($t['agreed'], $t['collected'] + $t['outstanding'] + $t['unregistered_amount'], 0.001,
            'pactado = cobrado + pendiente + sin registrar');
    }

    // ── De dónde salió un mes reclasificado ─────────────────────────────────

    public function test_el_mes_que_salio_de_reclasificar_un_cobro_dice_de_cual(): void
    {
        $this->abrirCaja();
        $r = $this->reclasificacion();
        $normal = $this->acuerdo();
        $this->cobrar($normal, '2026-11', ['method' => 'cash'])->assertCreated();

        $consultas = [];
        DB::listen(function (QueryExecuted $q) use (&$consultas): void {
            $consultas[] = $q->sql;
        });
        $filas = collect($this->panel('2026-11')['rows'])->keyBy('agreement_id');

        $this->assertSame([
            'reclassification_id' => $r->id,
            'payment_id' => $r->payment_id,
            'receipt_number' => 'RG-000001',
            'performed_at' => $r->performed_at->toIso8601String(),
        ], $filas[$r->agreement_id]['charge']['reclassified_from']);
        $this->assertNull($filas[$normal->id]['charge']['reclassified_from']);

        // Una consulta para las constancias y otra para sus recibos, sea cual sea
        // el número de filas: nada de una por mes.
        $this->assertCount(1, array_filter($consultas, fn ($s) => str_contains($s, 'from "payment_reclassifications"')));
        $this->assertCount(1, array_filter($consultas, fn ($s) => str_contains($s, 'from "receipts"')));

        // El histórico del acuerdo lo dice igual.
        $historial = $this->getJson("/api/admin/commissions/agreements/{$r->agreement_id}/history", $this->h)->assertOk()->json('charges');
        $this->assertSame($r->id, $historial[0]['reclassified_from']['reclassification_id']);
    }

    // ── El alta, extraída al servicio ───────────────────────────────────────

    public function test_create_agreement_tiene_las_mismas_reglas_que_el_alta_del_crm(): void
    {
        $servicio = app(TrainerCommissions::class);
        $entrenador = $this->entrenador();
        $cliente = $this->cliente();
        $datos = ['amount' => 50000, 'payer' => 'member', 'cycle_days' => 30, 'blocks_app' => false,
            'starts_on' => '2026-11-06', 'notes' => 'Piso de la socia'];

        $acuerdo = $servicio->createAgreement($entrenador, $cliente, $datos, $this->admin);
        $this->assertTrue($acuerdo->active);
        $this->assertSame('2026-11-06', $acuerdo->fresh()->starts_on->toDateString());
        $this->assertSame((int) $this->admin->id, (int) $acuerdo->created_by);

        // La misma traza que deja el alta por el CRM, firmada por quien se pasa.
        $traza = AuditLog::query()->where('entity', 'piso de entrenador')->where('action', 'create')->sole();
        $this->assertSame('Pactó el piso de un cliente personalizado', $traza->summary);
        $this->assertSame('Comisiones', $traza->module);
        $this->assertSame((string) $acuerdo->id, $traza->entity_id);
        $this->assertSame($entrenador->full_name, $traza->target_name);
        $this->assertSame((string) $this->admin->id, $traza->actor_id);
        // Por valor: el JSON de la traza guarda 50000.0 como 50000, igual que antes.
        $this->assertEquals(['trainer_id' => $entrenador->id, 'member_id' => $cliente->id, 'amount' => 50000, 'payer' => 'member'],
            $traza->metadata);

        // Activo duplicado: excepción con el mensaje de siempre, y nada escrito.
        try {
            $servicio->createAgreement($entrenador, $cliente, $datos, $this->admin);
            $this->fail('Pactó dos pisos activos con la misma pareja.');
        } catch (RuntimeException $e) {
            $this->assertSame(TrainerCommissions::DUPLICATE_AGREEMENT, $e->getMessage());
        }
        $this->assertSame(1, TrainerCommissionAgreement::count());

        // Terminado: se reabre LA MISMA fila.
        $acuerdo->update(['active' => false, 'ended_at' => now(), 'ended_by_name' => 'Alguien']);
        $reabierto = $servicio->createAgreement($entrenador, $cliente, ['amount' => 60000, 'payer' => 'trainer'], $this->admin);
        $this->assertSame($acuerdo->id, $reabierto->id);
        $this->assertTrue($reabierto->fresh()->active);
        $this->assertNull($reabierto->fresh()->ended_at);
        $this->assertSame('60000.00', $reabierto->fresh()->amount);
        $this->assertSame(1, TrainerCommissionAgreement::count());
    }

    public function test_el_alta_por_el_crm_sigue_igual(): void
    {
        $entrenador = $this->entrenador();
        $cliente = $this->cliente();
        $cuerpo = ['trainer_id' => $entrenador->id, 'member_id' => $cliente->id, 'amount' => 50000, 'payer' => 'trainer'];

        $this->postJson('/api/admin/commissions/agreements', $cuerpo, $this->h)->assertCreated()
            ->assertJsonPath('active', true)->assertJsonPath('cycle_days', 30)->assertJsonPath('by', 'Dueño Iron Body');
        $this->postJson('/api/admin/commissions/agreements', $cuerpo, $this->h)->assertStatus(422)
            ->assertJsonPath('ok', false)->assertJsonPath('message', TrainerCommissions::DUPLICATE_AGREEMENT);

        $traza = AuditLog::query()->where('entity', 'piso de entrenador')->sole();
        $this->assertSame((string) $this->admin->id, $traza->actor_id);
        $this->assertSame('127.0.0.1', $traza->ip_address, 'la IP de la petición del mostrador');
    }

    public function test_dos_altas_que_se_cruzan_dan_el_mismo_duplicado_y_no_un_500(): void
    {
        $entrenador = $this->entrenador();
        $cliente = $this->cliente();

        // La otra alta inserta entre la lectura y el INSERT: el índice único la
        // frena y tiene que salir como el duplicado de siempre.
        TrainerCommissionAgreement::creating(function () use ($entrenador, $cliente): void {
            DB::table('trainer_commission_agreements')->insert(['trainer_id' => $entrenador->id, 'member_id' => $cliente->id,
                'amount' => 1, 'payer' => 'trainer', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        });

        try {
            app(TrainerCommissions::class)->createAgreement($entrenador, $cliente, ['amount' => 50000, 'payer' => 'trainer'], $this->admin);
            $this->fail('No se detectó el alta cruzada.');
        } catch (RuntimeException $e) {
            $this->assertSame(TrainerCommissions::DUPLICATE_AGREEMENT, $e->getMessage());
        }
        $this->assertSame(0, AuditLog::query()->where('entity', 'piso de entrenador')->count());
    }

    // ── Editar una sola fecha ───────────────────────────────────────────────

    public function test_una_fecha_sola_se_compara_con_la_guardada(): void
    {
        $acuerdo = $this->acuerdo(['starts_on' => '2026-11-06']);

        $this->putJson("/api/admin/commissions/agreements/{$acuerdo->id}", ['ends_on' => '2026-11-01'], $this->h)
            ->assertStatus(422)
            ->assertJsonPath('errors.ends_on', ['El fin no puede ser anterior al inicio del acuerdo (06-11-2026).']);
        $this->assertNull($acuerdo->fresh()->ends_on);

        $this->putJson("/api/admin/commissions/agreements/{$acuerdo->id}", ['ends_on' => '2026-11-30'], $this->h)->assertOk();

        $this->putJson("/api/admin/commissions/agreements/{$acuerdo->id}", ['starts_on' => '2026-12-01'], $this->h)
            ->assertStatus(422)
            ->assertJsonPath('errors.starts_on', ['El inicio no puede ser posterior al fin del acuerdo (30-11-2026).']);
        $this->assertSame('2026-11-06', $acuerdo->fresh()->starts_on->toDateString());

        // Igual al fin vale (un trato de un día), y borrar el fin también.
        $this->putJson("/api/admin/commissions/agreements/{$acuerdo->id}", ['starts_on' => '2026-11-30'], $this->h)->assertOk();
        $this->putJson("/api/admin/commissions/agreements/{$acuerdo->id}", ['ends_on' => null], $this->h)->assertOk();
        $this->assertNull($acuerdo->fresh()->ends_on);
    }

    // ────────────────────────────────────────────────────────────────────────

    private function abrirCaja(): void
    {
        app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);
    }

    private function entrenador(): Trainer
    {
        static $n = 0;
        $n++;

        return Trainer::create(['full_name' => 'Entrenador '.$n, 'document' => (string) (61000000 + $n),
            'email' => 'entrenador-'.$n.'-'.uniqid().'@ironbody.test', 'status' => 'active']);
    }

    private function cliente(): Member
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => 'Clienta '.$n, 'email' => 'clienta-'.$n.'-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'status' => 'active']);

        return Member::create(['full_name' => 'Clienta '.$n, 'email' => $user->email, 'document_number' => (string) (62000000 + $n),
            'status' => Member::STATUS_ACTIVE, 'user_id' => $user->id]);
    }

    /** Un acuerdo por el endpoint real; las fechas, si las hay, por la edición real. */
    private function acuerdo(array $extra = []): TrainerCommissionAgreement
    {
        $id = $this->postJson('/api/admin/commissions/agreements', array_merge([
            'trainer_id' => $this->entrenador()->id, 'member_id' => $this->cliente()->id,
            'amount' => 50000, 'payer' => 'trainer',
        ], $extra), $this->h)->assertCreated()->json('id');

        return TrainerCommissionAgreement::findOrFail($id);
    }

    private function cobrar(TrainerCommissionAgreement $a, string $mes, array $cuerpo = []): TestResponse
    {
        return $this->postJson("/api/admin/commissions/agreements/{$a->id}/charge", array_merge(['period' => $mes], $cuerpo), $this->h);
    }

    /** @return array<string, mixed> */
    private function panel(string $mes): array
    {
        return $this->getJson('/api/admin/commissions/board?period='.$mes, $this->h)->assertOk()->json();
    }

    /** Lo que un rechazo no puede tocar. */
    private function huella(): array
    {
        $tablas = ['trainer_commission_charges', 'receivables', 'receivable_payments', 'audit_logs'];

        return array_combine($tablas, array_map(fn (string $t) => DB::table($t)->count(), $tablas));
    }

    /**
     * Lo que deja una reclasificación, con las piezas hechas por los caminos
     * reales: el cobro de mostrador con su recibo, el acuerdo, el mes abierto y
     * el abono que lo salda. La constancia se escribe aquí porque la escribe el
     * procedimiento de reclasificación (BE-B), que no es lo que se prueba.
     */
    private function reclasificacion(): PaymentReclassification
    {
        $cliente = $this->cliente();
        $plan = Plan::create(['name' => 'Comisión de personalizados', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        $pagoId = $this->postJson('/api/payments', ['user_id' => $cliente->user_id, 'plan_id' => $plan->id, 'amount' => 50000,
            'method' => 'cash', 'status' => 'paid'], $this->h)->assertCreated()->json('id');
        $this->postJson('/api/admin/receipts', ['source_type' => 'payment', 'source_id' => $pagoId], $this->h)->assertCreated();
        $pago = Payment::findOrFail($pagoId);

        $acuerdoId = $this->postJson('/api/admin/commissions/agreements', ['trainer_id' => $this->entrenador()->id,
            'member_id' => $cliente->id, 'amount' => 50000, 'payer' => 'member', 'starts_on' => '2026-11-17'], $this->h)
            ->assertCreated()->json('id');
        $cobro = TrainerCommissionCharge::findOrFail($this->cobrar(TrainerCommissionAgreement::findOrFail($acuerdoId), '2026-11')
            ->assertCreated()->json('charge.id'));
        $this->postJson("/api/admin/receivables/{$cobro->receivable_id}/payments", ['amount' => 50000, 'method' => 'cash'], $this->h)->assertCreated();

        return PaymentReclassification::create([
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
            'original_receipt_id' => Receipt::query()->where('number', 'RG-000001')->value('id'),
            'reason' => 'El piso del entrenador se cobró como venta de un plan.',
            'performed_by' => $this->admin->id,
            'performed_by_name' => $this->admin->name,
            'performed_at' => now()->addHour(),
            'procedure' => 'prueba',
        ])->refresh();
    }
}
