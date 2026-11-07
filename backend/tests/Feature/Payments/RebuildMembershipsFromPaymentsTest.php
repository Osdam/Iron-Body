<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\MembershipRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rehacer la cuenta para los socios de antes del 14/09/2026.
 *
 * El caso patrón es literal: Elizabet Calderón (#7642). Dos cobros de Élite
 * registrados el mismo día por recepción, el primero anulado a los 28 minutos y
 * repuesto por el segundo. El código de entonces no devolvía los días del
 * anulado ni dejaba periodo congelado, así que encadenó el segundo encima del
 * primero y la ficha quedó con 60 días de un plan de 30.
 */
class RebuildMembershipsFromPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private function plan(int $dias = 30): Plan
    {
        return Plan::create(['name' => 'Élite', 'price' => 180000, 'duration_days' => $dias, 'active' => true]);
    }

    /** El socio tal como lo dejó el código viejo: fechas estiradas, pagos sin periodo. */
    private function elizabet(Plan $plan): User
    {
        $u = User::create([
            'name' => 'Elizabet Calderón',
            'email' => 'eliza-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
            'plan' => 'Élite',
            'membership_start_date' => '2026-09-12',
            'membership_end_date' => '2026-11-11',
        ]);

        // Anulado 28 minutos después de registrarse. Fecha sin hora, como se
        // guardaba entonces.
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $plan->id, 'amount' => 180000,
            'method' => 'transfer', 'status' => 'cancelled', 'reference' => 'REC-1001',
            'paid_at' => '2026-09-12 00:00:00', 'origin' => 'counter',
        ]);
        // El que lo repuso, fechado dos días después por recepción.
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $plan->id, 'amount' => 180000,
            'method' => 'transfer', 'status' => 'paid', 'reference' => 'REC-1001',
            'paid_at' => '2026-09-14 00:00:00', 'origin' => 'counter',
        ]);

        return $u;
    }

    public function test_le_deja_el_mes_que_pago_y_no_dos(): void
    {
        $u = $this->elizabet($this->plan());

        $this->artisan("memberships:rebuild --user={$u->id} --apply")->assertSuccessful();

        $u->refresh();
        // El cobro que sobrevive está fechado el 14: su mes corre desde ahí.
        $this->assertSame('2026-09-14', $u->membership_start_date);
        $this->assertSame('2026-10-14', $u->membership_end_date);
    }

    public function test_la_medianoche_no_le_roba_un_dia(): void
    {
        $u = $this->elizabet($this->plan());

        $this->artisan("memberships:rebuild --user={$u->id} --apply")->assertSuccessful();

        // `2026-09-14 00:00:00` leído como instante y pasado a Neiva sería el
        // día 13: la fecha que escribió recepción es la que manda.
        $this->assertNotSame('2026-10-13', $u->refresh()->membership_end_date);
    }

    public function test_sin_apply_no_escribe_nada(): void
    {
        $u = $this->elizabet($this->plan());

        $this->artisan("memberships:rebuild --user={$u->id}")->assertSuccessful();

        $this->assertSame('2026-11-11', $u->refresh()->membership_end_date);
    }

    public function test_el_escaneo_sin_socio_solo_lee(): void
    {
        $u = $this->elizabet($this->plan());

        $this->artisan('memberships:rebuild')->assertSuccessful();

        $this->assertSame('2026-11-11', $u->refresh()->membership_end_date);
    }

    public function test_escribir_sobre_todos_exige_decirlo_a_proposito(): void
    {
        $u = $this->elizabet($this->plan());

        $this->artisan('memberships:rebuild --apply')->assertFailed();

        $this->assertSame('2026-11-11', $u->refresh()->membership_end_date);
    }

    public function test_dos_renovaciones_legitimas_se_encadenan_y_no_se_recortan(): void
    {
        $plan = $this->plan();
        $u = User::create([
            'name' => 'Socio al día', 'email' => 'aldia-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active', 'plan' => 'Élite',
            'membership_start_date' => '2026-09-12', 'membership_end_date' => '2026-11-11',
        ]);
        foreach (['2026-09-12 00:00:00', '2026-09-14 00:00:00'] as $fecha) {
            Payment::create([
                'user_id' => $u->id, 'plan_id' => $plan->id, 'amount' => 180000,
                'method' => 'cash', 'status' => 'paid', 'paid_at' => $fecha, 'origin' => 'counter',
            ]);
        }

        $this->artisan("memberships:rebuild --user={$u->id} --apply")->assertSuccessful();

        // Los dos siguen cobrados: 12 sept + 30 = 12 oct, y el segundo encadena.
        $this->assertSame('2026-11-11', $u->refresh()->membership_end_date);
    }

    public function test_no_reconstruye_a_un_socio_migrado(): void
    {
        $plan = $this->plan();
        $u = User::create([
            'name' => 'Migrado', 'email' => 'migrado-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active', 'plan' => 'Élite',
            'membership_start_date' => '2026-09-01', 'membership_end_date' => '2027-03-01',
        ]);
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $plan->id, 'amount' => 180000,
            'method' => 'manual', 'status' => 'paid', 'reference' => 'MIGR-4412',
            'paid_at' => '2026-09-01 00:00:00', 'origin' => 'legacy',
        ]);

        $this->artisan("memberships:rebuild --user={$u->id} --apply")->assertSuccessful();

        // Su vigencia la copió el importador del sistema anterior: intocable.
        $this->assertSame('2027-03-01', $u->refresh()->membership_end_date);
    }

    /**
     * El caso de Maria Fernanda Ocampo (#7659): el cobro que sobrevive está
     * fechado a futuro, así que la cuenta correcta la deja SIN acceso hasta
     * ese día. Es una decisión de mostrador, no un detalle: tiene que verse.
     */
    public function test_avisa_cuando_la_correccion_deja_al_socio_fuera(): void
    {
        $plan = $this->plan();
        $hoy = MembershipRebuilder::today();
        $u = User::create([
            'name' => 'Fechada a futuro', 'email' => 'futuro-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active', 'plan' => 'Élite',
            'membership_start_date' => $hoy->subDay()->toDateString(),
            'membership_end_date' => $hoy->addDays(59)->toDateString(),
        ]);
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $plan->id, 'amount' => 180000,
            'method' => 'transfer', 'status' => 'cancelled',
            'paid_at' => $hoy->subDay()->format('Y-m-d').' 00:00:00', 'origin' => 'counter',
        ]);
        $arranca = $hoy->addDays(5);
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $plan->id, 'amount' => 180000,
            'method' => 'transfer', 'status' => 'paid',
            'paid_at' => $arranca->format('Y-m-d').' 00:00:00', 'origin' => 'counter',
        ]);

        $this->artisan("memberships:rebuild --user={$u->id}")
            ->expectsOutputToContain('NO ENTRA HASTA '.$arranca->toDateString())
            ->assertSuccessful();
    }

    public function test_deja_rastro_de_lo_que_cambio(): void
    {
        $u = $this->elizabet($this->plan());

        $this->artisan("memberships:rebuild --user={$u->id} --apply")->assertSuccessful();

        $traza = AuditLog::where('entity', 'membership')->where('entity_id', (string) $u->id)->first();
        $this->assertNotNull($traza);
        $this->assertSame('2026-11-11', $traza->metadata['previous_end']);
        $this->assertSame('2026-10-14', $traza->metadata['end']);
    }

    /**
     * El gimnasio regala vigencias: demos de tester, cortesías del personal,
     * cuentas de revisión. No nacen de un pago, así que «reconstruirlas desde
     * los pagos» daría cero días y les cerraría la puerta. Se dejan en paz.
     */
    public function test_no_le_quita_el_acceso_a_quien_nunca_pago(): void
    {
        $u = User::create([
            'name' => 'Tester con demo', 'email' => 'demo-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active', 'plan' => 'Élite',
            'membership_start_date' => '2026-08-08', 'membership_end_date' => '2031-07-19',
        ]);

        $this->artisan("memberships:rebuild --user={$u->id} --apply")->assertSuccessful();

        $u->refresh();
        $this->assertSame('2031-07-19', $u->membership_end_date);
        $this->assertSame('Élite', $u->plan);
    }

    /** Un pago pendiente tampoco es una compra cobrada, pero sí prueba que compró. */
    public function test_si_se_anulan_todos_los_pagos_la_vigencia_desaparece(): void
    {
        $u = $this->elizabet($this->plan());
        Payment::where('user_id', $u->id)->update(['status' => 'cancelled']);

        $this->artisan("memberships:rebuild --user={$u->id} --apply")->assertSuccessful();

        $u->refresh();
        $this->assertNull($u->membership_end_date);
        $this->assertNull($u->plan);
        // La ficha sigue ahí: corregir una fecha no borra a nadie.
        $this->assertSame('Elizabet Calderón', $u->name);
        $this->assertSame(2, Payment::where('user_id', $u->id)->count());
    }

    public function test_no_le_quita_la_vigencia_a_quien_solo_compro_planes_dia(): void
    {
        // EL VISITANTE DE LA CALLE. No tiene membresía debajo, así que su plan
        // día ES su vigencia y así se la escribió la caja. La reconstrucción
        // salta los beneficios añadidos para no volver a inflar vencimientos,
        // y a él no le quedaba ni un pago que contar: le vaciaba la ficha y lo
        // dejaba fuera el mismo día que pagó.
        $dia = Plan::create([
            'name' => 'PLAN DÍA', 'price' => 12000, 'duration_days' => 1,
            'benefit_only' => true, 'active' => true,
        ]);
        $hoy = MembershipRebuilder::today();

        $u = User::create([
            'name' => 'Miguel Rincón', 'email' => 'miguel-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active', 'plan' => 'PLAN DÍA',
            'membership_start_date' => $hoy->toDateString(),
            'membership_end_date' => $hoy->addDays(1)->toDateString(),
        ]);
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $dia->id, 'amount' => 12000,
            'method' => 'cash', 'status' => 'paid', 'origin' => 'counter',
            'paid_at' => $hoy->setTime(9, 0),
            'period_start' => $hoy->toDateString(), 'period_end' => $hoy->addDays(1)->toDateString(),
        ]);

        $this->artisan("memberships:rebuild --user={$u->id} --apply")->assertSuccessful();

        $u->refresh();
        $this->assertSame('PLAN DÍA', $u->plan, 'su plan día es su membresía: no se le borra');
        $this->assertSame($hoy->toDateString(), $u->membership_start_date);
        $this->assertSame($hoy->addDays(1)->toDateString(), $u->membership_end_date);
    }

    public function test_labels_only_corrige_el_nombre_y_no_toca_ninguna_fecha(): void
    {
        // Las dos reparaciones no tienen el mismo riesgo: el nombre del plan
        // sale del historial y es demostrable; recalcular una vigencia puede
        // acortarle el acceso a quien hoy entra. Con --labels-only se aplica
        // la mitad segura sobre todo el gimnasio sin mover una sola fecha.
        $plan = $this->plan();
        $u = $this->elizabet($plan);

        $this->artisan("memberships:rebuild --user={$u->id} --apply --labels-only")->assertSuccessful();

        $u->refresh();
        $this->assertSame('2026-09-12', $u->membership_start_date);
        $this->assertSame('2026-11-11', $u->membership_end_date, 'su vigencia estirada sigue igual');
    }

    public function test_el_barrido_dice_de_quien_es_cada_etiqueta(): void
    {
        // En el barrido de todo el gimnasio son decenas de líneas seguidas. Sin
        // nombre ni número no hay forma de revisar la lista antes de escribirla
        // —ni de ver que a alguien le quiere poner de plan algo que no es una
        // membresía— ni de comprobarla después.
        $mensual = $this->plan();
        $dia = Plan::create([
            'name' => 'PLAN DÍA', 'price' => 12000, 'duration_days' => 1,
            'benefit_only' => true, 'active' => true,
        ]);

        $u = User::create([
            'name' => 'Socia del sistema anterior', 'email' => 'anterior-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active', 'plan' => 'PLAN DÍA',
            'membership_start_date' => '2026-10-01', 'membership_end_date' => '2026-12-01',
        ]);
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $mensual->id, 'amount' => 180000,
            'method' => 'cash', 'status' => 'paid', 'reference' => 'MIGR-77',
            'paid_at' => '2026-10-01 00:00:00', 'origin' => 'counter',
        ]);
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $dia->id, 'amount' => 12000,
            'method' => 'cash', 'status' => 'paid', 'origin' => 'counter',
            'paid_at' => '2026-11-01 00:00:00',
        ]);

        $this->artisan('memberships:rebuild')
            ->expectsOutputToContain("#{$u->id} Socia del sistema anterior: su plan dice «PLAN DÍA»")
            ->assertSuccessful();
    }

    // ── Cobros que no son membresías ────────────────────────────────────────

    /** El vehículo contable: cargado como plan, pero sin vigencia que dar. */
    private function comision(): Plan
    {
        return Plan::create([
            'name' => 'Comisión de personalizados', 'price' => 0,
            'duration_days' => 0, 'active' => true,
        ]);
    }

    public function test_una_comision_no_puede_ser_la_etiqueta_de_un_socio(): void
    {
        // «Comisión de personalizados» es el cobro de piso de los entrenadores,
        // cargado como un plan de treinta días. Puesto en cero días —así lo
        // marca el catálogo para los cobros sin vigencia— no puede ser la
        // membresía de nadie: la reparación tiene que seguir mirando hacia
        // atrás hasta el último cobro que SÍ compró membresía.
        $mensual = $this->plan();
        $dia = Plan::create([
            'name' => 'PLAN DÍA', 'price' => 12000, 'duration_days' => 1,
            'benefit_only' => true, 'active' => true,
        ]);

        $u = User::create([
            'name' => 'Sorley Hernandez', 'email' => 'sorley-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active', 'plan' => 'PLAN DÍA',
            'membership_start_date' => '2026-10-01', 'membership_end_date' => '2026-12-01',
        ]);
        foreach ([[$mensual, '2026-09-01'], [$this->comision(), '2026-10-01'], [$dia, '2026-11-01']] as [$plan, $fecha]) {
            Payment::create([
                'user_id' => $u->id, 'plan_id' => $plan->id, 'amount' => $plan->price,
                'method' => 'cash', 'status' => 'paid', 'origin' => 'counter',
                'paid_at' => $fecha.' 00:00:00',
            ]);
        }

        $this->assertSame('Élite', app(MembershipRebuilder::class)->labelFix($u));
    }

    public function test_sin_ninguna_membresia_pagada_no_propone_etiqueta(): void
    {
        // Y si lo único que tiene son comisiones, no hay nombre que devolver:
        // antes de inventar uno, mejor dejarlo como está para que lo mire una
        // persona.
        $dia = Plan::create([
            'name' => 'PLAN DÍA', 'price' => 12000, 'duration_days' => 1,
            'benefit_only' => true, 'active' => true,
        ]);
        $u = User::create([
            'name' => 'Solo comisiones', 'email' => 'comis-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active', 'plan' => 'PLAN DÍA',
            'membership_start_date' => '2026-10-01', 'membership_end_date' => '2026-12-01',
        ]);
        foreach ([$this->comision(), $dia] as $plan) {
            Payment::create([
                'user_id' => $u->id, 'plan_id' => $plan->id, 'amount' => $plan->price,
                'method' => 'cash', 'status' => 'paid', 'origin' => 'counter',
                'paid_at' => '2026-10-01 00:00:00',
            ]);
        }

        $this->assertNull(app(MembershipRebuilder::class)->labelFix($u));
    }

    public function test_una_comision_no_le_regala_dias_de_vigencia(): void
    {
        // Estos cobros tienen periodo congelado de cuando el plan duraba treinta
        // días. Reconstruir desde él le devolvería al socio el mes que la
        // comisión le regaló, que es de donde salieron las vigencias que
        // empiezan en 2027.
        $mensual = $this->plan();
        $comision = $this->comision();

        $u = User::create([
            'name' => 'Socio con comisión', 'email' => 'comision-'.uniqid().'@example.com',
            'password' => 'secret', 'status' => 'active', 'plan' => 'Élite',
            'membership_start_date' => '2026-09-14', 'membership_end_date' => '2026-11-13',
        ]);
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $mensual->id, 'amount' => 180000,
            'method' => 'cash', 'status' => 'paid', 'origin' => 'counter',
            'paid_at' => '2026-09-14 00:00:00',
        ]);
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $comision->id, 'amount' => 0,
            'method' => 'cash', 'status' => 'paid', 'origin' => 'counter',
            'paid_at' => '2026-10-14 00:00:00',
            'period_start' => '2026-10-14', 'period_end' => '2026-11-13',
        ]);

        $this->artisan("memberships:rebuild --user={$u->id} --apply")->assertSuccessful();

        // Su mes de Élite y nada más: 14 sept + 30 = 14 oct.
        $this->assertSame('2026-10-14', $u->refresh()->membership_end_date);
    }
}
