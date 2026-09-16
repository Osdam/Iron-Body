<?php

namespace Tests\Feature\Payments;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Anular un cobro le devuelve los días que había dado.
 *
 * EL CASO REAL. A una socia se le cobró el plan Élite (30 días), se anuló ese
 * cobro y se le volvió a cobrar dos días después. La app le mostraba 55 días
 * restantes de un plan de 30: el periodo anulado seguía dentro de
 * `users.membership_end_date`, así que el segundo cobro se encadenó al final de
 * un periodo que ya no existía en vez de empezar el día que pagó.
 *
 * Estos tests fijan las dos mitades: que los días vuelven, y que al volver no
 * se llevan por delante los días que el socio SÍ pagó.
 */
class CancelledPaymentReturnsMembershipDaysTest extends TestCase
{
    use RefreshDatabase;

    private Admin $recepcion;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recepcion = Admin::create([
            'name' => 'Laura Mostrador',
            'email' => 'anula-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        $this->h = $this->actingAsAdmin($this->recepcion);
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'gym'], $this->h)->assertStatus(201);
    }

    private function hoy(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    private function plan(int $dias = 30): Plan
    {
        return Plan::create(['name' => 'Élite', 'price' => 180000, 'duration_days' => $dias, 'active' => true]);
    }

    private function socio(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Socia '.uniqid(),
            'email' => 'socia-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
        ], $attrs));
    }

    private function cobrar(User $u, Plan $p, array $extra = []): Payment
    {
        $this->postJson('/api/payments', array_merge([
            'user_id' => $u->id,
            'plan_id' => $p->id,
            'amount' => 180000,
            'method' => 'transfer',
            'status' => 'paid',
        ], $extra), $this->h)->assertStatus(201);

        return Payment::where('user_id', $u->id)->latest('id')->firstOrFail();
    }

    private function anular(Payment $pago, string $estado = 'cancelled'): void
    {
        $this->putJson("/api/payments/{$pago->id}", ['status' => $estado], $this->actingAsAdmin($this->recepcion))
            ->assertOk();
    }

    // ── El caso que se vio en producción ────────────────────────────────────

    public function test_cobrar_anular_y_volver_a_cobrar_deja_un_solo_mes(): void
    {
        $u = $this->socio();
        $plan = $this->plan();

        $primero = $this->cobrar($u, $plan);
        $this->assertSame($this->hoy()->addDays(30)->toDateString(), $u->refresh()->membership_end_date);

        $this->anular($primero);
        $this->assertSame($this->hoy()->toDateString(), $u->refresh()->membership_end_date,
            'La membresía vuelve a donde empezó ese cobro.');

        // Dos días después se le vuelve a cobrar el mismo plan.
        $this->travel(2)->days();
        $this->h = $this->actingAsAdmin($this->recepcion);
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'gym'], $this->h);
        $segundo = $this->cobrar($u, $plan);

        $u->refresh();
        // 30 días desde HOY, no 55: el periodo anulado no está debajo.
        $this->assertSame(MembershipPeriod::today()->addDays(30)->toDateString(), $u->membership_end_date);
        $this->assertSame(MembershipPeriod::today()->toDateString(), $segundo->period_start->toDateString());
    }

    public function test_el_pago_anulado_deja_de_decir_que_cubre_un_periodo(): void
    {
        $u = $this->socio();
        $pago = $this->cobrar($u, $this->plan());

        $this->anular($pago);

        $pago->refresh();
        $this->assertNull($pago->period_start);
        $this->assertNull($pago->period_end);
    }

    // ── Devolver los días sin quitar los que sí se pagaron ──────────────────

    public function test_anular_el_primero_corre_hacia_atras_el_que_se_encadeno_detras(): void
    {
        $u = $this->socio();
        $plan = $this->plan();

        $primero = $this->cobrar($u, $plan);
        // Renovación anticipada: se encadena al final del primero.
        $segundo = $this->cobrar($u, $plan);
        $this->assertSame($this->hoy()->addDays(60)->toDateString(), $u->refresh()->membership_end_date);

        $this->anular($primero);

        $u->refresh();
        $segundo->refresh();
        // El segundo se pagó HOY, así que empieza hoy: queda un mes, no dos.
        $this->assertSame($this->hoy()->toDateString(), $segundo->period_start->toDateString());
        $this->assertSame($this->hoy()->addDays(30)->toDateString(), $segundo->period_end->toDateString());
        $this->assertSame($this->hoy()->addDays(30)->toDateString(), $u->membership_end_date);
    }

    public function test_el_periodo_recolocado_nunca_empieza_antes_de_haberse_pagado(): void
    {
        $u = $this->socio();
        $plan = $this->plan();

        $primero = $this->cobrar($u, $plan);

        // El segundo entra CINCO días más tarde, encadenado al primero.
        $this->travel(5)->days();
        $this->h = $this->actingAsAdmin($this->recepcion);
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'gym'], $this->h);
        $segundo = $this->cobrar($u, $plan);

        $this->anular($primero);

        $segundo->refresh();
        // Correrlo hasta el inicio del primero le regalaría cinco días que
        // nadie pagó: arranca el día en que entró el dinero.
        $this->assertSame(MembershipPeriod::today()->toDateString(), $segundo->period_start->toDateString());
        $this->assertSame(MembershipPeriod::today()->addDays(30)->toDateString(), $u->refresh()->membership_end_date);
    }

    public function test_un_pago_sin_plan_no_toca_la_membresia_al_anularse(): void
    {
        $fin = $this->hoy()->addDays(20)->toDateString();
        $u = $this->socio(['membership_start_date' => $this->hoy()->subDays(10)->toDateString(), 'membership_end_date' => $fin]);

        $this->postJson('/api/payments', [
            'user_id' => $u->id,
            'amount' => 25000,
            'method' => 'cash',
            'status' => 'paid',
        ], $this->h)->assertStatus(201);

        $this->anular(Payment::where('user_id', $u->id)->latest('id')->firstOrFail());

        $this->assertSame($fin, $u->refresh()->membership_end_date);
    }

    public function test_no_mueve_un_vencimiento_que_la_cadena_de_pagos_no_explica(): void
    {
        $u = $this->socio();
        $pago = $this->cobrar($u, $this->plan());

        // Ajuste manual posterior (o importación del sistema anterior): la
        // fecha vigente ya no es la que dejó este cobro.
        $u->forceFill(['membership_end_date' => $this->hoy()->addDays(120)->toDateString()])->save();

        $this->anular($pago);

        $this->assertSame($this->hoy()->addDays(120)->toDateString(), $u->refresh()->membership_end_date);
        $this->assertSame('cancelled', $pago->refresh()->status, 'El cobro se anula igual: la devolución es best-effort.');
    }

    // ── Rastro ──────────────────────────────────────────────────────────────

    public function test_la_devolucion_de_dias_queda_auditada(): void
    {
        $u = $this->socio();
        $pago = $this->cobrar($u, $this->plan());

        $this->anular($pago);

        $traza = AuditLog::where('entity', 'membership')->where('entity_id', (string) $u->id)->first();
        $this->assertNotNull($traza);
        $this->assertSame('Laura Mostrador', $traza->actor_name);
        $this->assertSame($this->hoy()->addDays(30)->toDateString(), $traza->metadata['membership_end_before']);
        $this->assertSame($this->hoy()->toDateString(), $traza->metadata['membership_end_after']);
    }

    public function test_un_reembolso_tambien_devuelve_los_dias(): void
    {
        $u = $this->socio();
        $pago = $this->cobrar($u, $this->plan());

        $this->anular($pago, 'refunded');

        $this->assertSame($this->hoy()->toDateString(), $u->refresh()->membership_end_date);
    }
}
