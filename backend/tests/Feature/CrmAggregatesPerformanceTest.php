<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agregados y paginación de Pagos y Analítica.
 *
 * Los módulos del CRM calculaban estos números en el navegador descargando las
 * tablas completas página por página. Analítica además tenía un techo de 10
 * páginas, así que sus KPIs quedaban SILENCIOSAMENTE truncados pasados los 200
 * registros. Este test fija el contrato de los endpoints que los sustituyen:
 *
 *   - GET /api/payments            → `per_page` y búsqueda insensible a mayúsculas
 *   - GET /api/admin/payments/stats → KPIs agregados
 *   - GET /api/admin/payments/latest-per-member → un pago por miembro
 *   - GET /api/admin/reports/overview → agregados de Analítica sobre TODA la tabla
 */
class CrmAggregatesPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $name, array $attributes = []): User
    {
        static $seq = 0;
        $seq++;

        return User::create(array_merge([
            'name'     => $name,
            'email'    => "aggr{$seq}@example.test",
            'password' => bcrypt('secret-password'),
            'document' => (string) (7000000 + $seq),
            'phone'    => '31000000' . $seq,
            'status'   => 'active',
        ], $attributes));
    }

    private function makePayment(User $user, array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => $user->id,
            'amount'  => 50000,
            'status'  => 'paid',
            'paid_at' => now(),
        ], $attributes));
    }

    // ── Pagos: paginación y búsqueda en el servidor ──────────────────────────

    public function test_listado_de_pagos_respeta_per_page(): void
    {
        $user = $this->makeUser('Pagador');
        for ($i = 0; $i < 7; $i++) {
            $this->makePayment($user);
        }

        $response = $this->getJson('/api/payments?per_page=3', $this->adminHeaders())->assertOk();

        $this->assertCount(3, $response->json('data'));
        $this->assertSame(7, $response->json('total'));
        $this->assertSame(3, $response->json('last_page'));
    }

    public function test_busqueda_de_pagos_no_distingue_mayusculas(): void
    {
        $ana = $this->makeUser('Ana Gómez');
        $luis = $this->makeUser('Luis Pérez');
        $this->makePayment($ana, ['reference' => 'REC-001']);
        $this->makePayment($luis, ['reference' => 'REC-002']);

        $porNombre = $this->getJson('/api/payments?search=ana', $this->adminHeaders())->assertOk();
        $this->assertSame(1, $porNombre->json('total'));

        $porReferencia = $this->getJson('/api/payments?search=rec-002', $this->adminHeaders())->assertOk();
        $this->assertSame(1, $porReferencia->json('total'));
    }

    // ── Pagos: KPIs agregados ────────────────────────────────────────────────

    public function test_stats_de_pagos_agrega_montos_y_conteos(): void
    {
        $user = $this->makeUser('Cliente KPI');
        $this->makePayment($user, ['amount' => 100000, 'status' => 'paid']);
        $this->makePayment($user, ['amount' => 50000, 'status' => 'approved']);
        $this->makePayment($user, ['amount' => 30000, 'status' => 'pending', 'paid_at' => null]);
        $this->makePayment($user, ['amount' => 10000, 'status' => 'cancelled', 'paid_at' => null]);

        $response = $this->getJson('/api/admin/payments/stats', $this->adminHeaders())->assertOk();

        $this->assertSame(4, $response->json('total_count'));
        $this->assertSame(190000.0, (float) $response->json('total_amount'));
        $this->assertSame(150000.0, (float) $response->json('paid_amount'));
        $this->assertSame(2, $response->json('paid_count'));
        $this->assertSame(30000.0, (float) $response->json('pending_amount'));
        $this->assertSame(1, $response->json('pending_count'));
        $this->assertSame(1, $response->json('failed_count'));
    }

    public function test_stats_de_pagos_acepta_los_filtros_del_listado(): void
    {
        $user = $this->makeUser('Cliente Filtrado');
        $this->makePayment($user, ['amount' => 100000, 'status' => 'paid']);
        $this->makePayment($user, ['amount' => 30000, 'status' => 'pending', 'paid_at' => null]);

        $this->getJson('/api/admin/payments/stats?status=pending', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('total_count', 1)
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('paid_count', 0);
    }

    public function test_stats_de_pagos_exige_credencial_admin(): void
    {
        $this->getJson('/api/admin/payments/stats')
            ->assertStatus(401)
            ->assertJsonPath('code', 'admin_token_required');
    }

    // ── Pagos: último por miembro ────────────────────────────────────────────

    public function test_ultimo_pago_por_miembro_devuelve_una_fila_por_persona(): void
    {
        $ana = $this->makeUser('Ana Última');
        $luis = $this->makeUser('Luis Último');
        $this->makeUser('Sin Pagos');

        $this->makePayment($ana, ['amount' => 10000, 'status' => 'paid', 'paid_at' => now()->subDays(30)]);
        $ultimoDeAna = $this->makePayment($ana, ['amount' => 20000, 'status' => 'pending', 'paid_at' => now()]);
        $ultimoDeLuis = $this->makePayment($luis, ['amount' => 30000, 'status' => 'paid', 'paid_at' => now()->subDay()]);

        $response = $this->getJson('/api/admin/payments/latest-per-member', $this->adminHeaders())
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('user_id');

        $this->assertCount(2, $rows, 'Solo los miembros con pagos, una fila cada uno.');
        $this->assertSame($ultimoDeAna->id, $rows[$ana->id]['id']);
        $this->assertSame('pending', $rows[$ana->id]['status']);
        $this->assertSame($ultimoDeLuis->id, $rows[$luis->id]['id']);
    }

    // ── Analítica: agregados sobre la tabla completa ──────────────────────────

    public function test_overview_agrega_ingresos_y_miembros_sin_truncar(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 60000, 'duration_days' => 30, 'active' => true]);

        $activo = $this->makeUser('Socio Activo', ['plan' => 'Mensual', 'membership_end_date' => now()->addMonth()]);
        $this->makeUser('Socio Inactivo', ['status' => 'inactive']);
        $this->makeUser('Socio Vencido', ['membership_end_date' => now()->subDays(5)]);
        $this->makeUser('Socio Pendiente', ['status' => 'pending']);

        // Más de 200 pagos: con el techo anterior de 10 páginas (20 por página)
        // los KPIs se quedaban cortos justo aquí.
        for ($i = 0; $i < 210; $i++) {
            $this->makePayment($activo, ['plan_id' => $plan->id, 'amount' => 1000, 'paid_at' => now()]);
        }
        $this->makePayment($activo, ['amount' => 7000, 'status' => 'pending', 'paid_at' => null]);

        $response = $this->getJson('/api/admin/reports/overview', $this->adminHeaders())->assertOk();

        $this->assertSame(210000.0, (float) $response->json('kpis.total_revenue'));
        $this->assertSame(210000.0, (float) $response->json('kpis.period_revenue'));
        $this->assertSame(1, $response->json('kpis.pending_payments'));
        $this->assertSame(4, $response->json('kpis.new_members'));

        $this->assertSame(1, $response->json('members_by_status.active'));
        $this->assertSame(1, $response->json('members_by_status.inactive'));
        $this->assertSame(1, $response->json('members_by_status.expired'));
        $this->assertSame(1, $response->json('members_by_status.pending'));

        $this->assertSame(210, $response->json('payments_by_status.paid'));
        $this->assertSame(1, $response->json('payments_by_status.pending'));

        // Serie diaria del último año, con el día de hoy sumado.
        $series = collect($response->json('revenue_series'));
        $this->assertCount(365, $series);
        $this->assertSame(210000, (int) $series->firstWhere('date', now()->toDateString())['revenue']);

        $ventas = collect($response->json('plan_sales'))->keyBy('plan');
        $this->assertSame(210, $ventas['Mensual']['sales']);
    }

    public function test_overview_respeta_el_rango_pedido(): void
    {
        $user = $this->makeUser('Socio Rango');
        $this->makePayment($user, ['amount' => 90000, 'paid_at' => now()->subMonths(6)]);
        $this->makePayment($user, ['amount' => 40000, 'paid_at' => now()]);

        $response = $this->getJson(
            '/api/admin/reports/overview?from=' . now()->startOfMonth()->toDateString()
                . '&to=' . now()->toDateString(),
            $this->adminHeaders()
        )->assertOk();

        // El total es histórico; el del período solo cuenta el pago de este mes.
        $this->assertSame(130000.0, (float) $response->json('kpis.total_revenue'));
        $this->assertSame(40000.0, (float) $response->json('kpis.period_revenue'));
    }

    public function test_overview_exige_credencial_admin(): void
    {
        $this->getJson('/api/admin/reports/overview')
            ->assertStatus(401)
            ->assertJsonPath('code', 'admin_token_required');
    }

    // ── Catálogos: per_page también en planes y clases ───────────────────────

    public function test_planes_y_clases_aceptan_per_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Plan::create(['name' => "Plan {$i}", 'price' => 1000, 'duration_days' => 30, 'active' => true]);
            MyClass::create([
                'name'         => "Clase {$i}",
                'type'         => 'funcional',
                'day_of_week'  => 'monday',
                'start_time'   => '08:00',
                'end_time'     => '09:00',
                'max_capacity' => 20,
                'status'       => 'active',
            ]);
        }

        $this->getJson('/api/plans?per_page=2', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 5);

        $this->getJson('/api/classes?per_page=2', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 5);
    }
}
