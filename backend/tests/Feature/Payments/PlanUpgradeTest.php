<?php

namespace Tests\Feature\Payments;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Caja\CashShiftReport;
use App\Services\Caja\CashShiftService;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MEJORAR DE PLAN A MITAD DE PERIODO.
 *
 * Paga el Mensual el día 1 y el día 8 quiere el Total Access. Pasa todas las
 * semanas y no había forma de registrarlo sin ensuciar la caja: o se le
 * cobraba el plan nuevo entero —y el sistema le ENCADENABA treinta días al
 * final de los que ya tenía, regalándole un mes— o se anulaba el cobro
 * anterior y se rehacía, moviendo dinero de un turno que podía estar cerrado.
 *
 * Las dos reglas que se fijan aquí, elegidas por lo mismo —que se puedan
 * explicar de pie en el mostrador—:
 *
 *   · se cobra la DIFERENCIA de precio, completa y sin prorratear;
 *   · el VENCIMIENTO NO SE MUEVE.
 *
 * Y una tercera: solo hacia arriba. Bajar de plan obligaría a devolver dinero,
 * que es otro producto con su autorización y su efecto en el arqueo.
 */
class PlanUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Dueño Iron Body',
            'email' => 'mejora-'.uniqid().'@ironbody.test',
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

    /** @return array{0: Plan, 1: Plan} [mensual, total access] */
    private function planes(): array
    {
        return [
            Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]),
            Plan::create(['name' => 'Total Access', 'price' => 150000, 'duration_days' => 30, 'active' => true]),
        ];
    }

    /** Pagó el Mensual hace una semana: le quedan 23 días. */
    private function socioConMensual(Plan $mensual, int $haceDias = 7): User
    {
        $inicio = $this->hoy()->subDays($haceDias);

        return User::create([
            'name' => 'Socio Mensual',
            'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
            'plan' => $mensual->name,
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $inicio->addDays(30)->toDateString(),
        ]);
    }

    private function abrirCaja(): void
    {
        app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);
    }

    // ── El cálculo ──────────────────────────────────────────────────────────

    public function test_cobra_la_diferencia_de_precio_completa(): void
    {
        [$mensual, $total] = $this->planes();
        $user = $this->socioConMensual($mensual);

        $this->getJson("/api/users/{$user->id}/plan-upgrade?plan_id={$total->id}", $this->h)
            ->assertOk()
            ->assertJsonPath('can_upgrade', true)
            ->assertJsonPath('amount', 70000)
            ->assertJsonPath('from.name', 'Mensual')
            ->assertJsonPath('to.name', 'Total Access');
    }

    public function test_el_vencimiento_no_se_mueve(): void
    {
        // Es la mitad de la explicación: se mejora lo que le queda del periodo
        // que ya compró, no se le vende uno nuevo.
        [$mensual, $total] = $this->planes();
        $user = $this->socioConMensual($mensual);
        $venceAntes = $user->membership_end_date;
        $this->abrirCaja();

        $this->postJson("/api/users/{$user->id}/plan-upgrade", [
            'plan_id' => $total->id, 'method' => 'cash',
        ], $this->h)->assertStatus(201);

        $user->refresh();
        $this->assertSame('Total Access', $user->plan);
        $this->assertSame(
            substr((string) $venceAntes, 0, 10),
            substr((string) $user->membership_end_date, 0, 10),
            'el vencimiento tiene que quedarse donde estaba',
        );
    }

    public function test_el_importe_lo_decide_el_servidor_y_no_el_navegador(): void
    {
        [$mensual, $total] = $this->planes();
        $user = $this->socioConMensual($mensual);
        $this->abrirCaja();

        // Se manda un importe ridículo: ni se mira.
        $this->postJson("/api/users/{$user->id}/plan-upgrade", [
            'plan_id' => $total->id, 'method' => 'cash', 'amount' => 1000,
        ], $this->h)->assertStatus(201);

        $this->assertSame(70000.0, (float) Payment::firstOrFail()->amount);
    }

    // ── La caja ─────────────────────────────────────────────────────────────

    public function test_solo_la_diferencia_entra_en_la_caja_y_sale_en_el_informe(): void
    {
        [$mensual, $total] = $this->planes();
        $user = $this->socioConMensual($mensual);
        $turno = app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);

        $this->postJson("/api/users/{$user->id}/plan-upgrade", [
            'plan_id' => $total->id, 'method' => 'cash',
        ], $this->h)->assertStatus(201);

        $informe = app(CashShiftReport::class)->for($turno->refresh());
        $linea = collect($informe['transactions'])->firstWhere('member', 'Socio Mensual');

        $this->assertNotNull($linea);
        $this->assertSame(70000.0, (float) $linea['total']);
        // Y DICE QUE ES UNA MEJORA: sin eso, en el informe aparece un Total
        // Access cobrado a 70.000 y parece un error de precio.
        $this->assertStringContainsString('mejora desde Mensual', (string) $linea['plan']);
        $this->assertSame(70000.0, (float) $informe['consistency']['detail_total']);
    }

    public function test_sin_caja_abierta_no_se_toca_nada(): void
    {
        [$mensual, $total] = $this->planes();
        $user = $this->socioConMensual($mensual);

        $this->postJson("/api/users/{$user->id}/plan-upgrade", [
            'plan_id' => $total->id, 'method' => 'cash',
        ], $this->h)->assertStatus(422);

        // Ni cobro, ni cambio de plan a medias.
        $this->assertSame(0, Payment::count());
        $this->assertSame('Mensual', $user->refresh()->plan);
    }

    // ── Lo que no se puede ──────────────────────────────────────────────────

    public function test_no_se_puede_bajar_de_plan(): void
    {
        [$mensual, $total] = $this->planes();
        $inicio = $this->hoy()->subDays(7);
        $user = User::create([
            'name' => 'Socio Total', 'email' => 'tot-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active',
            'plan' => $total->name,
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $inicio->addDays(30)->toDateString(),
        ]);

        $res = $this->getJson("/api/users/{$user->id}/plan-upgrade?plan_id={$mensual->id}", $this->h)
            ->assertOk();

        $this->assertFalse($res->json('can_upgrade'));
        $this->assertStringContainsString('más caro', (string) $res->json('reason'));
    }

    public function test_una_membresia_vencida_es_una_renovacion_no_una_mejora(): void
    {
        [$mensual, $total] = $this->planes();
        $user = $this->socioConMensual($mensual, haceDias: 60);

        $res = $this->getJson("/api/users/{$user->id}/plan-upgrade?plan_id={$total->id}", $this->h);

        $this->assertFalse($res->json('can_upgrade'));
        $this->assertStringContainsString('venció', (string) $res->json('reason'));
    }

    public function test_sin_plan_vigente_no_hay_nada_que_mejorar(): void
    {
        [, $total] = $this->planes();
        $user = User::create([
            'name' => 'Sin Plan', 'email' => 'sin-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        $this->assertFalse(
            $this->getJson("/api/users/{$user->id}/plan-upgrade?plan_id={$total->id}", $this->h)
                ->json('can_upgrade'),
        );
    }

    public function test_el_mismo_plan_no_se_mejora(): void
    {
        [$mensual] = $this->planes();
        $user = $this->socioConMensual($mensual);

        $res = $this->getJson("/api/users/{$user->id}/plan-upgrade?plan_id={$mensual->id}", $this->h);

        $this->assertFalse($res->json('can_upgrade'));
        $this->assertStringContainsString('Ya tiene', (string) $res->json('reason'));
    }

    public function test_recepcion_puede_mejorar_porque_es_vender(): void
    {
        [$mensual, $total] = $this->planes();
        $user = $this->socioConMensual($mensual);
        $this->abrirCaja();

        $recepcion = $this->actingAsAdmin(Admin::create([
            'name' => 'Laura Mostrador',
            'email' => 'rec-mejora-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION,
            'status' => 'active',
        ]));

        $this->postJson("/api/users/{$user->id}/plan-upgrade", [
            'plan_id' => $total->id, 'method' => 'cash',
        ], $recepcion)->assertStatus(201);
    }
}
