<?php

namespace Tests\Feature\Commissions;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentReclassification;
use App\Models\Plan;
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
use Tests\TestCase;

/**
 * SEGUIMIENTO DE VENCIMIENTOS DE LOS PERSONALIZADOS.
 *
 * El modelo (docs/ops/VENCIMIENTOS-PERSONALIZADOS.md): la obligación es
 * mensual, cada cobro cubre su tramo exacto, y el VENCIMIENTO es el último día
 * cubierto por pisos PAGADOS —lo mismo hasta lo que entra el entrenador—. La
 * fecha en que se paga NUNCA mueve la cobertura; un pago parcial no cubre; y
 * «hoy» es el día del gimnasio en Bogotá.
 *
 * Reloj en UTC: hoy es el 17-11-2026 (10:00 en Neiva).
 */
class TrainerCommissionFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private const HOY = '2026-11-17 10:00';

    private Admin $admin;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reloj(self::HOY);
        $this->admin = Admin::create(['name' => 'Dueño Iron Body', 'email' => 'seguimiento-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $this->h = $this->actingAsAdmin($this->admin);
        app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Los estados ─────────────────────────────────────────────────────────

    public function test_cada_estado_sale_del_ultimo_dia_cubierto_por_pisos_pagados(): void
    {
        // Tramos de 30 días, cobrados y pagados. Hoy es el 17-11.
        $alDia = $this->acuerdo();
        $this->pagar($alDia, '2026-11');                                         // 01-11 → 30-11: 13 días
        $proximo = $this->acuerdo();
        $this->pagar($proximo, '2026-10', ['covers_from' => '2026-10-22']);       // 22-10 → 20-11: 3 días
        $venceHoy = $this->acuerdo();
        $this->pagar($venceHoy, '2026-10', ['covers_from' => '2026-10-19']);      // 19-10 → 17-11: hoy
        $vencido = $this->acuerdo();
        $this->pagar($vencido, '2026-10', ['covers_from' => '2026-10-12']);       // 12-10 → 10-11: 7 días tarde
        $sinCobros = $this->acuerdo();

        $filas = $this->filas();

        $this->assertFila($filas[$alDia->id], 'al_dia', '2026-11-30', 13);
        $this->assertFila($filas[$proximo->id], 'proximo', '2026-11-20', 3);
        $this->assertFila($filas[$venceHoy->id], 'vence_hoy', '2026-11-17', 0);
        $this->assertFila($filas[$vencido->id], 'vencido', '2026-11-10', -7);
        $this->assertSame(7, $filas[$vencido->id]['days_overdue']);
        $this->assertSame(0, $filas[$alDia->id]['days_overdue']);
        $this->assertTrue($filas[$alDia->id]['confirmed'], 'sale de tramos guardados');

        // Sin cobros: no hay vencimiento que calcular, y como el acuerdo está
        // vigente hay que revisarlo.
        $this->assertSame('sin_registrar', $filas[$sinCobros->id]['due_state']);
        $this->assertNull($filas[$sinCobros->id]['next_due']);
        $this->assertNull($filas[$sinCobros->id]['days_remaining']);
        $this->assertNull($filas[$sinCobros->id]['days_overdue']);
        $this->assertFalse($filas[$sinCobros->id]['confirmed']);

        $revisar = array_keys(array_filter($filas, fn (array $f) => $f['needs_review']));
        sort($revisar);
        $this->assertSame([$venceHoy->id, $vencido->id, $sinCobros->id], $revisar);

        // El resumen.
        $r = app(TrainerCommissions::class)->followUp()['summary'];
        $this->assertSame(['vencido' => 1, 'vence_hoy' => 1, 'proximo' => 1, 'sin_registrar' => 1, 'al_dia' => 1, 'inactivo' => 0], $r['by_state']);
        $this->assertSame(2, $r['upcoming']);
        $this->assertSame(1, $r['overdue']);
        $this->assertSame(3, $r['needs_review']);
        $this->assertEqualsWithDelta(100000, $r['amount_upcoming'], 0.01);
        $this->assertEqualsWithDelta(50000, $r['amount_overdue'], 0.01);
        $this->assertSame(7, $r['window_days']);
        $this->assertSame('2026-11-17', $r['today']);
    }

    public function test_la_ventana_decide_que_es_proximo(): void
    {
        $a = $this->acuerdo();
        $this->pagar($a, '2026-10', ['covers_from' => '2026-10-22']);            // vence el 20-11: faltan 3 días

        $servicio = app(TrainerCommissions::class);
        $this->assertSame('proximo', $this->filaDe($servicio->followUp(null, 7), $a)['due_state'], 'dentro de la ventana');
        $this->assertSame('proximo', $this->filaDe($servicio->followUp(null, 3), $a)['due_state'], 'justo en el borde');
        $this->assertSame('al_dia', $this->filaDe($servicio->followUp(null, 2), $a)['due_state'], 'fuera de la ventana');
        // La ventana se acota a 1..30.
        $this->assertSame(30, $servicio->followUp(null, 365)['summary']['window_days']);
        $this->assertSame(1, $servicio->followUp(null, 0)['summary']['window_days']);
    }

    public function test_otro_ciclo_de_quince_dias(): void
    {
        $a = $this->acuerdo(['cycle_days' => 15]);
        $this->pagar($a, '2026-11', ['covers_from' => '2026-11-05']);            // 05-11 → 19-11

        $f = $this->filas()[$a->id];
        $this->assertSame(15, $f['cycle_days']);
        $this->assertFila($f, 'proximo', '2026-11-19', 2);

        // Dos tramos de 15 que no se tocan dejan un hueco: hoy no está cubierto.
        $b = $this->acuerdo(['cycle_days' => 15]);
        $this->pagar($b, '2026-10');                                             // 01-10 → 15-10
        $this->pagar($b, '2026-11');                                             // 01-11 → 15-11
        $this->assertFila($this->filas()[$b->id], 'vencido', '2026-11-15', -2);
    }

    public function test_pagar_antes_o_despues_no_mueve_la_cobertura(): void
    {
        // ADELANTADO: noviembre pagado el 20-10. Cubre su tramo, no desde el pago.
        $adelantado = $this->acuerdo();
        $this->reloj('2026-10-20 18:00');
        $this->pagar($adelantado, '2026-11');
        // ATRASADO: octubre pagado hoy. Pagarlo tarde no le regala noviembre.
        $atrasado = $this->acuerdo();
        $this->reloj(self::HOY);
        $this->pagar($atrasado, '2026-10');

        $filas = $this->filas();
        $this->assertFila($filas[$adelantado->id], 'al_dia', '2026-11-30', 13);
        $this->assertSame('2026-10-20T18:00:00-05:00', $filas[$adelantado->id]['last_payment']['paid_at']);
        $this->assertFila($filas[$atrasado->id], 'vencido', '2026-10-30', -18);
        $this->assertSame('2026-11-17T10:00:00-05:00', $filas[$atrasado->id]['last_payment']['paid_at']);

        // Y un mes adelantado y contiguo se suma al bloque: cubierto sin huecos.
        $this->pagar($adelantado, '2026-12');                                    // 01-12 → 30-12
        $this->assertFila($this->filas()[$adelantado->id], 'al_dia', '2026-12-30', 43);

        // Solo diciembre, por adelantado y sin noviembre: el bloque futuro manda (prepago).
        $prepago = $this->acuerdo();
        $this->pagar($prepago, '2026-12');
        $this->assertFila($this->filas()[$prepago->id], 'al_dia', '2026-12-30', 43);
    }

    public function test_inactivo_y_finalizado(): void
    {
        $terminado = $this->acuerdo();
        $this->pagar($terminado, '2026-11');
        $this->deleteJson("/api/admin/commissions/agreements/{$terminado->id}", [], $this->h)->assertOk();

        $finalizado = $this->acuerdo(['ends_on' => '2026-11-10']);

        $filas = $this->filas();
        $this->assertSame('inactivo', $filas[$terminado->id]['due_state']);
        $this->assertFalse($filas[$terminado->id]['active']);
        // Lo que pagó sigue diciéndose: es un hecho, no un estado.
        $this->assertSame('2026-11-30', $filas[$terminado->id]['covered_until']);
        $this->assertSame('inactivo', $filas[$finalizado->id]['due_state'], 'ends_on ya pasó');
        $this->assertTrue($filas[$finalizado->id]['active']);
        $this->assertFalse($filas[$finalizado->id]['needs_review']);
        $this->assertSame(2, app(TrainerCommissions::class)->followUp()['summary']['by_state']['inactivo']);
    }

    public function test_un_pago_parcial_es_deuda_registrada_y_no_cubre(): void
    {
        $a = $this->acuerdo();
        $this->pagar($a, '2026-10');                                             // 01-10 → 30-10, pagado
        $deuda = $this->cobrar($a, '2026-11')->assertCreated()->json('charge.receivable_id');
        $this->postJson("/api/admin/receivables/{$deuda}/payments", ['amount' => 20000, 'method' => 'cash'], $this->h)->assertCreated();

        $f = $this->filas()[$a->id];
        // Noviembre no cubre: solo octubre, que acabó el 30-10.
        $this->assertFila($f, 'vencido', '2026-10-30', -18);
        $this->assertSame([
            'receivable_id' => $deuda,
            'charge_id' => TrainerCommissionCharge::query()->where('receivable_id', $deuda)->value('id'),
            'period_label' => 'noviembre de 2026',
            'balance' => 30000.0,
            'due_at' => null,
            // Sin plazo no hay vencimiento de la deuda: registrada no es vencida.
            'overdue' => false,
        ], $f['registered_debt']);
        $this->assertSame(20000.0, $f['last_payment']['amount'], 'el último dinero que entró, aunque no cubra');

        // Solo un parcial, sin meses pagados: sin registrar, con la deuda a la vista.
        $b = $this->acuerdo();
        $deudaB = $this->cobrar($b, '2026-11', ['due_at' => '2026-11-15'])->assertCreated()->json('charge.receivable_id');
        $this->postJson("/api/admin/receivables/{$deudaB}/payments", ['amount' => 10000, 'method' => 'cash'], $this->h)->assertCreated();
        $g = $this->filas()[$b->id];
        $this->assertSame('sin_registrar', $g['due_state']);
        $this->assertNull($g['covered_until']);
        $this->assertTrue($g['registered_debt']['overdue'], 'con plazo pasado sí está vencida');
        $this->assertTrue($g['needs_review']);
    }

    public function test_el_cobro_reclasificado_cubre_como_pagado_y_dice_de_donde_salio(): void
    {
        [$acuerdo, $r] = $this->reclasificacion();

        $f = $this->filas()[$acuerdo->id];
        $this->assertFila($f, 'al_dia', '2026-12-05', 18);
        $this->assertSame($r->id, $f['last_paid']['reclassified_from']['reclassification_id']);
        $this->assertSame($r->payment_id, $f['last_paid']['reclassified_from']['payment_id']);
        $this->assertSame('2026-11-06', $f['last_paid']['covers_from']);
        // El abono de la reclasificación lleva la fecha en que entró el dinero.
        $this->assertSame('2026-11-06T20:43:00-05:00', $f['last_payment']['paid_at']);
    }

    public function test_un_cobro_antiguo_sin_tramo_cubre_su_mes_y_no_queda_confirmado(): void
    {
        $a = $this->acuerdo();
        $cobroId = $this->pagar($a, '2026-11');
        // Los cobros de antes de que existiera el tramo no lo tienen guardado.
        DB::table('trainer_commission_charges')->where('id', $cobroId)->update(['covers_from' => null, 'covers_to' => null]);

        $f = $this->filas()[$a->id];
        $this->assertFila($f, 'al_dia', '2026-11-30', 13);
        $this->assertFalse($f['confirmed']);
    }

    public function test_la_zona_horaria_de_bogota_manda(): void
    {
        // Un abono a las 01:43 UTC del 17 es del 16 a las 20:43 en el gimnasio.
        $a = $this->acuerdo();
        $this->reloj('2026-11-17 01:43', 'UTC');
        $this->pagar($a, '2026-10', ['covers_from' => '2026-10-19']);            // 19-10 → 17-11

        // A las 23:30 de Bogotá (04:30 UTC del 18) sigue siendo el 17: vence hoy.
        $seguimiento = app(TrainerCommissions::class)->followUp(CarbonImmutable::parse('2026-11-18 04:30', 'UTC'));
        $f = $this->filaDe($seguimiento, $a);

        $this->assertSame('2026-11-17', $seguimiento['summary']['today']);
        $this->assertSame('vence_hoy', $f['due_state']);
        $this->assertSame('2026-11-16T20:43:00-05:00', $f['last_payment']['paid_at']);

        // Y por el endpoint, con el reloj a las 23:30 de Bogotá, igual (con una
        // sesión abierta a esa hora: la de las 10:00 ya no vale).
        $this->reloj('2026-11-17 23:30');
        $this->getJson('/api/admin/commissions/follow-up', $this->actingAsAdmin($this->admin))->assertOk()
            ->assertJsonPath('summary.today', '2026-11-17')
            ->assertJsonPath('rows.0.due_state', 'vence_hoy');
    }

    public function test_con_fechas_nulas_el_acuerdo_se_sigue_igual(): void
    {
        $a = $this->acuerdo();
        $this->assertNull($a->starts_on);
        $this->assertNull($a->ends_on);
        $this->pagar($a, '2026-11');

        $f = $this->filas()[$a->id];
        $this->assertNull($f['starts_on']);
        $this->assertNull($f['ends_on']);
        $this->assertTrue($f['active']);
        $this->assertSame('al_dia', $f['due_state']);
        $this->assertSame(['id' => $a->trainer_id, 'name' => $a->trainer->full_name], $f['trainer']);
        $this->assertSame(50000.0, $f['amount']);
        $this->assertSame('trainer', $f['payer']);
        $this->assertSame('El entrenador', $f['payer_label']);
    }

    // ── El histórico ampliado ───────────────────────────────────────────────

    public function test_el_historico_dice_el_plazo_el_pago_y_lo_que_le_paso_a_cada_mes(): void
    {
        $a = $this->acuerdo();

        // Septiembre: con plazo el 05-09, pagado el 08-09 → 3 días tarde.
        $sep = $this->cobrar($a, '2026-09', ['due_at' => '2026-09-05'])->assertCreated()->json('charge');
        $this->reloj('2026-09-08 19:00');
        $this->postJson("/api/admin/receivables/{$sep['receivable_id']}/payments", ['amount' => 50000, 'method' => 'transfer'], $this->h)
            ->assertCreated();
        $abonoSep = (int) ReceivablePayment::query()->where('receivable_id', $sep['receivable_id'])->value('id');
        $this->postJson('/api/admin/receipts', ['source_type' => 'receivable_payment', 'source_ids' => [$abonoSep]], $this->h)->assertCreated();

        // Octubre: sin plazo, pagado en el acto; después se le corre el tramo.
        $this->reloj(self::HOY);
        $oct = $this->cobrar($a, '2026-10', ['method' => 'cash'])->assertCreated()->json('charge');
        $this->patchJson("/api/admin/commissions/charges/{$oct['id']}/coverage", ['covers_from' => '2026-10-05', 'reason' => 'Viajó la primera semana'], $this->h)
            ->assertOk();

        // Noviembre: cobrado y anulado.
        $nov = $this->cobrar($a, '2026-11', ['method' => 'cash'])->assertCreated()->json('charge');
        $this->postJson("/api/admin/commissions/charges/{$nov['id']}/cancel", ['reason' => 'Se cobró al entrenador equivocado'], $this->h)->assertOk();

        $historial = collect($this->getJson("/api/admin/commissions/agreements/{$a->id}/history", $this->h)->assertOk()->json('charges'))
            ->keyBy('id');

        $s = $historial[$sep['id']];
        $this->assertSame('2026-09-05', $s['due_date']);
        $this->assertTrue($s['due_date_confirmed']);
        $this->assertNull($s['expected_due_date']);
        $this->assertSame('2026-09-08T19:00:00-05:00', $s['paid_at']);
        $this->assertSame(3, $s['days_late']);
        $this->assertSame('paid', $s['state']);
        $this->assertSame(1, count($s['payments']));
        // Por valor: el JSON devuelve 50000.0 como 50000 y en su propio orden.
        $this->assertEquals([
            'receivable_payment_id' => $abonoSep,
            'reference' => 'RP-'.$abonoSep,
            'receipt_number' => 'RG-000001',
            'method' => 'transfer',
            'amount' => 50000.0,
            'cash_shift_id' => ReceivablePayment::findOrFail($abonoSep)->cash_shift_id,
            'by' => 'Dueño Iron Body',
            'reversed' => false,
        ], array_intersect_key($s['payments'][0], array_flip(['receivable_payment_id', 'reference', 'receipt_number', 'method', 'amount', 'cash_shift_id', 'by', 'reversed'])));

        $o = $historial[$oct['id']];
        $this->assertNull($o['due_date']);
        $this->assertFalse($o['due_date_confirmed']);
        $this->assertSame('2026-10-05', $o['expected_due_date'], 'el inicio del tramo, ya corrido');
        $this->assertSame('inicio del tramo, no confirmado', $o['expected_due_date_label']);
        $this->assertNull($o['days_late'], 'sin plazo confirmado no hay retraso que medir');
        $this->assertSame('2026-11-17T10:00:00-05:00', $o['paid_at']);
        $this->assertNull($o['cancelled']);
        $this->assertCount(1, $o['adjustments']);
        $this->assertSame(['by' => 'Dueño Iron Body', 'covers_from' => '2026-10-05', 'covers_to' => '2026-11-03', 'reason' => 'Viajó la primera semana'],
            array_intersect_key($o['adjustments'][0], array_flip(['covers_from', 'covers_to', 'reason', 'by'])));

        $n = $historial[$nov['id']];
        $this->assertSame('cancelled', $n['state']);
        $this->assertSame('Se cobró al entrenador equivocado', $n['cancelled']['reason']);
        $this->assertSame('Dueño Iron Body', $n['cancelled']['by']);
        $this->assertNotNull($n['cancelled']['at']);
        $this->assertNull($n['paid_at'], 'su abono se revirtió');
        $this->assertTrue($n['payments'][0]['reversed']);
        $this->assertSame([], $n['adjustments']);
    }

    // ── Solo lee ─────────────────────────────────────────────────────────────

    public function test_seguimiento_e_historico_no_escriben_nada_y_repiten_la_misma_respuesta(): void
    {
        $a = $this->acuerdo();
        $this->pagar($a, '2026-10');
        $this->cobrar($a, '2026-11')->assertCreated();
        $this->acuerdo();

        // Se escucha SOLO lo que hace el servicio: la capa HTTP puede tocar la
        // sesión del administrador, y eso no es del seguimiento.
        $escrituras = [];
        $escuchando = true;
        DB::listen(function (QueryExecuted $q) use (&$escrituras, &$escuchando): void {
            if ($escuchando && preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)\b/i', $q->sql) === 1) {
                $escrituras[] = $q->sql;
            }
        });

        $servicio = app(TrainerCommissions::class);
        $primero = $servicio->followUp();
        $segundo = $servicio->followUp();
        $historial1 = $servicio->history($a->fresh());
        $historial2 = $servicio->history($a->fresh());
        $escuchando = false;
        $endpoint = $this->getJson('/api/admin/commissions/follow-up', $this->h)->assertOk()->json();

        $this->assertSame([], $escrituras, 'ni una escritura');
        $this->assertSame($primero, $segundo);
        $this->assertSame($historial1, $historial2);
        $this->assertSame(json_decode(json_encode($primero['rows']), true), $endpoint['rows']);
    }

    public function test_el_seguimiento_no_hace_una_consulta_por_acuerdo(): void
    {
        $contar = function (): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            app(TrainerCommissions::class)->followUp();

            return $n;
        };

        $a = $this->acuerdo();
        $this->pagar($a, '2026-10');
        $con1 = $contar();

        foreach (range(1, 4) as $i) {
            $b = $this->acuerdo();
            $this->pagar($b, '2026-10');
            $this->pagar($b, '2026-11');
        }
        $con5 = $contar();

        $this->assertSame($con1, $con5, 'las mismas consultas con 1 acuerdo que con 5');
    }

    // ── El endpoint ─────────────────────────────────────────────────────────

    public function test_el_endpoint_filtra_valida_y_exige_el_permiso_del_panel(): void
    {
        $alDia = $this->acuerdo();
        $this->pagar($alDia, '2026-11');
        $vencido = $this->acuerdo();
        $this->pagar($vencido, '2026-10', ['covers_from' => '2026-10-12']);
        $this->acuerdo();                                                         // sin registrar

        $todo = $this->getJson('/api/admin/commissions/follow-up', $this->h)->assertOk();
        $todo->assertJsonPath('filter', 'all')->assertJsonCount(3, 'rows')->assertJsonPath('summary.total', 3);
        // Lo urgente primero.
        $this->assertSame(['vencido', 'sin_registrar', 'al_dia'], array_column($todo->json('rows'), 'due_state'));

        $filtrado = $this->getJson('/api/admin/commissions/follow-up?filter=overdue', $this->h)->assertOk();
        $this->assertSame([$vencido->id], array_column($filtrado->json('rows'), 'agreement_id'));
        $this->assertSame($todo->json('summary'), $filtrado->json('summary'), 'el filtro no cambia el resumen');
        $this->assertSame([$alDia->id], array_column($this->getJson('/api/admin/commissions/follow-up?filter=paid', $this->h)->json('rows'), 'agreement_id'));
        $this->assertSame([], $this->getJson('/api/admin/commissions/follow-up?filter=upcoming', $this->h)->json('rows'));
        $this->assertSame(14, $this->getJson('/api/admin/commissions/follow-up?window=14', $this->h)->json('summary.window_days'));

        foreach (['window=0', 'window=31', 'window=abc', 'filter=todo'] as $mal) {
            $this->getJson('/api/admin/commissions/follow-up?'.$mal, $this->h)->assertStatus(422);
        }

        // Recepción ve el panel, así que ve esto; sin `commissions.view`, no.
        $this->getJson('/api/admin/commissions/follow-up', $this->actingAsAdmin(Admin::create([
            'name' => 'Laura Mostrador', 'email' => 'rec-'.uniqid().'@ironbody.test', 'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ])))->assertOk();
        $this->getJson('/api/admin/commissions/follow-up', $this->adminWithPermissions(['plans.view']))->assertForbidden();
        $this->getJson('/api/admin/commissions/follow-up')->assertUnauthorized();
    }

    // ────────────────────────────────────────────────────────────────────────

    private function reloj(string $hora, string $zona = 'America/Bogota'): void
    {
        Carbon::setTestNow(Carbon::parse($hora, $zona)->utc());
    }

    private function acuerdo(array $extra = []): TrainerCommissionAgreement
    {
        static $n = 0;
        $n++;
        $entrenador = Trainer::create(['full_name' => 'Entrenador '.$n, 'document' => (string) (63000000 + $n),
            'email' => 'seg-entrenador-'.$n.'-'.uniqid().'@ironbody.test', 'status' => 'active']);
        $user = User::create(['name' => 'Clienta '.$n, 'email' => 'seg-clienta-'.$n.'-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'status' => 'active']);
        $cliente = Member::create(['full_name' => 'Clienta '.$n, 'email' => $user->email, 'document_number' => (string) (64000000 + $n),
            'status' => Member::STATUS_ACTIVE, 'user_id' => $user->id]);

        $id = $this->postJson('/api/admin/commissions/agreements', array_merge([
            'trainer_id' => $entrenador->id, 'member_id' => $cliente->id, 'amount' => 50000, 'payer' => 'trainer',
        ], $extra), $this->h)->assertCreated()->json('id');

        return TrainerCommissionAgreement::with('trainer')->findOrFail($id);
    }

    private function cobrar(TrainerCommissionAgreement $a, string $mes, array $cuerpo = []): TestResponse
    {
        return $this->postJson("/api/admin/commissions/agreements/{$a->id}/charge", array_merge(['period' => $mes], $cuerpo), $this->h);
    }

    /** Cobra el mes y lo paga entero en el acto. Devuelve el id del cobro. */
    private function pagar(TrainerCommissionAgreement $a, string $mes, array $cuerpo = []): int
    {
        return (int) $this->cobrar($a, $mes, array_merge(['method' => 'cash'], $cuerpo))->assertCreated()->json('charge.id');
    }

    /** @return array<int, array<string, mixed>> */
    private function filas(): array
    {
        return collect(app(TrainerCommissions::class)->followUp()['rows'])->keyBy('agreement_id')->all();
    }

    /** @param  array{rows: list<array<string, mixed>>}  $seguimiento */
    private function filaDe(array $seguimiento, TrainerCommissionAgreement $a): array
    {
        return collect($seguimiento['rows'])->firstWhere('agreement_id', $a->id);
    }

    private function assertFila(array $fila, string $estado, string $cubiertoHasta, int $restantes): void
    {
        $this->assertSame($estado, $fila['due_state'], "acuerdo {$fila['agreement_id']}");
        $this->assertSame($cubiertoHasta, $fila['covered_until'], "acuerdo {$fila['agreement_id']}");
        $this->assertSame($cubiertoHasta, $fila['next_due'], "acuerdo {$fila['agreement_id']}");
        $this->assertSame($restantes, $fila['days_remaining'], "acuerdo {$fila['agreement_id']}");
    }

    /**
     * El caso que lo motivó: 50.000 en efectivo del 06-11 a las 20:43 (Bogotá),
     * cobrados como venta de un plan y reclasificados como el piso de la socia.
     * Las piezas, por los caminos reales; la constancia, como la escribe el
     * procedimiento (BE-B) y con el abono fechado el día en que entró el dinero.
     *
     * @return array{0: TrainerCommissionAgreement, 1: PaymentReclassification}
     */
    private function reclasificacion(): array
    {
        $this->reloj('2026-11-06 20:43');
        $plan = Plan::create(['name' => 'Comisión de personalizados', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        $acuerdo = $this->acuerdo(['payer' => 'member', 'starts_on' => '2026-11-06']);
        $pago = Payment::findOrFail($this->postJson('/api/payments', ['user_id' => $acuerdo->member->user_id, 'plan_id' => $plan->id,
            'amount' => 50000, 'method' => 'cash', 'status' => 'paid'], $this->h)->assertCreated()->json('id'));
        $cobroId = $this->pagar($acuerdo, '2026-11');
        $this->reloj(self::HOY);

        $cobro = TrainerCommissionCharge::findOrFail($cobroId);
        $r = PaymentReclassification::create([
            'payment_id' => $pago->id,
            'kind' => PaymentReclassification::KIND_TRAINER_COMMISSION,
            'amount' => '50000.00',
            'method' => 'cash',
            'economic_at' => $pago->paid_at,
            'cash_shift_id' => $pago->cash_shift_id,
            'agreement_id' => $acuerdo->id,
            'charge_id' => $cobro->id,
            'receivable_id' => $cobro->receivable_id,
            'receivable_payment_id' => ReceivablePayment::query()->where('receivable_id', $cobro->receivable_id)->value('id'),
            'reason' => 'El piso del entrenador se cobró como venta de un plan.',
            'performed_by' => $this->admin->id,
            'performed_by_name' => $this->admin->name,
            'performed_at' => now(),
            'procedure' => 'prueba',
        ]);

        return [$acuerdo, $r->refresh()];
    }
}
