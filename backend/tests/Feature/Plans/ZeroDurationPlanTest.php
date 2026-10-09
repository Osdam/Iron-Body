<?php

namespace Tests\Feature\Plans;

use App\Models\Admin;
use App\Models\Plan;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Enums\CashShiftType;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UN PLAN DE CERO DÍAS NO MUEVE LA MEMBRESÍA DE NADIE.
 *
 * El caso que lo pide: la «Comisión de personalizados» estaba cargada como un
 * plan de treinta días, y un plan define la vigencia de quien lo tiene. Por
 * eso hubo socios con su anualidad pagada a los que el perfil les decía que
 * la membresía empieza en marzo de 2027, y la puerta no les dejaba entrar.
 *
 * MembershipPeriod ya se detenía en `dias <= 0`; lo que faltaba era poder
 * ponerlo desde el CRM en vez de entrar a la base a mano. Estos tests fijan
 * las dos mitades: que el catálogo lo acepta y que el cobro no toca la
 * vigencia.
 */
class ZeroDurationPlanTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Dueño',
            'email' => 'cero-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        $this->h = $this->actingAsAdmin($this->admin);
    }

    public function test_el_catalogo_acepta_un_plan_de_cero_dias(): void
    {
        $this->postJson('/api/plans', [
            'name' => 'Comisión de personalizados',
            'price' => 50000,
            'duration_days' => 0,
            'active' => true,
        ], $this->h)->assertSuccessful();

        $this->assertSame(0, (int) Plan::firstWhere('name', 'Comisión de personalizados')->duration_days);
    }

    public function test_cobrarlo_no_le_mueve_la_vigencia_al_socio(): void
    {
        $hoy = MembershipPeriod::today();
        $comision = Plan::create([
            'name' => 'Comisión de personalizados',
            'price' => 50000, 'duration_days' => 0, 'active' => true,
        ]);

        $user = User::create([
            'name' => 'Alejandra Palacios',
            'email' => 'ale-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
            'plan' => 'Anualidad',
            'membership_start_date' => $hoy->subDays(100)->toDateString(),
            'membership_end_date' => $hoy->addDays(265)->toDateString(),
        ]);

        app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);

        $this->postJson('/api/payments', [
            'user_id' => $user->id,
            'plan_id' => $comision->id,
            'amount' => 50000,
            'method' => 'cash',
            'status' => 'paid',
        ], $this->h)->assertStatus(201);

        $user->refresh();

        // Su anualidad sigue intacta: ni las fechas ni el plan se tocaron.
        $this->assertSame($hoy->subDays(100)->toDateString(), substr((string) $user->membership_start_date, 0, 10));
        $this->assertSame($hoy->addDays(265)->toDateString(), substr((string) $user->membership_end_date, 0, 10));
        $this->assertSame('Anualidad', $user->plan);
    }

    public function test_un_plan_normal_sigue_moviendola_como_siempre(): void
    {
        $hoy = MembershipPeriod::today();
        $mensual = Plan::create(['name' => 'Mensual', 'price' => 90000, 'duration_days' => 30, 'active' => true]);

        $user = User::create([
            'name' => 'Socio Normal',
            'email' => 'normal-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
        ]);

        app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);

        $this->postJson('/api/payments', [
            'user_id' => $user->id,
            'plan_id' => $mensual->id,
            'amount' => 90000,
            'method' => 'cash',
            'status' => 'paid',
        ], $this->h)->assertStatus(201);

        $this->assertSame($hoy->addDays(30)->toDateString(), substr((string) $user->refresh()->membership_end_date, 0, 10));
    }

    public function test_un_valor_negativo_sigue_sin_admitirse(): void
    {
        $this->postJson('/api/plans', [
            'name' => 'Imposible', 'price' => 1000, 'duration_days' => -5, 'active' => true,
        ], $this->h)->assertStatus(422);
    }
}
