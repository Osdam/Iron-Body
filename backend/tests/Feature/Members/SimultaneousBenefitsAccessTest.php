<?php

namespace Tests\Feature\Members;

use App\Models\Attendance;
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
 * UNA COMPRA AÑADIDA NO PUEDE QUITARLE DERECHOS A LA MEMBRESÍA QUE YA TENÍA.
 *
 * EL CASO REAL. Una socia con una ANUALIDAD vigente compra además un PLAN DÍA
 * para una clase. Son dos compras independientes, pero el sistema materializa
 * UNA sola membresía por persona: `users.plan` más dos fechas. Y cada cobro
 * reescribe esa etiqueta ({@see MembershipPeriod::applyLocked()}).
 *
 * Lo que pasaba, en orden de gravedad:
 *
 *  1. Las REGLAS DE ACCESO salían de la etiqueta, así que pasaban a ser las del
 *     plan día. Un plan día es por consumo —una entrada—, y las entradas se
 *     cuentan en el periodo del cobro que cubre hoy, que es el de la anualidad:
 *     la socia llevaba meses entrando, así que quedaba BLOQUEADA en el acto,
 *     con la anualidad pagada y vigente.
 *  2. La ficha enseñaba PLAN DÍA como su membresía; la anualidad desaparecía de
 *     la pantalla.
 *  3. El vencimiento se movía: el plan corto se encadenaba al final del año.
 *
 * Lo que fijan estas pruebas es (1) y (2): el plan que gobierna hoy es el del
 * DERECHO que cubre hoy —el periodo congelado del cobro—, nunca la etiqueta.
 * (3) depende de distinguir «compra añadida» de «renovación», que es una regla
 * de negocio y no se decide aquí.
 */
class SimultaneousBenefitsAccessTest extends TestCase
{
    use RefreshDatabase;

