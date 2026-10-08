<?php

namespace Tests\Feature\Members;

use App\Models\Admin;
use App\Models\EmployeeAccess;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\MembershipAccess;
use App\Services\Membership\PlanAccessRules;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EL EMPLEADO ENTRA PORQUE TRABAJA AQUÍ, NO PORQUE COMPRÓ UN PLAN.
 *
 * EL PROBLEMA QUE CIERRA. Para que un empleado pudiera entrenar había que
 * venderle una membresía. Eso es un cobro que nadie cobró —aparece en las
 * cifras como si alguien hubiera pagado— y, peor, no se acaba cuando se acaba
 * el trabajo: el día que renuncia sigue con su plan vigente hasta que alguien
 * se acuerda de ir a quitárselo.
 *
 * LO QUE SE FIJA AQUÍ
 *   · un empleado sin plan entra, y no hace falta inventarle una membresía;
 *   · sus horas son las mismas horas valle de los planes, con la misma clase,
 *     porque lo normal es que entrene fuera de su turno;
 *   · se apaga con un interruptor y deja de entrar ese mismo día;
 *   · convive con una membresía pagada: un entrenador puede ser empleado Y
 *     socio, y entonces entra por la puerta que esté abierta;
 *   · entrar como empleado NO gasta una entrada de un plan por consumo.
 */
class EmployeeAccessTest extends TestCase
{
    use RefreshDatabase;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->h = $this->actingAsAdmin(Admin::create([
            'name' => 'Dueño Iron Body',
            'email' => 'empleado-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]));
    }

    private function hoy(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    private function persona(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Laura Entrenadora',
            'email' => 'persona-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
        ], $attrs));
    }

    /** El acceso resuelto, a una hora concreta del día de hoy. */
    private function acceso(User $user, ?int $hora = null): array
    {
        $momento = $hora === null ? null : $this->hoy()->setTime($hora, 0);

        return app(MembershipAccess::class)->state($user->refresh(), $momento);
    }

    private function conceder(User $user, array $cuerpo = []): \Illuminate\Testing\TestResponse
    {
        return $this->putJson("/api/users/{$user->id}/employee-access", $cuerpo, $this->h);
    }

    // ── La puerta del empleado ──────────────────────────────────────────────

    public function test_un_empleado_sin_plan_entra(): void
    {
        $user = $this->persona();

        // Esto es lo que antes obligaba a venderle una mensualidad.
        $this->assertFalse($this->acceso($user)['can_enter']);

        $this->conceder($user, ['position' => 'Entrenador'])->assertStatus(201);

        $acceso = $this->acceso($user);
        $this->assertTrue($acceso['can_enter']);
        $this->assertSame(MembershipAccess::VIA_EMPLOYEE, $acceso['via']);
        $this->assertSame('Entrenador', $acceso['employee']['position']);
        // Y sigue sin membresía: no se le inventó ninguna por la puerta de atrás.
        $this->assertNull($user->refresh()->membership_end_date);
        $this->assertNull($acceso['plan']);
    }

    public function test_las_horas_del_empleado_son_las_mismas_horas_valle_de_un_plan(): void
    {
        // «Puede entrenar de 2 a 5», que es cuando no está en su turno.
        $user = $this->persona();
        $this->conceder($user, [
            'position' => 'Recepción',
            'access_windows' => [['from' => '14:00', 'to' => '17:00']],
        ])->assertStatus(201);

        $this->assertTrue($this->acceso($user, 15)['can_enter']);

        $fuera = $this->acceso($user, 9);
        $this->assertFalse($fuera['can_enter']);
        $this->assertSame(MembershipAccess::REASON_HOURS, $fuera['reason']);
        // El texto le habla de SU acceso, no de «su plan»: no tiene ninguno.
        $this->assertStringContainsString('acceso de empleado', $fuera['message']);
        $this->assertStringContainsString('14:00', $fuera['message']);
    }

    public function test_los_dias_del_empleado_tambien_limitan(): void
    {
        $user = $this->persona();
        $manana = $this->hoy()->addDay();

        // Solo el día de mañana: hoy tiene que quedar fuera.
        $this->conceder($user, ['access_days' => [$manana->isoWeekday()]])->assertStatus(201);

        $hoy = $this->acceso($user, 10);
        $this->assertFalse($hoy['can_enter']);
        $this->assertSame(MembershipAccess::REASON_WEEKDAY, $hoy['reason']);

        $this->assertTrue(
            app(MembershipAccess::class)->state($user->refresh(), $manana->setTime(10, 0))['can_enter'],
        );
    }

