<?php

namespace Tests\Feature\Reports;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ProductSale;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Reports\ReportRange;
use App\Support\Caja\PaymentOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EL CENTRO DE INFORMES, comprobado contra las formas de mentir.
 *
 * Un informe de gimnasio se equivoca casi siempre de las mismas maneras, y cada
 * test de aquí fija una:
 *
 *  · contar un plan a plazos como ingreso el día que se vendió;
 *  · sumar dos veces el mismo dinero al mezclar fuentes;
 *  · dar por ingresado lo anulado o lo devuelto;
 *  · atribuirle a quien vendió el dinero que cobró otra persona;
 *  · llamar «vencido» a quien tiene una membresía vigente y otra caducada;
 *  · perder las operaciones del último día del rango por la zona horaria;
 *  · y enseñar un total que no cuadra con su propio desglose.
 */
class ReportsCenterTest extends TestCase
{
    use RefreshDatabase;

    private Admin $eduar;

    private Admin $laura;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eduar = $this->admin('Eduar Vendedor');
        $this->laura = $this->admin('Laura Mostrador');
        $this->h = $this->actingAsAdmin($this->eduar);
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function admin(string $nombre, string $rol = Admin::ROLE_SUPER_ADMIN): Admin
    {
        return Admin::create([
            'name' => $nombre,
            'email' => 'rep-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $rol,
            'status' => 'active',
        ]);
    }

