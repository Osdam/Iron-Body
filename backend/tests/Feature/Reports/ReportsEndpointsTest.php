<?php

namespace Tests\Feature\Reports;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Reports\ReportRange;
use App\Support\Caja\PaymentOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Que TODAS las puertas del centro de informes abran, y devuelvan lo que la
 * pantalla espera encontrar.
 *
 * El otro fichero ({@see ReportsCenterTest}) comprueba que las cifras sean
 * ciertas. Este comprueba lo otro que rompe un panel: que un endpoint conteste
 * 500 con un filtro raro, que una clave cambie de nombre y la tarjeta se quede
 * en blanco, o que una descarga devuelva el HTML del login en vez del fichero.
 */
class ReportsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $h;

    private User $socio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Eduar Vendedor',
            'email' => 'endpoints-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        $this->h = $this->actingAsAdmin($this->admin);

        $hoy = ReportRange::today();
        $plan = Plan::create(['name' => 'Mensual', 'price' => 70000, 'duration_days' => 30, 'active' => true]);

        $this->socio = User::create([
            'name' => 'Ana Socia',
            'email' => 'ana-'.uniqid().'@example.com',
            'password' => 'secret',
            'document' => '1075123456',
            'status' => 'active',
            'membership_start_date' => $hoy->subDays(25)->toDateString(),
            'membership_end_date' => $hoy->addDays(5)->toDateString(),
        ]);

        Payment::create([
            'user_id' => $this->socio->id,
            'plan_id' => $plan->id,
            'amount' => 70000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => $hoy->setTime(10, 0)->utc(),
            'origin' => PaymentOrigin::COUNTER->value,
            'registered_by' => $this->admin->id,
            'registered_by_name' => $this->admin->name,
            'registered_by_role' => $this->admin->role,
            'period_start' => $hoy->subDays(25)->toDateString(),
            'period_end' => $hoy->addDays(5)->toDateString(),
        ]);

        AuditLog::create([
            'action' => 'update', 'module' => 'Miembros', 'entity' => 'miembro',
            'entity_id' => (string) $this->socio->id, 'target_name' => $this->socio->name,
            'actor_id' => (string) $this->admin->id, 'actor_name' => $this->admin->name,
            'actor_role' => $this->admin->role, 'summary' => 'Actualizó el teléfono',
            'created_at' => $hoy->setTime(11, 0)->utc(),
        ]);
    }

    private function hoy(): CarbonImmutable
    {
        return ReportRange::today();
    }

    public function test_los_desplegables_no_cuestan_un_informe(): void
    {
        $r = $this->getJson('/api/admin/reports/options', $this->h)->assertOk()->json();

        $this->assertContains('Mensual', array_column($r['plans'], 'value'));
        $this->assertContains('admin-'.$this->admin->id, array_column($r['staff'], 'value'));
        $this->assertContains('cash', array_column($r['methods'], 'value'));
        $this->assertContains('membership', array_column($r['sources'], 'value'));
        $this->assertNotEmpty($r['presets']);
    }

    public function test_cada_seccion_responde_con_su_estructura(): void
    {
        $this->getJson('/api/admin/reports/summary?preset=this_month', $this->h)
            ->assertOk()
            ->assertJsonStructure([
                'range' => ['from', 'to', 'label', 'granularity'],
                'pulse', 'money' => ['collected', 'by_method', 'series'],
                'sales', 'memberships' => ['snapshot', 'expiring', 'overdue'],
                'members', 'staff', 'receivables', 'reversals',
            ]);

        $this->getJson('/api/admin/reports/money?preset=this_month', $this->h)
            ->assertOk()
            ->assertJsonStructure(['totals', 'sold_vs_collected', 'by_method', 'series', 'by_staff', 'filters']);

        $this->getJson('/api/admin/reports/memberships?preset=this_month', $this->h)
            ->assertOk()
            ->assertJsonStructure(['snapshot', 'by_plan', 'expiring_buckets', 'overdue_buckets', 'sold']);

        $this->getJson('/api/admin/reports/members?preset=this_month', $this->h)
            ->assertOk()
            ->assertJsonStructure(['totals', 'new', 'signups', 'retention']);

        $this->getJson('/api/admin/reports/sales?preset=this_month', $this->h)
            ->assertOk()
            ->assertJsonStructure(['totals', 'by_plan', 'by_staff', 'series', 'plans']);

        $this->getJson('/api/admin/reports/staff?preset=this_month', $this->h)
            ->assertOk()
            ->assertJsonStructure(['roster', 'leaderboard']);
    }

    public function test_el_detalle_de_ventas_trae_vendido_cobrado_y_saldo(): void
    {
        $r = $this->getJson('/api/admin/reports/sales/rows?preset=this_month', $this->h)->assertOk()->json();

        $this->assertSame(1, $r['total']);
        $venta = $r['data'][0];
        $this->assertSame('Ana Socia', $venta['member_name']);
        $this->assertEquals(70000, $venta['sold']);
        $this->assertEquals(70000, $venta['collected']);
        $this->assertEquals(0, $venta['balance']);
        $this->assertSame('new', $venta['kind'], 'Es su primer plan: es una venta nueva.');
        $this->assertSame('Eduar Vendedor', $venta['staff_name']);
    }

    public function test_el_buscador_encuentra_por_nombre_y_por_documento(): void
    {
        $porNombre = $this->getJson('/api/admin/reports/members/search?q=Ana', $this->h)->assertOk()->json();
        $this->assertSame($this->socio->id, $porNombre['data'][0]['id']);

        $porDocumento = $this->getJson('/api/admin/reports/members/search?q=1075123', $this->h)->assertOk()->json();
        $this->assertSame($this->socio->id, $porDocumento['data'][0]['id']);

        // Sin término no se devuelve el padrón entero.
        $this->assertSame([], $this->getJson('/api/admin/reports/members/search?q=', $this->h)->assertOk()->json('data'));
    }

    public function test_la_ficha_de_una_persona_incluye_sus_dias_y_su_dia_a_dia(): void
    {
        $perfil = $this->getJson('/api/admin/reports/staff/profile?'.http_build_query([
            'preset' => 'this_month',
            'staff' => 'admin-'.$this->admin->id,
        ]), $this->h)->assertOk()->json();

        $this->assertSame('Eduar Vendedor', $perfil['staff']['name']);
        $this->assertEquals(70000, $perfil['money']['collected']);
        $this->assertSame(1, $perfil['sales']['count']);
        $this->assertSame($this->hoy()->toDateString(), $perfil['days'][0]['date']);
        $this->assertSame(1, $perfil['actions'], 'Tiene una acción auditada en el periodo.');
    }

    public function test_una_persona_que_no_existe_no_devuelve_los_datos_de_otra(): void
    {
        $perfil = $this->getJson('/api/admin/reports/staff/profile?'.http_build_query([
            'preset' => 'this_month',
            'staff' => 'admin-999999',
        ]), $this->h)->assertOk()->json();

        $this->assertEquals(0, $perfil['money']['collected']);
        $this->assertSame(0, $perfil['sales']['count']);
    }

    public function test_los_que_no_renovaron_salen_en_su_lista(): void
    {
        $hoy = $this->hoy();
        User::create([
            'name' => 'Se fue',
            'email' => 'sefue-'.uniqid().'@example.com',
            'password' => 'secret',
            'membership_start_date' => $hoy->subDays(40)->toDateString(),
            'membership_end_date' => $hoy->subDays(10)->toDateString(),
        ]);

        $r = $this->getJson('/api/admin/reports/members/lapsed?preset=this_month', $this->h)->assertOk()->json();

        $this->assertSame(1, $r['total']);
        $this->assertSame('Se fue', $r['data'][0]['name']);
        $this->assertSame(10, $r['data'][0]['days_overdue']);
    }

    public function test_un_filtro_con_un_valor_inventado_se_rechaza_y_no_devuelve_todo(): void
    {
        $this->getJson('/api/admin/reports/money?preset=el_mes_que_viene', $this->h)
            ->assertStatus(422);

        $this->getJson('/api/admin/reports/money?method=bitcoin', $this->h)
            ->assertStatus(422);

        // Una clave de persona que no existe devuelve VACÍO, nunca el total.
        $r = $this->getJson('/api/admin/reports/money?preset=this_month&staff=cualquier-cosa', $this->h)
            ->assertOk()->json();
        $this->assertEquals(0, $r['totals']['collected']['current']);
    }

    public function test_cada_informe_se_puede_descargar_con_los_filtros_puestos(): void
    {
        foreach (['transactions', 'sales', 'expiring', 'expired', 'staff'] as $informe) {
            $respuesta = $this->get('/api/admin/reports/exports/'.$informe.'?'.http_build_query([
                'format' => 'csv',
                'preset' => 'this_month',
            ]), $this->h)->assertOk();

            $csv = $respuesta->streamedContent();
            $this->assertStringContainsString('Iron Body ·', $csv, "El informe {$informe} debe llevar su cabecera.");
            $this->assertStringContainsString('Periodo:', $csv);
        }
    }

    public function test_un_formato_que_este_servidor_no_puede_generar_se_rechaza_con_un_mensaje(): void
    {
        $this->get('/api/admin/reports/exports/transactions?format=docx', $this->h)
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    public function test_el_periodo_personalizado_de_un_solo_dia_funciona(): void
    {
        $dia = $this->hoy()->toDateString();

        $r = $this->getJson('/api/admin/reports/money?'.http_build_query(['from' => $dia, 'to' => $dia]), $this->h)
            ->assertOk()->json();

        $this->assertSame($dia, $r['range']['from']);
        $this->assertSame($dia, $r['range']['to']);
        $this->assertSame(1, $r['range']['days']);
        $this->assertEquals(70000, $r['totals']['collected']['current']);
    }

    public function test_las_fechas_al_reves_se_ordenan_en_vez_de_fallar(): void
    {
        $r = $this->getJson('/api/admin/reports/money?'.http_build_query([
            'from' => $this->hoy()->toDateString(),
            'to' => $this->hoy()->subDays(6)->toDateString(),
        ]), $this->h)->assertOk()->json();

        $this->assertSame($this->hoy()->subDays(6)->toDateString(), $r['range']['from']);
        $this->assertSame(7, $r['range']['days']);
    }
}
