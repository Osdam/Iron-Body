<?php

namespace Tests\Feature\Reports;

use App\Models\Admin;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\MembershipFreeze;
use App\Services\Reports\MemberInsights;
use App\Services\Reports\ReportRange;
use App\Support\Caja\PaymentOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «VOLVIERON TRAS VENCER», que durante meses dijo CERO teniendo gente que volvía.
 *
 * El contador miraba a los socios cuya `membership_end_date` caía en el periodo
 * y, de esos, a los que tenían un pago posterior a esa fecha. Nunca podía dar
 * otra cosa que cero: al renovar, la fecha de vencimiento SE MUEVE hacia
 * delante. El que vuelve sale del grupo que se estaba contando y, de paso, su
 * pago queda por detrás de su nuevo vencimiento. Se medía un conjunto del que ya
 * se habían ido todos los que había que contar.
 *
 * El arreglo mide la vuelta donde de verdad queda registrada —en el historial de
 * pagos— y estos tests fijan las distinciones que importan: volver no es
 * renovar a tiempo, y no es inscribirse por primera vez.
 */
class MemberRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Plan $mensual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Dueño',
            'email' => 'ret-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        $this->mensual = Plan::create([
            'name' => 'Mensual',
            'price' => 70000,
            'duration_days' => 30,
            'active' => true,
        ]);
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    private function socio(string $nombre, ?CarbonImmutable $vence = null): User
    {
        return User::create([
            'name' => $nombre,
            'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
            'plan' => $this->mensual->name,
            'membership_end_date' => $vence?->toDateString(),
        ]);
    }

    /** Un cobro de membresía con el periodo que compró. */
    private function cobro(User $u, CarbonImmutable $cuando, ?CarbonImmutable $desde = null, ?CarbonImmutable $hasta = null): Payment
    {
        $desde ??= $cuando;

        return Payment::create([
            'user_id' => $u->id,
            'plan_id' => $this->mensual->id,
            'amount' => 70000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => $cuando->setTime(10, 0),
            'origin' => PaymentOrigin::COUNTER->value,
            'period_start' => $desde->toDateString(),
            'period_end' => ($hasta ?? $desde->addDays(30))->toDateString(),
        ]);
    }

    /**
     * Los últimos 30 días.
     *
     * No «este mes» a propósito: ese periodo va del día 1 a HOY, así que un test
     * escrito con fechas del mes caería fuera de rango según el día en que se
     * ejecutara. Aquí todo se sitúa respecto a hoy y vale cualquier día del año.
     */
    private function ultimos30(): MemberInsights
    {
        return new MemberInsights(ReportRange::fromRequest(['preset' => 'last_30']));
    }

    // ── El caso que estaba roto ─────────────────────────────────────────────

    public function test_el_socio_que_vencio_y_volvio_a_pagar_se_cuenta(): void
    {
        $hoy = $this->hoy();

        // Pagó hace tres meses, se le venció, y volvió hace tres días.
        $u = $this->socio('Volvió Pérez', $hoy->addDays(27));
        $this->cobro($u, $hoy->subDays(90), $hoy->subDays(90), $hoy->subDays(60));
        $this->cobro($u, $hoy->subDays(3), $hoy->subDays(3), $hoy->addDays(27));

        $retencion = $this->ultimos30()->retention();

        // Esto es exactamente lo que antes daba 0.
        $this->assertSame(1, $retencion['returned']);
        $this->assertSame(0, $retencion['lost']);
        // Estuvo fuera desde que se le acabó la cobertura hasta que volvió.
        $this->assertSame(57.0, $retencion['avg_days_away']);
    }

    public function test_renovar_antes_de_vencer_no_es_volver(): void
    {
        $hoy = $this->hoy();

        // Renovó cuando todavía le quedaban días: el periodo nuevo se encadena.
        $u = $this->socio('Puntual Gómez', $hoy->addDays(20));
        $this->cobro($u, $hoy->subDays(40), $hoy->subDays(40), $hoy->subDays(10));
        $this->cobro($u, $hoy->subDays(12), $hoy->subDays(10), $hoy->addDays(20));

        $this->assertSame(0, $this->ultimos30()->retention()['returned']);
    }

    public function test_un_socio_nuevo_no_es_una_vuelta(): void
    {
        $hoy = $this->hoy();
        $u = $this->socio('Nuevo Ramírez', $hoy->addDays(25));
        $this->cobro($u, $hoy->subDays(5));

        $retencion = $this->ultimos30()->retention();

        $this->assertSame(0, $retencion['returned']);
        $this->assertNull($retencion['avg_days_away']);
    }

    public function test_quien_vuelve_dos_veces_en_el_mes_cuenta_una(): void
    {
        $hoy = $this->hoy();

        $u = $this->socio('Intermitente Díaz', $hoy->addDays(40));
        $this->cobro($u, $hoy->subDays(120), $hoy->subDays(120), $hoy->subDays(90));
        $this->cobro($u, $hoy->subDays(20), $hoy->subDays(20), $hoy->addDays(10));
        $this->cobro($u, $hoy->subDays(2), $hoy->addDays(10), $hoy->addDays(40));

        $this->assertSame(1, $this->ultimos30()->retention()['returned']);
    }

    public function test_los_pagos_viejos_sin_periodo_guardado_tambien_cuentan(): void
    {
        $hoy = $this->hoy();

        // Pagos anteriores a que el periodo se congelara en el propio pago: la
        // cobertura se reconstruye con la duración del plan.
        $u = $this->socio('Importado López', $hoy->addDays(25));
        Payment::create([
            'user_id' => $u->id,
            'plan_id' => $this->mensual->id,
            'amount' => 70000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => $hoy->subDays(100)->setTime(9, 0),
        ]);
        $this->cobro($u, $hoy->subDays(5), $hoy->subDays(5), $hoy->addDays(25));

        $this->assertSame(1, $this->ultimos30()->retention()['returned']);
    }

    // ── Los que no han vuelto ───────────────────────────────────────────────

    public function test_el_que_vencio_en_el_periodo_y_no_ha_vuelto_se_cuenta_como_perdido(): void
    {
        $hoy = $this->hoy();

        $u = $this->socio('Se Fue Castro', $hoy->subDays(5));
        $this->cobro($u, $hoy->subDays(35), $hoy->subDays(35), $hoy->subDays(5));

        $retencion = $this->ultimos30()->retention();

        $this->assertSame(0, $retencion['returned']);
        $this->assertSame(1, $retencion['lost']);
    }

    public function test_una_membresia_congelada_no_es_un_socio_perdido(): void
    {
        $hoy = $this->hoy();

        // Congelar mueve el vencimiento a ayer: sin excluirlas, cada socio de
        // vacaciones aparecería en la lista de rescate como si se hubiera ido.
        $u = $this->socio('De Viaje Salas', $hoy->addDays(20));
        $this->cobro($u, $hoy->subDays(10), $hoy->subDays(10), $hoy->addDays(20));
        app(MembershipFreeze::class)->freeze($u, 15, 'Viaje');

        $this->assertSame(0, $this->ultimos30()->retention()['lost']);
    }

    // ── El endpoint ─────────────────────────────────────────────────────────

    public function test_el_informe_de_miembros_publica_el_contrato_nuevo(): void
    {
        $hoy = $this->hoy();

        $u = $this->socio('Volvió Pérez', $hoy->addDays(27));
        $this->cobro($u, $hoy->subDays(90), $hoy->subDays(90), $hoy->subDays(60));
        $this->cobro($u, $hoy->subDays(3), $hoy->subDays(3), $hoy->addDays(27));

        $this->getJson('/api/admin/reports/members?preset=last_30', $this->actingAsAdmin($this->admin))
            ->assertOk()
            ->assertJsonStructure(['retention' => ['returned', 'lost', 'avg_days_away']])
            ->assertJsonPath('retention.returned', 1);
    }
}