    private function socio(string $nombre = 'Socio', array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => $nombre,
            'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret',
            'document' => (string) random_int(10000000, 99999999),
            'status' => 'active',
        ], $attrs));
    }

    private function plan(string $nombre = 'Mensual', float $precio = 70000, int $dias = 30): Plan
    {
        return Plan::create(['name' => $nombre, 'price' => $precio, 'duration_days' => $dias, 'active' => true]);
    }

    /** Un cobro ya registrado, con su firma y su fecha. */
    private function cobro(User $u, ?Plan $plan, float $monto, CarbonImmutable $cuando, ?Admin $quien = null, array $extra = []): Payment
    {
        $quien ??= $this->eduar;

        return Payment::create(array_merge([
            'user_id' => $u->id,
            'plan_id' => $plan?->id,
            'amount' => $monto,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => $cuando,
            'origin' => PaymentOrigin::COUNTER->value,
            'registered_by' => $quien->id,
            'registered_by_name' => $quien->name,
            'registered_by_role' => $quien->role,
        ], $extra));
    }

    /** Un plan vendido a plazos: el contrato completo y su primer abono. */
    private function planAPlazos(User $u, Plan $plan, float $entrada, CarbonImmutable $cuando, Admin $vendedor, Admin $cajero): array
    {
        $miembro = Member::create([
            'user_id' => $u->id,
            'full_name' => $u->name,
            'document_number' => (string) random_int(10000000, 99999999),
            'is_staff' => false,
        ]);

        $contrato = $this->cobro($u, $plan, (float) $plan->price, $cuando, $vendedor, ['method' => 'receivable']);

        $cuenta = Receivable::create([
            'member_id' => $miembro->id,
            'debtor_type' => DebtorType::MEMBER,
            'debtor_id' => $miembro->id,
            'type' => CashShiftType::GYM,
            'concept' => 'Plan '.$plan->name,
            'total_amount' => $plan->price,
            'status' => 'partially_paid',
            'source_type' => Payment::class,
            'source_id' => $contrato->id,
            'created_by' => $vendedor->id,
            'created_by_name' => $vendedor->name,
        ]);

        $abono = ReceivablePayment::create([
            'receivable_id' => $cuenta->id,
            'amount' => $entrada,
            'method' => 'cash',
            'balance_before' => $plan->price,
            'balance_after' => $plan->price - $entrada,
            'status' => 'applied',
            'created_by' => $cajero->id,
            'created_by_name' => $cajero->name,
        ]);
        $abono->forceFill(['created_at' => $cuando])->save();

        return [$contrato, $cuenta, $abono];
    }

    private function ventaProducto(float $total, CarbonImmutable $cuando, Admin $cajero, string $metodo = 'card'): ProductSale
    {
        return ProductSale::create([
            'channel' => 'pos',
            'status' => 'paid',
            'payment_method' => $metodo,
            'payment_status' => 'paid',
            'subtotal' => $total,
            'total' => $total,
            'paid_at' => $cuando,
            'cashier_admin_id' => $cajero->id,
            'cashier_name' => $cajero->name,
        ]);
    }

    private function hoy(): CarbonImmutable
    {
        return ReportRange::today();
    }

    /** Las 10 de la mañana de un día del negocio, en UTC como lo guarda la base. */
    private function aLas(CarbonImmutable $dia, int $hora = 10): CarbonImmutable
    {
        return $dia->setTime($hora, 0)->utc();
    }

    private function money(array $query = []): array
    {
        return $this->getJson('/api/admin/reports/money?'.http_build_query($query), $this->h)
            ->assertOk()->json();
    }

    // ── Dinero ──────────────────────────────────────────────────────────────

    public function test_el_total_recaudado_suma_las_tres_fuentes_una_sola_vez(): void
    {
        $plan = $this->plan();
        $u = $this->socio();

        $this->cobro($u, $plan, 70000, $this->aLas($this->hoy()));
        $this->planAPlazos($this->socio('A plazos'), $this->plan('Premium', 200000), 80000, $this->aLas($this->hoy()), $this->eduar, $this->laura);
        $this->ventaProducto(15000, $this->aLas($this->hoy()), $this->laura);

        $r = $this->money(['preset' => 'today']);

        // 70.000 del cobro + 80.000 del abono + 15.000 de la venta. El plan de
        // 200.000 NO suma: de él solo entraron los 80.000 del abono.
        $this->assertEquals(165000, $r['totals']['collected']['current']);
        $this->assertSame(3, $r['totals']['operations']['current']);

        $porFuente = collect($r['totals']['by_source'])->keyBy('key');
        $this->assertEquals(70000, $porFuente['membership']['amount']);
        $this->assertEquals(80000, $porFuente['installment']['amount']);
        $this->assertEquals(15000, $porFuente['product']['amount']);
    }

    public function test_vendido_y_recaudado_son_dos_cifras_distintas(): void
    {
        $this->planAPlazos($this->socio('Plazos'), $this->plan('Premium', 200000), 80000, $this->aLas($this->hoy()), $this->eduar, $this->laura);

        $r = $this->money(['preset' => 'today']);

        $this->assertEquals(200000, $r['sold_vs_collected']['sold'], 'El contrato vale 200.000.');
        $this->assertEquals(80000, $r['sold_vs_collected']['collected_from_sales'], 'Pero solo entraron 80.000.');
        $this->assertEquals(80000, $r['totals']['collected']['current'], 'Y la caja del día son 80.000.');
        $this->assertSame(1, $r['sold_vs_collected']['installment_sales']);
    }

    public function test_lo_anulado_y_lo_devuelto_no_cuenta_como_ingreso(): void
    {
        $u = $this->socio();
        $plan = $this->plan();

        $this->cobro($u, $plan, 70000, $this->aLas($this->hoy()));
        $this->cobro($u, $plan, 50000, $this->aLas($this->hoy()), null, ['status' => 'cancelled']);
        $this->cobro($u, $plan, 30000, $this->aLas($this->hoy()), null, ['status' => 'refunded']);

        $r = $this->money(['preset' => 'today']);

        $this->assertEquals(70000, $r['totals']['collected']['current']);
        // Pero se ven: esconderlos sería peor que restarlos.
        $this->assertEquals(30000, $r['reversals']['refunded']['amount']);
        $this->assertEquals(50000, $r['reversals']['cancelled_payments']['amount']);
    }

    public function test_un_abono_revertido_sale_de_la_caja(): void
    {
        [, , $abono] = $this->planAPlazos($this->socio(), $this->plan('Premium', 200000), 80000, $this->aLas($this->hoy()), $this->eduar, $this->laura);

        $abono->forceFill([
            'status' => ReceivablePayment::STATUS_REVERSED,
            'reversed_at' => $this->aLas($this->hoy(), 12),
        ])->save();

        $r = $this->money(['preset' => 'today']);

        $this->assertEquals(0, $r['totals']['collected']['current']);
        $this->assertEquals(80000, $r['reversals']['reversed_installments']['amount']);
    }

    public function test_el_total_cuadra_con_el_listado_que_lo_explica(): void
    {
        $plan = $this->plan();
        foreach (range(1, 5) as $i) {
            $this->cobro($this->socio('Socio '.$i), $plan, 10000 * $i, $this->aLas($this->hoy()));
        }

        $r = $this->money(['preset' => 'today']);
        $filas = $this->getJson('/api/admin/reports/money/transactions?preset=today&per_page=100', $this->h)
            ->assertOk()->json();

        $this->assertEquals(150000, $r['totals']['collected']['current']);
        $this->assertEquals(
            $r['totals']['collected']['current'],
            collect($filas['data'])->sum('amount'),
            'Si la tarjeta y su desglose no suman lo mismo, una de las dos miente.',
        );
        $this->assertSame(5, $filas['total']);
    }

    public function test_el_desglose_por_medio_reparte_los_cobros_mixtos(): void
    {
        $u = $this->socio();
        $pago = $this->cobro($u, null, 60000, $this->aLas($this->hoy()), null, ['method' => Payment::MIXED_METHOD]);
        $pago->splits()->createMany([
            ['method' => 'cash', 'amount' => 20000],
            ['method' => 'card', 'amount' => 40000],
        ]);

        $porMedio = collect($this->money(['preset' => 'today'])['by_method'])->keyBy('key');

        $this->assertEquals(20000, $porMedio['cash']['amount']);
        $this->assertEquals(40000, $porMedio['card']['amount'], 'Los 60.000 no pueden caer enteros en un solo medio.');
    }

    // ── Periodos ────────────────────────────────────────────────────────────

    public function test_el_rango_incluye_sus_dos_extremos_completos(): void
    {
        $plan = $this->plan();
        $desde = $this->hoy()->subDays(6);

        // A las 00:05 del primer día y a las 23:50 del último: los dos bordes.
        $this->cobro($this->socio('Borde inicial'), $plan, 10000, $desde->setTime(0, 5)->utc());
        $this->cobro($this->socio('Borde final'), $plan, 20000, $this->hoy()->setTime(23, 50)->utc());

        $r = $this->money(['from' => $desde->toDateString(), 'to' => $this->hoy()->toDateString()]);

        $this->assertEquals(30000, $r['totals']['collected']['current']);
    }

    public function test_un_cobro_de_la_noche_cuenta_en_el_dia_de_colombia(): void
    {
        // 20:00 en Neiva es el día siguiente en UTC. Sin la zona del negocio,
        // este cobro se iría al día de mañana y el cierre de la tarde saldría
        // corto todos los días.
        $this->cobro($this->socio(), $this->plan(), 45000, $this->hoy()->setTime(20, 30)->utc());

        $this->assertEquals(45000, $this->money(['preset' => 'today'])['totals']['collected']['current']);
        $this->assertEquals(0, $this->money(['preset' => 'yesterday'])['totals']['collected']['current']);
    }

    public function test_la_serie_trae_todos_los_dias_del_periodo_con_ceros(): void
    {
        $this->cobro($this->socio(), $this->plan(), 50000, $this->aLas($this->hoy()->subDays(2)));

        $serie = $this->money(['preset' => 'last_7'])['series'];

        $this->assertCount(7, $serie, 'Siete días pedidos, siete puntos: los huecos son ceros, no ausencias.');
        $this->assertEquals(50000, collect($serie)->sum('total'));
    }

    // ── Filtros ─────────────────────────────────────────────────────────────

    public function test_los_filtros_se_combinan_y_no_se_pisan(): void
    {
        $plan = $this->plan();
        $premium = $this->plan('Premium', 120000, 90);

        $this->cobro($this->socio('De Eduar'), $plan, 70000, $this->aLas($this->hoy()), $this->eduar);
        $this->cobro($this->socio('De Laura'), $plan, 40000, $this->aLas($this->hoy()), $this->laura);
        $this->cobro($this->socio('De Eduar premium'), $premium, 120000, $this->aLas($this->hoy()), $this->eduar, ['method' => 'card']);

        $soloEduar = $this->money(['preset' => 'today', 'staff' => 'admin-'.$this->eduar->id]);
        $this->assertEquals(190000, $soloEduar['totals']['collected']['current']);

        $eduarEnEfectivo = $this->money([
            'preset' => 'today',
            'staff' => 'admin-'.$this->eduar->id,
            'method' => 'cash',
        ]);
        $this->assertEquals(70000, $eduarEnEfectivo['totals']['collected']['current']);

        $eduarPremium = $this->getJson('/api/admin/reports/sales?'.http_build_query([
            'preset' => 'today',
            'staff' => 'admin-'.$this->eduar->id,
            'plan' => 'Premium',
        ]), $this->h)->assertOk()->json();
        $this->assertSame(1, $eduarPremium['totals']['count']['current']);
        $this->assertEquals(120000, $eduarPremium['totals']['sold']['current']);
    }

    public function test_el_canal_separa_lo_que_se_pago_por_la_app(): void
    {
        $plan = $this->plan();
        $this->cobro($this->socio('Mostrador'), $plan, 70000, $this->aLas($this->hoy()));
        $this->cobro($this->socio('App'), $plan, 70000, $this->aLas($this->hoy()), null, [
            'origin' => PaymentOrigin::GATEWAY->value,
            'method' => 'wompi',
            'registered_by' => null,
            'registered_by_name' => null,
            'registered_by_role' => null,
        ]);

        $this->assertEquals(70000, $this->money(['preset' => 'today', 'channel' => 'app'])['totals']['collected']['current']);
        $this->assertEquals(70000, $this->money(['preset' => 'today', 'channel' => 'gym'])['totals']['collected']['current']);
        $this->assertEquals(140000, $this->money(['preset' => 'today'])['totals']['collected']['current']);
    }

    // ── Membresías ──────────────────────────────────────────────────────────

    public function test_quien_tiene_una_membresia_vigente_no_es_un_vencido(): void
    {
        $plan = $this->plan();

        // Un socio con historial: una membresía vieja caducada y la de ahora.
        $alDia = $this->socio('Al día', [
            'membership_start_date' => $this->hoy()->subDays(5)->toDateString(),
            'membership_end_date' => $this->hoy()->addDays(25)->toDateString(),
        ]);
        $this->cobro($alDia, $plan, 70000, $this->aLas($this->hoy()->subDays(40)), null, [
            'period_start' => $this->hoy()->subDays(40)->toDateString(),
            'period_end' => $this->hoy()->subDays(10)->toDateString(),
        ]);

        $this->socio('Vencido', [
            'membership_start_date' => $this->hoy()->subDays(42)->toDateString(),
            'membership_end_date' => $this->hoy()->subDays(12)->toDateString(),
        ]);

        $r = $this->getJson('/api/admin/reports/memberships?preset=this_month', $this->h)->assertOk()->json();

        $this->assertSame(1, $r['snapshot']['active']);
        $this->assertSame(1, $r['snapshot']['expired']);

        $vencidas = $this->getJson('/api/admin/reports/memberships/expired', $this->h)->assertOk()->json();
        $this->assertSame(1, $vencidas['total']);
        $this->assertSame('Vencido', $vencidas['data'][0]['name']);
        $this->assertSame(12, $vencidas['data'][0]['days_overdue'], 'Doce días vencido, ni once ni trece.');
    }

    public function test_las_que_vencen_pronto_salen_por_ventana_y_con_los_dias_que_faltan(): void
    {
        $this->socio('Vence en 2', ['membership_end_date' => $this->hoy()->addDays(2)->toDateString()]);
        $this->socio('Vence en 10', ['membership_end_date' => $this->hoy()->addDays(10)->toDateString()]);

        $en3 = $this->getJson('/api/admin/reports/memberships/expiring?days=3', $this->h)->assertOk()->json();
        $this->assertSame(1, $en3['total']);
        $this->assertSame(2, $en3['data'][0]['days_left']);

        $en15 = $this->getJson('/api/admin/reports/memberships/expiring?days=15', $this->h)->assertOk()->json();
        $this->assertSame(2, $en15['total']);
    }

    // ── Equipo ──────────────────────────────────────────────────────────────

    public function test_el_que_vende_y_el_que_cobra_el_abono_no_son_el_mismo(): void
    {
        // Eduar vende el plan a plazos; Laura cobra la entrada en el mostrador.
        $this->planAPlazos($this->socio('Cliente'), $this->plan('Premium', 200000), 80000, $this->aLas($this->hoy()), $this->eduar, $this->laura);

        $equipo = $this->getJson('/api/admin/reports/staff?preset=today', $this->h)->assertOk()->json();
        $tabla = collect($equipo['leaderboard'])->keyBy('key');

        $eduar = $tabla['admin-'.$this->eduar->id];
        $laura = $tabla['admin-'.$this->laura->id];

        $this->assertSame(1, $eduar['sales'], 'La venta es de Eduar…');
        $this->assertEquals(200000, $eduar['sold']);
        $this->assertEquals(0, $eduar['collected'], '…pero él no recibió el dinero.');

        $this->assertEquals(80000, $laura['collected'], 'El dinero lo cobró Laura…');
        $this->assertSame(0, $laura['sales'], '…y la venta no es suya.');
    }

    public function test_la_linea_de_tiempo_cuenta_el_dia_de_una_persona(): void
    {
        $plan = $this->plan();
        $u = $this->socio('Cliente de Eduar');
        $this->cobro($u, $plan, 70000, $this->aLas($this->hoy(), 8));
        $this->cobro($this->socio('Otro'), $plan, 40000, $this->aLas($this->hoy(), 11));
        // Y algo que solo consta en la auditoría.
        AuditLog::create([
            'action' => 'update', 'module' => 'Miembros', 'entity' => 'miembro',
            'entity_id' => (string) $u->id, 'target_name' => $u->name,
            'actor_id' => (string) $this->eduar->id, 'actor_name' => $this->eduar->name,
            'actor_role' => $this->eduar->role, 'summary' => 'Actualizó los datos del socio',
            'created_at' => $this->aLas($this->hoy(), 9),
        ]);

        $dia = $this->getJson('/api/admin/reports/staff/timeline?'.http_build_query([
            'staff' => 'admin-'.$this->eduar->id,
            'date' => $this->hoy()->toDateString(),
        ]), $this->h)->assertOk()->json();

        $this->assertEquals(110000, $dia['summary']['collected']);
        $this->assertSame(2, $dia['summary']['sales']);
        $this->assertCount(3, $dia['events'], 'Dos cobros y una modificación auditada.');
        // En orden cronológico: 8:00, 9:00, 11:00.
        $this->assertSame('membership', $dia['events'][0]['kind']);
        $this->assertSame('audit', $dia['events'][1]['kind']);
    }

    public function test_el_dinero_sin_firma_no_se_le_atribuye_a_nadie(): void
    {
        $this->cobro($this->socio(), $this->plan(), 50000, $this->aLas($this->hoy()), null, [
            'registered_by' => null, 'registered_by_name' => null, 'registered_by_role' => null,
        ]);

        $tabla = collect($this->money(['preset' => 'today'])['by_staff'])->keyBy('key');

        $this->assertArrayHasKey('none', $tabla);
        $this->assertEquals(50000, $tabla['none']['amount']);
    }

    // ── Portada, socios y actividad ─────────────────────────────────────────

    public function test_la_portada_responde_con_el_pulso_y_el_periodo(): void
    {
        $plan = $this->plan();
        $this->cobro($this->socio('Hoy'), $plan, 70000, $this->aLas($this->hoy()));
        $this->cobro($this->socio('Ayer'), $plan, 50000, $this->aLas($this->hoy()->subDay()));

        $r = $this->getJson('/api/admin/reports/summary?preset=last_7', $this->h)->assertOk()->json();

        $pulso = collect($r['pulse'])->keyBy('key');
        $this->assertEquals(70000, $pulso['today']['collected']['current']);
        $this->assertEquals(50000, $pulso['today']['collected']['previous']);
        $this->assertEquals(40.0, $pulso['today']['collected']['percent']);

        // El bloque del periodo sí depende del filtro.
        $this->assertEquals(120000, $r['money']['collected']['current']);
        $this->assertSame(2, $r['sales']['count']['current']);
    }

    public function test_la_variacion_no_inventa_porcentajes_sin_base(): void
    {
        $this->cobro($this->socio(), $this->plan(), 70000, $this->aLas($this->hoy()));

        $pulso = collect($this->getJson('/api/admin/reports/summary?preset=today', $this->h)->assertOk()->json()['pulse'])
            ->keyBy('key');

        // Ayer no hubo nada: no es «+100%», es que no había con qué comparar.
        $this->assertNull($pulso['today']['collected']['percent']);
        $this->assertEquals(70000, $pulso['today']['collected']['delta']);
    }

    public function test_los_socios_nuevos_se_cuentan_por_su_fecha_de_registro(): void
    {
        $viejo = $this->socio('Antiguo');
        $viejo->forceFill(['created_at' => $this->hoy()->subMonths(6)->utc()])->save();
        $this->socio('Nuevo');

        // Y el antiguo renueva hoy: sigue sin ser nuevo.
        $this->cobro($viejo, $this->plan(), 70000, $this->aLas($this->hoy()));

        $r = $this->getJson('/api/admin/reports/members?preset=today', $this->h)->assertOk()->json();

        $this->assertSame(1, $r['totals']['new']);
        $this->assertSame(2, $r['totals']['members']);
    }

    public function test_la_actividad_del_sistema_exige_el_permiso_de_auditoria(): void
    {
        AuditLog::create([
            'action' => 'status', 'module' => 'Pagos', 'entity' => 'pago', 'entity_id' => '1',
            'actor_id' => (string) $this->laura->id, 'actor_name' => $this->laura->name,
            'actor_role' => $this->laura->role, 'summary' => 'Cambió el cobro de pending a paid',
            'created_at' => $this->aLas($this->hoy()),
        ]);

        // El Super Admin sí puede.
        $r = $this->getJson('/api/admin/reports/activity?preset=today', $this->h)->assertOk()->json();
        $this->assertSame(1, $r['total']);
        $this->assertSame('Cambió el estado', $r['data'][0]['action_label']);

        // Administrador no tiene `audit.view` por defecto.
        $administrador = $this->admin('Administradora', Admin::ROLE_ADMINISTRADOR);
        $this->getJson('/api/admin/reports/activity?preset=today', $this->actingAsAdmin($administrador))
            ->assertForbidden();
    }

    public function test_recepcion_no_ve_el_dinero_del_gimnasio(): void
    {
        $recepcion = $this->admin('Recepción', Admin::ROLE_RECEPCION);
        $h = $this->actingAsAdmin($recepcion);

        $this->getJson('/api/admin/reports/summary', $h)->assertForbidden();
        $this->getJson('/api/admin/reports/money', $h)->assertForbidden();
        $this->getJson('/api/admin/reports/exports/transactions?format=csv', $h)->assertForbidden();
    }

    // ── Exportación ─────────────────────────────────────────────────────────

    public function test_la_exportacion_respeta_los_filtros_y_lleva_su_contexto(): void
    {
        $plan = $this->plan();
        $this->cobro($this->socio('De Eduar'), $plan, 70000, $this->aLas($this->hoy()), $this->eduar);
        $this->cobro($this->socio('De Laura'), $plan, 40000, $this->aLas($this->hoy()), $this->laura);

        $respuesta = $this->get('/api/admin/reports/exports/transactions?'.http_build_query([
            'format' => 'csv',
            'preset' => 'today',
            'staff' => 'admin-'.$this->eduar->id,
        ]), $this->h)->assertOk();

        $csv = $respuesta->streamedContent();

        $this->assertStringContainsString('Iron Body · Transacciones', $csv);
        $this->assertStringContainsString('Periodo: '.$this->hoy()->toDateString(), $csv);
        $this->assertStringContainsString('Generado:', $csv);
        $this->assertStringContainsString('De Eduar', $csv);
        $this->assertStringNotContainsString('De Laura', $csv, 'El fichero tiene que traer lo mismo que la pantalla.');

        // Y queda constancia de quién se llevó el informe.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'export',
            'module' => 'Informes',
            'entity_id' => 'transactions',
            'actor_name' => $this->eduar->name,
        ]);
    }

    public function test_el_catalogo_de_descargas_dice_lo_que_este_servidor_puede_hacer(): void
    {
        $r = $this->getJson('/api/admin/reports/exports', $this->h)->assertOk()->json();

        $this->assertContains('csv', array_column($r['formats'], 'key'));
        $this->assertContains('transactions', array_column($r['datasets'], 'key'));
    }
}