    public function test_renuncia_y_deja_de_entrar_hoy_mismo(): void
    {
        $user = $this->persona();
        $this->conceder($user, ['position' => 'Aseo'])->assertStatus(201);
        $this->assertTrue($this->acceso($user)['can_enter']);

        $this->deleteJson("/api/users/{$user->id}/employee-access", [], $this->h)->assertOk();

        $acceso = $this->acceso($user);
        $this->assertFalse($acceso['can_enter']);
        $this->assertStringContainsString('acceso de empleado', (string) $acceso['message']);

        // La fila NO se borra: queda quién lo cortó y cuándo. «¿Desde cuándo y
        // hasta cuándo entró gratis?» tiene que poder contestarse.
        $empleo = EmployeeAccess::where('user_id', $user->id)->firstOrFail();
        $this->assertFalse($empleo->active);
        $this->assertNotNull($empleo->revoked_at);
        $this->assertSame('Dueño Iron Body', $empleo->revoked_by_name);
        $this->assertSame('Aseo', $empleo->position);
    }

    public function test_un_contrato_con_fecha_de_fin_se_apaga_solo(): void
    {
        // El practicante de tres meses: nadie tiene que acordarse de nada.
        $user = $this->persona();
        $this->conceder($user, ['ends_on' => $this->hoy()->subDay()->toDateString()])->assertStatus(201);

        $acceso = $this->acceso($user);
        $this->assertFalse($acceso['can_enter']);
        $this->assertStringContainsString('terminó el', (string) $acceso['message']);
        // Sigue constando como empleado, con su permiso ya vencido.
        $this->assertFalse($acceso['employee']['current']);
        $this->assertTrue($acceso['employee']['active']);
    }

    public function test_un_permiso_que_aun_no_empieza_no_abre(): void
    {
        $user = $this->persona();
        $this->conceder($user, ['starts_on' => $this->hoy()->addDays(3)->toDateString()])->assertStatus(201);

        $this->assertFalse($this->acceso($user)['can_enter']);
    }

    // ── Las dos puertas a la vez ────────────────────────────────────────────

    public function test_el_entrenador_que_ademas_paga_su_membresia_entra_a_cualquier_hora(): void
    {
        // Es empleado con franja de 14 a 17, Y socio con un plan normal. A las
        // nueve de la mañana entra: lo que le abre es lo que pagó.
        $plan = Plan::create(['name' => 'Mensual', 'price' => 90000, 'duration_days' => 30, 'active' => true]);
        $user = $this->persona([
            'plan' => $plan->name,
            'membership_start_date' => $this->hoy()->subDays(5)->toDateString(),
            'membership_end_date' => $this->hoy()->addDays(25)->toDateString(),
        ]);
        $this->conceder($user, ['access_windows' => [['from' => '14:00', 'to' => '17:00']]])->assertStatus(201);

        $manana = $this->acceso($user, 9);
        $this->assertTrue($manana['can_enter']);
        $this->assertSame(MembershipAccess::VIA_MEMBERSHIP, $manana['via']);

        // Y en su franja de empleado entra por esa puerta.
        $this->assertSame(MembershipAccess::VIA_EMPLOYEE, $this->acceso($user, 15)['via']);
    }

    public function test_al_socio_vencido_que_ademas_es_empleado_se_le_dice_lo_de_la_membresia(): void
    {
        // Tiene las dos puertas y las dos cerradas. El motivo útil es el que
        // puede arreglar: su membresía venció. Lo otro ya lo sabe.
        $plan = Plan::create(['name' => 'Mensual', 'price' => 90000, 'duration_days' => 30, 'active' => true]);
        $user = $this->persona([
            'plan' => $plan->name,
            'membership_start_date' => $this->hoy()->subDays(40)->toDateString(),
            'membership_end_date' => $this->hoy()->subDays(10)->toDateString(),
        ]);
        $this->conceder($user, ['access_windows' => [['from' => '14:00', 'to' => '17:00']]])->assertStatus(201);

        $acceso = $this->acceso($user, 9);
        $this->assertFalse($acceso['can_enter']);
        $this->assertSame(MembershipAccess::REASON_EXPIRED, $acceso['reason']);
    }

