<?php

namespace Tests\Feature\Members;

use App\Models\Admin;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\MembershipFreeze;
use App\Services\Payments\MembershipPeriod;
use App\Support\Caja\PaymentOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CONGELAR UNA MEMBRESÍA sin poder tocar la app ni el terminal de recepción.
 *
 * La regla del negocio es una sola: los días que le quedan al socio se apartan y
 * se le devuelven al volver. Ni uno más ni uno menos.
 *
 * Y la restricción técnica manda en cómo se cuenta: los dos clientes que deciden
 * si alguien entra no se pueden modificar, así que la pausa tiene que verse en
 * lo que ya leen —cuenta inactiva y vigencia terminada—. Estos tests fijan las
 * dos cosas a la vez: que el socio no pueda entrar mientras está congelado, y
 * que no pierda un solo día por ello.
 */
class MembershipFreezeTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Laura Mostrador',
            'email' => 'freeze-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        $this->h = $this->actingAsAdmin($this->admin);
    }

    private function hoy(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    /** Un socio con membresía vigente hasta dentro de N días. */
    private function socio(int $diasRestantes = 20, array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Socio Congelable',
            'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
            'plan' => 'Mensual',
            'membership_start_date' => $this->hoy()->subDays(10)->toDateString(),
            'membership_end_date' => $this->hoy()->addDays($diasRestantes)->toDateString(),
        ], $attrs));
    }

    private function congelar(User $u, int $dias = 7, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/users/{$u->id}/freeze", array_merge([
            'days' => $dias,
        ], $extra), $this->h);
    }

    // ── Congelar ────────────────────────────────────────────────────────────

    public function test_congelar_guarda_los_dias_y_deja_al_socio_fuera(): void
    {
        $u = $this->socio(diasRestantes: 20);

        $res = $this->congelar($u, 7, ['reason' => 'Viaje de trabajo'])->assertStatus(201)->json();

        $u->refresh();

        // Lo que ven la app y el terminal: cuenta inactiva y vigencia terminada.
        // Con eso los dos bloquean el paso sin haber cambiado una línea.
        $this->assertSame('inactive', $u->status);
        $this->assertSame($this->hoy()->subDay()->toDateString(), $u->membership_end_date);

        // Y lo que sabe el CRM: es una pausa, no una baja.
        $this->assertTrue($res['freeze']['frozen']);
        $this->assertSame(20, $res['freeze']['days_left'], 'Le quedaban 20 días y se guardan los 20.');
        $this->assertSame($this->hoy()->addDays(7)->toDateString(), $res['freeze']['until']);
        $this->assertSame('Viaje de trabajo', $res['freeze']['reason']);
        $this->assertSame('Laura Mostrador', $res['freeze']['by']);
    }

    public function test_reanudar_devuelve_exactamente_los_dias_que_quedaban(): void
    {
        $u = $this->socio(diasRestantes: 18);
        $this->congelar($u, 7)->assertStatus(201);

        // Vuelve cinco días después.
        $this->travel(5)->days();
        $res = $this->postJson("/api/users/{$u->id}/resume", [], $this->actingAsAdmin($this->admin))
            ->assertOk()->json();

        $u->refresh();
        $this->assertSame(18, $res['days_returned']);
        $this->assertSame('active', $u->status);
        $this->assertSame(
            MembershipPeriod::today()->addDays(18)->toDateString(),
            $u->membership_end_date,
            'Los 18 días vuelven a contar desde el día en que reanuda, no desde antes.',
        );
        $this->assertNull($u->membership_frozen_at);
    }

    public function test_el_trabajo_diario_reanuda_sola_la_pausa_cumplida(): void
    {
        $u = $this->socio(diasRestantes: 12);
        $this->congelar($u, 7)->assertStatus(201);

        // Al sexto día todavía no le toca.
        $this->travel(6)->days();
        $this->artisan('memberships:resume-frozen')->assertExitCode(0);
        $this->assertNotNull($u->fresh()->membership_frozen_at, 'Aún está de pausa.');

        // Al séptimo, sí.
        $this->travel(1)->days();
        $this->artisan('memberships:resume-frozen')->assertExitCode(0);

        $u->refresh();
        $this->assertNull($u->membership_frozen_at);
        $this->assertSame('active', $u->status);
        $this->assertSame(MembershipPeriod::today()->addDays(12)->toDateString(), $u->membership_end_date);
    }

    public function test_reanudar_dos_veces_no_regala_dias(): void
    {
        $u = $this->socio(diasRestantes: 10);
        $this->congelar($u, 3)->assertStatus(201);

        $this->travel(3)->days();
        $this->artisan('memberships:resume-frozen');
        $vencimiento = $u->fresh()->membership_end_date;

        // Segunda corrida del mismo día: no hay nadie a quien devolverle nada.
        $this->artisan('memberships:resume-frozen');

        $this->assertSame($vencimiento, $u->fresh()->membership_end_date);
    }

    public function test_no_se_congela_lo_que_ya_esta_vencido_ni_lo_que_no_existe(): void
    {
        $vencido = $this->socio(diasRestantes: -3);
        $this->congelar($vencido)
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $sinMembresia = $this->socio();
        $sinMembresia->forceFill(['membership_end_date' => null])->save();
        $this->congelar($sinMembresia)->assertStatus(422);

        // Y no se tocó nada por el camino.
        $this->assertNull($vencido->fresh()->membership_frozen_at);
        $this->assertSame('active', $vencido->fresh()->status);
    }

    public function test_no_se_congela_dos_veces_ni_por_mas_de_medio_ano(): void
    {
        $u = $this->socio();
        $this->congelar($u, 7)->assertStatus(201);
        $this->congelar($u, 7)->assertStatus(422);

        $otro = $this->socio();
        $this->congelar($otro, MembershipFreeze::MAX_DAYS + 1)
            ->assertStatus(422)
            ->assertJsonValidationErrors('days');
    }

    public function test_al_reanudar_se_devuelve_el_estado_que_tenia_la_cuenta(): void
    {
        // Estaba inactivo por otro motivo antes de congelar: reanudar no puede
        // reactivarlo de rebote.
        $u = $this->socio(diasRestantes: 9, attrs: ['status' => 'inactive']);
        $this->congelar($u, 2)->assertStatus(201);

        $this->travel(2)->days();
        $this->artisan('memberships:resume-frozen');

        $this->assertSame('inactive', $u->fresh()->status);
    }

    // ── Cobrar durante la pausa ─────────────────────────────────────────────

    public function test_si_paga_durante_la_pausa_no_pierde_los_dias_guardados(): void
    {
        $u = $this->socio(diasRestantes: 15);
        $this->congelar($u, 30)->assertStatus(201);

        $plan = Plan::create(['name' => 'Mensual', 'price' => 70000, 'duration_days' => 30, 'active' => true]);

        // A los diez días renueva —desde la app ve la membresía vencida, así que
        // puede hacerlo—. El pago descongela y los 15 días guardados siguen ahí.
        $this->travel(10)->days();
        $hoy = MembershipPeriod::today();

        $pago = Payment::create([
            'user_id' => $u->id,
            'plan_id' => $plan->id,
            'amount' => 70000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => now(),
            'origin' => PaymentOrigin::GATEWAY->value,
        ]);
        app(MembershipPeriod::class)->apply($pago);

        $u->refresh();
        $this->assertNull($u->membership_frozen_at, 'Pagar descongela.');
        // 15 días guardados + 30 del plan nuevo, contados desde hoy.
        $this->assertSame($hoy->addDays(45)->toDateString(), $u->membership_end_date);
        $this->assertSame('active', $u->status);
    }

    // ── Quién puede ─────────────────────────────────────────────────────────

    public function test_congelar_exige_su_propio_permiso(): void
    {
        $u = $this->socio();

        // Recepción edita fichas y cobra, pero no mueve vigencias pagadas.
        $recepcion = Admin::create([
            'name' => 'Recepción',
            'email' => 'recep-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION,
            'status' => 'active',
        ]);

        $this->postJson("/api/users/{$u->id}/freeze", ['days' => 7], $this->actingAsAdmin($recepcion))
            ->assertForbidden();

        $this->assertNull($u->fresh()->membership_frozen_at);
    }

    public function test_queda_traza_de_quien_congelo_y_de_quien_reanudo(): void
    {
        $u = $this->socio(diasRestantes: 10);
        $this->congelar($u, 5, ['reason' => 'Lesión'])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'status',
            'module' => 'Miembros',
            'entity' => 'membresía',
            'entity_id' => (string) $u->id,
            'actor_name' => 'Laura Mostrador',
        ]);

        $this->postJson("/api/users/{$u->id}/resume", [], $this->h)->assertOk();

        $this->assertSame(
            2,
            \App\Models\AuditLog::where('entity_id', (string) $u->id)->where('entity', 'membresía')->count(),
            'Congelar y reanudar dejan su propia traza.',
        );
    }

    // ── Cómo se ve en el CRM ────────────────────────────────────────────────

    public function test_el_listado_de_miembros_sabe_filtrar_las_congeladas(): void
    {
        $congelado = $this->socio(diasRestantes: 10, attrs: ['name' => 'De viaje']);
        $this->congelar($congelado, 7)->assertStatus(201);
        $this->socio(diasRestantes: 10, attrs: ['name' => 'Al día']);

        $ids = collect($this->getJson('/api/users?status=frozen', $this->h)->assertOk()->json('data'))
            ->pluck('name');

        $this->assertTrue($ids->contains('De viaje'));
        $this->assertFalse($ids->contains('Al día'));
    }

    public function test_el_listado_trae_la_pausa_de_cada_socio(): void
    {
        $u = $this->socio(diasRestantes: 16);
        $this->congelar($u, 10)->assertStatus(201);

        $fila = collect($this->getJson('/api/users?search=Congelable', $this->h)->assertOk()->json('data'))
            ->firstWhere('id', $u->id);

        // El CRM necesita la pausa YA interpretada para pintar la insignia y
        // decidir si ofrece «congelar» o «reanudar».
        $this->assertTrue($fila['freeze']['frozen']);
        $this->assertSame($this->hoy()->addDays(10)->toDateString(), $fila['freeze']['until']);
        $this->assertSame(16, $fila['freeze']['days_left']);
        // Y no se publican las columnas crudas: una sola forma de leerlo.
        $this->assertArrayNotHasKey('membership_frozen_at', $fila);
    }

    public function test_la_ficha_del_socio_explica_la_pausa(): void
    {
        $u = $this->socio(diasRestantes: 21);
        $this->congelar($u, 14, ['reason' => 'Viaje'])->assertStatus(201);

        $ficha = $this->getJson("/api/users/{$u->id}", $this->h)->assertOk()->json();

        $this->assertTrue($ficha['freeze']['frozen']);
        $this->assertSame(14, $ficha['freeze']['days']);
        $this->assertSame(21, $ficha['freeze']['days_left']);
        // Hasta cuándo le valdrá si la pausa corre entera.
        $this->assertSame($this->hoy()->addDays(35)->toDateString(), $ficha['freeze']['resumes_to']);
        // Y hasta cuándo valía antes de pausarla, para poder explicarlo.
        $this->assertSame($this->hoy()->addDays(21)->toDateString(), $ficha['freeze']['was_valid_until']);
    }
}
