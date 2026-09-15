<?php

namespace Tests\Feature\Payments;

use App\Models\Admin;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\MembershipPeriod;
use App\Services\Payments\PaymentMembershipActivator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Paga hoy, empieza el lunes», y de dónde vino cada peso.
 *
 * Antes la fecha del cobro era también la fecha de inicio: para programar el
 * lunes había que fechar el cobro el lunes, y el ingreso salía en los reportes
 * ese día aunque el dinero estuviera hoy en la caja. Estos tests fijan que son
 * dos datos, que el periodo queda congelado en el pago, y que cada cobro sabe
 * si entró por la app o por el gimnasio y quién lo registró.
 */
class MembershipStartAndChannelTest extends TestCase
{
    use RefreshDatabase;

    private Admin $recepcion;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recepcion = Admin::create([
            'name' => 'Laura Mostrador',
            'email' => 'inicio-'.uniqid().'@ironbody.test',
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

    private function plan(): Plan
    {
        return Plan::create(['name' => 'Mensual', 'price' => 70000, 'duration_days' => 30, 'active' => true]);
    }

    private function socio(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Socio '.uniqid(),
            'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
        ], $attrs));
    }

    private function cobrar(User $u, Plan $p, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/payments', array_merge([
            'user_id' => $u->id,
            'plan_id' => $p->id,
            'amount' => 70000,
            'method' => 'cash',
            'status' => 'paid',
        ], $extra), $this->h);
    }

    // ── Inicio programado ───────────────────────────────────────────────────

    public function test_paga_hoy_y_empieza_la_semana_que_viene(): void
    {
        $u = $this->socio();
        $lunes = $this->hoy()->addDays(7)->toDateString();

        $this->cobrar($u, $this->plan(), ['starts_on' => $lunes])->assertStatus(201);

        $pago = Payment::firstWhere('user_id', $u->id);
        // El cobro es de HOY y está en la caja abierta…
        $this->assertSame($this->hoy()->toDateString(), $pago->paid_at->setTimezone(MembershipPeriod::TZ)->toDateString());
        $this->assertNotNull($pago->cash_shift_id);
        // …y la membresía empieza el lunes.
        $this->assertSame($lunes, $pago->period_start->toDateString());
        $this->assertSame($this->hoy()->addDays(37)->toDateString(), $pago->period_end->toDateString());

        $u->refresh();
        $this->assertSame($lunes, $u->membership_start_date);
        $this->assertSame($this->hoy()->addDays(37)->toDateString(), $u->membership_end_date);
    }

    public function test_en_el_mostrador_la_fecha_del_cobro_no_se_elige(): void
    {
        $u = $this->socio();

        // Un CRM viejo en caché que todavía fecha el cobro a futuro.
        $this->cobrar($u, $this->plan(), ['paid_at' => $this->hoy()->addDays(9)->toDateString()])->assertStatus(201);

        $pago = Payment::firstWhere('user_id', $u->id);
        $this->assertSame($this->hoy()->toDateString(), $pago->paid_at->setTimezone(MembershipPeriod::TZ)->toDateString());
        $this->assertSame($this->hoy()->toDateString(), $pago->period_start->toDateString());
    }

    public function test_no_acepta_inicio_en_el_pasado_ni_demasiado_lejos(): void
    {
        $u = $this->socio();
        $plan = $this->plan();

        $this->cobrar($u, $plan, ['starts_on' => $this->hoy()->subDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('starts_on');

        $this->cobrar($u, $plan, ['starts_on' => $this->hoy()->addDays(MembershipPeriod::MAX_START_AHEAD_DAYS + 1)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('starts_on');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_renovar_antes_de_vencer_encadena_y_no_pierde_dias(): void
    {
        $inicio = $this->hoy()->subDays(20)->toDateString();
        $fin = $this->hoy()->addDays(10)->toDateString();
        $u = $this->socio(['membership_start_date' => $inicio, 'membership_end_date' => $fin, 'plan' => 'Mensual']);

        $this->cobrar($u, $this->plan(), ['starts_on' => $this->hoy()->toDateString()])->assertStatus(201);

        $pago = Payment::firstWhere('user_id', $u->id);
        $this->assertSame($fin, $pago->period_start->toDateString());
        $this->assertSame($this->hoy()->addDays(40)->toDateString(), $pago->period_end->toDateString());

        $u->refresh();
        $this->assertSame($inicio, $u->membership_start_date, 'El inicio del periodo en curso no se mueve.');
        $this->assertSame($this->hoy()->addDays(40)->toDateString(), $u->membership_end_date);
    }

    public function test_pedir_empezar_despues_de_vencer_deja_el_hueco_pedido(): void
    {
        $u = $this->socio([
            'membership_start_date' => $this->hoy()->subDays(27)->toDateString(),
            'membership_end_date' => $this->hoy()->addDays(3)->toDateString(),
        ]);
        $dia = $this->hoy()->addDays(10)->toDateString();

        $this->cobrar($u, $this->plan(), ['starts_on' => $dia])->assertStatus(201);

        $u->refresh();
        $this->assertSame($dia, $u->membership_start_date);
        $this->assertSame($this->hoy()->addDays(40)->toDateString(), $u->membership_end_date);
    }

    public function test_un_pendiente_confirmado_tarde_no_empieza_antes_de_pagarse(): void
    {
        $u = $this->socio();
        $plan = $this->plan();
        $this->cobrar($u, $plan, ['status' => 'pending', 'starts_on' => $this->hoy()->toDateString()])->assertStatus(201);
        $pago = Payment::firstWhere('user_id', $u->id);

        // Se confirma «dentro de cinco días».
        $this->travel(5)->days();
        // La sesión de hace cinco días ya caducó: se entra de nuevo.
        $this->putJson("/api/payments/{$pago->id}", ['status' => 'paid'], $this->actingAsAdmin($this->recepcion))->assertOk();

        $pago->refresh();
        $this->assertSame(MembershipPeriod::today()->toDateString(), $pago->period_start->toDateString());
    }

    public function test_el_filtro_programadas_encuentra_a_quien_aun_no_empieza(): void
    {
        $programado = $this->socio(['name' => 'Programado']);
        $normal = $this->socio(['name' => 'Normal']);
        $plan = $this->plan();

        $this->cobrar($programado, $plan, ['starts_on' => $this->hoy()->addDays(4)->toDateString()])->assertStatus(201);
        $this->cobrar($normal, $plan)->assertStatus(201);

        $ids = collect($this->getJson('/api/users?status=scheduled', $this->h)->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($programado->id));
        $this->assertFalse($ids->contains($normal->id));
    }

    // ── Quién y por dónde ───────────────────────────────────────────────────

    public function test_el_cobro_del_mostrador_guarda_canal_y_quien_lo_registro(): void
    {
        $u = $this->socio();
        $this->cobrar($u, $this->plan())->assertStatus(201)
            ->assertJsonPath('origin', 'counter')
            ->assertJsonPath('registered_by_name', 'Laura Mostrador')
            ->assertJsonPath('registered_by_role', Admin::ROLE_SUPER_ADMIN);
    }

    public function test_el_pago_por_la_app_queda_como_app_y_sin_cajero(): void
    {
        $u = $this->socio();
        $plan = $this->plan();
        $tx = PaymentTransaction::create([
            'reference' => 'IRON-'.uniqid(),
            'idempotency_key' => 'IDEM-'.uniqid(),
            'provider' => 'wompi',
            'status' => PaymentTransaction::STATUS_APPROVED,
            'amount' => 70000,
            'currency' => 'COP',
            'user_id' => $u->id,
            'plan_id' => $plan->id,
            'paid_at' => now(),
        ]);

        app(PaymentMembershipActivator::class)->activate($tx, 'wompi');

        $pago = Payment::firstWhere('reference', $tx->reference);
        $this->assertSame('gateway', $pago->origin);
        $this->assertSame('app', $pago->channel());
        $this->assertNull($pago->registered_by_name);
        $this->assertNull($pago->cash_shift_id);
        $this->assertSame($this->hoy()->toDateString(), $pago->period_start->toDateString());
    }

    public function test_el_plan_a_plazos_acepta_inicio_y_guarda_quien_lo_vendio(): void
    {
        $u = $this->socio();
        Member::create([
            'user_id' => $u->id, 'full_name' => $u->name,
            'document_number' => (string) random_int(10000000, 99999999), 'is_staff' => false,
        ]);
        $plan = $this->plan();
        $dia = $this->hoy()->addDays(3)->toDateString();

        $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $u->id, 'plan_id' => $plan->id, 'first_payment' => 30000,
            'method' => 'cash', 'starts_on' => $dia,
        ], $this->h)->assertStatus(201);

        $pago = Payment::firstWhere('user_id', $u->id);
        $this->assertSame('counter', $pago->origin);
        $this->assertSame('Laura Mostrador', $pago->registered_by_name);
        $this->assertSame($dia, $pago->period_start->toDateString());
        $this->assertSame($dia, $u->fresh()->membership_start_date);
    }

    public function test_el_perfil_cuenta_la_historia_y_dice_que_aun_no_puede_entrar(): void
    {
        $u = $this->socio();
        $lunes = $this->hoy()->addDays(7)->toDateString();
        $this->cobrar($u, $this->plan(), ['starts_on' => $lunes])->assertStatus(201);

        $res = $this->getJson("/api/admin/users/{$u->id}/payment-history", $this->h)->assertOk();

        $res->assertJsonPath('membership.state', 'scheduled')
            ->assertJsonPath('membership.can_enter', false)
            ->assertJsonPath('membership.days_until_start', 7)
            ->assertJsonPath('summary.via_gym', 1)
            ->assertJsonPath('summary.total_paid', 70000)
            ->assertJsonPath('payments.0.channel', 'gym')
            ->assertJsonPath('payments.0.period_start', $lunes)
            ->assertJsonPath('payments.0.registered_by.name', 'Laura Mostrador');
        $this->assertNotNull($res->json('payments.0.cash_shift.id'));
    }

    public function test_la_migracion_clasifica_los_pagos_que_ya_existian(): void
    {
        $u = $this->socio();
        $mk = fn (array $a) => \Illuminate\Support\Facades\DB::table('payments')->insertGetId(array_merge([
            'user_id' => $u->id, 'amount' => 1000, 'status' => 'paid',
            'created_at' => now(), 'updated_at' => now(),
        ], $a));

        $migrado = $mk(['reference' => 'MIGR-77', 'method' => 'manual']);
        $app = $mk(['reference' => 'IRON-APP-1', 'method' => 'nequi']);
        $mostrador = $mk(['reference' => 'REC-1', 'method' => 'nequi']);
        PaymentTransaction::create([
            'reference' => 'IRON-APP-1', 'idempotency_key' => 'IDEM-'.uniqid(), 'provider' => 'nequi',
            'status' => PaymentTransaction::STATUS_APPROVED, 'amount' => 1000, 'currency' => 'COP',
        ]);
        \App\Models\AuditLog::create([
            'action' => 'create', 'module' => 'Pagos', 'entity' => 'pago', 'entity_id' => (string) $mostrador,
            'actor_id' => (string) $this->recepcion->id, 'actor_name' => 'Laura Mostrador',
            'actor_role' => 'Recepción', 'summary' => 'Registró un cobro', 'created_at' => now(),
        ]);

        $migracion = require database_path('migrations/2026_09_14_000002_add_membership_period_and_channel_to_payments_table.php');
        foreach (['backfillOrigin', 'backfillRegisteredBy'] as $paso) {
            (new \ReflectionMethod($migracion, $paso))->invoke($migracion);
        }

        $this->assertSame('legacy', Payment::find($migrado)->origin);
        // «Nequi» con transacción detrás es la app; sin ella, la transferencia del mostrador.
        $this->assertSame('gateway', Payment::find($app)->origin);
        $this->assertSame('counter', Payment::find($mostrador)->origin);
        $this->assertSame('Laura Mostrador', Payment::find($mostrador)->registered_by_name);
        $this->assertSame('Recepción', Payment::find($mostrador)->registered_by_role);
        $this->assertNull(Payment::find($app)->registered_by_name);
    }

    public function test_analitica_separa_app_de_gimnasio_y_suma_por_persona(): void
    {
        $plan = $this->plan();
        $this->cobrar($this->socio(), $plan)->assertStatus(201);
        $this->cobrar($this->socio(), $plan, ['method' => 'card'])->assertStatus(201);

        $app = $this->socio();
        app(PaymentMembershipActivator::class)->activate(PaymentTransaction::create([
            'reference' => 'IRON-'.uniqid(), 'idempotency_key' => 'IDEM-'.uniqid(), 'provider' => 'wompi',
            'status' => PaymentTransaction::STATUS_APPROVED, 'amount' => 70000, 'currency' => 'COP',
            'user_id' => $app->id, 'plan_id' => $plan->id, 'paid_at' => now(),
        ]), 'wompi');

        $res = $this->getJson('/api/admin/reports/payment-channels', $this->h)->assertOk();

        $canales = collect($res->json('channels'))->keyBy('key');
        $this->assertEquals(140000, $canales['gym']['amount']);
        $this->assertSame(2, $canales['gym']['count']);
        $this->assertEquals(70000, $canales['app']['amount']);

        $this->assertSame('Laura Mostrador', $res->json('by_staff.0.name'));
        $this->assertEquals(140000, $res->json('by_staff.0.amount'));
        $this->assertSame(Admin::ROLE_SUPER_ADMIN, $res->json('by_role.0.role'));

        $soloApp = $this->getJson('/api/admin/reports/payment-channels?channel=app', $this->h)->assertOk();
        $this->assertSame(1, $soloApp->json('rows.total'));
        $this->assertSame($app->id, $soloApp->json('rows.data.0.member_id'));
    }
}
