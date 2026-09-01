<?php

namespace Tests\Feature\Billing;

use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CajaInvoiceHookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cobrar exige un turno de caja abierto desde que Caja lleva arqueo. Lo que
     * verifican estas pruebas no cambia; solo necesitan el turno para llegar.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->openCashShift();
    }

    /**
     * Credencial de estas pruebas: una SESIÓN de administrador real.
     *
     * Estas rutas (`/admin/caja/*`, `/admin/products/*`) exigen ahora permiso
     * por ruta. El token compartido de automatizaciones es de SOLO LECTURA por
     * política —igual que en moderación—, así que cobrar con él ya no procede.
     * Autenticar como administrador refleja además cómo llama el CRM de verdad:
     * un cajero con sesión, no un secreto estático.
     */
    private ?array $adminSessionHeaders = null;

    protected function adminHeaders(array $headers = []): array
    {
        $this->adminSessionHeaders ??= $this->actingAsAdmin();

        return array_merge($this->adminSessionHeaders, $headers);
    }

    /**
     * Producto con tratamiento tributario asignado, como los 8 productos
     * activos de producción. Desde Pricing V2 un producto facturable SIN tarifa
     * no se puede vender: el POS devuelve 422 en vez de cobrar y facturar con un
     * IVA indefinido (ver test_sale_of_billable_product_without_tax_rate_is_rejected).
     */
    private function product(): Product
    {
        $rate = TaxRate::create([
            'code' => 'IVA_19_INCL', 'name' => 'IVA 19% incluido', 'rate' => 19,
            'price_includes_tax' => true, 'active' => true, 'factus_tribute_id' => '01',
        ]);

        return Product::create([
            'name' => 'Proteína', 'sale_price' => 50000, 'cost_price' => 30000,
            'stock' => 10, 'min_stock' => 1, 'active' => true,
            'tax_rate_id' => $rate->id, 'price_includes_tax' => true,
        ]);
    }

    public function test_caja_sale_creates_pending_invoice_without_emitting(): void
    {
        config(['billing.enabled' => true, 'billing.auto_emit.product_sales' => false]);
        Queue::fake();
        Http::fake();
        $product = $this->product();

        $res = $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash',
            'paid' => true,
        ])->assertCreated();

        $saleId = $res->json('data.id');
        $invoice = ElectronicInvoice::where('source_type', ProductSale::class)
            ->where('source_id', $saleId)->first();

        $this->assertNotNull($invoice);                 // pending creado
        $this->assertSame('pending', $invoice->status->value);
        Queue::assertNothingPushed();                   // NO emite
        Http::assertNothingSent();
    }

    public function test_caja_sale_emits_when_auto_emit_on(): void
    {
        config(['billing.enabled' => true, 'billing.auto_emit.product_sales' => true]);
        Queue::fake();
        $product = $this->product();

        $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid' => true,
        ])->assertCreated();

        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1); // con flag on sí emite
    }

    /**
     * Un producto que SE FACTURA y cuesta más de cero no se puede vender sin
     * tratamiento tributario: cobrar ahora y decidir el IVA después es
     * exactamente lo que produce facturas incoherentes con el cobro.
     */
    public function test_sale_of_billable_product_without_tax_rate_is_rejected(): void
    {
        config(['billing.enabled' => true, 'billing.auto_emit.product_sales' => false]);
        Queue::fake();
        Http::fake();

        $product = Product::create([
            'name' => 'Sin tarifa', 'sale_price' => 30000, 'cost_price' => 10000,
            'stock' => 5, 'active' => true,
        ]);

        $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid' => true,
        ])->assertStatus(422);

        $this->assertDatabaseCount('product_sales', 0);
        Http::assertNothingSent();
    }

    /**
     * Escape hatch operativo: marcando el producto como NO facturable se puede
     * seguir vendiendo sin tarifa. Así una configuración fiscal incompleta nunca
     * deja la caja bloqueada.
     */
    public function test_non_billable_product_without_tax_rate_can_be_sold(): void
    {
        config(['billing.enabled' => true, 'billing.auto_emit.product_sales' => false]);
        Queue::fake();
        Http::fake();

        $product = Product::create([
            'name' => 'Cortesía', 'sale_price' => 10000, 'cost_price' => 0,
            'stock' => 5, 'active' => true, 'billing_enabled' => false,
        ]);

        $res = $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid' => true,
        ])->assertCreated();

        $this->assertSame(10000.0, (float) $res->json('data.total'));
        Http::assertNothingSent();
    }
}
