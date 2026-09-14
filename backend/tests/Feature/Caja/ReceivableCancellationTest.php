<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Exceptions\ReceivableException;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CashShift;
use App\Models\CommercialAlert;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\MembershipFinancialStanding;
use App\Services\Caja\ReceivableDueNotifier;
use App\Services\Caja\ReceivableService;
use App\Services\RealtimeEvents;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Correcciones administrativas sobre una deuda: anular, reabrir y mover el plazo.
 *
 * LO QUE SE PROTEGE AQUÍ. Una deuda mal creada no se borra. Antes de esto, el
 * estado `cancelled` existía en el modelo y nadie lo escribía: la única forma de
 * quitar de encima una deuda equivocada era borrar la fila —y con ella el rastro
 * de un dinero que sí entró— o dejarla ahí reteniendo a un socio que no debía
 * nada. Las dos salidas son inaceptables cuando el vencimiento bloquea.
 *
 * Y las cuatro cosas que anular NO es, porque confundirlas cuesta dinero real:
 *
 *   · No es devolver dinero. Los abonos cobrados siguen cobrados y siguen en el
 *     arqueo del turno donde entraron.
 *   · No es devolver el producto.
 *   · No es cancelar la membresía.
 *   · No es poner `total_amount = pagado` para que cuadre. Eso reescribiría la
 *     historia; aquí el total no se toca nunca.
 */
class ReceivableCancellationTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Supervisión',
            'email' => 'anular-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        config(['caja.payment_terms.gym' => 15, 'caja.payment_terms.products' => 8]);
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function socio(string $nombre = 'Alejandro Casas'): Member
    {
        $user = User::create([
            'name' => $nombre,
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
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

    private function admin(): array
    {
        return $this->actingAsAdmin($this->cajero);
    }

    private function turno(CashShiftType $tipo = CashShiftType::GYM): CashShift
    {
        $abierto = CashShift::where('type', $tipo->value)->open()->first();

        return $abierto ?? app(CashShiftService::class)->open($this->cajero, $tipo);
    }

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

    private function alertaDe(Receivable $cuenta): ?CommercialAlert
    {
        return CommercialAlert::where('fingerprint', 'receivable_overdue:'.$cuenta->id)->first();
    }

    /** @return Collection<int, AuditLog> */
    private function trazasDe(Receivable $cuenta)
    {
        return AuditLog::where('entity', 'cuenta por cobrar')
            ->where('entity_id', (string) $cuenta->id)
            ->orderBy('id')
            ->get();
    }

    // ── 1-8 · Anular ────────────────────────────────────────────────────────

    public function test_1_anular_marca_el_estado_con_autor_y_motivo(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->manana());

        $r = $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Se cobró por error: el socio ya había pagado en efectivo.',
        ], $this->admin())->assertOk();

        $this->assertSame(Receivable::STATUS_CANCELLED, $r->json('data.status'));
        $this->assertTrue($r->json('data.cancelled'));

        $fresca = $cuenta->fresh();
        $this->assertNotNull($fresca->cancelled_at);
        $this->assertSame($this->cajero->id, $fresca->cancelled_by);
        $this->assertStringContainsString('por error', (string) $fresca->cancellation_reason);
    }

    public function test_2_anular_no_borra_la_fila_ni_los_abonos_cobrados(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, total: 200000, abonado: 80000);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Acuerdo con administración: no se sigue cobrando.',
        ], $this->admin())->assertOk();

        $this->assertDatabaseHas('receivables', ['id' => $cuenta->id]);
        $this->assertSame(1, ReceivablePayment::where('receivable_id', $cuenta->id)
            ->where('status', ReceivablePayment::STATUS_APPLIED)->count());
        $this->assertEqualsWithDelta(80000, $cuenta->fresh()->paidAmount()->toFloat(), 0.01);
    }

    public function test_3_anular_no_reescribe_el_total_para_que_cuadre(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, total: 200000, abonado: 80000);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Deuda duplicada al registrar la venta dos veces.',
        ], $this->admin())->assertOk();

        // Si el total bajara a lo pagado, la deuda parecería saldada y el
        // informe de mora del mes diría que se cobró algo que se perdonó.
        $this->assertEqualsWithDelta(200000, $cuenta->fresh()->total()->toFloat(), 0.01);
        $this->assertEqualsWithDelta(120000, $cuenta->fresh()->balance()->toFloat(), 0.01);
    }

    public function test_4_una_deuda_pagada_no_se_anula_se_revierte(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, total: 100000, abonado: 100000);
        $this->assertTrue($cuenta->fresh()->isSettled());

        $r = $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Quiero deshacer este cobro por la vía rápida.',
        ], $this->admin())->assertStatus(422);

        $this->assertSame('receivable_already_paid', $r->json('code'));
        $this->assertSame(Receivable::STATUS_PAID, $cuenta->fresh()->status);
    }

    public function test_5_anular_sin_motivo_se_rechaza(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => '',
        ], $this->admin())->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertFalse($cuenta->fresh()->isCancelled());
    }

    public function test_6_anular_dos_veces_no_duplica_nada(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio);

        $payload = ['reason' => 'Cobro registrado sobre el socio equivocado.'];
        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", $payload, $this->admin())->assertOk();
        $anulada = $cuenta->fresh();

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Otro motivo distinto, escrito por otra persona.',
        ], $this->admin())->assertOk();

        // El segundo intento no reescribe ni el motivo ni la hora: la anulación
        // ya ocurrió, y decir que ocurrió otra vez sería falso.
        $this->assertSame($anulada->cancellation_reason, $cuenta->fresh()->cancellation_reason);
        $this->assertSame(
            $anulada->cancelled_at->toIso8601String(),
            $cuenta->fresh()->cancelled_at->toIso8601String(),
        );
        $this->assertSame(1, $this->trazasDe($cuenta)->where('action', 'status')->count());
    }

    public function test_7_anular_deja_traza_con_el_saldo_que_se_dejo_de_exigir(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, total: 200000, abonado: 80000);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Se anula por acuerdo con el socio tras la queja.',
        ], $this->admin())->assertOk();

        $traza = $this->trazasDe($cuenta)->firstWhere('action', 'status');
        $this->assertNotNull($traza);
        $this->assertSame($this->cajero->name, $traza->actor_name);
        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $traza->changes[0]['before']);
        $this->assertSame(Receivable::STATUS_CANCELLED, $traza->changes[0]['after']);
        // Lo que alguien tendrá que justificar es lo PERDONADO, no el total.
        $this->assertEqualsWithDelta(120000, (float) $traza->metadata['forgiven'], 0.01);
        $this->assertEqualsWithDelta(80000, (float) $traza->metadata['paid'], 0.01);
        $this->assertStringContainsString('acuerdo con el socio', $traza->metadata['reason']);
    }

    public function test_8_anular_levanta_la_retencion_del_socio(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        $this->assertTrue($this->standing()->isOverdue($socio));

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'La deuda se creó por error de digitación en caja.',
        ], $this->admin())->assertOk();

        $this->assertFalse($this->standing()->isOverdue($socio->fresh()));
    }

    // ── 9-13 · Lo que anular NO toca ────────────────────────────────────────

    public function test_9_anular_no_cancela_la_membresia(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        $finAntes = $socio->user->membershipEndDate;
        $planAntes = $socio->user->plan;

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Perdón acordado por la dirección del gimnasio.',
        ], $this->admin())->assertOk();

        $usuario = $socio->fresh()->user;
        $this->assertSame($finAntes, $usuario->membershipEndDate);
        $this->assertSame($planAntes, $usuario->plan);
        $this->assertSame(Member::STATUS_ACTIVE, $socio->fresh()->status);
    }

    public function test_10_el_dinero_ya_cobrado_sigue_contando_despues_de_anular(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, total: 200000, abonado: 80000);
        $turno = $this->turno();

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Se deja de exigir el saldo restante por acuerdo.',
        ], $this->admin())->assertOk();

        // El abono sigue aplicado y sigue atado a su turno: anular la deuda no
        // puede descuadrar un arqueo que alguien ya contó.
        $abono = ReceivablePayment::where('receivable_id', $cuenta->id)->firstOrFail();
        $this->assertSame(ReceivablePayment::STATUS_APPLIED, $abono->status);
        $this->assertSame($turno->id, $abono->cash_shift_id);
        $this->assertNull($abono->reversed_at);
    }

    public function test_11_sobre_una_deuda_anulada_no_se_puede_abonar(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Deuda registrada dos veces en la misma venta.',
        ], $this->admin())->assertOk();

        $r = $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 10000,
            'method' => 'cash',
        ], $this->admin())->assertStatus(422);

        $this->assertSame('receivable_cancelled', $r->json('code'));
        $this->assertSame(1, ReceivablePayment::where('receivable_id', $cuenta->id)->count());
    }

    public function test_12_una_deuda_anulada_no_aparece_entre_las_vencidas(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Se anula porque el cobro correspondía a otra sede.',
        ], $this->admin())->assertOk();

        $this->assertTrue($this->standing()->overdueReceivables($socio->fresh())->isEmpty());
        $this->assertSame(0, Receivable::query()->overdue()->count());
    }

    public function test_13_anular_cierra_la_alerta_diciendo_que_fue_anulacion(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();
        $this->assertSame(CommercialAlert::STATUS_OPEN, $this->alertaDe($cuenta)?->status);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'La deuda no existía: error al registrar la venta.',
        ], $this->admin())->assertOk();

        $alerta = $this->alertaDe($cuenta);
        $this->assertSame(CommercialAlert::STATUS_RESOLVED, $alerta?->status);
        // NO como pagada: nadie pagó, y dejarlo así inflaría el cobro del mes.
        $this->assertSame(ReceivableDueNotifier::RESOLUTION_CANCELLED, $alerta?->resolution);
    }

    public function test_14_anular_empuja_el_refresco_de_la_app_solo_si_retenia(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        MemberRealtimeEvent::where('member_id', $socio->id)->delete();

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Error de mostrador: la venta era de otra persona.',
        ], $this->admin())->assertOk();

        $this->assertDatabaseHas('member_realtime_events', [
            'member_id' => $socio->id,
            'type' => RealtimeEvents::APP_STATE,
        ]);

        // Y una deuda que no retenía no despierta a nadie al anularse.
        $otro = $this->socio('Sin mora');
        $suya = $this->deuda($otro, vence: $this->manana());
        $this->postJson("/api/admin/receivables/{$suya->id}/cancel", [
            'reason' => 'Se anula antes de que llegue el plazo pactado.',
        ], $this->admin())->assertOk();

        $this->assertSame(0, MemberRealtimeEvent::where('member_id', $otro->id)
            ->where('type', RealtimeEvents::APP_STATE)->count());
    }

    // ── 15-20 · Reabrir ─────────────────────────────────────────────────────

    public function test_15_reabrir_devuelve_el_estado_que_le_toca_por_su_saldo(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, total: 200000, abonado: 80000);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Anulada por confusión con otra cuenta del socio.',
        ], $this->admin())->assertOk();

        $r = $this->postJson("/api/admin/receivables/{$cuenta->id}/reopen", [
            'reason' => 'Nos equivocamos al anular: la deuda sí era suya.',
        ], $this->admin())->assertOk();

        // `partially_paid` y no `pending`: tiene 80.000 abonados de verdad.
        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $r->json('data.status'));
        $this->assertFalse($r->json('data.cancelled'));
    }

    public function test_16_reabrir_una_deuda_sin_abonos_la_deja_pendiente(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, total: 200000, abonado: 0);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Anulada por error del turno de la mañana.',
        ], $this->admin())->assertOk();
        $this->postJson("/api/admin/receivables/{$cuenta->id}/reopen", [
            'reason' => 'Reabierta tras revisar el caso con administración.',
        ], $this->admin())->assertOk();

        $this->assertSame(Receivable::STATUS_PENDING, $cuenta->fresh()->status);
    }

    public function test_17_reabrir_limpia_la_marca_de_anulacion(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Anulación pedida por teléfono sin verificar.',
        ], $this->admin())->assertOk();
        $this->postJson("/api/admin/receivables/{$cuenta->id}/reopen", [
            'reason' => 'La verificación salió negativa: la deuda sigue viva.',
        ], $this->admin())->assertOk();

        $fresca = $cuenta->fresh();
        $this->assertNull($fresca->cancelled_at);
        $this->assertNull($fresca->cancelled_by);
        $this->assertNull($fresca->cancellation_reason);

        // Lo que pasó no se pierde: sigue en la auditoría, que no se toca.
        $this->assertSame(2, $this->trazasDe($cuenta)->where('action', 'status')->count());
    }

    public function test_18_reabrir_una_deuda_viva_no_hace_nada(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/reopen", [
            'reason' => 'Intento de reabrir algo que nunca se anuló.',
        ], $this->admin())->assertOk();

        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $cuenta->fresh()->status);
        $this->assertSame(0, $this->trazasDe($cuenta)->where('action', 'status')->count());
    }

    public function test_19_reabrir_una_deuda_vencida_vuelve_a_retener_y_a_alertar(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Anulada mientras se revisaba la reclamación.',
        ], $this->admin())->assertOk();
        $this->assertFalse($this->standing()->isOverdue($socio->fresh()));

        $this->postJson("/api/admin/receivables/{$cuenta->id}/reopen", [
            'reason' => 'La reclamación se resolvió en contra: la deuda vuelve.',
        ], $this->admin())->assertOk();

        $this->assertTrue($this->standing()->isOverdue($socio->fresh()));
        // La alerta se levanta AQUÍ y no en el detector nocturno: su llave de
        // idempotencia no cambió al reabrir, así que la mora no volvería a
        // emitirse y el problema se quedaría sin dueño.
        $this->assertSame(CommercialAlert::STATUS_OPEN, $this->alertaDe($cuenta)?->status);
    }

    public function test_20_reabrir_sin_motivo_se_rechaza(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio);
        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Anulada por revisión de la venta de ayer.',
        ], $this->admin())->assertOk();

        $this->postJson("/api/admin/receivables/{$cuenta->id}/reopen", [], $this->admin())
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertTrue($cuenta->fresh()->isCancelled());
    }

    // ── 21-29 · El plazo ────────────────────────────────────────────────────

    public function test_21_cambiar_el_plazo_mueve_la_fecha_y_lo_audita(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());

        $nueva = Receivable::businessToday()->addDays(10)->toDateString();
        $r = $this->patchJson("/api/admin/receivables/{$cuenta->id}/due-date", [
            'due_at' => $nueva,
            'reason' => 'El socio pidió diez días más y se le concedieron.',
        ], $this->admin())->assertOk();

        $this->assertSame($nueva, $r->json('data.due_date'));
        $this->assertFalse($r->json('data.overdue'));

        $traza = $this->trazasDe($cuenta)->firstWhere('action', 'update');
        $this->assertSame($this->ayer(), $traza->changes[0]['before']);
        $this->assertSame($nueva, $traza->changes[0]['after']);
    }

    public function test_22_mover_el_plazo_al_futuro_levanta_la_retencion(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        $this->assertTrue($this->standing()->isOverdue($socio));

        $this->patchJson("/api/admin/receivables/{$cuenta->id}/due-date", [
            'due_at' => $this->manana(),
            'reason' => 'Se pacta nuevo plazo tras hablar con el socio.',
        ], $this->admin())->assertOk();

        $this->assertFalse($this->standing()->isOverdue($socio->fresh()));
        // Sin perdonar un peso: la deuda sigue entera.
        $this->assertEqualsWithDelta(120000, $cuenta->fresh()->balance()->toFloat(), 0.01);
    }

    public function test_23_quitar_el_plazo_libera_pero_no_perdona(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());

        $r = $this->json('DELETE', "/api/admin/receivables/{$cuenta->id}/due-date", [
            'reason' => 'Caso en revisión: se le quita el plazo mientras tanto.',
        ], $this->admin())->assertOk();

        $this->assertNull($r->json('data.due_date'));
        $this->assertFalse($r->json('data.overdue'));
        $this->assertFalse($this->standing()->isOverdue($socio->fresh()));

        // Sigue debiendo, y sigue apareciendo como deuda viva.
        $this->assertEqualsWithDelta(120000, $cuenta->fresh()->balance()->toFloat(), 0.01);
        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $cuenta->fresh()->status);
    }

    public function test_24_una_deuda_anulada_no_admite_plazo(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio);
        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Anulada tras confirmar que fue un error de caja.',
        ], $this->admin())->assertOk();

        $r = $this->patchJson("/api/admin/receivables/{$cuenta->id}/due-date", [
            'due_at' => $this->manana(),
            'reason' => 'Intento de pactar un plazo sobre algo ya anulado.',
        ], $this->admin())->assertStatus(422);

        $this->assertSame('receivable_cancelled', $r->json('code'));
    }

    public function test_25_una_deuda_pagada_tampoco_admite_plazo(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, total: 100000, abonado: 100000);

        $r = $this->patchJson("/api/admin/receivables/{$cuenta->id}/due-date", [
            'due_at' => $this->manana(),
            'reason' => 'Intento de mover el plazo de una cuenta ya saldada.',
        ], $this->admin())->assertStatus(422);

        $this->assertSame('receivable_already_paid', $r->json('code'));
    }

    public function test_26_pactar_el_mismo_plazo_no_deja_traza(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->manana());

        $this->patchJson("/api/admin/receivables/{$cuenta->id}/due-date", [
            'due_at' => $this->manana(),
            'reason' => 'Confirmación del plazo que ya estaba pactado.',
        ], $this->admin())->assertOk();

        // Auditar un cambio que no cambió nada llena el libro de ruido y hace
        // que el que sí importa pase desapercibido.
        $this->assertSame(0, $this->trazasDe($cuenta)->where('action', 'update')->count());
    }

    public function test_27_aplazar_cierra_la_alerta_y_vuelve_a_abrirla_al_vencer(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();
        $this->assertSame(CommercialAlert::STATUS_OPEN, $this->alertaDe($cuenta)?->status);

        $this->patchJson("/api/admin/receivables/{$cuenta->id}/due-date", [
            'due_at' => $this->manana(),
            'reason' => 'Se le da hasta mañana para traer el dinero del saldo.',
        ], $this->admin())->assertOk();

        $alerta = $this->alertaDe($cuenta);
        $this->assertSame(CommercialAlert::STATUS_RESOLVED, $alerta?->status);
        $this->assertSame(ReceivableDueNotifier::RESOLUTION_RESCHEDULED, $alerta?->resolution);

        // Y si el plazo nuevo también se pasa, el problema vuelve a tener dueño.
        // Se deja correr el tiempo en vez de reescribir la fecha: aplazar y que
        // el aplazamiento venza es UNA mora distinta de la primera, y lo que se
        // comprueba es precisamente que el detector la distinga.
        $this->travel(2)->days();
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();
        $this->assertSame(CommercialAlert::STATUS_OPEN, $this->alertaDe($cuenta)?->status);
    }

    public function test_28_corregir_la_fecha_a_otro_dia_pasado_no_calla_la_alerta(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        // Corregir una fecha mal tecleada por otra que también pasó NO es
        // aplazar: la deuda sigue vencida, y cerrar su alerta dejaría una mora
        // real sin nadie detrás por haber arreglado un dígito.
        $this->patchJson("/api/admin/receivables/{$cuenta->id}/due-date", [
            'due_at' => Receivable::businessToday()->subDays(4)->toDateString(),
            'reason' => 'La fecha se tecleó mal: el plazo real era anterior.',
        ], $this->admin())->assertOk();

        $this->assertSame(CommercialAlert::STATUS_OPEN, $this->alertaDe($cuenta)?->status);
        $this->assertTrue($this->standing()->isOverdue($socio->fresh()));
    }

    public function test_29_el_servicio_exige_motivo_aunque_nadie_lo_valide_antes(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->manana());
        $servicio = app(ReceivableService::class);

        // El controlador ya exige diez caracteres, pero el servicio se llama
        // también desde comandos y desde tinker. El motivo ES la auditoría: sin
        // él queda una corrección firmada que no explica nada.
        foreach ([
            fn () => $servicio->cancel($cuenta, '   ', $this->cajero),
            fn () => $servicio->changeDueDate($cuenta, Receivable::businessToday()->addDays(3), '', $this->cajero),
        ] as $operacion) {
            try {
                $operacion();
                $this->fail('Una corrección sin motivo no puede aceptarse.');
            } catch (ReceivableException $e) {
                $this->assertStringContainsString('reason_required', $e->code_);
            }
        }

        $servicio->cancel($cuenta, 'Anulada con motivo, como debe ser.', $this->cajero);

        try {
            $servicio->reopen($cuenta->fresh(), "\t", $this->cajero);
            $this->fail('Reabrir sin motivo no puede aceptarse.');
        } catch (ReceivableException $e) {
            $this->assertSame('reopen_reason_required', $e->code_);
        }

        $this->assertTrue($cuenta->fresh()->isCancelled());
    }

    // ── 30-33 · Quién puede hacer qué ───────────────────────────────────────

    public function test_30_recepcion_puede_pactar_un_plazo_nuevo(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());

        $this->patchJson("/api/admin/receivables/{$cuenta->id}/due-date", [
            'due_at' => $this->manana(),
            'reason' => 'El socio vino al mostrador y pidió unos días más.',
        ], $this->adminWithPermissions(['receivables.view', 'receivables.operate']))->assertOk();

        $this->assertSame($this->manana(), optional($cuenta->fresh()->due_at)->toDateString());
    }

    public function test_31_recepcion_no_puede_anular_una_deuda(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Intento de anular desde el mostrador sin supervisión.',
        ], $this->adminWithPermissions(['receivables.view', 'receivables.operate']))->assertForbidden();

        $this->assertFalse($cuenta->fresh()->isCancelled());
    }

    public function test_32_quitar_el_plazo_exige_supervision(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio, vence: $this->ayer());

        // Es la forma silenciosa de levantar una retención: cobrar no basta.
        $this->json('DELETE', "/api/admin/receivables/{$cuenta->id}/due-date", [
            'reason' => 'Intento de dejar sin fecha desde el mostrador.',
        ], $this->adminWithPermissions(['receivables.view', 'receivables.operate']))->assertForbidden();

        $this->assertSame($this->ayer(), optional($cuenta->fresh()->due_at)->toDateString());
        $this->assertTrue($this->standing()->isOverdue($socio->fresh()));
    }

    public function test_33_reabrir_exige_supervision(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio);
        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Anulada por la supervisora del turno de la tarde.',
        ], $this->admin())->assertOk();

        $this->postJson("/api/admin/receivables/{$cuenta->id}/reopen", [
            'reason' => 'Intento de reabrir desde el mostrador sin permiso.',
        ], $this->adminWithPermissions(['receivables.view', 'receivables.operate']))->assertForbidden();

        $this->assertTrue($cuenta->fresh()->isCancelled());
    }

    // ── 34-37 · Lo que ve el CRM ────────────────────────────────────────────

    public function test_34_cada_cuenta_dice_que_admite_por_su_estado(): void
    {
        $socio = $this->socio();
        $viva = $this->deuda($socio);
        $pagada = $this->deuda($socio, total: 50000, abonado: 50000);

        $r = $this->getJson("/api/admin/receivables/{$viva->id}", $this->admin())->assertOk();
        $this->assertTrue($r->json('data.can_cancel'));
        $this->assertTrue($r->json('data.can_edit_due_date'));
        $this->assertFalse($r->json('data.can_reopen'));

        $r = $this->getJson("/api/admin/receivables/{$pagada->id}", $this->admin())->assertOk();
        $this->assertFalse($r->json('data.can_cancel'));
        $this->assertFalse($r->json('data.can_edit_due_date'));

        $this->postJson("/api/admin/receivables/{$viva->id}/cancel", [
            'reason' => 'Anulada para comprobar qué ofrece la pantalla.',
        ], $this->admin())->assertOk();

        $r = $this->getJson("/api/admin/receivables/{$viva->id}", $this->admin())->assertOk();
        $this->assertTrue($r->json('data.can_reopen'));
        $this->assertFalse($r->json('data.can_cancel'));
        $this->assertFalse($r->json('data.can_edit_due_date'));
    }

    public function test_35_las_anuladas_tienen_su_propia_pestana(): void
    {
        $socio = $this->socio();
        $viva = $this->deuda($socio);
        $anulada = $this->deuda($socio, total: 50000, abonado: 0);

        $this->postJson("/api/admin/receivables/{$anulada->id}/cancel", [
            'reason' => 'Anulada para comprobar el listado de anuladas.',
        ], $this->admin())->assertOk();

        $r = $this->getJson('/api/admin/receivables?scope=cancelled', $this->admin())->assertOk();
        $this->assertSame([$anulada->id], array_column($r->json('data'), 'id'));

        // Y desaparece de las que hay que cobrar.
        $r = $this->getJson('/api/admin/receivables?scope=all', $this->admin())->assertOk();
        $this->assertSame([$viva->id], array_column($r->json('data'), 'id'));
    }

    public function test_36_el_listado_dice_quien_anulo_sin_una_consulta_por_fila(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deuda($socio);
        $this->postJson("/api/admin/receivables/{$cuenta->id}/cancel", [
            'reason' => 'Cobrada por error: el socio nunca se llevó el plan.',
        ], $this->admin())->assertOk();

        $r = $this->getJson('/api/admin/receivables?scope=cancelled', $this->admin())->assertOk();

        $fila = $r->json('data.0');
        $this->assertSame($this->cajero->name, $fila['cancelled_by_name']);
        $this->assertStringContainsString('por error', $fila['cancellation_reason']);
        $this->assertNotNull($fila['cancelled_at']);
    }

    public function test_37_el_saldo_anulado_sale_del_total_pendiente(): void
    {
        $socio = $this->socio();
        $this->deuda($socio, total: 100000, abonado: 0);
        $otra = $this->deuda($socio, total: 60000, abonado: 0);

        $r = $this->getJson('/api/admin/receivables?scope=all', $this->admin())->assertOk();
        $this->assertEqualsWithDelta(160000, $r->json('summary.outstanding_total'), 0.01);

        $this->postJson("/api/admin/receivables/{$otra->id}/cancel", [
            'reason' => 'Se anula porque correspondía a otro establecimiento.',
        ], $this->admin())->assertOk();

        // Lo que ya no se exige deja de contarse como cobrable. Si siguiera
        // sumando, el pendiente del mes diría que hay 60.000 por recuperar.
        $r = $this->getJson('/api/admin/receivables?scope=all', $this->admin())->assertOk();
        $this->assertEqualsWithDelta(100000, $r->json('summary.outstanding_total'), 0.01);
    }
}
