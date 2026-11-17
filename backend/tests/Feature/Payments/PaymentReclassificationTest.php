<?php

namespace Tests\Feature\Payments;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AuditLog;
use App\Models\ElectronicInvoice;
use App\Models\EmployeeAccess;
use App\Models\Identity;
use App\Models\Member;
use App\Models\MembershipAdjustment;
use App\Models\Payment;
use App\Models\PaymentReclassification;
use App\Models\Plan;
use App\Models\Receipt;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\RolePermission;
use App\Models\Trainer;
use App\Models\TrainerCommissionAgreement;
use App\Models\TrainerCommissionCharge;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftReport;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\CashShiftTotalsService;
use App\Services\Caja\ReceivableService;
use App\Services\Payments\PaymentReclassifier;
use App\Services\Reports\MoneyLedger;
use App\Services\Reports\ReportRange;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RECLASIFICAR UN COBRO QUE YA ENTRÓ (docs/ops/RECLASIFICACION-DE-COBROS.md).
 *
 * El caso, sintético y con los relojes en UTC: el piso mensual que la socia le
 * paga a su entrenador externo se cobró en el mostrador como el plan «Comisión
 * de personalizados» a las 20:43 de Bogotá —las 01:43 UTC del DÍA SIGUIENTE—,
 * encadenado entre una mensualidad y una anualidad, con su recibo emitido, un
 * borrador de factura `pending` y el turno ya cerrado y firmado. El entrenador
 * no existe como tal: es un socio sin identidad.
 *
 * Todo entra por los endpoints y servicios reales (cobro, recibo, cierre); el
 * comando también, por Artisan. Nada se reescribe en la prueba.
 */
class PaymentReclassificationTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'Era el piso mensual del entrenador de la socia, no la venta del plan Comisión';

    private Admin $super;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.company.name' => 'IRON BODY NEIVA S.A.S.',
            'billing.company.nit' => '900123456',
            'billing.company.dv' => '7',
            'billing.company.address' => 'Calle 10 # 5-20, Neiva',
            'billing.company.phone' => '3001234567',
        ]);

        $this->super = Admin::create([
            'name' => 'Dueña Supervisión', 'email' => 'reclasifica-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->cajero = Admin::create([
            'name' => 'Cajero Nueve', 'email' => 'cajero-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── El caso ─────────────────────────────────────────────────────────────

    /** Un instante de Bogotá, como reloj UTC. */
    private function bogota(string $cuando): void
    {
        Carbon::setTestNow(Carbon::parse($cuando, 'America/Bogota')->utc());
    }

    private function persona(string $nombre, string $documento, string $telefono, ?string $plan = null): Member
    {
        $user = User::create([
            'name' => $nombre, 'email' => 'p-'.uniqid().'@ironbody.test', 'password' => 'secret-password',
            'status' => 'active', 'document' => $documento, 'phone' => $telefono, 'plan' => $plan,
        ]);

        return Member::create([
            'user_id' => $user->id, 'full_name' => $nombre, 'document_number' => $documento, 'phone' => $telefono,
            'status' => Member::STATUS_ACTIVE, 'access_hash' => 'tok-'.uniqid(),
        ]);
    }

    private function cobrar(Member $socio, Plan $plan, string $medio, array $extra = []): Payment
    {
        $id = $this->postJson('/api/payments', array_merge([
            'user_id' => $socio->user_id, 'plan_id' => $plan->id, 'amount' => (float) $plan->price,
            'method' => $medio, 'status' => 'paid', 'reference' => 'REC-'.(1000 + Payment::count() + 1),
        ], $extra), $this->actingAsAdmin($this->cajero))->assertStatus(201)->json('id');

        return Payment::findOrFail($id);
    }

    /**
     * Mensualidad (turno de octubre), y en el turno del 06-11: otro socio paga
     * por transferencia, la socia paga el «plan comisión» a las 20:43 y la
     * anualidad a las 20:45 con inicio pedido el mismo día (anterior a la base
     * de su cadena). Recibo del cobro, borrador de factura y cierre a las 21:30.
     *
     * @return array<string, mixed>
     */
    private function escenario(array $op = []): array
    {
        $mensual = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $comision = Plan::create(['name' => 'Comisión de personalizados', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        $anual = Plan::create(['name' => 'Anualidad', 'price' => 600000, 'duration_days' => 365, 'active' => true]);
        Plan::create(['name' => 'ACCESO EMPLEADOS', 'price' => 1, 'duration_days' => 365, 'active' => true]);

        $socia = $this->persona('Olga Socia Prueba', '52111222', '3105550001');
        $ficha = $this->persona('Carlos Entrenador Prueba', '1075222333', '3105550002', 'ACCESO EMPLEADOS');
        $otro = $this->persona('Pedro Otro Socio', '80111999', '3105550003');

        $this->bogota('2026-10-10 08:00');
        $turnoOctubre = app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);
        $pagoMensual = $this->cobrar($socia, $mensual, 'cash');
        app(CashShiftService::class)->close($this->cajero, CashShiftType::GYM);

        $this->bogota('2026-11-06 06:00');
        $turno = app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);
        $this->bogota('2026-11-06 09:15');
        $pagoOtro = $this->cobrar($otro, $mensual, 'transfer');
        $this->bogota('2026-11-06 20:43');
        $pago = $this->cobrar($socia, $comision, $op['method'] ?? 'cash', $op['extra'] ?? []);
        $recibo = $this->postJson('/api/admin/receipts', ['source_type' => 'payment', 'source_id' => $pago->id],
            $this->actingAsAdmin($this->cajero))->assertStatus(201)->json('data.0');
        // El borrador lo crea el propio alta del cobro (InvoicingService), como
        // nació el del caso real: `pending` y sin enviar.
        $factura = ElectronicInvoice::query()->where('source_type', $pago->getMorphClass())
            ->where('source_id', $pago->id)->sole();
        $this->assertSame('pending', $factura->status->value);
        if (isset($op['invoice_status'])) {
            $factura->forceFill(['status' => $op['invoice_status']])->save();
        }
        $this->bogota('2026-11-06 20:45');
        $pagoAnual = $this->cobrar($socia, $anual, 'card', ['starts_on' => '2026-11-06']);

        if (! ($op['keep_open'] ?? false)) {
            $this->bogota('2026-11-06 21:30');
            app(CashShiftService::class)->close($this->cajero, CashShiftType::GYM);
        }

        $this->bogota('2026-11-17 10:00');

        return compact('mensual', 'comision', 'anual', 'socia', 'ficha', 'otro', 'turnoOctubre', 'turno',
            'pagoMensual', 'pagoOtro', 'pago', 'pagoAnual', 'recibo', 'factura');
    }

    /** @return array<string, mixed> el informe JSON del comando, con su código de salida */
    private function reclasificar(array $e, array $opciones = []): array
    {
        $codigo = Artisan::call('payments:reclassify-to-commission', array_merge([
            '--payment' => $e['pago']->id,
            '--member' => $e['socia']->id,
            '--trainer-from-member' => $e['ficha']->id,
            '--actor' => $this->super->id,
            '--reason' => self::MOTIVO,
            '--json' => true,
        ], $opciones));
        $informe = json_decode(Artisan::output(), true);
        $this->assertIsArray($informe, 'el comando imprime JSON: '.Artisan::output());
        $this->assertSame($codigo, $informe['exit_code'] ?? null, 'el código de salida es el del informe');

        return $informe;
    }

    /**
     * Todo lo que el procedimiento podría tocar, contado y con huella: si el
     * ensayo o un rechazo cambiara una sola fila, cambia.
     *
     * @return array<string, mixed>
     */
    private function huella(): array
    {
        $tablas = ['payments', 'payment_splits', 'users', 'members', 'receivables', 'receivable_payments',
            'receivable_payment_splits', 'trainers', 'identities', 'trainer_roles', 'trainer_audit_logs',
            'trainer_commission_agreements', 'trainer_commission_charges', 'payment_reclassifications',
            'electronic_invoices', 'electronic_invoice_logs', 'audit_logs', 'member_realtime_events', 'receipts',
            'receipt_sources', 'cash_refunds', 'cash_shifts', 'plans', 'employee_accesses', 'notifications'];
        $out = [];
        foreach ($tablas as $t) {
            $out[$t] = DB::table($t)->count();
        }
        $out['firma'] = md5(json_encode([
            DB::table('payments')->orderBy('id')->get(['id', 'status', 'period_start', 'period_end', 'updated_at']),
            DB::table('users')->orderBy('id')->get(['id', 'plan', 'membership_start_date', 'membership_end_date', 'updated_at']),
            DB::table('members')->orderBy('id')->get(['id', 'identity_id', 'updated_at']),
            DB::table('electronic_invoices')->orderBy('id')->get(['id', 'status', 'retry_allowed', 'updated_at']),
            DB::table('cash_shifts')->orderBy('id')->get(['id', 'status', 'sales_total', 'operations_count', 'updated_at']),
            DB::table('plans')->orderBy('id')->get(['id', 'active', 'sellable', 'archived_at', 'updated_at']),
        ]));

        return $out;
    }

    /** @return array<string, ?string> el cobro como se guarda, para compararlo */
    private function fila(Payment $p): array
    {
        return (array) DB::table('payments')->where('id', $p->id)->first(['status', 'period_start', 'period_end', 'updated_at']);
    }

    private function libroDelDia(string $dia): MoneyLedger
    {
        return new MoneyLedger(ReportRange::fromRequest(['from' => $dia]));
    }

    // ── Ensayo ──────────────────────────────────────────────────────────────

    public function test_el_ensayo_hace_todo_lo_deshace_y_no_escribe_nada(): void
    {
        $e = $this->escenario();
        $antes = $this->huella();

        $r = $this->reclasificar($e);

        $this->assertSame(PaymentReclassifier::STATUS_DRY_RUN, $r['status'], $r['message']);
        $this->assertSame(0, $r['exit_code']);
        $this->assertSame([], array_values(array_filter($r['invariants'], fn ($i) => ! $i['ok'])), 'todas las invariantes se cumplen en el ensayo');
        $this->assertSame([], $r['unexpected_writes']);
        // Hizo de verdad el trabajo (y lo deshizo): escribió en las tablas del contrato.
        $this->assertSame(1, $r['writes']['insert receivable_payments']);
        $this->assertSame(1, $r['writes']['insert payment_reclassifications']);
        $this->assertSame(1, $r['writes']['insert trainers']);
        $this->assertSame('2027-12-09', $r['membership']['end_before']);
        $this->assertSame('2027-11-09', $r['membership']['end_after']);
        $this->assertSame(30, $r['membership']['days_removed']);

        $this->assertSame($antes, $this->huella(), 'el ensayo no deja ni una fila');
        $this->assertSame('paid', $e['pago']->fresh()->status);
    }

    // ── Aplicar ─────────────────────────────────────────────────────────────

    public function test_aplicar_deja_exactamente_el_estado_esperado(): void
    {
        $e = $this->escenario();
        $turno = $e['turno']->fresh();
        $congelado = $turno->only(['sales_total', 'cash_sales_total', 'transfer_total', 'card_total', 'operations_count', 'expected_amount']);
        $diaAntes = $this->libroDelDia('2026-11-06');
        [$totalAntes, $mediosAntes, $noEntroAntes] = [$diaAntes->totals(), $diaAntes->byMethod(), $diaAntes->reversals()];
        $diaSiguienteAntes = $this->libroDelDia('2026-11-07')->totals();
        $otroAntes = $this->fila($e['pagoOtro']);
        $mensualAntes = $this->fila($e['pagoMensual']);
        $recibos = Receipt::count();
        $pagos = Payment::count();
        $this->assertSame('2027-12-09', $e['socia']->user->fresh()->membershipEndDate);
        $this->assertSame('2026-12-09', $e['pagoAnual']->fresh()->period_start->toDateString());

        $r = $this->reclasificar($e, ['--apply' => true]);

        $this->assertSame(PaymentReclassifier::STATUS_APPLIED, $r['status'], json_encode($r['invariants']).' '.$r['message']);
        $this->assertSame(0, $r['exit_code']);

        // El cobro original: anulado y sin periodo. Los anteriores, intactos.
        $pago = $e['pago']->fresh();
        $this->assertSame('cancelled', $pago->status);
        $this->assertNull($pago->period_start);
        $this->assertNull($pago->period_end);
        $this->assertSame($mensualAntes, $this->fila($e['pagoMensual']));
        $this->assertSame($otroAntes, $this->fila($e['pagoOtro']), 'el cobro de otro socio no se toca');

        // La anualidad, recalculada por MembershipPeriod::revert, y 30 días menos.
        $anual = $e['pagoAnual']->fresh();
        $this->assertSame('2026-11-09', $anual->period_start->toDateString());
        $this->assertSame('2027-11-09', $anual->period_end->toDateString());
        $this->assertSame('2027-11-09', $e['socia']->user->fresh()->membershipEndDate);
        $this->assertSame('Anualidad', $e['socia']->user->fresh()->plan);

        // El entrenador: alta oficial con los datos de su ficha, sin roles, y la
        // ficha de socio SIN enlazar a la identidad.
        $entrenador = Trainer::findOrFail($r['created']['trainer_id']);
        $this->assertTrue($r['created']['trainer_created']);
        $this->assertSame('Carlos Entrenador Prueba', $entrenador->full_name);
        $this->assertSame('1075222333', $entrenador->document);
        $this->assertSame('3105550002', $entrenador->phone);
        $this->assertSame('Independiente', $entrenador->contract_type);
        $this->assertTrue($entrenador->isActive());
        $this->assertSame(0, $entrenador->roleAssignments()->count());
        $this->assertNotNull($entrenador->identity_id);
        $this->assertSame('1075222333', $entrenador->identity->document_normalized);
        $this->assertNull($e['ficha']->fresh()->identity_id, 'la ficha de socio no se enlaza a la identidad (decisión del 15-10)');

        // El acuerdo.
        $acuerdo = TrainerCommissionAgreement::findOrFail($r['created']['agreement_id']);
        $this->assertSame([$entrenador->id, $e['socia']->id, '50000.00', 'member', 30, false, '2026-11-06', true],
            [$acuerdo->trainer_id, $acuerdo->member_id, (string) $acuerdo->amount, $acuerdo->payer, $acuerdo->cycle_days,
                $acuerdo->blocks_app, $acuerdo->starts_on->toDateString(), $acuerdo->active]);
        $this->assertStringContainsString("cobro #{$pago->id}", (string) $acuerdo->notes);

        // El cobro del mes y su deuda: con la fecha en que ENTRÓ el dinero, saldada.
        $cobro = TrainerCommissionCharge::findOrFail($r['created']['charge_id']);
        $this->assertSame(['2026-11-01', '2026-11-06', '2026-12-05', '50000.00'],
            [$cobro->period->toDateString(), $cobro->covers_from->toDateString(), $cobro->covers_to->toDateString(), (string) $cobro->amount]);
        $this->assertSame('2026-11-07 01:43:00', $cobro->created_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-17 15:00:00', $cobro->updated_at->utc()->format('Y-m-d H:i:s'), 'updated_at es la hora real');
        $deuda = Receivable::findOrFail($r['created']['receivable_id']);
        $this->assertSame([DebtorType::MEMBER, $e['socia']->id, Receivable::STATUS_PAID, '0.00', false],
            [$deuda->debtor_type, (int) $deuda->debtor_id, $deuda->status, $deuda->balance()->toDatabase(), $deuda->blocks_benefits]);
        $this->assertSame('2026-11-07 01:43:00', $deuda->created_at->utc()->format('Y-m-d H:i:s'));

        // El abono: en el turno ORIGINAL, a las 01:43 UTC (20:43 en Bogotá), de quien recibió el dinero.
        $abono = ReceivablePayment::findOrFail($r['created']['receivable_payment_id']);
        $this->assertSame([$turno->id, '50000.00', 'cash', ReceivablePayment::STATUS_APPLIED, '50000.00', '0.00', $this->cajero->id, 'Cajero Nueve', 'reclass:payment:'.$pago->id],
            [$abono->cash_shift_id, (string) $abono->amount, $abono->method, $abono->status, (string) $abono->balance_before,
                (string) $abono->balance_after, (int) $abono->created_by, $abono->created_by_name, $abono->client_request_id]);
        $this->assertSame('2026-11-07 01:43:00', $abono->created_at->utc()->format('Y-m-d H:i:s'));

        // La factura: cancelada, sin reintento, con su bitácora.
        $factura = $e['factura']->fresh();
        $this->assertSame('cancelled', $factura->status->value);
        $this->assertFalse((bool) $factura->retry_allowed);
        $this->assertStringContainsString('reclasific', (string) $factura->cancellation_reason);
        $this->assertSame(1, DB::table('electronic_invoice_logs')->where('electronic_invoice_id', $factura->id)->where('action', 'cancel')->count());

        // La constancia.
        $c = PaymentReclassification::where('payment_id', $pago->id)->sole();
        $this->assertSame($r['reclassification_id'], $c->id);
        $this->assertSame([PaymentReclassification::KIND_TRAINER_COMMISSION, '50000.00', 'cash', $turno->id, $acuerdo->id, $cobro->id, $deuda->id, $abono->id, (int) Receipt::where('number', $e['recibo']['number'])->value('id'), $factura->id, '2027-12-09', '2027-11-09', 30, $this->super->id, 'payments:reclassify-to-commission@1'],
            [$c->kind, (string) $c->amount, $c->method, $c->cash_shift_id, $c->agreement_id, $c->charge_id, $c->receivable_id,
                $c->receivable_payment_id, $c->original_receipt_id, $c->cancelled_invoice_id, $c->membership_end_before->toDateString(),
                $c->membership_end_after->toDateString(), $c->membership_days_removed, $c->performed_by, $c->procedure]);
        $this->assertSame('2026-11-07 01:43:00', $c->economic_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-17 15:00:00', $c->performed_at->utc()->format('Y-m-d H:i:s'));
        // La foto guardada dice lo que dirán los informes con la constancia: los
        // anulados de verdad, igual; el reclasificado, aparte.
        $this->assertSame($c->before_snapshot['day']['not_collected']['cancelled_payments'], $c->after_snapshot['day']['not_collected']['cancelled_payments']);
        $this->assertEquals(['amount' => 50000, 'count' => 1], $c->after_snapshot['day']['not_collected']['reclassified_payments']);
        $this->assertSame($c->before_snapshot['shift']['live'], $c->after_snapshot['shift']['live']);
        $fotos = json_encode([$c->before_snapshot, $c->after_snapshot]);
        foreach (['Olga', 'Carlos', 'Pedro', '52111222', '1075222333', '3105550001', 'Cajero Nueve'] as $personal) {
            $this->assertStringNotContainsString($personal, $fotos, 'las fotos no llevan datos personales');
        }
        $this->assertStringNotContainsString('Olga', json_encode($r), 'el informe tampoco');

        // La traza de la reclasificación, en la historia del cobro.
        $traza = AuditLog::where('entity', 'pago')->where('entity_id', (string) $pago->id)->get()
            ->first(fn (AuditLog $l) => ($l->metadata['event'] ?? null) === 'payment.reclassified');
        $this->assertNotNull($traza);
        $this->assertSame((string) $this->super->id, $traza->actor_id);
        $this->assertSame(PaymentReclassifier::USER_AGENT, $traza->user_agent);
        $this->assertSame($c->id, $traza->metadata['reclassification_id']);

        // El turno: el cierre firmado no cambia y el detalle sigue cuadrando con él.
        $this->assertSame($congelado, $turno->fresh()->only(array_keys($congelado)));
        $vivo = app(CashShiftTotalsService::class)->for($turno->fresh());
        $this->assertSame([(string) $congelado['sales_total'], (string) $congelado['cash_sales_total'], (string) $congelado['transfer_total'], (int) $congelado['operations_count']],
            [$vivo['gross_total'], $vivo['cash_total'], $vivo['transfer_total'], $vivo['operations_count']]);
        $this->assertTrue(app(CashShiftReport::class)->for($turno->fresh())['consistency']['matches']);

        // El día económico (06-11 en Bogotá) recauda lo mismo y por los mismos
        // medios; el 07-11 (el día UTC de las 01:43) tampoco cambia.
        $dia = $this->libroDelDia('2026-11-06');
        $this->assertSame($totalAntes['collected'], $dia->totals()['collected']);
        $this->assertSame($totalAntes['count'], $dia->totals()['count']);
        $this->assertSame($mediosAntes, $dia->byMethod());
        $this->assertSame($diaSiguienteAntes, $this->libroDelDia('2026-11-07')->totals());
        // «Lo que no entró» no lo da por perdido: lo enseña aparte.
        $this->assertSame($noEntroAntes['cancelled_payments'], $dia->reversals()['cancelled_payments']);
        $this->assertSame(['amount' => 50000.0, 'count' => 1], $dia->reversals()['reclassified_payments']);

        // El observador del piso se disparó con el abono, pero el entrenador no
        // tiene ficha de socio enlazada: no hay puerta que abrirle.
        $this->assertSame(0, EmployeeAccess::count());

        // Sin deuda abierta, sin recibo nuevo, sin devolución, sin cobro nuevo.
        $this->assertSame(0, Receivable::query()->outstanding()->count());
        $this->assertSame($recibos, Receipt::count());
        $this->assertSame(0, DB::table('cash_refunds')->count());
        $this->assertSame($pagos, Payment::count());
        $this->assertSame([], $r['unexpected_writes']);
    }

    public function test_la_segunda_ejecucion_no_hace_nada(): void
    {
        $e = $this->escenario();
        $primera = $this->reclasificar($e, ['--apply' => true]);
        $this->assertSame(PaymentReclassifier::STATUS_APPLIED, $primera['status'], $primera['message']);
        $antes = $this->huella();

        $segunda = $this->reclasificar($e, ['--apply' => true]);

        $this->assertSame(PaymentReclassifier::STATUS_ALREADY, $segunda['status']);
        $this->assertSame(0, $segunda['exit_code']);
        $this->assertSame($primera['reclassification_id'], $segunda['reclassification_id']);
        $this->assertSame($antes, $this->huella(), 'ya aplicada: cero escrituras');
        // Y el ensayo sobre un cobro ya reclasificado, igual.
        $this->assertSame(PaymentReclassifier::STATUS_ALREADY, $this->reclasificar($e)['status']);
        $this->assertSame($antes, $this->huella());
    }

    /**
     * Un cobro REPARTIDO (20.000 en efectivo + 30.000 por transferencia): el
     * abono replica sus partes, así que el arqueo por medio del turno y del día
     * no se mueve ni un peso entre medios.
     */
    public function test_un_cobro_mixto_se_replica_parte_a_parte(): void
    {
        $e = $this->escenario(['method' => 'mixed', 'extra' => ['splits' => [
            ['method' => 'cash', 'amount' => 20000], ['method' => 'transfer', 'amount' => 30000],
        ]]]);
        $turno = $e['turno']->fresh();
        $antes = app(CashShiftTotalsService::class)->for($turno);
        $mediosAntes = $this->libroDelDia('2026-11-06')->byMethod();

        $r = $this->reclasificar($e, ['--apply' => true]);

        $this->assertSame(PaymentReclassifier::STATUS_APPLIED, $r['status'], $r['message']);
        $abono = ReceivablePayment::with('splits')->findOrFail($r['created']['receivable_payment_id']);
        $this->assertSame('mixed', $abono->method);
        $this->assertSame([['cash', '20000.00'], ['transfer', '30000.00']],
            $abono->splits->sortBy('method')->map(fn ($p) => [$p->method, (string) $p->amount])->values()->all());
        $despues = app(CashShiftTotalsService::class)->for($turno->fresh());
        $this->assertSame([$antes['cash_total'], $antes['transfer_total'], $antes['gross_total'], $antes['operations_count']],
            [$despues['cash_total'], $despues['transfer_total'], $despues['gross_total'], $despues['operations_count']]);
        $this->assertSame((string) $turno->cash_sales_total, $despues['cash_total']);
        $this->assertSame($mediosAntes, $this->libroDelDia('2026-11-06')->byMethod());
    }

    /** Quién recibió el dinero y desde cuándo vale el trato, cuando se indican. */
    public function test_received_by_y_starts_on_explicitos(): void
    {
        $e = $this->escenario();

        $r = $this->reclasificar($e, ['--apply' => true, '--received-by' => $this->super->id, '--starts-on' => '2026-11-01']);

        $this->assertSame(PaymentReclassifier::STATUS_APPLIED, $r['status'], $r['message']);
        $abono = ReceivablePayment::findOrFail($r['created']['receivable_payment_id']);
        $this->assertSame([$this->super->id, 'Dueña Supervisión'], [(int) $abono->created_by, $abono->created_by_name]);
        $this->assertSame('2026-11-01', TrainerCommissionAgreement::findOrFail($r['created']['agreement_id'])->starts_on->toDateString());
        $cobro = TrainerCommissionCharge::findOrFail($r['created']['charge_id']);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$cobro->covers_from->toDateString(), $cobro->covers_to->toDateString()]);
        // El dinero sigue fechado cuando entró, aunque el trato empiece antes.
        $this->assertSame('2026-11-07 01:43:00', $abono->created_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_reutiliza_el_entrenador_que_ya_existe_con_ese_documento(): void
    {
        $e = $this->escenario();
        $existente = Trainer::create(['full_name' => 'Carlos E.', 'document' => '1.075.222.333', 'status' => 'active']);

        $r = $this->reclasificar($e, ['--apply' => true]);

        $this->assertSame(PaymentReclassifier::STATUS_APPLIED, $r['status'], $r['message']);
        $this->assertSame($existente->id, $r['created']['trainer_id']);
        $this->assertFalse($r['created']['trainer_created']);
        $this->assertSame(1, Trainer::count());
    }

    /**
     * Con un entrenador que SÍ tiene ficha de socio enlazada a su identidad, el
     * abono dispara CommissionAccessObserver como lo haría settle(): su acceso
     * de empleado queda abierto mientras dure el tramo pagado. Es una escritura
     * prevista (lista blanca) y solo sobre SU usuario.
     */
    public function test_el_piso_reclasificado_abre_la_puerta_al_entrenador_enlazado(): void
    {
        $e = $this->escenario();
        $identidad = Identity::create(['document_normalized' => '1075222333']);
        $e['ficha']->forceFill(['identity_id' => $identidad->id])->save();
        $entrenador = Trainer::create(['full_name' => 'Carlos Entrenador Prueba', 'document' => '1075222333',
            'status' => 'active', 'identity_id' => $identidad->id]);

        $r = $this->reclasificar($e, ['--apply' => true, '--trainer' => $entrenador->id, '--trainer-from-member' => null]);

        $this->assertSame(PaymentReclassifier::STATUS_APPLIED, $r['status'], $r['message']);
        $this->assertSame([], $r['unexpected_writes']);
        $acceso = EmployeeAccess::where('user_id', $e['ficha']->user_id)->sole();
        $this->assertSame(['commission', true, '2026-11-06', '2026-12-05'],
            [$acceso->source, (bool) $acceso->active, $acceso->starts_on->toDateString(), $acceso->ends_on->toDateString()]);
        $this->assertSame(1, EmployeeAccess::count(), 'solo el entrenador del acuerdo');
    }

    /**
     * LA COMPENSACIÓN DOCUMENTADA: si hubiera que deshacer una reclasificación,
     * se hace con las vías oficiales y SIN borrar la constancia. Anular el cobro
     * del mes (revierte su abono y anula la deuda), reconfirmar el cobro
     * original y terminar el acuerdo. El turno vuelve a cuadrar con su cierre,
     * el día recauda lo mismo, la vigencia vuelve a su fin, la constancia sigue
     * y las vistas dejan de pintarlo como reclasificado.
     */
    public function test_la_compensacion_documentada_deshace_sin_borrar_la_constancia(): void
    {
        $e = $this->escenario();
        $turno = $e['turno']->fresh();
        $congelado = $turno->only(['sales_total', 'cash_sales_total', 'operations_count']);
        $diaAntes = $this->libroDelDia('2026-11-06')->totals();
        $r = $this->reclasificar($e, ['--apply' => true]);
        $h = $this->actingAsAdmin($this->super);
        $this->travel(1)->hours();

        // Sin declararlo, anular el mes reclasificado se rechaza y no toca nada:
        // su dinero es el del cobro anulado y desaparecería de la caja.
        $abonosAntes = \App\Models\ReceivablePayment::where('status', 'applied')->count();
        $this->postJson("/api/admin/commissions/charges/{$r['created']['charge_id']}/cancel",
            ['reason' => 'Anulación por descuido de un mes reclasificado'], $h)
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
        $this->assertSame($abonosAntes, \App\Models\ReceivablePayment::where('status', 'applied')->count(),
            'el rechazo no revierte ningún abono');

        // La compensación documentada lo declara explícitamente.
        $this->postJson("/api/admin/commissions/charges/{$r['created']['charge_id']}/cancel",
            ['reason' => "Compensación de la reclasificación #{$r['reclassification_id']}", 'reclassification_compensation' => true], $h)->assertOk();
        $this->patchJson("/api/payments/{$e['pago']->id}",
            ['status' => 'paid', 'reason' => "Compensación de la reclasificación #{$r['reclassification_id']}"], $h)->assertOk();
        $this->deleteJson("/api/admin/commissions/agreements/{$r['created']['agreement_id']}", [], $h)->assertOk();

        $pago = $e['pago']->fresh();
        $this->assertSame('paid', $pago->status);
        $this->assertSame('2026-11-07 01:43:00', $pago->paid_at->utc()->format('Y-m-d H:i:s'), 'conserva la fecha en que entró');
        $this->assertSame('2027-12-09', $e['socia']->user->fresh()->membershipEndDate, 'la vigencia vuelve a su fin');
        $this->assertSame(ReceivablePayment::STATUS_REVERSED, ReceivablePayment::findOrFail($r['created']['receivable_payment_id'])->status);
        $this->assertSame(Receivable::STATUS_CANCELLED, Receivable::findOrFail($r['created']['receivable_id'])->status);
        $this->assertFalse(TrainerCommissionAgreement::findOrFail($r['created']['agreement_id'])->active);
        $this->assertSame(1, PaymentReclassification::count(), 'la constancia no se borra');
        $this->assertSame('cancelled', $e['factura']->fresh()->status->value, 'el borrador sigue cancelado: facturar es otra decisión');

        $vivo = app(CashShiftTotalsService::class)->for($turno->fresh());
        $this->assertSame([(string) $congelado['sales_total'], (string) $congelado['cash_sales_total'], (int) $congelado['operations_count']],
            [$vivo['gross_total'], $vivo['cash_total'], $vivo['operations_count']]);
        $this->assertTrue(app(CashShiftReport::class)->for($turno->fresh())['consistency']['matches']);
        $this->assertSame($diaAntes, $this->libroDelDia('2026-11-06')->totals());
        $this->assertSame(['amount' => 0.0, 'count' => 0], $this->libroDelDia('2026-11-06')->reversals()['reclassified_payments']);

        // Las vistas: vuelve a ser la venta de un plan.
        $historia = $this->getJson("/api/admin/users/{$e['socia']->user_id}/payment-history", $h)->json('payments');
        $fila = collect($historia)->firstWhere('id', $e['pago']->id);
        $this->assertSame([false, 'paid', null], [$fila['reclassified'], $fila['status_group'], $fila['reclassification']]);
        $app = collect($this->getJson('/api/app/payments', ['Authorization' => 'Bearer '.$e['socia']->access_hash])->json('data'))
            ->firstWhere('reference', $pago->reference);
        $this->assertSame(['approved', 'Comisión de personalizados', false], [$app['status'], $app['product'], $app['reclassified']]);
        $lineas = collect($this->getJson("/api/admin/caja/shifts/{$turno->id}", $h)->json('transactions'));
        $this->assertFalse($lineas->firstWhere('id', $pago->id)['reclassified']);
        $this->assertSame('reversed', $lineas->firstWhere('id', 'abono-'.$r['created']['receivable_payment_id'])['status']);
    }

    // ── Precondiciones: cada una rechaza y no escribe ───────────────────────

    /** @return iterable<string, array{0: array<string, mixed>, 1: \Closure|null, 2: string}> */
    public static function precondiciones(): iterable
    {
        yield 'factura validada' => [['invoice_status' => 'validated'], null, 'invoice_not_pending'];
        yield 'factura en proceso' => [['invoice_status' => 'processing'], null, 'invoice_not_pending'];
        yield 'turno abierto' => [['keep_open' => true], null, 'shift_open'];
        yield 'factura pedida por el cliente' => [[], fn (array $e) => $e['pago']->forceFill(['invoice_requested' => true])->save(), 'invoice_requested'];
        yield 'factura con número' => [[], fn (array $e) => $e['factura']->forceFill(['full_number' => 'IBFE77'])->save(), 'invoice_emitted'];
        yield 'cadena rota (vencimiento movido a mano)' => [[], fn (array $e) => User::whereKey($e['socia']->user_id)->update(['membership_end_date' => '2027-12-20']), 'membership_chain_broken'];
        yield 'ajuste de días posterior' => [[], fn (array $e) => MembershipAdjustment::create([
            'user_id' => $e['socia']->user_id, 'kind' => 'days', 'amount' => 3, 'reason' => 'Prueba', 'created_by_name' => 'Prueba',
        ]), 'membership_adjusted'];
        yield 'membresía congelada' => [[], fn (array $e) => User::whereKey($e['socia']->user_id)->update(['membership_frozen_at' => now()]), 'membership_frozen'];
        yield 'cobro anulado' => [[], fn (array $e) => DB::table('payments')->where('id', $e['pago']->id)->update(['status' => 'cancelled']), 'payment_not_paid'];
        yield 'cobro de pasarela' => [[], fn (array $e) => DB::table('payments')->where('id', $e['pago']->id)->update(['origin' => 'gateway']), 'payment_not_counter'];
        yield 'la ficha no es la titular' => [[], fn (array $e) => null, 'member_not_holder'];
    }

    #[DataProvider('precondiciones')]
    public function test_cada_precondicion_rechaza_sin_escribir(array $op, ?\Closure $preparar, string $codigo): void
    {
        $e = $this->escenario($op);
        if ($preparar !== null) {
            $preparar($e);
        }
        $opciones = $codigo === 'member_not_holder' ? ['--member' => $e['otro']->id] : [];
        $antes = $this->huella();

        $r = $this->reclasificar($e, $opciones + ['--apply' => true]);

        $this->assertSame(PaymentReclassifier::STATUS_REJECTED, $r['status'], json_encode($r));
        $this->assertSame(1, $r['exit_code']);
        $this->assertSame($codigo, $r['problem']['code'], $r['problem']['message']);
        $this->assertSame($antes, $this->huella(), 'un rechazo no deja ni una fila');
    }

    public function test_un_actor_sin_permisos_o_inactivo_se_rechaza_sin_escribir(): void
    {
        $e = $this->escenario();
        $antes = $this->huella();

        $sinCaja = $this->reclasificar($e, ['--apply' => true, '--actor' => $this->cajero->id]);
        $this->assertSame('actor_forbidden', $sinCaja['problem']['code']);
        $this->assertStringContainsString('cash.gym.manage', $sinCaja['problem']['message']);

        $this->super->forceFill(['status' => 'inactive'])->save();
        $inactivo = $this->reclasificar($e, ['--apply' => true]);
        $this->assertSame('actor_invalid', $inactivo['problem']['code']);

        $this->assertSame($antes, $this->huella());
    }

    /**
     * Las cuatro llaves del contrato bastan con un entrenador que ya existe; dar
     * de alta uno nuevo pide además `trainers.manage` (es lo que exige
     * `POST /api/trainers`, y por consola no corre el middleware).
     */
    public function test_las_cuatro_llaves_bastan_y_el_alta_pide_trainers_manage(): void
    {
        $e = $this->escenario();
        $rol = 'Prueba-reclasificar';
        AdminRole::firstOrCreate(['name' => $rol], ['is_system' => false]);
        foreach (['payments.cancel', 'cash.gym.manage', 'commissions.manage', 'commissions.charge'] as $p) {
            RolePermission::updateOrCreate(['role' => $rol, 'permission' => $p], ['granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();
        $justo = Admin::create(['name' => 'Supervisora Justa', 'email' => 'justa-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => $rol, 'status' => 'active']);
        $antes = $this->huella();

        $alta = $this->reclasificar($e, ['--apply' => true, '--actor' => $justo->id]);
        $this->assertSame('actor_forbidden', $alta['problem']['code']);
        $this->assertStringContainsString('trainers.manage', $alta['problem']['message']);
        $this->assertSame($antes, $this->huella());

        $existente = Trainer::create(['full_name' => 'Carlos E.', 'document' => '99887766', 'status' => 'active']);
        $ok = $this->reclasificar($e, ['--apply' => true, '--actor' => $justo->id, '--trainer' => $existente->id, '--trainer-from-member' => null]);
        $this->assertSame(PaymentReclassifier::STATUS_APPLIED, $ok['status'], $ok['message']);
        $this->assertSame($justo->id, PaymentReclassification::sole()->performed_by);
    }

    public function test_dos_entrenadores_con_el_mismo_documento_no_se_adivinan(): void
    {
        $e = $this->escenario();
        Trainer::create(['full_name' => 'Carlos A', 'document' => '1075222333', 'status' => 'active']);
        Trainer::create(['full_name' => 'Carlos B', 'document' => '1075-222-333', 'status' => 'inactive']);
        $antes = $this->huella();

        $r = $this->reclasificar($e, ['--apply' => true]);

        $this->assertSame('trainer_ambiguous', $r['problem']['code']);
        $this->assertSame($antes, $this->huella());
    }

    public function test_las_opciones_invalidas_se_rechazan_en_el_borde(): void
    {
        $e = $this->escenario();
        $antes = $this->huella();

        $corto = $this->reclasificar($e, ['--reason' => 'corto']);
        $this->assertSame('invalid_options', $corto['problem']['code']);
        $los2 = $this->reclasificar($e, ['--trainer' => 5]);
        $this->assertSame('invalid_options', $los2['problem']['code']);
        $this->assertStringContainsString('no los dos', $los2['problem']['message']);

        $this->assertSame($antes, $this->huella());
    }

    // ── Invariantes: si una se rompe, ROLLBACK ──────────────────────────────

    /**
     * Se rompe el arqueo a mitad del procedimiento (el abono queda por 49.000):
     * el turno deja de cuadrar con su cierre, el día recauda distinto y hay una
     * escritura fuera de la lista blanca. Todo se deshace y sale con 2.
     */
    public function test_un_invariante_roto_deshace_todo(): void
    {
        $e = $this->escenario();
        $antes = $this->huella();
        Event::listen('eloquent.created: '.ReceivablePayment::class, function (ReceivablePayment $abono): void {
            DB::table('receivable_payments')->where('id', $abono->id)->update(['amount' => '49000.00']);
        });

        $r = $this->reclasificar($e, ['--apply' => true]);

        $this->assertSame(PaymentReclassifier::STATUS_INVARIANT, $r['status']);
        $this->assertSame(2, $r['exit_code']);
        $rotas = array_column(array_filter($r['invariants'], fn ($i) => ! $i['ok']), 'check');
        $this->assertContains('turno.cuadra_con_su_cierre', $rotas);
        $this->assertContains('dia.recaudado_total', $rotas);
        $this->assertContains('escrituras.lista_blanca', $rotas);
        $this->assertSame($antes, $this->huella(), 'ROLLBACK: nada quedó escrito');
    }

    /** Una sola escritura ajena (otro plan) basta para no confirmar. */
    public function test_una_escritura_fuera_de_la_lista_blanca_deshace_todo(): void
    {
        $e = $this->escenario();
        $antes = $this->huella();
        Event::listen('eloquent.created: '.TrainerCommissionCharge::class, function () use ($e): void {
            Plan::whereKey($e['mensual']->id)->update(['benefits' => 'tocado']);
        });

        $r = $this->reclasificar($e, ['--apply' => true]);

        $this->assertSame(PaymentReclassifier::STATUS_INVARIANT, $r['status']);
        $this->assertNotEmpty($r['unexpected_writes']);
        $this->assertStringContainsString('update plans', $r['unexpected_writes'][0]);
        $this->assertSame($antes, $this->huella());
    }

    // ── El plan ─────────────────────────────────────────────────────────────

    public function test_archivar_el_plan_solo_si_nadie_lo_tiene_vigente(): void
    {
        $e = $this->escenario();
        // Otro socio con el «plan comisión» vigente: el plan no se puede archivar.
        $this->bogota('2026-11-15 10:00');
        app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);
        $this->cobrar($e['otro'], $e['comision'], 'cash');
        app(CashShiftService::class)->close($this->cajero, CashShiftType::GYM);
        $this->bogota('2026-11-17 10:00');

        $r = $this->reclasificar($e, ['--apply' => true, '--archive-plan' => true]);

        $this->assertSame(PaymentReclassifier::STATUS_APPLIED, $r['status'], $r['message']);
        $this->assertFalse($r['plan_archive']['archived']);
        $this->assertSame(1, $r['plan_archive']['live_periods']);
        $plan = $e['comision']->fresh();
        $this->assertFalse($plan->isArchived());
        // No se archiva, pero el error no puede repetirse: deja de cobrarse en
        // recepción por la edición oficial (auditada), y sigue activo para que
        // quien lo tiene vigente no pierda nada.
        $this->assertTrue($r['plan_archive']['counter_blocked']);
        $this->assertTrue((bool) $plan->active);
        $this->assertFalse((bool) $plan->counter_sellable);
        $this->assertFalse($plan->isSellableAtCounter());
        $this->assertDatabaseHas('audit_logs', ['module' => 'Planes', 'entity' => 'plan', 'entity_id' => (string) $plan->id, 'action' => 'update']);

        // Y el mostrador ya no lo vende.
        $this->bogota('2026-11-17 11:00');
        app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);
        $this->postJson('/api/payments', ['user_id' => $e['otro']->user_id, 'plan_id' => $plan->id,
            'amount' => 50000, 'method' => 'cash', 'status' => 'paid'], $this->actingAsAdmin($this->cajero))
            ->assertStatus(422);
    }

    public function test_archivar_el_plan_cuando_es_seguro(): void
    {
        $e = $this->escenario();

        $r = $this->reclasificar($e, ['--apply' => true, '--archive-plan' => true]);

        $this->assertSame(PaymentReclassifier::STATUS_APPLIED, $r['status'], $r['message']);
        $this->assertTrue($r['plan_archive']['archived'], json_encode($r['plan_archive']));
        $plan = $e['comision']->fresh();
        $this->assertTrue($plan->isArchived());
        $this->assertFalse((bool) $plan->active);
        $this->assertSame($this->super->id, (int) $plan->archived_by);
    }

    // ── Recibos ─────────────────────────────────────────────────────────────

    public function test_el_abono_de_la_reclasificacion_no_es_imprimible(): void
    {
        $e = $this->escenario();
        $h = $this->actingAsAdmin($this->super);
        $pendientesAntes = $this->getJson("/api/admin/caja/shifts/{$e['turno']->id}/receipts", $h)->assertOk()->json('summary.pending');
        $r = $this->reclasificar($e, ['--apply' => true]);
        $abono = $r['created']['receivable_payment_id'];

        $res = $this->postJson('/api/admin/receipts', ['source_type' => 'receivable_payment', 'source_id' => $abono], $h)
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_printable')
            ->assertJsonPath('reason', 'reclassified');
        $this->assertStringContainsString($e['recibo']['number'], $res->json('message'));
        $this->assertStringContainsString('cobro #'.$e['pago']->id.' reclasificado', $res->json('message'));
        $this->assertSame(0, DB::table('receipt_sources')->where('source_type', 'receivable_payment')->where('source_id', $abono)->count());

        // La vista de recibos del turno no lo da por pendiente.
        $vista = $this->getJson("/api/admin/caja/shifts/{$e['turno']->id}/receipts", $h)->assertOk()->json();
        $fila = collect($vista['data'])->firstWhere('row_id', 'abono-'.$abono);
        $this->assertFalse($fila['eligible']);
        $this->assertSame('reclassified', $fila['reason']);
        $this->assertNull($fila['print_state']);
        // Antes: los dos cobros sin tirilla y el recibo del cobro, emitido y sin
        // imprimir en la prueba. Después: el cobro anulado ya no es tirilla que
        // falte, y el abono que lo sustituye tampoco suma. Quedan los dos.
        $this->assertSame(3, $pendientesAntes);
        $this->assertSame(2, $vista['summary']['pending']);
        // Y el recibo del cobro original sigue siendo EL recibo de ese dinero.
        $original = $this->postJson('/api/admin/receipts', ['source_type' => 'payment', 'source_id' => $e['pago']->id], $h)->assertOk();
        $this->assertSame($e['recibo']['number'], $original->json('data.0.number'));
    }

    // ── Informe del turno y PDF ─────────────────────────────────────────────

    public function test_el_informe_del_turno_cuenta_la_reclasificacion_sin_un_cobro_doble(): void
    {
        $e = $this->escenario();
        $r = $this->reclasificar($e, ['--apply' => true]);
        $h = $this->actingAsAdmin($this->super);

        $informe = $this->getJson("/api/admin/caja/shifts/{$e['turno']->id}", $h)->assertOk()->json();

        $this->assertTrue($informe['consistency']['matches']);
        $lineas = collect($informe['transactions']);
        $cobro = $lineas->firstWhere('id', $e['pago']->id);
        $this->assertSame('cancelled', $cobro['status']);
        $this->assertTrue($cobro['reclassified']);
        $this->assertFalse($lineas->firstWhere('id', $e['pagoOtro']->id)['reclassified']);
        $abono = $lineas->firstWhere('id', 'abono-'.$r['created']['receivable_payment_id']);
        $this->assertSame($e['pago']->id, $abono['reclassified_from_payment_id']);
        $this->assertSame('Olga Socia Prueba', $abono['member'], 'el abono dice quién pagó, no quién cobró');
        $this->assertSame('paid', $abono['status']);

        $correccion = collect($informe['post_close_corrections'])->firstWhere('type', 'reclassification');
        $this->assertNotNull($correccion);
        $this->assertSame($e['pago']->id, $correccion['payment_id']);
        $this->assertSame($r['created']['receivable_payment_id'], $correccion['receivable_payment_id']);
        $this->assertEquals(50000, $correccion['amount']);
        $this->assertSame('2026-11-07T01:43:00+00:00', $correccion['economic_at']);
        $this->assertSame('2026-11-17T15:00:00+00:00', $correccion['performed_at']);
        $this->assertSame('Dueña Supervisión', $correccion['performed_by_name']);
        $this->assertSame(self::MOTIVO, $correccion['reason']);
        // La anulación del cobro también sale, con su motivo.
        $this->assertNotNull(collect($informe['post_close_corrections'])->first(fn ($c) => ($c['type'] ?? null) === null
            && $c['entity'] === 'pago' && (string) $c['entity_id'] === (string) $e['pago']->id && $c['action'] === 'status'));
    }

    public function test_el_pdf_imprime_el_estado_de_los_cobros_que_no_suman(): void
    {
        $e = $this->escenario();
        $r = $this->reclasificar($e, ['--apply' => true]);

        $pdf = $this->get("/api/admin/caja/shifts/{$e['turno']->id}/pdf", $this->actingAsAdmin($this->super))->assertOk()->getContent();
        $texto = $this->textoDelPdf($pdf);

        $this->assertStringContainsString('RECLASIFICADO', $texto);
        $this->assertStringContainsString('Reclasificaci', $texto);
        $this->assertStringContainsString('#'.$e['pago']->id, $texto);
        $this->assertStringContainsString('CORRECCIONES POSTERIORES AL CIERRE', $texto);
    }

    /**
     * R1, sin reclasificación: un abono a la deuda de un ENTRENADOR salía en el
     * informe con el nombre del cajero. Ahora sale el deudor.
     */
    public function test_el_abono_de_un_entrenador_dice_el_entrenador_y_no_el_cajero(): void
    {
        $this->bogota('2026-11-10 09:00');
        $turno = app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);
        $entrenador = Trainer::create(['full_name' => 'Mario Deudor', 'document' => '91000111', 'status' => 'active']);
        $deuda = app(ReceivableService::class)->create(DebtorType::TRAINER, $entrenador->id, CashShiftType::GYM, 'Piso de octubre', Money::fromAmount(50000), $this->cajero);
        app(ReceivableService::class)->settle($deuda, Money::fromAmount(50000), 'cash', $this->cajero);

        $fila = collect(app(CashShiftReport::class)->for($turno->fresh())['transactions'])->firstWhere('source_type', 'receivable_payment');

        $this->assertSame('Mario Deudor', $fila['member']);
        $this->assertNull($fila['reclassified_from_payment_id']);
    }

    // ── Analíticas y vistas ─────────────────────────────────────────────────

    public function test_lo_que_no_entro_sigue_contando_las_anulaciones_de_verdad(): void
    {
        $e = $this->escenario();
        $this->reclasificar($e, ['--apply' => true]);
        // Una anulación de verdad el mismo día: esa SÍ es dinero que no entró.
        DB::table('payments')->where('id', $e['pagoOtro']->id)->update(['status' => 'cancelled']);

        $noEntro = $this->libroDelDia('2026-11-06')->reversals();

        $this->assertSame(['amount' => 80000.0, 'count' => 1], $noEntro['cancelled_payments']);
        $this->assertSame(['amount' => 50000.0, 'count' => 1], $noEntro['reclassified_payments']);
    }

    public function test_el_historial_del_socio_enseña_el_piso_y_no_un_fallido(): void
    {
        $e = $this->escenario();
        $totalAntes = $this->getJson("/api/admin/users/{$e['socia']->user_id}/payment-history", $this->actingAsAdmin($this->super))->json('summary.total_paid');
        $r = $this->reclasificar($e, ['--apply' => true]);

        $historia = $this->getJson("/api/admin/users/{$e['socia']->user_id}/payment-history", $this->actingAsAdmin($this->super))->assertOk()->json();

        $fila = collect($historia['payments'])->firstWhere('id', $e['pago']->id);
        $this->assertTrue($fila['reclassified']);
        $this->assertSame('reclassified', $fila['status_group']);
        $this->assertSame([
            'id' => $r['reclassification_id'],
            'label' => 'Reclasificado a piso de entrenador',
            'kind' => 'trainer_commission',
            'performed_at' => '2026-11-17T15:00:00+00:00',
            'economic_at' => '2026-11-07T01:43:00+00:00',
            'trainer_name' => 'Carlos Entrenador Prueba',
            'period_label' => 'noviembre de 2026',
            'charge_id' => $r['created']['charge_id'],
            'receivable_payment_id' => $r['created']['receivable_payment_id'],
        ], $fila['reclassification']);
        $this->assertFalse(collect($historia['payments'])->firstWhere('id', $e['pagoAnual']->id)['reclassified']);
        // El dinero lo pagó: el total no baja, y se dice cuánto fue reclasificado.
        $this->assertEquals($totalAntes, $historia['summary']['total_paid']);
        $this->assertSame(1, $historia['summary']['reclassified_count']);
        $this->assertEquals(50000, $historia['summary']['reclassified_amount']);
    }

    public function test_la_app_lo_pinta_como_un_pago_confirmado_del_piso(): void
    {
        $e = $this->escenario();
        $this->reclasificar($e, ['--apply' => true]);
        $h = ['Authorization' => 'Bearer '.$e['socia']->access_hash];

        $lista = collect($this->getJson('/api/app/payments', $h)->assertOk()->json('data'));
        $fila = $lista->firstWhere('reference', $e['pago']->reference);

        $this->assertSame('approved', $fila['status'], 'la app publicada lo pinta en verde, no «Pago no realizado»');
        $this->assertSame('Piso de entrenador personalizado · noviembre de 2026', $fila['product']);
        $this->assertSame($fila['product'], $fila['description']);
        $this->assertNull($fila['reason']);
        $this->assertNull($fila['membership_expiry']);
        $this->assertTrue($fila['reclassified']);
        $this->assertEquals(50000, $fila['amount']);
        $this->assertFalse($lista->firstWhere('reference', $e['pagoAnual']->reference)['reclassified']);
        $this->assertSame('approved', $lista->firstWhere('reference', $e['pagoAnual']->reference)['status']);

        $detalle = $this->getJson('/api/app/payments/'.$e['pago']->reference, $h)->assertOk()->json();
        $this->assertSame('approved', $detalle['status']);
        $this->assertSame('noviembre de 2026', $detalle['reclassification']['period_label']);
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    /** El texto de un PDF de TCPDF: sus flujos, descomprimidos. */
    private function textoDelPdf(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
        $texto = '';
        foreach ($m[1] as $bloque) {
            $plano = @gzuncompress($bloque);
            $texto .= $plano === false ? $bloque : $plano;
        }

        return $texto;
    }
}