    private function hoy(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    private function anualidad(): Plan
    {
        return Plan::create([
            'name' => 'Anualidad', 'price' => 1200000, 'duration_days' => 365, 'active' => true,
        ]);
    }

    /** El plan día de verdad: vigencia corta y UNA entrada. */
    private function planDia(int $dias = 1): Plan
    {
        return Plan::create([
            'name' => 'PLAN DÍA', 'price' => 12000, 'duration_days' => $dias,
            'access_mode' => PlanAccessRules::MODE_ENTRIES, 'entry_credits' => 1,
            'active' => true,
        ]);
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

    /** Un cobro ya aplicado, con su periodo congelado como lo deja apply(). */
    private function cobro(User $user, Plan $plan, CarbonImmutable $desde, CarbonImmutable $hasta): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => $desde->setTime(9, 0),
            'starts_on' => $desde->toDateString(),
            'period_start' => $desde->toDateString(),
            'period_end' => $hasta->toDateString(),
        ]);
    }

    private function acceso(User $user): array
    {
        return app(MembershipAccess::class)->state($user->refresh());
    }

    /** Marca N días distintos de entrada, hacia atrás desde ayer. */
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

    /**
     * El escenario completo tal y como lo deja el sistema hoy: la anualidad
     * empezó hace diez meses, el plan día se compró hoy y se encadenó al final
     * del año, y la etiqueta quedó en PLAN DÍA.
     *
     * @return array{0: User, 1: Plan, 2: Plan}
     */
    private function escenarioCamila(): array
    {
        $anual = $this->anualidad();
        $dia = $this->planDia();
        $socia = $this->socia();

        $inicioAnual = $this->hoy()->subDays(300);
        $finAnual = $inicioAnual->addDays(365);
        $this->cobro($socia, $anual, $inicioAnual, $finAnual);

        // El plan día, encadenado detrás del año (lo que hace apply() hoy).
        $this->cobro($socia, $dia, $finAnual, $finAnual->addDays(1));

        // Y lleva meses entrenando, que es lo que la dejaba fuera: con las
        // reglas del plan día —UNA entrada— sus entradas del año ya pasaban del
        // tope. Las entradas se cuentan en el periodo del cobro que cubre hoy,
        // o sea el de la anualidad.
        $this->entradas($socia, 40);

        // Y la etiqueta reescrita por esa última compra, con el fin movido.
        $socia->forceFill([
            'plan' => $dia->name,
            'membership_start_date' => $inicioAnual->toDateString(),
            'membership_end_date' => $finAnual->addDays(1)->toDateString(),
        ])->save();

        return [$socia, $anual, $dia];
    }

    // ── El fallo que bloqueaba ──────────────────────────────────────────────

    public function test_la_anualidad_sigue_dejandola_entrar_tras_comprar_un_plan_dia(): void
    {
        [$socia] = $this->escenarioCamila();

        $acceso = $this->acceso($socia);

        $this->assertTrue($acceso['can_enter'], 'la anualidad vigente tiene que dejarla entrar: '.($acceso['message'] ?? ''));
        $this->assertNull($acceso['reason']);
    }

    public function test_las_reglas_son_las_de_la_anualidad_no_las_del_plan_dia(): void
    {
        [$socia, $anual] = $this->escenarioCamila();

        $acceso = $this->acceso($socia);

        // El plan que gobierna, y que la ficha y el terminal reciben.
        $this->assertSame($anual->id, $acceso['plan']['id']);
        $this->assertSame('Anualidad', $acceso['plan']['name']);
        // Ilimitado: lo de «una entrada» era del plan día.
        $this->assertNull($acceso['entries'], 'la anualidad no es por consumo');
        $this->assertSame(PlanAccessRules::MODE_UNLIMITED, $acceso['rules']['mode']);
    }

    public function test_sin_el_arreglo_el_plan_dia_la_habria_dejado_fuera(): void
    {
        // La prueba de que el fallo era este y no otro: con la etiqueta como
        // única fuente —lo que hacía el código anterior— las reglas son las del
        // plan día, una entrada, y la socia llevaba 300 días entrando.
        [$socia, , $dia] = $this->escenarioCamila();

        $reglasEtiqueta = PlanAccessRules::fromPlan($dia);

        $this->assertTrue($reglasEtiqueta->isByEntries());
        $this->assertSame(1, $reglasEtiqueta->entries);
        // Y las que de verdad se aplican ya no son esas.
        $this->assertFalse($this->acceso($socia)['rules']['mode'] === PlanAccessRules::MODE_ENTRIES);
    }

    // ── Lo que no debe cambiar ──────────────────────────────────────────────

    public function test_un_plan_dia_a_secas_sigue_siendo_de_una_entrada(): void
    {
        // Quien solo compra un plan día no tiene nada más: ese es su derecho, y
        // sus reglas son las que manda. Aquí no se regala nada.
        $dia = $this->planDia();
        $socia = $this->socia();
        $hoy = $this->hoy();

        $this->cobro($socia, $dia, $hoy, $hoy->addDays(1));
        $socia->forceFill([
            'plan' => $dia->name,
            'membership_start_date' => $hoy->toDateString(),
            'membership_end_date' => $hoy->addDays(1)->toDateString(),
        ])->save();

        $acceso = $this->acceso($socia);

        $this->assertSame($dia->id, $acceso['plan']['id']);
        $this->assertTrue($acceso['can_enter']);
        $this->assertSame(1, $acceso['entries']['total']);
    }

    public function test_una_renovacion_normal_se_comporta_igual_que_antes(): void
    {
        // El caso de siempre: mismo plan, renovado. La etiqueta y el derecho
        // vigente coinciden, así que nada cambia.
        $mensual = Plan::create([
            'name' => 'Mensual', 'price' => 90000, 'duration_days' => 30,
            'access_mode' => PlanAccessRules::MODE_ENTRIES, 'entry_credits' => 15, 'active' => true,
        ]);
        $socia = $this->socia();
        $inicio = $this->hoy()->subDays(5);

        $this->cobro($socia, $mensual, $inicio, $inicio->addDays(30));
        $socia->forceFill([
            'plan' => $mensual->name,
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $inicio->addDays(30)->toDateString(),
        ])->save();

        $acceso = $this->acceso($socia);

        $this->assertSame($mensual->id, $acceso['plan']['id']);
        $this->assertSame(15, $acceso['entries']['total']);
        $this->assertTrue($acceso['can_enter']);
    }

    public function test_una_ficha_sin_cobros_sigue_gobernandose_por_su_etiqueta(): void
    {
        // Importadas del sistema anterior o puestas a mano: no tienen periodo
        // congelado en ningún cobro y la etiqueta es lo único que hay.
        $mensual = Plan::create([
            'name' => 'Mensual', 'price' => 90000, 'duration_days' => 30, 'active' => true,
        ]);
        $socia = $this->socia();

        $socia->forceFill([
            'plan' => $mensual->name,
            'membership_start_date' => $this->hoy()->subDays(3)->toDateString(),
            'membership_end_date' => $this->hoy()->addDays(27)->toDateString(),
        ])->save();

        $acceso = $this->acceso($socia);

        $this->assertSame($mensual->id, $acceso['plan']['id']);
        $this->assertTrue($acceso['can_enter']);
    }

    public function test_sin_ningun_derecho_vigente_sigue_bloqueada(): void
    {
        // Regla 4: si no queda nada vigente, el bloqueo se mantiene. El arreglo
        // no es una puerta abierta.
        $anual = $this->anualidad();
        $socia = $this->socia();

        $inicio = $this->hoy()->subDays(400);
        $this->cobro($socia, $anual, $inicio, $inicio->addDays(365));
        $socia->forceFill([
            'plan' => $anual->name,
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $inicio->addDays(365)->toDateString(),
        ])->save();

        $acceso = $this->acceso($socia);

        $this->assertFalse($acceso['can_enter']);
        $this->assertNotNull($acceso['reason']);
    }

    public function test_un_plan_dia_ya_vencido_no_le_quita_la_anualidad(): void
    {
        // Regla 3: al terminar el beneficio añadido solo se acaba ÉL.
        $anual = $this->anualidad();
        $dia = $this->planDia();
        $socia = $this->socia();

        $inicioAnual = $this->hoy()->subDays(100);
        $finAnual = $inicioAnual->addDays(365);
        $this->cobro($socia, $anual, $inicioAnual, $finAnual);
        // El plan día se usó y venció hace un mes.
        $this->cobro($socia, $dia, $this->hoy()->subDays(31), $this->hoy()->subDays(30));

        $socia->forceFill([
            'plan' => $dia->name,
            'membership_start_date' => $inicioAnual->toDateString(),
            'membership_end_date' => $finAnual->toDateString(),
        ])->save();

        $acceso = $this->acceso($socia);

        $this->assertTrue($acceso['can_enter'], $acceso['message'] ?? '');
        $this->assertSame($anual->id, $acceso['plan']['id']);
    }

    public function test_un_traslado_de_plan_sigue_mandando_aunque_no_tenga_cobro(): void
    {
        // Un traslado cambia el plan de un grupo de socios SIN cobrar nada, así
        // que no hay cobro que lo explique: la etiqueta es la única verdad y
        // tiene que seguir gobernando. Es lo contrario del caso del plan día, y
        // la primera versión de este arreglo lo rompía.
        $anual = $this->anualidad();
        $destino = Plan::create([
            'name' => 'Anualidad Plus', 'price' => 1400000, 'duration_days' => 365,
            'access_mode' => PlanAccessRules::MODE_ENTRIES, 'entry_credits' => 20, 'active' => true,
        ]);
        $socia = $this->socia();
        $inicio = $this->hoy()->subDays(10);

        // Compró la anualidad…
        $this->cobro($socia, $anual, $inicio, $inicio->addDays(365));
        // …y después se la trasladaron al plan nuevo, sin cobro.
        $socia->forceFill([
            'plan' => $destino->name,
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $inicio->addDays(365)->toDateString(),
        ])->save();

        $acceso = $this->acceso($socia);

        $this->assertSame($destino->id, $acceso['plan']['id'], 'el traslado manda');
        $this->assertSame(20, $acceso['entries']['total']);
    }

    public function test_entre_dos_derechos_que_cubren_hoy_manda_el_que_termina_mas_tarde(): void
    {
        // Lo que nunca se puede hacer es recortarle derechos a la membresía
        // principal por una compra añadida. Si algún día las dos cubren el
        // mismo día, gobierna la que llega más lejos.
        $anual = $this->anualidad();
        $dia = $this->planDia();
        $socia = $this->socia();
        $hoy = $this->hoy();

        $this->cobro($socia, $anual, $hoy->subDays(10), $hoy->addDays(355));
        $this->cobro($socia, $dia, $hoy, $hoy->addDays(1));

        $socia->forceFill([
            'plan' => $dia->name,
            'membership_start_date' => $hoy->subDays(10)->toDateString(),
            'membership_end_date' => $hoy->addDays(355)->toDateString(),
        ])->save();

        $this->assertSame($anual->id, $this->acceso($socia)['plan']['id']);
    }
}
