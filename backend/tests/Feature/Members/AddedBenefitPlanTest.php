<?php

namespace Tests\Feature\Members;

use App\Models\Admin;
use App\Models\Attendance;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\MembershipAccess;
use App\Services\Membership\MembershipFreeze;
use App\Services\Membership\PlanAccessRules;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EL BENEFICIO AÑADIDO: un plan que se compra ENCIMA de otro.
 *
 * El plan día y las clases sueltas no son membresías: son un derecho puntual
 * para una actividad, y casi siempre se compran teniendo ya otra cosa vigente.
 * El sistema no sabía distinguirlos de una renovación y los ENCADENABA: una
 * socia con una anualidad vigente pagaba un plan día para hoy y el sistema se
 * lo apuntaba doce meses más adelante, le movía el vencimiento del año y le
 * cambiaba las reglas de acceso a las del plan día —una entrada—, dejándola
 * bloqueada en el acto.
 *
 * Ahora el catálogo lo marca (`plans.benefit_only`) y entonces:
 *   · vale DESDE EL DÍA QUE SE COMPRÓ y por su propia duración;
 *   · no encadena, no mueve el vencimiento y no cambia la etiqueta;
 *   · abre la puerta POR SU CUENTA mientras dure, aunque la membresía diga que
 *     no (no es su día, sin entradas, vencida, congelada);
 *   · al terminar solo se acaba él.
 */
class AddedBenefitPlanTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Dueño Iron Body',
            'email' => 'beneficio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    private function hoy(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    private function anualidad(array $extra = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Anualidad', 'price' => 1200000, 'duration_days' => 365, 'active' => true,
        ], $extra));
    }

    /** El plan día: un día de vigencia, UNA entrada, y marcado como añadido. */
    private function planDia(array $extra = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'PLAN DÍA', 'price' => 12000, 'duration_days' => 1,
            'benefit_only' => true,
            'access_mode' => PlanAccessRules::MODE_ENTRIES, 'entry_credits' => 1,
            'active' => true,
        ], $extra));
    }

    private function socia(): User
    {
        return User::create([
            'name' => 'Camila Prueba',
            'email' => 'camila-'.uniqid().'@example.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
    }

    /** Cobra un plan y lo aplica como lo hace el mostrador. */
    private function cobrar(User $user, Plan $plan, ?CarbonImmutable $inicio = null): Payment
    {
        $inicio ??= $this->hoy();

        $pago = Payment::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => $inicio->setTime(9, 0),
            'starts_on' => $inicio->toDateString(),
        ]);

        app(MembershipPeriod::class)->apply($pago);

        return $pago->refresh();
    }

    private function acceso(User $user, ?CarbonImmutable $at = null): array
    {
        return app(MembershipAccess::class)->state($user->refresh(), $at);
    }

    private function entradas(User $user, int $dias): void
    {
        for ($i = 0; $i < $dias; $i++) {
            Attendance::create([
                'user_id' => $user->id,
                'action' => 'entry',
                'source' => 'facial',
                'captured_at' => $this->hoy()->subDays(1 + $i)->setTime(9, 0)->setTimezone('UTC'),
            ]);
        }
    }

    // ── La compra ───────────────────────────────────────────────────────────

    public function test_el_beneficio_no_encadena_ni_mueve_el_vencimiento(): void
    {
        $socia = $this->socia();
        $anual = $this->anualidad();
        $dia = $this->planDia();

        $this->cobrar($socia, $anual, $this->hoy()->subDays(300));
        $socia->refresh();
        $finAntes = $socia->membership_end_date;

        $pago = $this->cobrar($socia, $dia);

        // Su derecho empieza HOY, no al final del año.
        $this->assertSame($this->hoy()->toDateString(), $pago->period_start->format('Y-m-d'));
        $this->assertSame($this->hoy()->addDays(1)->toDateString(), $pago->period_end->format('Y-m-d'));

        // Y la membresía queda intacta: mismo plan, mismo vencimiento.
        $socia->refresh();
        $this->assertSame('Anualidad', $socia->plan);
        $this->assertEquals($finAntes, $socia->membership_end_date);
    }

    public function test_la_ficha_sigue_enseniando_la_anualidad(): void
    {
        $socia = $this->socia();
        $anual = $this->anualidad();
        $this->cobrar($socia, $anual, $this->hoy()->subDays(300));
        $this->cobrar($socia, $this->planDia());

        $acceso = $this->acceso($socia);

        $this->assertSame($anual->id, $acceso['plan']['id']);
        $this->assertSame(PlanAccessRules::MODE_UNLIMITED, $acceso['rules']['mode']);
        // Y el beneficio se ve, aparte, sin desplazar a nada.
        $this->assertCount(1, $acceso['benefits']);
        $this->assertSame('PLAN DÍA', $acceso['benefits'][0]['plan']['name']);
    }

    public function test_con_la_anualidad_vigente_y_meses_entrando_sigue_entrando(): void
    {
        // El caso que la bloqueaba: las entradas del año contra el tope de UNA
        // del plan día.
        $socia = $this->socia();
        $this->cobrar($socia, $this->anualidad(), $this->hoy()->subDays(300));
        $this->entradas($socia, 40);
        $this->cobrar($socia, $this->planDia());

        $acceso = $this->acceso($socia);

        $this->assertTrue($acceso['can_enter'], $acceso['message'] ?? '');
        $this->assertNull($acceso['entries'], 'gobierna la anualidad, que es ilimitada');
    }

    public function test_sin_membresia_propia_el_plan_dia_si_es_su_membresia(): void
    {
        // Quien entra por primera vez y compra un plan día no puede quedarse sin
        // nada que la puerta sepa leer.
        $socia = $this->socia();
        $dia = $this->planDia();

        $this->cobrar($socia, $dia);

        $socia->refresh();
        $this->assertSame('PLAN DÍA', $socia->plan);
        $this->assertSame($this->hoy()->addDays(1)->toDateString(), (string) $socia->membership_end_date);

        $acceso = $this->acceso($socia);
        $this->assertTrue($acceso['can_enter']);
        $this->assertSame(1, $acceso['entries']['total']);
    }

    // ── La puerta ───────────────────────────────────────────────────────────

    public function test_el_beneficio_abre_el_dia_que_su_plan_no_permite(): void
    {
        // La anualidad es de horas valle y hoy no es su día; compra un plan día
        // para venir igual. Es exactamente para lo que existe.
        $hoy = $this->hoy();
        $otroDia = (int) $hoy->addDay()->isoWeekday();
        $anual = $this->anualidad(['access_days' => [$otroDia]]);
        $socia = $this->socia();

        $this->cobrar($socia, $anual, $hoy->subDays(10));
        $this->assertFalse($this->acceso($socia)['can_enter'], 'hoy no es su día');

        $this->cobrar($socia, $this->planDia());

        $acceso = $this->acceso($socia);
        $this->assertTrue($acceso['can_enter'], $acceso['message'] ?? '');
        $this->assertSame('PLAN DÍA', $acceso['benefit_opened']['name']);
    }

    public function test_el_beneficio_abre_aunque_la_membresia_este_vencida(): void
    {
        $socia = $this->socia();
        $this->cobrar($socia, $this->anualidad(), $this->hoy()->subDays(400));
        $this->assertFalse($this->acceso($socia)['can_enter'], 'la anualidad venció');

        $this->cobrar($socia, $this->planDia());

        $this->assertTrue($this->acceso($socia)['can_enter']);
    }

    public function test_el_beneficio_no_descongela_la_membresia(): void
    {
        // Congelar guarda días. Comprar un plan día no es pedir volver: si
        // descongelara, le gastaría esos días sin que nadie lo haya decidido.
        $socia = $this->socia();
        $this->cobrar($socia, $this->anualidad(), $this->hoy()->subDays(10));
        app(MembershipFreeze::class)->freeze($socia->refresh(), 15, 'Viaje', $this->admin);

        $this->cobrar($socia, $this->planDia());

        $this->assertTrue(app(MembershipFreeze::class)->isFrozen($socia->refresh()), 'sigue congelada');
        // Y aun así entra hoy: ese día lo pagó.
        $this->assertTrue($this->acceso($socia)['can_enter']);
    }

    public function test_una_sola_entrada_por_plan_dia(): void
    {
        // Su derecho es de UNA entrada en SU ventana: si ya entró hoy puede
        // volver a pasar, pero mañana el plan día ya no abre nada.
        $socia = $this->socia();
        $hoy = $this->hoy();
        $otroDia = (int) $hoy->addDay()->isoWeekday();
        $this->cobrar($socia, $this->anualidad(['access_days' => [$otroDia]]), $hoy->subDays(10));
        $this->cobrar($socia, $this->planDia());

        // Entra hoy.
        Attendance::create([
            'user_id' => $socia->id, 'action' => 'entry', 'source' => 'facial',
            'captured_at' => $hoy->setTime(8, 0)->setTimezone('UTC'),
        ]);

        // Vuelve a pasar el mismo día: el día ya está pagado.
        $this->assertTrue($this->acceso($socia, $hoy->setTime(18, 0))['can_enter']);
    }

    public function test_cuando_el_beneficio_vence_solo_se_acaba_el(): void
    {
        // Regla 3. La anualidad sigue entera y la socia sigue entrando.
        $socia = $this->socia();
        $anual = $this->anualidad();
        $this->cobrar($socia, $anual, $this->hoy()->subDays(100));
        $this->cobrar($socia, $this->planDia(), $this->hoy()->subDays(30));

        $acceso = $this->acceso($socia);

        $this->assertSame($anual->id, $acceso['plan']['id']);
        $this->assertTrue($acceso['can_enter']);
        $this->assertSame([], $acceso['benefits'], 'el beneficio ya no está vivo');
        $this->assertNull($acceso['benefit_opened']);
    }

    public function test_dos_beneficios_a_la_vez_conviven(): void
    {
        $socia = $this->socia();
        $clase = $this->planDia(['name' => 'Clase de yoga', 'entry_credits' => 1]);
        $this->cobrar($socia, $this->anualidad(), $this->hoy()->subDays(50));
        $this->cobrar($socia, $this->planDia());
        $this->cobrar($socia, $clase);

        $this->assertCount(2, $this->acceso($socia)['benefits']);
    }

    public function test_sin_nada_vigente_sigue_bloqueada(): void
    {
        // Regla 4: el beneficio vencido no abre, y la anualidad tampoco.
        $socia = $this->socia();
        $this->cobrar($socia, $this->anualidad(), $this->hoy()->subDays(400));
        $this->cobrar($socia, $this->planDia(), $this->hoy()->subDays(10));

        $acceso = $this->acceso($socia);

        $this->assertFalse($acceso['can_enter']);
        $this->assertNotNull($acceso['reason']);
    }

    // ── Lo que no cambia ────────────────────────────────────────────────────

    public function test_la_casilla_se_guarda_y_se_devuelve_desde_el_crm(): void
    {
        // De punta a punta: el catálogo es quien lo marca, así que la casilla
        // tiene que poder ponerse y leerse por la API del CRM.
        $h = $this->actingAsAdmin($this->admin);

        $creado = $this->postJson('/api/plans', [
            'name' => 'Plan día', 'price' => 12000, 'duration_days' => 1,
            'benefit_only' => true, 'benefits' => 'Una entrada para una clase',
            'active' => true,
        ], $h)->assertCreated()->json();

        $id = $creado['id'] ?? $creado['data']['id'] ?? null;
        $this->assertNotNull($id);
        $this->assertTrue((bool) Plan::findOrFail($id)->benefit_only);

        // Y se puede quitar: un plan mal marcado se corrige sin tocar la base.
        $this->patchJson("/api/plans/{$id}", ['benefit_only' => false], $h)->assertOk();
        $this->assertFalse((bool) Plan::findOrFail($id)->benefit_only);
    }

    public function test_un_plan_normal_sigue_encadenando_como_siempre(): void
    {
        // Renovar anticipadamente NO es un beneficio añadido: el periodo nuevo
        // va detrás del que todavía corre y no se pierde ni un día pagado.
        $socia = $this->socia();
        $mensual = Plan::create([
            'name' => 'Mensual', 'price' => 90000, 'duration_days' => 30, 'active' => true,
        ]);

        $primero = $this->cobrar($socia, $mensual, $this->hoy()->subDays(10));
        $segundo = $this->cobrar($socia, $mensual);

        $this->assertSame(
            $primero->period_end->format('Y-m-d'),
            $segundo->period_start->format('Y-m-d'),
            'el segundo arranca donde acaba el primero',
        );
        $this->assertSame('Mensual', $socia->refresh()->plan);
    }

    public function test_un_beneficio_no_le_toca_la_ventana_de_entradas_al_plan(): void
    {
        // Lo que se rompía solo: desde que el plan día no encadena, su periodo
        // cubre hoy y es el más reciente. Si contara como la ventana del plan, un
        // mensual de 15 entradas reiniciaría su recuento y daría entradas
        // infinitas.
        $socia = $this->socia();
        $mensual = Plan::create([
            'name' => 'Valera', 'price' => 65000, 'duration_days' => 30,
            'access_mode' => PlanAccessRules::MODE_ENTRIES, 'entry_credits' => 15, 'active' => true,
        ]);

        $this->cobrar($socia, $mensual, $this->hoy()->subDays(5));
        $this->entradas($socia, 4);
        $this->cobrar($socia, $this->planDia());

        $acceso = $this->acceso($socia);

        $this->assertSame(15, $acceso['entries']['total']);
        $this->assertSame(4, $acceso['entries']['used'], 'sus entradas del mes siguen contadas');
        $this->assertSame($this->hoy()->subDays(5)->toDateString(), $acceso['entries']['window']['from']);
    }
}
