<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\AutomationEvent;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\Plan;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\MembershipFinancialStanding;
use App\Services\Caja\ReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Retención financiera: qué pasa cuando vence el plazo de un abono.
 *
 * LA IDEA QUE SE PROTEGE AQUÍ. Membresía vigente y socio al día son dos cosas
 * distintas. Deber dinero no cancela un contrato ni mueve una fecha de fin: deja
 * la membresía retenida, y se levanta pagando. Por eso nada de esto se guarda en
 * una columna — se deriva del saldo — y por eso pagar desbloquea en la misma
 * petición, sin esperar a ningún proceso nocturno.
 *
 * Y lo que NO puede pasar bajo ninguna circunstancia: que a alguien bloqueado se
 * le impida pagar. Un bloqueo que además encierra al socio no cobra, solo
 * enfada.
 */
class OverdueReceivablesTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Recepción',
            'email' => 'mora-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        config(['caja.default_payment_term_days' => 15]);
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function socio(string $nombre = 'Alejandro Casas'): Member
    {
        $user = User::create([
            'name' => $nombre,
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            // Membresía vigente: lo que se prueba es la RETENCIÓN, no el vencimiento
            // natural del plan. Si el plan estuviera vencido no sabríamos cuál de
            // las dos cosas bloqueó.
            'plan' => 'Premium',
            'membershipEndDate' => now()->addDays(30)->toDateString(),
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => $nombre,
            'document_number' => (string) random_int(10000000, 99999999),
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
            'is_staff' => false,
        ]);
    }

    private function comoSocio(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    private function admin(): array
    {
        return $this->actingAsAdmin($this->cajero);
    }

    /** Abre el turno solo si no hay uno ya abierto: un cajero tiene uno a la vez. */
    private function turno(CashShiftType $tipo = CashShiftType::GYM): CashShift
    {
        $abierto = CashShift::where('type', $tipo->value)->open()->first();

        return $abierto ?? app(CashShiftService::class)->open($this->cajero, $tipo);
    }

    /** Deuda de gimnasio con saldo y el plazo que se le indique. */
    private function deuda(
        Member $socio,
        float $total = 200000,
        float $abonado = 80000,
        ?string $vence = null,
        CashShiftType $tipo = CashShiftType::GYM,
    ): Receivable {
        $this->turno($tipo);

        $cuenta = app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER,
            debtorId: $socio->id,
            type: $tipo,
            concept: 'Plan Premium',
            total: Money::fromAmount($total),
            actor: $this->cajero,
            dueAt: $vence !== null ? Carbon::parse($vence) : null,
        );

        if ($abonado > 0) {
            app(ReceivableService::class)->settle(
                receivable: $cuenta,
                amount: Money::fromAmount($abonado),
                method: 'cash',
                actor: $this->cajero,
            );
        }

        return $cuenta->fresh();
    }

    private function standing(): MembershipFinancialStanding
    {
        return app(MembershipFinancialStanding::class);
    }

    private function ayer(): string
    {
        return Receivable::businessToday()->subDay()->toDateString();
    }

    private function hoy(): string
    {
        return Receivable::businessToday()->toDateString();
    }

    private function manana(): string
    {
        return Receivable::businessToday()->addDay()->toDateString();
    }

    // ── 1-3 · El predicado ──────────────────────────────────────────────────

    public function test_1_deuda_de_gimnasio_con_plazo_por_llegar_no_retiene(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->manana());

        $this->assertFalse($this->standing()->isOverdue($socio));
        $this->assertSame(MembershipFinancialStanding::GOOD, $this->standing()->standing($socio));
    }

    public function test_2_el_dia_pactado_todavia_no_esta_vencido(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->hoy());

        // El socio dispone del día entero: pagar a las ocho de la noche del día
        // límite es pagar a tiempo.
        $this->assertFalse($this->standing()->isOverdue($socio), 'Vencer el mismo día sería robarle el plazo.');
    }

    public function test_3_pasado_el_plazo_con_saldo_el_socio_queda_retenido(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->ayer());

        $this->assertTrue($this->standing()->isOverdue($socio));
        $resumen = $this->standing()->summary($socio);
        $this->assertSame(MembershipFinancialStanding::OVERDUE, $resumen['standing']);
        $this->assertEqualsWithDelta(120000, $resumen['balance'], 0.01);
        $this->assertSame($this->ayer(), $resumen['due_date']);
        $this->assertSame(1, $resumen['days_overdue']);
    }

    public function test_3b_el_dia_pactado_tampoco_se_marca_vencido_en_el_crm(): void
    {
        // El predicado SQL compara fechas y acierta, pero el CRM pinta el campo
        // `overdue` que calcula el modelo en PHP. `due_at` se guarda a medianoche
        // UTC y el día del gimnasio empieza a medianoche en Bogotá —cinco horas
        // después—, así que comparar instantes daba por vencida una deuda el
        // mismo día en que se había quedado en pagarla.
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->hoy());

        $pintado = $cuenta->fresh()->toCrmArray();

        $this->assertFalse($pintado['overdue'], 'Hoy es el día pactado: todavía no vence.');
        $this->assertSame(0, $pintado['days_overdue']);
        $this->assertSame($this->hoy(), $pintado['due_date']);
    }

    public function test_3c_los_dias_de_mora_se_cuentan_en_dias_de_calendario(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: Receivable::businessToday()->subDays(3)->toDateString());

        $this->assertSame(3, $cuenta->fresh()->daysOverdue(), 'Tres días son tres, no dos ni cuatro.');
    }

    // ── 4-7 · El ciclo de pago ──────────────────────────────────────────────

    public function test_4_pagar_antes_del_plazo_deja_todo_en_orden(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->manana());

        app(ReceivableService::class)->settle($cuenta, Money::fromAmount(120000), 'cash', $this->cajero);

        $this->assertFalse($this->standing()->isOverdue($socio));
        $this->assertSame(Receivable::STATUS_PAID, $cuenta->fresh()->status);
    }

    public function test_5_el_pago_final_estando_vencido_desbloquea_en_el_acto(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        $this->assertTrue($this->standing()->isOverdue($socio), 'preparación');

        app(ReceivableService::class)->settle($cuenta, Money::fromAmount(120000), 'cash', $this->cajero);

        // Sin scheduler de por medio: la solvencia se deriva del saldo.
        $this->assertFalse($this->standing()->isOverdue($socio));
    }

    public function test_6_el_caso_de_negocio_completo_de_la_especificacion(): void
    {
        // Plan 200.000 · inicial 80.000 · saldo 120.000 · vence el 15.
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());

        // Día 16: retenido.
        $this->assertTrue($this->standing()->isOverdue($socio));

        // Día 17: abona 50.000 → saldo 70.000 → SIGUE retenido.
        app(ReceivableService::class)->settle($cuenta, Money::fromAmount(50000), 'cash', $this->cajero);
        $this->assertTrue($this->standing()->isOverdue($socio), 'Un abono parcial no levanta la retención.');
        $this->assertEqualsWithDelta(70000, $this->standing()->summary($socio)['balance'], 0.01);

        // Día 18: abona 70.000 → saldo 0 → liberado.
        app(ReceivableService::class)->settle($cuenta->fresh(), Money::fromAmount(70000), 'cash', $this->cajero);
        $this->assertFalse($this->standing()->isOverdue($socio));
        $this->assertEqualsWithDelta(0, $this->standing()->summary($socio)['balance'], 0.01);
    }

    public function test_7_anular_el_pago_final_vuelve_a_retener(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        $final = app(ReceivableService::class)->settle($cuenta, Money::fromAmount(120000), 'cash', $this->cajero);
        $this->assertFalse($this->standing()->isOverdue($socio), 'preparación');

        app(ReceivableService::class)->reverse($final, 'se tecleó mal el monto', $this->cajero);

        // El saldo vuelve y el plazo sigue pasado: la retención vuelve sola.
        $this->assertTrue($this->standing()->isOverdue($socio));
    }

    // ── 8 · Sin plazo no hay mora ───────────────────────────────────────────

    public function test_8_una_deuda_sin_plazo_no_retiene_jamas(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: null);

        $this->assertFalse($this->standing()->isOverdue($socio));

        // Ni aunque pase un año: sin fecha pactada no hay nada que vencer. Es lo
        // que protege a las deudas que ya existían antes de todo esto.
        $this->travel(400)->days();
        $this->assertFalse($this->standing()->isOverdue($socio));
    }

    // ── 9-10 · Productos ────────────────────────────────────────────────────

    public function test_9_una_deuda_de_cafeteria_vencida_no_retiene_la_membresia(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, total: 137000, abonado: 0, vence: $this->ayer(), tipo: CashShiftType::PRODUCTS);

        $this->assertFalse(
            $this->standing()->isOverdue($socio),
            'Deber un tinto no puede quitarle el gimnasio a nadie.',
        );
        $this->assertTrue($this->standing()->overdueReceivables($socio)->isEmpty());
    }

    public function test_10_la_cafeteria_vencida_si_emite_su_propio_aviso(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, total: 137000, abonado: 0, vence: $this->ayer(), tipo: CashShiftType::PRODUCTS);

        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        $this->assertDatabaseHas('automation_events', ['event_type' => 'receivable.products_overdue']);
        $this->assertDatabaseMissing('automation_events', ['event_type' => 'receivable.overdue']);
    }

    // ── 11-13 · Lo que NUNCA se bloquea ─────────────────────────────────────

    public function test_11_un_socio_retenido_sigue_pudiendo_entrar_y_consultar_su_cuenta(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->ayer());

        // El estado de la app tiene que responder: es donde se le dice que está
        // retenido. Bloquearlo sería dejarlo sin saber qué pasa.
        $this->getJson('/api/member/app-state', $this->comoSocio($socio))->assertOk();
        $this->getJson('/api/member/account/status', $this->comoSocio($socio))->assertOk();
    }

    public function test_12_el_estado_de_la_app_apaga_el_home_y_todos_los_beneficios(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->ayer());

        $r = $this->getJson('/api/member/app-state', $this->comoSocio($socio))->assertOk();

        $this->assertFalse($r->json('features.can_access_home'));
        $this->assertTrue($r->json('features.requires_activation'));
        foreach (['can_use_ai', 'can_use_training', 'can_use_nutrition', 'can_use_classes', 'can_use_progress'] as $f) {
            $this->assertFalse($r->json("features.{$f}"), "{$f} debería quedar apagada");
        }
        // La membresía sigue viva: retener no es cancelar.
        $this->assertTrue($r->json('membership.is_active'));
        $this->assertTrue($r->json('financial.overdue'));
        $this->assertSame($this->ayer(), $r->json('financial.due_date'));
    }

    public function test_13_las_rutas_de_pago_siguen_abiertas_para_quien_debe(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->ayer());

        // Si se bloqueara esto, el socio quedaría encerrado: retenido y sin
        // forma de pagar desde la app.
        $this->getJson('/api/payments/wompi/config', $this->comoSocio($socio))->assertOk();
        $this->getJson('/api/member/contracts/status', $this->comoSocio($socio))->assertOk();
    }

    // ── 14-15 · Enforcement real ────────────────────────────────────────────

    public function test_14_un_beneficio_protegido_responde_403_con_el_motivo(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->ayer());

        $r = $this->getJson('/api/member/training/today', $this->comoSocio($socio))->assertStatus(403);

        $r->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'membership_payment_overdue')
            ->assertJsonPath('due_date', $this->ayer());
        $this->assertEqualsWithDelta(120000, $r->json('balance'), 0.01);
    }

    public function test_15_el_contrato_de_error_es_el_que_la_app_publicada_sabe_leer(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->ayer());

        $r = $this->getJson('/api/app/routines/assigned', $this->comoSocio($socio))->assertStatus(403);

        // ApiException de la app 2.0.4 lee message/statusCode/code/data de forma
        // genérica: un `code` que no conoce se propaga como error normal y no
        // rompe nada. Lo que sí necesita es JSON y un mensaje legible.
        $this->assertIsString($r->json('message'));
        $this->assertNotEmpty($r->json('message'));
        $this->assertSame('application/json', substr((string) $r->headers->get('content-type'), 0, 16));
    }

    public function test_15b_un_socio_al_dia_atraviesa_el_guard_sin_enterarse(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->manana());

        $this->getJson('/api/member/training/today', $this->comoSocio($socio))->assertOk();
    }

    // ── 16-19 · Varias deudas y deudores raros ──────────────────────────────

    public function test_16_cualquier_deuda_de_gimnasio_vencida_basta_para_retener(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, total: 100000, abonado: 100000, vence: $this->ayer());  // saldada
        $this->deuda($socio, total: 50000, abonado: 10000, vence: $this->ayer());    // viva

        $vencidas = $this->standing()->overdueReceivables($socio);
        $this->assertCount(1, $vencidas, 'solo la que de verdad debe');
        $this->assertTrue($this->standing()->isOverdue($socio));
    }

    public function test_17_pagar_el_plan_nuevo_no_borra_la_deuda_vieja_vencida(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, total: 200000, abonado: 80000, vence: $this->ayer());   // la vieja
        $nueva = $this->deuda($socio, total: 150000, abonado: 50000, vence: $this->manana());
        app(ReceivableService::class)->settle($nueva, Money::fromAmount(100000), 'cash', $this->cajero);

        // El plan nuevo está pagado y aun así sigue retenido: mirar solo la
        // última deuda dejaría entrar a quien todavía debe la anterior.
        $this->assertSame(Receivable::STATUS_PAID, $nueva->fresh()->status);
        $this->assertTrue($this->standing()->isOverdue($socio));
    }

    public function test_18_deber_en_las_dos_cajas_solo_retiene_por_la_del_gimnasio(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, total: 60000, abonado: 0, vence: $this->ayer(), tipo: CashShiftType::PRODUCTS);
        $this->deuda($socio, total: 200000, abonado: 80000, vence: $this->ayer());

        $resumen = $this->standing()->summary($socio);
        $this->assertTrue($resumen['overdue']);
        // 120.000 del gimnasio; los 60.000 de cafetería NO se suman al bloqueo.
        $this->assertEqualsWithDelta(120000, $resumen['balance'], 0.01);
        $this->assertSame(1, $resumen['receivables_count']);
    }

    public function test_19_una_deuda_sin_socio_no_puede_retener_a_nadie(): void
    {
        // En producción existen deudas de recepción con member_id nulo: el
        // servicio tiene que convivir con eso, no reventar con un null.
        $recepcion = Admin::create([
            'name' => 'Mostrador',
            'email' => 'recepcion-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => 'Recepción',
            'status' => 'active',
        ]);

        $this->turno(CashShiftType::PRODUCTS);
        app(ReceivableService::class)->create(
            debtorType: DebtorType::RECEPTION,
            debtorId: $recepcion->id,
            type: CashShiftType::PRODUCTS,
            concept: 'Consumo mostrador',
            total: Money::fromAmount(20000),
            actor: $this->cajero,
            dueAt: Carbon::parse($this->ayer()),
        );

        $this->assertFalse($this->standing()->isOverdue(null));
        $this->assertSame(MembershipFinancialStanding::GOOD, $this->standing()->standing(null));
    }

    public function test_20_una_deuda_cancelada_se_ignora(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        $this->assertTrue($this->standing()->isOverdue($socio), 'preparación');

        // El flujo de cancelación todavía no existe (hallazgo de la auditoría),
        // pero el predicado ya tiene que respetarlo para el día que exista.
        $cuenta->update(['status' => Receivable::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $this->assertFalse($this->standing()->isOverdue($socio));
    }

    // ── 21-23 · Detector, idempotencia y transiciones ───────────────────────

    public function test_21_el_detector_es_idempotente(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->ayer());

        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        $this->assertSame(
            1,
            AutomationEvent::where('event_type', 'receivable.overdue')->count(),
            'Tres corridas, un solo aviso.',
        );
    }

    public function test_22_el_detector_no_avisa_de_una_mora_que_ya_se_pago(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());

        // El pago final entra ANTES de que corra el detector: avisar ahora sería
        // una notificación fantasma.
        app(ReceivableService::class)->settle($cuenta, Money::fromAmount(120000), 'cash', $this->cajero);
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        $this->assertSame(0, AutomationEvent::where('event_type', 'receivable.overdue')->count());
    }

    public function test_22b_la_carrera_entre_el_detector_y_el_pago_final_no_deja_avisos_fantasma(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        app(ReceivableService::class)->settle($cuenta, Money::fromAmount(120000), 'cash', $this->cajero);

        // La carrera real: la consulta del detector leyó la cuenta cuando aún
        // debía, y el pago final entró antes de que llegara a emitir. Se simula
        // dejando el `status` como lo vio la consulta, con el saldo ya en cero.
        $cuenta->fresh()->updateQuietly(['status' => Receivable::STATUS_PARTIALLY_PAID]);
        $this->assertTrue(
            Receivable::query()->overdue()->whereKey($cuenta->id)->exists(),
            'preparación: la consulta del detector tiene que seleccionarla',
        );

        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        // Al tomar el bloqueo se revalida contra el dinero, no contra el estado
        // que traía la consulta: ya no debe nada, así que no se avisa de nada.
        $this->assertSame(0, AutomationEvent::where('event_type', 'receivable.overdue')->count());
    }

    public function test_23_el_ciclo_completo_emite_cada_transicion_una_sola_vez(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        $detectar = fn () => $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        // 1 · vence
        $detectar();
        $this->assertSame(1, AutomationEvent::where('event_type', 'receivable.overdue')->count());

        // 2 · se paga → se libera
        $final = app(ReceivableService::class)->settle($cuenta, Money::fromAmount(120000), 'cash', $this->cajero);
        $detectar();
        $this->assertSame(1, AutomationEvent::where('event_type', 'receivable.cleared')->count());

        // 3 · se anula el pago → vuelve a vencer. Ésta es la que una llave
        // ingenua silenciaría para siempre.
        app(ReceivableService::class)->reverse($final, 'error de caja', $this->cajero);
        $detectar();
        $this->assertSame(2, AutomationEvent::where('event_type', 'receivable.overdue')->count(),
            'La segunda mora es legítima y tiene que avisar.');

        // 4 · se vuelve a pagar → se libera otra vez
        app(ReceivableService::class)->settle($cuenta->fresh(), Money::fromAmount(120000), 'cash', $this->cajero);
        $detectar();
        $this->assertSame(2, AutomationEvent::where('event_type', 'receivable.cleared')->count());

        // Y repetir el comando no añade ninguna.
        $detectar();
        $detectar();
        $this->assertSame(2, AutomationEvent::where('event_type', 'receivable.overdue')->count());
        $this->assertSame(2, AutomationEvent::where('event_type', 'receivable.cleared')->count());
    }

    // ── 24 · Zona horaria ───────────────────────────────────────────────────

    public function test_24_el_vencimiento_se_juzga_con_el_dia_de_colombia(): void
    {
        // 04:30 UTC del día 16 son las 23:30 del día 15 en Bogotá: en Colombia
        // todavía es el día pactado, y por tanto no está vencida.
        $this->travelTo(Carbon::parse('2026-09-16 04:30:00', 'UTC'));

        $socio = $this->socio();
        $this->deuda($socio, vence: '2026-09-15');

        $this->assertSame('2026-09-15', Receivable::businessToday()->toDateString());
        $this->assertFalse(
            $this->standing()->isOverdue($socio),
            'Con la hora del servidor en UTC lo habríamos dado por vencido un día antes.',
        );

        // Una hora después ya es 16 en Colombia: ahora sí.
        $this->travelTo(Carbon::parse('2026-09-16 05:30:00', 'UTC'));
        $this->assertTrue($this->standing()->isOverdue($socio));
    }

    // ── 25-26 · No regresión ────────────────────────────────────────────────

    public function test_25_vencer_una_deuda_no_mueve_ningun_ingreso(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->ayer());

        $pagosAntes = ReceivablePayment::sum('amount');
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        $this->assertEqualsWithDelta($pagosAntes, ReceivablePayment::sum('amount'), 0.01,
            'La mora no mueve dinero: el reconocimiento sigue siendo de caja.');
    }

    public function test_26_vencer_una_deuda_de_productos_no_toca_el_inventario(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, total: 50000, abonado: 0, vence: $this->ayer(), tipo: CashShiftType::PRODUCTS);

        $ventasAntes = \App\Models\ProductSale::count();
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        $this->assertSame($ventasAntes, \App\Models\ProductSale::count(),
            'Vencer no crea ventas, no descuenta stock y no lo devuelve.');
    }

    // ── 27-29 · Estado de cuenta, SSE y CRM ─────────────────────────────────

    public function test_27_el_estado_de_cuenta_explica_la_retencion(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->ayer());

        $r = $this->getJson("/api/admin/receivables/account/{$socio->id}", $this->admin())->assertOk();

        $this->assertTrue($r->json('data.financial_standing.overdue'));
        $this->assertSame($this->ayer(), $r->json('data.financial_standing.due_date'));
        $this->assertSame(1, $r->json('data.financial_standing.days_overdue'));

        // Y cada obligación trae su fecha sin que haya que recalcular el saldo.
        $primera = $r->json('data.obligations.0');
        $this->assertSame($this->ayer(), $primera['due_date']);
        $this->assertTrue($primera['overdue']);
        $this->assertSame(1, $primera['days_overdue']);
        $this->assertEqualsWithDelta(120000, $primera['balance'], 0.01);
    }

    public function test_28_cruzar_la_mora_empuja_el_refresco_de_la_app(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->ayer());

        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        $this->assertDatabaseHas('member_realtime_events', [
            'member_id' => $socio->id,
            'type' => \App\Services\RealtimeEvents::APP_STATE,
        ]);

        // Y la segunda corrida NO despierta otra vez al móvil.
        $antes = MemberRealtimeEvent::where('member_id', $socio->id)->count();
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();
        $this->assertSame($antes, MemberRealtimeEvent::where('member_id', $socio->id)->count());
    }

    public function test_29_cambiar_la_fecha_limite_mueve_la_firma_del_stream_del_crm(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->manana());

        $firma = fn () => Receivable::count().':'.(string) Receivable::max('updated_at');
        $antes = $firma();

        $this->travel(2)->seconds();
        $cuenta->update(['due_at' => $this->ayer()]);

        $this->assertNotSame($antes, $firma(),
            'Pactar otra fecha no crea ningún abono: sin esto el CRM no se enteraría.');
    }

    // ── 30-32 · Reglas de negocio ───────────────────────────────────────────

    public function test_30_se_puede_vender_un_plan_nuevo_a_quien_tiene_deuda_vencida(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, vence: $this->ayer());
        $plan = Plan::create(['name' => 'Otro', 'price' => 150000, 'duration_days' => 30, 'active' => true]);

        // No se rechaza la venta: si el socio llega con dinero, el gimnasio lo
        // quiere. Pero la deuda vieja sigue viva y sigue reteniendo.
        $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 50000, 'method' => 'cash',
        ], $this->admin())->assertStatus(201);

        $this->assertTrue($this->standing()->isOverdue($socio));
    }

    public function test_31_una_fecha_limite_pasada_se_rechaza(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Premium', 'price' => 200000, 'duration_days' => 30, 'active' => true]);
        $this->turno();

        $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 80000, 'method' => 'cash',
            'due_at' => $this->ayer(),
        ], $this->admin())->assertStatus(422)->assertJsonValidationErrors('due_at');
    }

    public function test_32_sin_fecha_el_plan_a_plazos_toma_el_plazo_configurado(): void
    {
        config(['caja.default_payment_term_days' => 15]);
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Premium', 'price' => 200000, 'duration_days' => 30, 'active' => true]);
        $this->turno();

        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 80000, 'method' => 'cash',
        ], $this->admin())->assertStatus(201);

        $this->assertSame(
            Receivable::businessToday()->addDays(15)->toDateString(),
            $r->json('data.due_date'),
        );
    }

    public function test_32b_con_el_plazo_en_cero_la_deuda_nace_sin_vencimiento(): void
    {
        config(['caja.default_payment_term_days' => 0]);
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'Premium', 'price' => 200000, 'duration_days' => 30, 'active' => true]);
        $this->turno();

        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 80000, 'method' => 'cash',
        ], $this->admin())->assertStatus(201);

        $this->assertNull($r->json('data.due_date'));
    }

    public function test_32c_una_deuda_suelta_acepta_la_fecha_que_pacte_el_mostrador(): void
    {
        $socio = $this->socio();
        $this->turno(CashShiftType::PRODUCTS);

        $r = $this->postJson('/api/admin/receivables', [
            'debtor_type' => DebtorType::MEMBER->value,
            'debtor_id' => $socio->id,
            'type' => CashShiftType::PRODUCTS->value,
            'concept' => 'Consumo cafetería',
            'total_amount' => 35000,
            'due_at' => $this->manana(),
        ], $this->admin())->assertStatus(201);

        $this->assertSame($this->manana(), $r->json('data.due_date'));
    }
}
