<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductSaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reporte de ganancias del CRM: combina pagos del gimnasio (Payment) y ventas
 * de cafetería (ProductSale), con ingresos, utilidad de cafetería, serie por
 * periodo y filtros.
 */
class EarningsReportTest extends TestCase
{
    use RefreshDatabase;

    private function seedData(): void
    {
        $user = User::create([
            'name' => 'Cliente',
            'email' => 'cliente@earnings.test',
            'password' => 'secret-password',
        ]);

        // Gimnasio: 1 pago de 100 (paid) el 2026-06-10 + 1 pendiente (no cuenta).
        Payment::create([
            'user_id' => $user->id, 'amount' => 100, 'method' => 'cash',
            'status' => 'paid', 'paid_at' => '2026-06-10 12:00:00',
        ]);
        Payment::create([
            'user_id' => $user->id, 'amount' => 999, 'method' => 'cash',
            'status' => 'pending', 'paid_at' => null,
        ]);

        // Cafetería: producto costo 3 / venta 5; vende 2 uds → ingreso 10, utilidad 4.
        $product = Product::create([
            'name' => 'Agua', 'sale_price' => 5, 'cost_price' => 3, 'stock' => 100,
        ]);
        $sale = ProductSale::create([
            'uuid' => (string) Str::uuid(), 'code' => 'V-1', 'channel' => 'pos',
            'status' => 'paid', 'payment_method' => 'cash',
            'subtotal' => 10, 'total' => 10, 'paid_at' => '2026-06-10 13:00:00',
        ]);
        ProductSaleItem::create([
            'product_sale_id' => $sale->id, 'product_id' => $product->id,
            'name' => 'Agua', 'unit_price' => 5, 'quantity' => 2, 'subtotal' => 10,
        ]);
        // Venta cancelada: NO debe contar.
        ProductSale::create([
            'uuid' => (string) Str::uuid(), 'code' => 'V-2', 'channel' => 'pos',
            'status' => 'cancelled', 'total' => 500, 'paid_at' => '2026-06-10 14:00:00',
        ]);
    }

    public function test_totales_combinados_e_utilidad(): void
    {
        $this->seedData();

        $t = $this->adminGetJson('/api/admin/earnings?from=2026-06-01&to=2026-06-30&group_by=day')
            ->assertOk()
            ->json('totals');

        $this->assertEquals(100, $t['gym_revenue']);
        $this->assertEquals(10, $t['cafeteria_revenue']);
        $this->assertEquals(110, $t['combined_revenue']);
        $this->assertEquals(4, $t['cafeteria_profit']);
        $this->assertEquals(104, $t['combined_profit']);
        $this->assertEquals(1, $t['gym_count']);
        $this->assertEquals(1, $t['cafeteria_count']);
    }

    public function test_serie_por_dia(): void
    {
        $this->seedData();

        $series = collect(
            $this->adminGetJson('/api/admin/earnings?from=2026-06-01&to=2026-06-30&group_by=day')
                ->assertOk()->json('series')
        );

        $day = $series->firstWhere('period', '2026-06-10');
        $this->assertNotNull($day);
        $this->assertEquals(100, $day['gym']);
        $this->assertEquals(10, $day['cafeteria']);
        $this->assertEquals(110, $day['total']);
        $this->assertEquals(4, $day['cafeteria_profit']);
    }

    public function test_filtro_source_solo_gimnasio(): void
    {
        $this->seedData();

        $this->adminGetJson('/api/admin/earnings?from=2026-06-01&to=2026-06-30&source=gym')
            ->assertOk()
            ->assertJsonPath('totals.gym_revenue', 100)
            ->assertJsonPath('totals.cafeteria_revenue', 0);
    }

    public function test_rango_fuera_no_incluye(): void
    {
        $this->seedData();

        $t = $this->adminGetJson('/api/admin/earnings?from=2026-07-01&to=2026-07-31')
            ->assertOk()->json('totals');

        $this->assertEquals(0, $t['combined_revenue']);
    }

    public function test_requiere_admin(): void
    {
        $this->getJson('/api/admin/earnings')->assertStatus(401);
    }
}