    public function test_entrar_como_empleado_no_gasta_una_entrada_del_plan(): void
    {
        // Tiene un plan por consumo Y acceso de empleado. Si entra en su franja
        // de empleado, las 15 entradas que compró siguen enteras: esa entrada
        // no se la vendió nadie.
        $plan = Plan::create([
            'name' => 'Valera', 'price' => 65000, 'duration_days' => 30,
            'access_mode' => PlanAccessRules::MODE_ENTRIES, 'entry_credits' => 15, 'active' => true,
        ]);
        $inicio = $this->hoy()->subDays(5);
        $user = $this->persona([
            'plan' => $plan->name,
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $inicio->addDays(30)->toDateString(),
        ]);
        Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => $plan->price,
            'method' => 'cash', 'status' => 'paid', 'paid_at' => $inicio->setTime(9, 0),
            'period_start' => $inicio->toDateString(), 'period_end' => $inicio->addDays(30)->toDateString(),
        ]);
        $this->conceder($user, ['access_windows' => [['from' => '14:00', 'to' => '17:00']]])->assertStatus(201);

        $comoEmpleado = $this->acceso($user, 15);
        $this->assertSame(MembershipAccess::VIA_EMPLOYEE, $comoEmpleado['via']);
        $this->assertFalse(MembershipAccess::consumesEntry($comoEmpleado, true));

        // Y por la puerta de la membresía sí se gasta, como siempre.
        $comoSocio = $this->acceso($user, 9);
        $this->assertSame(MembershipAccess::VIA_MEMBERSHIP, $comoSocio['via']);
        $this->assertTrue(MembershipAccess::consumesEntry($comoSocio, true));
    }

    // ── Quién puede concederlo ──────────────────────────────────────────────

    public function test_recepcion_no_reparte_accesos_gratuitos(): void
    {
        $user = $this->persona();
        $this->h = $this->actingAsAdmin(Admin::create([
            'name' => 'Laura Mostrador',
            'email' => 'recepcion-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION,
            'status' => 'active',
        ]));

        $this->conceder($user, ['position' => 'Entrenador'])->assertStatus(403);
        $this->assertNull(EmployeeAccess::where('user_id', $user->id)->first());

        // Pero sí puede CONSULTARLO: lo necesita cuando alguien se planta en la
        // puerta y hay que explicarle por qué no abre.
        $this->getJson("/api/users/{$user->id}/employee-access", $this->h)->assertOk();
    }

    public function test_el_permiso_aparece_en_la_pantalla_de_roles(): void
    {
        $fila = collect(\App\Support\Access\PermissionCatalog::rows())
            ->firstWhere('key', 'members.employee');

        $this->assertNotNull($fila, 'un permiso que nadie puede conceder es un interruptor desconectado');
        $this->assertSame('members', $fila['domain']);
        $this->assertNotNull($fila['help']);
    }

    // ── El formulario ───────────────────────────────────────────────────────

    public function test_una_franja_sin_sentido_no_deja_a_nadie_encerrado_fuera(): void
    {
        // Lista vacía de días = sin restricción, no «ningún día permitido».
        $user = $this->persona();
        $this->conceder($user, ['access_days' => [], 'access_windows' => []])->assertStatus(201);

        $empleo = EmployeeAccess::where('user_id', $user->id)->firstOrFail();
        $this->assertNull($empleo->access_days);
        $this->assertNull($empleo->access_windows);
        $this->assertTrue($this->acceso($user, 3)['can_enter']);
    }

    public function test_editar_el_acceso_conserva_quien_lo_concedio(): void
    {
        $user = $this->persona();
        $this->conceder($user, ['position' => 'Entrenador'])->assertStatus(201);

        $otro = $this->actingAsAdmin(Admin::create([
            'name' => 'Otro Admin',
            'email' => 'otro-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]));
        $this->putJson("/api/users/{$user->id}/employee-access", ['position' => 'Jefe de piso'], $otro)
            ->assertOk();

        $empleo = EmployeeAccess::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Jefe de piso', $empleo->position);
        // La pregunta que importa es quién ABRIÓ esta puerta, y esa no cambia
        // porque alguien después corrija el cargo.
        $this->assertSame('Dueño Iron Body', $empleo->granted_by_name);
    }
}
