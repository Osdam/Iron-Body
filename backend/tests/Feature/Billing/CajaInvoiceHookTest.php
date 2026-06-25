<?php

namespace Tests\Feature\Billing;

use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\Product;
use App\Models\ProductSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CajaInvoiceHookTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        return Product::create([
            'name' => 'Proteína', 'sale_price' => 50000, 'cost_price' => 30000,
            'stock' => 10, 'min_stock' => 1, 'active' => true,
        ]);
    }

    public function test_caja_sale_creates_pending_invoice_without_emitting(): void
    {
        config(['billing.enabled' => true, 'billing.auto_emit.product_sales' => false]);
        Queue::fake();
        Http::fake();
        $product = $this->product();

        $res = $this->adminPostJson('/api/admin/caja/sales', [
            'items'          => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash',
            'paid'           => true,
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
            'items'          => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid'           => true,
        ])->assertCreated();

        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1); // con flag on sí emite
    }
}
