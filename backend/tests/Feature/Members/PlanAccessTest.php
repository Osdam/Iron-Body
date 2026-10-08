<?php

namespace Tests\Feature\Members;

use App\Models\Admin;
use App\Models\Attendance;
use App\Models\MembershipAdjustment;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\MembershipAccess;
use App\Services\Membership\MembershipAdjustments;
use App\Services\Membership\MembershipFreeze;
use App\Services\Membership\PlanAccessRules;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PLANES POR CONSUMO, DÍAS PERMITIDOS Y HORAS VALLE.
 *
 * El caso que lo origina es real y estaba mal vendido: el plan Valera cuesta
 * $65.000 y son 15 ENTRADAS dentro de un mes, no 15 días. Con la vigencia como
 * única regla, el socio perdía media mensualidad —o entraba treinta veces
 * pagando quince—.
 *
 * Lo que estos tests fijan:
 *   · las entradas se cuentan por DÍA, no por marcaje: entrar, salir a comprar
 *     agua y volver gasta una sola;
 *   · quien ya entró hoy puede volver a pasar aunque no le queden entradas;
 *   · las entradas pertenecen al PERIODO que pagó, y no se arrastran al
 *     siguiente ni se mezclan con el anterior;
 *   · un día o una hora prohibidos dejan fuera a un socio al día, y se le dice
 *     por qué;
 *   · un plan de los de antes sigue siendo ilimitado, sin tocarle nada.
 */
class PlanAccessTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Dueño Iron Body',
            'email' => 'acceso-'.uniqid().'@ironbody.test',
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

    /** El plan Valera de verdad: un mes de vigencia y 15 entradas. */
    private function planPorConsumo(int $entradas = 15, array $extra = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Valera',
            'price' => 65000,
            'duration_days' => 30,
            'access_mode' => PlanAccessRules::MODE_ENTRIES,
            'entry_credits' => $entradas,
            'active' => true,
        ], $extra));
    }

    private function planIlimitado(array $extra = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Mensual',
            'price' => 90000,
            'duration_days' => 30,
            'active' => true,
        ], $extra));
    }

    /** Un socio con el plan puesto y el periodo que pagó congelado en el pago. */
    private function socio(Plan $plan, int $diasTranscurridos = 5): User
    {
        $inicio = $this->hoy()->subDays($diasTranscurridos);
        $fin = $inicio->addDays((int) $plan->duration_days);

        $user = User::create([
            'name' => 'Socio Valera',
            'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
            'plan' => $plan->name,
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $fin->toDateString(),
        ]);

        Payment::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => $inicio->setTime(9, 0),
            'starts_on' => $inicio->toDateString(),
            'period_start' => $inicio->toDateString(),
            'period_end' => $fin->toDateString(),
        ]);

        return $user;
    }

    /** Marca N días distintos de entrada, hacia atrás desde ayer. */
    private function entradas(User $user, int $dias, int $desdeHaceDias = 1): void
    {
        for ($i = 0; $i < $dias; $i++) {
            Attendance::create([
                'user_id' => $user->id,
                'action' => 'entry',
                'source' => 'facial',
                'captured_at' => $this->hoy()->subDays($desdeHaceDias + $i)->setTime(9, 0)->setTimezone('UTC'),
            ]);
        }
    }

    private function acceso(User $user, ?CarbonImmutable $at = null): array
    {
        return app(MembershipAccess::class)->state($user->refresh(), $at);
    }

    // ── Las entradas ────────────────────────────────────────────────────────

    public function test_un_plan_por_consumo_dice_cuantas_entradas_le_quedan(): void
    {
        $user = $this->socio($this->planPorConsumo(15));
        $this->entradas($user, 4);

        $acceso = $this->acceso($user);

        $this->assertTrue($acceso['can_enter']);
        $this->assertSame('entries', $acceso['rules']['mode']);
        $this->assertSame(15, $acceso['entries']['total']);
        $this->assertSame(4, $acceso['entries']['used']);
        $this->assertSame(11, $acceso['entries']['left']);
    }

    public function test_dos_marcajes_el_mismo_dia_gastan_una_sola_entrada(): void
    {
        $user = $this->socio($this->planPorConsumo(15));

        // Entró, salió a comprar agua y volvió. Es un día, no dos.
        foreach ([8, 10] as $hora) {
            Attendance::create([
                'user_id' => $user->id,
                'action' => 'entry',
                'source' => 'facial',
                'captured_at' => $this->hoy()->subDay()->setTime($hora, 0)->setTimezone('UTC'),
            ]);
        }

        $this->assertSame(1, $this->acceso($user)['entries']['used']);
    }

    public function test_agotadas_las_entradas_no_puede_entrar_y_se_le_explica(): void
    {
        $user = $this->socio($this->planPorConsumo(15), diasTranscurridos: 20);
        $this->entradas($user, 15);

        $acceso = $this->acceso($user);

        $this->assertFalse($acceso['can_enter']);
        $this->assertSame(MembershipAccess::REASON_NO_ENTRIES, $acceso['reason']);
        $this->assertSame(0, $acceso['entries']['left']);
        $this->assertStringContainsString('15 entradas', (string) $acceso['message']);
    }

    public function test_quien_ya_entro_hoy_puede_volver_a_pasar_aunque_no_le_queden(): void
    {
        $user = $this->socio($this->planPorConsumo(15), diasTranscurridos: 20);
        // 14 días anteriores + hoy = 15 usadas, y hoy ya está pagada.
        $this->entradas($user, 14);
        Attendance::create([
            'user_id' => $user->id,
            'action' => 'entry',
            'source' => 'facial',
            'captured_at' => $this->hoy()->setTime(7, 0)->setTimezone('UTC'),
        ]);

        $acceso = $this->acceso($user);

        $this->assertSame(0, $acceso['entries']['left']);
        $this->assertTrue($acceso['entries']['used_today']);
        // El día ya se le cobró: salir a almorzar no puede costarle la entrada
        // de mañana.
        $this->assertTrue($acceso['can_enter']);
    }

    public function test_las_entradas_pertenecen_al_periodo_que_pago(): void
    {
        $plan = $this->planPorConsumo(15);
        $user = $this->socio($plan, diasTranscurridos: 3);

        // Entradas de un periodo ANTERIOR: se gastaron el mes pasado.
        $this->entradas($user, 10, desdeHaceDias: 40);
        // Y dos de este.
        $this->entradas($user, 2);

        $acceso = $this->acceso($user);

        $this->assertSame(2, $acceso['entries']['used']);
        $this->assertSame(13, $acceso['entries']['left']);
        $this->assertSame('payment', $acceso['entries']['window']['source']);
    }

    public function test_un_plan_de_los_de_antes_sigue_siendo_ilimitado(): void
    {
        $user = $this->socio($this->planIlimitado());
        $this->entradas($user, 25);

        $acceso = $this->acceso($user);

        $this->assertTrue($acceso['can_enter']);
        $this->assertNull($acceso['entries']);
        $this->assertFalse($acceso['rules']['restricted']);
    }

    // ── Días y horas ────────────────────────────────────────────────────────

    public function test_solo_ciertos_dias_de_la_semana(): void
    {
        // Lunes, miércoles y viernes.
        $plan = $this->planIlimitado(['access_days' => [1, 3, 5]]);
        $user = $this->socio($plan);

        $lunes = $this->hoy()->next(CarbonImmutable::MONDAY)->setTime(10, 0);
        $martes = $lunes->addDay();

        $this->assertTrue($this->acceso($user, $lunes)['can_enter']);

        $negado = $this->acceso($user, $martes);
        $this->assertFalse($negado['can_enter']);
        $this->assertSame(MembershipAccess::REASON_WEEKDAY, $negado['reason']);
        $this->assertStringContainsString('Lun, Mié y Vie', (string) $negado['message']);
    }

    public function test_horas_valle(): void
    {
        $plan = $this->planIlimitado(['access_windows' => [
            ['from' => '06:00', 'to' => '10:00', 'label' => 'Mañana'],
            ['from' => '14:00', 'to' => '17:00', 'label' => 'Tarde'],
        ]]);
        $user = $this->socio($plan);
        $dia = $this->hoy();

        $this->assertTrue($this->acceso($user, $dia->setTime(7, 30))['can_enter']);
        $this->assertTrue($this->acceso($user, $dia->setTime(16, 59))['can_enter']);

        $negado = $this->acceso($user, $dia->setTime(19, 0));
        $this->assertFalse($negado['can_enter']);
        $this->assertSame(MembershipAccess::REASON_HOURS, $negado['reason']);
        $this->assertStringContainsString('06:00', (string) $negado['message']);
    }

    public function test_una_franja_que_cruza_la_medianoche_vale_para_los_dos_lados(): void
    {
        $plan = $this->planIlimitado(['access_windows' => [['from' => '22:00', 'to' => '02:00']]]);
        $user = $this->socio($plan);
        $dia = $this->hoy();

        $this->assertTrue($this->acceso($user, $dia->setTime(23, 30))['can_enter']);
        $this->assertTrue($this->acceso($user, $dia->setTime(1, 0))['can_enter']);
        $this->assertFalse($this->acceso($user, $dia->setTime(12, 0))['can_enter']);
    }

    // ── El orden de las razones ─────────────────────────────────────────────

    public function test_el_congelamiento_manda_sobre_todo_lo_demas(): void
    {
        $user = $this->socio($this->planPorConsumo(15));
        app(MembershipFreeze::class)->freeze($user, 7, 'Viaje');

        $acceso = $this->acceso($user);

        $this->assertFalse($acceso['can_enter']);
        $this->assertSame(MembershipAccess::REASON_FROZEN, $acceso['reason']);
    }

    public function test_una_membresia_vencida_no_entra_aunque_le_queden_entradas(): void
    {
        $plan = $this->planPorConsumo(15);
        $user = $this->socio($plan, diasTranscurridos: 40);

        $acceso = $this->acceso($user);

        $this->assertFalse($acceso['can_enter']);
        $this->assertSame(MembershipAccess::REASON_EXPIRED, $acceso['reason']);
    }

    // ── Sumar días y entradas ───────────────────────────────────────────────

    public function test_sumar_entradas_al_periodo_en_curso(): void
    {
        $user = $this->socio($this->planPorConsumo(15), diasTranscurridos: 20);
        $this->entradas($user, 15);

        $this->assertFalse($this->acceso($user)['can_enter']);

        $res = $this->postJson("/api/users/{$user->id}/adjustments", [
            'kind' => 'entries',
            'amount' => 2,
            'reason' => 'El lector lo contó dos veces el martes',
        ], $this->h)->assertStatus(201)->json();

        $this->assertSame(17, $res['access']['entries']['total']);
        $this->assertSame(2, $res['access']['entries']['left']);
        $this->assertTrue($res['access']['can_enter']);
    }

    public function test_las_entradas_regaladas_no_se_arrastran_al_periodo_siguiente(): void
    {
        $plan = $this->planPorConsumo(15);
        $user = $this->socio($plan, diasTranscurridos: 5);

        // Concedidas para un periodo que ya pasó.
        MembershipAdjustment::create([
            'user_id' => $user->id,
            'kind' => MembershipAdjustment::KIND_ENTRIES,
            'amount' => 5,
            'window_start' => $this->hoy()->subDays(60)->toDateString(),
            'window_end' => $this->hoy()->subDays(30)->toDateString(),
        ]);

        $this->assertSame(15, $this->acceso($user)['entries']['total']);
    }

    public function test_sumar_dias_mueve_la_vigencia_y_queda_registrado(): void
    {
        $user = $this->socio($this->planIlimitado());
        $antes = $user->membership_end_date;

        $res = $this->postJson("/api/users/{$user->id}/adjustments", [
            'kind' => 'days',
            'amount' => 3,
            'reason' => 'Se cayó la luz tres días',
        ], $this->h)->assertStatus(201)->json();

        $user->refresh();

        $this->assertSame(
            CarbonImmutable::parse($antes)->addDays(3)->toDateString(),
            substr((string) $user->membership_end_date, 0, 10),
        );
        $this->assertSame('+3 días', $res['adjustment']['label']);
        $this->assertSame('Se cayó la luz tres días', $res['adjustment']['reason']);
        $this->assertSame($this->admin->name, $res['adjustment']['by']);
    }

    public function test_sumar_dias_a_una_membresia_vencida_cuenta_desde_hoy(): void
    {
        $user = $this->socio($this->planIlimitado(), diasTranscurridos: 45);

        $this->postJson("/api/users/{$user->id}/adjustments", [
            'kind' => 'days', 'amount' => 5,
        ], $this->h)->assertStatus(201);

        // Sumarlos detrás de un vencimiento viejo no le devolvería el acceso,
        // que es justo lo que se está intentando.
        $this->assertSame(
            $this->hoy()->addDays(5)->toDateString(),
            substr((string) $user->refresh()->membership_end_date, 0, 10),
        );
    }

    public function test_quitar_dias_recorta_desde_el_vencimiento(): void
    {
        // Le sobran 25 días y se le quitan 5: le tienen que quedar 20. Recortar
        // «desde hoy» se los habría llevado casi todos de golpe.
        $user = $this->socio($this->planIlimitado(), diasTranscurridos: 5);
        $finPrevio = $user->membership_end_date;

        $res = $this->postJson("/api/users/{$user->id}/adjustments", [
            'kind' => 'days',
            'amount' => -5,
            'reason' => 'Se le cargaron días de más al vender el plan',
        ], $this->h)->assertStatus(201)->json();

        $this->assertSame(
            CarbonImmutable::parse($finPrevio)->subDays(5)->toDateString(),
            substr((string) $user->refresh()->membership_end_date, 0, 10),
        );
        $this->assertSame('−5 días', $res['adjustment']['label']);
        $this->assertSame(-5, $res['adjustment']['amount']);
    }

    public function test_quitar_dias_puede_dejar_la_membresia_vencida(): void
    {
        // Deshacer 30 días dados por error el mes pasado deja al socio vencido,
        // y eso es exactamente lo que se pretende. Queda con autor y motivo.
        $user = $this->socio($this->planIlimitado(), diasTranscurridos: 25);

        $this->postJson("/api/users/{$user->id}/adjustments", [
            'kind' => 'days', 'amount' => -10, 'reason' => 'Corrige un ajuste anterior',
        ], $this->h)->assertStatus(201);

        $this->assertSame(
            $this->hoy()->subDays(5)->toDateString(),
            substr((string) $user->refresh()->membership_end_date, 0, 10),
        );
    }

    public function test_no_se_quitan_mas_dias_de_los_que_tiene(): void
    {
        // Una vigencia no puede terminar antes de empezar.
        $user = $this->socio($this->planIlimitado(), diasTranscurridos: 5);

        $this->postJson("/api/users/{$user->id}/adjustments", [
            'kind' => 'days', 'amount' => -300,
        ], $this->h)
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame(
            $this->hoy()->addDays(25)->toDateString(),
            substr((string) $user->refresh()->membership_end_date, 0, 10),
        );
    }

    public function test_no_se_suman_dias_sobre_una_membresia_congelada(): void
    {
        $user = $this->socio($this->planIlimitado());
        app(MembershipFreeze::class)->freeze($user, 7);

        $this->postJson("/api/users/{$user->id}/adjustments", [
            'kind' => 'days', 'amount' => 3,
        ], $this->h)
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_no_se_regalan_entradas_a_un_plan_ilimitado(): void
    {
        $user = $this->socio($this->planIlimitado());

        $this->postJson("/api/users/{$user->id}/adjustments", [
            'kind' => 'entries', 'amount' => 3,
        ], $this->h)->assertStatus(422);
    }

    public function test_recepcion_no_puede_regalar_dias(): void
    {
        $user = $this->socio($this->planIlimitado());

        $recepcion = Admin::create([
            'name' => 'Laura Mostrador',
            'email' => 'recepcion-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION,
            'status' => 'active',
        ]);
        $headers = $this->actingAsAdmin($recepcion);

        // Consultar el acceso sí: es parte de atender en el mostrador.
        $this->getJson("/api/users/{$user->id}/access", $headers)->assertOk();
        // Regalar días, no.
        $this->postJson("/api/users/{$user->id}/adjustments", [
            'kind' => 'days', 'amount' => 30,
        ], $headers)->assertStatus(403);
    }

    // ── El registro de asistencia ───────────────────────────────────────────

    public function test_la_entrada_fuera_de_regla_se_registra_marcada_y_no_se_rechaza(): void
    {
        $user = $this->socio($this->planPorConsumo(15), diasTranscurridos: 20);
        $this->entradas($user, 15);

        $res = $this->postJson('/api/attendances', [
            'user_id' => $user->id,
            'action' => 'entry',
            'source' => 'facial',
        ], $this->h)->assertStatus(201)->json();

        // Se guarda: la persona entró y el torniquete ya le abrió. Rechazarla
        // borraría la única prueba, porque el terminal descarta los 4xx.
        $this->assertTrue($res['ok']);
        $this->assertTrue($res['attendance']['over_limit']);
        $this->assertSame(MembershipAccess::REASON_NO_ENTRIES, $res['attendance']['limit_reason']);
        // Y la respuesta trae la cuenta ya al día, con esta entrada dentro.
        $this->assertSame(16, $res['access']['entries']['used']);
    }

    public function test_la_entrada_en_regla_no_se_marca(): void
    {
        $user = $this->socio($this->planPorConsumo(15));
        $this->entradas($user, 2);

        $res = $this->postJson('/api/attendances', [
            'user_id' => $user->id,
            'action' => 'entry',
            'source' => 'facial',
        ], $this->h)->assertStatus(201)->json();

        $this->assertFalse($res['attendance']['over_limit']);
        $this->assertSame(3, $res['access']['entries']['used']);
        $this->assertSame(12, $res['access']['entries']['left']);
    }

    // ── El catálogo de planes ───────────────────────────────────────────────

    public function test_crear_un_plan_por_consumo_desde_el_crm(): void
    {
        $res = $this->postJson('/api/plans', [
            'name' => 'Valera',
            'price' => 65000,
            'duration_days' => 30,
            'active' => true,
            'access_mode' => 'entries',
            'entry_credits' => 15,
            // El domingo llega como 0 desde cualquier calendario de JavaScript,
            // y desordenado y repetido desde cualquier formulario.
            'access_days' => [3, 1, 0, 1],
            'access_windows' => [
                ['from' => '14:00', 'to' => '17:00'],
                ['from' => '6:0', 'to' => '10:00', 'label' => 'Hora valle'],
            ],
        ], $this->h)->assertStatus(201)->json();

        $plan = Plan::findOrFail($res['id']);

        $this->assertSame('entries', $plan->access_mode);
        $this->assertSame(15, $plan->entry_credits);
        // Guardado normalizado: ordenado, sin repetidos y con el domingo como 7.
        $this->assertSame([1, 3, 7], $plan->access_days);
        $this->assertSame('06:00', $plan->access_windows[0]['from']);
        $this->assertSame('Por consumo', $res['access']['mode_label']);
        $this->assertSame('Lun, Mié y Dom', $res['access']['days_label']);
    }

    public function test_un_plan_por_consumo_sin_entradas_se_rechaza(): void
    {
        $this->postJson('/api/plans', [
            'name' => 'Mal configurado',
            'price' => 50000,
            'duration_days' => 30,
            'active' => true,
            'access_mode' => 'entries',
        ], $this->h)
            ->assertStatus(422)
            ->assertJsonValidationErrors('entry_credits');
    }

    public function test_editar_el_precio_no_borra_las_restricciones(): void
    {
        $plan = $this->planPorConsumo(15, ['access_days' => [1, 2, 3, 4, 5]]);

        $this->putJson("/api/plans/{$plan->id}", ['price' => 70000], $this->h)->assertOk();

        $plan->refresh();
        $this->assertSame([1, 2, 3, 4, 5], $plan->access_days);
        $this->assertSame(15, $plan->entry_credits);
    }

    public function test_pasar_un_plan_a_ilimitado_le_quita_el_tope_de_entradas(): void
    {
        $plan = $this->planPorConsumo(15);

        $this->putJson("/api/plans/{$plan->id}", ['access_mode' => 'unlimited'], $this->h)->assertOk();

        $plan->refresh();
        $this->assertSame('unlimited', $plan->access_mode);
        $this->assertNull($plan->entry_credits);
    }

    public function test_el_catalogo_de_la_app_dice_las_entradas_en_los_beneficios(): void
    {
        // La app no se puede modificar y solo lee la lista de beneficios: si las
        // entradas no van ahí, el socio no las ve en ninguna parte.
        $this->planPorConsumo(15, ['benefits' => json_encode(['Acceso a máquinas'])]);

        $res = $this->getJson('/api/membership-plans')->assertOk()->json();
        $valera = collect($res['data'])->firstWhere('name', 'Valera');

        $this->assertSame('15 entradas durante la vigencia del plan', $valera['benefits'][0]);
        $this->assertSame('Acceso a máquinas', $valera['benefits'][1]);
        $this->assertSame('1 mes', $valera['period']);
    }

    // ── El listado de socios (lo que lee el terminal) ────────────────────────

    public function test_el_listado_trae_el_acceso_de_cada_socio(): void
    {
        $user = $this->socio($this->planPorConsumo(15));
        $this->entradas($user, 3);

        $fila = collect($this->getJson('/api/users', $this->h)->assertOk()->json('data'))
            ->firstWhere('id', $user->id);

        $this->assertSame(12, $fila['access']['entries']['left']);
        $this->assertTrue($fila['access']['can_enter']);
        $this->assertSame('3 de 15 entradas', $fila['access']['summary']);
    }

    // ── Las reglas, a solas ─────────────────────────────────────────────────

    public function test_las_reglas_descartan_lo_que_no_es_una_franja(): void
    {
        $reglas = PlanAccessRules::fromArray([
            'access_mode' => 'entries',
            'entry_credits' => 10,
            'access_days' => ['2', 2, 99, null],
            'access_windows' => [
                ['from' => '08:00', 'to' => '08:00'],   // vacía
                ['from' => null, 'to' => '10:00'],      // incompleta
                '07:00-09:00',                          // escrita a mano
            ],
        ]);

        $this->assertSame([2], $reglas->days);
        $this->assertCount(1, $reglas->windows);
        $this->assertSame(['from' => '07:00', 'to' => '09:00', 'label' => null], $reglas->windows[0]);
    }

    public function test_un_plan_por_consumo_sin_numero_de_entradas_no_cierra_la_puerta(): void
    {
        // Una fila a medio llenar no puede dejar a nadie fuera: se degrada a
        // ilimitado, que es lo que era antes de configurarla.
        $reglas = PlanAccessRules::fromArray(['access_mode' => 'entries', 'entry_credits' => null]);

        $this->assertFalse($reglas->isByEntries());
        $this->assertNull($reglas->entries);
    }

    public function test_los_siete_dias_no_son_una_restriccion(): void
    {
        $reglas = PlanAccessRules::fromArray(['access_days' => [1, 2, 3, 4, 5, 6, 7]]);

        $this->assertFalse($reglas->restrictsDays());
        $this->assertNull($reglas->daysLabel());
    }

    public function test_los_topes_de_un_ajuste(): void
    {
        $user = $this->socio($this->planIlimitado());

        $this->expectExceptionMessage('365');
        app(MembershipAdjustments::class)->addDays($user, 400);
    }
}
